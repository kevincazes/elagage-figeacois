<?php
// Composition d'un bordereau : sections, lignes du catalogue, packs, lignes libres, quantités (DQE), prix figés
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_chiffrage();
$pdo = db();
$b = q("SELECT * FROM bordereaux WHERE id = ?", [(int)($_GET['id'] ?? 0)])->fetch();
if (!$b) { flash('Bordereau introuvable.', 'err'); redirect('bordereaux.php'); }
$bid = (int)$b['id']; $fige = $b['statut'] === 'fige';
$retour = "bordereau.php?id=$bid";
$equipes = ressources_equipes(); $eqChoisie = equipe_choisie($b['equipe_id'] !== null ? (int)$b['equipe_id'] : null); $eqId = $eqChoisie ? (int)$eqChoisie['id'] : null;
$noms = []; foreach (q("SELECT id, prenom, nom FROM utilisateurs") as $u) $noms[$u['id']] = trim($u['prenom'] . ' ' . $u['nom']);

// Verrou : battement de cœur (JS), libération, prise forcée
if (isset($_GET['ping'])) { $d = verrou_prendre($bid); http_response_code($d ? 409 : 204); exit; }
if (isset($_GET['liberer'])) { verrou_liberer($bid); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forcer') { check_csrf(); require_admin(); verrou_prendre($bid, true); flash('Verrou libéré de force : tu as la main.'); redirect($retour); }
$detenteur = verrou_prendre($bid);
$lecture = $detenteur !== null; // quelqu'un d'autre a le dossier ouvert
$next = fn() => (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM bordereau_lignes WHERE bordereau_id = ?", [$bid])->fetch()['o'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($lecture) { flash("Dossier ouvert par {$detenteur['prenom']} : lecture seule.", 'err'); redirect($retour); }
    if ($fige && !in_array($a, ['defiger'])) { flash('Bordereau figé : défige-le pour le modifier.', 'err'); redirect($retour); }
    if ($a === 'entete') {
        $neq = isset($_POST['equipe_id']) && $_POST['equipe_id'] !== '' ? (int)$_POST['equipe_id'] : null;
        q("UPDATE bordereaux SET nom = ?, client = ?, notes = ?, equipe_id = ? WHERE id = ?", [trim($_POST['nom']), trim($_POST['client'] ?? ''), trim($_POST['notes'] ?? ''), $neq, $bid]);
        $neqNom = equipe_choisie($neq)['nom'] ?? ''; journal('entete', 'En-tête modifié' . ($neq !== ($b['equipe_id'] !== null ? (int)$b['equipe_id'] : null) ? " ; équipe : $neqNom" : ''), $bid); flash('En-tête enregistré.');
    } elseif ($a === 'section') {
        q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, libelle) VALUES (?,?,'section',?)", [$bid, $next(), trim($_POST['libelle'] ?? 'Section')]); journal('ajout', 'Section « ' . trim($_POST['libelle'] ?? '') . ' »', $bid);
    } elseif ($a === 'libre') {
        q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, code, libelle, unite, pu_manuel) VALUES (?,?,'ligne',?,?,?,?)", [$bid, $next(), trim($_POST['code'] ?? ''), trim($_POST['libelle'] ?? 'Ligne'), trim($_POST['unite'] ?? 'U'), ($_POST['pu'] ?? '') === '' ? null : nb($_POST['pu'])]); journal('ajout', 'Ligne libre « ' . trim($_POST['libelle'] ?? '') . ' »', $bid);
    } elseif ($a === 'catalogue') {
        $o = $next(); $n = 0;
        foreach ($_POST['l'] ?? [] as $lid => $on) { $l = q("SELECT * FROM bpu_lignes WHERE id = ?", [(int)$lid])->fetch(); if (!$l) continue;
            q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, code, libelle, unite, ligne_id) VALUES (?,?,'ligne',?,?,?,?)", [$bid, $o++, $l['code'], $l['libelle'], $l['unite'], $l['id']]); $n++; }
        journal('ajout', "$n ligne(s) du catalogue", $bid); flash("$n ligne(s) ajoutée(s).");
    } elseif ($a === 'pack') {
        $o = $next(); $n = 0; $pack = q("SELECT * FROM packs WHERE id = ?", [(int)$_POST['pack_id']])->fetch();
        if ($pack && !empty($_POST['avec_section'])) q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, libelle) VALUES (?,?,'section',?)", [$bid, $o++, $pack['nom']]);
        foreach (q("SELECT l.* FROM pack_lignes pl JOIN bpu_lignes l ON l.id = pl.ligne_id WHERE pl.pack_id = ? AND l.actif = 1 ORDER BY pl.ordre", [(int)$_POST['pack_id']]) as $l) {
            q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, code, libelle, unite, ligne_id) VALUES (?,?,'ligne',?,?,?,?)", [$bid, $o++, $l['code'], $l['libelle'], $l['unite'], $l['id']]); $n++; }
        journal('ajout', 'Pack « ' . ($pack['nom'] ?? '') . "» ($n lignes)", $bid); flash("Pack ajouté : $n lignes.");
    } elseif ($a === 'save') {
        $avant = []; foreach (q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ?", [$bid]) as $x) $avant[$x['id']] = $x;
        $modifs = [];
        foreach ($_POST['r'] ?? [] as $id => $d) {
            $o = $avant[(int)$id] ?? null;
            if ($o) {
                if (array_key_exists('cat', $d) && trim((string)$d['cat']) !== trim((string)($d['cat0'] ?? ''))) { // rattachement modifié par l'utilisateur (code tapé)
                    $code = trim(explode(' — ', (string)$d['cat'])[0]);
                    $nl = $code === '' ? null : (preg_match('/^#(\d+)$/', $code, $mm) ? (q("SELECT id FROM bpu_lignes WHERE id = ?", [(int)$mm[1]])->fetch()['id'] ?? false) : (q("SELECT id FROM bpu_lignes WHERE actif = 1 AND code = ? ORDER BY id LIMIT 1", [$code])->fetch()['id'] ?? false));
                    if ($nl !== false && (int)$nl !== (int)$o['ligne_id']) { q("UPDATE bordereau_lignes SET ligne_id = ? WHERE id = ?", [$nl === null ? null : (int)$nl, (int)$id]); $modifs[] = '[' . ($o['numero'] ?: $o['ordre']) . ' ' . mb_substr($o['libelle'], 0, 40) . '] prestation : ' . ($code ?: 'aucune'); }
                }
                $nv = ['ordre' => (int)($d['ordre'] ?? 0), 'numero' => trim($d['numero'] ?? ''), 'code' => trim($d['code'] ?? ''), 'libelle' => trim($d['libelle'] ?? ''), 'unite' => trim($d['unite'] ?? ''),
                       'quantite' => ($d['qte'] ?? '') === '' ? null : nb($d['qte']), 'pu_manuel' => ($d['pu'] ?? '') === '' ? null : nb($d['pu'])];
                $ch = [];
                foreach ($nv as $k => $v) { $ov = $o[$k]; if (in_array($k, ['quantite', 'pu_manuel'])) { $ov = $ov === null ? null : (float)$ov; } elseif ($k === 'ordre') $ov = (int)$ov;
                    if ($ov !== $v) $ch[] = ['numero' => 'N°', 'code' => 'code', 'libelle' => 'libellé', 'unite' => 'unité', 'quantite' => 'quantité', 'pu_manuel' => 'PU imposé', 'ordre' => 'tri'][$k] . " : " . ($ov === null || $ov === '' ? '—' : $ov) . " → " . ($v === null || $v === '' ? '—' : $v); }
                if ($ch) $modifs[] = '[' . ($o['numero'] ?: $o['ordre']) . ' ' . mb_substr($o['libelle'], 0, 40) . '] ' . implode(', ', $ch);
            }
            q("UPDATE bordereau_lignes SET ordre = ?, numero = ?, code = ?, libelle = ?, unite = ?, quantite = ?, pu_manuel = ? WHERE id = ? AND bordereau_id = ?",
              [(int)($d['ordre'] ?? 0), trim($d['numero'] ?? ''), trim($d['code'] ?? ''), trim($d['libelle'] ?? ''), trim($d['unite'] ?? ''), ($d['qte'] ?? '') === '' ? null : nb($d['qte']), ($d['pu'] ?? '') === '' ? null : nb($d['pu']), (int)$id, $bid]);
        }
        if ($modifs) journal('modif', count($modifs) . " ligne(s) modifiée(s) :\n" . implode("\n", $modifs), $bid);
        flash('Bordereau enregistré.');
    } elseif ($a === 'del') {
        $x = q("SELECT * FROM bordereau_lignes WHERE id = ? AND bordereau_id = ?", [(int)$_POST['id'], $bid])->fetch();
        q("DELETE FROM bordereau_lignes WHERE id = ? AND bordereau_id = ?", [(int)$_POST['id'], $bid]);
        if ($x) journal('suppression', ($x['type'] === 'section' ? 'Section' : 'Ligne') . ' « ' . $x['libelle'] . ' »', $bid);
    } elseif ($a === 'renum') {
        $o = 0; foreach (q("SELECT id FROM bordereau_lignes WHERE bordereau_id = ? ORDER BY ordre, id", [$bid]) as $l) { ++$o; q("UPDATE bordereau_lignes SET ordre = ?, numero = ? WHERE id = ?", [$o, (string)$o, $l['id']]); }
        journal('modif', 'Renumérotation 1, 2, 3…', $bid); flash('Numérotation remise à plat.');
    } elseif ($a === 'figer') {
        $taux = []; foreach (ressources() as $r) $taux[$r['id']] = ['nom' => $r['nom'], 'taux' => (float)$r['taux']];
        if ($eqChoisie) $taux['equipe'] = ['nom' => 'Équipe appliquée : ' . $eqChoisie['nom'], 'taux' => (float)$eqChoisie['taux']];
        foreach (q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? AND type = 'ligne'", [$bid]) as $l) {
            $pu = $l['pu_manuel'] !== null ? (float)$l['pu_manuel'] : ($l['ligne_id'] ? prix_catalogue(q("SELECT * FROM bpu_lignes WHERE id = ?", [$l['ligne_id']])->fetch() ?: ['id' => 0, 'forfait' => 0], $eqId) : 0);
            q("UPDATE bordereau_lignes SET pu_fige = ? WHERE id = ?", [$pu, $l['id']]);
        }
        q("UPDATE bordereaux SET statut = 'fige', fige_le = NOW(), coef = ?, tva = ?, taux_json = ? WHERE id = ?", [(float)setting('coef'), (float)setting('tva'), json_encode($taux, JSON_UNESCAPED_UNICODE), $bid]);
        journal('figer', 'Prix figés (coef ' . setting('coef') . ')', $bid); flash('Bordereau figé : les prix ne bougeront plus, même si les taux changent.');
    } elseif ($a === 'defiger') {
        q("UPDATE bordereaux SET statut = 'brouillon', fige_le = NULL WHERE id = ?", [$bid]); journal('defiger', 'Remis en brouillon', $bid); flash('Bordereau remis en brouillon (les prix suivent à nouveau le catalogue).');
    }
    redirect($retour);
}

