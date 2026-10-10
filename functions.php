<?php
/**
 * DataGrid backend logic — a faithful PHP/SQLite port of the Apps Script Code.gs.
 *
 * Every public api* / getInitialData / getIdentity function here
 * is callable from the client (via api.php). Google identity becomes a session
 * email; Google Sheets become SQLite tables; Drive uploads become local files.
 */

require_once __DIR__ . '/auth.php';

/* ============================================================= *
 *  Small helpers
 * ============================================================= */

function random_hex(int $n): string
{
    return substr(bin2hex(random_bytes((int) ceil($n / 2))), 0, $n);
}
function make_table_id(): string { return 't_' . random_hex(12); }
function make_col_id(): string   { return 'c_' . random_hex(12); }
function make_row_id(): string   { return 'r_' . random_hex(32); }
function now_stamp(): string     { return date('Y-m-d H:i:s'); }

function make_system_col(): array
{
    return ['id' => CREATED_ID, 'name' => CREATED_NAME, 'type' => 'datetime', 'options' => [], 'width' => 180, 'order' => -1, 'system' => true];
}
function make_owner_col(): array
{
    return ['id' => OWNER_ID, 'name' => OWNER_NAME, 'type' => 'text', 'options' => [], 'width' => 200, 'order' => -1, 'system' => true, 'adminOnly' => true];
}
function is_system_id($id): bool { return $id === CREATED_ID || $id === OWNER_ID; }

/* ============================================================= *
 *  Settings
 * ============================================================= */

function get_setting(string $key, $default = '')
{
    $s = get_db()->prepare('SELECT value FROM settings WHERE key = ?');
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? $default : $v;
}
function set_setting(string $key, $value): void
{
    get_db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, (string) $value]);
}
function get_currency(): string
{
    $c = (string) get_setting(CURRENCY_KEY, DEFAULT_CURRENCY);
    return $c !== '' ? $c : DEFAULT_CURRENCY;
}
function read_schedule_notes(string $tableId): array
{
    $raw = (string) get_setting(SCHED_NOTES_PREFIX . $tableId, '');
    if ($raw === '') return [];
    $o = json_decode($raw, true);
    return (is_array($o)) ? $o : [];
}

/* ============================================================= *
 *  Identity, roles & audit
 * ============================================================= */

function owner_email(): string
{
    return strtolower(trim((string) get_setting(OWNER_KEY, '')));
}
function is_valid_email($v): bool
{
    $s = trim((string) $v);
    return $s !== '' && (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s);
}
function get_roles_map(): array
{
    $map = [];
    foreach (get_db()->query('SELECT email, role FROM roles') as $r) {
        $em = strtolower(trim($r['email']));
        if ($em !== '') $map[$em] = (strtolower(trim($r['role'])) === ROLE_ADMIN) ? ROLE_ADMIN : ROLE_MEMBER;
    }
    return $map;
}
function get_role_for(string $email): string
{
    $email = strtolower(trim($email));
    $owner = owner_email();
    if ($email !== '' && $owner !== '' && $email === $owner) return ROLE_ADMIN;
    if ($email !== '') { $map = get_roles_map(); if (isset($map[$email])) return $map[$email]; }
    return ROLE_MEMBER;
}
function current_role(): string { return get_role_for(current_user_email()); }
function require_admin(): void
{
    if (current_role() !== ROLE_ADMIN) throw new Exception('Only an administrator can do that. Ask an admin for access.');
}
function require_table_write(array $table): void
{
    if (($table['accessMode'] ?? ACCESS_PER_USER) === ACCESS_ADMIN_MANAGED && current_role() !== ROLE_ADMIN) {
        throw new Exception('Only an administrator can change this table.');
    }
}
/** A member may only touch rows they own; admins may touch any. */
function require_row_access(string $rowOwner): void
{
    if (current_role() === ROLE_ADMIN) return;
    $me = current_user_email();
    $ro = strtolower(trim($rowOwner));
    if ($ro !== '' && $me !== '' && $ro === $me) return;
    throw new Exception('You can only change rows you added.');
}

function log_audit(string $action, string $target = '', string $details = ''): void
{
    try {
        if (mb_strlen($details) > 4000) $details = mb_substr($details, 0, 3999) . '…';
        get_db()->prepare('INSERT INTO audit(ts, "user", action, target, details) VALUES(?,?,?,?,?)')
            ->execute([now_stamp(), current_user_email() ?: '(anonymous)', $action, $target, $details]);
    } catch (\Throwable $e) { /* logging must never break a write */ }
}
function audit_val($v): string
{
    return ($v === '' || $v === null) ? '(empty)' : (string) $v;
}
function summarize_record(array $schema, array $record): string
{
    $parts = [];
    foreach ($schema['columns'] as $c) {
        if (is_system_id($c['id'])) continue;
        $v = $record[$c['id']] ?? '';
        if ($v === '' || $v === null) continue;
        $parts[] = $c['name'] . '=' . (string) $v;
        if (mb_strlen(implode('; ', $parts)) > 400) break;
    }
    return mb_substr(implode('; ', $parts), 0, 500);
}
function diff_records(array $schema, array $oldObj, array $newObj): string
{
    $lines = [];
    foreach ($schema['columns'] as $c) {
        if (is_system_id($c['id'])) continue;
        $o = $oldObj[$c['id']] ?? '';
        $n = $newObj[$c['id']] ?? '';
        if ((string) $o === (string) $n) continue;
        $lines[] = $c['name'] . ': ' . audit_val($o) . ' → ' . audit_val($n);
    }
    return implode("\n", $lines);
}

/* ============================================================= *
 *  Table registry
 * ============================================================= */

function normalize_access_mode($v): string
{
    return (trim((string) $v) === ACCESS_ADMIN_MANAGED) ? ACCESS_ADMIN_MANAGED : ACCESS_PER_USER;
}
function list_tables(): array
{
    $out = [];
    foreach (get_db()->query('SELECT id, name, ord, access_mode, schema_json FROM tables ORDER BY ord') as $r) {
        $schema = json_decode($r['schema_json'], true);
        if (!is_array($schema) || !isset($schema['columns']) || !is_array($schema['columns'])) $schema = ['columns' => []];
        $out[] = [
            'id' => $r['id'],
            'name' => trim($r['name']) !== '' ? $r['name'] : 'Untitled',
            'order' => (int) $r['ord'],
            'accessMode' => normalize_access_mode($r['access_mode']),
            'schema' => $schema,
        ];
    }
    return $out;
}
function get_table_by_id(string $id): ?array
{
    foreach (list_tables() as $t) if ($t['id'] === $id) return $t;
    return null;
}
function get_table($tableId): ?array
{
    $tables = list_tables();
    if (!$tables) return null;
    $tableId = trim((string) $tableId);
    foreach ($tables as $t) if ($t['id'] === $tableId) return $t;
    return $tables[0];
}
function primary_table_id(): string
{
    $t = list_tables();
    return $t ? $t[0]['id'] : '';
}
function tables_for_client(): array
{
    return array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'order' => $t['order'], 'accessMode' => $t['accessMode']], list_tables());
}
function save_table_schema(string $tableId, array $schema): void
{
    get_db()->prepare('UPDATE tables SET schema_json = ? WHERE id = ?')->execute([json_encode($schema), $tableId]);
}
function index_of_col(array $schema, string $id): int
{
    foreach ($schema['columns'] as $i => $c) if ($c['id'] === $id) return $i;
    return -1;
}
function is_shift_table(array $table): bool
{
    $id = (string) get_setting(SHIFT_TABLE_ID_KEY, '');
    if ($id !== '' && $table['id'] === $id) return true;
    return mb_strtolower($table['name']) === mb_strtolower(SHIFT_TABLE_NAME);
}

