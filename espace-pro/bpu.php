<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();
$res = ressources(); $taux = taux_courants();
$coef = (float)setting('coef', '1'); $pas = (float)setting('arrondi', '1'); $tva = (float)setting('tva', '20');
$HAUT = ['' => '—', '<10' => '< 10 m', '10-20' => '10–20 m', '>20' => '> 20 m'];
$HOUP = ['' => '—', '<=5' => '≤ 5 m', '5-10' => '5–10 m', '>10' => '> 10 m'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $pdo->beginTransaction();
        foreach ($_POST['l'] ?? [] as $id => $d) {
            $id = (int)$id;
            q("UPDATE bpu_lignes SET code=?, libelle=?, unite=?, forfait=?, ordre=?, famille=?, hauteur=?, houppier=?, circonference=?, prix_ref=? WHERE id=?",
              [trim($d['code'] ?? ''), trim($d['libelle'] ?? ''), trim($d['unite'] ?? 'U'), (float)str_replace(',', '.', $d['forfait'] ?? '0'), (int)($d['ordre'] ?? 0),
               trim($d['famille'] ?? ''), $d['hauteur'] ?? '', $d['houppier'] ?? '', trim($d['circ'] ?? ''), ($d['prix_ref'] ?? '') === '' ? null : (float)str_replace(',', '.', $d['prix_ref']), $id]);
            q("DELETE FROM bpu_temps WHERE ligne_id = ?", [$id]);
            foreach ($d['h'] ?? [] as $rid => $h) { $h = (float)str_replace(',', '.', $h); if ($h > 0) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?)", [$id, (int)$rid, $h]); }
        }
        $pdo->commit(); journal('catalogue', count($_POST['l'] ?? []) . ' ligne(s) du catalogue enregistrée(s)'); flash('Lignes enregistrées.');
    } elseif ($action === 'add') {
        $ordre = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM bpu_lignes")->fetch()['o'];
        q("INSERT INTO bpu_lignes (code, libelle, unite, ordre, famille) VALUES (?,?,?,?,?)", [trim($_POST['code'] ?? ''), trim($_POST['libelle'] ?? 'Nouvelle ligne'), trim($_POST['unite'] ?? 'U'), $ordre, trim($_POST['famille'] ?? '')]);
        flash('Ligne ajoutée : renseigne ses temps puis enregistre.');
    } elseif ($action === 'dup') {
        $src = q("SELECT * FROM bpu_lignes WHERE id = ?", [(int)$_POST['id']])->fetch();
        if ($src) {
            q("INSERT INTO bpu_lignes (code, libelle, unite, forfait, ordre, famille, hauteur, houppier, circonference, source) VALUES (?,?,?,?,?,?,?,?,?,?)",
              [$src['code'] . '-COPIE', $src['libelle'] . ' (copie)', $src['unite'], $src['forfait'], (int)$src['ordre'] + 1, $src['famille'], $src['hauteur'], $src['houppier'], $src['circonference'], 'Copie']);
            $nid = (int)$pdo->lastInsertId();
            foreach (q("SELECT * FROM bpu_temps WHERE ligne_id = ?", [$src['id']]) as $t) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?)", [$nid, $t['ressource_id'], $t['heures']]);
            flash('Ligne dupliquée : adapte-la puis enregistre.');
        }
    } elseif ($action === 'del') {
        q("UPDATE bpu_lignes SET actif = 0 WHERE id = ?", [(int)$_POST['id']]); flash('Ligne supprimée.');
    } elseif ($action === 'import') {
        require_admin();
        [$n, $np] = importer_catalogue(); journal('catalogue', "Import du catalogue : $n lignes, $np packs"); flash("Catalogue importé : $n lignes et $np packs ajoutés.");
    }
    $qs = http_build_query(array_filter(['famille' => $_POST['f_famille'] ?? '', 'hauteur' => $_POST['f_hauteur'] ?? '', 'q' => $_POST['f_q'] ?? '']));
    redirect('bpu.php' . ($qs ? "?$qs" : ''));
}

