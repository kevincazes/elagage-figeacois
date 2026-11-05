<?php
// Importer les quantités d'un DQE (détail quantitatif estimatif) dans un bordereau existant :
// dépôt du fichier (ou copier-coller) → rapprochement ligne à ligne (code, N°, désignation) → aperçu vérifiable → quantités enregistrées
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lecture.php';
require_once __DIR__ . '/appariement.php';
require_chiffrage();
$bid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$b = q("SELECT * FROM bordereaux WHERE id = ?", [$bid])->fetch();
if (!$b) { flash('Bordereau introuvable.', 'err'); redirect('bordereaux.php'); }
$retour = 'bordereau.php?id=' . $bid;
if ($b['statut'] === 'fige') { flash('Bordereau figé : défige-le pour changer ses quantités.', 'err'); redirect($retour); }
if ($det = verrou_prendre($bid)) { flash("Dossier ouvert par {$det['prenom']} {$det['nom']} : attends qu'il le libère pour importer des quantités.", 'err'); redirect($retour); }
$bl = q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? AND type = 'ligne' ORDER BY ordre, id", [$bid])->fetchAll();
$blParId = []; foreach ($bl as $l) $blParId[(int)$l['id']] = $l;
$fmtQ = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 3, ',', ' '), '0'), ',');

// ---- Étape 2 : application des quantités validées ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'appliquer') {
    $n = 0; $vus = []; $modifs = [];
    foreach ((array)($_POST['m'] ?? []) as $k => $m) {
        if (empty($m['ok']) || ($m['bl'] ?? '') === '') continue;
        $id = (int)$m['bl']; if (!isset($blParId[$id])) continue;
        $q = ($m['qte'] ?? '') === '' ? null : nb($m['qte']);
        if (isset($vus[$id])) { $q = $q === null ? $vus[$id] : ($vus[$id] ?? 0) + $q; } // même ligne du bordereau trouvée deux fois : quantités additionnées
        $vus[$id] = $q;
    }
    foreach ($vus as $id => $q) {
        $avant = $blParId[$id]['quantite'] === null ? null : (float)$blParId[$id]['quantite'];
        if ($avant !== $q) { q("UPDATE bordereau_lignes SET quantite = ? WHERE id = ? AND bordereau_id = ?", [$q, $id, $bid]); $n++;
            $modifs[] = '[' . ($blParId[$id]['numero'] ?: $blParId[$id]['ordre']) . ' ' . mb_substr($blParId[$id]['libelle'], 0, 40) . '] quantité : ' . ($avant === null ? '—' : $fmtQ($avant)) . ' → ' . ($q === null ? '—' : $fmtQ($q)); }
    }
    $vides = 0;
    if (($_POST['absentes'] ?? '') === 'vider') foreach ($bl as $l) if (!array_key_exists((int)$l['id'], $vus) && $l['quantite'] !== null) { q("UPDATE bordereau_lignes SET quantite = NULL WHERE id = ?", [$l['id']]); $vides++; }
    journal('modif', "Quantités importées du DQE « " . mb_substr((string)($_POST['fichier'] ?? ''), 0, 80) . " » : $n ligne(s) modifiée(s)" . ($vides ? ", $vides vidée(s) (absentes du DQE)" : '') . ($modifs ? "\n" . implode("\n", array_slice($modifs, 0, 200)) : ''), $bid);
    flash("Quantités du DQE enregistrées : $n ligne(s) modifiée(s)" . ($vides ? ", $vides ligne(s) absente(s) du DQE vidée(s)" : '') . '.');
    redirect($retour);
}

