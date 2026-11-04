<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
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
  <a class="tile" href="bpu.php"><strong><?= $nbLignes ?></strong><span>prestations au catalogue</span><em>Temps, taux, prix de référence</em></a>
  <a class="tile" href="bordereaux.php?nouveau=1"><strong>+</strong><span>Nouveau bordereau</span><em>Composer au format de la collectivité</em></a>
  <a class="tile" href="bordereaux.php"><strong><?= $nbBord ?></strong><span>bordereaux</span><em><?= $dernier ? 'Dernier : ' . e($dernier['nom']) : 'Aucun pour l\'instant' ?></em></a>
  <a class="tile" href="parametres.php"><strong><?= e(setting('coef')) ?></strong><span>coefficient actuel</span><em>Taux horaires, TVA, arrondi</em></a>
</div>
<h2>Outils de l'entreprise</h2>
<div class="tiles">
<?php foreach ($liens as [$n, $url, $d, $ic]): $img = $ic ? '<img class="tile-ico" src="' . e($ic) . '" alt="" loading="lazy">' : ''; ?>
  <?php if ($url): ?><a class="tile link" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= $img ?><span><?= e($n) ?></span><em><?= e($d) ?></em></a>
  <?php else: ?><div class="tile link off"><?= $img ?><span><?= e($n) ?></span><em>Lien à renseigner dans Paramètres</em></div><?php endif; ?>
<?php endforeach; ?>
</div>
<?php page_end(); ?>
