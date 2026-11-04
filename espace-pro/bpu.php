<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();
$eqPrincipale = ressource_equipe();
// Une seule colonne d'heures d'équipe : celles de l'équipe principale. Les autres équipes ne changent que le taux (menu « Prix avec » ici, « Équipe appliquée » dans le bordereau).
$res = array_values(array_filter(ressources(), fn($r) => $r['role'] !== 'equipe' || ($eqPrincipale && (int)$r['id'] === (int)$eqPrincipale['id'])));
$eqApercu = isset($_GET['equipe']) ? (int)$_GET['equipe'] : null; $taux = taux_courants($eqApercu); $equipes = ressources_equipes();
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
        foreach ($_POST['l'] ?? [] as $id => $d) { appliquer_actions((int)$id); appliquer_variantes((int)$id); }
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
    } elseif ($action === 'act_add') {
        $lid = (int)$_POST['ligne_id']; $aid = (int)$_POST['action_id']; $nb = max(0.25, (float)str_replace(',', '.', $_POST['nb'] ?? '1'));
        if ($aid) { q("INSERT INTO ligne_actions (ligne_id, action_id, nb) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nb = nb + VALUES(nb)", [$lid, $aid, $nb]); appliquer_actions($lid); flash('Action ajoutée à la prestation.'); }
    } elseif ($action === 'act_del') {
        $lid = (int)$_POST['ligne_id']; q("DELETE FROM ligne_actions WHERE ligne_id = ? AND action_id = ?", [$lid, (int)$_POST['action_id']]); appliquer_actions($lid); flash('Action retirée.');
    } elseif ($action === 'variantes') {
        $n = generer_variantes((int)$_POST['id'], !empty($_POST['houppier']));
        journal('catalogue', "$n variante(s) générée(s) pour la ligne " . (int)$_POST['id']); flash("$n variante(s) créée(s) et calculée(s) à partir de la ligne de base.");
    } elseif ($action === 'detacher') {
        q("UPDATE bpu_lignes SET base_id = NULL, source = 'Variante détachée' WHERE id = ?", [(int)$_POST['id']]); flash('Ligne détachée de sa base : ses temps sont maintenant libres.');
    } elseif ($action === 'purge_exemples') {
        require_admin();
        $n = q("UPDATE bpu_lignes SET actif = 0 WHERE actif = 1 AND famille = '' AND source = ''")->rowCount();
        journal('catalogue', "$n ligne(s) d'exemple supprimée(s)"); flash("$n ligne(s) d'exemple supprimée(s).");
    } elseif ($action === 'import') {
        require_admin();
        [$n, $np] = importer_catalogue(); journal('catalogue', "Import du catalogue : $n lignes, $np packs"); flash("Catalogue importé : $n lignes et $np packs ajoutés.");
    }
    $qs = http_build_query(array_filter(['famille' => $_POST['f_famille'] ?? '', 'hauteur' => $_POST['f_hauteur'] ?? '', 'pack' => $_POST['f_pack'] ?? '', 'q' => $_POST['f_q'] ?? '']));
    redirect('bpu.php' . ($qs ? "?$qs" : ''));
}

