<?php
/** Evaluation of the chatbot on a labelled query set (also used by the administrator benchmark page). */
require_once __DIR__ . '/nlp.php';

/** Baseline: a query is matched to the entry whose question or variants share the most whole words (no normalisation, no TF-IDF). */
function baseline_match(string $query, array $entries): ?int
{
    $q = array_unique(array_filter(preg_split('/\W+/', strtolower($query)), fn($w) => strlen($w) > 2));
    $best = null; $bs = 0;
    foreach ($entries as $id => $e) {
        $t = array_unique(array_filter(preg_split('/\W+/', strtolower($e['question'] . ' ' . $e['variants'])), fn($w) => strlen($w) > 2));
        $s = count(array_intersect($q, $t));
        if ($s > $bs) { $bs = $s; $best = $id; }
    }
    return $bs >= 2 ? $best : null;
}

function evaluate(array $set, bool $withBaseline = true): array
{
    $n = nlp(); $codes = [];
    foreach ($n->entries as $id => $e) $codes[$e['code']] = $id;
    $inN = $outN = $top1 = $top3 = $answeredIn = $outOk = $b1 = $latency = 0; $errors = [];
    $answeredOut = 0; $conf = [];
    foreach ($set as [$query, $expected]) {
        $t0 = microtime(true);
        $r = $n->respond($query);
        $latency += (microtime(true) - $t0) * 1000;
        $rank = $n->rank($query);
        $got = ($r['level'] === 'high' || $r['level'] === 'medium') ? $r['entry_id'] : null;
        if ($expected === null) {
            $outN++;
            if ($got === null) $outOk++; else { $answeredOut++; $errors[] = [$query, 'answered an out-of-scope question with ' . $n->entries[$got]['code']]; }
        } else {
            $inN++; $eid = $codes[$expected] ?? 0;
            if ($got === $eid) { $top1++; $top3++; $answeredIn++; }
            else {
                $ids = array_map(fn($x) => $x['id'], array_slice($rank, 0, 3));
                if ($r['level'] === 'suggest') $ids = array_map(fn($x) => $x['id'], $r['suggestions']);
                if (in_array($eid, $ids)) $top3++;
                if ($got !== null) $answeredIn++;
                $errors[] = [$query, 'expected ' . $expected . ', got ' . ($got ? $n->entries[$got]['code'] : $r['level'])];
            }
            if ($withBaseline && baseline_match($query, $n->entries) === $eid) $b1++;
        }
    }
    $correctAnswers = $top1; $answered = $top1 + count(array_filter($errors, fn($e) => str_starts_with($e[1], 'expected') && !str_ends_with($e[1], 'fallback') && !str_ends_with($e[1], 'suggest'))) + $answeredOut;
    return [
        'total' => count($set), 'in_scope' => $inN, 'out_scope' => $outN,
        'top1' => $inN ? round(100 * $top1 / $inN, 1) : 0, 'top3' => $inN ? round(100 * $top3 / $inN, 1) : 0,
        'rejection' => $outN ? round(100 * $outOk / $outN, 1) : 0,
        'answer_precision' => $answered ? round(100 * $correctAnswers / $answered, 1) : 0,
        'baseline_top1' => $inN ? round(100 * $b1 / $inN, 1) : 0,
        'overall' => count($set) ? round(100 * ($top1 + $outOk) / count($set), 1) : 0,
        'avg_ms' => count($set) ? round($latency / count($set), 2) : 0, 'errors' => $errors,
    ];
}
