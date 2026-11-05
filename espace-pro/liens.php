<?php
// Liens des outils de l'entreprise : adresses ouvertes par les tuiles du tableau de bord (et par le bouton Kleyk de Mon planning)
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_admin();
const LIENS_OUTILS = [
    'lien_drive' => ['Google Drive', 'Dossiers, devis, photos de chantier', 'https://drive.google.com/…'],
    'lien_extrabat' => ['Extrabat', 'Gestion commerciale et espace client', 'https://…'],
    'lien_urgence' => ['Urgence Élagage', 'Dispositif collectivités', 'https://urgence-elagage.fr'],
    'lien_arbogestion' => ['Arbogestion', 'Gestion du patrimoine arboré', 'https://arbogestion.fr'],
    'lien_grality' => ['Grality', 'Inventaire et suivi des arbres', 'https://…'],
    'lien_kleyk' => ['Kleyk', "Fiches d'intervention (bouton de Mon planning)", 'https://…'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mauvais = [];
    foreach (LIENS_OUTILS as $k => [$nom]) { $v = trim((string)($_POST[$k] ?? ''));
        if ($v !== '' && !preg_match('#^https?://\S+$#i', $v)) { if (preg_match('#^[\w.-]+\.[a-z]{2,}(/\S*)?$#i', $v)) $v = 'https://' . $v; else { $mauvais[] = $nom; continue; } }
        set_setting($k, $v); }
    journal('parametres', 'Liens des outils modifiés');
    flash($mauvais ? 'Enregistré, sauf : ' . implode(', ', $mauvais) . ' (adresse à vérifier, elle doit commencer par https://).' : 'Liens enregistrés : les tuiles du tableau de bord sont à jour.');
    redirect('liens.php');
}
page_start('Liens des outils', 'liens.php');
?>
<p class="lead">Les adresses ouvertes par les tuiles « Outils de l'entreprise » du tableau de bord. Colle l'adresse de la page d'accueil (ou de connexion) de chaque outil ; une tuile sans adresse reste grisée.</p>
<h2>Outils de l'entreprise</h2>
<form method="post"><?= csrf_field() ?>
<div class="scroll"><table class="tbl liens-tbl" style="width:100%"><thead><tr><th>Outil</th><th>Adresse</th><th>Vérifier</th></tr></thead><tbody>
<?php foreach (LIENS_OUTILS as $k => [$nom, $desc, $ph]): $v = setting($k); ?>
<tr><td style="min-width:230px"><strong><?= e($nom) ?></strong><br><span class="muted small"><?= e($desc) ?></span></td>
  <td style="width:100%"><input name="<?= $k ?>" value="<?= e($v) ?>" placeholder="<?= e($ph) ?>" style="margin:0"></td>
  <td style="white-space:nowrap"><?php if ($v): ?><a class="mini" href="<?= e($v) ?>" target="_blank" rel="noopener">Tester ↗</a><?php else: ?><span class="muted small">—</span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p><button class="btn">Enregistrer les liens</button></p>
<p class="small muted">Kleyk : la même adresse sert au bouton « Fiche d'intervention Kleyk » de Mon planning ; sur téléphone, l'adresse web ouvre l'application si elle est installée.</p>
</form>
<?php page_end(); ?>