// Filtres
$fFam = $_GET['famille'] ?? ''; $fHaut = $_GET['hauteur'] ?? ''; $fQ = trim($_GET['q'] ?? ''); $fPack = (int)($_GET['pack'] ?? 0);
$where = ['actif = 1']; $p = [];
if ($fFam !== '') { $where[] = 'famille = ?'; $p[] = $fFam; }
if ($fHaut !== '') { $where[] = 'hauteur = ?'; $p[] = $fHaut; }
if ($fQ !== '') { $where[] = '(libelle LIKE ? OR code LIKE ?)'; $p[] = "%$fQ%"; $p[] = "%$fQ%"; }
if ($fPack) { $where[] = 'id IN (SELECT ligne_id FROM pack_lignes WHERE pack_id = ?)'; $p[] = $fPack; }
$packsListe = q("SELECT id, nom FROM packs ORDER BY nom")->fetchAll();
$lignes = q("SELECT * FROM bpu_lignes WHERE " . implode(' AND ', $where) . " ORDER BY famille, FIELD(hauteur,'','<10','10-20','>20'), FIELD(houppier,'','<=5','5-10','>10'), ordre, id", $p)->fetchAll();
$HLIB = ['' => 'Sans classe de hauteur', '<10' => 'Arbres de moins de 10 m', '10-20' => 'Arbres de 10 à 20 m', '>20' => 'Arbres de plus de 20 m'];
// Résumé par famille (nombre, prix mini/maxi) pour les en-têtes de bloc
$resume = [];
foreach ($lignes as $l) { $f = $l['famille'] ?: 'Sans famille'; $pu = prix_catalogue($l); $r = &$resume[$f]; $r['n'] = ($r['n'] ?? 0) + 1; $r['min'] = isset($r['min']) ? min($r['min'], $pu) : $pu; $r['max'] = isset($r['max']) ? max($r['max'], $pu) : $pu; unset($r); }
$familles = array_column(q("SELECT DISTINCT famille FROM bpu_lignes WHERE actif = 1 AND famille <> '' ORDER BY famille")->fetchAll(), 'famille');
$temps = temps_par_ligne(); $compo = actions_par_ligne(); $actionsDispo = actions_catalogue(); $eq = ressource_equipe();
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
  <select name="famille" onchange="this.form.submit()"><option value="">Toutes les familles</option><?php foreach ($familles as $f): ?><option <?= $f === $fFam ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?></select>
  <select name="hauteur" onchange="this.form.submit()"><?php foreach ($HAUT as $k => $v): ?><option value="<?= $k ?>" <?= $k === $fHaut ? 'selected' : '' ?>><?= $k === '' ? 'Toutes hauteurs' : $v ?></option><?php endforeach; ?></select>
  <input name="q" value="<?= e($fQ) ?>" placeholder="Rechercher (libellé, code)">
  <?php if ($packsListe): ?><select name="pack" title="N'afficher que les lignes d'un pack" onchange="this.form.submit()"><option value="">Tous les packs</option><?php foreach ($packsListe as $pk): ?><option value="<?= $pk['id'] ?>" <?= $fPack === (int)$pk['id'] ? 'selected' : '' ?>>Pack : <?= e($pk['nom']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php if (count($equipes) > 1): ?><select name="equipe" title="Équipe utilisée pour afficher les prix" onchange="this.form.submit()"><?php foreach ($equipes as $x): ?><option value="<?= $x['id'] ?>" <?= ($eqApercu ?? (int)$equipes[0]['id']) === (int)$x['id'] ? 'selected' : '' ?>>Prix avec : <?= e($x['nom']) ?> (<?= money((float)$x['taux']) ?>/h)</option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn secondary" type="submit">Filtrer</button>
  <a class="mini" href="bpu.php">Tout afficher</a>
  <span class="muted"><?= count($lignes) ?> / <?= $total ?> lignes</span>
  <button class="mini" type="button" onclick="toutes(true)">Tout déplier</button> <button class="mini" type="button" onclick="toutes(false)">Tout replier</button>
  <?php if (user()['role'] === 'admin'): ?><a class="mini" href="bpu.php?import=1">Importer le catalogue</a><?php endif; ?>
</form>
<?php $nbEx = (int)q("SELECT COUNT(*) c FROM bpu_lignes WHERE actif = 1 AND famille = '' AND source = ''")->fetch()['c']; if ($nbEx && user()['role'] === 'admin'): ?>
<form method="post" class="inline" onsubmit="return confirm('Supprimer les <?= $nbEx ?> lignes d\'exemple (sans famille) ?')"><?= csrf_field() ?><input type="hidden" name="action" value="purge_exemples"><span class="muted"><?= $nbEx ?> lignes d'exemple du premier jour sont encore là (bloc « Sans famille »).</span> <button class="mini danger" type="submit">Les supprimer</button></form>
<?php endif; ?>

<form method="post" id="f"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>"><input type="hidden" name="f_pack" value="<?= $fPack ?>">
<p class="scroll-aide">Le tableau défile dans sa propre fenêtre, dans les deux sens : molette pour descendre, barre du bas ou ⇤ ⇥ ou ⇧ + molette pour aller à droite, ou clic maintenu hors des champs pour le faire glisser. L'en-tête et la désignation restent visibles, pour voir les actions, les heures, le forfait et la référence.</p>
<div class="scroll">
<table class="tbl bpu">
<thead><tr><th>Ord.</th><th>Code</th><th class="w">Désignation</th><th>Famille</th><th>Haut.</th><th>Houp.</th><th>Circ.</th><th>Unité</th><th class="acts">Actions dans l'arbre</th>
<?php foreach ($res as $r): ?><th class="r rot" title="<?= $r['role'] === 'equipe' ? 'Heures d\'équipe (toute équipe : le taux dépend de l\'équipe choisie)' : e($r['nom']) . ' – ' . money((float)$r['taux']) . '/h' ?>"><span><?= $r['role'] === 'equipe' ? 'Heures d\'équipe' : e($r['nom']) ?></span></th><?php endforeach; ?>
<th class="r">Forfait €</th><th class="r">Réf. €</th><th class="r">Écart</th><th></th><th class="r pu">PU HT</th></tr></thead>
 <tbody>
<?php $famCour = null; $hautCour = null; $nbFam = count($resume); foreach ($lignes as $l): $id = $l['id']; $f = $l['famille'] ?: 'Sans famille';
  if ($f !== $famCour): $famCour = $f; $hautCour = null; $r = $resume[$f]; $grp = md5($f); ?>
<tr class="grp" data-grp="<?= $grp ?>"><td colspan="<?= 14 + count($res) ?>" onclick="toggle('<?= $grp ?>')"><button type="button" class="togg"><span class="chev">▸</span> <?= e($f) ?> <span class="muted">· <?= $r['n'] ?> ligne<?= $r['n'] > 1 ? 's' : '' ?> · de <?= money($r['min']) ?> à <?= money($r['max']) ?></span></button></td></tr>
<?php endif; if ($l['hauteur'] !== $hautCour && count(array_unique(array_map(fn($x) => $x['hauteur'], array_filter($lignes, fn($x) => ($x['famille'] ?: 'Sans famille') === $f)))) > 1): $hautCour = $l['hauteur']; ?>
<tr class="sousgrp g-<?= $grp ?>"><td colspan="<?= 14 + count($res) ?>"><span><?= e($HLIB[$l['hauteur']] ?? $l['hauteur']) ?></span></td></tr>
<?php endif; ?>
<tr class="g-<?= $grp ?>" data-id="<?= $id ?>" data-ref="<?= $l['prix_ref'] !== null ? (float)$l['prix_ref'] : '' ?>" title="<?= e($l['source']) ?>">
  <td><input class="tiny" name="l[<?= $id ?>][ordre]" value="<?= $l['ordre'] ?>"></td>
  <td><input class="tiny" style="width:110px" name="l[<?= $id ?>][code]" value="<?= e($l['code']) ?>"></td>
  <td><textarea class="lib" name="l[<?= $id ?>][libelle]" rows="1" title="<?= e($l['libelle']) ?>"><?= e($l['libelle']) ?></textarea></td>
  <td><input class="tiny" style="width:150px" name="l[<?= $id ?>][famille]" value="<?= e($l['famille']) ?>" list="familles"></td>
  <td><select name="l[<?= $id ?>][hauteur]"><?php foreach ($HAUT as $k => $v): ?><option value="<?= $k ?>" <?= $k === $l['hauteur'] ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
  <td><select name="l[<?= $id ?>][houppier]"><?php foreach ($HOUP as $k => $v): ?><option value="<?= $k ?>" <?= $k === $l['houppier'] ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
  <td><input class="tiny" style="width:80px" name="l[<?= $id ?>][circ]" value="<?= e($l['circonference']) ?>"></td>
  <td><input class="tiny" name="l[<?= $id ?>][unite]" value="<?= e($l['unite']) ?>"></td>
  <td class="acts"><?php $cp = $compo[$id] ?? []; foreach ($cp as $c): ?><span class="chip" title="<?= e((string)(float)$c['duree']) ?> h × <?= e((string)(float)$c['nb']) ?>"><?= e($c['nom']) ?><?= (float)$c['nb'] != 1 ? ' ×' . e((string)(float)$c['nb']) : '' ?> <button type="button" class="chipx" onclick="actDel(<?= $id ?>,<?= $c['action_id'] ?>)">×</button></span><?php endforeach; ?>
    <?php if ($actionsDispo): ?><span class="addact"><select class="actsel" data-l="<?= $id ?>"><option value="">+ action…</option><?php foreach ($actionsDispo as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['nom']) ?> (<?= e((string)(float)$x['duree']) ?> h)</option><?php endforeach; ?></select></span><?php endif; ?></td>
  <?php foreach ($res as $r): $compose = ($eq && (int)$r['id'] === (int)$eq['id'] && !empty($compo[$id])) || !empty($l['base_id']); ?><td class="r"><input class="num h" data-rid="<?= $r['id'] ?>" name="l[<?= $id ?>][h][<?= $r['id'] ?>]" value="<?= isset($temps[$id][$r['id']]) ? e((string)$temps[$id][$r['id']]) : '' ?>" inputmode="decimal" placeholder="–" <?= $compose ? 'readonly title="Calculé à partir des actions"' : '' ?>></td><?php endforeach; ?>
  <td class="r"><input class="num forfait" name="l[<?= $id ?>][forfait]" value="<?= (float)$l['forfait'] > 0 ? e($l['forfait']) : '' ?>" inputmode="decimal" placeholder="–"></td>
  <td class="r"><input class="num ref" name="l[<?= $id ?>][prix_ref]" value="<?= $l['prix_ref'] !== null ? e((string)(float)$l['prix_ref']) : '' ?>" inputmode="decimal" placeholder="–"></td>
  <td class="r out ecart"></td>
  <td style="white-space:nowrap"><?php if ($l['base_id']): ?><button class="mini" type="button" title="Variante calculée depuis sa ligne de base ; cliquer pour la rendre indépendante" onclick="if(confirm('Détacher cette variante de sa ligne de base ?'))varSend('detacher',<?= $id ?>,0)">⛓</button><?php else: ?><button class="mini" type="button" title="Générer les variantes 10–20 m et > 20 m (et houppiers)" onclick="varSend('variantes',<?= $id ?>,confirm('Générer aussi les variantes par houppier (3 × 3) ? Annuler = hauteur seule.')?1:0)">↕</button><?php endif; ?> <button class="mini" type="submit" form="c<?= $id ?>" title="Dupliquer">⧉</button> <button class="mini danger" type="submit" form="d<?= $id ?>" onclick="return confirm('Supprimer cette ligne ?')">×</button></td>
  <td class="r out pu"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p><button class="btn" type="submit">Enregistrer toutes les lignes</button> <a class="btn secondary" href="bordereaux.php">Composer un bordereau →</a></p>
</form>
<form id="fvar" method="post"><?= csrf_field() ?><input type="hidden" name="action" id="fvar_a"><input type="hidden" name="id" id="fvar_id"><input type="hidden" name="houppier" id="fvar_h"><input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>"><input type="hidden" name="f_pack" value="<?= $fPack ?>"></form>
<form id="fact" method="post"><?= csrf_field() ?><input type="hidden" name="action" id="fact_a"><input type="hidden" name="ligne_id" id="fact_l"><input type="hidden" name="action_id" id="fact_x"><input type="hidden" name="nb" value="1"><input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>"><input type="hidden" name="f_pack" value="<?= $fPack ?>"></form>
<datalist id="familles"><?php foreach ($familles as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
<?php foreach ($lignes as $l): ?>
<form id="d<?= $l['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>"><input type="hidden" name="f_pack" value="<?= $fPack ?>"></form>
<form id="c<?= $l['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="dup"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="f_famille" value="<?= e($fFam) ?>"><input type="hidden" name="f_hauteur" value="<?= e($fHaut) ?>"><input type="hidden" name="f_q" value="<?= e($fQ) ?>"><input type="hidden" name="f_pack" value="<?= $fPack ?>"></form>
<?php endforeach; ?>
<section class="card">
  <h2>Ajouter une ligne</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input class="tiny" style="width:110px" name="code" placeholder="Code"> <input name="libelle" placeholder="Désignation" required style="min-width:280px"> <input name="famille" placeholder="Famille" list="familles" style="width:160px"> <input class="tiny" name="unite" placeholder="Unité" value="U"> <button class="btn secondary" type="submit">Ajouter</button>
  </form>
  <p class="muted">Boutons de ligne : <strong>↕</strong> génère les variantes de taille (10–20 m, > 20 m, houppiers) à partir d'une ligne de base, avec les pourcentages de « Paramètres » ; <strong>⛓</strong> signale une variante liée (temps calculés, cliquer pour la détacher) ; <strong>⧉</strong> duplique ; <strong>×</strong> supprime.</p>
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
// Désignations : hauteur ajustée au texte
function ajuster(t) { if (!t.offsetParent) return; t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; }
document.querySelectorAll('textarea.lib').forEach(t => { ajuster(t); t.addEventListener('input', () => ajuster(t)); });
new ResizeObserver(() => document.querySelectorAll('textarea.lib').forEach(ajuster)).observe(document.body);
function actSend(a, l, x) { document.getElementById('fact_a').value = a; document.getElementById('fact_l').value = l; document.getElementById('fact_x').value = x; document.getElementById('fact').submit(); }
function actDel(l, x) { actSend('act_del', l, x); }
function varSend(a, id, h) { document.getElementById('fvar_a').value = a; document.getElementById('fvar_id').value = id; document.getElementById('fvar_h').value = h; document.getElementById('fvar').submit(); }
document.querySelectorAll('select.actsel').forEach(s => s.addEventListener('change', () => { if (s.value) actSend('act_add', s.dataset.l, s.value); }));
// Blocs repliables (état mémorisé dans le navigateur)
let ouverts = {}; try { ouverts = JSON.parse(localStorage.getItem('cat_ouverts') || '{}'); } catch (e) {}
function appliquer(g, on) { document.querySelectorAll('.g-' + g).forEach(tr => { tr.style.display = on ? '' : 'none'; if (on) tr.querySelectorAll('textarea.lib').forEach(ajuster); }); const c = document.querySelector('tr[data-grp="' + g + '"] .chev'); if (c) c.textContent = on ? '▾' : '▸'; }
function toggle(g) { ouverts[g] = !ouverts[g]; appliquer(g, ouverts[g]); try { localStorage.setItem('cat_ouverts', JSON.stringify(ouverts)); } catch (e) {} }
function toutes(on) { document.querySelectorAll('tr[data-grp]').forEach(tr => { ouverts[tr.dataset.grp] = on; appliquer(tr.dataset.grp, on); }); try { localStorage.setItem('cat_ouverts', JSON.stringify(ouverts)); } catch (e) {} }
const filtre = <?= json_encode($fFam !== '' || $fQ !== '' || $fPack > 0) ?>;
document.querySelectorAll('tr[data-grp]').forEach(tr => appliquer(tr.dataset.grp, filtre || !!ouverts[tr.dataset.grp]));
</script>
<?php page_end(); ?>