$lignes = q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? ORDER BY ordre, id", [$bid])->fetchAll();
$ro = $fige || $lecture; // champs non modifiables
$cat = []; foreach (q("SELECT * FROM bpu_lignes") as $c) $cat[$c['id']] = $c; // toutes, y compris supprimées : le prix d'une ligne déjà utilisée reste calculable
$tva = $fige ? (float)$b['tva'] : (float)setting('tva', '20');
$puDe = function (array $l) use ($fige, $cat, $eqId): float {
    if ($fige) return (float)$l['pu_fige'];
    if ($l['pu_manuel'] !== null) return (float)$l['pu_manuel'];
    return isset($cat[$l['ligne_id']]) ? prix_catalogue($cat[$l['ligne_id']], $eqId) : 0.0;
};
$total = 0; $nbQte = 0;
foreach ($lignes as $l) if ($l['type'] === 'ligne' && $l['quantite'] !== null) { $total += $puDe($l) * (float)$l['quantite']; $nbQte++; }
$packs = q("SELECT p.*, (SELECT COUNT(*) FROM pack_lignes pl WHERE pl.pack_id = p.id) nb FROM packs p ORDER BY nom")->fetchAll();
$familles = array_column(q("SELECT DISTINCT famille FROM bpu_lignes WHERE actif = 1 AND famille <> '' ORDER BY famille")->fetchAll(), 'famille');
$catalogue = q("SELECT * FROM bpu_lignes WHERE actif = 1 ORDER BY famille, FIELD(hauteur,'','<10','10-20','>20'), FIELD(houppier,'','<=5','5-10','>10'), ordre, id")->fetchAll();
$HLIB = ['' => '', '<10' => 'Arbres de moins de 10 m', '10-20' => 'Arbres de 10 à 20 m', '>20' => 'Arbres de plus de 20 m'];
page_start($b['nom'], 'bordereau.php', ['bordereaux.php', 'Bordereaux']);
if ($lecture): ?>
<div class="flash err">Dossier ouvert par <strong><?= e($detenteur['prenom'] . ' ' . $detenteur['nom']) ?></strong> depuis <?= date('H:i', strtotime($detenteur['verrou_le'])) ?> : tu peux le consulter, pas le modifier. Il se libérera quand cette personne quittera la page, ou après <?= VERROU_MINUTES ?> min sans activité.
  <?php if (est_admin()): ?><form method="post" class="inline" onsubmit="return confirm('Forcer la libération ? Les modifications non enregistrées de cette personne seront perdues.')"><?= csrf_field() ?><input type="hidden" name="action" value="forcer"><button class="mini danger">Forcer la libération (admin)</button></form><?php endif; ?>
