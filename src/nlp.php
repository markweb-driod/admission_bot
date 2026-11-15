<?php
/**
 * Natural language processing engine of the Admission Chatbot.
 * Pipeline: normalisation (admission vocabulary), spelling correction against the knowledge-base vocabulary,
 * tokenisation, stop-word removal and light stemming, unigram + bigram TF-IDF vectors,
 * max-pooled cosine retrieval over the questions and variants of every entry, keyword boost,
 * confidence thresholds (answer / suggest / fallback) and short-term conversation context.
 */
require_once __DIR__ . '/core.php';

const PHRASES = [
    "/\bpost[\s\-]?utme\b|\bpost[\s\-]?jamb\b|\bscreening\b/" => ' postutme ', "/\bdirect[\s\-]?entry\b|\bd\.?e\.? (admission|form|candidate)\b/" => ' directentry ',
    "/\bo['’\s\-]?level\b|\bssce\b|\bwaec\b|\bneco\b/" => ' olevel ', "/\bcut[\s\-]?off(s)?\b|\bcut[\s\-]off mark\b/" => ' cutoff ',
    "/\bu\.?t\.?m\.?e\b|\bjamb\b/" => ' utme ', "/\bcaps\b/" => ' caps ', "/\bmatric(ulation)?\b/" => ' matric ', "/\bhostel(s)?\b/" => ' hostel ',
    "/\bdocs?\b|\bpapers\b|\bcertificates?\b/" => ' documents ', "/\bfreshers?\b|\bfresh students?\b/" => ' new student ',
    "/\bnd\b|\bnational diploma\b/" => ' nd ', "/\bnce\b/" => ' nce ', "/\bremita\b|\brrr\b/" => ' remita ', "/\bwhat'?s\b/" => ' what is ', "/\bcan't\b|\bcannot\b/" => ' can not ',
];
const STOP = ['a', 'an', 'the', 'is', 'are', 'am', 'was', 'be', 'to', 'of', 'in', 'on', 'for', 'at', 'by', 'it', 'its', 'and', 'or', 'i', 'me', 'my', 'we', 'you', 'your', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'shall', 'please', 'pls', 'with', 'about', 'this', 'that', 'there', 'any', 'some', 'if', 'so', 'am', 'want', 'wants', 'need', 'like', 'know', 'tell', 'get', 'got', 'have', 'has', 'much', 'many', 'abeg', 'sir', 'madam', 'hello', 'hi', 'hey', 'then', 'than', 'also', 'just', 'what', 'how', 'when', 'where', 'which', 'who', 'whom', 'whose', 'why', 'must', 'may', 'might', 'been', 'being', 'into', 'out', 'up', 'from', 'after', 'before', 'new'];
const KEEP = ['when', 'where', 'how', 'what', 'much', 'many'];

class Nlp
{
    public array $entries = [];      // id => ['question','variants'[],'keywords'[], 'category','answer', ...]
    public array $docs = [];         // list of [entryId, tokenVector(array term=>weight), norm]
    public array $idf = [];
    public array $vocab = [];