/* ============================================================= *
 *  Value normalisation
 * ============================================================= */

function normalize_in($v, string $type)
{
    if ($v === null) return '';
    if ($type === 'number' || $type === 'price') {
        $t = trim((string) $v);
        if ($t === '' || !is_numeric($t)) return '';
        return $t + 0;
    }
    if ($type === 'date') return $v === '' ? '' : (string) $v;
    if ($type === 'time') return normalize_time($v);
    if ($type === 'link') return clean_link_ids($v);
    if ($type === 'file') return clean_files($v);
    return (string) $v;
}
function normalize_out($v, string $type)
{
    if ($v === '' || $v === null) return '';
    if ($type === 'number' || $type === 'price') return is_numeric($v) ? $v + 0 : '';
    if ($type === 'date') return (string) $v;
    if ($type === 'time') return normalize_time($v);
    if ($type === 'link') return clean_link_ids($v);
    if ($type === 'file') return clean_files($v);
    return (string) $v;
}
function normalize_time($v): string
{
    if ($v === '' || $v === null) return '';
    $s = trim((string) $v);
    if ($s === '') return '';
    if (preg_match('/^(\d{1,2})(?:[:.](\d{1,2}))?\s*([ap])\.?\s*m\.?$/i', $s, $m)) {
        $h = (int) $m[1]; $min = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : 0;
        if ($h < 1 || $h > 12 || $min > 59) return '';
        if ($h === 12) $h = 0;
        if (stripos($m[3], 'p') !== false) $h += 12;
        return sprintf('%02d:%02d', $h, $min);
    }
    if (preg_match('/^(\d{1,2}):(\d{1,2})(?::\d{1,2})?$/', $s, $m)) {
        $H = (int) $m[1]; $M = (int) $m[2];
        if ($H > 23 || $M > 59) return '';
        return sprintf('%02d:%02d', $H, $M);
    }
    return '';
}
function clean_link_ids($v): string
{
    $arr = is_array($v) ? $v : explode(',', (string) $v);
    $seen = []; $out = [];
    foreach ($arr as $x) { $s = trim((string) $x); if ($s !== '' && !isset($seen[$s])) { $seen[$s] = 1; $out[] = $s; } }
    return implode(',', $out);
}
function clean_files($v): string
{
    if (is_array($v)) { $arr = $v; }
    else { $s = trim((string) $v); if ($s === '') return ''; $arr = json_decode($s, true); }
    if (!is_array($arr)) return '';
    $out = [];
    foreach ($arr as $f) {
        if (count($out) >= MAX_FILES_PER_CELL) break;
        if (!is_array($f)) continue;
        $id = trim((string) ($f['id'] ?? '')); $name = trim((string) ($f['name'] ?? '')); $url = trim((string) ($f['url'] ?? ''));
        if ($id === '' || $name === '' || $url === '') continue;
        $size = (int) ($f['size'] ?? 0); if ($size < 0) $size = 0;
        $out[] = [
            'id' => mb_substr($id, 0, 128), 'name' => mb_substr($name, 0, 255), 'url' => mb_substr($url, 0, 1000),
            'mime' => mb_substr(trim((string) ($f['mime'] ?? '')), 0, 100), 'size' => $size,
        ];
    }
    return $out ? json_encode($out) : '';
}
function clean_options($opts, string $type): array
{
    if ($type !== 'dropdown' || !is_array($opts)) return [];
    $seen = []; $out = [];
    foreach ($opts as $o) { $s = trim((string) $o); if ($s !== '' && !isset($seen[$s])) { $seen[$s] = 1; $out[] = $s; } }
    return $out;
}
function sanitize_name($n): string
{
    $n = ($n === null) ? '' : trim((string) $n);
    return $n !== '' ? $n : 'Untitled';
}
function sanitize_file_name($n): string
{
    $n = preg_replace('/[\/\\\\\r\n\t\x00-\x1f]+/', ' ', (string) $n);
    $n = trim(preg_replace('/\s+/', ' ', $n));
    if (mb_strlen($n) > 200) {
        $dot = mb_strrpos($n, '.');
        $ext = ($dot !== false && $dot > 0 && mb_strlen($n) - $dot <= 12) ? mb_substr($n, $dot) : '';
        $n = mb_substr($n, 0, 200 - mb_strlen($ext)) . $ext;
    }
    return $n !== '' ? $n : 'file';
}
function allowed_type($t): string { return in_array($t, ALLOWED_TYPES, true) ? $t : 'text'; }
function clamp_width($w): int
{
    $w = (int) $w; if ($w === 0) $w = 160;
    return max(70, min(640, $w));
}
function resolve_link_meta($linkTableId, $linkDisplayColId): array
{
    $target = get_table_by_id(trim((string) $linkTableId));
    if (!$target) throw new Exception('Choose a table to link to.');
    $want = trim((string) $linkDisplayColId); $firstEligible = ''; $valid = false;
    foreach ($target['schema']['columns'] as $c) {
        if (is_system_id($c['id']) || $c['type'] === 'link' || $c['type'] === 'file') continue;
        if ($firstEligible === '') $firstEligible = $c['id'];
        if ($c['id'] === $want) $valid = true;
    }
    return ['linkTableId' => $target['id'], 'linkDisplayColId' => $valid ? $want : $firstEligible];
}

/* ============================================================= *
 *  Row helpers
 * ============================================================= */

