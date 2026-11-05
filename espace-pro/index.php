<?php
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
planning_taches();
$moiPl = q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1 LIMIT 1", [user()['id']])->fetch();
$nbAuj = (int)q("SELECT COUNT(*) c FROM evenements WHERE statut <> 'annule' AND debut < ? AND fin > ?", [date('Y-m-d 23:59:59'), date('Y-m-d 00:00:00')])->fetch()['c'];
$nbAPl = (int)q("SELECT COUNT(*) c FROM evenements WHERE statut = 'a_planifier'")->fetch()['c'];
$nbMoi = $moiPl ? (int)q("SELECT COUNT(*) c FROM evenements e JOIN evenement_personnel ep ON ep.evenement_id = e.id WHERE ep.personnel_id = ? AND e.statut <> 'annule' AND e.fin > NOW() AND e.debut < ?", [$moiPl['id'], date('Y-m-d 00:00:00', strtotime('+7 days'))])->fetch()['c'] : 0;
$alertesPl = planning_niveau() === 'tout' ? array_filter(planning_alertes(), fn($a) => $a['niv'] === 'haut') : [];
$nbLignes = (int)q("SELECT COUNT(*) c FROM bpu_lignes WHERE actif = 1")->fetch()['c'];
$nbBord = (int)q("SELECT COUNT(*) c FROM bordereaux")->fetch()['c'];
$dernier = q("SELECT * FROM bordereaux ORDER BY cree_le DESC LIMIT 1")->fetch();
// Icône d'un outil : logo local si on l'a, sinon l'icône officielle du site (service de favicons de Google)
$icone = function (string $url, string $local = ''): string {
    if ($local) return $local;
    $h = parse_url($url, PHP_URL_HOST); return $h ? 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($h) : '';
};
$liens = [
    ['Google Drive', setting('lien_drive'), 'Dossiers, devis, photos de chantier', $icone(setting('lien_drive') ?: 'https://drive.google.com')],
    ['Extrabat', setting('lien_extrabat'), 'Gestion commerciale et espace client', '../images/logo-extrabat.png'],
    ['Urgence Élagage', setting('lien_urgence'), 'Dispositif collectivités', '../images/logo-urgence-elagage.png'],
    ['Arbogestion', setting('lien_arbogestion'), 'Gestion du patrimoine arboré', '../images/logo-arbogestion.png'],
];
page_start('Tableau de bord', 'index.php');
?>
<p class="lead">Bonjour <?= e(user()['prenom']) ?>. Que veux-tu faire ?</p>
<div class="tiles">
  <a class="tile" href="planning.php"><strong><?= $nbAuj ?></strong><span>intervention(s) aujourd'hui</span><em>Planning des chantiers et rendez-vous</em></a>
  <a class="tile" href="mon-planning.php"><strong><?= $moiPl ? $nbMoi : '📅' ?></strong><span><?= $moiPl ? 'dans mon planning (7 jours)' : 'Mon planning' ?></span><em>Mes chantiers, adresses et itinéraires</em></a>
  <?php if (planning_niveau() === 'tout'): ?><a class="tile" href="planning.php?vue=liste"><strong><?= $nbAPl ?></strong><span>à planifier</span><em>Devis signés et visites en attente de date</em></a>
  <a class="tile" href="charge.php"><strong>📊</strong><span>Tableau de charge</span><em>Statuts, chiffre d'affaires planifié, occupation</em></a>
  <a class="tile" href="contrats.php"><strong><?= (int)q("SELECT COUNT(*) c FROM contrats WHERE statut = 'signe'")->fetch()['c'] ?></strong><span>contrats d'entretien</span><em>Signature en ligne, passages automatiques</em></a><?php endif; ?>
  <a class="tile" href="materiel.php"><strong><?= count(array_filter(materiel_echeances(), fn($x) => $x['retard'])) ?></strong><span>matériel : retard(s)</span><em>Contrôles, entretiens, pannes</em></a>
  <?php if (planning_niveau() === 'tout'): $ech = livret_echeances(90); ?><a class="tile" href="livret.php"><strong><?= count($ech) ?></strong><span>habilitation(s) à renouveler (90 j)</span><em>Livrets du personnel : SST, CACES, formations</em></a><?php endif; ?>
  <a class="tile" href="clients.php"><strong><?= (int)q("SELECT COUNT(*) c FROM clients WHERE actif = 1")->fetch()['c'] ?></strong><span>clients</span><em>Contacts, adresses, porte-doc</em></a>
  <a class="tile" href="conges.php"><strong><?= planning_niveau() === 'tout' ? (int)q("SELECT COUNT(*) c FROM conges WHERE statut = 'demande'")->fetch()['c'] : '🌴' ?></strong><span><?= planning_niveau() === 'tout' ? 'demande(s) de congés' : 'Mes congés' ?></span><em>Demander, valider, poser au planning</em></a>
</div>
<?php if ($alertesPl): ?><div class="flash err" style="margin-top:4px"><strong>Planning : <?= count($alertesPl) ?> point(s) à regarder</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach (array_slice($alertesPl, 0, 5) as $a): ?><li><?= $a['id'] ? '<a href="planning.php?fiche=' . $a['id'] . '">' . e($a['txt']) . '</a>' : (!empty($a['url']) ? '<a href="' . e($a['url']) . '">' . e($a['txt']) . '</a>' : e($a['txt'])) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (acces_chiffrage()): ?>
<h2>Chiffrage</h2>
<div class="tiles">
  <a class="tile" href="bpu.php"><strong><?= $nbLignes ?></strong><span>prestations au catalogue</span><em>Temps, taux, prix de référence</em></a>
  <a class="tile" href="importer.php"><strong>⇪</strong><span>Importer un bordereau reçu</span><em>Le BPU / DQE de la collectivité, chiffré automatiquement</em></a>
  <a class="tile" href="bordereaux.php#nouveau"><strong>+</strong><span>Nouveau bordereau</span><em>Vide ou à partir d'un pack</em></a>
  <a class="tile" href="bordereaux.php"><strong><?= $nbBord ?></strong><span>bordereaux</span><em><?= $dernier ? 'Dernier : ' . e($dernier['nom']) : 'Aucun pour l\'instant' ?></em></a>
  <?php if (est_admin()): ?><a class="tile" href="parametres.php"><strong><?= e(setting('coef')) ?></strong><span>coefficient actuel</span><em>Taux des équipes, marge, arrondi</em></a><?php endif; ?>
</div>
<?php endif; ?>
<h2>Outils de l'entreprise</h2>
<div class="tiles">
<?php foreach ($liens as [$n, $url, $d, $ic]): $img = $ic ? '<img class="tile-ico" src="' . e($ic) . '" alt="" loading="lazy">' : ''; ?>
  <?php if ($url): ?><a class="tile link" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= $img ?><span><?= e($n) ?></span><em><?= e($d) ?></em></a>
  <?php else: ?><div class="tile link off"><?= $img ?><span><?= e($n) ?></span><em><?= est_admin() ? 'Lien à renseigner dans Paramètres' : 'Lien pas encore renseigné' ?></em></div><?php endif; ?>
<?php endforeach; ?>
</div>
<?php page_end(); ?>
