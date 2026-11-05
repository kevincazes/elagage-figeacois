<?php
// Demandes de congés : le salarié demande, le responsable accepte ou refuse ; l'absence est posée au planning
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$gere = planning_niveau() === 'tout';
$moi = q("SELECT * FROM personnel WHERE utilisateur_id = ? AND actif = 1 ORDER BY id LIMIT 1", [user()['id']])->fetch();
const STATUTS_CONGE = ['demande' => ['En attente', '#c77a12'], 'accepte' => ['Accepté', '#4f9d5d'], 'refuse' => ['Refusé', '#e6332a'], 'annule' => ['Annulé', '#8a8f96']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? ''; $cid = (int)($_POST['id'] ?? 0);
    if ($a === 'demander') {
        $pid = $gere && !empty($_POST['personnel_id']) ? (int)$_POST['personnel_id'] : (int)($moi['id'] ?? 0);
        $tdu = strtotime((string)($_POST['du'] ?? '')); $tau = strtotime((string)($_POST['au'] ?? '')) ?: $tdu;
        $du = $tdu ? date('Y-m-d', $tdu) : ''; $au = $tau ? date('Y-m-d', $tau) : '';
        if (!$pid) flash("Ton compte n'est relié à aucune fiche du personnel : demande à l'administrateur de le relier.", 'err');
        elseif ($du === '' || $au < $du) flash('Dates invalides.', 'err');
        else {
            q("INSERT INTO conges (personnel_id, du, au, demi, type, motif, demande_par) VALUES (?,?,?,?,?,?,?)", [$pid, $du, $au, in_array($_POST['demi'] ?? '', ['', 'matin', 'apres'], true) ? $_POST['demi'] : '', array_key_exists($_POST['type'] ?? '', TYPES_CONGES) ? $_POST['type'] : 'conges', mb_substr(trim((string)($_POST['motif'] ?? '')), 0, 255), user()['id']]);
            $nid = (int)db()->lastInsertId();
            $p = q("SELECT prenom FROM personnel WHERE id = ?", [$pid])->fetch();
            if ($gere && !empty($_POST['direct'])) { conge_accepter($nid, 'Posé par ' . user()['prenom']); flash('Absence posée au planning.'); }
            else { notifier_gestionnaires('Demande de congés : ' . ($p['prenom'] ?? ''), 'Du ' . date('d/m', strtotime($du)) . ' au ' . date('d/m', strtotime($au)), 'conges.php'); flash('Demande envoyée. Tu seras prévenu de la réponse.'); }
        }
    } elseif ($a === 'accepter' && $gere) { $m = conge_accepter($cid, trim((string)($_POST['reponse'] ?? ''))); flash($m ?: 'Congés acceptés : absence posée au planning.', $m ? 'err' : 'ok'); }
    elseif ($a === 'refuser' && $gere) {
        $c = q("SELECT * FROM conges WHERE id = ?", [$cid])->fetch();
        if ($c && $c['statut'] === 'demande') { q("UPDATE conges SET statut = 'refuse', traite_par = ?, traite_le = NOW(), reponse = ? WHERE id = ?", [user()['id'], mb_substr(trim((string)($_POST['reponse'] ?? '')), 0, 255), $cid]);
            if ($c['demande_par']) notifier([(int)$c['demande_par']], 'Congés refusés', 'Du ' . date('d/m', strtotime($c['du'])) . ' au ' . date('d/m', strtotime($c['au'])) . (trim((string)($_POST['reponse'] ?? '')) ? ' – ' . trim($_POST['reponse']) : ''), 'conges.php'); flash('Demande refusée.'); }
    } elseif ($a === 'annuler') {
        $c = q("SELECT * FROM conges WHERE id = ?", [$cid])->fetch();
        if ($c && ($gere || ((int)$c['demande_par'] === (int)user()['id'] && $c['statut'] === 'demande'))) {
            if ($c['evenement_id']) { q("DELETE FROM evenement_personnel WHERE evenement_id = ?", [$c['evenement_id']]); q("DELETE FROM evenements WHERE id = ? AND type = 'absence'", [$c['evenement_id']]); }
            q("UPDATE conges SET statut = 'annule', evenement_id = NULL WHERE id = ?", [$cid]); flash('Demande annulée.');
        }
    }
    redirect('conges.php');
}
$pers = personnel_liste(); $noms = []; foreach (personnel_liste(true) as $p) $noms[(int)$p['id']] = trim($p['prenom'] . ' ' . $p['nom']);
$attente = $gere ? q("SELECT * FROM conges WHERE statut = 'demande' ORDER BY du")->fetchAll() : [];
$liste = $gere ? q("SELECT * FROM conges WHERE au >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND statut <> 'demande' ORDER BY du DESC LIMIT 100")->fetchAll()
               : q("SELECT * FROM conges WHERE demande_par = ? OR personnel_id = ? ORDER BY du DESC LIMIT 60", [user()['id'], (int)($moi['id'] ?? 0)])->fetchAll();
