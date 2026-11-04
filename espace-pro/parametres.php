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
        foreach (['prog_h2', 'prog_h3', 'prog_d2', 'prog_d3'] as $k) if (isset($_POST[$k])) set_setting($k, (string)(float)str_replace(',', '.', $_POST[$k]));
        foreach (q("SELECT DISTINCT base_id FROM bpu_lignes WHERE base_id IS NOT NULL") as $b) appliquer_variantes((int)$b['base_id']);
        foreach (['lien_drive', 'lien_extrabat', 'lien_urgence', 'lien_arbogestion'] as $k) set_setting($k, trim($_POST[$k] ?? ''));
        journal('parametres', 'Coefficient ' . setting('coef') . ' → ' . $_POST['coef'] . ', TVA ' . $_POST['tva'] . ', arrondi ' . $_POST['arrondi']); flash('Paramètres enregistrés.');
    } elseif ($action === 'taux') {
        foreach ($_POST['taux'] ?? [] as $id => $t) q("UPDATE ressources SET taux = ? WHERE id = ?", [(float)str_replace(',', '.', $t), (int)$id]);
        foreach ($_POST['nom'] ?? [] as $id => $n) if (trim($n) !== '') q("UPDATE ressources SET nom = ? WHERE id = ?", [trim($n), (int)$id]);
        foreach ($_POST['role'] ?? [] as $id => $r) q("UPDATE ressources SET role = ? WHERE id = ?", [in_array($r, ['equipe', 'rognage', '']) ? $r : '', (int)$id]);
        journal('taux', 'Taux horaires : ' . implode(', ', array_map(fn($id, $t) => (q("SELECT nom FROM ressources WHERE id = ?", [(int)$id])->fetch()['nom'] ?? $id) . " $t €/h", array_keys($_POST['taux'] ?? []), $_POST['taux'] ?? []))); flash('Taux horaires enregistrés.');
    } elseif ($action === 'simplifier') {
        require_admin();
        [$n, $old] = simplifier_en_equipe(max(1, (float)str_replace(',', '.', $_POST['taux_equipe'] ?? '190')), max(1, (float)str_replace(',', '.', $_POST['taux_rognage'] ?? '80')), (float)str_replace(',', '.', $_POST['taux_equipe2'] ?? '0'));
        journal('taux', "Passage au modèle Équipe + Rognage : $n lignes converties, $old anciennes ressources retirées");
        flash("Modèle simplifié : $n lignes converties en heures d'équipe (prix inchangés), $old anciennes ressources retirées.");
    } elseif ($action === 'ajout_ressource' && trim($_POST['nom_new'] ?? '') !== '') {
        $ordre = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM ressources")->fetch()['o'];
        q("INSERT INTO ressources (nom, taux, ordre, role) VALUES (?,?,?,?)", [trim($_POST['nom_new']), (float)str_replace(',', '.', $_POST['taux_new'] ?? '0'), $ordre, in_array($_POST['role_new'] ?? '', ['equipe', 'rognage']) ? $_POST['role_new'] : '']);
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
<?php if (!ressource_equipe() && user()['role'] === 'admin'): ?>
<section class="card" style="border-left:5px solid var(--anis)">
  <h2>Passer au modèle « Équipe + Rognage »</h2>
  <p>Le catalogue stocke des <strong>heures d'équipe</strong> (temps des actions dans l'arbre). Chaque bordereau choisit ensuite l'équipe qui s'applique : l'équipe standard (coût complet) ou une équipe à taux réduit pour les marchés publics. Le rognage est à part, et tu pourras ajouter des moyens spécifiques (enlèvement du bois sur gros sujets, grue…) comme ressources supplémentaires. Les lignes existantes sont converties en heures au taux de l'équipe standard, <strong>sans changer leurs prix</strong> ; les anciennes ressources sont retirées.</p>
  <form method="post" class="inline" onsubmit="return confirm('Convertir tout le catalogue au modèle Équipe + Rognage ?')"><?= csrf_field() ?><input type="hidden" name="action" value="simplifier">
    <span>Équipe standard</span> <input class="num" name="taux_equipe" value="190" inputmode="decimal"> <span>€/h</span>
    <span style="margin-left:14px">Équipe marché public</span> <input class="num" name="taux_equipe2" value="60" inputmode="decimal"> <span>€/h</span>
    <span style="margin-left:14px">Rognage</span> <input class="num" name="taux_rognage" value="80" inputmode="decimal"> <span>€/h</span>
    <button class="btn" type="submit">Convertir</button>
  </form>
