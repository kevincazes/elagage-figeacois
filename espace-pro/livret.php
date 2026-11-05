<?php
// Livret du salarié : fiche, titres et habilitations (validité, scans), plan de formation, documents. Liste des échéances pour l'administration.
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$gereTout = planning_niveau() === 'tout';
$pid = (int)($_GET['p'] ?? 0);
if (!$pid && !$gereTout) { $m = q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1 ORDER BY id LIMIT 1", [user()['id']])->fetch(); if ($m) redirect('livret.php?p=' . $m['id']); }
$acces = $pid ? livret_acces($pid) : ($gereTout ? 'gere' : '');
if ($acces === '') { http_response_code(403); page_start('Accès réservé', 'planning.php'); echo '<p class="lead">Le livret du salarié est consultable par la personne concernée et géré par l\'administration et le secrétariat.</p>'; page_end(); exit; }
$gere = $acces === 'gere';
$types = livret_types();
if (isset($_GET['doc']) || isset($_GET['scan'])) { // téléchargement d'un document ou du scan d'un titre
    $r = isset($_GET['doc']) ? q("SELECT fichier, nom, personnel_id FROM salarie_docs WHERE id = ?", [(int)$_GET['doc']])->fetch() : q("SELECT fichier, fichier_nom AS nom, personnel_id FROM salarie_titres WHERE id = ?", [(int)$_GET['scan']])->fetch();
    $f = $r && livret_acces((int)$r['personnel_id']) !== '' ? chemin_fichier($r['fichier']) : null;
    if (!$f) { http_response_code(404); exit('Document introuvable'); }
    $tm = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png']; $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($tm[$ext] ?? 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: ' . (isset($tm[$ext]) ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\n"], '', $r['nom'] ?: basename($f)) . '"'); readfile($f); exit;
}
$fichierPost = function (string $champ): array { // [nom stocké, nom d'origine]
    $f = $_FILES[$champ] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) return ['', ''];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)); $ext = $ext === 'jpeg' ? 'jpg' : $ext; if (!in_array($ext, ['pdf', 'jpg', 'png', 'docx', 'heic'], true)) { flash('Type de fichier non accepté (PDF, photo, Word).', 'err'); return ['', '']; }
    $n = 'liv-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . ".$ext"; move_uploaded_file($f['tmp_name'], dossier_fichiers() . "/$n"); return [$n, mb_substr(basename((string)$f['name']), 0, 200)];
};
$dt = fn($v) => ($v = trim((string)$v)) !== '' && ($t = strtotime($v)) ? date('Y-m-d', $t) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pid) {
    $a = $_POST['action'] ?? ''; $retour = "livret.php?p=$pid&o=" . ($_POST['o'] ?? 'titres');
    if (!$gere && $a !== 'doc' && $a !== 'titre_scan') { flash('Lecture seule.', 'err'); redirect($retour); } // le salarié peut déposer un scan ou un document
    if ($a === 'fiche') { q("UPDATE personnel SET poste = ?, entree_le = ?, contrat = ?, notes_livret = ? WHERE id = ?", [mb_substr(trim($_POST['poste'] ?? ''), 0, 80), $dt($_POST['entree_le'] ?? ''), mb_substr(trim($_POST['contrat'] ?? ''), 0, 40), mb_substr(trim($_POST['notes_livret'] ?? ''), 0, 5000), $pid]); flash('Fiche enregistrée.'); }
    elseif ($a === 'titre') {
        $code = array_key_exists($_POST['code'] ?? '', $types) ? $_POST['code'] : ''; $lib = mb_substr(trim($_POST['libelle'] ?? ''), 0, 150) ?: ($types[$code]['libelle'] ?? ''); if ($lib === '') { flash('Indique le titre.', 'err'); redirect($retour); }
        [$fic, $ficNom] = $fichierPost('scan'); $id = (int)($_POST['id'] ?? 0);
        $vals = [$code, $lib, $dt($_POST['obtenu_le'] ?? ''), $dt($_POST['valide_jusqua'] ?? ''), mb_substr(trim($_POST['organisme'] ?? ''), 0, 150), mb_substr(trim($_POST['reference'] ?? ''), 0, 80), mb_substr(trim($_POST['precision_t'] ?? ''), 0, 150), mb_substr(trim($_POST['notes'] ?? ''), 0, 500)];
        if ($id) { $anc = q("SELECT * FROM salarie_titres WHERE id = ? AND personnel_id = ?", [$id, $pid])->fetch(); if (!$anc) redirect($retour);
            q("UPDATE salarie_titres SET code = ?, libelle = ?, obtenu_le = ?, valide_jusqua = ?, organisme = ?, reference = ?, precision_t = ?, notes = ?" . ($fic ? ", fichier = ?, fichier_nom = ?" : '') . " WHERE id = ?", array_merge($vals, $fic ? [$fic, $ficNom] : [], [$id]));
            if ($fic && $anc['fichier'] && ($c = chemin_fichier($anc['fichier']))) @unlink($c); }
        else q("INSERT INTO salarie_titres (personnel_id, code, libelle, obtenu_le, valide_jusqua, organisme, reference, precision_t, notes, fichier, fichier_nom, cree_par) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", array_merge([$pid], $vals, [$fic, $ficNom, user()['id']]));
        $s = livret_competences_sync($pid); flash('Titre enregistré.' . ($s['ajout'] ? ' Compétence ajoutée au planning : ' . implode(', ', $s['ajout']) . '.' : '') . ($s['retrait'] ? ' Compétence retirée (titre expiré) : ' . implode(', ', $s['retrait']) . '.' : ''));
    }
    elseif ($a === 'titre_scan') { $t = q("SELECT * FROM salarie_titres WHERE id = ? AND personnel_id = ?", [(int)$_POST['id'], $pid])->fetch(); [$fic, $ficNom] = $fichierPost('scan');
        if ($t && $fic) { q("UPDATE salarie_titres SET fichier = ?, fichier_nom = ? WHERE id = ?", [$fic, $ficNom, $t['id']]); if ($t['fichier'] && ($c = chemin_fichier($t['fichier']))) @unlink($c); flash('Scan ajouté.'); } }
    elseif ($a === 'titre_suppr') { $t = q("SELECT * FROM salarie_titres WHERE id = ? AND personnel_id = ?", [(int)$_POST['id'], $pid])->fetch(); if ($t) { if ($t['fichier'] && ($c = chemin_fichier($t['fichier']))) @unlink($c); q("DELETE FROM salarie_titres WHERE id = ?", [$t['id']]); livret_competences_sync($pid); flash('Titre supprimé.'); } }
    elseif ($a === 'formation') {
        $int = mb_substr(trim($_POST['intitule'] ?? ''), 0, 150); $code = array_key_exists($_POST['code'] ?? '', $types) ? $_POST['code'] : ''; if ($int === '' && $code) $int = $types[$code]['libelle']; if ($int === '') { flash("Indique l'intitulé.", 'err'); redirect($retour); }
        $cout = ($x = trim($_POST['cout'] ?? '')) !== '' ? nb($x) : null; $id = (int)($_POST['id'] ?? 0); $vals = [$int, $code, $dt($_POST['prevue_le'] ?? ''), $dt($_POST['fin_le'] ?? ''), mb_substr(trim($_POST['organisme'] ?? ''), 0, 150), $cout, mb_substr(trim($_POST['notes'] ?? ''), 0, 500)];
        if ($id) q("UPDATE salarie_formations SET intitule = ?, code = ?, prevue_le = ?, fin_le = ?, organisme = ?, cout = ?, notes = ? WHERE id = ? AND personnel_id = ?", array_merge($vals, [$id, $pid]));
        else q("INSERT INTO salarie_formations (personnel_id, intitule, code, prevue_le, fin_le, organisme, cout, notes) VALUES (?,?,?,?,?,?,?,?)", array_merge([$pid], $vals));
        flash('Formation enregistrée.');
    }
    elseif ($a === 'formation_statut') { $f = q("SELECT * FROM salarie_formations WHERE id = ? AND personnel_id = ?", [(int)$_POST['id'], $pid])->fetch(); $st = in_array($_POST['statut'] ?? '', ['prevue', 'realisee', 'annulee'], true) ? $_POST['statut'] : 'prevue';
        if ($f) { q("UPDATE salarie_formations SET statut = ? WHERE id = ?", [$st, $f['id']]);
            if ($st === 'realisee' && $f['code'] && !$f['titre_id']) { // formation faite → le titre correspondant est créé (ou renouvelé) avec sa validité
                $ty = $types[$f['code']] ?? null; $obt = $f['fin_le'] ?: ($f['prevue_le'] ?: date('Y-m-d')); $fin = $ty && $ty['mois'] ? date('Y-m-d', strtotime("$obt +{$ty['mois']} months")) : null;
                q("INSERT INTO salarie_titres (personnel_id, code, libelle, obtenu_le, valide_jusqua, organisme, notes, cree_par) VALUES (?,?,?,?,?,?,?,?)", [$pid, $f['code'], $ty['libelle'] ?? $f['intitule'], $obt, $fin, $f['organisme'], 'Issu de la formation du ' . date('d/m/Y', strtotime($obt)), user()['id']]);
                q("UPDATE salarie_formations SET titre_id = ? WHERE id = ?", [(int)db()->lastInsertId(), $f['id']]); livret_competences_sync($pid); flash('Formation réalisée : le titre a été ajouté au livret avec sa date de validité.'); }
            else flash('Statut mis à jour.'); } }
    elseif ($a === 'formation_suppr') { q("DELETE FROM salarie_formations WHERE id = ? AND personnel_id = ?", [(int)$_POST['id'], $pid]); flash('Formation supprimée.'); }
    elseif ($a === 'doc') { [$fic, $ficNom] = $fichierPost('fichier'); if ($fic) { q("INSERT INTO salarie_docs (personnel_id, dossier, nom, fichier, taille, cree_par) VALUES (?,?,?,?,?,?)", [$pid, in_array($_POST['dossier'] ?? '', DOSSIERS_LIVRET, true) ? $_POST['dossier'] : 'Autres', $ficNom, $fic, (int)($_FILES['fichier']['size'] ?? 0), user()['id']]); flash('Document ajouté.'); } elseif (empty($_FILES['fichier']['name'])) flash("Le fichier n'est pas arrivé (taille maximale : " . ini_get('upload_max_filesize') . ').', 'err'); }
    elseif ($a === 'doc_suppr') { $d = q("SELECT * FROM salarie_docs WHERE id = ? AND personnel_id = ?", [(int)$_POST['id'], $pid])->fetch(); if ($d) { if ($c = chemin_fichier($d['fichier'])) @unlink($c); q("DELETE FROM salarie_docs WHERE id = ?", [$d['id']]); flash('Document supprimé.'); } }
    redirect($retour);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pid && $gereTout && ($_POST['action'] ?? '') === 'types' && est_admin()) { set_setting('livret_titres', trim($_POST['livret_titres'] ?? '')); flash('Liste des titres enregistrée.'); redirect('livret.php#types'); }

