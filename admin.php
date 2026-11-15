<?php
require __DIR__ . '/src/eval.php';
check_csrf();
db();
$p = preg_replace('/[^a-z_]/', '', $_GET['p'] ?? 'dash');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$u = me();

function badge(string $s): string
{
    $c = ['open' => 'amber', 'in_progress' => 'blue', 'answered' => 'green', 'closed' => 'grey', 'published' => 'green', 'draft' => 'grey', 'pending' => 'amber', 'converted' => 'green', 'ignored' => 'grey', 'high' => 'green', 'medium' => 'blue', 'suggest' => 'amber', 'fallback' => 'red', 'smalltalk' => 'grey'][$s] ?? 'grey';
    return '<span class="badge ' . $c . '">' . h(str_replace('_', ' ', ucfirst($s))) . '</span>';
}
function head(string $t, string $active = ''): void
{
    global $u, $active;
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    $open = (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'open'"); $un = (int)val("SELECT COUNT(*) FROM unanswered WHERE status = 'pending'");
    $nav = ['dash' => 'Dashboard', 'kb' => 'Knowledge Base', 'unanswered' => 'Unanswered Questions', 'convs' => 'Conversations', 'tickets' => 'Officer Enquiries', 'eval' => 'Benchmark'];
    if ($u['role'] === 'admin') $nav += ['users' => 'Users', 'settings' => 'Settings', 'audit' => 'Activity Log'];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($t) . ' - Admission Chatbot</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="layout"><aside class="sidebar"><div class="brand"><span class="logo">&#127891;</span><div><b>Admission Chatbot</b><small>Management console</small></div></div>';
    foreach ($nav as $k => $l) echo '<a href="admin.php?p=' . $k . '" class="' . ($active === $k ? 'on' : '') . '">' . h($l) . ($k === 'tickets' && $open ? '<span class="cnt">' . $open . '</span>' : '') . ($k === 'unanswered' && $un ? '<span class="cnt">' . $un . '</span>' : '') . '</a>';
    echo '<div class="sp"></div><a href="index.php" target="_blank">Open the chatbot</a><a href="admin.php?p=logout">Sign out (' . h($u['name']) . ')</a></aside><main class="main"><div class="top"><h1>' . h($t) . '</h1><span class="muted">' . h(ucfirst($u['role'])) . '</span></div>';
    if ($f) echo '<div class="flash ' . h($f[1]) . '">' . h($f[0]) . '</div>';
}
function foot(): void { echo '</main></div><script src="assets/vendor/chart.umd.min.js"></script><script src="assets/admin.js"></script></body></html>'; }
function need_admin(): void { global $u; if ($u['role'] !== 'admin') { http_response_code(403); exit('Access denied: administrator only'); } }

/* ---------------------------------------------------------------- login */
if ($p === 'logout') { audit('logout'); session_destroy(); header('Location: admin.php?p=login'); exit; }
if (!$u && $p !== 'login') { header('Location: admin.php?p=login'); exit; }
if ($p === 'login') {
    if ($u) go('dash');
    if ($post) {
        $r = row('SELECT * FROM users WHERE email = ?', [strtolower(trim($_POST['email'] ?? ''))]);
        if ($r && $r['locked_until'] > time()) { flash('Too many failed attempts. Try again in a few minutes.', 'err'); go('login'); }
        if ($r && $r['status'] === 'active' && password_verify($_POST['password'] ?? '', $r['password'])) { session_regenerate_id(true); $_SESSION['uid'] = $r['id']; q('UPDATE users SET fails = 0 WHERE id = ?', [$r['id']]); audit('login', $r['role']); go('dash'); }
        if ($r) { $f = $r['fails'] + 1; q('UPDATE users SET fails = ?, locked_until = ? WHERE id = ?', [$f >= 5 ? 0 : $f, $f >= 5 ? time() + 300 : 0, $r['id']]); }
        flash('Invalid email or password.', 'err'); go('login');
    }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Staff login</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="auth"><div class="brand"><span class="logo">&#127891;</span><div><b>Admission Chatbot</b><small>Staff login</small></div></div>';
    if ($f) echo '<div class="flash ' . h($f[1]) . '">' . h($f[0]) . '</div>';
    echo '<div class="card"><form method="post">' . csrf_field() . '<label>Email address</label><input type="email" name="email" required autofocus><label>Password</label><input type="password" name="password" required><div class="actions"><button class="btn primary" style="width:100%">Sign in</button></div></form></div>
    <div class="card"><style>.demo td{padding:6px 3px!important}.demo td+td{white-space:nowrap}</style><h2>Demo accounts</h2><table style="width:100%;font-size:12px" class="demo"><tr><td>System administrator</td><td>admin@admissionbot.test</td><td>admin123</td></tr><tr><td>Admission officer</td><td>officer@admissionbot.test</td><td>officer123</td></tr></table><p class="center"><a href="index.php">Back to the chatbot</a></p></div></div></body></html>';
    exit;
}
$active = $p;

/* ---------------------------------------------------------------- charts */
if ($p === 'chart') {
    $lv = rows("SELECT level, COUNT(*) n FROM messages WHERE sender = 'bot' GROUP BY level");
    $cat = rows("SELECT intent, COUNT(*) n FROM messages WHERE sender = 'bot' AND entry_id IS NOT NULL GROUP BY intent ORDER BY n DESC LIMIT 8");
    $day = rows("SELECT substr(created, 1, 10) d, COUNT(*) n FROM messages WHERE sender = 'user' GROUP BY d ORDER BY d DESC LIMIT 14");
    $fb = rows('SELECT rating, COUNT(*) n FROM feedback GROUP BY rating');
    json_out(['levels' => $lv, 'categories' => $cat, 'days' => array_reverse($day), 'feedback' => $fb]);
}

/* ---------------------------------------------------------------- dashboard */
if ($p === 'dash') {
    $tot = (int)val("SELECT COUNT(*) FROM messages WHERE sender = 'user'"); $bot = (int)val("SELECT COUNT(*) FROM messages WHERE sender = 'bot' AND level IN ('high','medium','suggest','fallback')");
    $ans = (int)val("SELECT COUNT(*) FROM messages WHERE sender = 'bot' AND level IN ('high','medium')"); $fb = row('SELECT SUM(rating = 1) a, COUNT(*) n FROM feedback');
    $top = rows("SELECT e.question, e.views FROM entries e ORDER BY e.views DESC LIMIT 6");
    head('Dashboard', 'dash');
    echo '<div class="stats"><div class="stat"><b>' . (int)val('SELECT COUNT(*) FROM conversations') . '</b><span>Conversations</span></div><div class="stat"><b>' . $tot . '</b><span>Questions asked</span></div><div class="stat"><b>' . ($bot ? round(100 * $ans / $bot) : 0) . '%</b><span>Answered with confidence</span></div><div class="stat"><b>' . ($fb['n'] ? round(100 * $fb['a'] / $fb['n']) . '%' : '-') . '</b><span>Rated helpful (' . (int)$fb['n'] . ' ratings)</span></div></div>';
    echo '<div class="stats"><div class="stat"><b>' . (int)val("SELECT COUNT(*) FROM entries WHERE status = 'published'") . '</b><span>Published answers</span></div><div class="stat"><b>' . (int)val("SELECT COUNT(*) FROM unanswered WHERE status = 'pending'") . '</b><span>Unanswered questions</span></div><div class="stat"><b>' . (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'open'") . '</b><span>Open officer enquiries</span></div><div class="stat"><b>' . (val("SELECT ROUND(AVG(latency_ms), 1) FROM messages WHERE sender = 'bot' AND latency_ms IS NOT NULL") ?: '0') . ' ms</b><span>Average response time</span></div></div>';
    echo '<div class="two"><div class="card"><h2>Questions per day</h2><div class="chart"><canvas id="cDays"></canvas></div></div><div class="card"><h2>Answer quality</h2><div class="chart"><canvas id="cLevels"></canvas></div></div></div>';
    echo '<div class="two"><div class="card"><h2>Topics asked about</h2><div class="chart"><canvas id="cCats"></canvas></div></div><div class="card"><h2>Most viewed answers</h2><table>';
    foreach ($top as $t) echo '<tr><td>' . h($t['question']) . '</td><td><b>' . (int)$t['views'] . '</b></td></tr>';
    echo '</table></div></div><script>window.CHARTS=true</script>'; foot(); exit;
}

/* ---------------------------------------------------------------- knowledge base */
if ($p === 'kb') {
    if ($post && ($_POST['do'] ?? '') === 'delete') { $e = row('SELECT * FROM entries WHERE id = ?', [(int)$_POST['id']]); if ($e) { q('DELETE FROM entries WHERE id = ?', [$e['id']]); audit('kb_deleted', $e['code']); flash('Entry deleted.'); } go('kb'); }
    $cat = (int)($_GET['cat'] ?? 0); $qs = trim($_GET['q'] ?? '');
    $w = ['1=1']; $par = [];
    if ($cat) { $w[] = 'e.category_id = ?'; $par[] = $cat; }
    if ($qs !== '') { $w[] = '(e.question LIKE ? OR e.variants LIKE ? OR e.code LIKE ?)'; array_push($par, "%$qs%", "%$qs%", "%$qs%"); }
    $es = rows('SELECT e.*, c.name AS category FROM entries e JOIN categories c ON c.id = e.category_id WHERE ' . implode(' AND ', $w) . ' ORDER BY e.code', $par);
    head('Knowledge Base', 'kb');
    echo '<form class="filters" method="get"><input type="hidden" name="p" value="kb"><input name="q" placeholder="Search questions or code" value="' . h($qs) . '"><select name="cat" onchange="this.form.submit()"><option value="">All categories</option>';
    foreach (rows('SELECT * FROM categories ORDER BY position') as $c) echo '<option value="' . $c['id'] . '"' . ($c['id'] == $cat ? ' selected' : '') . '>' . h($c['name']) . '</option>';
    echo '</select><button class="btn">Search</button><a class="btn primary" href="admin.php?p=kb_edit">+ New answer</a></form><div class="card"><table><tr><th>Code</th><th>Question</th><th>Category</th><th>Variants</th><th>Status</th><th>Views</th><th>Helpful</th><th></th></tr>';
    foreach ($es as $e) echo '<tr><td class="mono">' . h($e['code']) . '</td><td>' . h($e['question']) . '</td><td>' . h($e['category']) . '</td><td>' . count(array_filter(explode("\n", $e['variants']))) . '</td><td>' . badge($e['status']) . '</td><td>' . (int)$e['views'] . '</td><td>' . (int)$e['helpful'] . ' / ' . (int)$e['unhelpful'] . '</td><td><a class="btn sm" href="admin.php?p=kb_edit&id=' . $e['id'] . '">Edit</a> <form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . $e['id'] . '"><button name="do" value="delete" class="btn sm danger" onclick="return confirm(\'Delete this answer?\')">Delete</button></form></td></tr>';
    if (!$es) echo '<tr><td colspan="8" class="muted">No entries found.</td></tr>';
    echo '</table></div>'; foot(); exit;
}
if ($p === 'kb_edit') {
    $id = (int)($_GET['id'] ?? 0); $e = $id ? row('SELECT * FROM entries WHERE id = ?', [$id]) : null;
    if ($post) {
        $question = trim($_POST['question'] ?? ''); $answer = trim($_POST['answer'] ?? ''); $code = strtoupper(trim($_POST['code'] ?? ''));
        if ($question === '' || $answer === '' || !preg_match('/^[A-Z0-9\-]{3,20}$/', $code) || !(int)$_POST['category_id']) { flash('Code (letters, digits, dash), category, question and answer are required.', 'err'); header('Location: admin.php?p=kb_edit&id=' . $id); exit; }
        $vars = implode("\n", array_filter(array_map('trim', explode("\n", $_POST['variants'] ?? ''))));
        $dup = val('SELECT id FROM entries WHERE code = ? AND id != ?', [$code, $id]); if ($dup) { flash('This code is already used.', 'err'); header('Location: admin.php?p=kb_edit&id=' . $id); exit; }
        if ($e) { q('UPDATE entries SET code=?, category_id=?, question=?, variants=?, answer=?, keywords=?, status=?, updated=? WHERE id=?', [$code, (int)$_POST['category_id'], $question, $vars, $answer, trim($_POST['keywords'] ?? ''), $_POST['status'] === 'draft' ? 'draft' : 'published', now(), $id]); audit('kb_updated', $code); }
        else { q('INSERT INTO entries(code, category_id, question, variants, answer, keywords, status, created, updated) VALUES(?,?,?,?,?,?,?,?,?)', [$code, (int)$_POST['category_id'], $question, $vars, $answer, trim($_POST['keywords'] ?? ''), $_POST['status'] === 'draft' ? 'draft' : 'published', now(), now()]); $id = (int)db()->lastInsertId(); audit('kb_created', $code); }
        if (!empty($_POST['from_unanswered'])) q("UPDATE unanswered SET status = 'converted', resolved_entry_id = ? WHERE id = ?", [$id, (int)$_POST['from_unanswered']]);
        flash('Answer saved. The chatbot uses it immediately.'); go('kb');
    }
    $pre = $_GET['q'] ?? ''; $un = (int)($_GET['un'] ?? 0);
    $e = $e ?: ['code' => 'ADM-' . str_pad((string)((int)val('SELECT COUNT(*) FROM entries') + 100), 3, '0', STR_PAD_LEFT), 'category_id' => 0, 'question' => $pre, 'variants' => '', 'answer' => '', 'keywords' => '', 'status' => 'published'];
    head($id ? 'Edit Answer' : 'New Answer', 'kb');
    echo '<form method="post" class="card" style="max-width:820px">' . csrf_field() . '<input type="hidden" name="from_unanswered" value="' . $un . '"><div class="row2"><div><label>Code</label><input name="code" value="' . h($e['code']) . '" required></div><div><label>Category</label><select name="category_id"><option value="">Choose</option>';
    foreach (rows('SELECT * FROM categories ORDER BY position') as $c) echo '<option value="' . $c['id'] . '"' . ($c['id'] == $e['category_id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
    echo '</select></div></div><label>Main question</label><input name="question" value="' . h($e['question']) . '" required><label>Other ways to ask it (one per line)</label><textarea name="variants" rows="5">' . h($e['variants']) . '</textarea><label>Answer</label><textarea name="answer" rows="8" required>' . h($e['answer']) . '</textarea><label>Keywords (space separated)</label><input name="keywords" value="' . h($e['keywords']) . '"><label>Status</label><select name="status"><option value="published"' . ($e['status'] === 'published' ? ' selected' : '') . '>Published</option><option value="draft"' . ($e['status'] === 'draft' ? ' selected' : '') . '>Draft (hidden from the chatbot)</option></select><div class="actions"><a class="btn" href="admin.php?p=kb">Cancel</a><button class="btn primary">Save answer</button></div></form>';
    foot(); exit;
}

/* ---------------------------------------------------------------- unanswered */
if ($p === 'unanswered') {
    if ($post) { q("UPDATE unanswered SET status = ? WHERE id = ?", [($_POST['do'] ?? '') === 'ignore' ? 'ignored' : 'pending', (int)$_POST['id']]); flash('Updated.'); go('unanswered'); }
    $rs = rows("SELECT * FROM unanswered WHERE status = 'pending' ORDER BY count DESC, last_asked DESC");
    head('Unanswered Questions', 'unanswered');
    echo '<p class="muted">Questions that the chatbot could not answer with confidence. Turn a recurring question into a new answer so that the chatbot learns it.</p><div class="card"><table><tr><th>Question</th><th>Times asked</th><th>Last asked</th><th></th></tr>';
    foreach ($rs as $r) echo '<tr><td>' . h($r['query']) . '</td><td><b>' . (int)$r['count'] . '</b></td><td>' . h(date('j M, g:i A', strtotime($r['last_asked']))) . '</td><td><a class="btn sm primary" href="admin.php?p=kb_edit&un=' . $r['id'] . '&q=' . urlencode($r['query']) . '">Create answer</a> <form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . $r['id'] . '"><button name="do" value="ignore" class="btn sm">Ignore</button></form></td></tr>';
    if (!$rs) echo '<tr><td colspan="4" class="muted">No unanswered questions.</td></tr>';
    echo '</table></div>'; foot(); exit;
}

/* ---------------------------------------------------------------- conversations */
if ($p === 'convs') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $c = row('SELECT * FROM conversations WHERE id = ?', [$id]); if (!$c) go('convs');
        $ms = rows('SELECT m.*, e.code FROM messages m LEFT JOIN entries e ON e.id = m.entry_id WHERE m.conversation_id = ? ORDER BY m.id', [$id]);
        head('Conversation #' . $id, 'convs');
        echo '<div class="card transcript"><p class="muted">Started ' . h($c['started']) . ($c['escalated'] ? ' &middot; escalated to an officer' : '') . '</p>';
        foreach ($ms as $m) echo '<div class="b ' . ($m['sender'] === 'user' ? 'user' : 'bot') . '"><div class="t">' . h($m['text']) . '</div><small>' . h(date('g:i A', strtotime($m['created']))) . ($m['sender'] === 'bot' ? ' &middot; ' . h($m['level']) . ' &middot; confidence ' . h(number_format((float)$m['confidence'], 2)) . ($m['code'] ? ' &middot; ' . h($m['code']) : '') : '') . '</small></div>';
        echo '</div><a class="btn" href="admin.php?p=convs">Back to conversations</a>'; foot(); exit;
    }
    $cs = rows("SELECT c.*, (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender = 'user') n, (SELECT text FROM messages WHERE conversation_id = c.id AND sender = 'user' ORDER BY id LIMIT 1) first_q FROM conversations c ORDER BY c.last_activity DESC LIMIT 100");
    head('Conversations', 'convs');
    echo '<div class="card"><table><tr><th>#</th><th>First question</th><th>Questions</th><th>Last activity</th><th>Escalated</th><th></th></tr>';
    foreach ($cs as $c) echo '<tr><td>' . $c['id'] . '</td><td>' . h(mb_strimwidth((string)$c['first_q'], 0, 70, '...')) . '</td><td>' . (int)$c['n'] . '</td><td>' . h(date('j M, g:i A', strtotime($c['last_activity']))) . '</td><td>' . ($c['escalated'] ? badge('open') : '-') . '</td><td><a class="btn sm" href="admin.php?p=convs&id=' . $c['id'] . '">View</a></td></tr>';
    if (!$cs) echo '<tr><td colspan="6" class="muted">No conversations yet.</td></tr>';
    echo '</table></div>'; foot(); exit;
}

/* ---------------------------------------------------------------- tickets (officer enquiries) */
if ($p === 'tickets') {
    $id = (int)($_GET['id'] ?? 0);
    if ($post && $id) {
        $t = row('SELECT * FROM tickets WHERE id = ?', [$id]);
        if ($t) { $st = in_array($_POST['status'] ?? '', ['open', 'in_progress', 'answered', 'closed']) ? $_POST['status'] : $t['status']; $reply = trim($_POST['reply'] ?? ''); if ($reply !== '' && $st === 'open') $st = 'answered';
            q('UPDATE tickets SET status = ?, reply = ?, assigned_to = ?, updated = ? WHERE id = ?', [$st, $reply ?: $t['reply'], $u['id'], now(), $id]); audit('ticket_updated', $t['ref'] . ' ' . $st); flash('Enquiry updated. Send your reply to ' . $t['email'] . '.'); }
        go('tickets', '&id=' . $id);
    }
    if ($id) {
        $t = row('SELECT * FROM tickets WHERE id = ?', [$id]); if (!$t) go('tickets');
        head('Enquiry ' . $t['ref'], 'tickets');
        echo '<div class="two"><div class="card"><h2>' . h($t['subject']) . ' ' . badge($t['status']) . '</h2><p><b>' . h($t['name']) . '</b> &middot; ' . h($t['email']) . ($t['phone'] ? ' &middot; ' . h($t['phone']) : '') . '</p><p class="muted">Received ' . h($t['created']) . '</p><p>' . nl2br(h($t['message'])) . '</p>' . ($t['conversation_id'] ? '<a class="btn sm" href="admin.php?p=convs&id=' . $t['conversation_id'] . '">View the conversation</a>' : '') . '</div><form method="post" class="card">' . csrf_field() . '<h2>Reply</h2><label>Status</label><select name="status"><option value="open"' . ($t['status'] === 'open' ? ' selected' : '') . '>Open</option><option value="in_progress"' . ($t['status'] === 'in_progress' ? ' selected' : '') . '>In progress</option><option value="answered"' . ($t['status'] === 'answered' ? ' selected' : '') . '>Answered</option><option value="closed"' . ($t['status'] === 'closed' ? ' selected' : '') . '>Closed</option></select><label>Reply to the applicant</label><textarea name="reply" rows="7">' . h($t['reply']) . '</textarea><div class="actions"><button class="btn primary">Save reply</button></div></form></div>';
        foot(); exit;
    }
    $st = $_GET['status'] ?? ''; $ts = rows('SELECT * FROM tickets' . ($st ? ' WHERE status = ?' : '') . ' ORDER BY id DESC LIMIT 200', $st ? [$st] : []);
    head('Officer Enquiries', 'tickets');
    echo '<form class="filters" method="get"><input type="hidden" name="p" value="tickets"><select name="status" onchange="this.form.submit()"><option value="">All statuses</option>';
    foreach (['open', 'in_progress', 'answered', 'closed'] as $s) echo '<option value="' . $s . '"' . ($s === $st ? ' selected' : '') . '>' . h(str_replace('_', ' ', ucfirst($s))) . '</option>';
    echo '</select></form><div class="card"><table><tr><th>Reference</th><th>Applicant</th><th>Subject</th><th>Received</th><th>Status</th><th></th></tr>';
    foreach ($ts as $t) echo '<tr><td class="mono">' . h($t['ref']) . '</td><td>' . h($t['name']) . '<br><span class="muted">' . h($t['email']) . '</span></td><td>' . h($t['subject']) . '</td><td>' . h(date('j M, g:i A', strtotime($t['created']))) . '</td><td>' . badge($t['status']) . '</td><td><a class="btn sm" href="admin.php?p=tickets&id=' . $t['id'] . '">Open</a></td></tr>';
    if (!$ts) echo '<tr><td colspan="6" class="muted">No enquiries yet.</td></tr>';
    echo '</table></div>'; foot(); exit;
}

/* ---------------------------------------------------------------- benchmark */
if ($p === 'eval') {
    $sets = require __DIR__ . '/src/testsets.php';
    if ($post) { $which = ($_POST['set'] ?? 'heldout') === 'dev' ? 'dev' : 'heldout'; $r = evaluate($sets[$which]); q('INSERT INTO evaluations(created, n, top1, top3, fallback_ok, avg_ms, detail) VALUES(?,?,?,?,?,?,?)', [now(), $r['total'], $r['top1'], $r['top3'], $r['rejection'], $r['avg_ms'], json_encode($r + ['set' => $which])]); audit('benchmark_run', $which); flash('Benchmark completed on the ' . ($which === 'dev' ? 'development' : 'held-out') . ' set.'); go('eval'); }
    $runs = rows('SELECT * FROM evaluations ORDER BY id DESC LIMIT 10');
    head('Benchmark', 'eval');
    echo '<form method="post" class="card inline-form">' . csrf_field() . '<h2>Run the benchmark</h2><p class="muted">Runs a labelled set of applicant questions (including questions outside the admission information) through the chatbot and measures accuracy.</p><select name="set" style="max-width:320px"><option value="heldout">Held-out test set (' . count($sets['heldout']) . ' questions)</option><option value="dev">Development set (' . count($sets['dev']) . ' questions)</option></select> <button class="btn primary" style="margin-top:10px">Run benchmark</button></form>';
    if ($runs) { $d = json_decode($runs[0]['detail'], true); echo '<div class="stats"><div class="stat"><b>' . $d['top1'] . '%</b><span>Correct answer first (top-1)</span></div><div class="stat"><b>' . $d['top3'] . '%</b><span>Correct answer in top 3</span></div><div class="stat"><b>' . $d['rejection'] . '%</b><span>Out-of-scope questions refused</span></div><div class="stat"><b>' . $d['avg_ms'] . ' ms</b><span>Average processing time</span></div></div>'; }
    echo '<div class="card"><h2>Previous runs</h2><table><tr><th>Time</th><th>Set</th><th>Questions</th><th>Top-1</th><th>Top-3</th><th>Refused correctly</th><th>Baseline top-1</th></tr>';
    foreach ($runs as $r) { $d = json_decode($r['detail'], true); echo '<tr><td>' . h($r['created']) . '</td><td>' . h($d['set'] ?? '') . '</td><td>' . (int)$r['n'] . '</td><td>' . $r['top1'] . '%</td><td>' . $r['top3'] . '%</td><td>' . $r['fallback_ok'] . '%</td><td>' . ($d['baseline_top1'] ?? '-') . '%</td></tr>'; }
    if (!$runs) echo '<tr><td colspan="7" class="muted">No benchmark has been run yet.</td></tr>';
    echo '</table></div>'; foot(); exit;
}

/* ---------------------------------------------------------------- admin-only pages */
if ($p === 'users') {
    need_admin();
    if ($post) {
        $do = $_POST['do'] ?? '';
        if ($do === 'add') { $em = strtolower(trim($_POST['email'] ?? '')); if (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($_POST['password'] ?? '') < 8 || trim($_POST['name'] ?? '') === '') flash('Enter a name, a valid email and a password of at least 8 characters.', 'err'); elseif (val('SELECT 1 FROM users WHERE email = ?', [$em])) flash('This email is already registered.', 'err'); else { q('INSERT INTO users(role,name,email,password,created) VALUES(?,?,?,?,?)', [$_POST['role'] === 'admin' ? 'admin' : 'officer', trim($_POST['name']), $em, password_hash($_POST['password'], PASSWORD_DEFAULT), now()]); audit('user_added', $em); flash('Staff account created.'); } }
        if (in_array($do, ['activate', 'deactivate']) && (int)$_POST['id'] !== (int)$u['id']) { q('UPDATE users SET status = ? WHERE id = ?', [$do === 'activate' ? 'active' : 'inactive', (int)$_POST['id']]); audit('user_' . $do, (string)$_POST['id']); flash('Account updated.'); }
        go('users');
    }
    head('Staff Accounts', 'users');
    echo '<div class="two"><div class="card"><table><tr><th>Name</th><th>Role</th><th>Status</th><th></th></tr>';
    foreach (rows('SELECT * FROM users ORDER BY id') as $x) echo '<tr><td><b>' . h($x['name']) . '</b><br><span class="muted">' . h($x['email']) . '</span></td><td>' . h(ucfirst($x['role'])) . '</td><td>' . badge($x['status'] === 'active' ? 'published' : 'draft') . '</td><td>' . ($x['id'] != $u['id'] ? '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . $x['id'] . '"><button name="do" value="' . ($x['status'] === 'active' ? 'deactivate' : 'activate') . '" class="btn sm">' . ($x['status'] === 'active' ? 'Deactivate' : 'Activate') . '</button></form>' : '') . '</td></tr>';
    echo '</table></div><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="do" value="add"><h2>Add a staff account</h2><label>Name</label><input name="name" required><label>Email</label><input type="email" name="email" required><label>Role</label><select name="role"><option value="officer">Admission officer</option><option value="admin">System administrator</option></select><label>Password (min 8)</label><input type="password" name="password" minlength="8" required><div class="actions"><button class="btn primary">Create account</button></div></form></div>';
    foot(); exit;
}
if ($p === 'settings') {
    need_admin();
    if ($post) {
        $h = (float)$_POST['high']; $m = (float)$_POST['medium']; $l = (float)$_POST['low'];
        if (!($h > $m && $m > $l && $l > 0 && $h <= 1)) { flash('Thresholds must satisfy 0 < low < medium < high <= 1.', 'err'); go('settings'); }
        foreach (['institution', 'bot_name', 'welcome', 'contact_email', 'contact_phone', 'office_hours'] as $k) set_setting($k, trim($_POST[$k] ?? ''));
        foreach (['high', 'medium', 'low'] as $k) set_setting($k, (string)(float)$_POST[$k]);
        audit('settings_updated'); flash('Settings saved.'); go('settings');
    }
    head('Settings', 'settings');
    echo '<form method="post" class="card" style="max-width:820px">' . csrf_field() . '<h2>Chatbot</h2><div class="row2"><div><label>Institution name</label><input name="institution" value="' . h(setting('institution')) . '"></div><div><label>Chatbot name</label><input name="bot_name" value="' . h(setting('bot_name')) . '"></div></div><label>Welcome message</label><textarea name="welcome" rows="3">' . h(setting('welcome')) . '</textarea><div class="row2"><div><label>Contact email</label><input name="contact_email" value="' . h(setting('contact_email')) . '"></div><div><label>Office hours</label><input name="office_hours" value="' . h(setting('office_hours')) . '"></div></div>
    <h2 style="margin-top:20px">Confidence thresholds</h2><p class="muted">A match at or above <b>high</b> is answered directly, at or above <b>medium</b> is answered with a confirmation, at or above <b>low</b> the chatbot offers suggestions, and below <b>low</b> it refers the applicant to an officer.</p><div class="row2"><div><label>High</label><input name="high" type="number" step="0.01" value="' . h(setting('high')) . '"></div><div><label>Medium</label><input name="medium" type="number" step="0.01" value="' . h(setting('medium')) . '"></div><div><label>Low</label><input name="low" type="number" step="0.01" value="' . h(setting('low')) . '"></div></div><div class="actions"><button class="btn primary">Save settings</button></div></form>';
    foot(); exit;
}
if ($p === 'audit') {
    need_admin();
    head('Activity Log', 'audit');
    echo '<div class="card"><table><tr><th>Time</th><th>User</th><th>Action</th><th>Detail</th></tr>';
    foreach (rows('SELECT a.*, u.name FROM audit a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 200') as $a) echo '<tr><td>' . h($a['created']) . '</td><td>' . h($a['name']) . '</td><td>' . h($a['action']) . '</td><td>' . h($a['detail']) . '</td></tr>';
    echo '</table></div>'; foot(); exit;
}
go('dash');