/** Build the client-facing values map (all schema columns, system cols included). */
function build_values(array $schema, string $created, string $owner, array $data): array
{
    $values = [];
    foreach ($schema['columns'] as $c) {
        if ($c['id'] === CREATED_ID) $values[CREATED_ID] = $created;
        elseif ($c['id'] === OWNER_ID) $values[OWNER_ID] = $owner;
        else $values[$c['id']] = normalize_out($data[$c['id']] ?? '', $c['type']);
    }
    return $values;
}
/** Non-system values keyed by column id, normalised for storage. */
function record_data_from(array $schema, array $record): array
{
    $data = [];
    foreach ($schema['columns'] as $c) {
        if (is_system_id($c['id'])) continue;
        $data[$c['id']] = normalize_in($record[$c['id']] ?? '', $c['type']);
    }
    return $data;
}
function read_rows(array $table): array
{
    $schema = $table['schema'];
    $stmt = get_db()->prepare('SELECT row_id, created, owner, data_json FROM records WHERE table_id = ? ORDER BY seq');
    $stmt->execute([$table['id']]);
    $out = [];
    foreach ($stmt as $r) {
        $data = json_decode($r['data_json'] ?: '{}', true); if (!is_array($data)) $data = [];
        $out[] = ['_id' => $r['row_id'], 'values' => build_values($schema, (string) ($r['created'] ?? ''), (string) ($r['owner'] ?? ''), $data)];
    }
    return $out;
}
function find_record(string $tableId, string $rowId): ?array
{
    $s = get_db()->prepare('SELECT seq, row_id, created, owner, data_json FROM records WHERE table_id = ? AND row_id = ? LIMIT 1');
    $s->execute([$tableId, $rowId]);
    $r = $s->fetch();
    if (!$r) return null;
    $data = json_decode($r['data_json'] ?: '{}', true);
    $r['data'] = is_array($data) ? $data : [];
    return $r;
}
function insert_record(string $tableId, string $rowId, string $created, string $owner, array $data): void
{
    get_db()->prepare('INSERT INTO records(table_id, row_id, created, owner, data_json) VALUES(?,?,?,?,?)')
        ->execute([$tableId, $rowId, $created, $owner, json_encode($data)]);
}

function count_trash(string $tableId, bool $isAdmin, string $meLc): int
{
    $s = get_db()->prepare('SELECT owner FROM trash WHERE table_id = ?');
    $s->execute([$tableId]);
    $n = 0;
    foreach ($s as $r) if ($isAdmin || strtolower(trim((string) $r['owner'])) === $meLc) $n++;
    return $n;
}

/* ============================================================= *
 *  Identity API
 * ============================================================= */

function getIdentity()
{
    // Auth is handled by the login/register pages (a signed cookie); here we just
    // report who the cookie says we are, so the client can boot or redirect.
    return ['email' => current_user_email(), 'source' => 'session'];
}

/* ============================================================= *
 *  Tables API
 * ============================================================= */

function apiListTables()
{
    return ['tables' => tables_for_client(), 'isAdmin' => current_role() === ROLE_ADMIN];
}
function apiCreateTable($name, $accessMode = ACCESS_PER_USER)
{
    require_admin();
    $name = trim((string) $name);
    if ($name === '') throw new Exception('Enter a table name.');
    if (mb_strlen($name) > MAX_TABLE_NAME_LEN) throw new Exception('Table name can be at most ' . MAX_TABLE_NAME_LEN . ' characters.');
    $mode = normalize_access_mode($accessMode);
    $lc = mb_strtolower($name);
    foreach (list_tables() as $t) if (mb_strtolower($t['name']) === $lc) throw new Exception('A table named “' . $name . '” already exists.');

    $id = make_table_id();
    $ord = (int) get_db()->query('SELECT COALESCE(MAX(ord), -1) + 1 FROM tables')->fetchColumn();
    $schema = ['columns' => [make_system_col(), make_owner_col()]];
    get_db()->prepare('INSERT INTO tables(id, name, ord, access_mode, schema_json) VALUES(?,?,?,?,?)')
        ->execute([$id, $name, $ord, $mode, json_encode($schema)]);
    log_audit('CREATE_TABLE', $id, $name . ' (' . $mode . ')');
    return ['table' => ['id' => $id, 'name' => $name, 'order' => $ord, 'accessMode' => $mode], 'tables' => tables_for_client()];
}
function apiRenameTable($tableId, $name)
{
    require_admin();
    $name = trim((string) $name);
    if ($name === '') throw new Exception('Enter a table name.');
    if (mb_strlen($name) > MAX_TABLE_NAME_LEN) throw new Exception('Table name can be at most ' . MAX_TABLE_NAME_LEN . ' characters.');
    $target = null; $lc = mb_strtolower($name);
    foreach (list_tables() as $t) {
        if ($t['id'] === (string) $tableId) $target = $t;
        elseif (mb_strtolower($t['name']) === $lc) throw new Exception('A table named “' . $name . '” already exists.');
    }
    if (!$target) throw new Exception('That table no longer exists.');
    if ($target['name'] !== $name) {
        get_db()->prepare('UPDATE tables SET name = ? WHERE id = ?')->execute([$name, $target['id']]);
        log_audit('RENAME_TABLE', $target['id'], audit_val($target['name']) . ' → ' . audit_val($name));
    }
    return ['ok' => true, 'tables' => tables_for_client()];
}
function apiDeleteTable($tableId)
{
    require_admin();
    $tables = list_tables();
    if (count($tables) <= 1) throw new Exception('You can’t delete the only table — create another first.');
    $target = get_table_by_id((string) $tableId);
    if (!$target) throw new Exception('That table no longer exists.');
    $db = get_db();
    $db->prepare('DELETE FROM records WHERE table_id = ?')->execute([$target['id']]);
    $db->prepare('DELETE FROM trash WHERE table_id = ?')->execute([$target['id']]);
    $db->prepare('DELETE FROM views WHERE table_id = ?')->execute([$target['id']]);
    $db->prepare('DELETE FROM tables WHERE id = ?')->execute([$target['id']]);
    log_audit('DELETE_TABLE', $target['id'], $target['name']);
    return ['ok' => true, 'tables' => tables_for_client(), 'tableId' => primary_table_id()];
}
function apiSetTableAccessMode($tableId, $modeIn)
{
    require_admin();
    $mode = normalize_access_mode($modeIn);
    $target = get_table_by_id((string) $tableId);
    if (!$target) throw new Exception('That table no longer exists.');
    if ($target['accessMode'] !== $mode) {
        get_db()->prepare('UPDATE tables SET access_mode = ? WHERE id = ?')->execute([$mode, $target['id']]);
        log_audit('SET_TABLE_ACCESS', $target['id'], audit_val($target['accessMode']) . ' → ' . audit_val($mode));
    }
    return ['ok' => true, 'tables' => tables_for_client()];
}

/* ============================================================= *
 *  Initial data (the main workspace payload)
 * ============================================================= */