</div>
<?php else: ?>
<p class="muted no-print">Tu as la main sur ce dossier. <a href="bordereaux.php" onclick="navigator.sendBeacon('bordereau.php?id=<?= $bid ?>&liberer=1')">Libérer et revenir à la liste</a></p>
<?php endif; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="entete">
  <div class="cols">
    <label>Marché <input name="nom" value="<?= e($b['nom']) ?>" required <?= $ro ? 'readonly' : '' ?>></label>
    <label>Collectivité / client <input name="client" value="<?= e($b['client']) ?>" <?= $ro ? 'readonly' : '' ?>></label>
    <label>Notes <input name="notes" value="<?= e($b['notes']) ?>" <?= $ro ? 'readonly' : '' ?>></label>
    <?php if ($equipes): ?><label>Équipe appliquée aux prix <?php if ($ro): ?><input value="<?= e($eqChoisie['nom'] ?? '') ?> – <?= money((float)($eqChoisie['taux'] ?? 0)) ?>/h" readonly><?php else: ?><select name="equipe_id" onchange="this.form.submit()"><?php foreach ($equipes as $x): ?><option value="<?= $x['id'] ?>" <?= $eqId === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nom']) ?> – <?= money((float)$x['taux']) ?>/h</option><?php endforeach; ?></select><?php endif; ?></label><?php endif; ?>
  </div>
  <?php if ($fige): ?><p class="ok">Figé le <?= date('d/m/Y à H:i', strtotime($b['fige_le'])) ?> · coefficient <?= e($b['coef']) ?> · TVA <?= e($b['tva']) ?> %</p>
  <?php elseif (!$lecture): ?><button class="btn secondary" type="submit">Enregistrer l'en-tête</button><?php endif; ?>
  <a class="btn" href="export.php?id=<?= $bid ?>">Excel (CSV)</a> <button class="btn secondary" type="button" onclick="document.getElementById('bulleIA').click()">Assistant ✦</button> <a class="btn secondary" href="bordereau.php?id=<?= $bid ?>&print=1" target="_blank">Imprimer / PDF</a>
  <?php if ($lecture): ?><?php elseif ($fige): ?><button class="mini" type="submit" form="defiger">Défiger</button><?php else: ?><button class="mini" type="submit" form="figer" onclick="return confirm('Figer les prix de ce bordereau ?')">Figer les prix</button><?php endif; ?>
