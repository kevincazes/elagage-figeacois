<?php
// Packs : groupes de lignes du catalogue à ajouter d'un coup dans un bordereau
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_chiffrage();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'creer' && trim($_POST['nom'] ?? '') !== '') {
        q("INSERT INTO packs (nom, description) VALUES (?,?)", [trim($_POST['nom']), trim($_POST['description'] ?? '')]);
        flash('Pack créé : coche maintenant ses lignes.'); redirect('packs.php?id=' . (int)$pdo->lastInsertId());
    } elseif ($a === 'lignes') {
        $pid = (int)$_POST['id'];
        // On garde l'ordre existant des lignes déjà dans le pack ; les nouvelles s'ajoutent à la fin
        $avant = []; foreach (q("SELECT ligne_id, ordre FROM pack_lignes WHERE pack_id = ? ORDER BY ordre", [$pid]) as $x) $avant[(int)$x['ligne_id']] = (int)$x['ordre'];
        $coches = array_map('intval', array_keys($_POST['l'] ?? []));
        $garde = array_values(array_filter(array_keys($avant), fn($id) => in_array($id, $coches, true)));
        $nouvelles = array_values(array_filter($coches, fn($id) => !isset($avant[$id])));
        q("DELETE FROM pack_lignes WHERE pack_id = ?", [$pid]); $o = 0;
        foreach (array_merge($garde, $nouvelles) as $lid) q("INSERT INTO pack_lignes (pack_id, ligne_id, ordre) VALUES (?,?,?)", [$pid, $lid, ++$o]);
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
  <p class="muted">Un pack est une liste de lignes du catalogue, rangées dans un ordre. <strong>Modifier</strong> : nom, description, lignes à cocher. <strong>Régler les temps</strong> : ouvre le catalogue filtré sur ces lignes. <strong>→ Bordereau</strong> : crée un bordereau qui contient ces lignes, avec leurs prix calculés.</p>
  <?php if (!$packs): ?><p class="muted">Aucun pack. Importe le catalogue de démarrage depuis « Catalogue », ou crée un pack ci-dessous.</p><?php endif; ?>
  <table class="tbl packs-liste"><?php foreach ($packs as $p): ?><tr><td><a href="packs.php?id=<?= $p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a><br><span class="muted"><?= e($p['description']) ?></span></td><td class="r"><?= $p['nb'] ?> lignes</td><td class="r" style="white-space:nowrap"><a class="mini" href="packs.php?id=<?= $p['id'] ?>">Modifier</a> <a class="mini" href="bpu.php?pack=<?= $p['id'] ?>" title="Afficher uniquement ces lignes dans le catalogue pour régler leurs temps">Régler les temps</a> <a class="mini" href="bordereaux.php?pack=<?= $p['id'] ?>#nouveau" title="Créer un bordereau à partir de ce pack">→ Bordereau</a></td></tr><?php endforeach; ?></table>
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
  <form method="post" data-paquet><?= csrf_field() ?><input type="hidden" name="action" value="lignes"><input type="hidden" name="id" value="<?= $pack['id'] ?>">
    <h2>Composition du pack</h2>
    <p class="muted"><span id="nbCoche"><?= count($dans) ?></span> ligne(s) cochée(s). Les lignes déjà dans le pack gardent leur ordre ; celles que tu coches s'ajoutent à la fin. <a href="bpu.php?pack=<?= $pack['id'] ?>">Régler leurs temps dans le catalogue</a> · <a href="bordereaux.php?pack=<?= $pack['id'] ?>#nouveau">Créer un bordereau avec ce pack</a></p>
    <label>Nom <input name="nom" value="<?= e($pack['nom']) ?>" required></label>
    <label>Description <input name="description" value="<?= e($pack['description']) ?>"></label>
    <input id="filtre" placeholder="Filtrer les lignes…" oninput="const v=this.value.toLowerCase();document.querySelectorAll('#choix label').forEach(l=>l.style.display=l.textContent.toLowerCase().includes(v)?'':'none')">
    <div id="choix" style="max-height:480px;overflow:auto;border:1px solid #e0e5da;border-radius:8px;padding:8px;margin:10px 0">
      <?php $fam = null; foreach ($lignes as $l): if ($l['famille'] !== $fam) { $fam = $l['famille']; echo '<div class="kicker" style="margin-top:8px">' . e($fam ?: 'Sans famille') . '</div>'; } ?>
      <label style="font-weight:400;margin:2px 0;display:flex;gap:8px;align-items:center"><input type="checkbox" name="l[<?= $l['id'] ?>]" style="width:auto;margin:0" <?= in_array($l['id'], $dans) ? 'checked' : '' ?>> <span class="muted" style="min-width:90px"><?= e($l['code']) ?></span> <?= e($l['libelle']) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Enregistrer le pack</button> <label style="display:inline-flex;gap:6px;align-items:center;margin:0 0 0 12px;font-weight:400"><input type="checkbox" id="seulCoche" style="width:auto;margin:0" onchange="document.querySelectorAll('#choix label').forEach(l=>{l.style.display=!this.checked||l.querySelector('input').checked?'':'none'})"> n'afficher que les lignes cochées</label>
    <script>document.getElementById('choix').addEventListener('change',()=>document.getElementById('nbCoche').textContent=document.querySelectorAll('#choix input:checked').length);</script>
  </form>
  <form method="post" class="inline" onsubmit="return confirm('Supprimer ce pack ? (les lignes du catalogue restent)')"><?= csrf_field() ?><input type="hidden" name="action" value="suppr"><input type="hidden" name="id" value="<?= $pack['id'] ?>"><button class="mini danger">Supprimer le pack</button></form>
</section>
<?php endif; ?>
</div>
<?php page_end(); ?>