    public static function normalize(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = str_replace(["’", "‘", '`'], "'", $t);
        foreach (PHRASES as $re => $rep) $t = preg_replace($re, $rep, $t);
        $t = preg_replace("/[^a-z0-9\s]+/", ' ', $t);
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    public static function stem(string $w): string
    {
        if (strlen($w) <= 4) return $w;
        foreach (['ations', 'ation', 'ments', 'ment', 'ings', 'ing', 'edly', 'ed', 'ies', 'es', 'ly', 's'] as $suf) {
            if (str_ends_with($w, $suf) && strlen($w) - strlen($suf) >= 4) { $w = substr($w, 0, -strlen($suf)); if ($suf === 'ies') $w .= 'y'; break; }
        }
        return $w;
    }

    public function tokens(string $text, bool $correct = true): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($text)) as $w) {
            if ($w === '' || in_array($w, STOP, true) && !in_array($w, ['how', 'when', 'where'], true) && true) { if (!in_array($w, ['how', 'when', 'where'], true)) continue; }
            if ($correct) $w = $this->correct($w);
            $out[] = self::stem($w);
        }
        return $out;
    }

    /** Correct a misspelt word to the nearest word of the knowledge-base vocabulary (edit distance 1, or 2 for long words). */
    public function correct(string $w): string
    {
        if (strlen($w) < 5 || isset($this->vocab[$w]) || ctype_digit($w)) return $w;
        $best = $w; $bd = strlen($w) >= 8 ? 3 : 2;
        foreach ($this->vocab as $v => $_) {
            if (abs(strlen($v) - strlen($w)) > 2 || $v[0] !== $w[0] && strlen($w) < 7) continue;
            $d = levenshtein($w, (string)$v);
            if ($d < $bd) { $bd = $d; $best = (string)$v; }
        }
        return $best;
    }

    private function vector(array $tokens): array
    {
        $f = [];
        $n = count($tokens);
        foreach ($tokens as $i => $t) { $f[$t] = ($f[$t] ?? 0) + 1; if ($i + 1 < $n) { $b = $t . '_' . $tokens[$i + 1]; $f[$b] = ($f[$b] ?? 0) + 1; } }
        return $f;
    }

    public function load(): self
    {
        $rowsE = rows("SELECT e.*, c.name AS category FROM entries e JOIN categories c ON c.id = e.category_id WHERE e.status = 'published'");
        $this->entries = []; $this->vocab = [];
        foreach ($rowsE as $r) {
            foreach (preg_split('/[^a-z0-9]+/', self::normalize($r['question'] . ' ' . $r['variants'] . ' ' . $r['keywords'])) as $w) if (strlen($w) > 3) $this->vocab[$w] = true;
        }
        $raw = [];
        foreach ($rowsE as $r) {
            $variants = array_filter(array_map('trim', explode("\n", $r['variants'])));
            $kw = array_unique(array_map([self::class, 'stem'], array_filter(explode(' ', self::normalize($r['keywords'])))));
            $this->entries[$r['id']] = $r + ['variant_list' => $variants, 'kw' => $kw];
            foreach (array_merge([$r['question']], $variants) as $q) {
                $tk = $this->tokens($q, false); if (!$tk) continue;
                $raw[] = [$r['id'], $this->vector($tk)];
            }
        }
        $df = [];
        foreach ($raw as [, $v]) foreach (array_keys($v) as $t) $df[$t] = ($df[$t] ?? 0) + 1;
        $N = max(1, count($raw)); $this->idf = [];
        foreach ($df as $t => $c) $this->idf[$t] = log(1 + $N / $c);
        $this->docs = [];
        foreach ($raw as [$id, $v]) {
            $w = []; $norm = 0.0;
            foreach ($v as $t => $c) { $x = (1 + log($c)) * $this->idf[$t]; $w[$t] = $x; $norm += $x * $x; }
            $this->docs[] = [$id, $w, sqrt($norm) ?: 1.0];
        }
        return $this;
    }

    /** Rank entries for a query. Returns list of ['id','score','cosine','kw'] sorted by score. */
    public function rank(string $query, ?int $contextEntry = null): array
    {
        $tk = $this->tokens($query);
        if (!$tk) return [];
        $qv = $this->vector($tk); $qw = []; $qn = 0.0;
        foreach ($qv as $t => $c) { $x = (1 + log($c)) * ($this->idf[$t] ?? 0.0); if ($x > 0) { $qw[$t] = $x; $qn += $x * $x; } }
        $qn = sqrt($qn) ?: 1.0;
        $best = [];
        foreach ($this->docs as [$id, $w, $norm]) {
            $dot = 0.0;
            foreach ($qw as $t => $x) if (isset($w[$t])) $dot += $x * $w[$t];
            $cos = $dot / ($qn * $norm);
            if ($cos > ($best[$id] ?? 0)) $best[$id] = $cos;
        }
        $out = [];
        $unigrams = array_filter($tk, fn($t) => true);
        foreach ($best as $id => $cos) {
            $kw = $this->entries[$id]['kw'];
            $hit = count(array_intersect(array_unique($tk), $kw));
            $boost = min(0.18, 0.06 * $hit);
            $score = min(1.0, $cos + ($cos > 0.05 ? $boost : 0.0));
            if ($contextEntry && $id == $contextEntry && count($tk) <= 3) $score += 0.05;
            $out[] = ['id' => $id, 'score' => round($score, 4), 'cosine' => round($cos, 4), 'kw' => $hit];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, 5);
    }

    public static function smalltalk(string $text): ?string
    {
        $t = self::normalize($text);
        $w = explode(' ', $t);
        if (count($w) <= 4 && array_intersect($w, ['hello', 'hi', 'hey', 'good', 'morning', 'afternoon', 'evening', 'greetings']) && !array_intersect($w, ['admission', 'apply', 'fee'])) return 'greeting';
        if (preg_match('/\b(thank|thanks|thx|appreciate)\b/', $t) && count($w) <= 6) return 'thanks';
        if (preg_match('/^(bye|goodbye|see you|exit|quit)\b/', $t) || preg_match('/\b(that is all|that s all|no more questions)\b/', $t)) return 'bye';
        if (preg_match('/\b(human|real person|person|officer|staff|agent|someone|somebody|admission officer)\b/', $t) && preg_match('/\b(talk|speak|chat|connect|contact|call|reach)\b/', $t)) return 'human';
        return null;
    }

    /** Full answer decision for one user message. */
    public function respond(string $query, ?int $contextEntry = null): array
    {
        $t0 = microtime(true);
        $st = self::smalltalk($query);
        $thr = ['high' => (float)setting('high'), 'medium' => (float)setting('medium'), 'low' => (float)setting('low')];
        $res = ['intent' => 'unknown', 'level' => 'fallback', 'entry_id' => null, 'confidence' => 0.0, 'answer' => '', 'suggestions' => [], 'followups' => [], 'escalate' => false];
        if ($st === 'capabilities') { $e = row("SELECT id, answer FROM entries WHERE code = 'ADM-002'"); $res = ['intent' => 'capabilities', 'level' => 'high', 'entry_id' => $e ? (int)$e['id'] : null, 'confidence' => 1.0, 'answer' => $e['answer'] ?? 'I answer questions about the admission process.', 'suggestions' => [], 'followups' => [], 'escalate' => false]; }
        elseif ($st === 'greeting') $res = ['intent' => 'greeting', 'level' => 'smalltalk', 'entry_id' => null, 'confidence' => 1.0, 'answer' => setting('welcome'), 'suggestions' => [], 'followups' => [], 'escalate' => false];
        elseif ($st === 'thanks') $res = ['intent' => 'thanks', 'level' => 'smalltalk', 'entry_id' => null, 'confidence' => 1.0, 'answer' => 'You are welcome! Ask me anything else about the admission process.', 'suggestions' => [], 'followups' => [], 'escalate' => false];
        elseif ($st === 'bye') $res = ['intent' => 'goodbye', 'level' => 'smalltalk', 'entry_id' => null, 'confidence' => 1.0, 'answer' => 'Goodbye, and good luck with your admission! You can come back at any time.', 'suggestions' => [], 'followups' => [], 'escalate' => false];
        elseif ($st === 'human') $res = ['intent' => 'human_request', 'level' => 'smalltalk', 'entry_id' => null, 'confidence' => 1.0, 'answer' => 'I can pass your question to an admission officer. Use the "Talk to an officer" button and leave your name and email address. Office hours: ' . setting('office_hours') . '.', 'suggestions' => [], 'followups' => [], 'escalate' => true];
        else {
            $rank = $this->rank($query, $contextEntry);
            if (!$rank && $contextEntry) $rank = [];
            $top = $rank[0] ?? null;
            if ($top && $top['score'] >= $thr['medium']) {
                $e = $this->entries[$top['id']];
                $second = $rank[1] ?? null;
                $ambiguous = $second && ($top['score'] - $second['score']) < 0.04 && $top['score'] < $thr['high'];
                $res['entry_id'] = (int)$top['id']; $res['confidence'] = $top['score']; $res['intent'] = $e['category'];
                if ($ambiguous && $top['score'] < $thr['high']) {
                    $res['level'] = 'suggest'; $res['answer'] = 'I found more than one possible match for your question. Which of these do you mean?';
                    $res['suggestions'] = array_map(fn($r) => ['id' => $r['id'], 'question' => $this->entries[$r['id']]['question']], array_slice($rank, 0, 3)); $res['entry_id'] = null;
                } else {
                    $res['level'] = $top['score'] >= $thr['high'] ? 'high' : 'medium';
                    $res['answer'] = ($res['level'] === 'medium' ? 'I think you are asking: "' . $e['question'] . '"' . "\n\n" : '') . $e['answer'];
                    foreach (array_slice($rank, 1, 3) as $r) if ($r['score'] >= $thr['low']) $res['followups'][] = ['id' => $r['id'], 'question' => $this->entries[$r['id']]['question']];
                }
            } elseif ($top && $top['score'] >= $thr['low']) {
                $res['level'] = 'suggest'; $res['confidence'] = $top['score']; $res['intent'] = $this->entries[$top['id']]['category'];
                $res['answer'] = 'I am not completely sure what you mean. Did you mean one of these questions?';
                $res['suggestions'] = array_map(fn($r) => ['id' => $r['id'], 'question' => $this->entries[$r['id']]['question']], array_slice(array_filter($rank, fn($r) => $r['score'] >= $thr['low'] * 0.8), 0, 3));
                if (!$res['suggestions']) { $res['level'] = 'fallback'; }
            }
            if ($res['level'] === 'fallback') {
                $res['answer'] = "I could not find a reliable answer to that in the admission information I have. You can rephrase your question, or use the \"Talk to an officer\" button so that an admission officer can reply to you.";
                $res['escalate'] = true; $res['confidence'] = $top['score'] ?? 0.0;
                if ($top) $res['suggestions'] = array_map(fn($r) => ['id' => $r['id'], 'question' => $this->entries[$r['id']]['question']], array_slice(array_filter($rank, fn($r) => $r['score'] >= 0.1), 0, 2));
            }
        }
        $res['latency_ms'] = round((microtime(true) - $t0) * 1000, 2);
        return $res;
    }
}

function nlp(): Nlp { static $n; return $n ??= (new Nlp())->load(); }
