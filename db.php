<?php
/**
 * Database layer — dual driver.
 *   - PostgreSQL when DATABASE_URL / POSTGRES_URL is set (production / Vercel).
 *   - SQLite file otherwise (local dev, zero setup).
 * The SQL used elsewhere is written to be compatible with both; only the DDL
 * (auto-increment type) differs, handled by serial_pk().
 */

require_once __DIR__ . '/config.php';

function db_env_url(): string
{
    foreach (['DATABASE_URL', 'POSTGRES_URL', 'POSTGRES_PRISMA_URL', 'POSTGRES_URL_NON_POOLING'] as $k) {
        $v = getenv($k);
        if ($v) return $v;
    }
    return '';
}
function db_driver(): string
{
    return db_env_url() !== '' ? 'pgsql' : 'sqlite';
}

function get_db(): PDO
{
    static $db = null;
    if ($db !== null) return $db;

    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    if (db_driver() === 'pgsql') {
        $p = parse_url(db_env_url());
        $host = $p['host'] ?? 'localhost';
        $port = $p['port'] ?? 5432;
        $name = ltrim($p['path'] ?? '', '/');
        $user = isset($p['user']) ? urldecode($p['user']) : '';
        $pass = isset($p['pass']) ? urldecode($p['pass']) : '';
        parse_str($p['query'] ?? '', $q);
        $ssl = $q['sslmode'] ?? 'require';
        $dsn = "pgsql:host=$host;port=$port;dbname=$name;sslmode=$ssl";
        // Neon serves many endpoints behind one address and uses TLS SNI to route.
        // The runtime's libpq may not send SNI, so pass the endpoint id explicitly
        // (see https://neon.com/sni). Honor an explicit ?options= in the URL, else
        // derive it from the hostname's first label (minus any -pooler suffix).
        if (!empty($q['options'])) {
            $dsn .= ';options=' . $q['options'];
        } elseif (preg_match('/^(ep-[^.]+?)(?:-pooler)?\./', $host, $m)) {
            $dsn .= ';options=endpoint=' . $m[1];
        }
        $db = new PDO($dsn, $user, $pass, $opts);
    } else {
        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
        $db = new PDO('sqlite:' . DB_FILE, null, null, $opts);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA foreign_keys = ON');
    }
    return $db;
}

/** Auto-incrementing primary key clause for the current driver. */
function serial_pk(): string
{
    return db_driver() === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
}

/** Create tables on first use and seed defaults. Idempotent (safe every request). */
function init_db(): void
{
    static $done = false;
    if ($done) return;
    $db = get_db();
    $pk = serial_pk();

    $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id ' . $pk . ', email TEXT UNIQUE NOT NULL, name TEXT, pass_hash TEXT NOT NULL, created TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tables (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, ord INTEGER NOT NULL DEFAULT 0,
        access_mode TEXT NOT NULL DEFAULT \'perUser\', schema_json TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS records (
        seq ' . $pk . ', table_id TEXT NOT NULL, row_id TEXT NOT NULL,
        created TEXT, owner TEXT, data_json TEXT NOT NULL DEFAULT \'{}\'
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_records_table ON records(table_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS trash (
        seq ' . $pk . ', row_id TEXT NOT NULL, table_id TEXT NOT NULL,
        deleted_at TEXT, deleted_by TEXT, owner TEXT, data_json TEXT NOT NULL DEFAULT \'{}\'
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS views (
        id TEXT PRIMARY KEY, table_id TEXT NOT NULL, owner TEXT, name TEXT,
        scope TEXT NOT NULL DEFAULT \'personal\', config_json TEXT NOT NULL DEFAULT \'{}\', ord INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS roles (email TEXT PRIMARY KEY, role TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS audit (
        seq ' . $pk . ', ts TEXT, "user" TEXT, action TEXT, target TEXT, details TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS files (
        id TEXT PRIMARY KEY, name TEXT, mime TEXT, size INTEGER, data_b64 TEXT, owner TEXT, created TEXT
    )');

    if (get_setting(CURRENCY_KEY, null) === null) {
        set_setting(CURRENCY_KEY, DEFAULT_CURRENCY);
    }

    $count = (int) $db->query('SELECT COUNT(*) FROM tables')->fetchColumn();
    if ($count === 0) {
        $schema = ['columns' => [make_system_col(), make_owner_col()]];
        $db->prepare('INSERT INTO tables (id, name, ord, access_mode, schema_json) VALUES (?,?,?,?,?)')
            ->execute([make_table_id(), DEFAULT_TABLE_NAME, 0, ACCESS_PER_USER, json_encode($schema)]);
    }

    ensure_shift_schedule_seed();
    $done = true;
}

/** Provision the admin-managed "Shift Schedule" table once (idempotent). */
function ensure_shift_schedule_seed(): void
{
    $db = get_db();
    if (get_setting(SHIFT_SEED_KEY, '') === '1') return;
    foreach (list_tables() as $t) {
        if (mb_strtolower($t['name']) === mb_strtolower(SHIFT_TABLE_NAME)) {
            set_setting(SHIFT_TABLE_ID_KEY, $t['id']);
            set_setting(SHIFT_SEED_KEY, '1');
            return;
        }
    }
    $cols = [
        make_system_col(), make_owner_col(),
        ['id' => make_col_id(), 'name' => 'Name',        'type' => 'text', 'options' => [], 'width' => 200, 'order' => 0],
        ['id' => $dateId = make_col_id(), 'name' => 'Date', 'type' => 'date', 'options' => [], 'width' => 150, 'order' => 1],
        ['id' => make_col_id(), 'name' => 'Shift Start', 'type' => 'time', 'options' => [], 'width' => 130, 'order' => 2],
        ['id' => make_col_id(), 'name' => 'Shift End',   'type' => 'time', 'options' => [], 'width' => 130, 'order' => 3],
        ['id' => make_col_id(), 'name' => SHIFT_STATUS_NAME,    'type' => 'dropdown', 'options' => SHIFT_STATUS_OPTS,    'width' => 140, 'order' => 4],
        ['id' => make_col_id(), 'name' => SHIFT_LOCATION_NAME,  'type' => 'dropdown', 'options' => SHIFT_LOCATION_OPTS,  'width' => 140, 'order' => 5],
        ['id' => make_col_id(), 'name' => SHIFT_HIGHLIGHT_NAME, 'type' => 'dropdown', 'options' => SHIFT_HIGHLIGHT_OPTS, 'width' => 140, 'order' => 6],
    ];
    $id = make_table_id();
    $ord = (int) $db->query('SELECT COALESCE(MAX(ord), -1) + 1 FROM tables')->fetchColumn();
    $db->prepare('INSERT INTO tables (id, name, ord, access_mode, schema_json) VALUES (?,?,?,?,?)')
        ->execute([$id, SHIFT_TABLE_NAME, $ord, ACCESS_ADMIN_MANAGED, json_encode(['columns' => $cols])]);

    $cfg = sanitize_view_config(['viewType' => 'grid', 'calendarColId' => $dateId]);
    $db->prepare('INSERT INTO views (id, table_id, owner, name, scope, config_json, ord) VALUES (?,?,?,?,?,?,?)')
        ->execute(['v_' . random_hex(12), $id, '', 'Schedule', VIEW_SCOPE_SHARED, json_encode($cfg), 0]);

    set_setting(SHIFT_TABLE_ID_KEY, $id);
    set_setting(SHIFT_SEED_KEY, '1');
}
