<?php
// Packs : groupes de lignes du catalogue à ajouter d'un coup dans un bordereau
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'creer' && trim($_POST['nom'] ?? '') !== '') {
        q("INSERT INTO packs (nom, description) VALUES (?,?)", [trim($_POST['nom']), trim($_POST['description'] ?? '')]);
        flash('Pack créé : coche maintenant ses lignes.'); redirect('packs.php?id=' . (int)$pdo->lastInsertId());
    } elseif ($a === 'lignes') {
        $pid = (int)$_POST['id'];
        q("DELETE FROM pack_lignes WHERE pack_id = ?", [$pid]); $o = 0;
        foreach ($_POST['l'] ?? [] as $lid => $on) q("INSERT INTO pack_lignes (pack_id, ligne_id, ordre) VALUES (?,?,?)", [$pid, (int)$lid, ++$o]);
        q("UPDATE packs SET nom = ?, description = ? WHERE id = ?", [trim($_POST['nom']), trim($_POST['description'] ?? ''), $pid]);
        flash('Pack enregistré.'); redirect('packs.php?id=' . $pid);
    } elseif ($a === 'suppr') {
        q("DELETE FROM pack_lignes WHERE pack_id = ?", [(int)$_POST['id']]); q("DELETE FROM packs WHERE id = ?", [(int)$_POST['id']]);
        flash('Pack supprimé.'); redirect('packs.php');
    }
}
$packs = q("SELECT p.*, (SELECT COUNT(*) FROM pack_lignes pl WHERE pl.pack_id = p.id) nb FROM packs p ORDER BY nom")->fetchAll();
$pack = isset($_GET['id']) ? q("SELECT * FROM packs WHERE id = ?", [(int)$_GET['id']])->fetch() : null;
page_start($pack ? $pack['nom'] : 'Packs de lignes', 'packs.php', $pack ? ['packs.php', 'Packs'] : null);
?>
<div class="cols">
<section class="card">
  <h2>Packs existants</h2>
  <?php if (!$packs): ?><p class="muted">Aucun pack. Importe le catalogue de démarrage depuis « Catalogue », ou crée un pack ci-dessous.</p><?php endif; ?>
  <table class="tbl"><?php foreach ($packs as $p): ?><tr><td><a href="packs.php?id=<?= $p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a><br><span class="muted"><?= e($p['description']) ?></span></td><td class="r"><?= $p['nb'] ?> lignes</td></tr><?php endforeach; ?></table>
  <h2>Nouveau pack</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="creer">
    <label>Nom <input name="nom" required placeholder="Ex. Tailles par hauteur – commune"></label>
    <label>Description <input name="description"></label>
    <button class="btn secondary" type="submit">Créer</button>
  </form>
</section>
<?php if ($pack): $dans = array_column(q("SELECT ligne_id FROM pack_lignes WHERE pack_id = ?", [$pack['id']])->fetchAll(), 'ligne_id');
  $lignes = q("SELECT * FROM bpu_lignes WHERE actif = 1 ORDER BY famille, ordre, id")->fetchAll(); ?>
<section class="card">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lignes"><input type="hidden" name="id" value="<?= $pack['id'] ?>">
    <h2>Composition du pack</h2>
    <label>Nom <input name="nom" value="<?= e($pack['nom']) ?>" required></label>
    <label>Description <input name="description" value="<?= e($pack['description']) ?>"></label>
    <input id="filtre" placeholder="Filtrer les lignes…" oninput="const v=this.value.toLowerCase();document.querySelectorAll('#choix label').forEach(l=>l.style.display=l.textContent.toLowerCase().includes(v)?'':'none')">
    <div id="choix" style="max-height:480px;overflow:auto;border:1px solid #e0e5da;border-radius:8px;padding:8px;margin:10px 0">
      <?php $fam = null; foreach ($lignes as $l): if ($l['famille'] !== $fam) { $fam = $l['famille']; echo '<div class="kicker" style="margin-top:8px">' . e($fam ?: 'Sans famille') . '</div>'; } ?>
      <label style="font-weight:400;margin:2px 0;display:flex;gap:8px;align-items:center"><input type="checkbox" name="l[<?= $l['id'] ?>]" style="width:auto;margin:0" <?= in_array($l['id'], $dans) ? 'checked' : '' ?>> <span class="muted" style="min-width:90px"><?= e($l['code']) ?></span> <?= e($l['libelle']) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Enregistrer le pack</button>
  </form>
  <form method="post" class="inline" onsubmit="return confirm('Supprimer ce pack ? (les lignes du catalogue restent)')"><?= csrf_field() ?><input type="hidden" name="action" value="suppr"><input type="hidden" name="id" value="<?= $pack['id'] ?>"><button class="mini danger">Supprimer le pack</button></form>
</section>
<?php endif; ?>
</div>
<?php page_end(); ?>
