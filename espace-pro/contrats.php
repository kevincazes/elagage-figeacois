<?php
// Contrats d'entretien : création à partir d'un modèle, envoi pour signature en ligne, interventions récurrentes au planning
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
if (planning_niveau() !== 'tout') { http_response_code(403); page_start('Accès réservé', 'contrats.php'); echo '<p class="lead">Les contrats sont gérés par l\'administration et le secrétariat.</p>'; page_end(); exit; }
const STATUTS_CONTRAT = ['brouillon' => 'Brouillon', 'envoye' => 'Envoyé – en attente de signature', 'signe' => 'Signé – en cours', 'resilie' => 'Résilié', 'termine' => 'Terminé'];
$id = (int)($_GET['id'] ?? 0);
if ($id && isset($_GET['pdf'])) { $c = q("SELECT * FROM contrats WHERE id = ?", [$id])->fetch(); if (!$c) exit; header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Contrat-' . $c['numero'] . '.pdf"'); echo contrat_pdf($c); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'enregistrer' || $a === 'envoyer') {
        $cid = (int)($_POST['id'] ?? 0); $ex = $cid ? q("SELECT * FROM contrats WHERE id = ?", [$cid])->fetch() : null;
        if ($ex && in_array($ex['statut'], ['signe', 'resilie', 'termine'], true) && $a !== 'enregistrer') redirect("contrats.php?id=$cid");
        $mois = implode(',', array_filter(array_map('intval', (array)($_POST['mois'] ?? [])), fn($m) => $m >= 1 && $m <= 12));
        $v = ['client_nom' => trim($_POST['client_nom'] ?? ''), 'client_email' => trim($_POST['client_email'] ?? ''), 'client_tel' => trim($_POST['client_tel'] ?? ''), 'adresse' => trim($_POST['adresse'] ?? ''), 'cp' => trim($_POST['cp'] ?? ''), 'ville' => trim($_POST['ville'] ?? ''),
              'lat' => is_numeric($_POST['lat'] ?? '') ? (float)$_POST['lat'] : null, 'lng' => is_numeric($_POST['lng'] ?? '') ? (float)$_POST['lng'] : null, 'objet' => trim($_POST['objet'] ?? ''),
              'montant_ht' => ($_POST['montant_ht'] ?? '') === '' ? null : nb($_POST['montant_ht']), 'tva' => nb($_POST['tva'] ?? 20), 'mois' => $mois, 'duree_h' => ($_POST['duree_h'] ?? '') === '' ? null : nb($_POST['duree_h']),
              'nb_jours' => ($_POST['nb_jours'] ?? '') === '' ? null : nb($_POST['nb_jours']), 'equipe_id' => ($_POST['equipe_id'] ?? '') !== '' ? (int)$_POST['equipe_id'] : null, 'date_debut' => ($_POST['date_debut'] ?? '') !== '' && strtotime($_POST['date_debut']) ? date('Y-m-d', strtotime($_POST['date_debut'])) : null,
              'duree_mois' => max(1, (int)($_POST['duree_mois'] ?? 12)), 'tacite' => !empty($_POST['tacite']) ? 1 : 0, 'modele_id' => ($_POST['modele_id'] ?? '') !== '' ? (int)$_POST['modele_id'] : null, 'notes' => trim($_POST['notes'] ?? '')];
        if ($v['client_nom'] === '') { flash('Indique le client.', 'err'); redirect($cid ? "contrats.php?id=$cid" : 'contrats.php?nouveau=1'); }
        $v['client_id'] = client_assurer(['client_nom' => $v['client_nom'], 'client_tel' => $v['client_tel'], 'client_email' => $v['client_email'], 'adresse' => $v['adresse'], 'cp' => $v['cp'], 'ville' => $v['ville'], 'lat' => $v['lat'], 'lng' => $v['lng'], 'client_id' => (int)($_POST['client_id'] ?? 0), 'type' => 'chantier']);
        if ($ex && $ex['statut'] === 'signe') { // contrat signé : seuls l'organisation (équipe, durée d'intervention) et les notes changent
            q("UPDATE contrats SET duree_h = ?, nb_jours = ?, equipe_id = ?, notes = ? WHERE id = ?", [$v['duree_h'], $v['nb_jours'], $v['equipe_id'], $v['notes'], $cid]);
        } elseif ($ex) { q("UPDATE contrats SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ", statut = 'brouillon', texte_final = NULL WHERE id = ?", array_merge(array_values($v), [$cid])); }
        else {
            $an = date('Y'); $n = (int)q("SELECT COUNT(*) c FROM contrats WHERE numero LIKE ?", ["CE-$an-%"])->fetch()['c'] + 1;
            q("INSERT INTO contrats (" . implode(', ', array_keys($v)) . ", numero, cree_par) VALUES (" . implode(',', array_fill(0, count($v) + 2, '?')) . ")", array_merge(array_values($v), [sprintf('CE-%s-%03d', $an, $n), user()['id']]));
            $cid = (int)db()->lastInsertId();
        }
        if ($a === 'envoyer') { $m = contrat_envoyer($cid); flash($m === '' ? 'Contrat envoyé au client pour signature.' : $m, $m === '' ? 'ok' : 'err'); }
        else flash('Contrat enregistré.');
        redirect("contrats.php?id=$cid");
    }
    if ($a === 'statut') { $cid = (int)$_POST['id']; $s = $_POST['statut'] ?? ''; if (in_array($s, ['resilie', 'termine', 'signe'], true)) {
        if ($s === 'signe') q("UPDATE contrats SET statut = 'signe', signe_le = COALESCE(signe_le, NOW()), signataire = IF(signataire = '', 'Signé sur papier', signataire) WHERE id = ?", [$cid]);
        else q("UPDATE contrats SET statut = ? WHERE id = ?", [$s, $cid]);
        if ($s !== 'signe') q("UPDATE evenements SET statut = 'annule' WHERE contrat_id = ? AND statut = 'a_planifier'", [$cid]); else contrats_generer();
        journal('contrats', "Contrat n° $cid : " . STATUTS_CONTRAT[$s]); flash('Statut mis à jour.'); } redirect("contrats.php?id=$cid"); }
    if ($a === 'modele') {
        $mid = (int)($_POST['mid'] ?? 0); $nom = trim($_POST['nom'] ?? ''); $txt = (string)($_POST['texte'] ?? '');
        if ($nom !== '' && trim($txt) !== '') { if ($mid) q("UPDATE contrat_modeles SET nom = ?, texte = ? WHERE id = ?", [$nom, $txt, $mid]); else q("INSERT INTO contrat_modeles (nom, texte) VALUES (?,?)", [$nom, $txt]); flash('Modèle enregistré.'); }
        redirect('contrats.php?modeles=1');
    }
    if ($a === 'generer') { $n = contrats_generer(); flash("$n intervention(s) ajoutée(s) dans « À planifier »."); redirect('contrats.php'); }
    redirect('contrats.php');
}
$eqs = equipes_liste(); $modeles = q("SELECT * FROM contrat_modeles WHERE actif = 1 ORDER BY id")->fetchAll();

if (!empty($_GET['modeles'])) {
    page_start('Modèles de contrat', 'contrats.php', ['contrats.php', 'Contrats']);
    echo '<p class="lead">Le texte du contrat, avec des champs remplis automatiquement : ' . implode(' ', array_map(fn($k) => '<code>{{' . $k . '}}</code>', array_keys(contrat_variables(['numero' => '', 'client_nom' => '', 'adresse' => '', 'cp' => '', 'ville' => '', 'objet' => '', 'mois' => '', 'montant_ht' => 0, 'tva' => 20, 'date_debut' => null, 'duree_mois' => 12, 'tacite' => 1])))) . '.</p>';
    echo '<p class="muted small">Ce modèle est une base de travail : fais-le relire par ton conseil (avocat, expert-comptable, fédération) avant de l\'utiliser.</p>';
    foreach (array_merge($modeles, [['id' => 0, 'nom' => '', 'texte' => '']]) as $m): ?>
      <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="modele"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
        <label>Nom du modèle <input name="nom" value="<?= e($m['nom']) ?>" placeholder="Nouveau modèle…"></label><label>Texte <textarea name="texte" rows="<?= $m['id'] ? 22 : 4 ?>" style="font-family:ui-monospace,monospace;font-size:.85rem"><?= e($m['texte']) ?></textarea></label>
        <button class="btn"><?= $m['id'] ? 'Enregistrer' : 'Créer le modèle' ?></button></form>
    <?php endforeach; page_end(); exit;
}
if (!$id && empty($_GET['nouveau'])) {
    $l = q("SELECT * FROM contrats ORDER BY FIELD(statut,'envoye','brouillon','signe','resilie','termine'), cree_le DESC")->fetchAll();
    page_start("Contrats d'entretien", 'contrats.php');
    $ca = array_sum(array_map(fn($c) => $c['statut'] === 'signe' ? (float)$c['montant_ht'] : 0, $l)); ?>
    <p class="lead">Contrats annuels : le client signe en ligne, puis les passages prévus (ex. taille en mars et en octobre) arrivent tout seuls dans « À planifier » environ deux mois avant.</p>
    <p><a class="btn" href="contrats.php?nouveau=1">+ Nouveau contrat</a> <a class="btn secondary" href="contrats.php?modeles=1">Modèles de contrat</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="generer"><button class="mini">Générer les interventions maintenant</button></form></p>
    <p class="muted"><?= count(array_filter($l, fn($c) => $c['statut'] === 'signe')) ?> contrat(s) en cours · <?= money($ca) ?> HT par an.</p>
    <div class="scroll"><table class="tbl"><tr><th>N°</th><th>Client</th><th>Passages</th><th>Montant HT / an</th><th>Statut</th><th>Échéance</th></tr>
    <?php foreach ($l as $c): ?><tr><td><a href="?id=<?= $c['id'] ?>"><strong><?= e($c['numero']) ?></strong></a></td><td><?= e($c['client_nom']) ?><br><span class="small"><?= e($c['ville']) ?></span></td>
      <td><?= e(implode(', ', array_map(fn($m) => MOIS_FR[$m], contrat_mois($c)))) ?></td><td><?= $c['montant_ht'] !== null ? money((float)$c['montant_ht']) : '' ?></td><td><?= e(STATUTS_CONTRAT[$c['statut']] ?? $c['statut']) ?></td><td><?= ($f = contrat_fin($c)) ? date('d/m/Y', strtotime($f)) . ((int)$c['tacite'] ? ' (tacite)' : '') : '' ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php page_end(); exit;
}
$c = $id ? q("SELECT * FROM contrats WHERE id = ?", [$id])->fetch() : null;
if ($id && !$c) redirect('contrats.php');
if (!$c) { $c = ['id' => 0, 'numero' => '', 'statut' => 'brouillon', 'client_id' => null, 'client_nom' => '', 'client_email' => '', 'client_tel' => '', 'adresse' => '', 'cp' => '', 'ville' => '', 'lat' => null, 'lng' => null, 'objet' => '', 'montant_ht' => null, 'tva' => 20, 'mois' => '3,10', 'duree_h' => null, 'nb_jours' => 1, 'equipe_id' => null, 'date_debut' => date('Y-m-d'), 'duree_mois' => 12, 'tacite' => 1, 'modele_id' => $modeles[0]['id'] ?? null, 'notes' => '', 'signe_le' => null, 'signataire' => '', 'envoye_le' => null, 'empreinte' => ''];
    if (!empty($_GET['client']) && ($cl = q("SELECT * FROM clients WHERE id = ?", [(int)$_GET['client']])->fetch())) { $ad = q("SELECT * FROM client_adresses WHERE client_id = ? ORDER BY ordre, id LIMIT 1", [$cl['id']])->fetch() ?: [];
        $c = array_merge($c, ['client_id' => $cl['id'], 'client_nom' => $cl['nom'], 'client_email' => $cl['email'], 'client_tel' => $cl['mobile'] ?: $cl['telephone']], array_intersect_key($ad, array_flip(['adresse', 'cp', 'ville', 'lat', 'lng']))); } }
$fige = in_array($c['statut'], ['signe', 'resilie', 'termine'], true);
$evs = $id ? q("SELECT * FROM evenements WHERE contrat_id = ? ORDER BY COALESCE(debut, cree_le)", [$id])->fetchAll() : [];
page_start($id ? 'Contrat ' . $c['numero'] : 'Nouveau contrat', 'contrats.php', ['contrats.php', 'Contrats']);
?>
<?php if ($id): ?><p><strong><?= e(STATUTS_CONTRAT[$c['statut']] ?? '') ?></strong><?= $c['envoye_le'] ? ' · envoyé le ' . date('d/m/Y', strtotime($c['envoye_le'])) : '' ?><?= $c['signe_le'] ? ' · signé le ' . date('d/m/Y à H:i', strtotime($c['signe_le'])) . ' par ' . e($c['signataire']) : '' ?>
  · <a href="?id=<?= $id ?>&pdf=1" target="_blank">PDF du contrat</a><?= $c['client_id'] ? ' · <a href="clients.php?id=' . (int)$c['client_id'] . '">Fiche client</a>' : '' ?></p><?php endif; ?>
<?php if ($fige): ?><p class="flash">Contrat signé : le texte, le prix et les périodes ne sont plus modifiables (seules l'équipe, la durée d'intervention et les notes le sont). Pour changer le contrat, fais-en un nouveau.</p><?php endif; ?>
<form method="post" class="card" id="fc"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="client_id" value="<?= (int)$c['client_id'] ?>">
  <fieldset <?= $fige ? 'disabled' : '' ?> style="border:0;padding:0;margin:0">
  <h3 style="margin-top:0">Client</h3>
  <div class="cols"><label>Nom <input name="client_nom" value="<?= e($c['client_nom']) ?>" required></label><label>E-mail (pour la signature) <input type="email" name="client_email" value="<?= e($c['client_email']) ?>"></label><label>Téléphone <input name="client_tel" value="<?= e($c['client_tel']) ?>"></label></div>
  <div class="cols"><label class="ac">Adresse du site <input name="adresse" id="cAdr" value="<?= e($c['adresse']) ?>"><ul hidden id="cAdrL"></ul></label><label>Code postal <input name="cp" value="<?= e($c['cp']) ?>"></label><label>Ville <input name="ville" value="<?= e($c['ville']) ?>"></label></div>
  <input type="hidden" name="lat" value="<?= e((string)$c['lat']) ?>"><input type="hidden" name="lng" value="<?= e((string)$c['lng']) ?>">
  <h3>Prestations et prix</h3>
  <label>Prestations (une par ligne) <textarea name="objet" rows="4" placeholder="- Taille d'entretien des haies (80 ml)&#10;- Taille douce de 4 tilleuls&#10;- Ramassage et évacuation des déchets verts"><?= e($c['objet']) ?></textarea></label>
  <div class="cols"><label>Montant annuel HT (€) <input name="montant_ht" inputmode="decimal" value="<?= e((string)$c['montant_ht']) ?>"></label><label>TVA (%) <input name="tva" value="<?= e((string)$c['tva']) ?>"></label><label>Modèle <select name="modele_id"><?php foreach ($modeles as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$c['modele_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nom']) ?></option><?php endforeach; ?></select></label></div>
  <p style="margin:8px 0 4px;font-weight:700">Mois des passages</p>
  <div style="display:flex;flex-wrap:wrap;gap:6px"><?php $mm = contrat_mois($c); for ($m = 1; $m <= 12; $m++): ?><label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:700;border:1px solid #d5dccd;border-radius:999px;padding:4px 10px;background:#fff"><input type="checkbox" name="mois[]" value="<?= $m ?>" style="width:auto;margin:0" <?= in_array($m, $mm, true) ? 'checked' : '' ?>><?= MOIS_FR[$m] ?></label><?php endfor; ?></div>
  <div class="cols"><label>Début du contrat <input type="date" name="date_debut" value="<?= e((string)$c['date_debut']) ?>"></label><label>Durée (mois) <input name="duree_mois" value="<?= (int)$c['duree_mois'] ?>"></label><label style="display:flex;gap:8px;align-items:center;font-weight:700;margin-top:24px"><input type="checkbox" name="tacite" value="1" style="width:auto;margin:0" <?= (int)$c['tacite'] ? 'checked' : '' ?>> Reconduction tacite</label></div>
  </fieldset>
  <h3>Organisation des passages</h3>
  <div class="cols"><label>Équipe habituelle <select name="equipe_id"><option value="">—</option><?php foreach ($eqs as $q): ?><option value="<?= $q['id'] ?>" <?= (int)$c['equipe_id'] === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['nom']) ?></option><?php endforeach; ?></select></label><label>Durée d'un passage (h) <input name="duree_h" value="<?= e((string)$c['duree_h']) ?>"></label><label>Nombre de jours <input name="nb_jours" value="<?= e((string)$c['nb_jours']) ?>"></label></div>
  <label>Notes internes <input name="notes" value="<?= e((string)$c['notes']) ?>"></label>
  <p><button class="btn" name="action" value="enregistrer">Enregistrer</button>
  <?php if (!$fige): ?><button class="btn secondary" name="action" value="envoyer" title="Enregistre, puis envoie au client un e-mail avec le PDF et un lien pour signer en ligne">Envoyer au client pour signature</button><?php endif; ?></p>
