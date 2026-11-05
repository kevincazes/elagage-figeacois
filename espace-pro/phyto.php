<?php
// Chantiers platanes : registre de traçabilité (modèle DRAAF), export Excel, passeport phytosanitaire (écran et PDF), réglages
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$gere = planning_niveau() === 'tout';
$bureau = in_array(user()['role'] ?? '', ['admin', 'secretaire'], true); // réglages : administration et secrétariat seulement
// ---- Passeport d'un chantier (écran plein ou PDF) : accessible aux participants
if (!empty($_GET['passeport'])) {
    $e = evt_charger((int)$_GET['passeport']); if (!$e || !(int)$e['phyto']) { http_response_code(404); page_start('Passeport', 'planning.php'); echo '<p class="lead">Ce chantier n\'est pas un chantier platane : coche « Chantier sur platane » dans sa fiche.</p>'; page_end(); exit; }
    if (!phyto_verrouille((int)$e['id']) && (int)phyto_charger((int)$e['id'])['transport']) phyto_numero((int)$e['id']);
    $p = phyto_charger((int)$e['id']); $pp = phyto_passeport($e, $p['code_c']);
    if (isset($_GET['pdf'])) { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Passeport-' . $pp['C'] . '.pdf"'); echo phyto_pdf($e, $p['code_c']); exit; }
    page_start('Passeport phytosanitaire', 'mon-planning.php', ['mon-planning.php?jour=' . substr((string)$e['debut'], 0, 10), 'Mon planning']);
    ?>
    <style>main>h1{position:absolute;left:-9999px}.pp{max-width:560px;margin:0 auto;background:#fff;border:3px solid #222;border-radius:14px;padding:22px 20px;color:#111}.pp .t{display:flex;gap:16px;align-items:center}.pp .t img{width:90px;border:1px solid #ccc}.pp .t b{font-size:1.25rem;display:block}.pp .t span{color:#444}
    .pp dl{display:grid;grid-template-columns:40px 1fr;gap:10px 8px;margin:22px 0 0;font-size:1.5rem}.pp dt{font-weight:900}.pp dd{margin:0;word-break:break-all}.pp .c dd{font-weight:900;color:#683c11}.pp small{display:block;margin-top:18px;color:#666;font-size:.78rem}
    .act{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin:14px 0}</style>
    <?php if (!(int)$p['transport']): ?><p class="flash err">Ce chantier est noté « bois non transporté » : le passeport ne s'applique pas.</p><?php endif; ?>
    <?php if (phyto_zone_delimitee($e['ville'], $e['cp'])): ?><p class="flash err">Commune en zone délimitée : le bois de platane ne circule pas avec un passeport simple (incinération sur place ou dérogation préfectorale).</p><?php endif; ?>
    <?php if ($pp['num']): ?><p class="muted small" style="text-align:center;margin:0 0 8px">Passeport n° <strong><?= e($pp['num']) ?></strong><?= $e['devis_ref'] ? ' · devis ' . e($e['devis_ref']) : '' ?><?= $p['verrou_le'] ? ' · 🔒 verrouillé le ' . date('d/m/Y', strtotime($p['verrou_le'])) : '' ?></p><?php endif; ?>
    <div class="pp"><div class="t"><img src="phyto-ue.jpg" alt="Union européenne"><div><b>Passeport Phytosanitaire</b><span>Plant Passport</span></div></div>
      <dl><dt>A</dt><dd><?= e($pp['A']) ?></dd><dt>B</dt><dd><?= e($pp['B']) ?></dd><div class="c" style="display:contents"><dt>C</dt><dd><?= e($pp['C']) ?></dd></div><dt>D</dt><dd><?= e($pp['D']) ?></dd></dl>
      <small><?= e(setting('ent_nom')) ?> · <?= e($e['titre']) ?> · <?= $e['debut'] ? e(date_fr($e['debut'], false)) : '' ?><?= $e['client_nom'] ? ' · ' . e($e['client_nom']) : '' ?></small></div>
    <div class="act"><a class="btn" href="phyto.php?passeport=<?= (int)$e['id'] ?>&pdf=1" target="_blank">PDF à imprimer</a><a class="btn secondary" href="planning.php?fiche=<?= (int)$e['id'] ?>">Fiche chantier</a></div>
    <p class="muted small" style="text-align:center">A : bois transporté · B : notre numéro d'enregistrement professionnel · C : traçabilité du chantier (numéro de devis) · D : pays d'origine. Cet écran reste lisible sans réseau une fois ouvert.</p>
    <?php page_end(); exit;
}
if (!$gere) { http_response_code(403); page_start('Accès réservé', 'planning.php'); echo '<p class="lead">Le registre des chantiers platanes est tenu par l\'administration et le secrétariat.</p>'; page_end(); exit; }
$an = (int)($_GET['an'] ?? date('Y'));
$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['du'] ?? '')) ? $_GET['du'] : "$an-01-01"; $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['au'] ?? '')) ? $_GET['au'] : "$an-12-31";
$client = trim((string)($_GET['client'] ?? ''));
$colsDef = phyto_colonnes(); $colsChoisies = array_values(array_filter(explode(',', setting('phyto_export_cols') ?: implode(',', PHYTO_COLS_DRAAF)), fn($c) => isset($colsDef[$c])));
if (isset($_GET['export'])) {
    $cols = isset($_GET['cols']) ? array_values(array_intersect((array)$_GET['cols'], array_keys($colsDef))) : $colsChoisies;
    if (!empty($_GET['memoriser'])) set_setting('phyto_export_cols', implode(',', $cols));
    [$nomF, $type, $data] = phyto_export($du, $au, $cols, in_array($_GET['format'] ?? '', ['csv', 'pdf'], true) ? $_GET['format'] : 'xlsx', $client);
    header("Content-Type: $type"); header("Content-Disposition: attachment; filename=\"$nomF\""); echo $data; exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'ligne') { // chantier platane saisi ou corrigé à la main (travaux ajoutés hors devis, etc.)
        $id = (int)($_POST['id'] ?? 0); $dt = fn($v) => ($v = trim((string)$v)) !== '' && ($t = strtotime($v)) ? date('Y-m-d', $t) : null;
        $deb = $dt($_POST['debut'] ?? '') ?: date('Y-m-d'); $fin = $dt($_POST['fin'] ?? '') ?: $deb; if ($fin < $deb) $fin = $deb;
        $r = ['type' => 'chantier', 'titre' => mb_substr(trim($_POST['titre'] ?? ''), 0, 190) ?: ('Platanes – ' . trim($_POST['client_nom'] ?? '')), 'client_nom' => mb_substr(trim($_POST['client_nom'] ?? ''), 0, 190), 'client_tel' => mb_substr(trim($_POST['client_tel'] ?? ''), 0, 30), 'client_email' => filter_var(trim($_POST['client_email'] ?? ''), FILTER_VALIDATE_EMAIL) ? trim($_POST['client_email']) : '',
              'adresse' => mb_substr(trim($_POST['adresse'] ?? ''), 0, 190), 'cp' => mb_substr(trim($_POST['cp'] ?? ''), 0, 10), 'ville' => mb_substr(trim($_POST['ville'] ?? ''), 0, 100), 'devis_ref' => mb_substr(trim($_POST['devis_ref'] ?? ''), 0, 60),
              'debut' => "$deb 08:00:00", 'fin' => date('Y-m-d 00:00:00', strtotime("$fin +1 day")), 'journee' => 1, 'statut' => in_array($_POST['statut'] ?? '', ['confirme', 'termine'], true) ? $_POST['statut'] : 'termine'];
        if ($r['client_nom'] === '' && trim($_POST['proprietaire'] ?? '') === '') { flash('Indique le propriétaire des arbres.', 'err'); redirect("phyto.php?modifier=$id"); }
        if ($id) { $e = evt_charger($id); if (!$e || !(int)$e['phyto']) { flash('Chantier introuvable.', 'err'); redirect('phyto.php'); }
            if (phyto_verrouille($id) && empty($_POST['deverrouille'])) { flash('Fiche verrouillée : clique d\'abord « Déverrouiller pour corriger ».', 'err'); redirect("phyto.php?modifier=$id"); }
            if ((int)($e['manuel'] ?? 0) || !empty($_POST['maj_chantier'])) q("UPDATE evenements SET titre = ?, client_nom = ?, client_tel = ?, client_email = ?, adresse = ?, cp = ?, ville = ?, devis_ref = ?, debut = ?, fin = ?, statut = ? WHERE id = ?", [$r['titre'], $r['client_nom'], $r['client_tel'], $r['client_email'], $r['adresse'], $r['cp'], $r['ville'], $r['devis_ref'], $r['debut'], $r['fin'], $r['statut'], $id]);
            phyto_enregistrer($id, $_POST, true); evt_journal($id, 'Fiche platane corrigée depuis le registre');
            if ($r['statut'] === 'termine' || !empty($_POST['reverrouiller'])) phyto_verrouiller($id);
            flash('Ligne enregistrée.' . (phyto_verrouille($id) ? ' Fiche verrouillée avec son passeport.' : ''));
        } else {
            q("INSERT INTO evenements (type, titre, statut, debut, fin, journee, client_nom, client_tel, client_email, adresse, cp, ville, devis_ref, phyto, cree_par) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)", [$r['type'], $r['titre'], $r['statut'], $r['debut'], $r['fin'], 1, $r['client_nom'], $r['client_tel'], $r['client_email'], $r['adresse'], $r['cp'], $r['ville'], $r['devis_ref'], user()['id']]);
            $id = (int)db()->lastInsertId(); try { $cid = client_assurer($r); if ($cid) q("UPDATE evenements SET client_id = ? WHERE id = ?", [$cid, $id]); } catch (Throwable $t) {}
            foreach (array_map('intval', (array)($_POST['personnel'] ?? [])) as $pp) if ($pp) q("INSERT IGNORE INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$id, $pp]);
            phyto_enregistrer($id, $_POST, true); q("UPDATE phyto_chantiers SET manuel = 1 WHERE evenement_id = ?", [$id]); evt_journal($id, 'Chantier platane saisi à la main dans le registre');
            if ($r['statut'] === 'termine') { q("UPDATE evenements SET termine_le = NOW() WHERE id = ?", [$id]); phyto_verrouiller($id); }
            flash('Chantier platane ajouté au registre' . (phyto_verrouille($id) ? ' et verrouillé avec son passeport n° ' . (q("SELECT passeport_num FROM phyto_chantiers WHERE evenement_id = ?", [$id])->fetch()['passeport_num'] ?? '') : '') . '.');
        }
        redirect("phyto.php?an=" . substr($deb, 0, 4));
    }
    if ($a === 'deverrouiller' && $bureau) { $id = (int)($_POST['id'] ?? 0); if ($id && phyto_verrouille($id)) { phyto_deverrouiller($id); flash('Fiche déverrouillée : corrige puis enregistre (elle se reverrouille si le chantier est terminé).'); } redirect("phyto.php?modifier=$id"); }
    if (($_POST['action'] ?? '') === 'reglages' && $bureau) { set_setting('phyto_inupp', trim($_POST['phyto_inupp'] ?? '')); set_setting('phyto_produit', trim($_POST['phyto_produit'] ?? '')); set_setting('phyto_zones', trim($_POST['phyto_zones'] ?? '')); set_setting('phyto_responsables', trim($_POST['phyto_responsables'] ?? '')); flash('Réglages enregistrés.'); }
    redirect("phyto.php?an=$an");
}
if (isset($_GET['modifier'])) { // ---- Saisie / correction d'une ligne à la main
    $id = (int)$_GET['modifier']; $e = $id ? evt_charger($id) : null; if ($id && (!$e || !(int)$e['phyto'])) { flash('Chantier introuvable.', 'err'); redirect('phyto.php'); }
    $ph = $id ? phyto_charger($id) : phyto_charger(0); $verrou = $id && phyto_verrouille($id); $pers = personnel_liste();
    page_start($id ? 'Corriger un chantier platane' : 'Ajouter un chantier platane à la main', 'planning.php', ['phyto.php', 'Chantiers platanes']);
    $v = fn($k, $d = '') => e((string)($e[$k] ?? $d)); $pv = fn($k) => e((string)($ph[$k] ?? ''));
    ?>
    <p class="lead"><?= $id ? 'Corrige la ligne du registre (propriétaire, travaux réellement réalisés, bois évacué…).' : 'Pour les travaux sur platane non prévus au devis (arbres ajoutés sur place, évacuation imprévue…) : la ligne est créée dans le registre et dans le planning, avec son passeport.' ?><?= $verrou ? ' <strong>🔒 Cette fiche est verrouillée</strong> (chantier terminé) : déverrouille-la pour corriger.' : '' ?></p>
    <?php if ($verrou && $bureau): ?><form method="post" style="margin-bottom:10px"><?= csrf_field() ?><input type="hidden" name="action" value="deverrouiller"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn secondary">🔓 Déverrouiller pour corriger</button></form><?php endif; ?>
    <form method="post" class="card" <?= $verrou ? 'style="opacity:.6;pointer-events:none"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="ligne"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="maj_chantier" value="1">
      <h2 style="margin-top:0">Chantier</h2>
      <div class="cols"><label>Propriétaire des arbres (nom / société) <input name="client_nom" value="<?= $v('client_nom') ?>" required></label><label>Titre du chantier <input name="titre" value="<?= $v('titre') ?>" placeholder="Ex. Abattage 2 platanes – mairie de X"></label></div>
      <div class="cols"><label>Téléphone <input name="client_tel" value="<?= $v('client_tel') ?>"></label><label>E-mail <input name="client_email" value="<?= $v('client_email') ?>"></label></div>
      <div class="cols"><label>Adresse du chantier <input name="adresse" value="<?= $v('adresse') ?>"></label><label>Code postal <input name="cp" value="<?= $v('cp') ?>" style="max-width:120px"></label><label>Commune <input name="ville" value="<?= $v('ville') ?>"></label></div>
      <div class="cols"><label>Du <input type="date" name="debut" value="<?= $e && $e['debut'] ? substr($e['debut'], 0, 10) : date('Y-m-d') ?>"></label><label>Au <input type="date" name="fin" value="<?= $e && $e['fin'] ? date('Y-m-d', strtotime($e['fin']) - 1) : date('Y-m-d') ?>"></label><label>Statut <select name="statut"><option value="termine" <?= !$e || $e['statut'] === 'termine' ? 'selected' : '' ?>>Terminé (verrouille la fiche avec le passeport)</option><option value="confirme" <?= $e && $e['statut'] !== 'termine' ? 'selected' : '' ?>>En cours / prévu</option></select></label></div>
      <div class="cols"><label>N° de devis (code de traçabilité C) <input name="devis_ref" value="<?= $v('devis_ref') ?>" placeholder="laisser vide si hors devis"></label><label>Code C forcé (si pas de devis) <input name="code_c" value="<?= $pv('code_c') ?>" placeholder="Ex. HD-2026-03"></label></div>
      <?php if (!$id): ?><label>Intervenants <div class="chips" style="display:flex;flex-wrap:wrap;gap:6px"><?php foreach ($pers as $pp): ?><label style="display:inline-flex;gap:4px;align-items:center;margin:0;border:1px solid #d5dccd;border-radius:16px;padding:3px 9px;font-weight:400"><input type="checkbox" name="personnel[]" value="<?= $pp['id'] ?>" style="width:auto;margin:0"><?= e($pp['prenom']) ?></label><?php endforeach; ?></div></label><?php endif; ?>
      <h2>Registre DRAAF</h2>
      <p class="small muted" style="margin:0 0 6px">Les trois champs suivants remplacent, s'ils sont remplis, les informations du client et de l'adresse ci-dessus dans le registre (propriétaire différent du donneur d'ordre, parcelle…).</p>
      <div class="cols"><label>Propriétaire (si différent) <input name="proprietaire" value="<?= $pv('proprietaire') ?>"></label><label>Coordonnées du propriétaire <input name="coordonnees" value="<?= $pv('coordonnees') ?>"></label><label>Localisation (adresse / GPS / parcelle) <input name="localisation" value="<?= $pv('localisation') ?>"></label></div>
      <div class="cols"><label>Type de travaux <input name="travaux" value="<?= $pv('travaux') ?>" placeholder="abattage, élagage, ramassage bois mort…"></label><label>Nombre de platanes <input name="nb_arbres" type="number" min="0" value="<?= $pv('nb_arbres') ?>"></label></div>
      <div class="cols"><label>Surveillance avant chantier <select name="surv_avant"><?php foreach (PHYTO_SURV as $k => $lb): ?><option value="<?= $k ?>" <?= ($ph['surv_avant'] ?? '') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></label><label>Surveillance pendant <select name="surv_pendant"><?php foreach (PHYTO_SURV as $k => $lb): ?><option value="<?= $k ?>" <?= ($ph['surv_pendant'] ?? '') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></label></div>
      <div class="cols"><label>Destination du bois <input name="destination" value="<?= $pv('destination') ?>"></label><label>Usage du bois <select name="usage_bois"><?php foreach (PHYTO_USAGES as $k => $lb): ?><option value="<?= $k ?>" <?= ($ph['usage_bois'] ?? '') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></label><label>Volume (m³) <input name="volume_m3" inputmode="decimal" value="<?= $pv('volume_m3') ?>"></label></div>
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin:6px 0"><label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="transport" value="1" style="width:auto;margin:0" <?= !isset($ph['transport']) || (int)$ph['transport'] ? 'checked' : '' ?>>Bois / rémanents transportés (passeport)</label><label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="desinf_entree" value="1" style="width:auto;margin:0" <?= !empty($ph['desinf_entree']) ? 'checked' : '' ?>>Désinfection à l'entrée</label><label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="desinf_sortie" value="1" style="width:auto;margin:0" <?= !empty($ph['desinf_sortie']) ? 'checked' : '' ?>>à la sortie</label><label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="desinf_arbres" value="1" style="width:auto;margin:0" <?= !empty($ph['desinf_arbres']) ? 'checked' : '' ?>>entre chaque arbre</label><label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="griffes" value="1" style="width:auto;margin:0" <?= !empty($ph['griffes']) ? 'checked' : '' ?>>Griffes (démontage)</label></div>
      <input type="hidden" name="transport" value="0" disabled>
      <div class="cols"><label>Produit <input name="produit" value="<?= e((string)($ph['produit'] ?: setting('phyto_produit', 'Sitonal'))) ?>"></label><label>Déclaration DRAAF le (zone délimitée) <input type="date" name="declaration_le" value="<?= $pv('declaration_le') ?>"></label><label>Notes <input name="notes" value="<?= $pv('notes') ?>"></label></div>
      <?php if ($id && !$verrou && $e['statut'] !== 'termine'): ?><label style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" name="reverrouiller" value="1" style="width:auto;margin:0">Verrouiller la fiche avec le passeport après enregistrement</label><?php endif; ?>
      <?php if ($id && $bureau && !$verrou && $ph['verrou_le'] === null && $ph['passeport_num']): ?><input type="hidden" name="deverrouille" value="1"><?php endif; ?>
      <p><button class="btn">Enregistrer</button> <a class="btn secondary" href="phyto.php">Annuler</a><?= $id ? ' <a class="mini" href="planning.php?fiche=' . $id . '">Ouvrir la fiche complète du planning</a>' : '' ?></p>
    </form>
    <?php page_end(); exit;
}
$l = phyto_registre($du, $au, $client);
page_start('Chantiers platanes', 'planning.php', ['planning.php', 'Planning']);
?>
<p class="lead">Registre de traçabilité des interventions sur platane (modèle DRAAF Occitanie, chancre coloré). Chaque chantier coché « platane » dans le planning apparaît ici ; l'export Excel reprend la mise en page du tableau à transmettre.</p>
<form class="card" id="exp"><h2 style="margin-top:0">Période et export pour la DRAAF</h2>
  <div class="cols" style="align-items:end"><label>Du <input type="date" name="du" value="<?= e($du) ?>"></label><label>Au <input type="date" name="au" value="<?= e($au) ?>"></label><label>Propriétaire (filtre) <input name="client" value="<?= e($client) ?>" placeholder="tous"></label><button class="btn secondary">Afficher</button></div>
  <details <?= isset($_GET['cols']) ? 'open' : '' ?>><summary style="cursor:pointer;font-weight:700">Colonnes de l'export (<?= count($colsChoisies) ?> choisies)</summary>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0"><?php foreach ($colsDef as $k => [$t]): ?><label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:400;border:1px solid #d5dccd;border-radius:999px;padding:3px 10px;background:#fff;font-size:.85rem"><input type="checkbox" name="cols[]" value="<?= $k ?>" style="width:auto;margin:0" <?= in_array($k, $colsChoisies, true) ? 'checked' : '' ?>><?= e(strtok($t, "\n")) ?></label><?php endforeach; ?></div>
    <p class="small muted" style="margin:0 0 6px">Par défaut : les 12 colonnes du tableau DRAAF. <button type="button" class="mini" onclick="document.querySelectorAll('#exp [name=\'cols[]\']').forEach(c=>c.checked=<?= json_encode(PHYTO_COLS_DRAAF) ?>.includes(c.value))">Remettre le modèle DRAAF</button> <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:400"><input type="checkbox" name="memoriser" value="1" style="width:auto;margin:0"> mémoriser ce choix</label></p></details>
  <p style="margin:8px 0 0"><button class="btn" name="export" value="1">Exporter en Excel</button> <button class="btn secondary" name="export" value="1" onclick="this.form.format.value='csv'">Exporter en CSV</button> <button class="btn secondary" name="export" value="1" onclick="this.form.format.value='pdf'">Exporter en PDF</button><input type="hidden" name="format" value="xlsx"></p>
</form>
<p><a class="btn" href="phyto.php?modifier=0">+ Ajouter un chantier platane à la main</a> <span class="small muted">(travaux hors devis, arbres ajoutés sur place…)</span></p>
<p class="muted"><?= count($l) ?> chantier(s) platane du <?= date('d/m/Y', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?> · numéro d'enregistrement <strong><?= e(setting('phyto_inupp')) ?></strong>. Manque : <?= count(array_filter($l, fn($e) => !$e['desinf_entree'] || !$e['desinf_sortie'])) ?> désinfection(s) non cochée(s), <?= count(array_filter($l, fn($e) => $e['surv_avant'] === '' || $e['surv_pendant'] === '')) ?> surveillance(s) non renseignée(s).</p>
<div class="scroll"><table class="tbl"><tr><th>Propriétaire</th><th>Coordonnées</th><th>Du</th><th>Au</th><th>Travaux</th><th>Localisation</th><th>Surv. avant</th><th>Surv. pendant</th><th>Code C</th><th>Passeport</th><th>Destination</th><th>Usage</th><th>m³</th><th>Désinf.</th><th></th></tr>
<?php foreach ($l as $e): ?><tr<?= phyto_zone_delimitee($e['ville'], $e['cp']) ? ' style="background:#fdecea"' : '' ?>>
  <td><?= e($e['proprietaire'] ?: $e['client_nom']) ?><?= (int)$e['manuel'] ? ' <span class="small muted">(saisi)</span>' : '' ?></td><td class="small"><?= e($e['coordonnees'] ?: trim($e['client_tel'] . ' ' . $e['client_email'])) ?></td><td><?= $e['debut'] ? date('d/m/Y', strtotime($e['debut'])) : '' ?></td><td><?= $e['fin'] ? date('d/m/Y', strtotime($e['fin']) - 1) : '' ?></td>
  <td><?= e($e['travaux']) ?></td><td class="small"><?= e($e['localisation'] ?: trim($e['adresse'] . ' ' . $e['cp'] . ' ' . $e['ville'])) ?></td><td><?= e(PHYTO_SURV[$e['surv_avant']] ?? '') ?></td><td><?= e(PHYTO_SURV[$e['surv_pendant']] ?? '') ?></td>
  <td><strong><?= e($e['code_c']) ?></strong><?= $e['devis_ref'] ? '<br><span class="small">devis ' . e($e['devis_ref']) . '</span>' : '' ?></td><td><?= e($e['passeport_num']) ?><?= $e['verrou_le'] ? '<br><span class="small">🔒 ' . date('d/m/Y', strtotime($e['verrou_le'])) . '</span>' : '' ?></td><td><?= e($e['destination']) ?></td><td><?= e(PHYTO_USAGES[$e['usage_bois']] ?? '') ?></td><td><?= $e['volume_m3'] !== null ? (float)$e['volume_m3'] : '' ?></td>
  <td><?= $e['desinf_entree'] && $e['desinf_sortie'] ? '✓' : '<span style="color:#e6332a">à cocher</span>' ?></td>
  <td style="white-space:nowrap"><a class="mini" href="phyto.php?modifier=<?= $e['id'] ?>">✎ Corriger</a> <a class="mini" href="planning.php?fiche=<?= $e['id'] ?>">Fiche</a> <a class="mini" href="phyto.php?passeport=<?= $e['id'] ?>" target="_blank">Passeport</a></td></tr><?php endforeach; ?>
<?php if (!$l): ?><tr><td colspan="15" class="muted">Aucun chantier platane cette année. Dans la fiche d'un chantier, coche « Chantier sur platane ».</td></tr><?php endif; ?>
</table></div>
<h2>Rappels réglementaires (arrêté du 31 janvier 2025)</h2>
<ul class="dots"><li>Sur tout le territoire : outils et engins nettoyés puis désinfectés (produit fongicide autorisé) à chaque entrée et sortie de site et pendant le chantier ; pas de griffes ni crampons, sauf abattage par démontage (art. 18).</li>
<li>Élagueur professionnel : enregistrement au registre des opérateurs (n° <?= e(setting('phyto_inupp')) ?>) et passeport phytosanitaire pour le bois et les écorces de platane mis en circulation, y compris vers une déchèterie (art. 21).</li>
<li>En zone délimitée : déclaration préalable à la DRAAF au moins 15 jours ouvrés avant les travaux et désinfection entre chaque platane (art. 19) ; produits d'abattage incinérés sur place sauf dérogation (art. 14).</li>
<li>Suspicion de chancre coloré : stopper le chantier et signaler à la DRAAF — <a href="https://draaf.occitanie.agriculture.gouv.fr/signaler-un-organisme-nuisible-aux-vegetaux-a9380.html" target="_blank" rel="noopener">formulaire de signalement ↗</a> · <a href="https://draaf.occitanie.agriculture.gouv.fr/chancre-colore-du-platane" target="_blank" rel="noopener">carte des zones délimitées ↗</a>.</li></ul>
<?php if ($bureau): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="reglages"><h2 style="margin-top:0">Réglages</h2>
  <div class="cols"><label>Numéro d'enregistrement professionnel (code B) <input name="phyto_inupp" value="<?= e(setting('phyto_inupp')) ?>"></label><label>Produit de désinfection habituel <input name="phyto_produit" value="<?= e(setting('phyto_produit')) ?>"></label><label>Responsable(s) phytosanitaire(s) <input name="phyto_responsables" value="<?= e(setting('phyto_responsables')) ?>" placeholder="Taurand / Cazes Bouchet"></label></div>
  <label>Communes en zone délimitée (une par ligne, nom ou code postal) — annexe de l'arrêté préfectoral <textarea name="phyto_zones" rows="5" placeholder="Carcassonne&#10;Nîmes&#10;… (le Lot, l'Aveyron, le Cantal et la Corrèze ne sont pas concernés par l'arrêté du 24/07/2025)"><?= e(setting('phyto_zones')) ?></textarea></label>
  <button class="btn">Enregistrer</button></form>
<?php endif; ?>
<?php page_end(); ?>
