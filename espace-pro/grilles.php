<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer') {
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') { flash('Donne un nom à la grille.', 'err'); redirect('grilles.php?nouvelle=1'); }
    $res = q("SELECT * FROM ressources WHERE actif = 1 ORDER BY ordre, id")->fetchAll();
    $taux = []; foreach ($res as $r) $taux[$r['id']] = ['nom' => $r['nom'], 'taux' => (float)$r['taux']];
    $coef = (float)setting('coef', '1'); $pas = (float)setting('arrondi', '1'); $tva = (float)setting('tva', '20');
    $temps = []; foreach (q("SELECT * FROM bpu_temps") as $t) $temps[$t['ligne_id']][$t['ressource_id']] = (float)$t['heures'];
    $tauxSimple = array_map(fn($x) => $x['taux'], $taux);

    $pdo->beginTransaction();
    q("INSERT INTO grilles (nom, client, notes, coef, tva, taux_json, cree_par) VALUES (?,?,?,?,?,?,?)",
      [$nom, trim($_POST['client'] ?? ''), trim($_POST['notes'] ?? ''), $coef, $tva, json_encode($taux, JSON_UNESCAPED_UNICODE), user()['id']]);
    $gid = (int)$pdo->lastInsertId();
    foreach (q("SELECT * FROM bpu_lignes WHERE actif = 1 ORDER BY ordre, id") as $l) {
        $t = $temps[$l['id']] ?? [];
        $p = prix_ligne($t, $tauxSimple, (float)$l['forfait'], $coef, $pas);
        $detail = []; foreach ($t as $rid => $h) $detail[] = ['ressource' => $taux[$rid]['nom'] ?? '?', 'heures' => $h, 'taux' => $taux[$rid]['taux'] ?? 0];
        if ((float)$l['forfait'] > 0) $detail[] = ['ressource' => 'Forfait', 'heures' => null, 'taux' => (float)$l['forfait']];
        q("INSERT INTO grille_lignes (grille_id, ordre, code, libelle, unite, detail_json, cout, pu_ht) VALUES (?,?,?,?,?,?,?,?)",
          [$gid, (int)$l['ordre'], $l['code'], $l['libelle'], $l['unite'], json_encode($detail, JSON_UNESCAPED_UNICODE), $p['cout'] + (float)$l['forfait'], $p['pu']]);
    }
    $pdo->commit();
    flash('Grille « ' . $nom . ' » enregistrée avec les prix figés à ce jour.');
    redirect('grille.php?id=' . $gid);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'suppr') {
    require_admin();
    $gid = (int)$_POST['id'];
    q("DELETE FROM grille_lignes WHERE grille_id = ?", [$gid]); q("DELETE FROM grilles WHERE id = ?", [$gid]);
    flash('Grille supprimée.'); redirect('grilles.php');
}
$grilles = q("SELECT g.*, u.prenom FROM grilles g LEFT JOIN utilisateurs u ON u.id = g.cree_par ORDER BY g.cree_le DESC")->fetchAll();
page_start('Grilles par marché', 'grilles.php');
?>
<section class="card" id="nouvelle">
  <h2>Figer une nouvelle grille</h2>
  <p class="muted">Enregistre une photographie des prix actuels (taux, coefficient, lignes) pour un appel d'offres. Elle ne bougera plus même si tu modifies les taux ensuite.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="creer">
    <div class="cols">
      <label>Nom du marché <input name="nom" placeholder="Ex. Mairie de Figeac – élagage 2027" required <?= isset($_GET['nouvelle']) ? 'autofocus' : '' ?>></label>
      <label>Client / collectivité <input name="client" placeholder="Ex. Commune de Figeac"></label>
    </div>
    <label>Notes (variante, remise, conditions…) <textarea name="notes" rows="2"></textarea></label>
    <button class="btn" type="submit">Figer la grille maintenant</button>
  </form>
</section>
<h2>Grilles enregistrées</h2>
<?php if (!$grilles): ?><p class="muted">Aucune grille pour l'instant.</p><?php else: ?>
<table class="tbl">
<tr><th>Date</th><th>Marché</th><th>Client</th><th class="r">Coef.</th><th>Par</th><th></th></tr>
<?php foreach ($grilles as $g): ?>
<tr>
  <td><?= date('d/m/Y', strtotime($g['cree_le'])) ?></td>
  <td><a href="grille.php?id=<?= $g['id'] ?>"><strong><?= e($g['nom']) ?></strong></a></td>
  <td><?= e($g['client']) ?></td><td class="r"><?= e($g['coef']) ?></td><td><?= e($g['prenom'] ?? '') ?></td>
  <td class="r"><a class="mini" href="grille.php?id=<?= $g['id'] ?>">Voir</a> <a class="mini" href="export.php?id=<?= $g['id'] ?>">Excel</a>
  <?php if (user()['role'] === 'admin'): ?><form method="post" class="inline" onsubmit="return confirm('Supprimer cette grille ?')"><?= csrf_field() ?><input type="hidden" name="action" value="suppr"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="mini danger">×</button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php page_end(); ?>