function getInitialData($tableId = '')
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $schema = $table['schema'];
    $email = current_user_email();
    $role = get_role_for($email);
    $isAdmin = $role === ROLE_ADMIN;

    $columns = $schema['columns'];
    $rows = read_rows($table);
    $isShift = is_shift_table($table);

    if (!$isAdmin) {
        if ($table['accessMode'] !== ACCESS_ADMIN_MANAGED) {
            $meLc = strtolower($email);
            $rows = array_values(array_filter($rows, fn($r) => strtolower(trim((string) ($r['values'][OWNER_ID] ?? ''))) === $meLc));
        }
        // Hide admin-only columns, and strip their values from the rows too.
        $adminOnly = [];
        foreach ($columns as $c) if (!empty($c['adminOnly'])) $adminOnly[] = $c['id'];
        $columns = array_values(array_filter($columns, fn($c) => empty($c['adminOnly'])));
        if ($adminOnly) {
            foreach ($rows as &$r) foreach ($adminOnly as $aid) unset($r['values'][$aid]);
            unset($r);
        }
    }

    return [
        'columns' => $columns,
        'rows' => $rows,
        'tables' => tables_for_client(),
        'tableId' => $table['id'],
        'accessMode' => $table['accessMode'],
        'sheetUrl' => '',
        'currency' => get_currency(),
        'trashCount' => count_trash($table['id'], $isAdmin, strtolower($email)),
        'views' => views_for_client($table['id'], $email, $isAdmin),
        'scheduleView' => $isShift,
        'dateNotes' => $isShift ? (object) read_schedule_notes($table['id']) : null,
        'me' => ['email' => $email, 'role' => $role, 'isAdmin' => $isAdmin, 'owner' => owner_email()],
    ];
}

function apiSetScheduleNote($tableId, $dateKey, $text)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $key = trim((string) $dateKey);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) throw new Exception('That date is not valid.');
    $notes = read_schedule_notes($table['id']);
    $t = trim(mb_substr(rtrim((string) $text), 0, 500));
    if ($t !== '') $notes[$key] = $t; else unset($notes[$key]);
    set_setting(SCHED_NOTES_PREFIX . $table['id'], json_encode($notes));
    log_audit('SET_SCHED_NOTE', $table['id'], $key . ($t !== '' ? ' set' : ' cleared'));
    return ['ok' => true, 'dateNotes' => (object) $notes];
}

/* ============================================================= *
 *  Settings API
 * ============================================================= */

function apiSetCurrency($symbol)
{
    require_admin();
    $sym = trim((string) $symbol);
    if ($sym === '') throw new Exception('Enter a currency symbol.');
    if (mb_strlen($sym) > MAX_CURRENCY_LEN) throw new Exception('Currency symbol can be at most ' . MAX_CURRENCY_LEN . ' characters.');
    $prev = get_currency();
    set_setting(CURRENCY_KEY, $sym);
    if ($prev !== $sym) log_audit('SET_CURRENCY', CURRENCY_KEY, audit_val($prev) . ' → ' . audit_val($sym));
    return ['ok' => true, 'currency' => $sym];
}
/* ============================================================= *
 *  Column API (admin only)
 * ============================================================= */

function apiAddColumn($tableId, $colDef)
{
    require_admin();
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $schema = $table['schema'];
    $type = allowed_type($colDef['type'] ?? null);
    $col = [
        'id' => make_col_id(),
        'name' => sanitize_name($colDef['name'] ?? null),
        'type' => $type,
        'options' => clean_options($colDef['options'] ?? null, $type),
        'width' => 170,
        'order' => count($schema['columns']),
    ];
    if ($type === 'link') {
        $lm = resolve_link_meta($colDef['linkTableId'] ?? '', $colDef['linkDisplayColId'] ?? '');
        $col['linkTableId'] = $lm['linkTableId'];
        $col['linkDisplayColId'] = $lm['linkDisplayColId'];
    }
    $schema['columns'][] = $col;
    save_table_schema($table['id'], $schema);
    log_audit('ADD_COLUMN', $col['name'], 'type=' . $col['type'] . ($col['options'] ? '; options=' . implode(', ', $col['options']) : ''));
    return ['columns' => $schema['columns']];
}
function apiUpdateColumn($tableId, $id, $patch)
{
    if (is_system_id($id)) throw new Exception('That column is built in and can’t be edited.');
    require_admin();
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $schema = $table['schema'];
    $idx = index_of_col($schema, $id);
    if ($idx < 0) throw new Exception('That column no longer exists.');
    $col = $schema['columns'][$idx];
    $patch = is_array($patch) ? $patch : [];
    if (array_key_exists('name', $patch))    $col['name'] = sanitize_name($patch['name']);
    if (array_key_exists('type', $patch))    $col['type'] = allowed_type($patch['type']);
    if (array_key_exists('options', $patch)) $col['options'] = clean_options($patch['options'], $col['type']);
    if (array_key_exists('width', $patch))   $col['width'] = clamp_width($patch['width']);
    if ($col['type'] === 'link') {
        $lm = resolve_link_meta(
            array_key_exists('linkTableId', $patch) ? $patch['linkTableId'] : ($col['linkTableId'] ?? ''),
            array_key_exists('linkDisplayColId', $patch) ? $patch['linkDisplayColId'] : ($col['linkDisplayColId'] ?? '')
        );
        $col['linkTableId'] = $lm['linkTableId'];
        $col['linkDisplayColId'] = $lm['linkDisplayColId'];
    } else {
        unset($col['linkTableId'], $col['linkDisplayColId']);
    }
    $schema['columns'][$idx] = $col;
    save_table_schema($table['id'], $schema);
    log_audit('UPDATE_COLUMN', $col['name'], 'updated');
    return ['columns' => $schema['columns']];
}
function apiDeleteColumn($tableId, $id)
{
    if (is_system_id($id)) throw new Exception('That column is built in and can’t be deleted.');
    require_admin();
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $schema = $table['schema'];
    $idx = index_of_col($schema, $id);
    if ($idx < 0) return ['columns' => $schema['columns']];
    $removed = $schema['columns'][$idx]['name'];
    array_splice($schema['columns'], $idx, 1);
    save_table_schema($table['id'], $schema);
    // Drop the orphaned value from every row.
    $stmt = get_db()->prepare('SELECT seq, data_json FROM records WHERE table_id = ?');
    $stmt->execute([$table['id']]);
    $upd = get_db()->prepare('UPDATE records SET data_json = ? WHERE seq = ?');
    foreach ($stmt->fetchAll() as $r) {
        $data = json_decode($r['data_json'] ?: '{}', true); if (!is_array($data)) $data = [];
        if (array_key_exists($id, $data)) { unset($data[$id]); $upd->execute([json_encode($data), $r['seq']]); }
    }
    log_audit('DELETE_COLUMN', $removed, '');
    return ['columns' => $schema['columns']];
}
function apiReorderColumns($tableId, $orderedIds)
{
    require_admin();
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $schema = $table['schema'];
    $pos = [];
    foreach ((array) $orderedIds as $i => $cid) $pos[$cid] = $i;
    foreach ($schema['columns'] as &$c) {
        if (is_system_id($c['id'])) { $c['order'] = -1; continue; }
        if (isset($pos[$c['id']])) $c['order'] = $pos[$c['id']];
    }
    unset($c);
    save_table_schema($table['id'], $schema);
    log_audit('REORDER_COLUMNS', '', implode(' → ', (array) $orderedIds));
    return ['ok' => true];
}
function apiSetColumnWidth($tableId, $id, $width)
{
    require_admin();
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $schema = $table['schema'];
    $idx = index_of_col($schema, $id);
    if ($idx >= 0) { $schema['columns'][$idx]['width'] = clamp_width($width); save_table_schema($table['id'], $schema); }
    return ['ok' => true];
}
function apiLinkOptions($targetTableId, $displayColId)
{
    $table = get_table_by_id((string) $targetTableId);
    if (!$table) throw new Exception('That table no longer exists.');
    $schema = $table['schema'];
    $email = current_user_email();
    $isAdmin = get_role_for($email) === ROLE_ADMIN;
    $meLc = strtolower($email);

    $dispId = trim((string) $displayColId); $ok = false;
    foreach ($schema['columns'] as $c) {
        if ($c['id'] === $dispId && !is_system_id($c['id']) && $c['type'] !== 'link' && $c['type'] !== 'file') { $ok = true; break; }
    }
    if (!$ok) $dispId = '';

    $rows = read_rows($table);
    if (!$isAdmin) $rows = array_filter($rows, fn($r) => strtolower(trim((string) ($r['values'][OWNER_ID] ?? ''))) === $meLc);
    $options = [];
    foreach ($rows as $r) {
        $label = $dispId !== '' ? ($r['values'][$dispId] ?? '') : '';
        $options[] = ['id' => $r['_id'], 'label' => ($label === '' || $label === null) ? '' : (string) $label];
    }
    return ['tableId' => $table['id'], 'tableName' => $table['name'], 'displayColId' => $dispId, 'options' => $options];
}

