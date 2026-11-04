<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();
$res = q("SELECT * FROM ressources WHERE actif = 1 ORDER BY ordre, id")->fetchAll();
$taux = []; foreach ($res as $r) $taux[$r['id']] = (float)$r['taux'];
$coef = (float)setting('coef', '1'); $pas = (float)setting('arrondi', '1'); $tva = (float)setting('tva', '20');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $pdo->beginTransaction();
        foreach ($_POST['l'] ?? [] as $id => $d) {
            $id = (int)$id;
            q("UPDATE bpu_lignes SET code=?, libelle=?, unite=?, forfait=?, ordre=? WHERE id=?",
              [trim($d['code'] ?? ''), trim($d['libelle'] ?? ''), trim($d['unite'] ?? 'U'), (float)str_replace(',', '.', $d['forfait'] ?? '0'), (int)($d['ordre'] ?? 0), $id]);
            q("DELETE FROM bpu_temps WHERE ligne_id = ?", [$id]);
            foreach ($d['h'] ?? [] as $rid => $h) {
                $h = (float)str_replace(',', '.', $h);
                if ($h > 0) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?)", [$id, (int)$rid, $h]);
            }
        }
        $pdo->commit();
        flash('Lignes enregistrées.');
    } elseif ($action === 'add') {
        $ordre = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM bpu_lignes")->fetch()['o'];
        q("INSERT INTO bpu_lignes (code, libelle, unite, ordre) VALUES (?,?,?,?)", [trim($_POST['code'] ?? ''), trim($_POST['libelle'] ?? 'Nouvelle ligne'), trim($_POST['unite'] ?? 'U'), $ordre]);
        flash('Ligne ajoutée : renseigne ses temps puis enregistre.');
    } elseif ($action === 'del') {
        q("UPDATE bpu_lignes SET actif = 0 WHERE id = ?", [(int)$_POST['id']]);
        flash('Ligne supprimée.');
    }
    redirect('bpu.php');
}
$lignes = q("SELECT * FROM bpu_lignes WHERE actif = 1 ORDER BY ordre, id")->fetchAll();
$temps = [];
foreach (q("SELECT * FROM bpu_temps") as $t) $temps[$t['ligne_id']][$t['ressource_id']] = (float)$t['heures'];
page_start('Lignes du bordereau de prix unitaires', 'bpu.php');
?>
<p class="lead">Pour chaque ligne, indique le temps (en heures) de chaque ressource. Le prix se recalcule instantanément : <strong>PU HT = (Σ heures × taux + forfait) × <?= e(setting('coef')) ?></strong>, arrondi à <?= e(setting('arrondi')) ?> €.</p>
<form method="post" id="f"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="scroll">
<table class="tbl bpu">
<thead><tr><th>N°</th><th>Code</th><th class="w">Désignation</th><th>Unité</th>
<?php foreach ($res as $r): ?><th class="r rot" title="<?= e($r['nom']) ?> – <?= money((float)$r['taux']) ?>/h"><span><?= e($r['nom']) ?></span></th><?php endforeach; ?>
<th class="r">Forfait €</th><th class="r">Coût</th><th class="r">PU TTC</th><th></th><th class="r pu">PU HT</th></tr></thead>
<tbody>
<?php foreach ($lignes as $l): $id = $l['id']; ?>
<tr data-id="<?= $id ?>">
  <td><input class="tiny" name="l[<?= $id ?>][ordre]" value="<?= $l['ordre'] ?>"></td>
  <td><input class="tiny" name="l[<?= $id ?>][code]" value="<?= e($l['code']) ?>"></td>
  <td><input name="l[<?= $id ?>][libelle]" value="<?= e($l['libelle']) ?>"></td>
  <td><input class="tiny" name="l[<?= $id ?>][unite]" value="<?= e($l['unite']) ?>"></td>
  <?php foreach ($res as $r): ?><td class="r"><input class="num h" data-rid="<?= $r['id'] ?>" name="l[<?= $id ?>][h][<?= $r['id'] ?>]" value="<?= isset($temps[$id][$r['id']]) ? e((string)$temps[$id][$r['id']]) : '' ?>" inputmode="decimal" placeholder="–"></td><?php endforeach; ?>
  <td class="r"><input class="num forfait" name="l[<?= $id ?>][forfait]" value="<?= (float)$l['forfait'] > 0 ? e($l['forfait']) : '' ?>" inputmode="decimal" placeholder="–"></td>
  <td class="r out cout"></td><td class="r out ttc"></td>
  <td><button class="mini danger" type="submit" form="d<?= $id ?>" onclick="return confirm('Supprimer cette ligne ?')">×</button></td>
  <td class="r out pu"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p><button class="btn" type="submit">Enregistrer toutes les lignes</button> <a class="btn secondary" href="grilles.php?nouvelle=1">Figer dans une grille →</a></p>
</form>
<?php foreach ($lignes as $l): ?><form id="d<?= $l['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $l['id'] ?>"></form><?php endforeach; ?>
<section class="card">
  <h2>Ajouter une ligne</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input class="tiny" name="code" placeholder="Code"> <input name="libelle" placeholder="Désignation" required style="min-width:280px"> <input class="tiny" name="unite" placeholder="Unité" value="U"> <button class="btn secondary" type="submit">Ajouter</button>
  </form>
</section>
<script>
const TAUX = <?= json_encode($taux) ?>, COEF = <?= json_encode($coef) ?>, PAS = <?= json_encode($pas) ?>, TVA = <?= json_encode($tva) ?>;
const num = v => parseFloat(String(v).replace(',', '.')) || 0;
const fmt = v => v.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
function calc(tr) {
  let cout = 0;
  tr.querySelectorAll('input.h').forEach(i => cout += num(i.value) * (TAUX[i.dataset.rid] || 0));
  const brut = (cout + num(tr.querySelector('.forfait').value)) * COEF;
  const pu = PAS > 0 ? Math.round(brut / PAS) * PAS : Math.round(brut * 100) / 100;
  tr.querySelector('.cout').textContent = fmt(cout);
  tr.querySelector('.pu').textContent = fmt(pu);
  tr.querySelector('.ttc').textContent = fmt(pu * (1 + TVA / 100));
}
document.querySelectorAll('tr[data-id]').forEach(tr => { calc(tr); tr.addEventListener('input', () => calc(tr)); });
</script>
<?php page_end(); ?>
