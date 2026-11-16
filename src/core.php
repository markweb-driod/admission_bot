<?php
/** Core of the Admission Chatbot: database, schema, knowledge-base seeding, settings, auth, CSRF, audit. */
date_default_timezone_set('Africa/Lagos');
define('ROOT', dirname(__DIR__));
if (getenv('VERCEL')) {
    // Vercel's filesystem is read-only except /tmp: work on a per-instance copy of the bundled database.
    define('DB_FILE', '/tmp/chatbot.sqlite');
    if (!file_exists(DB_FILE) && file_exists(ROOT . '/data/chatbot.sqlite') && copy(ROOT . '/data/chatbot.sqlite', DB_FILE)) refresh_demo_dates(DB_FILE);
} else {
    define('DB_FILE', ROOT . '/data/chatbot.sqlite');
}
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) session_start();

const DEFAULTS = ['institution' => 'Nasarawa State University, Keffi', 'bot_name' => 'Admission Assistant', 'welcome' => 'Hello! I am the admission assistant. Ask me about admission requirements, UTME and Direct Entry, screening, acceptance, documents or fees.',
    'high' => '0.50', 'medium' => '0.32', 'low' => '0.20', 'contact_email' => 'admissions@example.edu.ng', 'contact_phone' => '', 'office_hours' => 'Monday to Friday, 8:00 am to 4:00 pm'];

/** Moves every activity timestamp forward by whole days so the newest message falls on today and the demo dashboard always looks current. */
function refresh_demo_dates(string $file): void
{
    $d = new PDO('sqlite:' . $file);
    $last = $d->query('SELECT MAX(created) FROM messages')->fetchColumn();
    if (!$last) return;
    $days = (int)((strtotime(date('Y-m-d')) - strtotime(substr($last, 0, 10))) / 86400);
    if ($days <= 0) return;
    $cols = ['conversations' => ['started', 'last_activity'], 'messages' => ['created'], 'feedback' => ['created'], 'tickets' => ['created', 'updated'], 'unanswered' => ['last_asked']];
    $d->beginTransaction();
    foreach ($cols as $t => $cs) {
        $set = implode(', ', array_map(fn($c) => "$c = datetime($c, '+$days days')", $cs));
        $d->exec("UPDATE $t SET $set");
    }
    $d->commit();
}

function db(): PDO
{
    static $pdo;
    if ($pdo) return $pdo;
    $new = !file_exists(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, role TEXT, name TEXT, email TEXT UNIQUE, password TEXT, status TEXT DEFAULT 'active', fails INTEGER DEFAULT 0, locked_until INTEGER DEFAULT 0, created TEXT);
    CREATE TABLE IF NOT EXISTS categories(id INTEGER PRIMARY KEY, name TEXT UNIQUE, position INTEGER DEFAULT 0);
    CREATE TABLE IF NOT EXISTS entries(id INTEGER PRIMARY KEY, code TEXT UNIQUE, category_id INTEGER REFERENCES categories(id), question TEXT, variants TEXT, answer TEXT, keywords TEXT, status TEXT DEFAULT 'published', views INTEGER DEFAULT 0, helpful INTEGER DEFAULT 0, unhelpful INTEGER DEFAULT 0, created TEXT, updated TEXT);
    CREATE TABLE IF NOT EXISTS conversations(id INTEGER PRIMARY KEY, session_id TEXT, started TEXT, last_activity TEXT, last_entry_id INTEGER, escalated INTEGER DEFAULT 0);
    CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY, conversation_id INTEGER REFERENCES conversations(id) ON DELETE CASCADE, sender TEXT, text TEXT, entry_id INTEGER, intent TEXT, confidence REAL, level TEXT, latency_ms REAL, created TEXT);
    CREATE TABLE IF NOT EXISTS feedback(id INTEGER PRIMARY KEY, message_id INTEGER UNIQUE REFERENCES messages(id) ON DELETE CASCADE, rating INTEGER, created TEXT);
    CREATE TABLE IF NOT EXISTS tickets(id INTEGER PRIMARY KEY, ref TEXT UNIQUE, conversation_id INTEGER, name TEXT, email TEXT, phone TEXT, subject TEXT, message TEXT, status TEXT DEFAULT 'open', reply TEXT, assigned_to INTEGER, created TEXT, updated TEXT);
    CREATE TABLE IF NOT EXISTS unanswered(id INTEGER PRIMARY KEY, query TEXT, normalized TEXT UNIQUE, count INTEGER DEFAULT 1, status TEXT DEFAULT 'pending', last_asked TEXT, resolved_entry_id INTEGER);
    CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, detail TEXT, created TEXT);
    CREATE TABLE IF NOT EXISTS evaluations(id INTEGER PRIMARY KEY, created TEXT, n INTEGER, top1 REAL, top3 REAL, fallback_ok REAL, avg_ms REAL, detail TEXT);
    CREATE INDEX IF NOT EXISTS idx_msg ON messages(conversation_id, id);");
    if ($new) seed($pdo);
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { return q($sql, $p)->fetch() ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function now(): string { return date('Y-m-d H:i:s'); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function setting(string $k): string
{
    static $c = null;
    if ($c === null) { $c = DEFAULTS; foreach (rows('SELECT k, v FROM settings') as $r) $c[$r['k']] = $r['v']; }
    return (string)($c[$k] ?? '');
}
function set_setting(string $k, string $v): void { q('INSERT OR REPLACE INTO settings(k, v) VALUES(?, ?)', [$k, $v]); }

function seed(PDO $d): void
{
    $u = $d->prepare('INSERT INTO users(role,name,email,password,created) VALUES(?,?,?,?,?)');
    $u->execute(['admin', 'System Administrator', 'admin@admissionbot.test', password_hash('admin123', PASSWORD_DEFAULT), now()]);
    $u->execute(['officer', 'Admission Officer', 'officer@admissionbot.test', password_hash('officer123', PASSWORD_DEFAULT), now()]);
    $cat = $d->prepare('INSERT INTO categories(name, position) VALUES(?, ?)');
    $ent = $d->prepare('INSERT INTO entries(code, category_id, question, variants, answer, keywords, created, updated) VALUES(?,?,?,?,?,?,?,?)');
    foreach (require __DIR__ . '/kb_seed.php' as $i => [$cname, $items]) {
        $cat->execute([$cname, $i]); $cid = (int)$d->lastInsertId();
        foreach ($items as $e) $ent->execute([$e[0], $cid, $e[1], implode("\n", $e[2]), $e[3], $e[4], now(), now()]);
    }
}

/* ------------------------------------------------------------------ auth */
function me(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $u = isset($_SESSION['uid']) ? row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['uid']]) : null;
    return $u;
}
function go(string $p, string $x = ''): void { header('Location: admin.php?p=' . $p . $x); exit; }
function flash(string $m, string $t = 'ok'): void { $_SESSION['flash'] = [$m, $t]; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="_t" value="' . csrf() . '">'; }
function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf(), $_POST['_t'] ?? '')) { http_response_code(400); exit('Invalid request token'); }
}
function audit(string $a, string $d = ''): void { q('INSERT INTO audit(user_id, action, detail, created) VALUES(?,?,?,?)', [$_SESSION['uid'] ?? null, $a, $d, now()]); }
function json_out($d, int $status = 200): void
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
}