</section>
<?php endif; ?>
<div class="cols cols-2">
<section class="card">
  <h2>Taux horaires des ressources</h2>
  <p class="muted">Prix horaire de chaque moyen. « Équipe » couvre tout ce qui est toujours présent ; les autres lignes sont des moyens spécifiques ajoutés ligne par ligne dans le catalogue.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="taux">
  <div class="scroll"><table class="tbl">
    <tr><th>Ressource</th><th>Type</th><th class="r">€ HT / heure</th><th></th></tr>
    <?php foreach ($res as $r): ?>
    <tr>
      <td style="min-width:240px"><input name="nom[<?= $r['id'] ?>]" value="<?= e($r['nom']) ?>"></td>
      <td><select name="role[<?= $r['id'] ?>]" style="width:auto"><option value="" <?= $r['role'] === '' ? 'selected' : '' ?>>Moyen spécifique</option><option value="equipe" <?= $r['role'] === 'equipe' ? 'selected' : '' ?>>Équipe</option><option value="rognage" <?= $r['role'] === 'rognage' ? 'selected' : '' ?>>Rognage</option></select></td>
      <td class="r"><input class="num" name="taux[<?= $r['id'] ?>]" value="<?= e($r['taux']) ?>" inputmode="decimal"></td>
      <td style="white-space:nowrap"><button class="mini danger" type="submit" form="del<?= $r['id'] ?>" onclick="return confirm('Retirer cette ressource ? Ses temps seront effacés des lignes.')">×</button></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <button class="btn" type="submit">Enregistrer les taux</button>
  </form>
  <?php foreach ($res as $r): ?><form id="del<?= $r['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="suppr_ressource"><input type="hidden" name="id" value="<?= $r['id'] ?>"></form><?php endforeach; ?>
  <h2>Ajouter une ressource</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="ajout_ressource">
    <input name="nom_new" placeholder="Ex. Évacuation du bois – camion grappin" style="min-width:300px"> <select name="role_new"><option value="">Moyen spécifique</option><option value="equipe">Équipe</option><option value="rognage">Rognage</option></select> <input class="num" name="taux_new" placeholder="€/h" inputmode="decimal"> <button class="btn secondary" type="submit">Ajouter</button>
  </form>
</section>

<section class="card">
  <h2>Règles de calcul</h2>
    <div class="grille-champs">
      <label for="coef">Coefficient de marge et d'aléas</label><div><input id="coef" class="num" name="coef" value="<?= e(setting('coef')) ?>" inputmode="decimal"><small>1,25 = coût × 1,25. Couvre marge, frais généraux, aléas météo.</small></div>
      <label for="tva">TVA (%)</label><div><input id="tva" class="num" name="tva" value="<?= e(setting('tva')) ?>" inputmode="decimal"></div>
      <label for="arrondi">Arrondi des prix unitaires (€)</label><div><input id="arrondi" class="num" name="arrondi" value="<?= e(setting('arrondi')) ?>" inputmode="decimal"><small>1 = à l'euro ; 5 = aux 5 € ; 0 = au centime.</small></div>
    </div>
    <h2>Progression du temps selon la taille de l'arbre</h2>
    <p class="muted">Pourcentage de temps en plus par rapport à la classe de base (moins de 10 m, houppier ≤ 5 m). Sert à générer et recalculer les variantes du catalogue.</p>
    <div class="grille-champs">
      <label for="ph2">Arbre de 10 à 20 m</label><div>+ <input id="ph2" class="num" name="prog_h2" value="<?= e(setting('prog_h2', '80')) ?>" inputmode="decimal"> %</div>
      <label for="ph3">Arbre de plus de 20 m</label><div>+ <input id="ph3" class="num" name="prog_h3" value="<?= e(setting('prog_h3', '200')) ?>" inputmode="decimal"> %</div>
      <label for="pd2">Houppier de 5 à 10 m</label><div>+ <input id="pd2" class="num" name="prog_d2" value="<?= e(setting('prog_d2', '50')) ?>" inputmode="decimal"> %</div>
      <label for="pd3">Houppier de plus de 10 m</label><div>+ <input id="pd3" class="num" name="prog_d3" value="<?= e(setting('prog_d3', '120')) ?>" inputmode="decimal"> %</div>
    </div>
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
