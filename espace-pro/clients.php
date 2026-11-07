<?php
// Répertoire clients : recherche, fiche (contact et adresses, rendez-vous, devis et contrats, porte-document, autorisations)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$gere = planning_niveau() === 'tout';
$ajoute = peut_planifier();
$id = (int)($_GET['id'] ?? 0);

if (isset($_GET['doc'])) { // téléchargement d'un document du porte-doc
    $d = q("SELECT * FROM client_docs WHERE id = ?", [(int)$_GET['doc']])->fetch();
    if ($d && !$gere && in_array($d['dossier'], DOSSIERS_FINANCIERS, true)) $d = null; // devis et contrats : administration et secrétariat seulement
    $f = $d ? chemin_fichier($d['fichier']) : null;
    if (!$f) { http_response_code(404); exit('Document introuvable'); }
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv'];
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream')); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (isset($types[$ext]) && $ext !== 'csv' ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\n"], '', $d['nom']) . '"');
    readfile($f); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? ''; $cid = (int)($_POST['id'] ?? 0);
    if (in_array($a, ['client', 'adresse', 'adresse_suppr', 'archiver'], true) && !$gere) { flash("Seuls les comptes qui gèrent le planning modifient les fiches clients.", 'err'); redirect("clients.php?id=$cid"); }
    if ($a === 'client') {
        $nom = trim($_POST['nom'] ?? ''); if ($nom === '') { flash('Le nom est obligatoire.', 'err'); redirect('clients.php' . ($cid ? "?id=$cid" : '?nouveau=1')); }
        $v = [$nom, array_key_exists($_POST['type'] ?? '', TYPES_CLIENT) ? $_POST['type'] : 'particulier', trim($_POST['telephone'] ?? ''), trim($_POST['mobile'] ?? ''), trim($_POST['email'] ?? ''), !empty($_POST['ok_sms']) ? 1 : 0, !empty($_POST['ok_email']) ? 1 : 0, trim($_POST['ref_externe'] ?? ''), trim($_POST['notes'] ?? '')];
        if ($cid) q("UPDATE clients SET nom = ?, type = ?, telephone = ?, mobile = ?, email = ?, ok_sms = ?, ok_email = ?, ref_externe = ?, notes = ? WHERE id = ?", array_merge($v, [$cid]));
        else { q("INSERT INTO clients (nom, type, telephone, mobile, email, ok_sms, ok_email, ref_externe, notes) VALUES (?,?,?,?,?,?,?,?,?)", $v); $cid = (int)db()->lastInsertId(); }
        flash('Fiche client enregistrée.'); redirect("clients.php?id=$cid&o=" . ($_POST['onglet'] ?? 'contact'));
    }
    if ($a === 'adresse') {
        $aid = (int)($_POST['aid'] ?? 0);
        $v = [trim($_POST['libelle'] ?? '') ?: 'Chantier', trim($_POST['adresse'] ?? ''), trim($_POST['cp'] ?? ''), trim($_POST['ville'] ?? ''), is_numeric($_POST['lat'] ?? '') ? (float)$_POST['lat'] : null, is_numeric($_POST['lng'] ?? '') ? (float)$_POST['lng'] : null, trim($_POST['interlocuteur'] ?? ''), trim($_POST['tel'] ?? '')];
        if ($aid) q("UPDATE client_adresses SET libelle = ?, adresse = ?, cp = ?, ville = ?, lat = ?, lng = ?, interlocuteur = ?, tel = ? WHERE id = ? AND client_id = ?", array_merge($v, [$aid, $cid]));
        else q("INSERT INTO client_adresses (client_id, libelle, adresse, cp, ville, lat, lng, interlocuteur, tel, ordre) VALUES (?,?,?,?,?,?,?,?,?,99)", array_merge([$cid], $v));
        flash('Adresse enregistrée.'); redirect("clients.php?id=$cid&o=contact");
    }
    if ($a === 'adresse_suppr') { q("DELETE FROM client_adresses WHERE id = ? AND client_id = ?", [(int)$_POST['aid'], $cid]); redirect("clients.php?id=$cid&o=contact"); }
    if ($a === 'archiver') { q("UPDATE clients SET actif = 1 - actif WHERE id = ?", [$cid]); redirect("clients.php?id=$cid"); }
    if ($a === 'doc' && $ajoute) {
        $f = $_FILES['fichier'] ?? null; $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION)); $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) flash("Le fichier n'est pas arrivé (taille maximale : " . ini_get('upload_max_filesize') . ').', 'err');
        elseif (!in_array($ext, ['pdf', 'jpg', 'png', 'docx', 'xlsx', 'odt', 'ods', 'txt', 'csv', 'heic'], true)) flash('Type de fichier non accepté (PDF, photo, Word, Excel…).', 'err');
        else { $dos = in_array($_POST['dossier'] ?? '', DOSSIERS_CLIENT, true) ? $_POST['dossier'] : 'Documents';
            $nf = chemin_client_nouveau($cid, $dos, basename((string)$f['name']), $ext); move_uploaded_file($f['tmp_name'], dossier_fichiers() . "/$nf");
            q("INSERT INTO client_docs (client_id, dossier, nom, fichier, taille, cree_par, visible_client) VALUES (?,?,?,?,?,?,?)", [$cid, $dos, mb_substr(basename((string)$f['name']), 0, 200), $nf, (int)$f['size'], user()['id'], !empty($_POST['visible_client']) ? 1 : 0]); flash('Document ajouté.'); }
        redirect("clients.php?id=$cid&o=doc");
    }
    // ---- Espace client ----
    if (in_array($a, ['acces_ajout', 'acces_inviter', 'acces_actif', 'acces_suppr', 'grality', 'demande_traitee', 'doc_visible'], true) && !$gere) { flash("Réservé aux comptes qui gèrent le planning.", 'err'); redirect("clients.php?id=$cid"); }
    if ($a === 'acces_ajout') {
        $em = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) { flash('Adresse e-mail invalide.', 'err'); redirect("clients.php?id=$cid&o=portail"); }
        if (q("SELECT id FROM client_acces WHERE client_id = ? AND email = ?", [$cid, $em])->fetch()) { flash('Cette adresse a déjà un accès pour ce client.', 'err'); redirect("clients.php?id=$cid&o=portail"); }
        q("INSERT INTO client_acces (client_id, nom, email, cree_par) VALUES (?,?,?,?)", [$cid, mb_substr(trim((string)($_POST['nom'] ?? '')), 0, 150), $em, user()['id']]); $aid = (int)db()->lastInsertId();
        journal('clients', "Accès à l'espace client ouvert pour $em (client $cid)");
        flash(!empty($_POST['inviter']) ? (portail_inviter($aid) ? "Accès créé et invitation envoyée à $em." : "Accès créé, mais l'e-mail d'invitation n'est pas parti.") : "Accès créé (pas d'invitation envoyée).");
        redirect("clients.php?id=$cid&o=portail");
    }
    if ($a === 'acces_inviter') { $ok = portail_inviter((int)$_POST['aid']); flash($ok ? 'Invitation envoyée.' : "L'e-mail n'est pas parti.", $ok ? 'ok' : 'err'); redirect("clients.php?id=$cid&o=portail"); }
    if ($a === 'acces_actif') { q("UPDATE client_acces SET actif = 1 - actif WHERE id = ? AND client_id = ?", [(int)$_POST['aid'], $cid]); q("DELETE j FROM client_jetons j JOIN client_acces a ON a.id = j.acces_id WHERE a.id = ? AND a.actif = 0", [(int)$_POST['aid']]); redirect("clients.php?id=$cid&o=portail"); }
    if ($a === 'acces_suppr') { q("DELETE FROM client_jetons WHERE acces_id = ?", [(int)$_POST['aid']]); q("DELETE FROM client_acces WHERE id = ? AND client_id = ?", [(int)$_POST['aid'], $cid]); flash('Accès supprimé.'); redirect("clients.php?id=$cid&o=portail"); }
    if ($a === 'grality') { $u = trim((string)($_POST['grality_url'] ?? '')); if ($u !== '' && !preg_match('#^https://#i', $u)) { flash('Le lien Grality doit commencer par https://', 'err'); redirect("clients.php?id=$cid&o=portail"); } q("UPDATE clients SET grality_url = ? WHERE id = ?", [mb_substr($u, 0, 500), $cid]); flash('Lien Grality enregistré.'); redirect("clients.php?id=$cid&o=portail"); }
    if ($a === 'demande_traitee') { q("UPDATE client_demandes SET traitee_le = IF(traitee_le IS NULL, NOW(), NULL), traitee_par = ? WHERE id = ? AND client_id = ?", [user()['id'], (int)$_POST['did'], $cid]); redirect("clients.php?id=$cid&o=portail"); }
    if ($a === 'doc_visible') { q("UPDATE client_docs SET visible_client = 1 - visible_client WHERE id = ? AND client_id = ?", [(int)$_POST['did'], $cid]); redirect("clients.php?id=$cid&o=doc"); }
    if ($a === 'doc_suppr' && $gere) { $d = q("SELECT * FROM client_docs WHERE id = ? AND client_id = ?", [(int)$_POST['did'], $cid])->fetch(); if ($d) { if ($c = chemin_fichier($d['fichier'])) @unlink($c); q("DELETE FROM client_docs WHERE id = ?", [$d['id']]); } redirect("clients.php?id=$cid&o=doc"); }
    redirect('clients.php' . ($cid ? "?id=$cid" : ''));
}

