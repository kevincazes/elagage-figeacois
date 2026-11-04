<?php
// Export CSV (séparateur point-virgule, UTF-8 avec BOM) : s'ouvre directement dans Excel en français.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$g = q("SELECT * FROM grilles WHERE id = ?", [(int)($_GET['id'] ?? 0)])->fetch();
if (!$g) exit('Grille introuvable.');
$lignes = q("SELECT * FROM grille_lignes WHERE grille_id = ? ORDER BY ordre, id", [$g['id']])->fetchAll();
$tva = (float)$g['tva'];
$nomFichier = 'BPU_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $g['nom'])) . '_' . date('Y-m-d', strtotime($g['cree_le'])) . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$fr = fn($v) => number_format((float)$v, 2, ',', '');
fputcsv($out, ['Bordereau des prix unitaires', $g['nom']], ';');
fputcsv($out, ['Client', $g['client']], ';');
fputcsv($out, ['Date', date('d/m/Y', strtotime($g['cree_le'])), 'TVA %', $fr($tva)], ';');
fputcsv($out, [], ';');
fputcsv($out, ['N°', 'Code', 'Désignation', 'Unité', 'PU HT (€)', 'PU TTC (€)'], ';');
foreach ($lignes as $i => $l) fputcsv($out, [$i + 1, $l['code'], $l['libelle'], $l['unite'], $fr($l['pu_ht']), $fr((float)$l['pu_ht'] * (1 + $tva / 100))], ';');
fclose($out);