/* ============================================================= *
 *  Row API
 * ============================================================= */

function apiAddRow($tableId, $record)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $schema = $table['schema'];
    $record = is_array($record) ? $record : [];
    $id = make_row_id();
    $stamp = now_stamp();
    $me = current_user_email();
    $data = record_data_from($schema, $record);
    insert_record($table['id'], $id, $stamp, $me, $data);
    $values = build_values($schema, $stamp, $me, $data);
    log_audit('ADD_ROW', $id, summarize_record($schema, $values));
    return ['row' => ['_id' => $id, 'values' => $values]];
}
function apiUpdateRow($tableId, $rowId, $record)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $schema = $table['schema'];
    $rec = find_record($table['id'], (string) $rowId);
    if (!$rec) throw new Exception('That row no longer exists.');
    require_row_access((string) $rec['owner']);

    $before = build_values($schema, (string) $rec['created'], (string) $rec['owner'], $rec['data']);
    $record = is_array($record) ? $record : [];
    $data = record_data_from($schema, $record);   // system cols stay as stored (created/owner unchanged)
    get_db()->prepare('UPDATE records SET data_json = ? WHERE seq = ?')->execute([json_encode($data), $rec['seq']]);
    $values = build_values($schema, (string) $rec['created'], (string) $rec['owner'], $data);
    log_audit('UPDATE_ROW', (string) $rowId, diff_records($schema, $before, $values) ?: '(no changes)');
    return ['row' => ['_id' => (string) $rowId, 'values' => $values]];
}
function apiUpdateCell($tableId, $rowId, $colId, $value)
{
    if (is_system_id($colId)) throw new Exception('That column is read-only.');
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $schema = $table['schema'];
    $idx = index_of_col($schema, $colId);
    if ($idx < 0) throw new Exception('That column no longer exists.');
    $rec = find_record($table['id'], (string) $rowId);
    if (!$rec) throw new Exception('That row no longer exists.');
    require_row_access((string) $rec['owner']);
    $col = $schema['columns'][$idx];
    $oldOut = normalize_out($rec['data'][$colId] ?? '', $col['type']);
    $inVal = normalize_in($value, $col['type']);
    $rec['data'][$colId] = $inVal;
    get_db()->prepare('UPDATE records SET data_json = ? WHERE seq = ?')->execute([json_encode($rec['data']), $rec['seq']]);
    $outVal = normalize_out($inVal, $col['type']);
    $unchanged = (string) $oldOut === (string) $outVal;
    log_audit('UPDATE_CELL', (string) $rowId, $col['name'] . ': ' . audit_val($oldOut) . ($unchanged ? ' (unchanged)' : ' → ' . audit_val($outVal)));
    return ['ok' => true, 'value' => $outVal];
}
function apiImportRows($tableId, $records)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $schema = $table['schema'];
    if (count($schema['columns']) === 0) throw new Exception('Add a column before importing.');
    if (!$records) return ['ok' => true, 'added' => 0, 'skipped' => 0];

    $stamp = now_stamp();
    $me = current_user_email();
    $added = 0; $skipped = 0;
    $db = get_db();
    $db->beginTransaction();
    try {
        foreach ($records as $rec) {
            $rec = is_array($rec) ? $rec : [];
            $data = []; $any = false;
            foreach ($schema['columns'] as $c) {
                if (is_system_id($c['id'])) continue;
                $v = normalize_in($rec[$c['id']] ?? '', $c['type']);
                $data[$c['id']] = $v;
                if ($v !== '' && $v !== null) $any = true;
            }
            if (!$any) { $skipped++; continue; }
            insert_record($table['id'], make_row_id(), $stamp, $me, $data);
            $added++;
        }
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    if ($added > 0) log_audit('IMPORT_ROWS', (string) $added, 'Imported ' . $added . ' row(s)' . ($skipped ? " ($skipped skipped)" : ''));
    return ['ok' => true, 'added' => $added, 'skipped' => $skipped];
}

/* ---------- Bulk actions ---------- */