// ---------- Liste ----------
if (!$id && empty($_GET['nouveau'])) {
    $t = trim((string)($_GET['q'] ?? ''));
    $liste = $t !== '' ? q("SELECT c.*, (SELECT ville FROM client_adresses a WHERE a.client_id = c.id ORDER BY ordre, id LIMIT 1) ville FROM clients c WHERE nom LIKE ? OR email LIKE ? OR REPLACE(mobile,' ','') LIKE ? OR REPLACE(telephone,' ','') LIKE ? OR id IN (SELECT client_id FROM client_adresses WHERE ville LIKE ? OR adresse LIKE ?) ORDER BY actif DESC, nom LIMIT 200", ["%$t%", "%$t%", '%' . str_replace(' ', '', $t) . '%', '%' . str_replace(' ', '', $t) . '%', "%$t%", "%$t%"])->fetchAll()
                     : q("SELECT c.*, (SELECT ville FROM client_adresses a WHERE a.client_id = c.id ORDER BY ordre, id LIMIT 1) ville FROM clients c JOIN client_vus v ON v.client_id = c.id AND v.utilisateur_id = ? ORDER BY v.vu_le DESC LIMIT 12", [user()['id']])->fetchAll();
    $nb = (int)q("SELECT COUNT(*) c FROM clients WHERE actif = 1")->fetch()['c'];
    page_start('Clients', 'clients.php');
    ?>
    <style>.cl-l{display:grid;gap:8px;max-width:760px}.cl-l a{display:flex;gap:12px;align-items:center;background:var(--blanc);border:1px solid #e1e5da;border-radius:14px;padding:10px 14px;text-decoration:none;color:var(--texte)}
    .cl-l a i{width:42px;height:42px;border-radius:50%;background:#efe3d5;display:grid;place-items:center;font-style:normal;font-weight:900;color:var(--brun);flex:none}.cl-l b{display:block;text-transform:uppercase}.cl-l small{color:var(--doux);text-transform:uppercase}.cl-l a.off{opacity:.5}@media(max-width:900px){.cl-haut{margin-left:-12px;margin-right:-12px;margin-top:-14px}}
</style>
    <form class="cols" style="max-width:760px;align-items:end"><label>Rechercher un client <input name="q" value="<?= e($t) ?>" placeholder="Nom, ville, téléphone, e-mail…" autofocus></label><button class="btn">Rechercher</button><?php if ($gere): ?><a class="btn secondary" href="clients.php?nouveau=1">+ Nouveau client</a><?php endif; ?></form>
    <p class="muted"><?= $t !== '' ? count($liste) . ' résultat(s).' : "Les derniers clients consultés ($nb clients au total). Les clients sont aussi créés automatiquement quand tu enregistres un rendez-vous." ?></p>
    <div class="cl-l"><?php foreach ($liste as $c): ?><a href="clients.php?id=<?= $c['id'] ?>" class="<?= (int)$c['actif'] ? '' : 'off' ?>"><i><?= e(mb_strtoupper(mb_substr($c['nom'], 0, 1))) ?></i><span><b><?= e($c['nom']) ?></b><small><?= e($c['ville'] ?? '') ?><?= $c['type'] !== 'particulier' ? ' · ' . e(TYPES_CLIENT[$c['type']] ?? '') : '' ?></small></span></a><?php endforeach; ?></div>
    <?php page_end(); exit;
}

// ---------- Fiche ----------
$c = $id ? q("SELECT * FROM clients WHERE id = ?", [$id])->fetch() : ['id' => 0, 'nom' => '', 'type' => 'particulier', 'telephone' => '', 'mobile' => '', 'email' => '', 'ok_sms' => 1, 'ok_email' => 1, 'ref_externe' => '', 'notes' => '', 'actif' => 1];
if (!$c) { redirect('clients.php'); }
if ($id) client_vu($id);
$adr = $id ? q("SELECT * FROM client_adresses WHERE client_id = ? ORDER BY ordre, id", [$id])->fetchAll() : [];
$rdv = $id ? q("SELECT * FROM evenements WHERE client_id = ? ORDER BY COALESCE(debut, cree_le) DESC LIMIT 200", [$id])->fetchAll() : [];
$contrats = $id ? q("SELECT * FROM contrats WHERE client_id = ? ORDER BY cree_le DESC", [$id])->fetchAll() : [];
$docs = $id ? q("SELECT * FROM client_docs WHERE client_id = ? ORDER BY cree_le DESC", [$id])->fetchAll() : [];
if (!$gere) $docs = array_values(array_filter($docs, fn($d) => !in_array($d['dossier'], DOSSIERS_FINANCIERS, true)));
$rapps = $id ? q("SELECT r.*, e.titre, e.debut FROM rapports r JOIN evenements e ON e.id = r.evenement_id WHERE e.client_id = ? ORDER BY e.debut DESC", [$id])->fetchAll() : [];
$photos = $id ? q("SELECT p.*, e.titre FROM rapport_photos p JOIN evenements e ON e.id = p.evenement_id WHERE e.client_id = ? ORDER BY p.cree_le DESC LIMIT 60", [$id])->fetchAll() : [];
$o = $_GET['o'] ?? ($id ? 'contact' : 'contact');
$acces = $id && $gere ? q("SELECT * FROM client_acces WHERE client_id = ? ORDER BY cree_le", [$id])->fetchAll() : [];
$demandesC = $id && $gere ? q("SELECT d.*, a.email FROM client_demandes d LEFT JOIN client_acces a ON a.id = d.acces_id WHERE d.client_id = ? ORDER BY d.traitee_le IS NOT NULL, d.cree_le DESC LIMIT 30", [$id])->fetchAll() : [];
$nbDem = count(array_filter($demandesC, fn($d) => $d['traitee_le'] === null));
$pi = fn(string $i) => '<svg class="pi" aria-hidden="true"><use href="#pi-' . $i . '"/></svg>';
$tel = $c['mobile'] ?: $c['telephone']; $telN = preg_replace('/[^0-9+]/', '', $tel); $a0 = $adr[0] ?? null;
$dest = $a0 ? ($a0['lat'] !== null ? $a0['lat'] . ',' . $a0['lng'] : urlencode(trim($a0['adresse'] . ' ' . $a0['cp'] . ' ' . $a0['ville']))) : '';
page_start($id ? $c['nom'] : 'Nouveau client', 'clients.php', ['clients.php', 'Clients']);
?>
<style>
main{max-width:860px}main>h1{position:absolute;left:-9999px}
.cl-haut{background:var(--brun);color:#fff;border-radius:0 0 26px 26px;margin:-22px -20px 0;padding:18px 16px 40px;text-align:center}.cl-haut small{opacity:.85;display:block}.cl-haut h2{color:#fff;margin:2px 0;font-size:1.4rem;text-transform:uppercase}
.boutons{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin:-28px 0 14px}.boutons a{width:50px;height:50px;border-radius:50%;background:#efe3d5;color:var(--brun);display:grid;place-items:center;font-size:1.35rem;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.12)}.boutons a.off{opacity:.3;pointer-events:none}
.onglets{display:flex;gap:4px;overflow-x:auto;background:#ece8df;border-radius:12px;padding:4px;margin:0 0 14px}.onglets a{padding:8px 14px;border-radius:9px;text-decoration:none;color:var(--texte);font-weight:700;white-space:nowrap}.onglets a.on{background:#efe3d5;color:var(--brun);box-shadow:0 1px 3px rgba(0,0,0,.1)}
.bloc{background:var(--blanc);border:1px solid #e1e5da;border-radius:14px;padding:12px 14px;margin:0 0 10px}.bloc h4{margin:0 0 4px;color:var(--brun)}
.dos{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eee}.dos:last-child{border:0}.dos .ic{display:grid;place-items:center;color:var(--brun)}.boutons .pi{width:22px;height:22px}.vis{background:none;border:1px solid #d5dccd;border-radius:999px;padding:3px 9px;font-size:.78rem;font-weight:700;cursor:pointer;color:var(--doux)}.vis.on{background:#e3f0da;border-color:#8aa33c;color:var(--sapin)}
.phg{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:6px}.phg img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px}
</style>
<div class="cl-haut"><small><?= e(setting('ent_nom')) ?></small><h2><?= e($c['nom'] ?: 'Nouveau client') ?></h2><small><?= e(TYPES_CLIENT[$c['type']] ?? 'Client') ?><?= (int)$c['actif'] ? '' : ' · archivé' ?></small></div>
<?php if ($id): ?><div class="boutons">
  <a href="https://www.google.com/maps/dir/?api=1&destination=<?= e($dest) ?>" target="_blank" rel="noopener" class="<?= $dest ? '' : 'off' ?>" title="Itinéraire"><?= $pi('pin') ?></a>
  <a href="tel:<?= e($telN) ?>" class="<?= $telN ? '' : 'off' ?>" title="Appeler"><?= $pi('phone') ?></a><a href="sms:<?= e($telN) ?>" class="<?= $telN ? '' : 'off' ?>" title="SMS"><?= $pi('sms') ?></a>
  <a href="mailto:<?= e($c['email']) ?>" class="<?= $c['email'] ? '' : 'off' ?>" title="E-mail"><?= $pi('mail') ?></a>
  <?php if ($ajoute): ?><a href="planning.php?nouveau_client=<?= $id ?>" title="Nouveau rendez-vous pour ce client"><?= $pi('calendar') ?></a><?php endif; ?>
</div>
<nav class="onglets"><?php foreach (['contact' => 'Contact', 'rdv' => 'Rendez-vous (' . count($rdv) . ')'] + ($gere ? ['finance' => 'Devis et contrats'] : []) + ['doc' => 'Porte-doc'] + ($gere ? ['portail' => 'Espace client' . ($nbDem ? " ($nbDem)" : '')] : []) + ['autres' => 'Autres'] as $k => $l): ?><a href="?id=<?= $id ?>&o=<?= $k ?>" class="<?= $o === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav><?php endif; ?>

<?php if ($o === 'contact' || !$id): ?>
  <form method="post" class="bloc"><?= csrf_field() ?><input type="hidden" name="action" value="client"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="onglet" value="contact">
    <div class="cols"><label>Nom <input name="nom" value="<?= e($c['nom']) ?>" required <?= $gere ? '' : 'readonly' ?>></label><label>Type <select name="type" <?= $gere ? '' : 'disabled' ?>><?php foreach (TYPES_CLIENT as $k => $l): ?><option value="<?= $k ?>" <?= $c['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label></div>
    <div class="cols"><label>Mobile <input name="mobile" type="tel" value="<?= e($c['mobile']) ?>" <?= $gere ? '' : 'readonly' ?>></label><label>Téléphone fixe <input name="telephone" type="tel" value="<?= e($c['telephone']) ?>" <?= $gere ? '' : 'readonly' ?>></label><label>E-mail <input name="email" type="email" value="<?= e($c['email']) ?>" <?= $gere ? '' : 'readonly' ?>></label></div>
    <div class="cols"><label>Référence Extrabat <input name="ref_externe" value="<?= e($c['ref_externe']) ?>" <?= $gere ? '' : 'readonly' ?>></label><label>Notes <input name="notes" value="<?= e($c['notes']) ?>" <?= $gere ? '' : 'readonly' ?>></label></div>
    <input type="hidden" name="ok_sms" value="<?= (int)$c['ok_sms'] ?>"><input type="hidden" name="ok_email" value="<?= (int)$c['ok_email'] ?>">
    <?php if ($gere): ?><button class="btn"><?= $id ? 'Enregistrer' : 'Créer le client' ?></button><?php endif; ?>
  </form>
  <?php if ($id): ?><h3>Adresses</h3>
  <?php foreach (array_merge($adr, $gere ? [['id' => 0, 'libelle' => '', 'adresse' => '', 'cp' => '', 'ville' => '', 'lat' => null, 'lng' => null, 'interlocuteur' => '', 'tel' => '']] : []) as $x): ?>
    <form method="post" class="bloc adr"><?= csrf_field() ?><input type="hidden" name="action" value="adresse"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aid" value="<?= $x['id'] ?>">
      <?php if ($x['id']): ?><h4><?= $pi('house') ?> <?= e($x['libelle']) ?> <?php if ($x['lat'] !== null): ?><a class="mini" href="https://www.google.com/maps/dir/?api=1&destination=<?= e($x['lat'] . ',' . $x['lng']) ?>" target="_blank" rel="noopener">Itinéraire</a><?php endif; ?></h4><?php else: ?><h4>+ Ajouter une adresse</h4><?php endif; ?>
      <?php if ($gere): ?>
      <div class="cols"><label>Libellé <input name="libelle" value="<?= e($x['libelle']) ?>" placeholder="Chantier, Correspondance, Résidence secondaire…"></label><label class="ac">Adresse <input name="adresse" class="adrIn" value="<?= e($x['adresse']) ?>" placeholder="Tape puis choisis dans la liste"><ul hidden></ul></label></div>
      <div class="cols"><label>Code postal <input name="cp" value="<?= e($x['cp']) ?>"></label><label>Ville <input name="ville" value="<?= e($x['ville']) ?>"></label><label>Interlocuteur sur place <input name="interlocuteur" value="<?= e($x['interlocuteur']) ?>"></label><label>Son téléphone <input name="tel" value="<?= e($x['tel']) ?>"></label></div>
      <input type="hidden" name="lat" value="<?= e((string)$x['lat']) ?>"><input type="hidden" name="lng" value="<?= e((string)$x['lng']) ?>">
      <p style="margin:0"><button class="mini"><?= $x['id'] ? 'Enregistrer' : 'Ajouter' ?></button> <?php if ($x['id']): ?><button class="mini danger" name="action" value="adresse_suppr" onclick="return confirm('Supprimer cette adresse ?')">Supprimer</button><?php endif; ?></p>
      <?php else: ?><p style="margin:0"><?= e($x['adresse']) ?><br><?= e($x['cp'] . ' ' . $x['ville']) ?><?= $x['interlocuteur'] ? '<br>Interlocuteur : ' . e($x['interlocuteur'] . ' ' . $x['tel']) : '' ?></p><?php endif; ?>
    </form>
  <?php endforeach; ?><?php endif; ?>
<?php elseif ($o === 'rdv'): ?>
  <?php if (!$rdv): ?><p class="muted">Aucun rendez-vous pour ce client.</p><?php endif; ?>
  <?php $mois = ''; foreach ($rdv as $r): $j = $r['debut'] ? substr($r['debut'], 0, 10) : ''; ?>
    <?php if ($j !== $mois): $mois = $j; ?><p class="muted" style="margin:12px 0 4px;text-transform:capitalize"><?= $j ? e(date_fr($j, false)) : 'À planifier' ?></p><?php endif; ?>
    <a class="bloc" href="planning.php?fiche=<?= $r['id'] ?>&jour=<?= e($j ?: date('Y-m-d')) ?>" style="display:block;text-decoration:none;color:inherit;border-left:5px solid <?= e(TYPES_EVT[$r['type']][1] ?? '#888') ?>">
      <span class="muted small"><?= $r['debut'] ? ((int)$r['journee'] ? 'Journée' : date('H:i', strtotime($r['debut'])) . ' – ' . date('H:i', strtotime($r['fin']))) : '' ?> · <?= e(TYPES_EVT[$r['type']][0] ?? '') ?> · <?= e(STATUTS_EVT[$r['statut']] ?? '') ?></span><br><strong><?= e($r['titre']) ?></strong></a>
  <?php endforeach; ?>
<?php elseif ($o === 'finance' && $gere): ?>
  <?php $devis = array_filter($rdv, fn($r) => $r['devis_ref'] !== '' || $r['devis_montant'] !== null); ?>
  <div class="bloc"><h4>Chiffre d'affaires</h4><p style="margin:0">Planifié ou réalisé : <strong><?= money((float)array_sum(array_map(fn($r) => $r['statut'] !== 'annule' && $r['devis_signe'] ? (float)$r['devis_montant'] : 0, $rdv))) ?></strong> HT · devis non signés : <?= money((float)array_sum(array_map(fn($r) => !$r['devis_signe'] ? (float)$r['devis_montant'] : 0, $devis))) ?> HT</p></div>
  <h3>Devis</h3><?php if (!$devis): ?><p class="muted">Aucun devis renseigné dans les rendez-vous.</p><?php endif; ?>
  <?php foreach ($devis as $r): ?><div class="bloc" style="display:flex;justify-content:space-between;gap:10px"><span><?= $pi('doc') ?> <strong><?= e($r['devis_ref'] ?: 'Devis') ?></strong><br><span class="small"><?= e($r['titre']) ?> · <?= (int)$r['devis_signe'] ? '<span style="color:var(--anis);font-weight:700">signé</span>' : 'non signé' ?></span><?= preg_match('#^https?://#i', $r['devis_lien']) ? '<br><a class="small" href="' . e($r['devis_lien']) . '" target="_blank" rel="noopener">Ouvrir ↗</a>' : '' ?></span><strong style="color:var(--brun)"><?= $r['devis_montant'] !== null ? money((float)$r['devis_montant']) : '' ?></strong></div><?php endforeach; ?>
  <h3>Contrats d'entretien</h3>
  <?php foreach ($contrats as $k): ?><a class="bloc" href="contrats.php?id=<?= $k['id'] ?>" style="display:flex;justify-content:space-between;text-decoration:none;color:inherit"><span><?= $pi('clipboard') ?> <strong>Contrat <?= e($k['numero']) ?></strong><br><span class="small"><?= e(['brouillon' => 'Brouillon', 'envoye' => 'Envoyé, en attente de signature', 'signe' => 'Signé', 'resilie' => 'Résilié', 'termine' => 'Terminé'][$k['statut']] ?? $k['statut']) ?></span></span><strong style="color:var(--brun)"><?= $k['montant_ht'] !== null ? money((float)$k['montant_ht']) . ' / an' : '' ?></strong></a><?php endforeach; ?>
  <p><a class="btn secondary" href="contrats.php?nouveau=1&client=<?= $id ?>">+ Nouveau contrat d'entretien</a></p>
<?php elseif ($o === 'doc'): ?>
  <?php if ($ajoute): ?><form method="post" enctype="multipart/form-data" class="bloc cols" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="doc"><input type="hidden" name="id" value="<?= $id ?>">
    <label>Ajouter un document <input type="file" name="fichier" required></label><label style="display:flex;gap:6px;align-items:center;font-weight:700;margin-bottom:10px"><input type="checkbox" name="visible_client" value="1" checked style="width:auto;margin:0"> Visible dans l'espace client</label><label>Dossier <select name="dossier"><?php foreach (DOSSIERS_CLIENT as $d): if (!$gere && in_array($d, DOSSIERS_FINANCIERS, true)) continue; ?><option><?= e($d) ?></option><?php endforeach; ?></select></label><button class="btn">Ajouter</button></form><?php endif; ?>
  <?php
  $dos = []; foreach ($docs as $d) $dos[$d['dossier']][] = ['nom' => $d['nom'], 'url' => "clients.php?doc={$d['id']}", 'date' => $d['cree_le'], 'id' => $d['id'], 'vis' => (int)$d['visible_client']];
  foreach ($rapps as $r) $dos[$r['modele'] === 'fin' ? "Bons d'intervention" : 'Rapports de rendez-vous'][] = ['nom' => ($r['modele'] === 'fin' ? 'Bon' : 'Rapport') . ' – ' . $r['titre'] . ($r['signe_le'] ? ' (signé)' : ''), 'url' => "rapport.php?evt={$r['evenement_id']}&pdf=1", 'date' => $r['debut'] ?: $r['cree_le'], 'id' => 0, 'auto' => 1];
  if ($gere) foreach ($contrats as $k) if (in_array($k['statut'], ['signe', 'envoye'], true)) $dos[$k['statut'] === 'signe' ? 'Contrats signés' : 'Devis'][] = ['nom' => 'Contrat ' . $k['numero'] . ($k['statut'] === 'signe' ? ' (signé)' : ' (à signer)'), 'url' => "contrats.php?id={$k['id']}&pdf=1", 'date' => $k['signe_le'] ?: $k['envoye_le'], 'id' => 0, 'auto' => 1];
  ?>
  <?php foreach (DOSSIERS_CLIENT as $d): $l = $dos[$d] ?? []; if ($d === 'Photos' || (!$gere && in_array($d, DOSSIERS_FINANCIERS, true))) continue; ?>
    <details class="bloc" <?= $l ? 'open' : '' ?>><summary style="cursor:pointer"><strong><?= $pi('box') ?> <?= e($d) ?></strong> <span class="muted small"><?= $l ? count($l) . ' élément(s)' : 'Dossier vide' ?></span></summary>
      <?php foreach ($l as $x): ?><div class="dos"><span class="ic"><?= $pi(str_ends_with(strtolower($x['nom']), '.pdf') || !empty($x['auto']) ? 'doc' : 'clipboard') ?></span><span style="flex:1"><a href="<?= e($x['url']) ?>" target="_blank" rel="noopener"><?= e($x['nom']) ?></a><br><span class="muted small"><?= $x['date'] ? e(date('d/m/Y', strtotime($x['date']))) : '' ?></span></span>
        <?php if ($x['id'] && $gere): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="doc_visible"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="did" value="<?= $x['id'] ?>"><button class="vis <?= $x['vis'] ? 'on' : '' ?>" title="Visible ou non dans l'espace client"><?= $x['vis'] ? 'Visible client' : 'Interne' ?></button></form><?php endif; ?>
        <?php if ($x['id'] && $gere): ?><form method="post" onsubmit="return confirm('Supprimer ce document ?')"><?= csrf_field() ?><input type="hidden" name="action" value="doc_suppr"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="did" value="<?= $x['id'] ?>"><button class="mini danger" title="Supprimer"><?= $pi('fermer') ?></button></form><?php endif; ?></div><?php endforeach; ?>
    </details>
  <?php endforeach; ?>
  <details class="bloc" <?= $photos ? 'open' : '' ?>><summary style="cursor:pointer"><strong><?= $pi('camera') ?> Photos</strong> <span class="muted small"><?= count($photos) ?> photo(s) des rapports</span></summary>
    <div class="phg"><?php foreach ($photos as $ph): ?><a href="rapport.php?evt=<?= $ph['evenement_id'] ?>&photo=<?= $ph['id'] ?>" target="_blank" title="<?= e($ph['titre'] . ($ph['legende'] ? ' – ' . $ph['legende'] : '')) ?>"><img src="rapport.php?evt=<?= $ph['evenement_id'] ?>&photo=<?= $ph['id'] ?>" alt="" loading="lazy"></a><?php endforeach; ?></div></details>
<?php elseif ($o === 'portail' && $gere): ?>
  <div class="bloc"><h4>Espace client</h4><p class="small muted" style="margin:0 0 8px">Le client se connecte sur <a href="../espace-client/" target="_blank" rel="noopener"><?= e(portail_url()) ?></a> avec son adresse e-mail (lien de connexion, sans mot de passe). Il y voit ses interventions, les documents marqués « Visible client » du porte-doc, les photos des rapports envoyés, ses contrats à signer, et peut faire une demande. Une collectivité peut avoir plusieurs accès (services techniques, élu, secrétariat).</p>
    <?php foreach ($acces as $x): ?><div class="dos"><span class="ic"><?= $pi('personne') ?></span><span style="flex:1"><strong><?= e($x['nom'] ?: $x['email']) ?></strong><?= (int)$x['actif'] ? '' : ' <span class="muted small">(désactivé)</span>' ?><br><span class="muted small"><?= e($x['email']) ?> · <?= $x['derniere_connexion'] ? 'dernière connexion le ' . e(date('d/m/Y', strtotime($x['derniere_connexion']))) : ($x['invite_le'] ? 'invité le ' . e(date('d/m/Y', strtotime($x['invite_le']))) . ', pas encore connecté' : 'pas encore invité') ?></span></span>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aid" value="<?= $x['id'] ?>"><?php if ((int)$x['actif']): ?><button class="mini" name="action" value="acces_inviter">Envoyer le lien</button> <?php endif; ?><button class="mini" name="action" value="acces_actif"><?= (int)$x['actif'] ? 'Désactiver' : 'Réactiver' ?></button> <button class="mini danger" name="action" value="acces_suppr" onclick="return confirm('Supprimer cet accès ?')">Supprimer</button></form></div><?php endforeach; ?>
    <?php if (!$acces): ?><p class="muted small">Aucun accès pour l'instant.</p><?php endif; ?>
  </div>
  <form method="post" class="bloc"><?= csrf_field() ?><input type="hidden" name="action" value="acces_ajout"><input type="hidden" name="id" value="<?= $id ?>"><h4>Ouvrir un accès</h4>
    <div class="cols"><label>Nom de la personne <input name="nom" placeholder="Ex. Services techniques, M. Dupont"></label><label>E-mail <input name="email" type="email" required value="<?= $acces ? '' : e($c['email']) ?>"></label></div>
    <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="inviter" value="1" checked style="width:auto;margin:0"> Lui envoyer l'invitation par e-mail maintenant</label>
    <button class="btn">Ouvrir l'accès</button></form>
  <form method="post" class="bloc"><?= csrf_field() ?><input type="hidden" name="action" value="grality"><input type="hidden" name="id" value="<?= $id ?>"><h4>Projet Grality (Arbogestion)</h4>
    <label>Lien vers le projet de gestion du patrimoine arboré <input name="grality_url" type="url" value="<?= e($c['grality_url'] ?? '') ?>" placeholder="https://…"></label>
    <p class="small muted" style="margin:0 0 8px">Si un lien est renseigné, le client voit le bouton « Mon patrimoine arboré » dans son espace.<?= $c['type'] === 'collectivite' ? ' Collectivité : le raccourci Urgence Élagage est affiché automatiquement.' : '' ?></p><button class="mini">Enregistrer</button></form>
  <h3>Demandes reçues</h3>
  <?php if (!$demandesC): ?><p class="muted">Aucune demande depuis l'espace client.</p><?php endif; ?>
  <?php foreach ($demandesC as $d): ?><div class="bloc" style="<?= $d['traitee_le'] ? 'opacity:.6' : 'border-left:5px solid #c77a12' ?>"><strong><?= e(['devis' => 'Demande de devis', 'intervention' => 'Intervention ou entretien', 'rdv' => 'Rendez-vous', 'question' => 'Question', 'autre' => 'Autre'][$d['objet']] ?? 'Demande') ?></strong> <span class="muted small">· <?= e(date('d/m/Y H:i', strtotime($d['cree_le']))) ?><?= $d['email'] ? ' · ' . e($d['email']) : '' ?></span>
    <?php if ($d['adresse']): ?><br><span class="small"><?= $pi('pin') ?> <?= e($d['adresse']) ?></span><?php endif; ?><p style="margin:6px 0;white-space:pre-line"><?= e($d['message']) ?></p>
    <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="demande_traitee"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="did" value="<?= $d['id'] ?>"><button class="mini"><?= $d['traitee_le'] ? 'Remettre à traiter' : 'Marquer comme traitée' ?></button> <?php if (!$d['traitee_le'] && $ajoute): ?><a class="mini" href="planning.php?nouveau_client=<?= $id ?>">Planifier un rendez-vous</a><?php endif; ?></form></div><?php endforeach; ?>
<?php elseif ($o === 'autres'): ?>
  <form method="post" class="bloc"><?= csrf_field() ?><input type="hidden" name="action" value="client"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="onglet" value="autres">
    <?php foreach (['nom', 'type', 'telephone', 'mobile', 'email', 'ref_externe', 'notes'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($c[$k]) ?>"><?php endforeach; ?>
    <h4>Autorisation de contact</h4>
    <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="ok_sms" value="1" style="width:auto;margin:0" <?= (int)$c['ok_sms'] ? 'checked' : '' ?> <?= $gere ? '' : 'disabled' ?>> SMS (confirmations, rappels, demande d'avis)</label>
    <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="ok_email" value="1" style="width:auto;margin:0" <?= (int)$c['ok_email'] ? 'checked' : '' ?> <?= $gere ? '' : 'disabled' ?>> E-mails (rappels, demande d'avis)</label>
    <p class="muted small">Si le client refuse, aucun SMS ni rappel automatique ne lui est envoyé ; les e-mails indispensables (proposition de date, rapport, contrat) restent possibles à la main.</p>
    <?php if ($gere): ?><button class="btn">Enregistrer</button><?php endif; ?>
  </form>
  <?php if ($gere): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archiver"><input type="hidden" name="id" value="<?= $id ?>"><button class="mini"><?= (int)$c['actif'] ? 'Archiver ce client' : 'Réactiver ce client' ?></button></form><?php endif; ?>
<?php endif; ?>
<script>
document.querySelectorAll('form.adr').forEach(f => { const a = f.querySelector('.adrIn'); if (!a) return; const l = a.nextElementSibling; let t, r = [];
  a.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => { if (a.value.length < 4) return; try { r = (await (await fetch('https://api-adresse.data.gouv.fr/search/?limit=5&lat=44.6&lon=1.95&q=' + encodeURIComponent(a.value))).json()).features || []; l.innerHTML = r.map((x, i) => `<li data-i="${i}" style="padding:6px 8px;cursor:pointer">${x.properties.label.replace(/</g, '&lt;')}</li>`).join(''); l.hidden = !r.length; } catch (e) {} }, 250); });
  l.addEventListener('mousedown', ev => { const li = ev.target.closest('li'); if (!li) return; ev.preventDefault(); const x = r[+li.dataset.i]; a.value = x.properties.name; f.cp.value = x.properties.postcode || ''; f.ville.value = x.properties.city || ''; f.lat.value = x.geometry.coordinates[1]; f.lng.value = x.geometry.coordinates[0]; l.hidden = true; });
  a.addEventListener('blur', () => setTimeout(() => l.hidden = true, 150)); });
</script>
<?php page_end(); ?>
