<?php
// Bibliothèque des actions dans l'arbre : chaque action a une durée en heures d'équipe.
// Une prestation du catalogue se compose d'une ou plusieurs actions, indépendantes les unes des autres.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        foreach ($_POST['a'] ?? [] as $id => $d) q("UPDATE actions SET nom = ?, duree = ?, description = ?, ordre = ? WHERE id = ?", [trim($d['nom']), nb($d['duree'] ?? '0'), trim($d['description'] ?? ''), (int)($d['ordre'] ?? 0), (int)$id]);
        // Recalcule les lignes composées d'actions
        foreach (q("SELECT DISTINCT ligne_id FROM ligne_actions") as $l) { appliquer_actions((int)$l['ligne_id']); appliquer_variantes((int)$l['ligne_id']); }
        journal('catalogue', 'Actions modifiées (' . count($_POST['a'] ?? []) . ')'); flash('Actions enregistrées ; les prestations composées ont été recalculées.');
    } elseif ($a === 'add' && trim($_POST['nom'] ?? '') !== '') {
        $o = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM actions")->fetch()['o'];
        q("INSERT INTO actions (nom, duree, description, ordre) VALUES (?,?,?,?)", [trim($_POST['nom']), nb($_POST['duree'] ?? '0'), trim($_POST['description'] ?? ''), $o]);
        flash('Action ajoutée.');
    } elseif ($a === 'del') {
        $touchees = array_column(q("SELECT ligne_id FROM ligne_actions WHERE action_id = ?", [(int)$_POST['id']])->fetchAll(), 'ligne_id');
        q("UPDATE actions SET actif = 0 WHERE id = ?", [(int)$_POST['id']]); q("DELETE FROM ligne_actions WHERE action_id = ?", [(int)$_POST['id']]);
        foreach ($touchees as $lid) { appliquer_actions((int)$lid); appliquer_variantes((int)$lid); }
        flash('Action supprimée : retirée de ' . count($touchees) . ' prestation(s), dont le temps a été recalculé (une prestation qui n\'avait que cette action garde son temps actuel, modifiable à la main).');
    } elseif ($a === 'exemples') {
        $ex = [
            ['Reconnaissance et mise en place du chantier', 0.5, 'Balisage, choix des accès, installation des cordes'],
            ['Grimpe et installation dans l\'arbre', 0.5, 'Accès au houppier, pose du point d\'ancrage'],
            ['Taille d\'éclaircie du houppier', 1.5, 'Sélection et coupe des branches, par arbre moyen'],
            ['Taille de réduction d\'une charpentière', 0.75, 'Par charpentière'],
            ['Démontage d\'une charpentière avec rétention', 1.5, 'Par charpentière, descente contrôlée'],
            ['Démontage du tronc par billons', 1.0, 'Par tronçon de tronc'],
            ['Abattage dirigé au sol', 0.75, 'Entaille, trait d\'abattage, coup de pied'],
            ['Débitage des gros bois sur place', 0.75, 'Tronçonnage du bois au sol'],
            ['Broyage des branches', 0.75, 'Passage des rémanents au broyeur'],
            ['Nettoyage du site', 0.5, 'Soufflage, ramassage, remise en état'],
            ['Chargement et évacuation des rémanents', 0.75, 'Camion-benne, aller en déchèterie ou plateforme'],
            ['Haubanage : pose d\'un hauban', 1.0, 'Par hauban, hors fourniture'],
        ];
        $o = (int)q("SELECT COALESCE(MAX(ordre),0) o FROM actions")->fetch()['o']; $n = 0;
        foreach ($ex as [$nom, $d, $desc]) if (!q("SELECT id FROM actions WHERE nom = ?", [$nom])->fetch()) { q("INSERT INTO actions (nom, duree, description, ordre) VALUES (?,?,?,?)", [$nom, $d, $desc, ++$o]); $n++; }
        flash("$n actions d'exemple ajoutées : adapte leurs durées à ta pratique.");
    }
    redirect('actions.php');
}
$actions = q("SELECT a.*, (SELECT COUNT(*) FROM ligne_actions la WHERE la.action_id = a.id) nb FROM actions a WHERE actif = 1 ORDER BY ordre, nom")->fetchAll();
$equipes = ressources_equipes();
$eq = equipe_choisie(equipe_apercu());
page_start('Actions dans l\'arbre', 'actions.php');
?>
<p class="muted small">Facultatif : tu peux aussi saisir directement les heures d'équipe d'une prestation dans le catalogue. Les actions servent à construire ce temps geste par geste : dans le catalogue, menu « + action… » sur une ligne.</p>
<p class="lead">Une action = un geste dans l'arbre et sa durée en heures d'équipe (<?= $eq ? e($eq['nom']) . ' à ' . money((float)$eq['taux']) . '/h' : 'ressource « Équipe » à créer dans Paramètres' ?>). Les actions sont indépendantes : dans le catalogue, chaque prestation combine celles dont elle a besoin, et son temps d'équipe est la somme. Le coût affiché est indicatif : l'équipe réellement appliquée se choisit dans chaque bordereau, et les taux se modifient dans <a href="parametres.php">Paramètres</a>.</p>
<?php if (count($equipes) > 1): ?><form method="get" class="inline" style="margin:0 0 10px"><select name="equipe" onchange="this.form.submit()"><?php foreach ($equipes as $x): ?><option value="<?= $x['id'] ?>" <?= (int)$eq['id'] === (int)$x['id'] ? 'selected' : '' ?>>Coût avec : <?= e($x['nom']) ?> (<?= money((float)$x['taux']) ?>/h)</option><?php endforeach; ?></select></form><?php endif; ?>
<?php if (!$actions): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="exemples"><p>Aucune action pour l'instant. Tu peux partir d'une douzaine d'actions types et les adapter.</p><button class="btn" type="submit">Charger des actions d'exemple</button></form><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<table class="tbl">
<tr><th>Ord.</th><th>Action</th><th class="r">Durée (h)</th><th>Précision</th><th class="r">Coût</th><th>Utilisée</th><th></th></tr>
<?php foreach ($actions as $x): ?>
<tr>
  <td><input class="tiny" name="a[<?= $x['id'] ?>][ordre]" value="<?= $x['ordre'] ?>"></td>
  <td><input name="a[<?= $x['id'] ?>][nom]" value="<?= e($x['nom']) ?>" style="min-width:300px;width:100%"></td>
  <td class="r"><input class="num" name="a[<?= $x['id'] ?>][duree]" value="<?= e((string)(float)$x['duree']) ?>" inputmode="decimal"></td>
  <td><input name="a[<?= $x['id'] ?>][description]" value="<?= e($x['description']) ?>" style="min-width:420px;width:100%"></td>
  <td class="r out"><?= $eq ? money((float)$x['duree'] * (float)$eq['taux']) : '—' ?></td>
  <td class="muted"><?php if ($x['nb']): ?><a href="bpu.php?action_id=<?= $x['id'] ?>" title="Voir les prestations qui utilisent cette action"><?= $x['nb'] ?> prestation<?= $x['nb'] > 1 ? 's' : '' ?></a><?php else: ?>0 prestation<?php endif; ?></td>
  <td><button class="mini danger" type="submit" form="d<?= $x['id'] ?>" onclick="return confirm('Supprimer cette action ?')">×</button></td>
</tr>
<?php endforeach; ?>
</table>
<?php if ($actions): ?><button class="btn" type="submit">Enregistrer</button><?php endif; ?>
</form>
<?php foreach ($actions as $x): ?><form id="d<?= $x['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $x['id'] ?>"></form><?php endforeach; ?>
<section class="card"><h2>Ajouter une action</h2>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input name="nom" placeholder="Nom de l'action" required style="min-width:300px"> <input class="num" name="duree" placeholder="h" inputmode="decimal"> <input name="description" placeholder="Précision" style="min-width:240px"> <button class="btn secondary" type="submit">Ajouter</button>
  </form>
  <?php if ($actions): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="exemples"><button class="mini" type="submit">Ajouter les actions d'exemple manquantes</button></form><?php endif; ?>
</section>
<?php page_end(); ?>
