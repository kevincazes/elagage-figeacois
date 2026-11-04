<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'generaux') {
        set_setting('coef', (string)max(0.1, (float)str_replace(',', '.', $_POST['coef'] ?? '1')));
        set_setting('tva', (string)max(0, (float)str_replace(',', '.', $_POST['tva'] ?? '20')));
        set_setting('arrondi', (string)max(0, (float)str_replace(',', '.', $_POST['arrondi'] ?? '1')));
        foreach (['lien_drive', 'lien_extrabat', 'lien_urgence', 'lien_arbogestion'] as $k) set_setting($k, trim($_POST[$k] ?? ''));
        flash('Paramètres enregistrés.');
    } elseif ($action === 'taux') {
        foreach ($_POST['taux'] ?? [] as $id => $t) q("UPDATE ressources SET taux = ? WHERE id = ?", [(float)str_replace(',', '.', $t), (int)$id]);
        foreach ($_POST['nom'] ?? [] as $id => $n) if (trim($n) !== '') q("UPDATE ressources SET nom = ? WHERE id = ?", [trim($n), (int)$id]);
        flash('Taux horaires enregistrés.');
    } elseif ($action === 'ajout_ressource' && trim($_POST['nom_new'] ?? '') !== '') {
        $ordre = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM ressources")->fetch()['o'];
        q("INSERT INTO ressources (nom, taux, ordre) VALUES (?,?,?)", [trim($_POST['nom_new']), (float)str_replace(',', '.', $_POST['taux_new'] ?? '0'), $ordre]);
        flash('Ressource ajoutée.');
    } elseif ($action === 'suppr_ressource') {
        $id = (int)$_POST['id'];
        q("UPDATE ressources SET actif = 0 WHERE id = ?", [$id]);
        q("DELETE FROM bpu_temps WHERE ressource_id = ?", [$id]);
        flash('Ressource retirée.');
    }
    redirect('parametres.php');
}
$res = q("SELECT * FROM ressources WHERE actif = 1 ORDER BY ordre, id")->fetchAll();
page_start('Paramètres', 'parametres.php');
?>
<div class="cols">
<section class="card">
  <h2>Taux horaires des ressources</h2>
  <p class="muted">Coût horaire réel ou coût de revient de chaque moyen. C'est la base de tous les prix.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="taux">
  <table class="tbl">
    <tr><th>Ressource</th><th class="r">€ HT / heure</th><th></th></tr>
    <?php foreach ($res as $r): ?>
    <tr>
      <td><input name="nom[<?= $r['id'] ?>]" value="<?= e($r['nom']) ?>"></td>
      <td class="r"><input class="num" name="taux[<?= $r['id'] ?>]" value="<?= e($r['taux']) ?>" inputmode="decimal"></td>
      <td><button class="mini danger" type="submit" form="del<?= $r['id'] ?>" onclick="return confirm('Retirer cette ressource ? Ses temps seront effacés des lignes.')">×</button></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <button class="btn" type="submit">Enregistrer les taux</button>
  </form>
  <?php foreach ($res as $r): ?><form id="del<?= $r['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="suppr_ressource"><input type="hidden" name="id" value="<?= $r['id'] ?>"></form><?php endforeach; ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="ajout_ressource">
    <input name="nom_new" placeholder="Nouvelle ressource (ex. Grue)"> <input class="num" name="taux_new" placeholder="€/h" inputmode="decimal"> <button class="btn secondary" type="submit">Ajouter</button>
  </form>
</section>

<section class="card">
  <h2>Règles de calcul</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generaux">
    <label>Coefficient de marge et d'aléas <input class="num" name="coef" value="<?= e(setting('coef')) ?>" inputmode="decimal"><small>1,25 = coût × 1,25. Couvre marge, frais généraux, aléas météo.</small></label>
    <label>TVA (%) <input class="num" name="tva" value="<?= e(setting('tva')) ?>" inputmode="decimal"></label>
    <label>Arrondi des prix unitaires (€) <input class="num" name="arrondi" value="<?= e(setting('arrondi')) ?>" inputmode="decimal"><small>1 = à l'euro ; 5 = aux 5 € ; 0 = au centime.</small></label>
    <h2>Liens du tableau de bord</h2>
    <label>Google Drive <input name="lien_drive" value="<?= e(setting('lien_drive')) ?>" placeholder="https://drive.google.com/…"></label>
    <label>Extrabat <input name="lien_extrabat" value="<?= e(setting('lien_extrabat')) ?>" placeholder="https://…"></label>
    <label>Urgence Élagage <input name="lien_urgence" value="<?= e(setting('lien_urgence')) ?>"></label>
    <label>Arbogestion <input name="lien_arbogestion" value="<?= e(setting('lien_arbogestion')) ?>"></label>
    <button class="btn" type="submit">Enregistrer</button>
  </form>
</section>
</div>
<?php page_end(); ?>
