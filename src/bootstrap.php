<?php
// Shared setup: config, database, sessions, auth and small helpers.
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function env(string $key, string $default = ''): string
{
    static $env = null;
    $env ??= require APP_ROOT . '/env.php';
    return (string)($env[$key] ?? $default);
}

/** Config value: env.php wins, then the Settings page (settings table). */
function conf(string $key, string $default = ''): string
{
    $v = env($key);
    return $v !== '' ? $v : (setting($key) ?? $default);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . env('DB_PATH'), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS pages (
        id TEXT PRIMARY KEY, name TEXT, category TEXT, link TEXT, picture TEXT,
        can_post INTEGER DEFAULT 0, tasks TEXT, token TEXT, fan_count INTEGER, updated_at TEXT
    );
    CREATE TABLE IF NOT EXISTS groups (
        id TEXT PRIMARY KEY, name TEXT, url TEXT, created_at TEXT, last_scan_at TEXT
    );
    CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY,
        source TEXT NOT NULL, source_id TEXT, source_name TEXT,
        ext_id TEXT NOT NULL, author TEXT, text TEXT, url TEXT,
        posted_at TEXT, captured_at TEXT, seen INTEGER DEFAULT 0,
        ai_status TEXT DEFAULT 'pending', ai_priority INTEGER, ai_category TEXT,
        ai_summary TEXT, ai_action TEXT, ai_reply TEXT, ai_flags TEXT, ai_error TEXT,
        UNIQUE(source, ext_id)
    );
    CREATE INDEX IF NOT EXISTS posts_seen ON posts(seen, captured_at);
    CREATE INDEX IF NOT EXISTS posts_source ON posts(source, source_id, captured_at);
    CREATE TABLE IF NOT EXISTS group_stats (group_id TEXT NOT NULL, day TEXT NOT NULL, members INTEGER, PRIMARY KEY(group_id, day));
    CREATE TABLE IF NOT EXISTS tracked_pages (
        id TEXT PRIMARY KEY, name TEXT, url TEXT, kind TEXT DEFAULT 'liked', followers INTEGER, likes INTEGER, created_at TEXT, last_scan_at TEXT
    );
    CREATE TABLE IF NOT EXISTS page_stats (page_id TEXT NOT NULL, day TEXT NOT NULL, followers INTEGER, likes INTEGER, PRIMARY KEY(page_id, day));
    CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT, at INTEGER);
    CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY, at TEXT, kind TEXT, msg TEXT);
    CREATE TABLE IF NOT EXISTS moderation (
        id INTEGER PRIMARY KEY,
        kind TEXT NOT NULL, page_id TEXT NOT NULL, page_name TEXT, ext_id TEXT NOT NULL UNIQUE, parent_id TEXT,
        author TEXT, text TEXT, url TEXT, created_at TEXT, captured_at TEXT,
        verdict TEXT DEFAULT 'pending', severity INTEGER, reason TEXT,
        hidden INTEGER DEFAULT 0, hidden_by TEXT, hidden_at TEXT, error TEXT
    );
    CREATE INDEX IF NOT EXISTS moderation_verdict ON moderation(verdict, captured_at);
    CREATE TABLE IF NOT EXISTS videos (
        id INTEGER PRIMARY KEY, file TEXT NOT NULL UNIQUE, title TEXT, description TEXT,
        status TEXT DEFAULT 'new', sort INTEGER DEFAULT 0, page_id TEXT, fb_id TEXT, error TEXT,
        created_at TEXT, uploaded_at TEXT
    );
    SQL);
    $cols = array_column($pdo->query('PRAGMA table_info(groups)')->fetchAll(), 'name');
    if (!in_array('members', $cols, true)) $pdo->exec('ALTER TABLE groups ADD COLUMN members INTEGER');
    if (!in_array('privacy', $cols, true)) $pdo->exec('ALTER TABLE groups ADD COLUMN privacy TEXT');
}

/** Is this request for facebookstats.fullstackpartner.com? */
function is_stats_host(): bool
{
    return str_starts_with(strtolower($_SERVER['HTTP_HOST'] ?? ''), 'facebookstats.');
}

function setting(string $k): ?string
{
    $s = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

function set_setting(string $k, ?string $v): void
{
    db()->prepare('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

function app_log(string $kind, string $msg): void
{
    db()->prepare('INSERT INTO log(at, kind, msg) VALUES(?, ?, ?)')->execute([now(), $kind, mb_substr($msg, 0, 2000)]);
    db()->exec("DELETE FROM log WHERE id NOT IN (SELECT id FROM log ORDER BY id DESC LIMIT 500)");
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ago(?string $utc): string
{
    if (!$utc) return '';
    $d = time() - strtotime($utc . ' UTC');
    return match (true) {
        $d < 60 => 'just now',
        $d < 3600 => intdiv($d, 60) . ' min ago',
        $d < 86400 => intdiv($d, 3600) . ' h ago',
        default => intdiv($d, 86400) . ' d ago',
    };
}

function http_json(string $method, string $url, array $data = [], array $headers = [], int $timeout = 30): array
{
    $ch = curl_init();
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers];
    if ($method === 'GET') {
        $opts[CURLOPT_URL] = $data ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($data) : $url;
    } else {
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = in_array('Content-Type: application/json', $headers, true) ? json_encode($data) : http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($body === false) throw new RuntimeException("HTTP error: $err");
    $json = json_decode($body, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null, 'body' => $body];
}

// ---- sessions + auth ------------------------------------------------------

function start_session(): void
{
    session_save_path('/var/lib/fbgroups/sessions');
    session_name('fbg');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    session_start();
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Returns true on success. Max 10 failed tries per IP per 15 minutes. */
function attempt_login(string $email, string $password): bool
{
    $ip = client_ip();
    db()->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([time() - 900]);
    $s = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $s->execute([$ip]);
    if ((int)$s->fetchColumn() >= 10) return false;
    $ok = strcasecmp($email, env('ADMIN_EMAIL')) === 0 && password_verify($password, env('ADMIN_PASSWORD_HASH'));
    if (!$ok) {
        db()->prepare('INSERT INTO login_attempts(ip, at) VALUES(?, ?)')->execute([$ip, time()]);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    return true;
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function check_csrf(): void
{
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Form expired, go back and try again.');
    }
}

function redirect(string $to): never
{
    // Only external target we ever send people to is Facebook's login dialog.
    if (!str_starts_with($to, 'https://www.facebook.com/') && !preg_match('#^/(?!/)#', $to)) $to = '/';
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

/** "id:123" (numeric profile id) or a lower-case Facebook username, else ''. */
function clean_page_id(string $id): string
{
    $id = strtolower(trim($id));
    return preg_match('/^(id:\d{1,25}|[\w.\-]{3,80})$/', $id) ? $id : '';
}

function page_url(string $id): string
{
    return str_starts_with($id, 'id:') ? 'https://www.facebook.com/profile.php?id=' . substr($id, 3) : 'https://www.facebook.com/' . $id;
}
