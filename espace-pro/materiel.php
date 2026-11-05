<?php
// Suivi du matériel : tableau de bord des échéances, fiche machine (contrôles, entretiens, carnet, pannes, documents)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$gere = planning_niveau() === 'tout';
$id = (int)($_GET['id'] ?? 0);
if (isset($_GET['doc'])) {
    $d = q("SELECT * FROM materiel_docs WHERE id = ?", [(int)$_GET['doc']])->fetch(); $f = $d ? chemin_fichier($d['fichier']) : null;
    if (!$f) { http_response_code(404); exit('Document introuvable'); }
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png']; $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: ' . (isset($types[$ext]) ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\n"], '', $d['nom']) . '"'); readfile($f); exit;
}
if (isset($_GET['img'])) { // photo d'une panne ou rapport joint au carnet
    $j = q("SELECT * FROM materiel_journal WHERE id = ?", [(int)$_GET['img']])->fetch(); $f = $j ? chemin_fichier(isset($_GET['rapport']) ? $j['fichier'] : $j['photo']) : null;
    if (!$f) { http_response_code(404); exit; } $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg'))); readfile($f); exit;
}
$fichierPost = function (string $champ, array $exts): string { // fichier envoyé (facultatif) → nom stocké
    $f = $_FILES[$champ] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) return '';
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)); $ext = $ext === 'jpeg' ? 'jpg' : $ext; if (!in_array($ext, $exts, true)) return '';
    $n = 'mat-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . ".$ext"; move_uploaded_file($f['tmp_name'], dossier_fichiers() . "/$n"); return $n;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? ''; $mid = (int)($_POST['id'] ?? 0);
    $dt = fn($v) => ($v = trim((string)$v)) !== '' ? date('Y-m-d', strtotime($v)) : null;
    $num = fn($v) => ($v = trim((string)$v)) !== '' ? nb($v) : null;
    if ($a === 'panne') { // ouvert à tout le monde (signalement depuis le terrain)
        $photo = !empty($_POST['photo_data']) ? (enregistrer_image_dataurl((string)$_POST['photo_data'], 'mat') ?? '') : $fichierPost('photo', ['jpg', 'png']);
        $imm = !empty($_POST['immobilise']) ? 1 : 0;
        q("INSERT INTO materiel_journal (materiel_id, type, quand, compteur, qui, detail, photo, statut, immobilise, cree_par) VALUES (?, 'panne', CURDATE(), ?, ?, ?, ?, 'ouverte', ?, ?)", [$mid, $num($_POST['compteur'] ?? ''), user()['prenom'], mb_substr(trim((string)($_POST['detail'] ?? '')), 0, 2000), $photo, $imm, user()['id']]);
        if ($imm) q("UPDATE materiel SET etat = 'immobilise' WHERE id = ?", [$mid]);
        $m = q("SELECT nom FROM materiel WHERE id = ?", [$mid])->fetch();
        notifier_gestionnaires(($imm ? '⛔ Matériel immobilisé : ' : 'Panne signalée : ') . ($m['nom'] ?? ''), mb_substr(trim((string)($_POST['detail'] ?? '')), 0, 200) . ' – ' . user()['prenom'], "materiel.php?id=$mid");
        flash('Panne signalée, le responsable est prévenu.'); redirect(!empty($_POST['retour']) ? $_POST['retour'] : "materiel.php?id=$mid");
    }
    if (!$gere) { flash('Réservé aux comptes qui gèrent le planning.', 'err'); redirect("materiel.php?id=$mid"); }
    if ($a === 'fiche') {
        $v = [trim($_POST['nom'] ?? ''), array_key_exists($_POST['type'] ?? '', types_materiel()) ? $_POST['type'] : 'autre', trim($_POST['immatriculation'] ?? ''), trim($_POST['marque'] ?? ''), trim($_POST['modele'] ?? ''), trim($_POST['serie'] ?? ''), $dt($_POST['achat_le'] ?? ''), $num($_POST['prix_achat'] ?? ''),
              $num($_POST['compteur'] ?? ''), in_array($_POST['compteur_unite'] ?? '', ['h', 'km'], true) ? $_POST['compteur_unite'] : 'h', $num($_POST['usage_annuel'] ?? ''), ($_POST['porteur_id'] ?? '') !== '' && (int)$_POST['porteur_id'] !== $mid ? (int)$_POST['porteur_id'] : null,
              ($_POST['personnel_id'] ?? '') !== '' ? (int)$_POST['personnel_id'] : null, array_key_exists($_POST['competence_requise'] ?? '', COMPETENCES) ? $_POST['competence_requise'] : '', trim($_POST['notes'] ?? ''), in_array($_POST['etat'] ?? '', ['ok', 'immobilise'], true) ? $_POST['etat'] : 'ok', !empty($_POST['actif']) ? 1 : 0];
        if ($v[0] === '') { flash('Le nom est obligatoire.', 'err'); redirect($mid ? "materiel.php?id=$mid" : 'materiel.php?nouveau=1'); }
        if ($mid) { $old = q("SELECT compteur FROM materiel WHERE id = ?", [$mid])->fetch(); q("UPDATE materiel SET nom = ?, type = ?, immatriculation = ?, marque = ?, modele = ?, serie = ?, achat_le = ?, prix_achat = ?, compteur = ?, compteur_unite = ?, usage_annuel = ?, porteur_id = ?, personnel_id = ?, competence_requise = ?, notes = ?, etat = ?, actif = ? WHERE id = ?", array_merge($v, [$mid]));
            if ($v[8] !== null && (float)($old['compteur'] ?? -1) !== $v[8]) q("UPDATE materiel SET compteur_le = CURDATE() WHERE id = ?", [$mid]); }
        else { q("INSERT INTO materiel (nom, type, immatriculation, marque, modele, serie, achat_le, prix_achat, compteur, compteur_unite, usage_annuel, porteur_id, personnel_id, competence_requise, notes, etat, actif, ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,99)", $v); $mid = (int)db()->lastInsertId();
            foreach (CONTROLES_TYPES[$v[1]] ?? [] as [$n, $p, $o]) q("INSERT INTO materiel_controles (materiel_id, nom, periodicite_mois, obligatoire) VALUES (?,?,?,?)", [$mid, $n, $p, $o]);
            foreach (ENTRETIENS_TYPES[$v[1]] ?? [] as [$n, $mo, $cp]) q("INSERT INTO materiel_entretiens (materiel_id, nom, tous_les_mois, tous_les_compteur) VALUES (?,?,?,?)", [$mid, $n, $mo, $cp]); }
        flash('Fiche enregistrée.'); redirect("materiel.php?id=$mid");
    }
    if ($a === 'controle_plan') {
        $cid = (int)($_POST['cid'] ?? 0); $v = [trim($_POST['nom'] ?? ''), max(1, (int)($_POST['periodicite_mois'] ?? 12)), !empty($_POST['obligatoire']) ? 1 : 0, trim($_POST['prestataire'] ?? ''), max(0, (int)($_POST['rappel_jours'] ?? 30)), $dt($_POST['dernier_le'] ?? ''), trim($_POST['notes'] ?? '')];
        if ($v[0] !== '') { $proch = $v[5] ? date('Y-m-d', strtotime($v[5] . ' +' . $v[1] . ' months')) : ($dt($_POST['prochain_le'] ?? '') ?: null);
            if ($cid) q("UPDATE materiel_controles SET nom = ?, periodicite_mois = ?, obligatoire = ?, prestataire = ?, rappel_jours = ?, dernier_le = ?, notes = ?, prochain_le = ? WHERE id = ? AND materiel_id = ?", array_merge($v, [$proch, $cid, $mid]));
            else q("INSERT INTO materiel_controles (materiel_id, nom, periodicite_mois, obligatoire, prestataire, rappel_jours, dernier_le, notes, prochain_le) VALUES (?,?,?,?,?,?,?,?,?)", array_merge([$mid], $v, [$proch]));
            materiel_maj_controle($mid); flash('Contrôle enregistré.'); }
        redirect("materiel.php?id=$mid&o=controles");
    }
    if ($a === 'controle_suppr') { q("UPDATE materiel_controles SET actif = 0 WHERE id = ? AND materiel_id = ?", [(int)$_POST['cid'], $mid]); materiel_maj_controle($mid); redirect("materiel.php?id=$mid&o=controles"); }
    if ($a === 'entretien_plan') {
        $eid = (int)($_POST['eid'] ?? 0); $v = [trim($_POST['nom'] ?? ''), max(0, (int)($_POST['tous_les_mois'] ?? 0)), max(0, (float)nb($_POST['tous_les_compteur'] ?? 0)), $dt($_POST['dernier_le'] ?? ''), $num($_POST['dernier_compteur'] ?? ''), trim($_POST['notes'] ?? '')];
        if ($v[0] !== '') { if ($eid) q("UPDATE materiel_entretiens SET nom = ?, tous_les_mois = ?, tous_les_compteur = ?, dernier_le = ?, dernier_compteur = ?, notes = ? WHERE id = ? AND materiel_id = ?", array_merge($v, [$eid, $mid])); else q("INSERT INTO materiel_entretiens (materiel_id, nom, tous_les_mois, tous_les_compteur, dernier_le, dernier_compteur, notes) VALUES (?,?,?,?,?,?,?)", array_merge([$mid], $v)); flash('Entretien enregistré.'); }
        redirect("materiel.php?id=$mid&o=entretiens");
    }
    if ($a === 'entretien_suppr') { q("UPDATE materiel_entretiens SET actif = 0 WHERE id = ? AND materiel_id = ?", [(int)$_POST['eid'], $mid]); redirect("materiel.php?id=$mid&o=entretiens"); }
    if ($a === 'realiser') { // contrôle / entretien / réparation fait
        $genre = in_array($_POST['genre'] ?? '', ['controle', 'entretien', 'reparation', 'note'], true) ? $_POST['genre'] : 'note';
        materiel_realiser($mid, $genre, (int)($_POST['plan_id'] ?? 0), $dt($_POST['quand'] ?? '') ?: date('Y-m-d'), $num($_POST['compteur'] ?? ''), $num($_POST['cout'] ?? ''), trim($_POST['qui'] ?? ''), mb_substr(trim((string)($_POST['detail'] ?? '')), 0, 2000), array_key_exists($_POST['resultat'] ?? '', RESULTATS) ? $_POST['resultat'] : '', $fichierPost('fichier', ['pdf', 'jpg', 'png']));
        if (!empty($_POST['panne_id'])) { q("UPDATE materiel_journal SET statut = 'reglee' WHERE id = ? AND materiel_id = ?", [(int)$_POST['panne_id'], $mid]); }
        if (!empty($_POST['remettre'])) q("UPDATE materiel SET etat = 'ok' WHERE id = ?", [$mid]);
        flash('Noté dans le carnet.'); redirect("materiel.php?id=$mid&o=carnet");
    }
    if ($a === 'planifier') { // crée un événement « Entretien matériel » qui réserve la machine
        $m = q("SELECT * FROM materiel WHERE id = ?", [$mid])->fetch(); $j = $dt($_POST['quand'] ?? '') ?: date('Y-m-d', strtotime('+1 day'));
        q("INSERT INTO evenements (type, titre, statut, debut, fin, journee, consignes, cree_par) VALUES ('entretien', ?, 'confirme', ?, ?, ?, ?, ?)", [trim((string)($_POST['titre'] ?? '')) ?: 'Entretien – ' . $m['nom'], "$j " . (empty($_POST['demi']) ? setting('planning_debut', '08:00') : '08:00') . ':00', "$j " . (empty($_POST['demi']) ? setting('planning_fin', '17:00') : '12:00') . ':00', empty($_POST['demi']) ? 1 : 0, trim((string)($_POST['detail'] ?? '')), user()['id']]);
        $eid = (int)db()->lastInsertId(); q("INSERT INTO evenement_materiel (evenement_id, materiel_id) VALUES (?,?)", [$eid, $mid]);
        if (!empty($_POST['personnel_id'])) q("INSERT INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$eid, (int)$_POST['personnel_id']]);
        evt_journal($eid, 'Entretien planifié depuis la fiche matériel'); flash('Entretien posé au planning : la machine est réservée ce jour-là.'); redirect("planning.php?fiche=$eid&jour=$j");
    }
    if ($a === 'doc') {
        $n = $fichierPost('fichier', ['pdf', 'jpg', 'png', 'docx', 'xlsx', 'txt']); if ($n === '') flash('Fichier manquant ou type non accepté (PDF, photo, Word, Excel).', 'err');
        else { q("INSERT INTO materiel_docs (materiel_id, dossier, nom, fichier, taille, cree_par) VALUES (?,?,?,?,?,?)", [$mid, in_array($_POST['dossier'] ?? '', DOSSIERS_MATERIEL, true) ? $_POST['dossier'] : 'Documents', mb_substr(basename((string)$_FILES['fichier']['name']), 0, 200), $n, (int)$_FILES['fichier']['size'], user()['id']]); flash('Document ajouté.'); }
        redirect("materiel.php?id=$mid&o=docs");
    }
    if ($a === 'doc_suppr') { $d = q("SELECT * FROM materiel_docs WHERE id = ? AND materiel_id = ?", [(int)$_POST['did'], $mid])->fetch(); if ($d) { if ($c = chemin_fichier($d['fichier'])) @unlink($c); q("DELETE FROM materiel_docs WHERE id = ?", [$d['id']]); } redirect("materiel.php?id=$mid&o=docs"); }
    redirect('materiel.php');
}
$pers = personnel_liste(true); $noms = []; foreach ($pers as $p) $noms[(int)$p['id']] = trim($p['prenom'] . ' ' . $p['nom']);
$TM = types_materiel();

