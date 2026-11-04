<?php
// Import d'un bordereau reçu : dépôt du fichier → lecture → appariement → validation ligne par ligne → création du bordereau
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lecture.php';
require_once __DIR__ . '/appariement.php';
require_chiffrage();
$pdo = db();
try { $pdo->exec("CREATE TABLE IF NOT EXISTS appariements (cle VARCHAR(255) PRIMARY KEY, ligne_id INT NULL, maj DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $t) {}
$catalogue = q("SELECT * FROM bpu_lignes WHERE actif = 1 ORDER BY famille, FIELD(hauteur,'','<10','10-20','>20'), FIELD(houppier,'','<=5','5-10','>10'), ordre")->fetchAll();
$catParId = []; foreach ($catalogue as $c) $catParId[$c['id']] = $c;
$memoire = []; foreach (q("SELECT cle, ligne_id FROM appariements") as $m) $memoire[$m['cle']] = $m['ligne_id'] ? (int)$m['ligne_id'] : null;
$etape = 'depot'; $lignes = []; $erreur = '';

// ---- Étape 2 : validation → création ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer') {
    $nom = trim($_POST['nom'] ?? ''); if ($nom === '') { flash('Donne un nom au bordereau.', 'err'); redirect('importer.php'); }
    $equipeId = isset($_POST['equipe_id']) && $_POST['equipe_id'] !== '' ? (int)$_POST['equipe_id'] : null;
    $pdo->beginTransaction();
    q("INSERT INTO bordereaux (nom, client, notes, cree_par, equipe_id) VALUES (?,?,?,?,?)", [$nom, trim($_POST['client'] ?? ''), 'Importé depuis ' . ($_POST['fichier'] ?? 'fichier'), user()['id'], $equipeId]);
    $bid = (int)$pdo->lastInsertId(); $o = 0; $n = 0; $sans = 0;
    foreach ($_POST['r'] ?? [] as $i => $r) {
        $o++;
        if (!empty($r['ignorer'])) { $o--; continue; }
        if (!empty($r['section'])) { q("INSERT INTO bordereau_lignes (bordereau_id, ordre, numero, type, libelle) VALUES (?,?,?,'section',?)", [$bid, $o, $r['numero'] ?? '', $r['libelle'] ?? '']); continue; }
        if (!empty($r['ignorer'])) { $o--; continue; }
        $lid = ($r['ligne_id'] ?? '') !== '' ? (int)$r['ligne_id'] : null;
        $cle = cle_memoire($r['libelle'] ?? '', $r['ctx'] ?? '');
        if ($cle !== '') q("INSERT INTO appariements (cle, ligne_id) VALUES (?,?) ON DUPLICATE KEY UPDATE ligne_id = VALUES(ligne_id)", [$cle, $lid]);
        q("INSERT INTO bordereau_lignes (bordereau_id, ordre, numero, type, code, libelle, unite, ligne_id, quantite, pu_manuel) VALUES (?,?,?,'ligne',?,?,?,?,?,?)",
          [$bid, $o, $r['numero'] ?? '', $r['code'] ?? '', $r['libelle'] ?? '', $r['unite'] ?? '', $lid, ($r['qte'] ?? '') === '' ? null : nb($r['qte']), ($r['pu'] ?? '') === '' ? null : nb($r['pu'])]);
        $n++; if (!$lid && ($r['pu'] ?? '') === '') $sans++;
    }
    $pdo->commit();
    journal('creation', "Bordereau importé depuis « " . ($_POST['fichier'] ?? '') . " » : $n lignes" . ($sans ? ", $sans sans prix" : ''), $bid);
    flash("Bordereau créé : $n lignes" . ($sans ? ", dont $sans sans prix à compléter" : '') . '.');
    redirect('bordereau.php?id=' . $bid);
}

// ---- Étape 1 : dépôt → lecture + appariement ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lire' && !empty($_FILES['fichier']['tmp_name'])) {
    check_csrf();
    $nomFichier = $_FILES['fichier']['name'];
    try {
        $brutes = lire_fichier($_FILES['fichier']['tmp_name'], $nomFichier);
        // Fichier BPU + DQE : le DQE répète les lignes (sans code) avec la quantité ; on raccroche par ordre d'apparition des lignes de prestation
        $aCodes = (bool)array_filter($brutes, fn($x) => $x['code'] !== '');
        if ($aCodes) {
            $bpu = []; $dqe = [];
            foreach ($brutes as $i => $x) { if ($x['section']) continue; if ($x['code'] !== '') $bpu[] = $i; elseif ($x['unite'] !== '' || $x['quantite'] !== null || $x['prix'] !== null) $dqe[] = $i; }
            if (count($dqe) >= count($bpu) * 0.8) {
                foreach ($dqe as $k => $i) { if (!isset($bpu[$k])) break; $v = $brutes[$i]['quantite'] ?? $brutes[$i]['prix']; if ($v !== null && $brutes[$bpu[$k]]['quantite'] === null) $brutes[$bpu[$k]]['quantite'] = $v; $brutes[$i]['libelle'] = ''; }
                // sections de la partie DQE (après la dernière ligne BPU) retirées
                $fin = end($bpu); foreach ($brutes as $i => $x) if ($i > $fin && $x['section']) $brutes[$i]['libelle'] = '';
            }
        }
        // Lignes sans unité, ni quantité, ni prix, ni code : sous-titres (ex. « 2.1 Arbre de moins de 10 m ») ou descriptions → traitées comme sections de contexte
        foreach ($brutes as $i => $x) if (!$x['section'] && $x['code'] === '' && $x['unite'] === '' && $x['quantite'] === null && $x['prix'] === null) { $brutes[$i]['section'] = true; $brutes[$i]['sous'] = true; }
        $brutes = array_values(array_filter($brutes, fn($x) => $x['libelle'] !== ''));
        // Fusion BPU + DQE (même libellé répété : on garde la première occurrence et on récupère la quantité de la seconde)
        $ctx = ''; $ctx2 = ''; $vus = [];
        foreach ($brutes as $b) {
            if ($b['section']) {
                if (($lignes[count($lignes) - 1]['libelle'] ?? '') === $b['libelle']) continue;
                if (!empty($b['sous'])) { if ($b['numero'] === '' && mb_strlen($b['libelle']) > 90) continue; $ctx2 = $b['libelle']; }
                else { $ctx = $b['libelle']; $ctx2 = ''; }
                $lignes[] = $b + ['contexte' => '', 'ligne_id' => null, 'score' => 0, 'raison' => '', 'sous_titre' => !empty($b['sous'])]; continue;
            }
            $cle = $b['code'] !== '' ? 'code:' . $b['code'] : norm($b['numero'] . '|' . $ctx . '|' . $ctx2 . '|' . $b['libelle']);
            if (isset($vus[$cle])) { $k = $vus[$cle]; if ($lignes[$k]['quantite'] === null && $b['quantite'] !== null) $lignes[$k]['quantite'] = $b['quantite']; if ($lignes[$k]['prix'] === null && $b['prix'] !== null) $lignes[$k]['prix'] = $b['prix']; continue; }
            [$id, $score, $raison] = apparier($b, trim($ctx . ' › ' . $ctx2, ' ›'), array_values(array_filter($catalogue, fn($c) => $c['famille'] !== '')), $memoire);
            $vus[$cle] = count($lignes);
            $lignes[] = $b + ['contexte' => trim($ctx . ' › ' . $ctx2, ' ›'), 'ligne_id' => $id, 'score' => $score, 'raison' => $raison, 'sous_titre' => false];
        }
        // Un sous-titre suivi de lignes devient une section ; un sous-titre isolé en fin de groupe est retiré
        $etape = 'valider';
        if (!$lignes) $erreur = 'Aucune ligne reconnue dans ce fichier.';
    } catch (Throwable $t) { $erreur = $t->getMessage(); }
}
$equipes = ressources_equipes();
page_start('Importer un bordereau reçu', 'bordereaux.php', ['bordereaux.php', 'Bordereaux']);
?>
<?php if ($etape === 'depot' || $erreur): ?>
<?php if ($erreur): ?><div class="flash err"><?= e($erreur) ?></div><?php endif; ?>
<section class="card">
  <p class="lead">Dépose le bordereau de la collectivité (Excel, Word, CSV ou PDF texte). L'outil lit les lignes, propose pour chacune la prestation du catalogue correspondante, puis tu valides ligne par ligne avant la création.</p>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="lire">
    <label>Fichier du bordereau <input type="file" name="fichier" accept=".xlsx,.xlsm,.docx,.csv,.txt,.pdf" required></label>
    <button class="btn" type="submit">Lire le fichier</button>
  </form>
  <p class="muted">Les corrections que tu fais à l'étape suivante sont mémorisées : la même formulation sera reconnue au bordereau suivant.</p>