function apiBulkSetField($tableId, $rowIds, $colId, $value)
{
    if (is_system_id($colId)) throw new Exception('That column is read-only.');
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $schema = $table['schema'];
    $idx = index_of_col($schema, $colId);
    if ($idx < 0) throw new Exception('That column no longer exists.');
    if (!$rowIds) return ['ok' => true, 'updated' => 0, 'colId' => $colId, 'value' => ''];
    $col = $schema['columns'][$idx];
    $isAdmin = current_role() === ROLE_ADMIN;
    $meLc = current_user_email();
    $inVal = normalize_in($value, $col['type']);
    $outVal = normalize_out($inVal, $col['type']);
    $want = array_flip(array_map('strval', (array) $rowIds));
    $updated = 0;
    $db = get_db();
    $sel = $db->prepare('SELECT seq, row_id, owner, data_json FROM records WHERE table_id = ?');
    $sel->execute([$table['id']]);
    $all = $sel->fetchAll();   // materialise before issuing UPDATEs on the same connection
    $db->beginTransaction();
    try {
        $upd = $db->prepare('UPDATE records SET data_json = ? WHERE seq = ?');
        foreach ($all as $r) {
            if (!isset($want[$r['row_id']])) continue;
            if (!$isAdmin && strtolower(trim((string) $r['owner'])) !== $meLc) continue;
            $data = json_decode($r['data_json'] ?: '{}', true); if (!is_array($data)) $data = [];
            $data[$colId] = $inVal;
            $upd->execute([json_encode($data), $r['seq']]);
            $updated++;
        }
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    if ($updated > 0) log_audit('BULK_SET_FIELD', $col['name'], 'set ' . $updated . ' row(s) to ' . audit_val($outVal));
    return ['ok' => true, 'updated' => $updated, 'colId' => $colId, 'value' => $outVal];
}
function apiBulkDeleteRows($tableId, $rowIds)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    if (!$rowIds) return ['ok' => true, 'deleted' => 0, 'ids' => []];
    $schema = $table['schema'];
    $isAdmin = current_role() === ROLE_ADMIN;
    $meLc = current_user_email();
    $want = array_flip(array_map('strval', (array) $rowIds));
    $ids = [];
    $db = get_db();
    $db->beginTransaction();
    try {
        $sel = $db->prepare('SELECT seq, row_id, created, owner, data_json FROM records WHERE table_id = ?');
        $sel->execute([$table['id']]);
        foreach ($sel->fetchAll() as $r) {
            if (!isset($want[$r['row_id']])) continue;
            if (!$isAdmin && strtolower(trim((string) $r['owner'])) !== $meLc) continue;
            $data = json_decode($r['data_json'] ?: '{}', true); if (!is_array($data)) $data = [];
            $snapshot = build_values($schema, (string) $r['created'], (string) $r['owner'], $data);
            trash_put($table['id'], $r['row_id'], (string) $r['owner'], $snapshot);
            $db->prepare('DELETE FROM records WHERE seq = ?')->execute([$r['seq']]);
            $ids[] = $r['row_id'];
        }
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    if ($ids) log_audit('BULK_DELETE', (string) count($ids), 'moved ' . count($ids) . ' row(s) to trash');
    return ['ok' => true, 'deleted' => count($ids), 'ids' => $ids];
}
function apiDeleteRow($tableId, $rowId)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $schema = $table['schema'];
    $rec = find_record($table['id'], (string) $rowId);
    if ($rec) {
        require_row_access((string) $rec['owner']);
        $snapshot = build_values($schema, (string) $rec['created'], (string) $rec['owner'], $rec['data']);
        get_db()->prepare('DELETE FROM records WHERE seq = ?')->execute([$rec['seq']]);
        trash_put($table['id'], (string) $rowId, (string) $rec['owner'], $snapshot);
        log_audit('DELETE_ROW', (string) $rowId, summarize_record($schema, $snapshot));
    }
    return ['ok' => true];
}

function trash_put(string $tableId, string $rowId, string $owner, array $snapshot): void
{
    get_db()->prepare('INSERT INTO trash(row_id, table_id, deleted_at, deleted_by, owner, data_json) VALUES(?,?,?,?,?,?)')
        ->execute([$rowId, $tableId, now_stamp(), current_user_email(), $owner, json_encode($snapshot)]);
}

/* ---------- Trash & restore ---------- */

function apiListTrash($tableId)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $schema = $table['schema'];
    $email = current_user_email();
    $isAdmin = get_role_for($email) === ROLE_ADMIN;
    $meLc = strtolower($email);
    $columns = $isAdmin ? $schema['columns'] : array_values(array_filter($schema['columns'], fn($c) => empty($c['adminOnly'])));

    $stmt = get_db()->prepare('SELECT row_id, deleted_at, deleted_by, owner, data_json FROM trash WHERE table_id = ? ORDER BY seq DESC');
    $stmt->execute([$table['id']]);
    $rows = [];
    foreach ($stmt as $r) {
        if (!$isAdmin && strtolower(trim((string) $r['owner'])) !== $meLc) continue;
        $snap = json_decode($r['data_json'] ?: '{}', true); if (!is_array($snap)) $snap = [];
        $values = [];
        foreach ($columns as $c) $values[$c['id']] = normalize_out($snap[$c['id']] ?? '', $c['type']);
        $rows[] = ['_id' => $r['row_id'], 'deletedAt' => (string) $r['deleted_at'], 'deletedBy' => (string) $r['deleted_by'], 'owner' => (string) $r['owner'], 'values' => $values];
    }
    return ['columns' => $columns, 'rows' => $rows, 'isAdmin' => $isAdmin];
}
function find_trash(string $tableId, string $rowId): ?array
{
    $s = get_db()->prepare('SELECT seq, owner, data_json FROM trash WHERE table_id = ? AND row_id = ? LIMIT 1');
    $s->execute([$tableId, $rowId]);
    $r = $s->fetch();
    return $r ?: null;
}
function apiRestoreRow($tableId, $rowId)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    require_table_write($table);
    $t = find_trash($table['id'], (string) $rowId);
    if (!$t) throw new Exception('That row is no longer in the trash.');
    $owner = (string) $t['owner'];
    if (get_role_for(current_user_email()) !== ROLE_ADMIN) {
        $me = current_user_email();
        if ($owner === '' || strtolower($owner) !== $me) throw new Exception('You can only restore rows you deleted.');
    }
    $snap = json_decode($t['data_json'] ?: '{}', true); if (!is_array($snap)) $snap = [];
    $schema = $table['schema'];
    $stamp = now_stamp();
    $created = (isset($snap[CREATED_ID]) && $snap[CREATED_ID] !== '') ? (string) $snap[CREATED_ID] : $stamp;
    $rowOwner = (isset($snap[OWNER_ID]) && $snap[OWNER_ID] !== '') ? (string) $snap[OWNER_ID] : $owner;
    $data = [];
    foreach ($schema['columns'] as $c) {
        if (is_system_id($c['id'])) continue;
        $data[$c['id']] = normalize_in($snap[$c['id']] ?? '', $c['type']);
    }
    insert_record($table['id'], (string) $rowId, $created, $rowOwner, $data);
    get_db()->prepare('DELETE FROM trash WHERE seq = ?')->execute([$t['seq']]);
    $values = build_values($schema, $created, $rowOwner, $data);
    log_audit('RESTORE_ROW', (string) $rowId, summarize_record($schema, $values));
    return ['row' => ['_id' => (string) $rowId, 'values' => $values]];
}
function apiPurgeRow($tableId, $rowId)
{
    $table = get_table($tableId);
    if (!$table) return ['ok' => true];
    require_table_write($table);
    $t = find_trash($table['id'], (string) $rowId);
    if (!$t) return ['ok' => true];
    if (get_role_for(current_user_email()) !== ROLE_ADMIN) {
        $me = current_user_email();
        if (strtolower((string) $t['owner']) !== $me) throw new Exception('You can only delete rows you deleted.');
    }
    get_db()->prepare('DELETE FROM trash WHERE seq = ?')->execute([$t['seq']]);
    log_audit('PURGE_ROW', (string) $rowId, 'permanently deleted');
    return ['ok' => true];
}
function apiEmptyTrash($tableId)
{
    $table = get_table($tableId);
    if (!$table) return ['ok' => true, 'removed' => 0];
    require_table_write($table);
    $isAdmin = get_role_for(current_user_email()) === ROLE_ADMIN;
    $meLc = current_user_email();
    $stmt = get_db()->prepare('SELECT seq, owner FROM trash WHERE table_id = ?');
    $stmt->execute([$table['id']]);
    $del = get_db()->prepare('DELETE FROM trash WHERE seq = ?');
    $removed = 0;
    foreach ($stmt->fetchAll() as $r) {
        if ($isAdmin || strtolower(trim((string) $r['owner'])) === $meLc) { $del->execute([$r['seq']]); $removed++; }
    }
    if ($removed > 0) log_audit('EMPTY_TRASH', '', 'permanently deleted ' . $removed . ' row(s)');
    return ['ok' => true, 'removed' => $removed];
}