$jours = function (array $c): string { $n = 0; for ($t = strtotime($c['du']); $t <= strtotime($c['au']); $t = strtotime('+1 day', $t)) if ((int)date('N', $t) <= 5 && !est_ferie(date('Y-m-d', $t))) $n++; return $c['demi'] !== '' ? max(0.5, $n - 0.5) . ' j' : $n . ' j'; };
page_start('Congés', 'conges.php', ['planning.php', 'Planning']);
?>
<p class="lead">Demande tes congés ici : le responsable est prévenu sur son téléphone, et une fois acceptés ils apparaissent au planning (personne ne pourra t'affecter un chantier ces jours-là).</p>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="demander">
  <h3 style="margin-top:0"><?= $gere ? 'Nouvelle demande ou absence' : 'Nouvelle demande' ?></h3>
  <div class="cols">
    <?php if ($gere): ?><label>Personne <select name="personnel_id"><?php foreach ($pers as $p): ?><option value="<?= $p['id'] ?>" <?= $moi && (int)$moi['id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['prenom'] . ' ' . $p['nom']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>Type <select name="type"><?php foreach (TYPES_CONGES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label>Du <input type="date" name="du" required></label><label>Au <input type="date" name="au" required></label>
    <label>Demi-journée <select name="demi"><option value="">Journées entières</option><option value="matin">Seulement le matin du dernier jour</option><option value="apres">À partir de l'après-midi du premier jour</option></select></label>
  </div>
  <label>Commentaire <input name="motif" placeholder="Facultatif"></label>
  <p><button class="btn"><?= $gere ? 'Envoyer la demande' : 'Demander' ?></button> <?php if ($gere): ?><label style="display:inline-flex;gap:6px;align-items:center;font-weight:700"><input type="checkbox" name="direct" value="1" style="width:auto;margin:0"> Accepter directement (poser l'absence)</label><?php endif; ?></p>
</form>
<?php if ($attente): ?><h2>À traiter (<?= count($attente) ?>)</h2>
  <?php foreach ($attente as $c): ?><div class="card" style="border-left:5px solid #c77a12"><strong><?= e($noms[(int)$c['personnel_id']] ?? '?') ?></strong> · <?= e(TYPES_CONGES[$c['type']] ?? '') ?> · du <?= date('d/m/Y', strtotime($c['du'])) ?> au <?= date('d/m/Y', strtotime($c['au'])) ?> (<?= $jours($c) ?>)<?= $c['motif'] ? '<br><span class="muted">« ' . e($c['motif']) . ' »</span>' : '' ?>
    <?php $conf = q("SELECT COUNT(*) n FROM evenements ev JOIN evenement_personnel ep ON ep.evenement_id = ev.id WHERE ep.personnel_id = ? AND ev.statut <> 'annule' AND ev.type <> 'absence' AND ev.debut < ? AND ev.fin > ?", [$c['personnel_id'], date('Y-m-d', strtotime($c['au'] . ' +1 day')), $c['du']])->fetch()['n']; if ($conf): ?><br><span style="color:#e6332a">⚠ <?= $conf ?> intervention(s) déjà prévue(s) pour cette personne sur la période.</span><?php endif; ?>
    <form method="post" class="cols" style="align-items:end;margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><label>Réponse (facultative) <input name="reponse"></label><button class="btn" name="action" value="accepter">Accepter</button><button class="mini danger" name="action" value="refuser">Refuser</button></form></div>
  <?php endforeach; ?><?php endif; ?>
<h2><?= $gere ? 'Congés récents et à venir' : 'Mes demandes' ?></h2>
<?php if (!$liste): ?><p class="muted">Aucune demande.</p><?php endif; ?>
<div class="scroll"><table class="tbl"><?php foreach ($liste as $c): [$sl, $sc] = STATUTS_CONGE[$c['statut']]; ?>
  <tr><td><?= e($noms[(int)$c['personnel_id']] ?? '?') ?></td><td><?= e(TYPES_CONGES[$c['type']] ?? '') ?></td><td>du <?= date('d/m/Y', strtotime($c['du'])) ?> au <?= date('d/m/Y', strtotime($c['au'])) ?> (<?= $jours($c) ?>)</td>
  <td><span style="color:<?= $sc ?>;font-weight:700"><?= $sl ?></span><?= $c['reponse'] ? '<br><span class="small">' . e($c['reponse']) . '</span>' : '' ?></td>
  <td><?php if ($c['statut'] !== 'annule' && ($gere || $c['statut'] === 'demande')): ?><form method="post" onsubmit="return confirm('Annuler cette demande ?')"><?= csrf_field() ?><input type="hidden" name="action" value="annuler"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="mini">Annuler</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></table></div>
<?php page_end(); ?>
