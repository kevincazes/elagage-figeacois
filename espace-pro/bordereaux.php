<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_chiffrage();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'creer') {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') { flash('Donne un nom au bordereau.', 'err'); redirect('bordereaux.php'); }
        $neq = isset($_POST['equipe_id']) && $_POST['equipe_id'] !== '' ? (int)$_POST['equipe_id'] : null;
        $client = trim($_POST['client'] ?? '');
        // Copie d'un bordereau : même équipe (donc mêmes prix unitaires) et même collectivité par défaut, pour un DQE identique au BPU
        if (!empty($_POST['modele']) && ($srcB = q("SELECT equipe_id, client FROM bordereaux WHERE id = ?", [(int)$_POST['modele']])->fetch())) {
            if ($srcB['equipe_id'] !== null) $neq = (int)$srcB['equipe_id'];
            if ($client === '') $client = (string)$srcB['client'];
        }
        q("INSERT INTO bordereaux (nom, client, notes, cree_par, equipe_id) VALUES (?,?,?,?,?)", [$nom, $client, trim($_POST['notes'] ?? ''), user()['id'], $neq]);
        $bid = (int)$pdo->lastInsertId();
        if (!empty($_POST['modele'])) { // dupliquer un bordereau existant
            $src = (int)$_POST['modele'];
            foreach (q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? ORDER BY ordre, id", [$src]) as $l) {
                q("INSERT INTO bordereau_lignes (bordereau_id, ordre, numero, type, code, libelle, unite, ligne_id, quantite, pu_manuel) VALUES (?,?,?,?,?,?,?,?,?,?)", [$bid, $l['ordre'], $l['numero'], $l['type'], $l['code'], $l['libelle'], $l['unite'], $l['ligne_id'], $l['quantite'], $l['pu_manuel']]);
                $nid = (int)$pdo->lastInsertId(); foreach (q("SELECT * FROM bordereau_moyens WHERE bl_id = ?", [$l['id']]) as $m) q("INSERT INTO bordereau_moyens (bordereau_id, bl_id, ressource_id, libelle, taux, heures) VALUES (?,?,?,?,?,?)", [$bid, $nid, $m['ressource_id'], $m['libelle'], $m['taux'], $m['heures']]); }
        }
        if (!empty($_POST['pack'])) { // partir d'un pack : ses lignes, dans l'ordre (après celles d'un bordereau copié)
            $o = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM bordereau_lignes WHERE bordereau_id = ?", [$bid])->fetch()['o']; $n = 0; $pack = q("SELECT * FROM packs WHERE id = ?", [(int)$_POST['pack']])->fetch();
            foreach (q("SELECT l.* FROM pack_lignes pl JOIN bpu_lignes l ON l.id = pl.ligne_id WHERE pl.pack_id = ? AND l.actif = 1 ORDER BY pl.ordre", [(int)$_POST['pack']]) as $l) {
                q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, code, libelle, unite, ligne_id) VALUES (?,?,'ligne',?,?,?,?)", [$bid, $o++, $l['code'], $l['libelle'], $l['unite'], $l['id']]); $n++; }
            journal('ajout', 'Pack « ' . ($pack['nom'] ?? '') . " » ($n lignes)", $bid);
        }
        journal('creation', 'Bordereau créé' . (!empty($_POST['modele']) ? ' à partir d\'un modèle' : '') . (!empty($_POST['pack']) ? ' à partir d\'un pack' : '') . ($neq ? ' ; équipe : ' . (equipe_choisie($neq)['nom'] ?? '') : ''), $bid);
        redirect('bordereau.php?id=' . $bid);
    } elseif ($a === 'suppr') {
        require_admin();
        q("DELETE FROM bordereau_lignes WHERE bordereau_id = ?", [(int)$_POST['id']]); q("DELETE FROM bordereau_moyens WHERE bordereau_id = ?", [(int)$_POST['id']]); q("DELETE FROM bordereaux WHERE id = ?", [(int)$_POST['id']]);
        flash('Bordereau supprimé.'); redirect('bordereaux.php');
    }
}
$bords = q("SELECT b.*, u.prenom, v.prenom vprenom, v.nom vnom, (SELECT COUNT(*) FROM bordereau_lignes l WHERE l.bordereau_id = b.id AND l.type = 'ligne') nb FROM bordereaux b LEFT JOIN utilisateurs u ON u.id = b.cree_par LEFT JOIN utilisateurs v ON v.id = b.verrou_par ORDER BY b.cree_le DESC")->fetchAll();
$packsListe = q("SELECT p.*, (SELECT COUNT(*) FROM pack_lignes pl WHERE pl.pack_id = p.id) nb FROM packs p ORDER BY nom")->fetchAll(); $equipes = ressources_equipes();
page_start('Bordereaux de prix', 'bordereaux.php');
?>
<?php $packPre = (int)($_GET['pack'] ?? 0); $eqNoms = []; foreach ($equipes as $x) $eqNoms[(int)$x['id']] = $x['nom']; ?>
<p class="lead">Un bordereau = le document de prix d'un marché. Ouvre-en un pour le modifier, ou crée-en un nouveau : import du fichier reçu, ou à partir d'un pack (les packs se préparent dans Chiffrage › Packs).</p>
<h2>Mes bordereaux</h2>
<?php if (!$bords): ?><p class="muted">Aucun bordereau pour l'instant : importe le fichier d'une collectivité ou crée-en un à partir d'un pack ci-dessous.</p><?php else: ?>
<div class="bfiltre" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:0 0 10px">
  <label style="flex:1;min-width:220px;margin:0">Rechercher <input type="search" id="bRech" placeholder="Marché, collectivité, client, note…" autocomplete="off"></label>
  <label style="margin:0">Classer par <select id="bTri"><option value="date">Date de création (récents d'abord)</option><option value="client">Collectivité / client</option><option value="nom">Nom du marché</option></select></label>
  <span class="muted small" id="bNb"></span>
</div>
<div class="scroll"><table class="tbl" id="bTable">
<thead><tr><th>Créé le</th><th>Marché</th><th>Client</th><th>Équipe</th><th class="r">Lignes</th><th>Statut</th><th>Par</th><th></th></tr></thead>
<tbody>
<?php foreach ($bords as $b): ?>
<tr class="bl" data-date="<?= e((string)$b['cree_le']) ?>" data-client="<?= e(trim((string)$b['client'])) ?>" data-nom="<?= e((string)$b['nom']) ?>" data-txt="<?= e(mb_strtolower($b['nom'] . ' ' . $b['client'] . ' ' . $b['notes'])) ?>">
  <td><?= date('d/m/Y', strtotime($b['cree_le'])) ?></td>
  <td><a href="bordereau.php?id=<?= $b['id'] ?>"><strong><?= e($b['nom']) ?></strong></a><?= $b['notes'] ? '<br><span class="small muted">' . e($b['notes']) . '</span>' : '' ?></td>
  <td><?= e($b['client']) ?></td>
  <td class="small"><?= e($eqNoms[(int)$b['equipe_id']] ?? ($equipes[0]['nom'] ?? '—')) ?></td>
  <td class="r"><?= $b['nb'] ?></td>
  <td><?= $b['statut'] === 'fige' ? '<span class="ok">Figé le ' . date('d/m/Y', strtotime($b['fige_le'])) . '</span>' : 'Brouillon' ?>
    <?php if ($b['verrou_par'] && strtotime($b['verrou_le']) > time() - VERROU_MINUTES * 60): ?><br><?php if ((int)$b['verrou_par'] === (int)user()['id']): ?><span class="muted small">ouvert par toi</span><?php else: ?><span class="off">ouvert par <?= e($b['vprenom'] . ' ' . $b['vnom']) ?> depuis <?= date('H:i', strtotime($b['verrou_le'])) ?> : lecture seule</span><?php endif; ?><?php endif; ?></td>
  <td><?= e($b['prenom'] ?? '') ?></td>
  <td class="r" style="white-space:nowrap"><a class="btn secondary" style="padding:6px 14px" href="bordereau.php?id=<?= $b['id'] ?>">Ouvrir</a> <a class="mini" href="export.php?id=<?= $b['id'] ?>">Excel</a>
  <?php if (est_admin()): ?><form method="post" class="inline" onsubmit="return confirm('Supprimer ce bordereau ?')"><?= csrf_field() ?><input type="hidden" name="action" value="suppr"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="mini danger" title="Supprimer">×</button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<script>
// Liste des bordereaux : recherche (marché, collectivité, notes) et classement par date, collectivité ou nom ; choix gardé sur ce poste
(function () {
  const tb = document.querySelector('#bTable tbody'), rech = document.getElementById('bRech'), tri = document.getElementById('bTri'), nb = document.getElementById('bNb');
  const lignes = [...tb.querySelectorAll('tr.bl')], sans = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  try { tri.value = localStorage.getItem('bordereaux.tri') || 'date'; } catch (e) {}
  function maj() {
    const q = sans(rech.value.trim()), t = tri.value, coll = new Intl.Collator('fr', {sensitivity: 'base', numeric: true});
    tb.querySelectorAll('tr.grp').forEach(x => x.remove());
    const vis = lignes.filter(tr => !q || sans(tr.dataset.txt).includes(q));
    lignes.forEach(tr => tr.hidden = !vis.includes(tr));
    const tries = [...vis].sort((a, b) => t === 'nom' ? coll.compare(a.dataset.nom, b.dataset.nom)
      : t === 'client' ? (!a.dataset.client - !b.dataset.client) || coll.compare(a.dataset.client, b.dataset.client) || b.dataset.date.localeCompare(a.dataset.date)
      : b.dataset.date.localeCompare(a.dataset.date));
    let grp = null;
    tries.forEach(tr => {
      if (t === 'client' && tr.dataset.client !== grp) {
        grp = tr.dataset.client; const n = tries.filter(x => x.dataset.client === grp).length, h = document.createElement('tr');
        h.className = 'grp'; h.innerHTML = '<td colspan="8" style="background:#eef3ea;font-weight:700"></td>'; h.firstChild.textContent = (grp || 'Sans collectivité / client') + ' · ' + n + ' bordereau' + (n > 1 ? 'x' : ''); tb.appendChild(h);
      }
      tb.appendChild(tr);
    });
    nb.textContent = vis.length + ' bordereau' + (vis.length > 1 ? 'x' : '') + (q ? ' trouvé' + (vis.length > 1 ? 's' : '') : '');
  }
  rech.addEventListener('input', maj);
  tri.addEventListener('change', () => { try { localStorage.setItem('bordereaux.tri', tri.value); } catch (e) {} maj(); });
  maj();
})();
</script>
<?php endif; ?>

<h2 id="nouveau">Créer un bordereau</h2>
<div class="cols">
<section class="card" style="border-left:5px solid var(--anis)">
  <h3 style="margin-top:0">Importer un bordereau reçu</h3>
  <p>Le cas courant : la collectivité t'envoie son BPU / DQE. Dépose le fichier (Excel, Word, CSV, PDF texte) : chaque ligne est rapprochée d'une prestation du catalogue, tu vérifies, le bordereau est créé à son format, avec ses quantités.</p>
  <a class="btn" href="importer.php">Importer un fichier</a>
</section>
<section class="card">
  <h3 style="margin-top:0">Nouveau bordereau (copie d'un bordereau, pack ou vide)</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="creer">
    <?php if ($bords): ?><label>Copier un bordereau existant <small class="muted">(ex. le BPU pour en faire le DQE : lignes, numéros, quantités, prix imposés ; nom, collectivité et équipe repris)</small> <select name="modele" onchange="copieModele(this)"><option value="">— aucun —</option><?php foreach ($bords as $b): ?><option value="<?= $b['id'] ?>" data-equipe="<?= $b['equipe_id'] !== null ? (int)$b['equipe_id'] : '' ?>" data-client="<?= e((string)$b['client']) ?>" data-nom="<?= e((string)$b['nom']) ?>"><?= e($b['nom']) ?> (<?= $b['nb'] ?> lignes)</option><?php endforeach; ?></select></label><?php endif; ?>
    <label>Nom du marché <input name="nom" placeholder="Ex. Lot Habitat – entretien du patrimoine arboré 2026" required <?= isset($_GET['nouveau']) || $packPre ? 'autofocus' : '' ?>></label>
    <label>Collectivité / client <input name="client"></label>
    <label>Équipe appliquée aux prix <select name="equipe_id" id="nvEquipe"><?php foreach ($equipes as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['nom']) ?> (<?= money((float)$x['taux']) ?>/h)</option><?php endforeach; ?></select> <small class="muted" id="nvEquipeNote" hidden>reprise du bordereau copié, pour garder les mêmes prix unitaires</small></label>
    <label>Partir d'un pack <small class="muted">(ses lignes arrivent avec leurs prix calculés)</small> <select name="pack"><option value="">— aucun : bordereau vide —</option><?php foreach ($packsListe as $pk): ?><option value="<?= $pk['id'] ?>" <?= $packPre === (int)$pk['id'] ? 'selected' : '' ?>><?= e($pk['nom']) ?> (<?= $pk['nb'] ?> lignes)</option><?php endforeach; ?></select></label>
    <label>Notes <input name="notes" placeholder="Date limite, variante, remise…"></label>
    <p class="muted small">L'équipe fixe le taux horaire de toutes les lignes ; tu pourras la changer dans le bordereau. Les prix viennent des temps du catalogue et des paramètres, et les suivent jusqu'au figement.</p>
    <button class="btn" type="submit">Créer et ouvrir</button>
  </form>
</section>
<script>
// Copie d'un bordereau (ex. le DQE d'un BPU) : même équipe que l'original, donc mêmes prix unitaires ; collectivité reprise si vide
function copieModele(sel) {
  const o = sel.selectedOptions[0], eq = document.getElementById('nvEquipe'), note = document.getElementById('nvEquipeNote'), cl = sel.form.elements.client;
  const fixe = !!(o && o.value && o.dataset.equipe);
  if (fixe) eq.value = o.dataset.equipe;
  eq.disabled = fixe; note.hidden = !fixe;
  if (cl && (cl.value === '' || cl.dataset.auto === cl.value)) { cl.value = o && o.value ? (o.dataset.client || '') : ''; cl.dataset.auto = cl.value; }
  // Nom proposé : « BPU … » devient « DQE … », sinon « DQE – » devant ; tant que tu ne l'as pas retouché, il suit le bordereau choisi
  const nm = sel.form.elements.nom;
  if (nm && (nm.value === '' || nm.dataset.auto === nm.value)) {
    const src = o && o.value ? (o.dataset.nom || '') : '';
    nm.value = src === '' ? '' : (/^\s*BPU\b/i.test(src) ? src.replace(/^\s*BPU\b/i, 'DQE') : 'DQE – ' + src);
    nm.dataset.auto = nm.value;
  }
}
</script>
</div>
<?php page_end(); ?>