</form>
<form id="figer" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="figer"></form>
<form id="defiger" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="defiger"></form>

<datalist id="catcodes"><?php foreach ($catalogue as $c): ?><option value="<?= e($c['code'] !== '' ? $c['code'] : '#' . $c['id']) ?>"><?= e(mb_substr($c['libelle'], 0, 90)) ?> · <?= money(prix_catalogue($c, $eqId)) ?></option><?php endforeach; ?></datalist>
<?php if (!$ro): ?><p class="barre-ajout">Ajouter : <a class="mini" href="#ajout">un pack</a> <a class="mini" href="#ajout-cat">des lignes du catalogue</a> <a class="mini" href="#ajout">un titre de section</a> <a class="mini" href="#ajout">une ligne libre</a> <span class="muted small">· Colonnes : <strong>PU calculé</strong> = temps du catalogue × taux de l'équipe × coefficient (clic ↗ pour régler le temps) ; <strong>PU imposé</strong> = prix forcé pour ce marché seulement ; <strong>PU retenu</strong> = celui qui part dans l'offre.</span></p><?php endif; ?>
<form method="post" id="f" data-paquet><?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="scroll"><table class="tbl bord">
<thead><tr><th title="Ordre de tri">Tri</th><th>N°</th><th>Code</th><th class="w">Désignation</th><th>Unité</th><th class="r">Qté (DQE)</th><th title="Prestation du catalogue qui donne le prix : tape un code ou un mot pour choisir">Prestation du catalogue</th><th class="r">PU calculé</th><th class="r">PU imposé</th><th class="r">Total HT</th><th></th><th class="r pu">PU retenu</th></tr></thead>
<tbody>
<?php $n = 0; foreach ($lignes as $l): $id = $l['id']; ?>
<?php if ($l['type'] === 'section'): ?>
<tr class="section"><td><input class="tiny" name="r[<?= $id ?>][ordre]" value="<?= $l['ordre'] ?>" <?= $ro ? 'readonly' : '' ?>></td><td><input class="tiny" name="r[<?= $id ?>][numero]" value="<?= e($l['numero']) ?>" <?= $ro ? 'readonly' : '' ?>></td><td colspan="8"><input name="r[<?= $id ?>][libelle]" value="<?= e($l['libelle']) ?>" style="font-weight:900;text-transform:uppercase" <?= $ro ? 'readonly' : '' ?>></td>
<td><?php if (!$ro): ?><button class="mini danger" type="submit" form="x<?= $id ?>">×</button><?php endif; ?></td><td class="pu"></td></tr>
<?php else: $n++; $calc = isset($cat[$l['ligne_id']]) ? prix_catalogue($cat[$l['ligne_id']], $eqId) : null; $pu = $puDe($l); $src = $cat[$l['ligne_id']]['source'] ?? ''; ?>
<tr data-calc="<?= $calc ?? '' ?>" title="<?= e($src) ?>">
  <td><input class="tiny" name="r[<?= $id ?>][ordre]" value="<?= $l['ordre'] ?>" <?= $ro ? 'readonly' : '' ?>></td>
  <td><input class="tiny" name="r[<?= $id ?>][numero]" value="<?= e($l['numero']) ?>" placeholder="<?= $l['ordre'] ?>" <?= $ro ? 'readonly' : '' ?>></td>
  <td><input class="tiny" style="width:100px" name="r[<?= $id ?>][code]" value="<?= e($l['code']) ?>" <?= $ro ? 'readonly' : '' ?>></td>
  <td><textarea class="lib" name="r[<?= $id ?>][libelle]" rows="1" <?= $ro ? 'readonly' : '' ?>><?= e($l['libelle']) ?></textarea><?php if ($l['ligne_id'] && isset($cat[$l['ligne_id']]) && !(int)$cat[$l['ligne_id']]['actif']): ?><br><span class="off">prestation supprimée du catalogue : prix conservé, à rattacher à une autre prestation ou à imposer</span><?php endif; ?></td>
  <td><input class="tiny" style="width:70px" name="r[<?= $id ?>][unite]" value="<?= e($l['unite']) ?>" <?= $ro ? 'readonly' : '' ?>></td>
  <td class="r"><input class="num qte" name="r[<?= $id ?>][qte]" value="<?= $l['quantite'] !== null ? e((string)(float)$l['quantite']) : '' ?>" inputmode="decimal" placeholder="–" <?= $ro ? 'readonly' : '' ?>></td>
  <td><?php $cl = $cat[$l['ligne_id']] ?? null; ?><input type="hidden" name="r[<?= $id ?>][cat0]" value="<?= $cl ? e($cl['code'] !== '' ? $cl['code'] : '#' . $cl['id']) : '' ?>"><input class="catsel" list="catcodes" name="r[<?= $id ?>][cat]" value="<?= $cl ? e($cl['code'] !== '' ? $cl['code'] : '#' . $cl['id']) : '' ?>" placeholder="aucune – prix à imposer" title="<?= $cl ? e($cl['libelle']) : 'Aucune prestation : saisis un PU imposé, ou tape un code / un mot pour rattacher une prestation' ?>" style="width:150px" <?= $ro ? 'readonly' : '' ?>></td>
  <td class="r out calc"><?php if ($calc !== null): ?><a href="bpu.php?ligne=<?= (int)$l['ligne_id'] ?>" data-fen="Prestation du catalogue" data-raf title="Voir ou régler le temps de cette prestation dans une fenêtre, sans quitter le bordereau"><?= money($calc) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
  <td class="r"><input class="num manuel" name="r[<?= $id ?>][pu]" value="<?= $l['pu_manuel'] !== null ? e((string)(float)$l['pu_manuel']) : '' ?>" inputmode="decimal" placeholder="–" <?= $ro ? 'readonly' : '' ?>></td>
  <td class="r out tot"></td>
  <td><?php if (!$ro): ?><button class="mini danger" type="submit" form="x<?= $id ?>" onclick="return confirm('Retirer cette ligne ?')">×</button><?php endif; ?></td>
  <td class="r out pu" data-fige="<?= $fige ? $pu : '' ?>"></td>
