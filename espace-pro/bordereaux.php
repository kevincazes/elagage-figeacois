<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'creer') {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') { flash('Donne un nom au bordereau.', 'err'); redirect('bordereaux.php'); }
        q("INSERT INTO bordereaux (nom, client, notes, cree_par) VALUES (?,?,?,?)", [$nom, trim($_POST['client'] ?? ''), trim($_POST['notes'] ?? ''), user()['id']]);
        $bid = (int)$pdo->lastInsertId();
        if (!empty($_POST['modele'])) { // dupliquer un bordereau existant
            $src = (int)$_POST['modele'];
            foreach (q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? ORDER BY ordre, id", [$src]) as $l)
                q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, code, libelle, unite, ligne_id, quantite, pu_manuel) VALUES (?,?,?,?,?,?,?,?,?)", [$bid, $l['ordre'], $l['type'], $l['code'], $l['libelle'], $l['unite'], $l['ligne_id'], $l['quantite'], $l['pu_manuel']]);
        }
        redirect('bordereau.php?id=' . $bid);
    } elseif ($a === 'suppr') {
        require_admin();
        q("DELETE FROM bordereau_lignes WHERE bordereau_id = ?", [(int)$_POST['id']]); q("DELETE FROM bordereaux WHERE id = ?", [(int)$_POST['id']]);
        flash('Bordereau supprimé.'); redirect('bordereaux.php');
    }
}
$bords = q("SELECT b.*, u.prenom, (SELECT COUNT(*) FROM bordereau_lignes l WHERE l.bordereau_id = b.id AND l.type = 'ligne') nb FROM bordereaux b LEFT JOIN utilisateurs u ON u.id = b.cree_par ORDER BY b.cree_le DESC")->fetchAll();
page_start('Bordereaux de prix', 'bordereaux.php');
?>
<section class="card">
  <h2>Nouveau bordereau</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="creer">
    <div class="cols">
      <label>Nom du marché <input name="nom" placeholder="Ex. Lot Habitat – entretien du patrimoine arboré 2026" required <?= isset($_GET['nouveau']) ? 'autofocus' : '' ?>></label>
      <label>Collectivité / client <input name="client"></label>
    </div>
    <div class="cols">
      <label>Partir d'un bordereau existant (copie des lignes) <select name="modele"><option value="">— vide —</option><?php foreach ($bords as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['nom']) ?> (<?= $b['nb'] ?> lignes)</option><?php endforeach; ?></select></label>
      <label>Notes <input name="notes" placeholder="Date limite, variante, remise…"></label>
    </div>
    <button class="btn" type="submit">Créer et composer</button>
  </form>
</section>
<h2>Bordereaux</h2>
<?php if (!$bords): ?><p class="muted">Aucun bordereau pour l'instant.</p><?php else: ?>
<table class="tbl">
<tr><th>Date</th><th>Marché</th><th>Client</th><th class="r">Lignes</th><th>Statut</th><th>Par</th><th></th></tr>
<?php foreach ($bords as $b): ?>
<tr>
  <td><?= date('d/m/Y', strtotime($b['cree_le'])) ?></td>
  <td><a href="bordereau.php?id=<?= $b['id'] ?>"><strong><?= e($b['nom']) ?></strong></a></td>
  <td><?= e($b['client']) ?></td><td class="r"><?= $b['nb'] ?></td>
  <td><?= $b['statut'] === 'fige' ? '<span class="ok">Figé le ' . date('d/m/Y', strtotime($b['fige_le'])) . '</span>' : 'Brouillon' ?></td>
  <td><?= e($b['prenom'] ?? '') ?></td>
  <td class="r"><a class="mini" href="bordereau.php?id=<?= $b['id'] ?>">Ouvrir</a> <a class="mini" href="export.php?id=<?= $b['id'] ?>">Excel</a>
  <?php if (user()['role'] === 'admin'): ?><form method="post" class="inline" onsubmit="return confirm('Supprimer ce bordereau ?')"><?= csrf_field() ?><input type="hidden" name="action" value="suppr"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="mini danger">×</button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php page_end(); ?>
