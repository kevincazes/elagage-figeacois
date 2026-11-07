<?php
// Administration › Rangement des fichiers : range les documents et photos déjà enregistrés dans le dossier de chaque client
// (fichiers/clients/0142-mairie-de-figeac/2026/rapports/…). Les fichiers sont COPIÉS : l'ancien exemplaire reste en place
// jusqu'au nettoyage, qu'on ne lance qu'après avoir vérifié que tout s'ouvre.
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
if (!est_admin()) { http_response_code(403); page_start('Accès réservé'); echo '<p>Réservé aux administrateurs.</p>'; page_end(); exit; }

function rangement_a_faire(): array {
    $docs = q("SELECT d.* FROM client_docs d WHERE d.fichier NOT LIKE 'clients/%' OR d.fichier REGEXP '^clients/[^/]+/[0-9]{4}/[a-z]'")->fetchAll();
    $phs = q("SELECT p.*, e.client_id, e.titre, e.debut FROM rapport_photos p JOIN evenements e ON e.id = p.evenement_id WHERE e.client_id IS NOT NULL AND (p.fichier NOT LIKE 'clients/%' OR p.fichier REGEXP '^clients/[^/]+/[0-9]{4}/[a-z]' OR (p.original <> '' AND (p.original NOT LIKE 'clients/%' OR p.original REGEXP '^clients/[^/]+/[0-9]{4}/[a-z]')))")->fetchAll();
    return [$docs, $phs];
}
function copier_vers(string $ancien, int $cid, string $cat, string $libelle, ?string $date): ?string {
    $src = chemin_fichier($ancien); if (!$src) return null;
    $ext = strtolower(pathinfo($ancien, PATHINFO_EXTENSION));
    $nouveau = chemin_client_nouveau($cid, $cat, $libelle, $ext, $date);
    if (!@copy($src, dossier_fichiers() . '/' . $nouveau) || filesize(dossier_fichiers() . '/' . $nouveau) !== filesize($src)) { @unlink(dossier_fichiers() . '/' . $nouveau); return null; }
    return $nouveau;
}
/** Anciens fichiers à plat encore utilisés quelque part (on ne les efface jamais) */
function anciens_utilises(): array {
    $u = [];
    foreach (["SELECT fichier f FROM client_docs", "SELECT fichier f FROM rapport_photos", "SELECT original f FROM rapport_photos"] as $sql) foreach (q($sql) as $r) $u[$r['f']] = 1;
    foreach (['materiel_docs' => 'fichier', 'materiel_journal' => 'photo', 'salarie_docs' => 'fichier', 'salarie_titres' => 'fichier'] as $t => $col) { try { foreach (q("SELECT `$col` f FROM `$t`") as $r) $u[(string)$r['f']] = 1; } catch (Throwable $e) {} }
    try { foreach (q("SELECT fichier f FROM materiel_journal") as $r) $u[(string)$r['f']] = 1; } catch (Throwable $e) {}
    return $u;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $a = $_POST['action'] ?? '';
    if ($a === 'lot') {
        @set_time_limit(60); [$docs, $phs] = rangement_a_faire(); $fait = 0; $erreurs = 0; $t0 = microtime(true);
        foreach ($docs as $d) {
            if (microtime(true) - $t0 > 20) break;
            $n = copier_vers($d['fichier'], (int)$d['client_id'], $d['dossier'], $d['nom'], $d['cree_le']);
            if ($n) { q("UPDATE client_docs SET fichier = ?, ancien_fichier = ? WHERE id = ?", [$n, $d['fichier'], $d['id']]); $fait++; }
            else { $erreurs++; q("UPDATE client_docs SET ancien_fichier = ? WHERE id = ?", ['introuvable', $d['id']]); }
        }
        foreach ($phs as $p) {
            if (microtime(true) - $t0 > 20) break;
            $maj = []; $ok = true;
            foreach (['fichier', 'original'] as $col) {
                if ($p[$col] === '' || (str_starts_with($p[$col], 'clients/') && !preg_match('#^clients/[^/]+/\d{4}/[a-z]#', $p[$col]))) continue;
                $n = copier_vers($p[$col], (int)$p['client_id'], 'Photos', ($col === 'original' ? 'photo originale ' : 'photo ') . $p['titre'], $p['debut'] ?: $p['cree_le']);
                if ($n) $maj[$col] = $n; else $ok = false;
            }
            if ($maj) { q("UPDATE rapport_photos SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($maj))) . ", ancien_fichier = ? WHERE id = ?", array_merge(array_values($maj), [$p['fichier'], $p['id']])); $fait++; }
            if (!$ok) $erreurs++;
        }
        [$d2, $p2] = rangement_a_faire();
        // fichiers introuvables : on les sort de la liste pour ne pas boucler (ils restent signalés)
        $reste = count(array_filter($d2, fn($d) => $d['ancien_fichier'] !== 'introuvable')) + count(array_filter($p2, fn($p) => chemin_fichier($p['fichier']) || ($p['original'] !== '' && chemin_fichier($p['original']))));
        if ($fait === 0 && $reste > 0) $reste = 0; // plus rien de rangeable
        if ($reste === 0) { set_setting('fichiers_ranges_le', date('Y-m-d H:i')); journal('parametres', 'Fichiers rangés par client'); }
        echo json_encode(['fait' => $fait, 'erreurs' => $erreurs, 'reste' => $reste]); exit;
    }
    if ($a === 'nettoyer') {
        check_csrf(); $u = anciens_utilises(); $n = 0; $o = 0;
        foreach (glob(dossier_fichiers() . '/*.*') ?: [] as $f) {
            $b = basename($f); if (!preg_match('/^[a-z]+-\d{8}-[a-f0-9]{16}\.[a-z]+$/', $b) || isset($u[$b])) continue;
            $rangé = q("SELECT 1 FROM client_docs WHERE ancien_fichier = ? UNION SELECT 1 FROM rapport_photos WHERE ancien_fichier = ? LIMIT 1", [$b, $b])->fetch();
            if (!$rangé) { $o++; continue; } // jamais supprimé s'il n'a pas été rangé par cet outil
            $o += 0; $n += (int)@unlink($f);
        }
        foreach (q("SELECT ancien_fichier f FROM client_docs WHERE ancien_fichier LIKE 'clients/%' UNION SELECT ancien_fichier FROM rapport_photos WHERE ancien_fichier LIKE 'clients/%'") as $r) {
            if (isset($u[$r['f']]) || !preg_match('#^clients/[^/]+/\d{4}/[a-z]#', $r['f'])) continue; if (($c = chemin_fichier($r['f'])) && @unlink($c)) { $n++; @rmdir(dirname($c)); @rmdir(dirname($c, 2)); }
        }
        journal('parametres', "Nettoyage des anciens fichiers : $n supprimé(s)");
        echo json_encode(['supprimes' => $n]); exit;
    }
    echo json_encode(['erreur' => 'Action inconnue']); exit;
}

[$docs, $phs] = rangement_a_faire();
$aRanger = count(array_filter($docs, fn($d) => $d['ancien_fichier'] !== 'introuvable')) + count($phs);
$introuvables = (int)q("SELECT COUNT(*) c FROM client_docs WHERE ancien_fichier = 'introuvable' AND fichier NOT LIKE 'clients/%'")->fetch()['c'];
$ranges = (int)q("SELECT COUNT(*) c FROM client_docs WHERE fichier LIKE 'clients/%'")->fetch()['c'] + (int)q("SELECT COUNT(*) c FROM rapport_photos WHERE fichier LIKE 'clients/%'")->fetch()['c'];
$u = anciens_utilises(); $anciens = 0; foreach (glob(dossier_fichiers() . '/*.*') ?: [] as $f) { $b = basename($f); if (preg_match('/^[a-z]+-\d{8}-[a-f0-9]{16}\.[a-z]+$/', $b) && !isset($u[$b]) && q("SELECT 1 FROM client_docs WHERE ancien_fichier = ? UNION SELECT 1 FROM rapport_photos WHERE ancien_fichier = ? LIMIT 1", [$b, $b])->fetch()) $anciens++; }
page_start('Rangement des fichiers', 'ranger-fichiers.php');
?>
<p class="lead">Les documents, rapports, contrats signés et photos sont rangés dans un dossier par client sur l'hébergement, lisible par FTP :<br><code>fichiers/clients/0142-mairie-de-figeac/2026/11-novembre/rapports/2026-11-06_rapport-de-visite_….pdf</code></p>
<div class="card">
  <p><strong><?= $ranges ?></strong> fichier(s) déjà rangé(s) · <strong id="nbReste"><?= $aRanger ?></strong> à ranger<?= $introuvables ? " · $introuvables introuvable(s) sur le serveur (laissés tels quels)" : '' ?></p>
  <?php if ($aRanger): ?><p><button class="btn" id="go">Ranger les fichiers existants</button></p>
  <p class="small muted">Les fichiers sont copiés dans les dossiers des clients puis l'appli pointe vers la copie. L'ancien exemplaire reste en place tant que tu ne lances pas le nettoyage : rien n'est perdu.</p>
  <?php else: ?><p style="color:var(--sapin);font-weight:700">Tout est rangé. Les nouveaux documents et photos arrivent directement dans le dossier du client.</p><?php endif; ?>
  <p id="etat" class="small"></p>
</div>
<?php if (!$aRanger && $anciens): ?><div class="card"><h2 style="margin-top:0">Anciens exemplaires</h2>
  <p><?= $anciens ?> ancien(s) fichier(s) à plat ont été copiés dans les dossiers des clients. Une fois que tu as vérifié que les documents s'ouvrent bien depuis quelques fiches clients, tu peux les supprimer pour libérer de la place.</p>
  <p><button class="btn secondary" id="nettoyer">Supprimer les anciens exemplaires</button></p></div><?php endif; ?>
<script>
const CSRF = <?= json_encode(csrf()) ?>, etat = document.getElementById('etat');
const post = async a => (await fetch('ranger-fichiers.php', {method: 'POST', body: new URLSearchParams({action: a, csrf: CSRF})})).json();
const go = document.getElementById('go');
if (go) go.onclick = async () => { go.disabled = true; let total = 0, err = 0;
  for (let i = 0; i < 500; i++) { etat.textContent = 'Rangement en cours… ' + total + ' fichier(s) rangé(s)'; let r; try { r = await post('lot'); } catch (e) { etat.textContent = 'Coupure réseau : relance le rangement, il reprend où il en était.'; go.disabled = false; return; }
    total += r.fait || 0; err += r.erreurs || 0; document.getElementById('nbReste').textContent = r.reste; if (!r.reste) break; }
  etat.textContent = 'Terminé : ' + total + ' fichier(s) rangé(s)' + (err ? ', ' + err + ' introuvable(s) laissé(s) tel(s) quel(s)' : '') + '.'; setTimeout(() => location.reload(), 1500); };
const n = document.getElementById('nettoyer');
if (n) n.onclick = async () => { if (!n.dataset.ok) { n.dataset.ok = 1; n.textContent = 'Confirmer la suppression des anciens exemplaires'; return; } n.disabled = true; const r = await post('nettoyer'); etat.textContent = r.supprimes + ' ancien(s) fichier(s) supprimé(s).'; setTimeout(() => location.reload(), 1500); };
</script>
<?php page_end(); ?>