</section>
<?php else: $nbOk = count(array_filter($lignes, fn($l) => !$l['section'] && $l['score'] >= 60)); $nbL = count(array_filter($lignes, fn($l) => !$l['section'])); ?>
<form method="post" id="fv" data-paquet><?= csrf_field() ?><input type="hidden" name="action" value="creer"><input type="hidden" name="fichier" value="<?= e($nomFichier) ?>">
<section class="card">
  <div class="cols">
    <label>Nom du marché <input name="nom" value="<?= e(preg_replace('/\.[a-z0-9]+$/i', '', $nomFichier)) ?>" required></label>
    <label>Collectivité / client <input name="client"></label>
    <?php if ($equipes): ?><label>Équipe appliquée aux prix <select name="equipe_id" id="eqimp"><?php foreach ($equipes as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['nom']) ?> – <?= money((float)$x['taux']) ?>/h</option><?php endforeach; ?></select></label><?php endif; ?>
  </div>
  <p><strong><?= $nbL ?> lignes</strong> lues, <?= $nbOk ?> appariées avec confiance. Vérifie les lignes <span class="conf c2"></span>orange et <span class="conf c3"></span>rouge, corrige avec le menu, ou coche « ignorer » pour les lignes qui n'ont rien à faire dans le bordereau. Les prix du fichier (s'il y en a) sont proposés en « PU imposé ».</p>