$p = $pid ? q("SELECT * FROM personnel WHERE id = ?", [$pid])->fetch() : null;
if ($pid && !$p) { flash('Personne introuvable.', 'err'); redirect('livret.php'); }
$ETATS = ['valide' => ['Valide', 'v'], 'bientot' => ['Expire bientôt', 'o'], 'expire' => ['Expiré', 'r'], 'sans' => ['Sans limite', 'g']];
page_start($p ? 'Livret de ' . $p['prenom'] . ' ' . $p['nom'] : 'Livrets du personnel', 'livret.php', $p && $gereTout ? ['livret.php', 'Livrets du personnel'] : null);
?>
<style>.onglets{display:flex;gap:4px;overflow-x:auto;background:#ece8df;border-radius:12px;padding:4px;margin:0 0 14px}.onglets a{padding:8px 14px;border-radius:9px;text-decoration:none;color:var(--texte);font-weight:700;white-space:nowrap}.onglets a.on{background:#efe3d5;color:var(--brun)}
.lig{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #eee}.lig:last-child{border:0}.lig .d{flex:1;min-width:0}.lig .d small{color:var(--doux);display:block}.tag{border-radius:999px;padding:2px 9px;font-size:.75rem;font-weight:700;color:#fff;background:#8caa31;white-space:nowrap}.tag.r{background:#e6332a}.tag.o{background:#c77a12}.tag.g{background:#9aa5b1}.tag.v{background:#8caa31}
details.card summary{cursor:pointer;font-weight:700}.lig form{display:inline}.lig .mini{margin-left:4px}.recap td,.recap th{vertical-align:middle}
@media(max-width:700px){.lig{flex-wrap:wrap}}</style>

<?php if (!$p): // ---------- Vue d'ensemble (administration) ---------- ?>
<p class="lead">Un livret par salarié : titres et habilitations avec leur validité (SST, CACES, autorisations de conduite…), scans des diplômes, plan de formation, documents. Un titre valide donne la compétence au planning ; expiré, il la retire.</p>
<?php $ech = livret_echeances(90); if ($ech): ?><section class="card"><h2>Échéances à 90 jours <span class="muted">(<?= count($ech) ?>)</span></h2>
<?php foreach ($ech as $x): ?><div class="lig"><?= $x['retard'] ? '⛔' : '⏰' ?><div class="d"><a href="livret.php?p=<?= $x['personnel_id'] ?>&o=titres"><b><?= e($x['qui']) ?></b> – <?= e($x['lib']) ?></a><small><?= $x['retard'] ? 'Expiré depuis le ' : 'Expire le ' ?><?= date('d/m/Y', strtotime($x['date'])) ?></small></div></div><?php endforeach; ?></section><?php endif; ?>
<section class="card"><h2>Personnel</h2><div style="overflow-x:auto"><table class="tbl recap"><tr><th>Salarié</th><th>Poste</th><th>Entrée</th><th>Titres</th><th>Formations prévues</th><th></th></tr>
<?php foreach (personnel_liste(true) as $x): $tt = livret_titres((int)$x['id']); $n = ['valide' => 0, 'bientot' => 0, 'expire' => 0, 'sans' => 0]; foreach ($tt as $t) $n[$t['etat']]++; $nf = (int)q("SELECT COUNT(*) c FROM salarie_formations WHERE personnel_id = ? AND statut = 'prevue'", [$x['id']])->fetch()['c']; ?>
<tr<?= (int)$x['actif'] ? '' : ' style="opacity:.55"' ?>><td><a href="livret.php?p=<?= $x['id'] ?>"><b><?= e($x['prenom'] . ' ' . $x['nom']) ?></b></a><?= (int)$x['actif'] ? '' : ' <span class="small muted">(inactif)</span>' ?></td><td><?= e($x['poste']) ?></td><td><?= $x['entree_le'] ? date('d/m/Y', strtotime($x['entree_le'])) : '—' ?></td>
<td><?= $tt ? ($n['valide'] + $n['sans'] ? '<span class="tag v">' . ($n['valide'] + $n['sans']) . ' ok</span> ' : '') . ($n['bientot'] ? '<span class="tag o">' . $n['bientot'] . ' bientôt</span> ' : '') . ($n['expire'] ? '<span class="tag r">' . $n['expire'] . ' expiré(s)</span>' : '') : '<span class="muted small">aucun</span>' ?></td><td><?= $nf ?: '—' ?></td><td><a class="mini" href="livret.php?p=<?= $x['id'] ?>">Ouvrir</a></td></tr>
<?php endforeach; ?></table></div></section>
<?php if (est_admin()): ?><form method="post" class="card" id="types"><?= csrf_field() ?><input type="hidden" name="action" value="types"><h2 style="margin-top:0">Types de titres et habilitations</h2>
<label>Un par ligne : <code>code|libellé|validité en mois (0 = sans limite)|compétence du planning liée (grimpeur, nacelle, pl, abattage, broyeur, rogneuse, chef – facultatif)</code> <textarea name="livret_titres" rows="12" style="font-family:monospace;font-size:.85rem"><?= e(setting('livret_titres')) ?></textarea></label>
<p class="small muted">La validité pré-remplit la date de fin quand on saisit un titre. La compétence liée est ajoutée au planning tant que le titre est valide et retirée à l'expiration. Le code ne doit pas changer une fois utilisé.</p><button class="btn">Enregistrer</button></form><?php endif; ?>

<?php else: // ---------- Livret d'une personne ---------- ?>
<?php $o = $_GET['o'] ?? 'titres'; $titres = livret_titres($pid); $forms = livret_formations($pid); $docs = livret_docs($pid); $echP = array_filter($titres, fn($t) => in_array($t['etat'], ['bientot', 'expire'], true)); ?>
<p><?= e($p['poste']) ?><?= $p['entree_le'] ? ' · entré(e) le ' . date('d/m/Y', strtotime($p['entree_le'])) : '' ?><?= $p['contrat'] ? ' · ' . e($p['contrat']) : '' ?> · compétences au planning : <strong><?= e(implode(', ', array_map(fn($c) => COMPETENCES[$c] ?? $c, array_filter(explode(',', (string)$p['competences'])))) ?: 'aucune') ?></strong><?= $gereTout ? ' · <a class="mini" href="planning-ressources.php#personnel">Personnel</a>' : '' ?> · <a class="mini" href="https://elagage-hevea.com" target="_blank" rel="noopener">EPI : Vérif EPI ↗</a></p>
<?php if ($echP): ?><div class="flash <?= array_filter($echP, fn($t) => $t['etat'] === 'expire') ? 'err' : '' ?>"><?php foreach ($echP as $t): ?><div><?= $t['etat'] === 'expire' ? '⛔' : '⏰' ?> <?= e($t['libelle']) ?><?= $t['precision_t'] ? ' (' . e($t['precision_t']) . ')' : '' ?> : <?= $t['etat'] === 'expire' ? 'expiré depuis le' : 'expire le' ?> <?= date('d/m/Y', strtotime($t['valide_jusqua'])) ?></div><?php endforeach; ?></div><?php endif; ?>
<nav class="onglets"><?php foreach (['titres' => 'Titres et habilitations (' . count($titres) . ')', 'formations' => 'Plan de formation (' . count(array_filter($forms, fn($f) => $f['statut'] === 'prevue')) . ')', 'docs' => 'Documents (' . count($docs) . ')', 'fiche' => 'Fiche'] as $k => $l): ?><a href="?p=<?= $pid ?>&o=<?= $k ?>" class="<?= $o === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>

<?php if ($o === 'titres'): ?>
<?php foreach ($titres as $t): ?><div class="lig"><?= ['valide' => '✅', 'bientot' => '⏰', 'expire' => '⛔', 'sans' => '📜'][$t['etat']] ?><div class="d"><b><?= e($t['libelle']) ?></b><?= $t['precision_t'] ? ' – ' . e($t['precision_t']) : '' ?> <span class="tag <?= $ETATS[$t['etat']][1] ?>"><?= $ETATS[$t['etat']][0] ?></span>
  <small><?= $t['obtenu_le'] ? 'Obtenu le ' . date('d/m/Y', strtotime($t['obtenu_le'])) : '' ?><?= $t['valide_jusqua'] ? ' · valable jusqu\'au ' . date('d/m/Y', strtotime($t['valide_jusqua'])) : '' ?><?= $t['organisme'] ? ' · ' . e($t['organisme']) : '' ?><?= $t['reference'] ? ' · n° ' . e($t['reference']) : '' ?><?= $t['notes'] ? '<br>' . e($t['notes']) : '' ?></small>
  <?php if ($t['fichier']): ?><a class="mini" href="livret.php?scan=<?= $t['id'] ?>" target="_blank">📎 <?= e($t['fichier_nom'] ?: 'Scan') ?></a><?php else: ?><form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:4px;align-items:center;margin-top:4px"><?= csrf_field() ?><input type="hidden" name="action" value="titre_scan"><input type="hidden" name="o" value="titres"><input type="hidden" name="id" value="<?= $t['id'] ?>"><label class="small muted" style="margin:0">Joindre le scan / la photo <input type="file" name="scan" accept=".pdf,.jpg,.jpeg,.png,.heic" style="width:auto;font-size:.8rem" onchange="this.form.submit()" capture="environment"></label></form><?php endif; ?></div>
  <?php if ($gere): ?><div><button type="button" class="mini" onclick="modifierTitre(<?= e(json_encode($t)) ?>)">Modifier</button><form method="post" onsubmit="return confirm('Supprimer ce titre ?')"><?= csrf_field() ?><input type="hidden" name="action" value="titre_suppr"><input type="hidden" name="o" value="titres"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="mini danger">✕</button></form></div><?php endif; ?></div>
<?php endforeach; if (!$titres): ?><p class="muted">Aucun titre enregistré.</p><?php endif; ?>
<?php if ($gere): ?><form method="post" enctype="multipart/form-data" class="card" id="fTitre" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="titre"><input type="hidden" name="o" value="titres"><input type="hidden" name="id" value="0" id="tId"><h3 style="margin-top:0" id="tTitre">Ajouter un titre / une habilitation</h3>
  <div class="cols"><label>Type <select name="code" id="tCode"><option value="">— autre (libellé libre) —</option><?php foreach ($types as $c => $ty): ?><option value="<?= $c ?>" data-mois="<?= $ty['mois'] ?>"><?= e($ty['libelle']) ?><?= $ty['mois'] ? ' (' . $ty['mois'] . ' mois)' : '' ?></option><?php endforeach; ?></select></label><label>Libellé (si autre, ou pour préciser) <input name="libelle" id="tLib" placeholder="Ex. CACES R486 catégorie B"></label></div>
  <div class="cols"><label>Précision (engin, catégorie…) <input name="precision_t" id="tPrec" placeholder="Ex. Nacelle Haulotte, catégorie 3B"></label><label>Organisme / formateur <input name="organisme" id="tOrg"></label></div>
  <div class="cols"><label>Obtenu le <input type="date" name="obtenu_le" id="tObt"></label><label>Valable jusqu'au <span class="small muted">(calculé d'après le type, modifiable)</span> <input type="date" name="valide_jusqua" id="tFin"></label></div>
  <div class="cols"><label>N° / référence <input name="reference" id="tRef"></label><label>Scan ou photo du diplôme <input type="file" name="scan" accept=".pdf,.jpg,.jpeg,.png,.heic,.docx"></label></div>
  <label>Notes <input name="notes" id="tNotes"></label>
  <button class="btn">Enregistrer</button> <button type="button" class="mini" id="tAnnul" hidden onclick="location.href='livret.php?p=<?= $pid ?>&o=titres'">Annuler la modification</button></form>
<script>
const calcFin = () => { const o = document.querySelector('#tCode').selectedOptions[0], m = o ? +o.dataset.mois : 0, d = document.querySelector('#tObt').value; if (!m || !d) return; const x = new Date(d); x.setMonth(x.getMonth() + m); document.querySelector('#tFin').value = x.toISOString().slice(0, 10); };
document.querySelector('#tCode').addEventListener('change', calcFin); document.querySelector('#tObt').addEventListener('change', calcFin);
function modifierTitre(t) { document.querySelector('#tId').value = t.id; document.querySelector('#tCode').value = t.code; document.querySelector('#tLib').value = t.libelle; document.querySelector('#tPrec').value = t.precision_t; document.querySelector('#tOrg').value = t.organisme; document.querySelector('#tObt').value = t.obtenu_le || ''; document.querySelector('#tFin').value = t.valide_jusqua || ''; document.querySelector('#tRef').value = t.reference; document.querySelector('#tNotes').value = t.notes; document.querySelector('#tTitre').textContent = 'Modifier : ' + t.libelle; document.querySelector('#tAnnul').hidden = false; document.querySelector('#fTitre').scrollIntoView({behavior: 'smooth'}); }
</script><?php endif; ?>

<?php elseif ($o === 'formations'): ?>
<?php $sugg = []; foreach ($titres as $t) if (in_array($t['etat'], ['bientot', 'expire'], true) && $t['code'] && !array_filter($forms, fn($f) => $f['statut'] === 'prevue' && $f['code'] === $t['code'])) $sugg[] = $t; ?>
<?php if ($sugg && $gere): ?><div class="flash"><b>À prévoir</b> (titres qui expirent sans formation planifiée) : <?= e(implode(', ', array_map(fn($t) => $t['libelle'], $sugg))) ?> — le formulaire ci-dessous pré-remplit le premier.</div><?php endif; ?>
<?php foreach ($forms as $f): ?><div class="lig"><?= ['prevue' => '📅', 'realisee' => '✅', 'annulee' => '✖'][$f['statut']] ?? '' ?><div class="d"><b><?= e($f['intitule']) ?></b> <span class="tag <?= ['prevue' => 'o', 'realisee' => 'v', 'annulee' => 'g'][$f['statut']] ?>"><?= ['prevue' => 'Prévue', 'realisee' => 'Réalisée', 'annulee' => 'Annulée'][$f['statut']] ?></span>
  <small><?= $f['prevue_le'] ? date('d/m/Y', strtotime($f['prevue_le'])) : 'date à fixer' ?><?= $f['fin_le'] ? ' → ' . date('d/m/Y', strtotime($f['fin_le'])) : '' ?><?= $f['organisme'] ? ' · ' . e($f['organisme']) : '' ?><?= $f['cout'] !== null ? ' · ' . money((float)$f['cout']) . ' HT' : '' ?><?= $f['notes'] ? '<br>' . e($f['notes']) : '' ?></small></div>
  <?php if ($gere): ?><div><?php if ($f['statut'] === 'prevue'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="formation_statut"><input type="hidden" name="o" value="formations"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="mini" name="statut" value="realisee" title="Crée le titre correspondant dans le livret">✓ Réalisée</button> <button class="mini" name="statut" value="annulee">Annulée</button></form><?php endif; ?><button type="button" class="mini" onclick="modifierFormation(<?= e(json_encode($f)) ?>)">Modifier</button><form method="post" onsubmit="return confirm('Supprimer ?')"><?= csrf_field() ?><input type="hidden" name="action" value="formation_suppr"><input type="hidden" name="o" value="formations"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="mini danger">✕</button></form></div><?php endif; ?></div>
<?php endforeach; if (!$forms): ?><p class="muted">Aucune formation.</p><?php endif; ?>
<?php if ($gere): $s0 = $sugg[0] ?? null; ?><form method="post" class="card" id="fForm" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="formation"><input type="hidden" name="o" value="formations"><input type="hidden" name="id" value="0" id="fId"><h3 style="margin-top:0" id="fTitreF">Prévoir une formation</h3>
  <div class="cols"><label>Titre visé <select name="code" id="fCode"><option value="">— autre —</option><?php foreach ($types as $c => $ty): ?><option value="<?= $c ?>" <?= $s0 && $s0['code'] === $c ? 'selected' : '' ?>><?= e($ty['libelle']) ?></option><?php endforeach; ?></select></label><label>Intitulé <input name="intitule" id="fInt" placeholder="Ex. Recyclage SST"></label></div>
  <div class="cols"><label>Date prévue <input type="date" name="prevue_le" id="fDeb"></label><label>Fin (si plusieurs jours) <input type="date" name="fin_le" id="fFin"></label></div>
  <div class="cols"><label>Organisme <input name="organisme" id="fOrg"></label><label>Coût HT (€) <input name="cout" id="fCout" inputmode="decimal"></label></div>
  <label>Notes <input name="notes" id="fNotes"></label><button class="btn">Enregistrer</button></form>
<script>function modifierFormation(f) { document.querySelector('#fId').value = f.id; document.querySelector('#fCode').value = f.code; document.querySelector('#fInt').value = f.intitule; document.querySelector('#fDeb').value = f.prevue_le || ''; document.querySelector('#fFin').value = f.fin_le || ''; document.querySelector('#fOrg').value = f.organisme; document.querySelector('#fCout').value = f.cout ?? ''; document.querySelector('#fNotes').value = f.notes; document.querySelector('#fTitreF').textContent = 'Modifier : ' + f.intitule; document.querySelector('#fForm').scrollIntoView({behavior: 'smooth'}); }</script><?php endif; ?>

<?php elseif ($o === 'docs'): ?>
<form method="post" enctype="multipart/form-data" class="card cols" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="doc"><input type="hidden" name="o" value="docs"><label>Ajouter un document (PDF, photo, Word) <input type="file" name="fichier" required accept=".pdf,.jpg,.jpeg,.png,.heic,.docx"></label><label>Dossier <select name="dossier"><?php foreach (DOSSIERS_LIVRET as $d): ?><option><?= e($d) ?></option><?php endforeach; ?></select></label><button class="btn">Ajouter</button></form>
<?php $dos = []; foreach ($docs as $d) $dos[$d['dossier']][] = $d; foreach ($titres as $t) if ($t['fichier']) $dos['Diplômes et formations'][] = ['id' => 0, 'nom' => $t['libelle'] . ($t['precision_t'] ? ' – ' . $t['precision_t'] : ''), 'url' => "livret.php?scan={$t['id']}", 'cree_le' => $t['obtenu_le'] ?: $t['cree_le']]; ?>
<?php foreach (DOSSIERS_LIVRET as $d): $l = $dos[$d] ?? []; ?><details class="card" <?= $l ? 'open' : '' ?>><summary>📁 <?= e($d) ?> <span class="muted small"><?= $l ? count($l) . ' élément(s)' : 'vide' ?></span></summary>
  <?php foreach ($l as $x): ?><div class="lig">📄<div class="d"><a href="<?= e($x['url'] ?? "livret.php?doc={$x['id']}") ?>" target="_blank"><?= e($x['nom']) ?></a><small><?= $x['cree_le'] ? date('d/m/Y', strtotime($x['cree_le'])) : '' ?></small></div><?php if (!empty($x['id']) && $gere): ?><form method="post" onsubmit="return confirm('Supprimer ?')"><?= csrf_field() ?><input type="hidden" name="action" value="doc_suppr"><input type="hidden" name="o" value="docs"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="mini danger">✕</button></form><?php endif; ?></div><?php endforeach; ?></details><?php endforeach; ?>

<?php else: ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="fiche"><input type="hidden" name="o" value="fiche">
  <div class="cols"><label>Poste <input name="poste" value="<?= e($p['poste']) ?>" <?= $gere ? '' : 'readonly' ?>></label><label>Date d'entrée <input type="date" name="entree_le" value="<?= e((string)$p['entree_le']) ?>" <?= $gere ? '' : 'readonly' ?>></label></div>
  <div class="cols"><label>Contrat <input name="contrat" value="<?= e($p['contrat']) ?>" placeholder="CDI, CDD, apprenti…" <?= $gere ? '' : 'readonly' ?>></label><label>Compte de l'espace pro <input value="<?= e($p['utilisateur_id'] ? (q("SELECT CONCAT(prenom, ' ', nom, ' – ', email) n FROM utilisateurs WHERE id = ?", [$p['utilisateur_id']])->fetch()['n'] ?? '') : 'non relié') ?>" readonly></label></div>
  <label>Notes (poste, parcours, souhaits de formation…) <textarea name="notes_livret" rows="4" <?= $gere ? '' : 'readonly' ?>><?= e((string)$p['notes_livret']) ?></textarea></label>
  <p class="small muted">Pas de données médicales ni disciplinaires ici : seulement les éléments de qualification et de poste. Le suivi des EPI reste dans Vérif EPI.</p>
  <?php if ($gere): ?><button class="btn">Enregistrer</button><?php endif; ?></form>
<?php endif; endif; ?>
<?php page_end(); ?>
