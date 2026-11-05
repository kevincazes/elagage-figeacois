<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'generaux') {
        $avantCoef = setting('coef');
        set_setting('coef', (string)max(0.1, nb($_POST['coef'] ?? '1')));
        set_setting('tva', (string)max(0, nb($_POST['tva'] ?? '20')));
        set_setting('arrondi', (string)max(0, nb($_POST['arrondi'] ?? '1')));
        foreach (['h', 'd'] as $g) if (isset($_POST["c{$g}_code"])) { // classes de taille : code (stable), libellé court, libellé long, % de temps en plus
            $lignes = []; $n = count((array)$_POST["c{$g}_code"]);
            for ($k = 0; $k < $n; $k++) { $code = trim((string)($_POST["c{$g}_code"][$k] ?? '')); $court = trim((string)($_POST["c{$g}_court"][$k] ?? '')); if ($court === '') continue;
                if ($code === '' || !preg_match('/^[^|\s]{1,12}$/', $code)) $code = $g . ($k + 1) . '_' . substr(md5($court), 0, 3);
                $lignes[] = str_replace('|', '/', $code) . '|' . str_replace('|', '/', $court) . '|' . str_replace('|', '/', trim((string)($_POST["c{$g}_long"][$k] ?? '')) ?: $court) . '|' . (string)nb($_POST["c{$g}_prog"][$k] ?? '0'); }
            if ($lignes) set_setting('classes_' . $g, implode("\n", $lignes)); }
        foreach (q("SELECT DISTINCT base_id FROM bpu_lignes WHERE base_id IS NOT NULL") as $b) appliquer_variantes((int)$b['base_id']);
        journal('parametres', "Coefficient $avantCoef → " . setting('coef') . ', TVA ' . setting('tva') . ' %, arrondi ' . setting('arrondi') . ' €, progression ' . setting('prog_h2') . '/' . setting('prog_h3') . '/' . setting('prog_d2') . '/' . setting('prog_d3') . ' %'); flash('Règles de calcul enregistrées : tous les prix non figés sont recalculés.');
    } elseif ($action === 'liens') {
        redirect('liens.php');
    } elseif ($action === 'taux') {
        $avantP = ressource_equipe();
        foreach ($_POST['taux'] ?? [] as $id => $t) q("UPDATE ressources SET taux = ? WHERE id = ?", [nb($t), (int)$id]);
        foreach ($_POST['nom'] ?? [] as $id => $n) if (trim($n) !== '') q("UPDATE ressources SET nom = ? WHERE id = ?", [trim($n), (int)$id]);
        foreach ($_POST['role'] ?? [] as $id => $r) q("UPDATE ressources SET role = ? WHERE id = ?", [in_array($r, ['equipe', 'rognage', '']) ? $r : '', (int)$id]);
        // Les heures d'équipe du catalogue sont portées par l'équipe principale (la première de type Équipe) :
        // si elle change (type modifié), on y déplace les heures pour que les prix ne bougent pas.
        $nouvP = q("SELECT * FROM ressources WHERE actif = 1 AND role = 'equipe' ORDER BY ordre, id LIMIT 1")->fetch();
        if ($avantP && $nouvP && (int)$avantP['id'] !== (int)$nouvP['id']) {
            q("DELETE FROM bpu_temps WHERE ressource_id = ? AND ligne_id IN (SELECT ligne_id FROM (SELECT ligne_id FROM bpu_temps WHERE ressource_id = ?) x)", [(int)$nouvP['id'], (int)$avantP['id']]);
            q("UPDATE bpu_temps SET ressource_id = ? WHERE ressource_id = ?", [(int)$nouvP['id'], (int)$avantP['id']]);
            journal('taux', "Équipe principale : « {$avantP['nom']} » → « {$nouvP['nom']} » (heures d'équipe reportées)");
        }
        journal('taux', 'Taux horaires : ' . implode(', ', array_map(fn($id, $t) => (q("SELECT nom FROM ressources WHERE id = ?", [(int)$id])->fetch()['nom'] ?? $id) . " $t €/h", array_keys($_POST['taux'] ?? []), $_POST['taux'] ?? []))); flash('Taux horaires enregistrés.');
    } elseif ($action === 'simplifier') {
        require_admin();
        [$n, $old] = simplifier_en_equipe(max(1, nb($_POST['taux_equipe'] ?? '190')), 80.0, nb($_POST['taux_equipe2'] ?? '0'));
        journal('taux', "Passage au modèle Équipe + Rognage : $n lignes converties, $old anciennes ressources retirées");
        flash("Modèle simplifié : $n lignes converties en heures d'équipe (prix inchangés), $old anciennes ressources retirées.");
    } elseif ($action === 'ajout_ressource' && trim($_POST['nom_new'] ?? '') !== '') {
        $ordre = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM ressources")->fetch()['o'];
        q("INSERT INTO ressources (nom, taux, ordre, role) VALUES (?,?,?,?)", [trim($_POST['nom_new']), nb($_POST['taux_new'] ?? '0'), $ordre, in_array($_POST['role_new'] ?? '', ['equipe', 'rognage']) ? $_POST['role_new'] : '']);
        flash('Ressource ajoutée.');
    } elseif ($action === 'suppr_ressource') {
        $id = (int)$_POST['id'];
        if (($p = ressource_equipe()) && (int)$p['id'] === $id) { flash("Impossible de retirer l'équipe principale : elle porte les heures d'équipe de tout le catalogue. Change d'abord le type ou l'ordre des équipes.", 'err'); redirect('parametres.php'); }
        q("UPDATE ressources SET actif = 0 WHERE id = ?", [$id]);
        q("DELETE FROM bpu_temps WHERE ressource_id = ?", [$id]);
        flash('Ressource retirée.');
    }
    redirect('parametres.php');
}
$res = q("SELECT * FROM ressources WHERE actif = 1 ORDER BY ordre, id")->fetchAll();
page_start('Paramètres', 'parametres.php');
?>
<?php if (!ressource_equipe() && est_admin()): ?>
<section class="card" style="border-left:5px solid var(--anis)">
  <h2>Passer au modèle « Équipe + Rognage »</h2>
  <p>Le catalogue stocke des <strong>heures d'équipe</strong> (temps des actions dans l'arbre). Chaque bordereau choisit ensuite l'équipe qui s'applique : l'équipe standard (coût complet) ou une équipe à taux réduit pour les marchés publics. Le rognage et les moyens spécifiques (enlèvement du bois sur gros sujets, grue…) sont des ressources à part, réglées ensuite dans « Taux horaires des ressources ». Les lignes existantes sont converties en heures au taux de l'équipe standard, <strong>sans changer leurs prix</strong> ; les anciennes ressources sont retirées.</p>
  <form method="post" class="inline" onsubmit="return confirm('Convertir tout le catalogue au modèle Équipe + Rognage ?')"><?= csrf_field() ?><input type="hidden" name="action" value="simplifier">
    <span>Équipe standard</span> <input class="num" name="taux_equipe" value="190" inputmode="decimal"> <span>€/h</span>
    <span style="margin-left:14px">Équipe marché public</span> <input class="num" name="taux_equipe2" value="60" inputmode="decimal"> <span>€/h</span>
    <button class="btn" type="submit">Convertir</button>
  </form>