</section>
<div class="scroll"><table class="tbl imp">
<thead><tr><th>N°</th><th class="w">Ligne du fichier</th><th>Unité</th><th class="r">Qté</th><th>Prestation du catalogue proposée</th><th class="r" title="Prix calculé avec l'équipe choisie en haut">Prix catalogue</th><th class="r">PU imposé</th><th>Ignorer</th></tr></thead>
<tbody>
<?php foreach ($lignes as $i => $l): ?>
<?php if ($l['section']): ?>
<tr class="section"><td><input class="tiny" name="r[<?= $i ?>][numero]" value="<?= e($l['numero'] === '?' ? '' : $l['numero']) ?>"><input type="hidden" name="r[<?= $i ?>][section]" value="1"></td><td colspan="6"><input name="r[<?= $i ?>][libelle]" value="<?= e($l['libelle']) ?>" style="font-weight:900"></td><td><label class="check" style="margin:0"><input type="checkbox" name="r[<?= $i ?>][ignorer]" value="1"></label></td></tr>
<?php else: $cl = $l['score'] >= 60 ? 'c1' : ($l['score'] >= 40 ? 'c2' : 'c3'); $cat = $l['ligne_id'] ? $catParId[$l['ligne_id']] ?? null : null; ?>
<tr class="<?= $cl ?>" title="<?= e($l['raison']) ?>">
  <td><input class="tiny" name="r[<?= $i ?>][numero]" value="<?= e($l['numero'] === '?' ? '' : $l['numero']) ?>"><input type="hidden" name="r[<?= $i ?>][code]" value="<?= e($l['code']) ?>"><input type="hidden" name="r[<?= $i ?>][ctx]" value="<?= e($l['contexte']) ?>"></td>
  <td><textarea class="lib" name="r[<?= $i ?>][libelle]" rows="1"><?= e($l['libelle']) ?></textarea><?php if ($l['contexte']): ?><div class="small"><?= e($l['contexte']) ?></div><?php endif; ?></td>
  <td><input class="tiny" style="width:80px" name="r[<?= $i ?>][unite]" value="<?= e($l['unite']) ?>"></td>
  <td class="r"><input class="num" name="r[<?= $i ?>][qte]" value="<?= $l['quantite'] !== null ? e((string)(float)$l['quantite']) : '' ?>" inputmode="decimal"></td>
  <td><span class="conf <?= $cl ?>" title="Confiance <?= (int)$l['score'] ?> %"></span>
    <select name="r[<?= $i ?>][ligne_id]" class="selcat" data-i="<?= $i ?>"><option value="">— Sans prestation (prix à saisir) —</option>
    <?php $fam = null; foreach ($catalogue as $c): if ($c['famille'] !== $fam) { if ($fam !== null) echo '</optgroup>'; $fam = $c['famille']; echo '<optgroup label="' . e($fam ?: 'Sans famille') . '">'; } ?>
      <option value="<?= $c['id'] ?>" <?= $l['ligne_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?> · <?= e(mb_substr($c['libelle'], 0, 70)) ?></option>
    <?php endforeach; if ($fam !== null) echo '</optgroup>'; ?></select>
    <?php if ($l['raison']): ?><div class="small"><?= e($l['raison']) ?></div><?php endif; ?></td>
  <td class="r out puc"><?= $cat ? money(prix_catalogue($cat)) : '—' ?></td>
  <td class="r"><input class="num" name="r[<?= $i ?>][pu]" value="<?= $l['prix'] !== null ? e((string)(float)$l['prix']) : '' ?>" inputmode="decimal" placeholder="–"></td>
  <td><label class="check" style="margin:0"><input type="checkbox" name="r[<?= $i ?>][ignorer]" value="1"></label></td>
</tr>
<?php endif; endforeach; ?>
</tbody></table></div>
<p><button class="btn" type="submit">Créer le bordereau</button> <a class="btn secondary" href="importer.php">Recommencer</a></p>
</form>
<script>
const fmt = v => v.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
// Prix de chaque prestation pour chaque équipe : suit le choix d'équipe en haut de page
const PU = <?php $m = []; foreach ($catalogue as $c) foreach ($equipes ?: [['id' => 0]] as $x) $m[$c['id']][$x['id']] = prix_catalogue($c, $x['id'] ? (int)$x['id'] : null); echo json_encode($m); ?>;
const eqSel = document.getElementById('eqimp');
const eqCour = () => eqSel ? eqSel.value : '0';
function majPrix(s) { const tr = s.closest('tr'), v = s.value; tr.querySelector('.puc').textContent = v && PU[v] ? fmt(PU[v][eqCour()]) : '—'; }
document.querySelectorAll('select.selcat').forEach(s => { majPrix(s); s.addEventListener('change', () => { majPrix(s); s.closest('tr').className = s.value ? 'c1' : 'c3'; }); });
if (eqSel) eqSel.addEventListener('change', () => document.querySelectorAll('select.selcat').forEach(majPrix));
function ajuster(t) { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; }
document.querySelectorAll('textarea.lib').forEach(t => { ajuster(t); t.addEventListener('input', () => ajuster(t)); });
</script>
<?php endif; ?>
<?php page_end(); ?>