</tr>
<?php endif; endforeach; ?>
</tbody>
<tfoot><tr><td colspan="9" class="r"><strong>Total estimatif HT (lignes avec quantité)</strong></td><td class="r out" id="total"></td><td></td><td class="pu"></td></tr></tfoot>
</table></div>
<?php if (!$ro): ?><p><button class="btn" type="submit">Enregistrer le bordereau</button> <button class="mini" type="submit" form="renum">Renuméroter 1, 2, 3…</button> <span class="muted">« Tri » ordonne les lignes ; « N° » est le numéro affiché sur le bordereau (ex. 2.1.1), libre ; « PU imposé » remplace le prix calculé.</span></p><?php endif; ?>
</form>
<form id="renum" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="renum"></form>
<?php foreach ($lignes as $l): ?><form id="x<?= $l['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $l['id'] ?>"></form><?php endforeach; ?>

<?php if (!$ro): ?>
<div class="cols" id="ajout">
<section class="card">
  <h2>Ajouter un pack</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="pack">
    <select name="pack_id"><?php foreach ($packs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['nom']) ?> (<?= $p['nb'] ?>)</option><?php endforeach; ?></select>
    <label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="avec_section" value="1" checked style="width:auto;margin:0"> avec un titre de section</label>
    <button class="btn secondary" type="submit">Ajouter</button>
  </form>
  <h2>Ajouter un titre de section</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="section"><input name="libelle" placeholder="Ex. 2.0 – Abattage d'arbres" required style="min-width:280px"> <button class="btn secondary" type="submit">Ajouter</button></form>
  <h2>Ajouter une ligne libre</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="libre">
    <input class="tiny" style="width:90px" name="code" placeholder="Code"> <input name="libelle" placeholder="Désignation" required style="min-width:260px"> <input class="tiny" name="unite" placeholder="Unité" value="U" style="width:80px;flex:none"> <input class="num" name="pu" placeholder="PU €" inputmode="decimal"> <button class="btn secondary" type="submit">Ajouter</button>
  </form>
