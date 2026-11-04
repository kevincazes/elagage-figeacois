<?php
// Export CSV (point-virgule, UTF-8 avec BOM) d'un bordereau : s'ouvre directement dans Excel en français.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$b = q("SELECT * FROM bordereaux WHERE id = ?", [(int)($_GET['id'] ?? 0)])->fetch();
if (!$b) exit('Bordereau introuvable.');
$fige = $b['statut'] === 'fige';
$tva = $fige ? (float)$b['tva'] : (float)setting('tva', '20');
$cat = []; foreach (q("SELECT * FROM bpu_lignes") as $c) $cat[$c['id']] = $c;
$eqId = $b['equipe_id'] !== null ? (int)$b['equipe_id'] : null;
$lignes = q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? ORDER BY ordre, id", [$b['id']])->fetchAll();
$nomFichier = 'BPU_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $b['nom'])) . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
$out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
$fr = fn($v) => number_format((float)$v, 2, ',', '');
fputcsv($out, ['Bordereau des prix unitaires', $b['nom']], ';');
fputcsv($out, ['Client', $b['client']], ';');
fputcsv($out, ['Date', date('d/m/Y'), $fige ? 'Prix figés le ' . date('d/m/Y', strtotime($b['fige_le'])) : 'Brouillon', 'TVA %', $fr($tva)], ';');
fputcsv($out, [], ';');
fputcsv($out, ['N°', 'Code', 'Désignation', 'Unité', 'PU HT (€)', 'PU TTC (€)', 'Quantité', 'Total HT (€)'], ';');
$total = 0;
foreach ($lignes as $l) {
    $num = $l['numero'] !== '' ? $l['numero'] : (string)$l['ordre'];
    if ($l['type'] === 'section') { fputcsv($out, [$num, '', mb_strtoupper($l['libelle'])], ';'); continue; }
    $pu = $fige ? (float)$l['pu_fige'] : ($l['pu_manuel'] !== null ? (float)$l['pu_manuel'] : (isset($cat[$l['ligne_id']]) ? prix_catalogue($cat[$l['ligne_id']], $eqId) : 0));
    $q = $l['quantite']; $tot = $q !== null ? $pu * (float)$q : null; if ($tot !== null) $total += $tot;
    fputcsv($out, [$num, $l['code'], $l['libelle'], $l['unite'], $fr($pu), $fr($pu * (1 + $tva / 100)), $q !== null ? $fr($q) : '', $tot !== null ? $fr($tot) : ''], ';');
}
if ($total > 0) { fputcsv($out, [], ';'); fputcsv($out, ['', '', 'TOTAL ESTIMATIF HT', '', '', '', '', $fr($total)], ';'); fputcsv($out, ['', '', 'TOTAL TTC', '', '', '', '', $fr($total * (1 + $tva / 100))], ';'); }
fclose($out);
