<?php
/** JSON API of the chatbot: chat, history, popular questions, feedback, ticket (escalation). */
require __DIR__ . '/src/nlp.php';
$a = $_GET['a'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$sid = preg_replace('/[^a-zA-Z0-9\-]/', '', $in['session'] ?? ($_GET['session'] ?? ''));

function conversation(string $sid): array
{
    $c = row('SELECT * FROM conversations WHERE session_id = ?', [$sid]);
    if ($c) return $c;
    q('INSERT INTO conversations(session_id, started, last_activity) VALUES(?,?,?)', [$sid, now(), now()]);
    return row('SELECT * FROM conversations WHERE session_id = ?', [$sid]);
}
function public_entry(int $id): ?array { return row('SELECT id, question FROM entries WHERE id = ? AND status = "published"', [$id]); }

try {
    if ($a === 'config') json_out(['institution' => setting('institution'), 'bot' => setting('bot_name'), 'welcome' => setting('welcome'), 'hours' => setting('office_hours')]);
    if ($a === 'popular') {
        $r = rows("SELECT id, question FROM entries WHERE status = 'published' ORDER BY views DESC, id LIMIT 8");
        json_out(['questions' => $r]);
    }
    if ($sid === '' || strlen($sid) < 8) json_out(['error' => 'Missing session'], 400);
    if ($a === 'history') {
        $c = row('SELECT id FROM conversations WHERE session_id = ?', [$sid]);
        $m = $c ? rows('SELECT id, sender, text, entry_id, level, created FROM messages WHERE conversation_id = ? ORDER BY id', [$c['id']]) : [];
        json_out(['messages' => $m]);
    }
    if ($a === 'chat') {
        $text = trim((string)($in['message'] ?? ''));
        if ($text === '') json_out(['error' => 'Type a question first'], 400);
        if (mb_strlen($text) > 500) json_out(['error' => 'Please keep your question under 500 characters'], 400);
        $c = conversation($sid);
        q('INSERT INTO messages(conversation_id, sender, text, created) VALUES(?,?,?,?)', [$c['id'], 'user', $text, now()]);
        $ctx = null;
        if (!empty($in['entry_id'])) { $e = public_entry((int)$in['entry_id']); if ($e) $ctx = (int)$e['id']; }
        // a tapped suggestion answers that exact entry
        if ($ctx && !empty($in['pick'])) {
            $e = row("SELECT * FROM entries WHERE id = ? AND status = 'published'", [$ctx]);
            $res = ['intent' => 'selected', 'level' => 'high', 'entry_id' => $ctx, 'confidence' => 1.0, 'answer' => $e['answer'], 'suggestions' => [], 'followups' => [], 'escalate' => false, 'latency_ms' => 0];
        } else {
            $res = nlp()->respond($text, $c['last_entry_id'] ? (int)$c['last_entry_id'] : null);
        }
        q('INSERT INTO messages(conversation_id, sender, text, entry_id, intent, confidence, level, latency_ms, created) VALUES(?,?,?,?,?,?,?,?,?)', [$c['id'], 'bot', $res['answer'], $res['entry_id'], $res['intent'], $res['confidence'], $res['level'], $res['latency_ms'], now()]);
        $mid = (int)db()->lastInsertId();
        if ($res['entry_id']) { q('UPDATE entries SET views = views + 1 WHERE id = ?', [$res['entry_id']]); q('UPDATE conversations SET last_entry_id = ? WHERE id = ?', [$res['entry_id'], $c['id']]); }
        q('UPDATE conversations SET last_activity = ? WHERE id = ?', [now(), $c['id']]);
        if ($res['level'] === 'fallback') {
            $n = Nlp::normalize($text);
            // Update-then-insert instead of an upsert: the SQLite on Vercel predates ON CONFLICT ... DO UPDATE.
            if (!q('UPDATE unanswered SET count = count + 1, last_asked = ? WHERE normalized = ?', [now(), $n])->rowCount())
                q('INSERT INTO unanswered(query, normalized, last_asked) VALUES(?,?,?)', [$text, $n, now()]);
        }
        json_out(['message_id' => $mid] + array_intersect_key($res, array_flip(['answer', 'level', 'intent', 'confidence', 'suggestions', 'followups', 'escalate', 'entry_id', 'latency_ms'])));
    }
    if ($a === 'feedback') {
        $m = row('SELECT m.id, m.entry_id FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.id = ? AND c.session_id = ? AND m.sender = "bot"', [(int)($in['message_id'] ?? 0), $sid]);
        if (!$m) json_out(['error' => 'Message not found'], 404);
        $r = ((int)($in['rating'] ?? 0)) > 0 ? 1 : -1;
        if (val('SELECT 1 FROM feedback WHERE message_id = ?', [$m['id']])) json_out(['error' => 'You have already rated this answer'], 409);
        q('INSERT INTO feedback(message_id, rating, created) VALUES(?,?,?)', [$m['id'], $r, now()]);
        if ($m['entry_id']) q('UPDATE entries SET ' . ($r > 0 ? 'helpful' : 'unhelpful') . ' = ' . ($r > 0 ? 'helpful' : 'unhelpful') . ' + 1 WHERE id = ?', [$m['entry_id']]);
        json_out(['ok' => true]);
    }
    if ($a === 'ticket') {
        $name = trim($in['name'] ?? ''); $email = trim($in['email'] ?? ''); $msg = trim($in['message'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($msg) < 10) json_out(['error' => 'Enter your name, a valid email address and a message of at least 10 characters.'], 422);
        $c = conversation($sid);
        $ref = 'ADM-' . date('Y') . '-' . str_pad((string)((int)val('SELECT COUNT(*) FROM tickets') + 1), 4, '0', STR_PAD_LEFT);
        q('INSERT INTO tickets(ref, conversation_id, name, email, phone, subject, message, created, updated) VALUES(?,?,?,?,?,?,?,?,?)', [$ref, $c['id'], $name, $email, trim($in['phone'] ?? ''), mb_substr(trim($in['subject'] ?? 'Admission enquiry'), 0, 120), $msg, now(), now()]);
        q('UPDATE conversations SET escalated = 1 WHERE id = ?', [$c['id']]);
        json_out(['ref' => $ref, 'message' => 'Your question has been sent to the admission office. Your reference number is ' . $ref . '. An officer will reply to ' . $email . '.']);
    }
    json_out(['error' => 'Unknown action'], 404);
} catch (Throwable $e) {
    json_out(['error' => 'The assistant is temporarily unavailable. Please try again.', 'detail' => $e->getMessage()], 500);
}