</section>
<?php endif; ?>
<div class="cols cols-2">
<section class="card" id="ressources">
  <h2>Taux horaires des ressources</h2>
  <p class="muted">Prix horaire de chaque moyen. « Équipe » couvre tout ce qui est toujours présent (on peut en créer plusieurs, ex. standard, intermédiaire, marché public : chaque bordereau choisit la sienne et les compare) ; les autres lignes sont des moyens spécifiques ajoutés ligne par ligne dans le catalogue.</p>
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
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generaux">
    <div class="grille-champs">
      <label for="coef">Coefficient de marge et d'aléas<small>1,25 = coût × 1,25. Couvre marge, frais généraux, aléas météo.</small></label><div><input id="coef" class="num" name="coef" value="<?= e(setting('coef')) ?>" inputmode="decimal"></div>
      <label for="tva">TVA (%)</label><div><input id="tva" class="num" name="tva" value="<?= e(setting('tva')) ?>" inputmode="decimal"></div>
      <label for="arrondi">Arrondi des prix unitaires (€)<small>1 = à l'euro ; 5 = aux 5 € ; 0 = au centime.</small></label><div><input id="arrondi" class="num" name="arrondi" value="<?= e(setting('arrondi')) ?>" inputmode="decimal"></div>
    </div>
    <h2>Progression du temps selon la taille de l'arbre</h2>
    <p class="muted">Pour chaque classe, le pourcentage de temps en plus par rapport à la première classe (0 %). Les deux facteurs se multiplient : un arbre de 10–20 m à +80 % avec un houppier de 5–10 m à +50 % demande 1,8 × 1,5 = 2,7 fois le temps de base. Sert à générer et recalculer les variantes du catalogue et au rapprochement des lignes importées. Tu peux ajouter des classes (ex. houppier > 20 m) ; ne retire une classe que si aucune ligne ne l'utilise.</p>
    <?php foreach (['h' => ['Hauteur de l\'arbre', classes_h()], 'd' => ['Largeur du houppier', classes_d()]] as $g => [$titre, $cls]): ?>
    <h3 style="margin:12px 0 4px"><?= $titre ?></h3>
    <div style="overflow-x:auto"><table class="tbl edit" id="tc<?= $g ?>"><tr><th>Libellé court</th><th>Libellé dans le catalogue</th><th>Temps en plus</th><th></th></tr>
    <?php foreach ($cls as $c): ?><tr><td><input type="hidden" name="c<?= $g ?>_code[]" value="<?= e($c['code']) ?>"><input name="c<?= $g ?>_court[]" value="<?= e($c['court']) ?>" style="max-width:150px" required></td><td><input name="c<?= $g ?>_long[]" value="<?= e($c['long']) ?>"></td><td style="white-space:nowrap">+ <input class="num" name="c<?= $g ?>_prog[]" value="<?= e((string)$c['prog']) ?>" inputmode="decimal" style="width:80px"> %</td><td><button type="button" class="mini danger" onclick="this.closest('tr').remove()" title="Retirer">✕</button></td></tr><?php endforeach; ?>
    </table></div>
    <p><button type="button" class="mini" onclick="const t=document.querySelector('#tc<?= $g ?>').tBodies[0]; t.appendChild(document.querySelector('#lc<?= $g ?>').content.cloneNode(true)); t.querySelector('tr:last-child input[name$=\'_court[]\']').focus()">+ Ajouter une classe</button></p>
    <template id="lc<?= $g ?>"><tr><td><input type="hidden" name="c<?= $g ?>_code[]" value=""><input name="c<?= $g ?>_court[]" placeholder="Ex. > 30 m" style="max-width:150px" required></td><td><input name="c<?= $g ?>_long[]" placeholder="Ex. arbre de plus de 30 m"></td><td style="white-space:nowrap">+ <input class="num" name="c<?= $g ?>_prog[]" value="0" inputmode="decimal" style="width:80px"> %</td><td><button type="button" class="mini danger" onclick="this.closest('tr').remove()">✕</button></td></tr></template>
    <?php endforeach; ?>
    <button class="btn" type="submit">Enregistrer les règles</button>
  </form>
</section>
</div>
<?php page_end(); ?>