/* ============================================================= *
 *  Attachments
 * ============================================================= */

function apiUploadFile($tableId, $payload)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('That table no longer exists.');
    require_table_write($table);
    $me = current_user_email();
    if ($me === '') throw new Exception('Please sign in before uploading files.');

    $payload = is_array($payload) ? $payload : [];
    $name = sanitize_file_name($payload['name'] ?? '');
    $mime = mb_substr(trim((string) ($payload['mime'] ?? '')), 0, 100) ?: 'application/octet-stream';
    $b64 = (string) ($payload['data'] ?? '');
    if (strncmp($b64, 'data:', 5) === 0 && ($comma = strpos($b64, ',')) !== false) $b64 = substr($b64, $comma + 1);
    $b64 = preg_replace('/\s+/', '', $b64);
    if ($b64 === '') throw new Exception('That file was empty.');
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) === 0) throw new Exception('That file could not be read.');
    if (strlen($bytes) > MAX_UPLOAD_BYTES) throw new Exception('That file is too large (max ' . round(MAX_UPLOAD_BYTES / (1024 * 1024)) . ' MB).');

    // Store the bytes in the DB (no persistent filesystem on serverless hosts).
    $fid = random_hex(24);
    get_db()->prepare('INSERT INTO files (id, name, mime, size, data_b64, owner, created) VALUES (?,?,?,?,?,?,?)')
        ->execute([$fid, $name, $mime, strlen($bytes), base64_encode($bytes), $me, now_stamp()]);
    $desc = ['id' => $fid, 'name' => $name, 'url' => 'api.php?dl=' . $fid, 'mime' => $mime, 'size' => strlen($bytes)];
    log_audit('UPLOAD_FILE', $fid, $name . ' (' . strlen($bytes) . ' bytes)');
    return $desc;
}

