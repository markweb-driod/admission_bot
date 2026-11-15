<?php
/** Evaluates the chatbot on the dev and held-out sets, with a spelling-correction ablation. php tools/evaluate.php */
require __DIR__ . '/../src/eval.php';
$sets = require __DIR__ . '/../src/testsets.php';
$out = [];
foreach (['dev', 'heldout'] as $k) {
    $r = evaluate($sets[$k]);
    $out[$k] = $r;
}
$n = nlp(); $saved = $n->vocab; $n->vocab = [];
foreach (['dev', 'heldout'] as $k) { $r = evaluate($sets[$k], false); unset($r['errors']); $out[$k . '_nospell'] = $r; }
$n->vocab = $saved;
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