/** Rapprochement : chaque ligne du DQE (avec une quantité) → une ligne du bordereau */
function dqe_rapprocher(array $dqe, array $bl): array {
    $nc = fn($s) => mb_strtolower(preg_replace('/[\s.\-_]+/u', '', (string)$s));
    $parCode = []; $parNum = []; $parLib = []; $parNumLib = [];
    foreach ($bl as $l) { if ($l['code'] !== '') $parCode[$nc($l['code'])][] = (int)$l['id']; if ($l['numero'] !== '') $parNum[$nc($l['numero'])][] = (int)$l['id']; $parLib[norm($l['libelle'])][] = (int)$l['id']; $parNumLib[$nc($l['numero']) . '|' . norm($l['libelle'])][] = (int)$l['id']; }
    $pris = []; $res = [];
    $rang = []; foreach ($bl as $n => $l) $rang[(int)$l['id']] = $n; $dernier = -1;
    $libre = function (array $ids) use (&$pris, &$dernier, $rang) { foreach ($ids as $i) if (!isset($pris[$i]) && $rang[$i] > $dernier) return $i; foreach ($ids as $i) if (!isset($pris[$i])) return $i; return null; };
    $mots = fn($s) => array_values(array_unique(array_filter(preg_split('/[^a-z0-9,]+/', norm($s)), fn($w) => mb_strlen($w) > 1)));
    foreach ($dqe as $k => $d) {
        $id = null; $par = '';
        if ($d['code'] !== '' && isset($parCode[$nc($d['code'])])) { $id = $libre($parCode[$nc($d['code'])]); $par = 'code'; }
        if (!$id && $d['numero'] !== '' && isset($parNumLib[$nc($d['numero']) . '|' . norm($d['libelle'])])) { $id = $libre($parNumLib[$nc($d['numero']) . '|' . norm($d['libelle'])]); $par = 'N° et désignation'; }
        if (!$id && $d['libelle'] !== '' && isset($parLib[norm($d['libelle'])])) { $id = $libre($parLib[norm($d['libelle'])]); $par = 'désignation identique'; }
        if (!$id && $d['numero'] !== '' && isset($parNum[$nc($d['numero'])])) { $id = $libre($parNum[$nc($d['numero'])]); $par = 'N°'; }
        $score = $id ? 100 : 0;
        if (!$id && $d['libelle'] !== '') { // désignation la plus proche (mots communs, nombres compris : « 10 à 20 m » ≠ « plus de 20 m »)
            $md = $mots($d['libelle']); $best = 0; $bestId = null;
            foreach ($bl as $l) { $ml = $mots($l['libelle']); if (!$ml || !$md) continue; $c = count(array_intersect($md, $ml)); $s = 2 * $c / (count($md) + count($ml)); if (isset($pris[(int)$l['id']])) $s -= .2; elseif ($rang[(int)$l['id']] === $dernier + 1) $s += .08; elseif ($rang[(int)$l['id']] > $dernier) $s += .02; if ($s > $best) { $best = $s; $bestId = (int)$l['id']; } }
            if ($best >= .6) { $id = $bestId; $score = min(99, (int)round($best * 100)); $par = 'désignation proche'; }
        }
        if ($id) { $pris[$id] = true; $dernier = $rang[$id]; }
        $res[$k] = ['bl' => $id, 'par' => $par, 'score' => $score];
    }
    return $res;
}

$etape = 'depot'; $dqe = []; $rap = []; $erreur = ''; $nomFichier = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lire') {
    check_csrf();
    try {
        if (!empty($_FILES['fichier']['tmp_name'])) { $nomFichier = (string)$_FILES['fichier']['name']; $brutes = lire_fichier($_FILES['fichier']['tmp_name'], $nomFichier); }
        elseif (trim((string)($_POST['colle'] ?? '')) !== '') { // copier-coller depuis Excel : colonnes séparées par des tabulations
            $nomFichier = 'copier-coller';
            $rows = array_values(array_filter(array_map(fn($ln) => array_map('trim', explode("\t", $ln)), preg_split('/\R/', (string)$_POST['colle'])), fn($r) => array_filter($r, fn($c) => $c !== '')));
            $brutes = interpreter_lignes($rows, 'xlsx');
        } else throw new RuntimeException('Dépose le fichier du DQE, ou colle ses lignes.');
        $dqe = array_values(array_filter($brutes, fn($x) => !$x['section'] && $x['quantite'] !== null));
        if (!$dqe) throw new RuntimeException("Aucune quantité trouvée dans ce fichier. Vérifie qu'il a bien une colonne « Quantité » (ou « Qté ») ; sinon colle les lignes en gardant la ligne de titres.");
        $rap = dqe_rapprocher($dqe, $bl); $etape = 'apercu';
    } catch (Throwable $t) { $erreur = $t->getMessage(); }
}