// ---------- Signalement de panne (depuis le terrain) ----------
if (!empty($_GET['panne'])) {
    $mats = materiel_liste(); page_start('Signaler une panne', 'materiel.php', ['materiel.php', 'Matériel']); ?>
    <form method="post" enctype="multipart/form-data" class="card" style="max-width:560px"><?= csrf_field() ?><input type="hidden" name="action" value="panne"><input type="hidden" name="retour" value="<?= e((string)($_GET['retour'] ?? '')) ?>">
      <label>Matériel <select name="id" required><option value="">—</option><?php foreach ($mats as $m): ?><option value="<?= $m['id'] ?>" <?= (int)($_GET['panne']) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nom']) ?></option><?php endforeach; ?></select></label>
      <label>Que se passe-t-il ? <textarea name="detail" rows="3" required placeholder="Ex. fuite hydraulique au vérin, voyant moteur, chaîne qui saute…"></textarea></label>
      <div class="cols"><label>Compteur (h ou km) <input name="compteur" inputmode="decimal"></label><label>Photo <input type="file" name="photo" accept="image/*" capture="environment"></label></div>
      <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="immobilise" value="1" style="width:auto;margin:0"> La machine ne peut plus être utilisée (immobilisée)</label>
      <p><button class="btn">Envoyer le signalement</button></p></form>
    <?php page_end(); exit;
}
// ---------- Liste / tableau de bord ----------
if (!$id && empty($_GET['nouveau'])) {
    $ech = materiel_echeances(); $mats = materiel_liste(true);
    $parMat = []; foreach ($ech as $x) $parMat[$x['mid']][] = $x;
    page_start('Suivi du matériel', 'materiel.php', ['planning.php', 'Planning']); ?>
    <style>.ech{display:grid;gap:6px;margin:0 0 16px}.ech a{display:flex;gap:10px;align-items:center;background:var(--blanc);border:1px solid #e1e5da;border-left:5px solid;border-radius:10px;padding:8px 12px;text-decoration:none;color:inherit}.ech a small{margin-left:auto;white-space:nowrap}
    .mats{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px}.mat{background:var(--blanc);border:1px solid #e1e5da;border-radius:14px;padding:12px 14px;text-decoration:none;color:inherit;border-top:5px solid}.mat b{display:block}.mat.imm{background:#fdecea}.mat small{color:var(--doux)}</style>
    <p class="lead">Contrôles réglementaires, entretiens et pannes de chaque machine. Une machine immobilisée ou dont un contrôle obligatoire est dépassé est signalée au planning.</p>
    <p><?php if ($gere): ?><a class="btn" href="materiel.php?nouveau=1">+ Nouvelle machine ou outil</a> <?php endif; ?><a class="btn secondary" href="materiel.php?panne=1">⚠ Signaler une panne</a> <a class="mini" href="planning-ressources.php#materiel">Liste rapide (planning)</a></p>
    <h2>À faire <span class="muted">(<?= count($ech) ?>)</span></h2>
    <?php if (!$ech): ?><p class="muted">Rien d'urgent : tout est à jour.</p><?php endif; ?>
    <div class="ech"><?php foreach ($ech as $x): ?><a href="materiel.php?id=<?= $x['mid'] ?>&o=<?= $x['genre'] === 'panne' ? 'carnet' : $x['genre'] . 's' ?>" style="border-left-color:<?= $x['retard'] ? '#e6332a' : '#c77a12' ?>"><strong><?= e($x['materiel']) ?></strong> <?= e($x['lib']) ?><small><?= $x['date'] ? e(date('d/m/Y', strtotime($x['date']))) . ' · ' : '' ?><?= e($x['txt']) ?></small></a><?php endforeach; ?></div>
    <h2>Parc matériel</h2>
    <div class="mats"><?php foreach ($mats as $m): $n = count($parMat[(int)$m['id']] ?? []); $retard = count(array_filter($parMat[(int)$m['id']] ?? [], fn($x) => $x['retard'])); ?>
      <a class="mat <?= $m['etat'] === 'immobilise' ? 'imm' : '' ?>" href="materiel.php?id=<?= $m['id'] ?>" style="border-top-color:<?= e($m['couleur']) ?>;<?= (int)$m['actif'] ? '' : 'opacity:.5' ?>"><b><?= e($m['nom']) ?></b><small><?= e($TM[$m['type']] ?? $m['type']) ?><?= $m['marque'] || $m['modele'] ? ' · ' . e(trim($m['marque'] . ' ' . $m['modele'])) : '' ?><?= $m['porteur_id'] ? ' · monté sur ' . e(q("SELECT nom FROM materiel WHERE id = ?", [$m['porteur_id']])->fetch()['nom'] ?? '') : '' ?><?= $m['personnel_id'] ? ' · ' . e($noms[(int)$m['personnel_id']] ?? '') : '' ?></small>
        <small style="display:block;margin-top:4px"><?= $m['compteur'] !== null ? number_format((float)$m['compteur'], 0, ',', ' ') . ' ' . e($m['compteur_unite']) . ' · ' : '' ?><?= $m['etat'] === 'immobilise' ? '<strong style="color:#e6332a">immobilisé</strong>' : ($retard ? '<strong style="color:#e6332a">' . $retard . ' en retard</strong>' : ($n ? $n . ' à prévoir' : '✓ à jour')) ?></small></a>
    <?php endforeach; ?></div>
    <?php page_end(); exit;
}
// ---------- Fiche ----------
$m = $id ? q("SELECT * FROM materiel WHERE id = ?", [$id])->fetch() : null;
if ($id && !$m) redirect('materiel.php');
if (!$m) $m = ['id' => 0, 'nom' => '', 'type' => 'autre', 'immatriculation' => '', 'marque' => '', 'modele' => '', 'serie' => '', 'achat_le' => null, 'prix_achat' => null, 'compteur' => null, 'compteur_unite' => 'h', 'usage_annuel' => null, 'porteur_id' => null, 'personnel_id' => null, 'competence_requise' => '', 'notes' => '', 'etat' => 'ok', 'actif' => 1, 'couleur' => '#7a4fa0'];
$o = $_GET['o'] ?? 'fiche';
$ctrl = $id ? q("SELECT * FROM materiel_controles WHERE materiel_id = ? AND actif = 1 ORDER BY prochain_le", [$id])->fetchAll() : [];
$entr = $id ? q("SELECT * FROM materiel_entretiens WHERE materiel_id = ? AND actif = 1 ORDER BY id", [$id])->fetchAll() : [];
$carnet = $id ? q("SELECT * FROM materiel_journal WHERE materiel_id = ? ORDER BY quand DESC, id DESC LIMIT 200", [$id])->fetchAll() : [];
$docs = $id ? q("SELECT * FROM materiel_docs WHERE materiel_id = ? ORDER BY dossier, cree_le DESC", [$id])->fetchAll() : [];
$pannes = array_values(array_filter($carnet, fn($j) => $j['type'] === 'panne' && $j['statut'] === 'ouverte'));
$ech = $id ? materiel_echeances($id) : []; $autres = q("SELECT id, nom FROM materiel WHERE id <> ? AND actif = 1 ORDER BY nom", [$id])->fetchAll();
page_start($id ? $m['nom'] : 'Nouvelle machine', 'materiel.php', ['materiel.php', 'Matériel']);
?>
<style>.onglets{display:flex;gap:4px;overflow-x:auto;background:#ece8df;border-radius:12px;padding:4px;margin:0 0 14px}.onglets a{padding:8px 14px;border-radius:9px;text-decoration:none;color:var(--texte);font-weight:700;white-space:nowrap}.onglets a.on{background:#efe3d5;color:var(--brun)}
.lig{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #eee}.lig:last-child{border:0}.lig .d{flex:1}.lig .d small{color:var(--doux);display:block}.tag{border-radius:999px;padding:2px 9px;font-size:.75rem;font-weight:700;color:#fff;background:#8caa31}.tag.r{background:#e6332a}.tag.o{background:#c77a12}
details.card summary{cursor:pointer;font-weight:700}</style>
<?php if ($id): ?>
<p><?= $m['etat'] === 'immobilise' ? '<strong style="color:#e6332a">⛔ Immobilisé</strong> · ' : '' ?><?= e($TM[$m['type']] ?? '') ?><?= $m['compteur'] !== null ? ' · ' . number_format((float)$m['compteur'], 0, ',', ' ') . ' ' . e($m['compteur_unite']) . ($m['compteur_le'] ? ' au ' . date('d/m/Y', strtotime($m['compteur_le'])) : '') : '' ?> · coût 12 mois : <strong><?= money(materiel_cout_annuel($id)) ?></strong>
  · <a class="mini" href="materiel.php?panne=<?= $id ?>">Signaler une panne</a></p>
<?php if ($ech): ?><div class="flash <?= array_filter($ech, fn($x) => $x['retard']) ? 'err' : '' ?>"><?php foreach ($ech as $x): ?><div><?= $x['retard'] ? '⛔' : '⏰' ?> <?= e($x['lib']) ?><?= $x['date'] ? ' le ' . date('d/m/Y', strtotime($x['date'])) : '' ?> (<?= e($x['txt']) ?>)</div><?php endforeach; ?></div><?php endif; ?>
<nav class="onglets"><?php foreach (['fiche' => 'Fiche', 'controles' => 'Contrôles (' . count($ctrl) . ')', 'entretiens' => 'Entretiens (' . count($entr) . ')', 'carnet' => 'Carnet' . ($pannes ? ' · ' . count($pannes) . ' panne(s)' : ''), 'docs' => 'Documents (' . count($docs) . ')'] as $k => $l): ?><a href="?id=<?= $id ?>&o=<?= $k ?>" class="<?= $o === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<?php endif; ?>

<?php if ($o === 'fiche' || !$id): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="fiche"><input type="hidden" name="id" value="<?= $id ?>"><fieldset <?= $gere ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
  <div class="cols"><label>Nom <input name="nom" value="<?= e($m['nom']) ?>" required placeholder="Ex. Nacelle 20 m, Robo Evo, Camion benne"></label><label>Type <select name="type"><?php foreach ($TM as $k => $l): ?><option value="<?= $k ?>" <?= $m['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label><label>État <select name="etat"><option value="ok" <?= $m['etat'] === 'ok' ? 'selected' : '' ?>>En service</option><option value="immobilise" <?= $m['etat'] === 'immobilise' ? 'selected' : '' ?>>Immobilisé</option></select></label></div>
  <div class="cols"><label>Marque <input name="marque" value="<?= e($m['marque']) ?>"></label><label>Modèle <input name="modele" value="<?= e($m['modele']) ?>"></label><label>N° de série <input name="serie" value="<?= e($m['serie']) ?>"></label><label>Immatriculation <input name="immatriculation" value="<?= e($m['immatriculation']) ?>"></label></div>
  <div class="cols"><label>Date d'achat <input type="date" name="achat_le" value="<?= e((string)$m['achat_le']) ?>"></label><label>Prix d'achat HT (€) <input name="prix_achat" value="<?= e((string)$m['prix_achat']) ?>"></label><label>Compteur actuel <input name="compteur" inputmode="decimal" value="<?= e((string)$m['compteur']) ?>"></label><label>Unité <select name="compteur_unite"><option value="h" <?= $m['compteur_unite'] === 'h' ? 'selected' : '' ?>>heures</option><option value="km" <?= $m['compteur_unite'] === 'km' ? 'selected' : '' ?>>km</option></select></label><label>Usage annuel estimé <input name="usage_annuel" value="<?= e((string)$m['usage_annuel']) ?>" placeholder="h ou km par an"></label></div>
  <div class="cols"><label>Monté sur (machine partagée) <select name="porteur_id"><option value="">— autonome —</option><?php foreach ($autres as $x): ?><option value="<?= $x['id'] ?>" <?= (int)$m['porteur_id'] === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nom']) ?></option><?php endforeach; ?></select></label>
    <label>Attribué à (véhicule, tronçonneuse…) <select name="personnel_id"><option value="">—</option><?php foreach ($pers as $p): ?><option value="<?= $p['id'] ?>" <?= (int)$m['personnel_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($noms[(int)$p['id']]) ?></option><?php endforeach; ?></select></label>
    <label>Compétence requise <select name="competence_requise"><option value="">—</option><?php foreach (COMPETENCES as $k => $l): ?><option value="<?= $k ?>" <?= $m['competence_requise'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label></div>
  <label>Notes <input name="notes" value="<?= e($m['notes']) ?>"></label>
  <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="actif" value="1" style="width:auto;margin:0" <?= (int)$m['actif'] ? 'checked' : '' ?>> En parc (décoche si vendu ou à la casse)</label>
  <?php if ($gere): ?><p><button class="btn"><?= $id ? 'Enregistrer' : 'Créer la fiche' ?></button><?= !$id ? ' <span class="muted small">Les contrôles et entretiens habituels de ce type de matériel seront proposés automatiquement.</span>' : '' ?></p><?php endif; ?></fieldset></form>

<?php elseif ($o === 'controles'): ?>
<p class="muted">Contrôles réglementaires et vérifications périodiques. Un contrôle « obligatoire » dépassé est signalé comme conflit dans le planning. Marque-le « fait » pour reporter l'échéance et garder le rapport.</p>
<?php foreach ($ctrl as $c): $j = $c['prochain_le'] ? (int)floor((strtotime($c['prochain_le']) - strtotime(date('Y-m-d'))) / 86400) : null; ?>
<details class="card"><summary><?= e($c['nom']) ?> <span class="tag <?= $j === null || $j < 0 ? 'r' : ($j <= (int)$c['rappel_jours'] ? 'o' : '') ?>"><?= $c['prochain_le'] ? 'prochain le ' . date('d/m/Y', strtotime($c['prochain_le'])) : 'jamais fait' ?></span> <small class="muted">tous les <?= (int)$c['periodicite_mois'] ?> mois<?= (int)$c['obligatoire'] ? ' · obligatoire' : '' ?><?= $c['prestataire'] ? ' · ' . e($c['prestataire']) : '' ?></small></summary>
  <?php if ($gere): ?>
  <form method="post" enctype="multipart/form-data" class="cols" style="align-items:end;margin-top:10px;background:#f6f9f1;padding:10px;border-radius:10px"><?= csrf_field() ?><input type="hidden" name="action" value="realiser"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="genre" value="controle"><input type="hidden" name="plan_id" value="<?= $c['id'] ?>">
    <strong style="width:100%">✓ Contrôle réalisé</strong><label>Date <input type="date" name="quand" value="<?= date('Y-m-d') ?>"></label><label>Résultat <select name="resultat"><?php foreach (RESULTATS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></label><label>Par (organisme) <input name="qui" value="<?= e($c['prestataire']) ?>"></label><label>Coût HT <input name="cout" inputmode="decimal"></label><label>Compteur <input name="compteur" inputmode="decimal"></label><label>Rapport (PDF / photo) <input type="file" name="fichier"></label><label style="flex-basis:100%">Observations <input name="detail"></label><button class="btn">Enregistrer</button></form>
  <form method="post" class="cols" style="align-items:end;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="controle_plan"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="cid" value="<?= $c['id'] ?>">
    <label>Nom <input name="nom" value="<?= e($c['nom']) ?>"></label><label>Périodicité (mois) <input class="tiny" name="periodicite_mois" value="<?= (int)$c['periodicite_mois'] ?>"></label><label>Rappel (jours avant) <input class="tiny" name="rappel_jours" value="<?= (int)$c['rappel_jours'] ?>"></label><label>Prestataire <input name="prestataire" value="<?= e($c['prestataire']) ?>"></label><label>Dernier fait le <input type="date" name="dernier_le" value="<?= e((string)$c['dernier_le']) ?>"></label><label>Ou prochain prévu le <input type="date" name="prochain_le" value="<?= e((string)$c['prochain_le']) ?>"></label><label style="display:flex;gap:6px;align-items:center;font-weight:700"><input type="checkbox" name="obligatoire" value="1" style="width:auto;margin:0" <?= (int)$c['obligatoire'] ? 'checked' : '' ?>> Obligatoire (bloque au planning)</label><label>Notes <input name="notes" value="<?= e($c['notes']) ?>"></label>
    <button class="mini">Modifier</button><button class="mini danger" name="action" value="controle_suppr" onclick="return confirm('Retirer ce contrôle ?')">Retirer</button></form>
  <?php endif; ?></details>
<?php endforeach; ?>
<?php if ($gere): ?><form method="post" class="card cols" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="controle_plan"><input type="hidden" name="id" value="<?= $id ?>"><strong style="width:100%">+ Ajouter un contrôle</strong>
  <label>Nom <input name="nom" placeholder="Ex. VGP, contrôle technique, extincteurs"></label><label>Périodicité (mois) <input class="tiny" name="periodicite_mois" value="12"></label><label>Prestataire <input name="prestataire"></label><label>Dernier fait le <input type="date" name="dernier_le"></label><label>Ou prochain prévu le <input type="date" name="prochain_le"></label><label style="display:flex;gap:6px;align-items:center;font-weight:700"><input type="checkbox" name="obligatoire" value="1" checked style="width:auto;margin:0"> Obligatoire</label><button class="btn">Ajouter</button></form><?php endif; ?>

<?php elseif ($o === 'entretiens'): ?>
<p class="muted">Entretiens préventifs, par échéance de temps et/ou de compteur. Mets le compteur à jour (fiche) pour que les échéances au compteur soient justes.</p>
<?php foreach ($entr as $c): $x = array_values(array_filter($ech, fn($y) => $y['genre'] === 'entretien' && $y['id'] === (int)$c['id']))[0] ?? null; ?>
<details class="card"><summary><?= e($c['nom']) ?> <?= $x ? '<span class="tag ' . ($x['retard'] ? 'r' : 'o') . '">' . e($x['txt']) . '</span>' : '<span class="tag">à jour</span>' ?> <small class="muted"><?= (int)$c['tous_les_mois'] ? 'tous les ' . (int)$c['tous_les_mois'] . ' mois' : '' ?><?= (float)$c['tous_les_compteur'] > 0 ? ((int)$c['tous_les_mois'] ? ' ou ' : '') . 'tous les ' . (float)$c['tous_les_compteur'] . ' ' . e($m['compteur_unite']) : '' ?><?= $c['dernier_le'] ? ' · dernier le ' . date('d/m/Y', strtotime($c['dernier_le'])) . ($c['dernier_compteur'] !== null ? ' à ' . (float)$c['dernier_compteur'] . ' ' . e($m['compteur_unite']) : '') : '' ?></small></summary>
  <?php if ($gere): ?>
  <form method="post" enctype="multipart/form-data" class="cols" style="align-items:end;margin-top:10px;background:#f6f9f1;padding:10px;border-radius:10px"><?= csrf_field() ?><input type="hidden" name="action" value="realiser"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="genre" value="entretien"><input type="hidden" name="plan_id" value="<?= $c['id'] ?>">
    <strong style="width:100%">✓ Entretien réalisé</strong><label>Date <input type="date" name="quand" value="<?= date('Y-m-d') ?>"></label><label>Compteur <input name="compteur" inputmode="decimal" value="<?= e((string)$m['compteur']) ?>"></label><label>Par <input name="qui"></label><label>Coût HT (pièces, main-d'œuvre) <input name="cout" inputmode="decimal"></label><label>Facture (PDF / photo) <input type="file" name="fichier"></label><label style="flex-basis:100%">Détail (pièces changées…) <input name="detail"></label><button class="btn">Enregistrer</button></form>
  <form method="post" class="cols" style="align-items:end;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="entretien_plan"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="eid" value="<?= $c['id'] ?>">
    <label>Nom <input name="nom" value="<?= e($c['nom']) ?>"></label><label>Tous les (mois, 0 = non) <input class="tiny" name="tous_les_mois" value="<?= (int)$c['tous_les_mois'] ?>"></label><label>Tous les (<?= e($m['compteur_unite']) ?>, 0 = non) <input class="tiny" name="tous_les_compteur" value="<?= (float)$c['tous_les_compteur'] ?>"></label><label>Dernier fait le <input type="date" name="dernier_le" value="<?= e((string)$c['dernier_le']) ?>"></label><label>À (compteur) <input class="tiny" name="dernier_compteur" value="<?= e((string)$c['dernier_compteur']) ?>"></label><label>Notes <input name="notes" value="<?= e($c['notes']) ?>"></label>
    <button class="mini">Modifier</button><button class="mini danger" name="action" value="entretien_suppr" onclick="return confirm('Retirer cet entretien ?')">Retirer</button></form>
  <?php endif; ?></details>
<?php endforeach; ?>
<?php if ($gere): ?><form method="post" class="card cols" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="entretien_plan"><input type="hidden" name="id" value="<?= $id ?>"><strong style="width:100%">+ Ajouter un entretien</strong>
  <label>Nom <input name="nom" placeholder="Ex. Vidange, affûtage, graissage"></label><label>Tous les (mois) <input class="tiny" name="tous_les_mois" value="0"></label><label>Tous les (<?= e($m['compteur_unite']) ?>) <input class="tiny" name="tous_les_compteur" value="0"></label><label>Dernier fait le <input type="date" name="dernier_le"></label><label>À (compteur) <input class="tiny" name="dernier_compteur"></label><button class="btn">Ajouter</button></form>
<form method="post" class="card cols" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="planifier"><input type="hidden" name="id" value="<?= $id ?>"><strong style="width:100%">📅 Poser un entretien au planning (réserve la machine)</strong>
  <label>Jour <input type="date" name="quand" value="<?= date('Y-m-d', strtotime('+1 day')) ?>"></label><label>Titre <input name="titre" placeholder="Entretien – <?= e($m['nom']) ?>"></label><label>Qui <select name="personnel_id"><option value="">—</option><?php foreach ($pers as $p): if (!(int)$p['actif']) continue; ?><option value="<?= $p['id'] ?>"><?= e($noms[(int)$p['id']]) ?></option><?php endforeach; ?></select></label><label style="display:flex;gap:6px;align-items:center;font-weight:700"><input type="checkbox" name="demi" value="1" style="width:auto;margin:0"> Demi-journée (matin)</label><label>Consignes <input name="detail"></label><button class="btn secondary">Planifier</button></form><?php endif; ?>

<?php elseif ($o === 'carnet'): ?>
<?php foreach ($pannes as $p): ?><div class="card" style="border-left:5px solid #e6332a"><strong>⚠ Panne <?= (int)$p['immobilise'] ? '(machine immobilisée) ' : '' ?>du <?= date('d/m/Y', strtotime($p['quand'])) ?></strong> – <?= e($p['qui']) ?><br><?= nl2br(e((string)$p['detail'])) ?><?= $p['photo'] ? '<br><a href="materiel.php?id=' . $id . '&img=' . $p['id'] . '" target="_blank"><img src="materiel.php?id=' . $id . '&img=' . $p['id'] . '" alt="" style="max-height:140px;border-radius:8px;margin-top:6px"></a>' : '' ?>
  <?php if ($gere): ?><form method="post" enctype="multipart/form-data" class="cols" style="align-items:end;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="realiser"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="genre" value="reparation"><input type="hidden" name="panne_id" value="<?= $p['id'] ?>"><strong style="width:100%">🔧 Réparation faite</strong><label>Date <input type="date" name="quand" value="<?= date('Y-m-d') ?>"></label><label>Par <input name="qui"></label><label>Coût HT <input name="cout" inputmode="decimal"></label><label>Compteur <input name="compteur" inputmode="decimal"></label><label>Facture <input type="file" name="fichier"></label><label style="flex-basis:100%">Détail <input name="detail"></label><label style="display:flex;gap:6px;align-items:center;font-weight:700"><input type="checkbox" name="remettre" value="1" checked style="width:auto;margin:0"> Remettre en service</label><button class="btn">Clore la panne</button></form><?php endif; ?></div><?php endforeach; ?>
<?php if ($gere): ?><details class="card"><summary>+ Ajouter une note, une réparation ou un entretien hors programme</summary><form method="post" enctype="multipart/form-data" class="cols" style="align-items:end;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="realiser"><input type="hidden" name="id" value="<?= $id ?>"><label>Type <select name="genre"><option value="note">Note</option><option value="reparation">Réparation</option><option value="entretien">Entretien</option></select></label><label>Date <input type="date" name="quand" value="<?= date('Y-m-d') ?>"></label><label>Par <input name="qui"></label><label>Coût HT <input name="cout" inputmode="decimal"></label><label>Compteur <input name="compteur" inputmode="decimal"></label><label>Pièce jointe <input type="file" name="fichier"></label><label style="flex-basis:100%">Détail <input name="detail"></label><button class="btn">Enregistrer</button></form></details><?php endif; ?>
<h3>Historique</h3><?php if (!$carnet): ?><p class="muted">Carnet vide.</p><?php endif; ?>
<div class="card"><?php foreach ($carnet as $j): ?><div class="lig"><span class="tag <?= $j['type'] === 'panne' ? 'r' : ($j['type'] === 'controle' ? ($j['resultat'] === 'non_conforme' ? 'r' : '') : 'o') ?>" style="<?= $j['type'] === 'note' ? 'background:#8a8f96' : '' ?>"><?= e(TYPES_JOURNAL[$j['type']] ?? $j['type']) ?></span>
  <div class="d"><strong><?= date('d/m/Y', strtotime($j['quand'])) ?></strong> <?= $j['plan_id'] ? e(($j['type'] === 'controle' ? q("SELECT nom FROM materiel_controles WHERE id = ?", [$j['plan_id']])->fetch()['nom'] ?? '' : q("SELECT nom FROM materiel_entretiens WHERE id = ?", [$j['plan_id']])->fetch()['nom'] ?? '')) : '' ?><?= $j['resultat'] ? ' · ' . e(RESULTATS[$j['resultat']] ?? '') : '' ?><?= $j['type'] === 'panne' ? ' · ' . ($j['statut'] === 'ouverte' ? 'en cours' : 'réglée') : '' ?>
  <small><?= e((string)$j['detail']) ?><?= $j['qui'] ? ' — ' . e($j['qui']) : '' ?><?= $j['compteur'] !== null ? ' · ' . (float)$j['compteur'] . ' ' . e($m['compteur_unite']) : '' ?><?= $j['cout'] !== null ? ' · ' . money((float)$j['cout']) : '' ?><?= $j['fichier'] ? ' · <a href="materiel.php?id=' . $id . '&img=' . $j['id'] . '&rapport=1" target="_blank">pièce jointe</a>' : '' ?></small></div></div><?php endforeach; ?></div>

<?php elseif ($o === 'docs'): ?>
<?php if ($gere): ?><form method="post" enctype="multipart/form-data" class="card cols" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="doc"><input type="hidden" name="id" value="<?= $id ?>"><label>Ajouter un document <input type="file" name="fichier" required></label><label>Dossier <select name="dossier"><?php foreach (DOSSIERS_MATERIEL as $d): ?><option><?= e($d) ?></option><?php endforeach; ?></select></label><button class="btn">Ajouter</button></form><?php endif; ?>
<?php $dos = []; foreach ($docs as $d) $dos[$d['dossier']][] = $d; foreach ($carnet as $j) if ($j['fichier']) $dos[$j['type'] === 'controle' ? 'Rapports de contrôle' : 'Factures'][] = ['id' => 0, 'nom' => TYPES_JOURNAL[$j['type']] . ' du ' . date('d/m/Y', strtotime($j['quand'])), 'url' => "materiel.php?id=$id&img={$j['id']}&rapport=1", 'cree_le' => $j['quand']]; ?>
<?php foreach (DOSSIERS_MATERIEL as $d): $l = $dos[$d] ?? []; ?><details class="card" <?= $l ? 'open' : '' ?>><summary>📁 <?= e($d) ?> <span class="muted small"><?= $l ? count($l) . ' élément(s)' : 'vide' ?></span></summary>
  <?php foreach ($l as $x): ?><div class="lig">📄<div class="d"><a href="<?= e($x['url'] ?? "materiel.php?id=$id&doc={$x['id']}") ?>" target="_blank"><?= e($x['nom']) ?></a><small><?= e(date('d/m/Y', strtotime($x['cree_le']))) ?></small></div><?php if (!empty($x['id']) && $gere): ?><form method="post" onsubmit="return confirm('Supprimer ?')"><?= csrf_field() ?><input type="hidden" name="action" value="doc_suppr"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="did" value="<?= $x['id'] ?>"><button class="mini danger">✕</button></form><?php endif; ?></div><?php endforeach; ?></details><?php endforeach; ?>
<?php endif; ?>
<?php page_end(); ?>