// Filtres
$fFam = $_GET['famille'] ?? ''; $fHaut = $_GET['hauteur'] ?? ''; $fQ = trim($_GET['q'] ?? '');
$where = ['actif = 1']; $p = [];
if ($fFam !== '') { $where[] = 'famille = ?'; $p[] = $fFam; }
if ($fHaut !== '') { $where[] = 'hauteur = ?'; $p[] = $fHaut; }
if ($fQ !== '') { $where[] = '(libelle LIKE ? OR code LIKE ?)'; $p[] = "%$fQ%"; $p[] = "%$fQ%"; }
$lignes = q("SELECT * FROM bpu_lignes WHERE " . implode(' AND ', $where) . " ORDER BY famille, ordre, id", $p)->fetchAll();
$familles = array_column(q("SELECT DISTINCT famille FROM bpu_lignes WHERE actif = 1 ORDER BY famille")->fetchAll(), 'famille');
$temps = temps_par_ligne();
$total = (int)q("SELECT COUNT(*) c FROM bpu_lignes WHERE actif = 1")->fetch()['c'];
page_start('Catalogue des prestations', 'bpu.php');
?>
<p class="lead">Chaque ligne a ses temps par ressource ; le prix se recalcule en direct : <strong>PU HT = (Σ heures × taux + forfait) × <?= e(setting('coef')) ?></strong>, arrondi à <?= e(setting('arrondi')) ?> €. La colonne « Réf. » rappelle le prix remis sur un bordereau précédent, pour comparer.</p>

<?php if ($total === 0 || isset($_GET['import'])): ?>
<section class="card"><h2>Catalogue de démarrage</h2>
  <p>Charge les lignes issues de tes trois bordereaux (EB, Lot Habitat, Département), avec des temps calibrés sur les prix remis, et les packs correspondants. Les lignes déjà présentes (même code) ne sont pas dupliquées.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="import"><button class="btn" type="submit">Importer le catalogue</button></form>
</section>
<?php endif; ?>

<form method="get" class="inline card" style="display:flex">
  <select name="famille"><option value="">Toutes les familles</option><?php foreach ($familles as $f): ?><option <?= $f === $fFam ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?></select>
  <select name="hauteur"><?php foreach ($HAUT as $k => $v): ?><option value="<?= $k ?>" <?= $k === $fHaut ? 'selected' : '' ?>><?= $k === '' ? 'Toutes hauteurs' : $v ?></option><?php endforeach; ?></select>
  <input name="q" value="<?= e($fQ) ?>" placeholder="Rechercher (libellé, code)">
  <button class="btn secondary" type="submit">Filtrer</button>
  <a class="mini" href="bpu.php">Tout afficher</a>
  <span class="muted"><?= count($lignes) ?> / <?= $total ?> lignes</span>
  <?php if (user()['role'] === 'admin'): ?><a class="mini" href="bpu.php?import=1">Importer le catalogue</a><?php endif; ?>
</form>

