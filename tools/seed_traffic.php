<?php
/** Generates simulated demonstration traffic (conversations over 14 days) so that the dashboard has data. php tools/seed_traffic.php */
require __DIR__ . '/../src/nlp.php';
db();
if ((int)val('SELECT COUNT(*) FROM conversations') > 0) { echo "Traffic already exists.\n"; exit; }
mt_srand(2026);
$sets = require __DIR__ . '/../src/testsets.php';
$pool = array_merge(array_column($sets['dev'], 0), ['hello', 'thanks', 'fees', 'how do i get my admission letter', 'what about direct entry', 'can i pay in dollars', 'where is the admission office']);
$n = nlp();
for ($s = 0; $s < 46; $s++) {
    $day = mt_rand(0, 13); $t = strtotime("-$day days " . mt_rand(8, 21) . ':' . str_pad((string)mt_rand(0, 59), 2, '0', STR_PAD_LEFT));
    q('INSERT INTO conversations(session_id, started, last_activity) VALUES(?,?,?)', ['demo-session-' . str_pad((string)$s, 3, '0', STR_PAD_LEFT), date('Y-m-d H:i:s', $t), date('Y-m-d H:i:s', $t)]);
    $cid = (int)db()->lastInsertId(); $ctx = null;
    for ($k = 0, $m = mt_rand(1, 4); $k < $m; $k++) {
        $text = $pool[mt_rand(0, count($pool) - 1)]; $t += mt_rand(20, 90);
        q('INSERT INTO messages(conversation_id, sender, text, created) VALUES(?,?,?,?)', [$cid, 'user', $text, date('Y-m-d H:i:s', $t)]);
        $r = $n->respond($text, $ctx); $t += 1;
        q('INSERT INTO messages(conversation_id, sender, text, entry_id, intent, confidence, level, latency_ms, created) VALUES(?,?,?,?,?,?,?,?,?)', [$cid, 'bot', $r['answer'], $r['entry_id'], $r['intent'], $r['confidence'], $r['level'], $r['latency_ms'], date('Y-m-d H:i:s', $t)]);
        $mid = (int)db()->lastInsertId();
        if ($r['entry_id']) { $ctx = $r['entry_id']; q('UPDATE entries SET views = views + 1 WHERE id = ?', [$r['entry_id']]); }
        if ($r['level'] === 'fallback') q('INSERT INTO unanswered(query, normalized, last_asked) VALUES(?,?,?) ON CONFLICT(normalized) DO UPDATE SET count = count + 1, last_asked = excluded.last_asked', [$text, Nlp::normalize($text), date('Y-m-d H:i:s', $t)]);
        if (in_array($r['level'], ['high', 'medium']) && mt_rand(1, 100) <= 35) {
            $good = mt_rand(1, 100) <= 86; q('INSERT INTO feedback(message_id, rating, created) VALUES(?,?,?)', [$mid, $good ? 1 : -1, date('Y-m-d H:i:s', $t + 5)]);
            if ($r['entry_id']) q('UPDATE entries SET ' . ($good ? 'helpful' : 'unhelpful') . ' = ' . ($good ? 'helpful' : 'unhelpful') . ' + 1 WHERE id = ?', [$r['entry_id']]);
        }
    }
}
$tk = [['Chinedu Obi', 'chinedu.obi@example.com', 'Admission status not showing', 'My name is not on the admission list although I met the cut-off mark. Please advise.'], ['Amina Yusuf', 'amina.yusuf@example.com', 'Change of course', 'I want to change my course from Biology to Microbiology. What should I submit?'], ['Samuel Peter', 'samuel.peter@example.com', 'Payment not confirmed', 'I paid the acceptance fee yesterday but the portal still shows unpaid.']];
foreach ($tk as $i => $x) { q('INSERT INTO tickets(ref, conversation_id, name, email, subject, message, status, created, updated) VALUES(?,?,?,?,?,?,?,?,?)', ['ADM-' . date('Y') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT), $i + 1, $x[0], $x[1], $x[2], $x[3], $i == 2 ? 'answered' : 'open', date('Y-m-d H:i:s', strtotime('-' . ($i + 1) . ' days')), now()]); }
echo "Demo traffic created: " . (int)val('SELECT COUNT(*) FROM messages') . " messages.\n";