</section>
<section class="card" id="ajout-cat">
  <h2>Ajouter des lignes du catalogue</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="catalogue">
    <div class="inline">
      <select id="fam" onchange="filtrer()"><option value="">Toutes les familles</option><?php foreach ($familles as $f): ?><option><?= e($f) ?></option><?php endforeach; ?></select>
      <select id="haut" onchange="filtrer()"><option value="">Toutes hauteurs</option><option value="<10">&lt; 10 m</option><option value="10-20">10–20 m</option><option value=">20">&gt; 20 m</option></select>
      <select id="houp" onchange="filtrer()"><option value="">Tous houppiers</option><option value="<=5">≤ 5 m</option><option value="5-10">5–10 m</option><option value=">10">&gt; 10 m</option></select>
      <input id="rech" placeholder="Rechercher…" oninput="filtrer()">
    </div>
    <div id="choix" style="max-height:420px;overflow:auto;border:1px solid #e0e5da;border-radius:8px;padding:8px;margin:10px 0">
      <?php $fam = null; $haut = null; foreach ($catalogue as $c): ?>
      <?php if ($c['famille'] !== $fam) { $fam = $c['famille']; $haut = null; $nb = count(array_filter($catalogue, fn($x) => $x['famille'] === $fam)); echo '<div class="fam-titre" data-fam="' . e($fam) . '"><button type="button" class="togg" onclick="plier(this)"><span class="chev">▸</span> ' . e($fam ?: 'Sans famille') . ' <span class="muted">· ' . $nb . '</span></button></div>'; } ?>
      <?php if ($c['hauteur'] !== $haut && $c['hauteur'] !== '') { $haut = $c['hauteur']; echo '<div class="sous-titre choix" data-fam="' . e($fam) . '" data-haut="' . e($c['hauteur']) . '" data-houp="">' . e($HLIB[$c['hauteur']]) . '</div>'; } ?>
      <label class="choix" data-fam="<?= e($c['famille']) ?>" data-haut="<?= e($c['hauteur']) ?>" data-houp="<?= e($c['houppier']) ?>" style="font-weight:400;margin:2px 0;display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="l[<?= $c['id'] ?>]" style="width:auto;margin:0"> <span class="muted" style="min-width:90px"><?= e($c['code']) ?></span> <span style="flex:1"><?= e($c['libelle']) ?></span> <strong style="white-space:nowrap"><?= money(prix_catalogue($c, $eqId)) ?></strong>
      </label>
      <?php endforeach; ?>
    </div>
    <p class="inline"><button class="mini" type="button" onclick="document.querySelectorAll('#choix label.choix').forEach(l=>{if(l.style.display!=='none')l.querySelector('input').checked=true})">Tout cocher (visible)</button> <button class="mini" type="button" onclick="document.querySelectorAll('#choix input').forEach(i=>i.checked=false)">Tout décocher</button></p>
    <button class="btn" type="submit">Ajouter les lignes cochées</button>
  </form>
