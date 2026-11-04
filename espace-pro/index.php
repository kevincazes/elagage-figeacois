<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$nbLignes = (int)q("SELECT COUNT(*) c FROM bpu_lignes WHERE actif = 1")->fetch()['c'];
$nbGrilles = (int)q("SELECT COUNT(*) c FROM grilles")->fetch()['c'];
$derniere = q("SELECT * FROM grilles ORDER BY cree_le DESC LIMIT 1")->fetch();
$liens = [
    ['Google Drive', setting('lien_drive'), 'Dossiers, devis, photos de chantier'],
    ['Extrabat', setting('lien_extrabat'), 'Gestion commerciale et espace client'],
    ['Urgence Élagage', setting('lien_urgence'), 'Dispositif collectivités'],
    ['Arbogestion', setting('lien_arbogestion'), 'Gestion du patrimoine arboré'],
];
page_start('Tableau de bord', 'index.php');
?>
<p class="lead">Bonjour <?= e(user()['prenom']) ?>. Que veux-tu faire ?</p>
<div class="tiles">
  <a class="tile" href="bpu.php"><strong><?= $nbLignes ?></strong><span>lignes de bordereau</span><em>Modifier les temps et voir les prix</em></a>
  <a class="tile" href="grilles.php?nouvelle=1"><strong>+</strong><span>Nouvelle grille</span><em>Figer les prix pour un marché</em></a>
  <a class="tile" href="grilles.php"><strong><?= $nbGrilles ?></strong><span>grilles enregistrées</span><em><?= $derniere ? 'Dernière : ' . e($derniere['nom']) : 'Aucune pour l\'instant' ?></em></a>
  <a class="tile" href="parametres.php"><strong><?= e(setting('coef')) ?></strong><span>coefficient actuel</span><em>Taux horaires, TVA, arrondi</em></a>
</div>
<h2>Outils de l'entreprise</h2>
<div class="tiles">
<?php foreach ($liens as [$n, $url, $d]): ?>
  <?php if ($url): ?><a class="tile link" href="<?= e($url) ?>" target="_blank" rel="noopener"><span><?= e($n) ?></span><em><?= e($d) ?></em></a>
  <?php else: ?><div class="tile link off"><span><?= e($n) ?></span><em>Lien à renseigner dans Paramètres</em></div><?php endif; ?>
<?php endforeach; ?>
</div>
<?php page_end(); ?>
