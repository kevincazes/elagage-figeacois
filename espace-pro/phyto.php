<?php
// Chantiers platanes : registre de traçabilité (modèle DRAAF), export Excel, passeport phytosanitaire (écran et PDF), réglages
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$gere = planning_niveau() === 'tout';
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
    if (($_POST['action'] ?? '') === 'reglages' && est_admin()) { set_setting('phyto_inupp', trim($_POST['phyto_inupp'] ?? '')); set_setting('phyto_produit', trim($_POST['phyto_produit'] ?? '')); set_setting('phyto_zones', trim($_POST['phyto_zones'] ?? '')); set_setting('phyto_responsables', trim($_POST['phyto_responsables'] ?? '')); flash('Réglages enregistrés.'); }
    redirect("phyto.php?an=$an");
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
<p class="muted"><?= count($l) ?> chantier(s) platane du <?= date('d/m/Y', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?> · numéro d'enregistrement <strong><?= e(setting('phyto_inupp')) ?></strong>. Manque : <?= count(array_filter($l, fn($e) => !$e['desinf_entree'] || !$e['desinf_sortie'])) ?> désinfection(s) non cochée(s), <?= count(array_filter($l, fn($e) => $e['surv_avant'] === '' || $e['surv_pendant'] === '')) ?> surveillance(s) non renseignée(s).</p>
<div class="scroll"><table class="tbl"><tr><th>Propriétaire</th><th>Coordonnées</th><th>Du</th><th>Au</th><th>Travaux</th><th>Localisation</th><th>Surv. avant</th><th>Surv. pendant</th><th>Code C</th><th>Passeport</th><th>Destination</th><th>Usage</th><th>m³</th><th>Désinf.</th><th></th></tr>
<?php foreach ($l as $e): ?><tr<?= phyto_zone_delimitee($e['ville'], $e['cp']) ? ' style="background:#fdecea"' : '' ?>>
  <td><?= e($e['client_nom']) ?></td><td class="small"><?= e(trim($e['client_tel'] . ' ' . $e['client_email'])) ?></td><td><?= $e['debut'] ? date('d/m/Y', strtotime($e['debut'])) : '' ?></td><td><?= $e['fin'] ? date('d/m/Y', strtotime($e['fin']) - 1) : '' ?></td>
  <td><?= e($e['travaux']) ?></td><td class="small"><?= e(trim($e['adresse'] . ' ' . $e['cp'] . ' ' . $e['ville'])) ?></td><td><?= e(PHYTO_SURV[$e['surv_avant']] ?? '') ?></td><td><?= e(PHYTO_SURV[$e['surv_pendant']] ?? '') ?></td>
  <td><strong><?= e($e['code_c']) ?></strong><?= $e['devis_ref'] ? '<br><span class="small">devis ' . e($e['devis_ref']) . '</span>' : '' ?></td><td><?= e($e['passeport_num']) ?><?= $e['verrou_le'] ? '<br><span class="small">🔒 ' . date('d/m/Y', strtotime($e['verrou_le'])) . '</span>' : '' ?></td><td><?= e($e['destination']) ?></td><td><?= e(PHYTO_USAGES[$e['usage_bois']] ?? '') ?></td><td><?= $e['volume_m3'] !== null ? (float)$e['volume_m3'] : '' ?></td>
  <td><?= $e['desinf_entree'] && $e['desinf_sortie'] ? '✓' : '<span style="color:#e6332a">à cocher</span>' ?></td>
  <td style="white-space:nowrap"><a class="mini" href="planning.php?fiche=<?= $e['id'] ?>">Fiche</a> <a class="mini" href="phyto.php?passeport=<?= $e['id'] ?>" target="_blank">Passeport</a></td></tr><?php endforeach; ?>
<?php if (!$l): ?><tr><td colspan="15" class="muted">Aucun chantier platane cette année. Dans la fiche d'un chantier, coche « Chantier sur platane ».</td></tr><?php endif; ?>
</table></div>
<h2>Rappels réglementaires (arrêté du 31 janvier 2025)</h2>
<ul class="dots"><li>Sur tout le territoire : outils et engins nettoyés puis désinfectés (produit fongicide autorisé) à chaque entrée et sortie de site et pendant le chantier ; pas de griffes ni crampons, sauf abattage par démontage (art. 18).</li>
<li>Élagueur professionnel : enregistrement au registre des opérateurs (n° <?= e(setting('phyto_inupp')) ?>) et passeport phytosanitaire pour le bois et les écorces de platane mis en circulation, y compris vers une déchèterie (art. 21).</li>
<li>En zone délimitée : déclaration préalable à la DRAAF au moins 15 jours ouvrés avant les travaux et désinfection entre chaque platane (art. 19) ; produits d'abattage incinérés sur place sauf dérogation (art. 14).</li>
<li>Suspicion de chancre coloré : stopper le chantier et signaler à la DRAAF — <a href="https://draaf.occitanie.agriculture.gouv.fr/signaler-un-organisme-nuisible-aux-vegetaux-a9380.html" target="_blank" rel="noopener">formulaire de signalement ↗</a> · <a href="https://draaf.occitanie.agriculture.gouv.fr/chancre-colore-du-platane" target="_blank" rel="noopener">carte des zones délimitées ↗</a>.</li></ul>
<?php if (est_admin()): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="reglages"><h2 style="margin-top:0">Réglages</h2>
  <div class="cols"><label>Numéro d'enregistrement professionnel (code B) <input name="phyto_inupp" value="<?= e(setting('phyto_inupp')) ?>"></label><label>Produit de désinfection habituel <input name="phyto_produit" value="<?= e(setting('phyto_produit')) ?>"></label><label>Responsable(s) phytosanitaire(s) <input name="phyto_responsables" value="<?= e(setting('phyto_responsables')) ?>" placeholder="Taurand / Cazes Bouchet"></label></div>
  <label>Communes en zone délimitée (une par ligne, nom ou code postal) — annexe de l'arrêté préfectoral <textarea name="phyto_zones" rows="5" placeholder="Carcassonne&#10;Nîmes&#10;… (le Lot, l'Aveyron, le Cantal et la Corrèze ne sont pas concernés par l'arrêté du 24/07/2025)"><?= e(setting('phyto_zones')) ?></textarea></label>
  <button class="btn">Enregistrer</button></form>
<?php endif; ?>
<?php page_end(); ?>