/** Fetch a stored attachment by id (for the download endpoint in api.php). */
function get_file(string $id): ?array
{
    $s = get_db()->prepare('SELECT id, name, mime, size, data_b64 FROM files WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

/* ============================================================= *
 *  Saved views
 * ============================================================= */

function clean_date_str($s): string
{
    $s = (string) $s;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : '';
}
function sanitize_view_config($config): array
{
    $config = is_array($config) ? $config : [];
    $s = (isset($config['sort']) && is_array($config['sort'])) ? $config['sort'] : [];
    $vt = (string) ($config['viewType'] ?? '');
    $out = [
        'search' => mb_substr((string) ($config['search'] ?? ''), 0, 200),
        'filters' => [],
        'sort' => ['colId' => !empty($s['colId']) ? (string) $s['colId'] : null, 'dir' => ((string) ($s['dir'] ?? '') === '-1' || ($s['dir'] ?? 1) === -1) ? -1 : 1],
        'viewType' => in_array($vt, ['kanban', 'dashboard'], true) ? $vt : 'grid',
        'groupColId' => mb_substr((string) ($config['groupColId'] ?? ''), 0, 60),
        'gridGroupColId' => mb_substr((string) ($config['gridGroupColId'] ?? ''), 0, 60),
        'calendarColId' => mb_substr((string) ($config['calendarColId'] ?? ''), 0, 60),
        'galleryColId' => mb_substr((string) ($config['galleryColId'] ?? ''), 0, 60),
        'charts' => [],
        'colorRules' => [],
    ];
    if (isset($config['filters']) && is_array($config['filters'])) {
        foreach (array_slice($config['filters'], 0, 50) as $f) {
            if (!is_array($f)) continue;
            $out['filters'][] = [
                'colId' => mb_substr((string) ($f['colId'] ?? ''), 0, 60),
                'op' => mb_substr((string) ($f['op'] ?? ''), 0, 24),
                'value' => mb_substr((string) ($f['value'] ?? ''), 0, 200),
                'value2' => mb_substr((string) ($f['value2'] ?? ''), 0, 200),
            ];
        }
    }
    if (isset($config['charts']) && is_array($config['charts'])) {
        foreach ($config['charts'] as $ch) {
            if (count($out['charts']) >= 3) break;
            if (!is_array($ch)) continue;
            $colId = mb_substr((string) ($ch['colId'] ?? ''), 0, 60);
            if ($colId === '') continue;
            $ct = (string) ($ch['chartType'] ?? '');
            if (!in_array($ct, ['bar', 'pie', 'line', 'donut'], true)) $ct = 'bar';
            $out['charts'][] = ['colId' => $colId, 'chartType' => $ct, 'dateColId' => mb_substr((string) ($ch['dateColId'] ?? ''), 0, 60), 'from' => clean_date_str($ch['from'] ?? ''), 'to' => clean_date_str($ch['to'] ?? '')];
        }
    }
    if (isset($config['colorRules']) && is_array($config['colorRules'])) {
        foreach ($config['colorRules'] as $cr) {
            if (count($out['colorRules']) >= 25) break;
            if (!is_array($cr)) continue;
            $crCol = mb_substr((string) ($cr['colId'] ?? ''), 0, 60);
            if ($crCol === '') continue;
            $out['colorRules'][] = [
                'colId' => $crCol, 'op' => mb_substr((string) ($cr['op'] ?? ''), 0, 24),
                'value' => mb_substr((string) ($cr['value'] ?? ''), 0, 200), 'value2' => mb_substr((string) ($cr['value2'] ?? ''), 0, 200),
                'style' => mb_substr((string) ($cr['style'] ?? ''), 0, 24),
            ];
        }
    }
    return $out;
}
function views_for_client(string $tableId, string $email, bool $isAdmin): array
{
    $meLc = strtolower(trim($email));
    $stmt = get_db()->prepare('SELECT id, owner, name, scope, config_json, ord FROM views WHERE table_id = ?');
    $stmt->execute([$tableId]);
    $out = [];
    foreach ($stmt as $r) {
        $owner = strtolower(trim((string) $r['owner']));
        $scope = (strtolower(trim((string) $r['scope'])) === VIEW_SCOPE_SHARED) ? VIEW_SCOPE_SHARED : VIEW_SCOPE_PERSONAL;
        if ($scope === VIEW_SCOPE_PERSONAL && $owner !== $meLc) continue;
        $cfg = json_decode($r['config_json'] ?: '{}', true); if (!is_array($cfg)) $cfg = [];
        $out[] = [
            'id' => $r['id'], 'name' => trim((string) $r['name']) !== '' ? $r['name'] : 'Untitled',
            'scope' => $scope, 'config' => $cfg, 'order' => (int) $r['ord'],
            'mine' => $owner === $meLc, 'canEdit' => ($scope === VIEW_SCOPE_SHARED) ? $isAdmin : ($owner === $meLc),
        ];
    }
    usort($out, function ($a, $b) {
        if ($a['scope'] !== $b['scope']) return $a['scope'] === VIEW_SCOPE_SHARED ? -1 : 1;
        return ($a['order'] - $b['order']) ?: strcmp($a['name'], $b['name']);
    });
    return $out;
}
function apiListViews($tableId)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $email = current_user_email();
    return ['views' => views_for_client($table['id'], $email, get_role_for($email) === ROLE_ADMIN)];
}
function apiSaveView($tableId, $view)
{
    $table = get_table($tableId);
    if (!$table) throw new Exception('No table found.');
    $view = is_array($view) ? $view : [];
    $me = current_user_email();
    if ($me === '') throw new Exception('Sign in to save a view.');
    $meLc = strtolower($me);
    $isAdmin = get_role_for($me) === ROLE_ADMIN;
    $name = trim((string) ($view['name'] ?? ''));
    if ($name === '') throw new Exception('Enter a view name.');
    if (mb_strlen($name) > MAX_VIEW_NAME_LEN) throw new Exception('View name can be at most ' . MAX_VIEW_NAME_LEN . ' characters.');
    $scope = ((string) ($view['scope'] ?? '') === VIEW_SCOPE_SHARED) ? VIEW_SCOPE_SHARED : VIEW_SCOPE_PERSONAL;
    if ($scope === VIEW_SCOPE_SHARED) require_admin();
    $json = json_encode(sanitize_view_config($view['config'] ?? []));
    if (strlen($json) > 8000) throw new Exception('That view is too complex to save.');
    $db = get_db();
    $id = trim((string) ($view['id'] ?? ''));
    if ($id !== '') {
        $s = $db->prepare('SELECT table_id, owner, scope, ord FROM views WHERE id = ?');
        $s->execute([$id]); $row = $s->fetch();
        if (!$row) throw new Exception('That view no longer exists.');
        if ((string) $row['table_id'] !== $table['id']) throw new Exception('That view belongs to another table.');
        $existScope = (strtolower(trim((string) $row['scope'])) === VIEW_SCOPE_SHARED) ? VIEW_SCOPE_SHARED : VIEW_SCOPE_PERSONAL;
        $existOwner = strtolower(trim((string) $row['owner']));
        $mayEdit = ($existScope === VIEW_SCOPE_SHARED) ? $isAdmin : ($existOwner === $meLc);
        if (!$mayEdit) throw new Exception('You can only change your own views.');
        $keepOwner = $existOwner !== '' ? $existOwner : $meLc;
        $db->prepare('UPDATE views SET owner=?, name=?, scope=?, config_json=? WHERE id=?')->execute([$keepOwner, $name, $scope, $json, $id]);
    } else {
        $id = 'v_' . random_hex(12);
        $ord = (int) ($db->query('SELECT COALESCE(MAX(ord), -1) + 1 FROM views WHERE table_id = ' . $db->quote($table['id']))->fetchColumn());
        $db->prepare('INSERT INTO views(id, table_id, owner, name, scope, config_json, ord) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $table['id'], $meLc, $name, $scope, $json, $ord]);
    }
    log_audit('SAVE_VIEW', $id, $name . ' (' . $scope . ')');
    return ['ok' => true, 'id' => $id, 'views' => views_for_client($table['id'], $me, $isAdmin)];
}
function apiDeleteView($viewId)
{
    $db = get_db();
    $s = $db->prepare('SELECT table_id, owner, name, scope FROM views WHERE id = ?');
    $s->execute([(string) $viewId]); $row = $s->fetch();
    if (!$row) return ['ok' => true, 'views' => []];
    $me = current_user_email(); $meLc = strtolower($me);
    $isAdmin = get_role_for($me) === ROLE_ADMIN;
    $scope = (strtolower(trim((string) $row['scope'])) === VIEW_SCOPE_SHARED) ? VIEW_SCOPE_SHARED : VIEW_SCOPE_PERSONAL;
    $owner = strtolower(trim((string) $row['owner']));
    $mayDelete = ($scope === VIEW_SCOPE_SHARED) ? $isAdmin : ($owner === $meLc);
    if (!$mayDelete) throw new Exception('You can only delete your own views.');
    $db->prepare('DELETE FROM views WHERE id = ?')->execute([(string) $viewId]);
    log_audit('DELETE_VIEW', (string) $viewId, (string) $row['name']);
    return ['ok' => true, 'views' => views_for_client((string) $row['table_id'], $me, $isAdmin)];
}

/* ============================================================= *
 *  Access control + audit viewer
 * ============================================================= */

function apiListRoles()
{
    require_admin();
    $owner = owner_email();
    $map = get_roles_map();
    $list = [];
    if ($owner !== '') $list[] = ['email' => $owner, 'role' => ROLE_ADMIN, 'owner' => true];
    foreach ($map as $em => $role) {
        if ($owner !== '' && $em === $owner) continue;
        $list[] = ['email' => $em, 'role' => $role, 'owner' => false];
    }
    usort($list, function ($a, $b) {
        if ($a['owner'] !== $b['owner']) return $a['owner'] ? -1 : 1;
        return strcmp($a['email'], $b['email']);
    });
    return ['roles' => $list, 'me' => current_user_email(), 'owner' => $owner];
}
function apiSetRole($email, $role)
{
    require_admin();
    $email = strtolower(trim((string) $email));
    if ($email === '' || strpos($email, '@') < 1) throw new Exception('Enter a valid email address.');
    $role = (strtolower((string) $role) === ROLE_ADMIN) ? ROLE_ADMIN : ROLE_MEMBER;
    if ($email === owner_email()) throw new Exception('The owner is always an administrator.');
    get_db()->prepare('INSERT INTO roles(email, role) VALUES(?,?) ON CONFLICT(email) DO UPDATE SET role = excluded.role')->execute([$email, $role]);
    log_audit('SET_ROLE', $email, 'role = ' . $role);
    return ['ok' => true];
}
function apiRemoveRole($email)
{
    require_admin();
    $email = strtolower(trim((string) $email));
    if ($email === owner_email()) throw new Exception('The owner can’t be removed.');
    get_db()->prepare('DELETE FROM roles WHERE email = ?')->execute([$email]);
    log_audit('REMOVE_ROLE', $email, '');
    return ['ok' => true];
}
function apiGetAuditLog($limit = 200)
{
    require_admin();
    $limit = (int) $limit; if ($limit <= 0) $limit = 200;
    $stmt = get_db()->prepare('SELECT ts, "user", action, target, details FROM audit ORDER BY seq DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $out = [];
    foreach ($stmt as $r) {
        $out[] = ['time' => (string) $r['ts'], 'user' => (string) $r['user'], 'action' => (string) $r['action'], 'target' => (string) $r['target'], 'details' => (string) $r['details']];
    }
    return ['entries' => $out];
}
