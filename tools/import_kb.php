<?php
/**
 * Brings an existing database's knowledge base in line with src/kb_seed.php without touching other data.
 * New categories and entries are added; entries whose code already exists get the seed's question, variants,
 * answer, keywords and category. Views, ratings and entries not in the seed are kept. php tools/import_kb.php
 */
require __DIR__ . '/../src/core.php';
$d = db();
$d->beginTransaction();
$added = $updated = 0;
foreach (require __DIR__ . '/../src/kb_seed.php' as $i => [$cname, $items]) {
    $cid = val('SELECT id FROM categories WHERE name = ?', [$cname]);
    if (!$cid) { q('INSERT INTO categories(name, position) VALUES(?, ?)', [$cname, $i]); $cid = (int)$d->lastInsertId(); }
    foreach ($items as [$code, $question, $variants, $answer, $keywords]) {
        $variants = implode("\n", $variants);
        $old = row('SELECT id, category_id, question, variants, answer, keywords FROM entries WHERE code = ?', [$code]);
        if (!$old) {
            q('INSERT INTO entries(code, category_id, question, variants, answer, keywords, created, updated) VALUES(?,?,?,?,?,?,?,?)', [$code, $cid, $question, $variants, $answer, $keywords, now(), now()]);
            $added++;
        } elseif ([(int)$old['category_id'], $old['question'], $old['variants'], $old['answer'], $old['keywords']] !== [(int)$cid, $question, $variants, $answer, $keywords]) {
            q('UPDATE entries SET category_id = ?, question = ?, variants = ?, answer = ?, keywords = ?, updated = ? WHERE id = ?', [$cid, $question, $variants, $answer, $keywords, now(), $old['id']]);
            $updated++;
        }
    }
}
$d->commit();
echo "Knowledge base imported: $added added, $updated updated, " . (int)val("SELECT COUNT(*) FROM entries WHERE status = 'published'") . " published entries.\n";