<form method="post" id="f"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>">
<div class="scroll">
<table class="tbl bpu">
<thead><tr><th>Ord.</th><th>Code</th><th class="w">Désignation</th><th>Famille</th><th>Haut.</th><th>Houp.</th><th>Circ.</th><th>Unité</th>
<?php foreach ($res as $r): ?><th class="r rot" title="<?= e($r['nom']) ?> – <?= money((float)$r['taux']) ?>/h"><span><?= e($r['nom']) ?></span></th><?php endforeach; ?>
<th class="r">Forfait €</th><th class="r">Réf. €</th><th class="r">Écart</th><th></th><th class="r pu">PU HT</th></tr></thead>
<tbody>
<?php foreach ($lignes as $l): $id = $l['id']; ?>
<tr data-id="<?= $id ?>" data-ref="<?= $l['prix_ref'] !== null ? (float)$l['prix_ref'] : '' ?>" title="<?= e($l['source']) ?>">
  <td><input class="tiny" name="l[<?= $id ?>][ordre]" value="<?= $l['ordre'] ?>"></td>
  <td><input class="tiny" style="width:110px" name="l[<?= $id ?>][code]" value="<?= e($l['code']) ?>"></td>
  <td><input name="l[<?= $id ?>][libelle]" value="<?= e($l['libelle']) ?>"></td>
  <td><input class="tiny" style="width:150px" name="l[<?= $id ?>][famille]" value="<?= e($l['famille']) ?>" list="familles"></td>
  <td><select name="l[<?= $id ?>][hauteur]"><?php foreach ($HAUT as $k => $v): ?><option value="<?= $k ?>" <?= $k === $l['hauteur'] ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
  <td><select name="l[<?= $id ?>][houppier]"><?php foreach ($HOUP as $k => $v): ?><option value="<?= $k ?>" <?= $k === $l['houppier'] ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
  <td><input class="tiny" style="width:80px" name="l[<?= $id ?>][circ]" value="<?= e($l['circonference']) ?>"></td>
  <td><input class="tiny" name="l[<?= $id ?>][unite]" value="<?= e($l['unite']) ?>"></td>
  <?php foreach ($res as $r): ?><td class="r"><input class="num h" data-rid="<?= $r['id'] ?>" name="l[<?= $id ?>][h][<?= $r['id'] ?>]" value="<?= isset($temps[$id][$r['id']]) ? e((string)$temps[$id][$r['id']]) : '' ?>" inputmode="decimal" placeholder="–"></td><?php endforeach; ?>
  <td class="r"><input class="num forfait" name="l[<?= $id ?>][forfait]" value="<?= (float)$l['forfait'] > 0 ? e($l['forfait']) : '' ?>" inputmode="decimal" placeholder="–"></td>
  <td class="r"><input class="num ref" name="l[<?= $id ?>][prix_ref]" value="<?= $l['prix_ref'] !== null ? e((string)(float)$l['prix_ref']) : '' ?>" inputmode="decimal" placeholder="–"></td>
  <td class="r out ecart"></td>
  <td style="white-space:nowrap"><button class="mini" type="submit" form="c<?= $id ?>" title="Dupliquer">⧉</button> <button class="mini danger" type="submit" form="d<?= $id ?>" onclick="return confirm('Supprimer cette ligne ?')">×</button></td>
  <td class="r out pu"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p><button class="btn" type="submit">Enregistrer toutes les lignes</button> <a class="btn secondary" href="bordereaux.php">Composer un bordereau →</a></p>
</form>
<datalist id="familles"><?php foreach ($familles as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
<?php foreach ($lignes as $l): ?>
<form id="d<?= $l['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>"></form>
<form id="c<?= $l['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="dup"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>"></form>
<?php endforeach; ?>
<section class="card">
  <h2>Ajouter une ligne</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input class="tiny" style="width:110px" name="code" placeholder="Code"> <input name="libelle" placeholder="Désignation" required style="min-width:280px"> <input name="famille" placeholder="Famille" list="familles" style="width:160px"> <input class="tiny" name="unite" placeholder="Unité" value="U"> <button class="btn secondary" type="submit">Ajouter</button>
  </form>
  <p class="muted">Astuce : le bouton ⧉ duplique une ligne avec ses temps, pratique pour créer une variante (autre hauteur, autre houppier).</p>
</section>
<script>
const TAUX = <?= json_encode($taux) ?>, COEF = <?= json_encode($coef) ?>, PAS = <?= json_encode($pas) ?>;
const num = v => parseFloat(String(v).replace(',', '.')) || 0;
const fmt = v => v.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
function calc(tr) {
  let cout = 0;
  tr.querySelectorAll('input.h').forEach(i => cout += num(i.value) * (TAUX[i.dataset.rid] || 0));
  const brut = (cout + num(tr.querySelector('.forfait').value)) * COEF;
  const pu = PAS > 0 ? Math.round(brut / PAS) * PAS : Math.round(brut * 100) / 100;
  tr.querySelector('.pu').textContent = fmt(pu);
  const ref = num(tr.querySelector('.ref').value), ec = tr.querySelector('.ecart');
  if (ref > 0) { const d = pu - ref; ec.textContent = (d > 0 ? '+' : '') + Math.round(d) + ' €'; ec.style.color = Math.abs(d) / ref > 0.1 ? '#b3403a' : '#5b6a60'; } else ec.textContent = '';
}
document.querySelectorAll('tr[data-id]').forEach(tr => { calc(tr); tr.addEventListener('input', () => calc(tr)); });
</script>
<?php page_end(); ?>