page_start('Importer les quantités d\'un DQE', 'bordereaux.php', ['bordereau.php?id=' . $bid, $b['nom']]);
?>
<style>
.dqe-tbl td{vertical-align:top}.dqe-tbl select{min-width:280px;max-width:520px}.dqe-tbl tr.non td{background:#fdf1ef}.dqe-tbl tr.doute td{background:#fff8e6}
.etiq{display:inline-block;font-size:.72rem;font-weight:700;border-radius:999px;padding:1px 8px;background:#e9f3df;color:#2f6b45;white-space:nowrap}.etiq.doute{background:#fff1cc;color:#8a5a00}.etiq.non{background:#fbe0dc;color:#a3271c}
.resume-dqe{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px}.resume-dqe span{border:1px solid #d5dccd;border-radius:10px;padding:6px 12px;background:#fff}
</style>
<p class="lead">Reprend les quantités du DQE de la collectivité et les met dans la colonne « Qté » du bordereau <strong><?= e($b['nom']) ?></strong>. Les lignes sont rapprochées par code, puis par désignation, puis par N° ; tu vérifies avant d'enregistrer. Les prix ne changent pas.</p>
<?php if ($erreur): ?><div class="flash err"><?= e($erreur) ?></div><?php endif; ?>

<?php if ($etape === 'depot'): ?>
<h2>Le fichier du DQE</h2>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="lire"><input type="hidden" name="id" value="<?= $bid ?>">
  <label>Fichier Excel, Word, PDF ou CSV <input type="file" name="fichier" accept=".xlsx,.xlsm,.docx,.pdf,.csv,.txt"></label>
  <p class="muted small" style="margin:6px 0">ou</p>
  <label>Coller les lignes copiées dans Excel <span class="muted small">(sélectionne le tableau avec sa ligne de titres : N°, Désignation, Unité, Quantité…)</span>
    <textarea name="colle" rows="6" placeholder="N°	Désignation	Unité	Quantité&#10;1	Abattage d'arbre, circonférence ≤ 1,50 m	U	12"></textarea></label>
  <p><button class="btn">Lire et rapprocher</button> <a class="btn secondary" href="<?= e($retour) ?>">Retour au bordereau</a></p>
</form>
<p class="small muted">Conseil : garde un bordereau « BPU » (quantité 1 partout) et fais-en une copie nommée « DQE » (Bordereaux › Créer › copier un bordereau existant) pour y importer les quantités.</p>

<?php else:
    $nOk = count(array_filter($rap, fn($r) => $r['bl'] && $r['score'] >= 100)); $nDoute = count(array_filter($rap, fn($r) => $r['bl'] && $r['score'] < 100)); $nNon = count(array_filter($rap, fn($r) => !$r['bl']));
    $trouves = array_flip(array_filter(array_column($rap, 'bl'))); $absentes = array_filter($bl, fn($l) => !isset($trouves[(int)$l['id']]));
?>
<h2>Vérifier le rapprochement</h2>
<div class="resume-dqe"><span><strong><?= count($dqe) ?></strong> ligne(s) avec quantité dans « <?= e($nomFichier) ?> »</span><span class="etiq">sûres : <?= $nOk ?></span><?php if ($nDoute): ?><span class="etiq doute">à vérifier : <?= $nDoute ?></span><?php endif; ?><?php if ($nNon): ?><span class="etiq non">non trouvées : <?= $nNon ?></span><?php endif; ?><span><?= count($absentes) ?> ligne(s) du bordereau absente(s) du DQE</span></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="appliquer"><input type="hidden" name="id" value="<?= $bid ?>"><input type="hidden" name="fichier" value="<?= e($nomFichier) ?>">
<div class="scroll"><table class="tbl dqe-tbl"><thead><tr><th></th><th>Ligne du DQE</th><th class="r">Quantité</th><th>Ligne du bordereau</th><th>Trouvée par</th></tr></thead><tbody>
<?php foreach ($dqe as $k => $d): $r = $rap[$k]; $cl = !$r['bl'] ? 'non' : ($r['score'] < 100 ? 'doute' : ''); ?>
<tr class="<?= $cl ?>">
  <td><input type="checkbox" name="m[<?= $k ?>][ok]" value="1" <?= $r['bl'] ? 'checked' : '' ?> style="width:auto"></td>
  <td><span class="muted small"><?= e(trim($d['numero'] . ' ' . $d['code'])) ?></span> <?= e(mb_strimwidth($d['libelle'], 0, 140, '…')) ?> <span class="muted small"><?= e($d['unite']) ?></span></td>
  <td class="r"><input class="num" name="m[<?= $k ?>][qte]" value="<?= e($fmtQ($d['quantite'])) ?>" inputmode="decimal" style="width:90px"></td>
  <td><select name="m[<?= $k ?>][bl]" onchange="this.closest('tr').querySelector('[type=checkbox]').checked = this.value !== ''"><option value="">— ne pas reprendre —</option><?php foreach ($bl as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$r['bl'] === (int)$l['id'] ? 'selected' : '' ?>><?= e(trim(($l['numero'] ?: $l['ordre']) . ' ' . $l['code']) . ' – ' . mb_strimwidth($l['libelle'], 0, 90, '…')) ?></option><?php endforeach; ?></select></td>
  <td><?php if ($r['bl']): ?><span class="etiq <?= $r['score'] < 100 ? 'doute' : '' ?>"><?= e($r['par']) ?><?= $r['score'] < 100 ? ' (' . $r['score'] . ' %)' : '' ?></span><?php else: ?><span class="etiq non">non trouvée</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($absentes): ?><details style="margin:10px 0"><summary><?= count($absentes) ?> ligne(s) du bordereau absente(s) du DQE</summary><ul class="small"><?php foreach ($absentes as $l): ?><li><?= e(trim(($l['numero'] ?: $l['ordre']) . ' ' . $l['code']) . ' – ' . mb_strimwidth($l['libelle'], 0, 110, '…')) ?><?= $l['quantite'] !== null ? ' <span class="muted">(quantité actuelle ' . e($fmtQ($l['quantite'])) . ')</span>' : '' ?></li><?php endforeach; ?></ul></details>
<p><label style="display:inline-flex;gap:8px;align-items:center;font-weight:400"><input type="radio" name="absentes" value="garder" checked style="width:auto"> Garder la quantité actuelle des lignes absentes du DQE</label><br>
<label style="display:inline-flex;gap:8px;align-items:center;font-weight:400"><input type="radio" name="absentes" value="vider" style="width:auto"> Vider la quantité des lignes absentes du DQE</label></p><?php endif; ?>
<p><button class="btn">Enregistrer les quantités cochées</button> <a class="btn secondary" href="dqe.php?id=<?= $bid ?>">Autre fichier</a> <a class="mini" href="<?= e($retour) ?>">Annuler</a></p>
</form>
<?php endif; ?>
<?php page_end(); ?>