</section>
</div>
<?php endif; ?>
<section class="card no-print">
  <h2>Historique du dossier</h2>
  <?php $hist = q("SELECT * FROM historique WHERE bordereau_id = ? ORDER BY quand DESC, id DESC LIMIT 100", [$bid])->fetchAll(); if (!$hist): ?><p class="muted">Aucune action enregistrée.</p><?php else: ?>
  <div style="overflow-x:auto"><table class="tbl"><tr><th>Quand</th><th>Qui</th><th>Action</th><th>Détail</th></tr>
  <?php foreach ($hist as $h): ?><tr><td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($h['quand'])) ?></td><td><?= e($noms[$h['utilisateur_id']] ?? '—') ?></td><td><?= e(['creation' => 'Création', 'entete' => 'En-tête', 'ajout' => 'Ajout', 'modif' => 'Modification', 'suppression' => 'Suppression', 'figer' => 'Figé', 'defiger' => 'Défigé', 'verrou_force' => 'Verrou forcé'][$h['action']] ?? $h['action']) ?></td><td class="small" style="white-space:pre-line"><?= e($h['detail']) ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
</section>
<script>
<?php if (!$lecture): ?>
// Garde le verrou tant que la page est ouverte ; le libère en partant
setInterval(() => fetch('bordereau.php?id=<?= $bid ?>&ping=1').then(r => { if (r.status === 409) location.reload(); }), 60000);
window.addEventListener('pagehide', () => navigator.sendBeacon('bordereau.php?id=<?= $bid ?>&liberer=1'));
<?php endif; ?>
const num = v => parseFloat(String(v).replace(/[\s\u00a0\u202f]/g, '').replace(',', '.')) || 0;
const fmt = v => (Math.round(v * 100) / 100).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
const TVA = <?= json_encode($tva) ?>;
function recalc() {
  let total = 0;
  document.querySelectorAll('tr[data-calc]').forEach(tr => {
    const puCell = tr.querySelector('.pu'); let pu;
    if (puCell.dataset.fige !== '') pu = num(puCell.dataset.fige);
    else { const mv = tr.querySelector('.manuel').value.trim(); pu = mv !== '' ? num(mv) : num(tr.dataset.calc); } // un PU imposé à 0 (gratuit) est respecté, comme à l'export
    puCell.textContent = fmt(pu);
    const q = num(tr.querySelector('.qte').value);
    const qv = tr.querySelector('.qte').value.trim();
    tr.querySelector('.tot').textContent = qv !== '' ? fmt(pu * q) : '';
    if (qv !== '') total += pu * q;
  });
  document.getElementById('total').textContent = total !== 0 ? fmt(total) + ' HT · ' + fmt(total * (1 + TVA / 100)) + ' TTC' : '—';
}
document.querySelectorAll('tr[data-calc]').forEach(tr => tr.addEventListener('input', recalc)); recalc();
function ajuster(t) { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; }
document.querySelectorAll('textarea.lib').forEach(t => { ajuster(t); t.addEventListener('input', () => ajuster(t)); });
// Rattachement à une prestation : prix mis à jour tout de suite (enregistrer pour conserver)
const CATPU = <?php $m = []; foreach (q("SELECT * FROM bpu_lignes WHERE actif = 1 ORDER BY id") as $c) { $k = $c['code'] !== '' ? $c['code'] : '#' . $c['id']; if (!isset($m[$k])) $m[$k] = prix_catalogue($c, $eqId); } echo json_encode($m, JSON_UNESCAPED_UNICODE); ?>;
document.querySelectorAll('input.catsel').forEach(i => i.addEventListener('change', () => {
  const tr = i.closest('tr'), code = i.value.split(' — ')[0].trim(), cell = tr.querySelector('.calc');
  if (code === '') { tr.dataset.calc = ''; cell.innerHTML = '<span class="muted">—</span>'; }
  else if (code in CATPU) { tr.dataset.calc = CATPU[code]; cell.textContent = fmt(CATPU[code]) + ' (à enregistrer)'; }
  else { cell.innerHTML = '<span class="off">code inconnu</span>'; }
  recalc();
}));
const plies = {};
function plier(btn) { const t = btn.closest('.fam-titre'), f = t.dataset.fam; plies[f] = !(plies[f] ?? true); filtrer(); }
function filtrer() {
  if (!document.getElementById('fam')) return; // bordereau figé ou en lecture seule : pas de cadre d'ajout
  const f = document.getElementById('fam').value, h = document.getElementById('haut').value, d = document.getElementById('houp').value, r = document.getElementById('rech').value.toLowerCase();
  const actif = f || h || d || r; // avec un filtre, on déplie tout ce qui correspond
  document.querySelectorAll('#choix .choix').forEach(l => {
    const sousTitre = l.classList.contains('sous-titre');
    const ok = (!f || l.dataset.fam === f) && (!h || l.dataset.haut === h) && (sousTitre || !d || l.dataset.houp === d) && (sousTitre || !r || l.textContent.toLowerCase().includes(r));
    l.style.display = ok && (actif || !(plies[l.dataset.fam] ?? true)) ? '' : 'none';
  });
  document.querySelectorAll('#choix .fam-titre').forEach(t => { t.style.display = (!f || t.dataset.fam === f) ? '' : 'none'; t.querySelector('.chev').textContent = (actif || !(plies[t.dataset.fam] ?? true)) ? '▾' : '▸'; });
}
filtrer();
<?php if (isset($_GET['print'])): ?>window.print();<?php endif; ?>
</script>
<?php page_end(); ?>
