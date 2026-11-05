<?php
// Rapport d'un rendez-vous : compte rendu de visite ou bon de fin d'intervention (photos annotées, ressenti, signature, PDF)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_once __DIR__ . '/assistant_lib.php';
require_planning();
$id = (int)($_GET['evt'] ?? 0);
$e = evt_charger($id);
if (!$e) { http_response_code(404); page_start('Rapport', 'mon-planning.php'); echo '<p class="lead">Rendez-vous introuvable.</p>'; page_end(); exit; }
$monPerso = array_map('intval', array_column(q("SELECT id FROM personnel WHERE utilisateur_id = ?", [user()['id']])->fetchAll(), 'id'));
$peut = peut_modifier_evt($e['personnel']) || (bool)array_intersect($monPerso, $e['personnel']);
if (isset($_GET['pdf'])) {
    $r = rapport_charger($id);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . ($r['modele'] === 'fin' ? 'Bon-intervention' : 'Rapport-visite') . "-$id.pdf\"");
    echo rapport_pdf($id); exit;
}
if (isset($_GET['photo'])) { // vignette ou photo du rapport
    $ph = q("SELECT * FROM rapport_photos WHERE id = ? AND evenement_id = ?", [(int)$_GET['photo'], $id])->fetch();
    $f = $ph ? chemin_fichier(isset($_GET['orig']) && $ph['original'] ? $ph['original'] : $ph['fichier']) : null;
    if (!$f) { http_response_code(404); exit; }
    header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg')); header('Cache-Control: private, max-age=86400'); readfile($f); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $out = function ($d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    if (!$peut) $out(['erreur' => "Tu ne participes pas à ce rendez-vous : consultation seule."], 403);
    $r = rapport_charger($id);
    if (!$r['id']) { q("INSERT INTO rapports (evenement_id, modele, signataire, cree_par) VALUES (?,?,?,?)", [$id, $r['modele'], $r['signataire'], user()['id']]); }
    $signe = (bool)$r['signe_le'];
    switch ($in['a'] ?? '') {
        case 'enregistrer':
            if ($signe) $out(['erreur' => 'Le bon est signé : il ne peut plus être modifié.'], 409);
            q("UPDATE rapports SET modele = ?, texte = ?, reserves = ?, dechets = ?, ressenti = ?, signataire = ? WHERE evenement_id = ?", [
                array_key_exists($in['modele'] ?? '', MODELES_RAPPORT) ? $in['modele'] : $r['modele'], mb_substr((string)($in['texte'] ?? ''), 0, 20000), mb_substr((string)($in['reserves'] ?? ''), 0, 5000),
                in_array($in['dechets'] ?? '', ['', 'evacues', 'sur_place', 'broyes'], true) ? $in['dechets'] : '', array_key_exists($in['ressenti'] ?? '', RESSENTIS) ? $in['ressenti'] : '', mb_substr(trim((string)($in['signataire'] ?? '')), 0, 150), $id]);
            $out(['ok' => 1]);
        case 'photo':
            if ($signe) $out(['erreur' => 'Le bon est signé : les photos ne peuvent plus changer.'], 409);
            $n = enregistrer_image_dataurl((string)($in['data'] ?? '')); if (!$n) $out(['erreur' => 'Photo illisible ou trop lourde.'], 400);
            q("INSERT INTO rapport_photos (evenement_id, fichier, ordre, cree_par) VALUES (?,?,?,?)", [$id, $n, (int)q("SELECT COALESCE(MAX(ordre),0)+1 m FROM rapport_photos WHERE evenement_id = ?", [$id])->fetch()['m'], user()['id']]);
            $out(['ok' => 1, 'id' => (int)db()->lastInsertId()]);
        case 'photo_maj':
            if ($signe) $out(['erreur' => 'Le bon est signé : les photos ne peuvent plus changer.'], 409);
            $ph = q("SELECT * FROM rapport_photos WHERE id = ? AND evenement_id = ?", [(int)($in['id'] ?? 0), $id])->fetch(); if (!$ph) $out(['erreur' => 'Photo introuvable.'], 404);
            if (!empty($in['data'])) { $n = enregistrer_image_dataurl((string)$in['data']); if ($n) { if ($ph['original'] === '') q("UPDATE rapport_photos SET original = fichier WHERE id = ?", [$ph['id']]); else @unlink(dossier_fichiers() . '/' . $ph['fichier']); q("UPDATE rapport_photos SET fichier = ? WHERE id = ?", [$n, $ph['id']]); } }
            if (isset($in['legende'])) q("UPDATE rapport_photos SET legende = ? WHERE id = ?", [mb_substr(trim((string)$in['legende']), 0, 200), $ph['id']]);
            if (isset($in['dans_pdf'])) q("UPDATE rapport_photos SET dans_pdf = ? WHERE id = ?", [$in['dans_pdf'] ? 1 : 0, $ph['id']]);
            if (!empty($in['retablir']) && $ph['original'] !== '') { @unlink(dossier_fichiers() . '/' . $ph['fichier']); q("UPDATE rapport_photos SET fichier = original, original = '' WHERE id = ?", [$ph['id']]); }
            $out(['ok' => 1]);
        case 'photo_suppr':
            if ($signe) $out(['erreur' => 'Le bon est signé : les photos ne peuvent plus changer.'], 409);
            $ph = q("SELECT * FROM rapport_photos WHERE id = ? AND evenement_id = ?", [(int)($in['id'] ?? 0), $id])->fetch();
            if ($ph) { foreach ([$ph['fichier'], $ph['original']] as $f) if ($f !== '' && ($c = chemin_fichier($f))) @unlink($c); q("DELETE FROM rapport_photos WHERE id = ?", [$ph['id']]); }
            $out(['ok' => 1]);
        case 'signer':
            if ($signe) $out(['erreur' => 'Déjà signé.'], 409);
            $sig = (string)($in['signature'] ?? ''); $nom = mb_substr(trim((string)($in['signataire'] ?? '')), 0, 150);
            if (!preg_match('#^data:image/png;base64,#', $sig) || strlen($sig) > 600000) $out(['erreur' => 'Signature manquante.'], 400);
            if ($nom === '') $out(['erreur' => 'Indique le nom de la personne qui signe.'], 400);
            $r = rapport_charger($id);
            $emp = hash('sha256', json_encode([$id, $e['titre'], $r['texte'], $r['reserves'], $r['dechets'], array_column($r['photos'], 'fichier'), $nom, $sig]));
            q("UPDATE rapports SET signataire = ?, signature = ?, signe_le = NOW(), ip = ?, empreinte = ? WHERE evenement_id = ?", [$nom, $sig, $_SERVER['REMOTE_ADDR'] ?? '', $emp, $id]);
            if ($e['type'] === 'chantier' && !in_array($e['statut'], ['termine', 'annule'], true)) q("UPDATE evenements SET statut = 'termine', termine_le = NOW() WHERE id = ?", [$id]);
            if ((int)$e['phyto']) phyto_verrouiller($id);
            evt_journal($id, "Bon de fin d'intervention signé par $nom");
            notifier_gestionnaires('Chantier terminé : ' . $e['titre'], "Bon signé par $nom", "rapport.php?evt=$id");
            $out(['ok' => 1]);
        case 'terminer':
            if ($e['type'] === 'chantier' || $e['type'] === 'visite' || $e['type'] === 'rdv') { q("UPDATE evenements SET statut = 'termine', termine_le = COALESCE(termine_le, NOW()) WHERE id = ? AND statut <> 'annule'", [$id]); evt_journal($id, 'Marqué terminé depuis le rapport'); if ((int)$e['phyto']) phyto_verrouiller($id); }
            $out(['ok' => 1]);
        case 'porte_doc': // dépôt du PDF dans le porte-document du client
            $cid = $e['client_id'] ? (int)$e['client_id'] : client_assurer($e);
            if (!$cid) $out(['erreur' => "Pas de client sur ce rendez-vous : renseigne son nom dans la fiche."], 400);
            if (!$e['client_id']) q("UPDATE evenements SET client_id = ? WHERE id = ?", [$cid, $id]);
            $r = rapport_charger($id); $pdf = rapport_pdf($id);
            $nf = 'doc-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.pdf'; file_put_contents(dossier_fichiers() . "/$nf", $pdf);
            $dossier = $r['modele'] === 'fin' ? "Bons d'intervention" : 'Rapports de rendez-vous';
            $nom = ($r['modele'] === 'fin' ? "Bon de fin d'intervention" : 'Rapport de visite') . ' du ' . date('d/m/Y', strtotime($e['debut'] ?: 'now')) . ' – ' . $e['titre'] . '.pdf';
            q("INSERT INTO client_docs (client_id, dossier, nom, fichier, taille, cree_par) VALUES (?,?,?,?,?,?)", [$cid, $dossier, mb_substr($nom, 0, 200), $nf, strlen($pdf), user()['id']]);
            evt_journal($id, "PDF déposé dans le porte-document du client ($dossier)");
            $out(['ok' => 1, 'client_id' => $cid, 'dossier' => $dossier]);
        case 'envoyer':
            $m = rapport_envoyer($id, trim((string)($in['email'] ?? ''))); if ($m !== '') $out(['erreur' => $m], 400); $out(['ok' => 1]);
    }
    $out(['erreur' => 'Action inconnue.'], 400);
}
$r = rapport_charger($id);
$fin = $r['modele'] === 'fin';
$noms = []; foreach (personnel_liste(true) as $x) if (in_array((int)$x['id'], $e['personnel'], true)) $noms[] = $x['prenom'];
page_start($fin ? "Bon de fin d'intervention" : 'Rapport de visite', 'mon-planning.php', ['mon-planning.php?jour=' . substr((string)$e['debut'], 0, 10), 'Mon planning']);
?>
<style>
.rp{max-width:760px}.rp .tete{background:var(--brun);color:#fff;border-radius:16px;padding:14px 16px;margin-bottom:14px}.rp .tete h2{color:#fff;margin:0 0 4px;font-size:1.1rem}.rp .tete small{opacity:.85}
.rp .carte{background:var(--blanc);border:1px solid #e1e5da;border-radius:14px;padding:14px;margin:0 0 12px}
.rp .carte>strong{display:block;margin-bottom:6px;color:var(--brun)}
.ress{display:flex;gap:18px;justify-content:center;margin:6px 0}.ress button{display:grid;justify-items:center;gap:4px;border:0;background:none;font:inherit;font-weight:700;color:var(--doux);cursor:pointer}
.ress i{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;color:#fff;font-style:normal;font-size:1.2rem;opacity:.5;transition:.15s}.ress button.on i{opacity:1;transform:scale(1.18)}.ress button.on{color:var(--texte)}
.phs{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px}.ph{position:relative;aspect-ratio:3/4;border-radius:10px;overflow:hidden;background:#eee;cursor:pointer}
.ph img{width:100%;height:100%;object-fit:cover}.ph .lg{position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);color:#fff;font-size:.72rem;padding:3px 5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ph.hors img{opacity:.35}.ph .hb{position:absolute;top:4px;right:4px;background:#fff;border-radius:999px;font-size:.7rem;padding:1px 6px}
.ajout{aspect-ratio:3/4;border:2px dashed #c9d2bd;border-radius:10px;display:grid;place-items:center;text-align:center;color:var(--doux);font-weight:700;cursor:pointer;font-size:.85rem}
.sig{width:100%;height:180px;border:1px solid #c9d2bd;border-radius:10px;background:#fff;display:block}
.bas{position:sticky;bottom:0;z-index:5;background:var(--creme);box-shadow:0 -8px 12px -8px rgba(0,0,0,.15);padding:10px 0 max(10px,env(safe-area-inset-bottom));display:flex;gap:8px;flex-wrap:wrap}
.menu-ph{position:fixed;inset:0;z-index:150;background:rgba(0,0,0,.5);display:flex;align-items:flex-end}.menu-ph>div{background:#fff;width:100%;border-radius:18px 18px 0 0;padding:14px 16px max(16px,env(safe-area-inset-bottom));display:grid;gap:8px}
</style>
<div class="rp">
  <div class="tete"><h2><?= e($e['titre']) ?></h2><small><?= e(ucfirst(evt_quand($e))) ?><?= $e['ville'] ? ' · ' . e($e['ville']) : '' ?><?= $noms ? ' · ' . e(implode(', ', $noms)) : '' ?></small></div>
  <?php if (!$peut): ?><p class="flash">Consultation seule : tu ne participes pas à ce rendez-vous.</p><?php endif; ?>
  <?php if ($r['signe_le']): ?><p class="flash ok">✓ Bon signé par <?= e($r['signataire']) ?> le <?= e(date('d/m/Y à H:i', strtotime($r['signe_le']))) ?>.</p><?php endif; ?>
  <div class="carte"><strong>Modèle de rapport</strong>
    <select id="modele" <?= $r['signe_le'] ? 'disabled' : '' ?>><?php foreach (MODELES_RAPPORT as $k => $l): ?><option value="<?= $k ?>" <?= $r['modele'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="carte" id="cRess"><strong>Comment s'est déroulé le rendez-vous ?</strong>
    <div class="ress"><?php foreach (RESSENTIS as $k => [$l, $c]): ?><button type="button" data-r="<?= $k ?>" class="<?= $r['ressenti'] === $k ? 'on' : '' ?>"><i style="background:<?= $c ?>"><?= $k === 'bien' ? '✓' : '' ?></i><?= e($l) ?></button><?php endforeach; ?></div>
    <p class="muted small" style="margin:0;text-align:center">Pour toi seulement : n'apparaît pas sur le PDF.</p></div>
  <div class="carte"><strong id="lTexte"><?= $fin ? 'Travaux réalisés' : 'Compte rendu' ?></strong>
    <?php if ($peut && !$r['signe_le'] && acces_chiffrage() && assistant_ok()): ?><p class="small muted" style="margin:0 0 6px">Dicte ou tape tes notes en vrac, puis <button type="button" class="mini" id="rediger">✦ Mettre en forme en rapport de visite</button> <button type="button" class="mini" id="dicter" hidden>🎤 Dicter</button></p><?php endif; ?>
    <textarea id="texte" rows="6" placeholder="<?= $fin ? 'Ex. Taille douce de 3 tilleuls, abattage d\'un frêne mort, rognage de 2 souches…' : 'Ex. 4 chênes à élaguer, accès nacelle par le chemin, prévoir broyeur…' ?>"><?= e($r['texte']) ?></textarea></div>
  <div class="carte f-fin"><strong>Déchets verts</strong><select id="dechets"><option value="">—</option><option value="evacues" <?= $r['dechets'] === 'evacues' ? 'selected' : '' ?>>Évacués par nos soins</option><option value="sur_place" <?= $r['dechets'] === 'sur_place' ? 'selected' : '' ?>>Laissés sur place à la demande du client</option><option value="broyes" <?= $r['dechets'] === 'broyes' ? 'selected' : '' ?>>Broyés et laissés en paillage</option></select>
    <label style="margin-top:8px">Remarques ou réserves du client<textarea id="reserves" rows="2"><?= e($r['reserves']) ?></textarea></label></div>
  <div class="carte"><strong>Photos</strong><p class="muted small" style="margin:0 0 8px">Touche une photo pour la légender, l'annoter au doigt (cercles, flèches) ou la retirer du PDF.</p>
    <div class="phs" id="phs"></div><input type="file" id="fic" accept="image/*" multiple hidden></div>
  <div class="carte f-fin" id="cSig"><strong>Signature du client</strong>
    <?php if ($r['signe_le']): ?><img src="<?= e($r['signature']) ?>" alt="Signature" style="max-width:260px;border:1px solid #e1e5da;border-radius:8px"><p class="small muted">Empreinte : <?= e(substr($r['empreinte'], 0, 16)) ?>…</p>
    <?php else: ?><label>Nom du signataire <input id="signataire" value="<?= e($r['signataire']) ?>"></label><canvas class="sig" id="sig"></canvas>
      <p style="display:flex;gap:8px;margin:8px 0 0"><button type="button" class="mini" id="sigEff">Effacer</button><button type="button" class="btn" id="sigOk">Faire signer et terminer le chantier</button></p>
      <p class="muted small">Le client signe sur l'écran. La date, l'heure et une empreinte du bon sont enregistrées ; le bon ne peut plus être modifié ensuite.</p><?php endif; ?></div>
  <div class="bas">
    <button class="btn" type="button" id="pdfMenu" style="flex:1">Rapport PDF</button>
    <?php if ($peut && !$fin && $e['statut'] !== 'termine'): ?><button class="mini" id="term" type="button">Marquer terminé</button><?php endif; ?>
  </div>
  <p class="muted small" id="etat"><?= $r['envoye_le'] ? 'Dernier envoi le ' . e(date('d/m/Y à H:i', strtotime($r['envoye_le']))) . ' à ' . e($r['envoye_a']) . '.' : '' ?></p>
</div>
<script src="terrain.js?v=<?= e(app_version()) ?>"></script>
<script>
const CSRF = <?= json_encode(csrf()) ?>, ID = <?= $id ?>, PEUT = <?= $peut ? 'true' : 'false' ?>, SIGNE = <?= $r['signe_le'] ? 'true' : 'false' ?>;
let PH = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'legende' => $p['legende'], 'dans_pdf' => (int)$p['dans_pdf'], 'annotee' => $p['original'] !== ''], $r['photos']), JSON_UNESCAPED_UNICODE) ?>;
const $ = s => document.querySelector(s), esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);
async function post(d) { const r = await fetch('rapport.php?evt=' + ID, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': CSRF}, body: JSON.stringify(d)}); const j = await r.json().catch(() => ({erreur: 'Réponse illisible.'})); if (j.erreur) throw new Error(j.erreur); return j; }
const etat = (m, err) => { $('#etat').textContent = m; $('#etat').style.color = err ? '#e6332a' : ''; };
let ress = <?= json_encode($r['ressenti']) ?>;
const lire = () => ({a: 'enregistrer', modele: $('#modele').value, texte: $('#texte').value, reserves: $('#reserves').value, dechets: $('#dechets').value, ressenti: ress, signataire: $('#signataire') ? $('#signataire').value : ''});
let ts; const sauver = () => { if (!PEUT || SIGNE) return; clearTimeout(ts); ts = setTimeout(async () => { try { await post(lire()); etat('Enregistré automatiquement.'); } catch (e) { etat(e.message, true); } }, 600); };
const majModele = () => { const f = $('#modele').value === 'fin'; document.querySelectorAll('.f-fin').forEach(x => x.style.display = f ? '' : 'none'); $('#cRess').style.display = f ? 'none' : ''; $('#lTexte').textContent = f ? 'Travaux réalisés' : 'Compte rendu'; };
$('#modele').onchange = () => { majModele(); sauver(); }; majModele();
['#texte', '#reserves', '#dechets', '#signataire'].forEach(s => { const x = $(s); if (x) x.addEventListener('input', sauver); });
document.querySelectorAll('.ress button').forEach(b => b.onclick = () => { if (!PEUT) return; ress = ress === b.dataset.r ? '' : b.dataset.r; document.querySelectorAll('.ress button').forEach(x => x.classList.toggle('on', x.dataset.r === ress)); sauver(); });
if (!PEUT || SIGNE) document.querySelectorAll('.rp textarea,.rp select,.rp input:not(#mail)').forEach(x => x.disabled = true);
// photos
const src = p => 'rapport.php?evt=' + ID + '&photo=' + p.id + '&v=' + (p.v || 0);
function rendrePhotos() {
  $('#phs').innerHTML = PH.map((p, i) => `<div class="ph ${p.dans_pdf ? '' : 'hors'}" data-i="${i}"><img src="${src(p)}" alt="" loading="lazy">${p.dans_pdf ? '' : '<span class="hb">hors PDF</span>'}${p.legende ? `<span class="lg">${esc(p.legende)}</span>` : ''}</div>`).join('') + (PEUT && !SIGNE ? '<div class="ajout" id="aj">📷<br>Prendre ou choisir des photos</div>' : '');
  if ($('#aj')) $('#aj').onclick = () => $('#fic').click();
  document.querySelectorAll('.ph').forEach(d => d.onclick = () => menuPhoto(PH[+d.dataset.i]));
}
$('#fic').onchange = async ev => { const l = [...ev.target.files]; ev.target.value = '';
  for (const [k, f] of l.entries()) { etat(`Envoi de la photo ${k + 1}/${l.length}…`); try { const d = await reduirePhoto(f); const r = await post({a: 'photo', data: d}); PH.push({id: r.id, legende: '', dans_pdf: 1}); rendrePhotos(); } catch (e) { etat(e.message, true); return; } }
  etat(l.length + ' photo(s) ajoutée(s).'); };
function menuPhoto(p) {
  const m = document.createElement('div'); m.className = 'menu-ph';
  m.innerHTML = `<div><img src="${src(p)}" style="max-height:38vh;object-fit:contain;border-radius:10px;width:100%;background:#eee">
    ${PEUT && !SIGNE ? `<label>Légende <input id="mLg" value="${esc(p.legende)}"></label>
    <button class="btn" data-a="annoter">✏️ Annoter au doigt</button>${p.annotee ? '<button class="mini" data-a="retablir">Revenir à la photo d\'origine</button>' : ''}
    <button class="mini" data-a="pdf">${p.dans_pdf ? 'Retirer du PDF' : 'Remettre dans le PDF'}</button><button class="mini danger" data-a="suppr">Supprimer la photo</button>` : ''}
    <button class="btn secondary" data-a="fermer">Fermer</button></div>`;
  document.body.appendChild(m);
  const fermer = async () => { const lg = m.querySelector('#mLg'); if (lg && lg.value !== p.legende) { p.legende = lg.value; await post({a: 'photo_maj', id: p.id, legende: p.legende}).catch(() => {}); } m.remove(); rendrePhotos(); };
  m.addEventListener('click', async ev => { if (ev.target === m) return fermer(); const b = ev.target.closest('button'); if (!b) return; const a = b.dataset.a;
    try {
      if (a === 'fermer') return fermer();
      if (a === 'annoter') { const r = await fetch(src(p)); const bl = await r.blob(); const d = await new Promise(ok => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.readAsDataURL(bl); }); const n = await annoterPhoto(d); if (n) { await post({a: 'photo_maj', id: p.id, data: n}); p.annotee = true; p.v = Date.now(); } return fermer(); }
      if (a === 'retablir') { await post({a: 'photo_maj', id: p.id, retablir: 1}); p.annotee = false; p.v = Date.now(); return fermer(); }
      if (a === 'pdf') { p.dans_pdf = p.dans_pdf ? 0 : 1; await post({a: 'photo_maj', id: p.id, dans_pdf: p.dans_pdf}); return fermer(); }
      if (a === 'suppr') { if (b.dataset.ok !== '1') { b.dataset.ok = '1'; b.textContent = 'Confirmer la suppression'; return; } await post({a: 'photo_suppr', id: p.id}); PH = PH.filter(x => x !== p); m.remove(); rendrePhotos(); }
    } catch (e) { etat(e.message, true); m.remove(); } });
}
rendrePhotos();
// signature
if ($('#sig')) { let zs = null; const init = () => { if (!zs && $('#sig').offsetWidth) zs = zoneSignature($('#sig')); }; init(); $('#modele').addEventListener('change', () => setTimeout(init, 50));
  $('#sigEff').onclick = () => zs && zs.effacer();
  $('#sigOk').onclick = async () => { if (!zs || zs.vide()) { etat('Le client doit signer dans le cadre.', true); return; }
    try { await post(lire()); await post({a: 'signer', signataire: $('#signataire').value, signature: zs.image()}); location.reload(); } catch (e) { etat(e.message, true); } }; }
if ($('#rediger')) { const R = $('#rediger'), T = $('#texte'), SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  R.onclick = async () => { if (!T.value.trim()) { etat('Écris ou dicte d\'abord quelques notes.', true); return; } R.disabled = true; etat('Mise en forme en cours…');
    try { const r = await fetch('assistant.php?api=1', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': CSRF}, body: JSON.stringify({csrf: CSRF, action: 'rediger', texte: T.value, contexte: <?= json_encode(($e['titre'] ?: '') . ($e['client_nom'] ? ' – ' . $e['client_nom'] : '') . ($e['ville'] ? ' – ' . $e['ville'] : '') . ($e['debut'] ? ' – ' . date('d/m/Y', strtotime($e['debut'])) : ''), JSON_UNESCAPED_UNICODE) ?>})});
      const j = await r.json(); if (j.erreur) throw new Error(j.erreur); T.dataset.brut = T.value; T.value = j.texte; sauver(); etat('Rapport mis en forme (tes notes d\'origine sont conservées : bouton « Revenir aux notes »).'); if (!$('#retourNotes')) { const b = document.createElement('button'); b.type = 'button'; b.className = 'mini'; b.id = 'retourNotes'; b.textContent = 'Revenir aux notes'; b.onclick = () => { T.value = T.dataset.brut || ''; sauver(); b.remove(); }; R.after(' ', b); } }
    catch (err) { etat(err.message, true); } R.disabled = false; };
  if (SR) { const D = $('#dicter'); D.hidden = false; let rec = null, on = false;
    D.onclick = () => { if (on) { rec.stop(); return; } rec = new SR(); rec.lang = 'fr-FR'; rec.interimResults = true; rec.continuous = true; const base = T.value ? T.value.replace(/\s+$/, '') + '\n' : '';
      rec.onresult = ev => { let f = '', c = ''; for (const x of ev.results) (x.isFinal ? (f += x[0].transcript + ' ') : (c += x[0].transcript)); T.value = base + f + c; };
      rec.onend = () => { on = false; D.textContent = '🎤 Dicter'; sauver(); }; rec.onerror = () => { on = false; D.textContent = '🎤 Dicter'; };
      try { rec.start(); on = true; D.textContent = '⏹ Arrêter'; } catch (e) {} }; } }
if ($('#term')) $('#term').onclick = async () => { await post({a: 'terminer'}); etat('Rendez-vous marqué terminé.'); $('#term').remove(); };
const URLPDF = 'rapport.php?evt=' + ID + '&pdf=1', MAIL = <?= json_encode($e['client_email']) ?>;
$('#pdfMenu').onclick = async () => {
  if (PEUT && !SIGNE) await post(lire()).catch(() => {});
  const m = document.createElement('div'); m.className = 'menu-ph';
  m.innerHTML = `<div><a class="btn secondary" href="${URLPDF}" target="_blank" style="text-align:center">Voir le PDF</a>
    ${PEUT ? `<button class="btn secondary" data-a="porte">Envoyer sur le porte-document</button>
    <div style="display:flex;gap:6px"><input type="email" id="mMail" value="${esc(MAIL)}" placeholder="E-mail du destinataire" style="margin:0;flex:1"><button class="btn" data-a="mail">${MAIL ? 'Email' : 'Envoyer'}</button></div>` : ''}
    <button class="btn secondary" data-a="partager">Partager…</button><button class="mini" data-a="fermer" style="padding:10px">Annuler</button></div>`;
  document.body.appendChild(m);
  m.addEventListener('click', async ev => { if (ev.target === m) return m.remove(); const b = ev.target.closest('button'); if (!b) return; const a = b.dataset.a;
    if (a === 'fermer') return m.remove();
    b.disabled = true;
    try {
      if (a === 'porte') { const r = await post({a: 'porte_doc'}); etat('PDF déposé dans le porte-document du client (' + r.dossier + ').'); }
      if (a === 'mail') { const ad = m.querySelector('#mMail').value; await post({a: 'envoyer', email: ad}); etat('Envoyé à ' + ad + '.'); }
      if (a === 'partager') { const bl = await (await fetch(URLPDF)).blob(); const f = new File([bl], (<?= json_encode($fin) ?> ? 'Bon-intervention' : 'Rapport-visite') + '-' + ID + '.pdf', {type: 'application/pdf'});
        if (navigator.canShare && navigator.canShare({files: [f]})) await navigator.share({files: [f], title: <?= json_encode($e['titre']) ?>}); else window.open(URLPDF, '_blank'); }
      m.remove();
    } catch (e) { if (e.name !== 'AbortError') etat(e.message, true); m.remove(); } });
};
</script>
<?php page_end(); ?>
