<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$g = q("SELECT * FROM grilles WHERE id = ?", [(int)($_GET['id'] ?? 0)])->fetch();
if (!$g) { flash('Grille introuvable.', 'err'); redirect('grilles.php'); }
$lignes = q("SELECT * FROM grille_lignes WHERE grille_id = ? ORDER BY ordre, id", [$g['id']])->fetchAll();
$taux = json_decode($g['taux_json'] ?? '[]', true) ?: [];
$tva = (float)$g['tva'];
page_start($g['nom'], 'grilles.php');
?>
<p class="lead"><?= $g['client'] ? e($g['client']) . ' · ' : '' ?>figée le <?= date('d/m/Y à H:i', strtotime($g['cree_le'])) ?> · coefficient <?= e($g['coef']) ?> · TVA <?= e($g['tva']) ?> %</p>
<?php if ($g['notes']): ?><p class="note"><?= nl2br(e($g['notes'])) ?></p><?php endif; ?>
<p><a class="btn" href="export.php?id=<?= $g['id'] ?>">Télécharger pour Excel (CSV)</a> <a class="btn secondary" href="grille.php?id=<?= $g['id'] ?>&print=1" target="_blank">Version imprimable / PDF</a> <a class="btn secondary" href="grilles.php">← Toutes les grilles</a></p>
<div class="scroll">
<table class="tbl">
<thead><tr><th>N°</th><th>Code</th><th class="w">Désignation</th><th>Unité</th><th class="r">PU HT</th><th class="r">PU TTC</th><th>Détail du calcul</th></tr></thead>
<tbody>
<?php foreach ($lignes as $i => $l): $d = json_decode($l['detail_json'] ?? '[]', true) ?: []; ?>
<tr>
  <td><?= $i + 1 ?></td><td><?= e($l['code']) ?></td><td><?= e($l['libelle']) ?></td><td><?= e($l['unite']) ?></td>
  <td class="r"><strong><?= money((float)$l['pu_ht']) ?></strong></td>
  <td class="r"><?= money((float)$l['pu_ht'] * (1 + $tva / 100)) ?></td>
  <td class="small"><?php foreach ($d as $x) echo e($x['ressource']) . ($x['heures'] !== null ? ' ' . e((string)$x['heures']) . ' h × ' . money((float)$x['taux']) : ' ' . money((float)$x['taux'])) . ' · '; ?>coût <?= money((float)$l['cout']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<details class="card"><summary>Taux horaires utilisés pour cette grille</summary>
<ul><?php foreach ($taux as $t): ?><li><?= e($t['nom']) ?> : <?= money((float)$t['taux']) ?>/h</li><?php endforeach; ?></ul></details>
<?php if (isset($_GET['print'])): ?><script>window.print()</script><?php endif; ?>
<?php page_end(); ?>
