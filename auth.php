<?php
/**
 * Authentication — stateless, cookie-based (no server sessions, so it works on
 * serverless hosts). A signed token (HMAC-SHA256 over a small JSON payload) is
 * stored in an httpOnly cookie. Set AUTH_SECRET in the environment in production.
 *
 * Depends on helpers defined in functions.php (now_stamp, owner_email,
 * set_setting, is_valid_email, log_audit); those are resolved at call time, since
 * every entry point loads functions.php which pulls this file in.
 */

require_once __DIR__ . '/db.php';

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function auth_secret(): string
{
    $s = getenv('AUTH_SECRET');
    return ($s && strlen($s) >= 8) ? $s : 'datagrid-dev-secret-change-me';
}
function b64url(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function b64url_dec(string $d): string { return base64_decode(strtr($d, '-_', '+/')); }

function auth_make_token(string $email): string
{
    $p = b64url(json_encode(['email' => strtolower(trim($email)), 'exp' => time() + AUTH_TTL]));
    $sig = b64url(hash_hmac('sha256', $p, auth_secret(), true));
    return $p . '.' . $sig;
}
function auth_verify_token(string $tok): string
{
    if ($tok === '' || strpos($tok, '.') === false) return '';
    [$p, $sig] = explode('.', $tok, 2);
    $expect = b64url(hash_hmac('sha256', $p, auth_secret(), true));
    if (!hash_equals($expect, $sig)) return '';
    $data = json_decode(b64url_dec($p), true);
    if (!is_array($data) || empty($data['email'])) return '';
    if (isset($data['exp']) && time() > (int) $data['exp']) return '';
    return strtolower((string) $data['email']);
}
function auth_set_cookie(string $email): void
{
    $tok = auth_make_token($email);
    setcookie(AUTH_COOKIE, $tok, ['expires' => time() + AUTH_TTL, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    $_COOKIE[AUTH_COOKIE] = $tok;
}
function auth_clear_cookie(): void
{
    setcookie(AUTH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    unset($_COOKIE[AUTH_COOKIE]);
}
/** The signed-in user's email (lowercased), or '' when not signed in. */
function current_user_email(): string
{
    return auth_verify_token($_COOKIE[AUTH_COOKIE] ?? '');
}

/* ---------- Users ---------- */

function find_user(string $email): ?array
{
    $s = get_db()->prepare('SELECT * FROM users WHERE email = ?');
    $s->execute([strtolower(trim($email))]);
    $u = $s->fetch();
    return $u ?: null;
}
function users_count(): int
{
    return (int) get_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}
/** Create an account. The first-ever account becomes the owner/admin. */
function create_user(string $email, string $name, string $password): array
{
    $email = strtolower(trim($email));
    if (!is_valid_email($email)) throw new Exception('Enter a valid email address.');
    if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters.');
    if (find_user($email)) throw new Exception('An account with that email already exists.');
    $name = trim($name) !== '' ? trim($name) : explode('@', $email)[0];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    get_db()->prepare('INSERT INTO users (email, name, pass_hash, created) VALUES (?,?,?,?)')
        ->execute([$email, $name, $hash, now_stamp()]);
    if (owner_email() === '') set_setting(OWNER_KEY, $email);
    log_audit('REGISTER', $email, $name);
    return ['email' => $email, 'name' => $name];
}
/** Returns the user row on success, false otherwise. */
function verify_user(string $email, string $password)
{
    $u = find_user($email);
    if (!$u) return false;
    return password_verify($password, (string) $u['pass_hash']) ? $u : false;
}
