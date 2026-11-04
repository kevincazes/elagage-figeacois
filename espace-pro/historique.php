<?php
// Journal global : bordereaux, catalogue, taux, paramètres
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$noms = []; foreach (q("SELECT id, prenom, nom FROM utilisateurs") as $u) $noms[$u['id']] = trim($u['prenom'] . ' ' . $u['nom']);
$bords = []; foreach (q("SELECT id, nom FROM bordereaux") as $b) $bords[$b['id']] = $b['nom'];
$hist = q("SELECT * FROM historique ORDER BY quand DESC, id DESC LIMIT 300")->fetchAll();
$LIB = ['creation' => 'Création', 'entete' => 'En-tête', 'ajout' => 'Ajout', 'modif' => 'Modification', 'suppression' => 'Suppression', 'figer' => 'Figé', 'defiger' => 'Défigé', 'verrou_force' => 'Verrou forcé', 'parametres' => 'Paramètres', 'taux' => 'Taux horaires', 'catalogue' => 'Catalogue'];
page_start('Historique', 'historique.php');
?>
<p class="lead">Les 300 dernières actions, tous dossiers confondus.</p>
<div class="scroll"><table class="tbl">
<tr><th>Quand</th><th>Qui</th><th>Dossier</th><th>Action</th><th>Détail</th></tr>
<?php foreach ($hist as $h): ?>
<tr><td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($h['quand'])) ?></td><td><?= e($noms[$h['utilisateur_id']] ?? '—') ?></td>
<td><?= $h['bordereau_id'] ? '<a href="bordereau.php?id=' . $h['bordereau_id'] . '">' . e($bords[$h['bordereau_id']] ?? '(supprimé)') . '</a>' : '<span class="muted">général</span>' ?></td>
<td><?= e($LIB[$h['action']] ?? $h['action']) ?></td><td class="small" style="white-space:pre-line"><?= e($h['detail']) ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php page_end(); ?>