</form>
<?php if ($id): ?>
  <form method="post" class="cols" style="align-items:end;max-width:640px"><?= csrf_field() ?><input type="hidden" name="action" value="statut"><input type="hidden" name="id" value="<?= $id ?>">
    <label>Changer le statut <select name="statut"><?php if ($c['statut'] !== 'signe'): ?><option value="signe">Signé sur papier</option><?php endif; ?><option value="resilie">Résilié</option><option value="termine">Terminé</option></select></label><button class="mini" onclick="return confirm('Changer le statut du contrat ?')">Appliquer</button></form>
  <h3>Passages au planning</h3>
  <?php if (!$evs): ?><p class="muted">Aucun pour l'instant : ils sont créés dans « À planifier » environ deux mois avant chaque période, une fois le contrat signé.</p><?php endif; ?>
  <ul><?php foreach ($evs as $e): ?><li><a href="planning.php?fiche=<?= $e['id'] ?>"><?= e($e['titre']) ?></a> – <?= e(STATUTS_EVT[$e['statut']] ?? '') ?><?= $e['debut'] ? ' – ' . e(evt_quand($e)) : '' ?></li><?php endforeach; ?></ul>
  <h3>Aperçu du texte</h3><pre style="white-space:pre-wrap;background:#fff;border:1px solid #e1e5da;border-radius:10px;padding:14px;font-family:inherit;font-size:.9rem"><?= e($c['texte_final'] ?? '' ?: contrat_texte($c)) ?></pre>
<?php endif; ?>
<script>
(function(){ const a = document.getElementById('cAdr'), l = document.getElementById('cAdrL'), f = document.getElementById('fc'); let t, r = [];
  a.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => { if (a.value.length < 4) return; try { r = (await (await fetch('https://api-adresse.data.gouv.fr/search/?limit=5&lat=44.6&lon=1.95&q=' + encodeURIComponent(a.value))).json()).features || []; l.innerHTML = r.map((x, i) => `<li data-i="${i}">${x.properties.label.replace(/</g, '&lt;')}</li>`).join(''); l.hidden = !r.length; } catch (e) {} }, 250); });
  l.addEventListener('mousedown', ev => { const li = ev.target.closest('li'); if (!li) return; ev.preventDefault(); const x = r[+li.dataset.i]; a.value = x.properties.name; f.cp.value = x.properties.postcode || ''; f.ville.value = x.properties.city || ''; f.lat.value = x.geometry.coordinates[1]; f.lng.value = x.geometry.coordinates[0]; l.hidden = true; });
  a.addEventListener('blur', () => setTimeout(() => l.hidden = true, 150)); })();
</script>
<?php page_end(); ?>
