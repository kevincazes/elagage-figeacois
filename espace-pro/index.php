<?php
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
planning_taches();
$moiPl = q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1 LIMIT 1", [user()['id']])->fetch();
$nbAuj = (int)q("SELECT COUNT(*) c FROM evenements WHERE statut <> 'annule' AND debut < ? AND fin > ?", [date('Y-m-d 23:59:59'), date('Y-m-d 00:00:00')])->fetch()['c'];
$nbAPl = (int)q("SELECT COUNT(*) c FROM evenements WHERE statut = 'a_planifier'")->fetch()['c'];
$nbMoi = $moiPl ? (int)q("SELECT COUNT(*) c FROM evenements e JOIN evenement_personnel ep ON ep.evenement_id = e.id WHERE ep.personnel_id = ? AND e.statut <> 'annule' AND e.fin > NOW() AND e.debut < ?", [$moiPl['id'], date('Y-m-d 00:00:00', strtotime('+7 days'))])->fetch()['c'] : 0;
$alertesPl = planning_niveau() === 'tout' ? array_filter(planning_alertes(), fn($a) => $a['niv'] === 'haut') : [];
$nbLignes = (int)q("SELECT COUNT(*) c FROM bpu_lignes WHERE actif = 1")->fetch()['c'];
$nbBord = (int)q("SELECT COUNT(*) c FROM bordereaux")->fetch()['c'];
$dernier = q("SELECT * FROM bordereaux ORDER BY cree_le DESC LIMIT 1")->fetch();
// Icône d'un outil : logo local si on l'a, sinon l'icône officielle du site (service de favicons de Google)
$icone = function (string $url, string $local = ''): string {
    if ($local) return $local;
    $h = parse_url($url, PHP_URL_HOST); return $h ? 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($h) : '';
};
$liens = [
    ['Google Drive', setting('lien_drive'), 'Dossiers, devis, photos de chantier', $icone(setting('lien_drive') ?: 'https://drive.google.com')],
    ['Extrabat', setting('lien_extrabat'), 'Gestion commerciale et espace client', '../images/logo-extrabat.png'],
    ['Urgence Élagage', setting('lien_urgence'), 'Dispositif collectivités', '../images/logo-urgence-elagage.png'],
    ['Arbogestion', setting('lien_arbogestion'), 'Gestion du patrimoine arboré', '../images/logo-arbogestion.png'],
];
// ---- Widgets du tableau de bord : liste complète (selon les droits), ordre et masquage propres à chaque compte
$tout = planning_niveau() === 'tout';
$W = [];
$w = function (string $cle, string $groupe, string $href, string $valeur, string $titre, string $detail, bool $ext = false, string $img = '', string $extra = '') use (&$W) { $W[$cle] = compact('cle', 'groupe', 'href', 'valeur', 'titre', 'detail', 'ext', 'img', 'extra'); };
// contenu supplémentaire affiché quand le widget est agrandi (Large / Grand)
$li = function (array $rows, callable $f, string $vide = 'Rien pour le moment') { if (!$rows) return '<ul class="plus"><li class="muted">' . $vide . '</li></ul>'; return '<ul class="plus">' . implode('', array_map(fn($r) => '<li>' . $f($r) . '</li>', $rows)) . '</ul>'; };
$xPlanning = $li(q("SELECT e.* FROM evenements e WHERE e.statut <> 'annule' AND e.debut < ? AND e.fin > ? ORDER BY e.debut LIMIT 6", [date('Y-m-d 23:59:59'), date('Y-m-d 00:00:00')])->fetchAll(), fn($e) => (+$e['journee'] ? 'Journée' : date('H:i', strtotime($e['debut']))) . ' · ' . e($e['titre']) . ($e['ville'] ? ' <span class="muted">' . e($e['ville']) . '</span>' : ''), "Rien aujourd'hui");
$xMoi = $moiPl ? $li(q("SELECT e.* FROM evenements e JOIN evenement_personnel ep ON ep.evenement_id = e.id WHERE ep.personnel_id = ? AND e.statut <> 'annule' AND e.fin > NOW() ORDER BY e.debut LIMIT 6", [$moiPl['id']])->fetchAll(), fn($e) => date('d/m', strtotime($e['debut'])) . (+$e['journee'] ? '' : ' ' . date('H:i', strtotime($e['debut']))) . ' · ' . e($e['titre']) . ($e['ville'] ? ' <span class="muted">' . e($e['ville']) . '</span>' : ''), 'Rien de prévu') : '';
$xAPl = $tout ? $li(q("SELECT * FROM evenements WHERE statut = 'a_planifier' ORDER BY priorite DESC, cree_le LIMIT 6")->fetchAll(), fn($e) => e($e['titre']) . ($e['ville'] ? ' <span class="muted">' . e($e['ville']) . '</span>' : ''), 'Rien à planifier') : '';
$echMat = array_filter(materiel_echeances(), fn($x) => $x['retard'] || $x['bientot']); $xMat = $li(array_slice(array_values($echMat), 0, 6), fn($x) => ($x['retard'] ? '<b style="color:#e6332a">retard</b> ' : '') . e($x['materiel']) . ' · ' . e($x['lib']) . ($x['date'] ? ' <span class="muted">' . date('d/m', strtotime($x['date'])) . '</span>' : ''), 'Aucune échéance proche');
$xLiv = $tout ? $li(array_slice(livret_echeances(90), 0, 6), fn($x) => e($x['qui']) . ' · ' . e($x['lib']) . ' <span class="muted">' . date('d/m/Y', strtotime($x['date'])) . '</span>', 'Aucune habilitation à renouveler') : '';
$xCli = $li(q("SELECT * FROM clients WHERE actif = 1 ORDER BY id DESC LIMIT 6")->fetchAll(), fn($c) => e($c['nom']) . ($c['telephone'] || $c['mobile'] ? ' <span class="muted">' . e($c['mobile'] ?: $c['telephone']) . '</span>' : ''), 'Aucun client');
$xCong = $tout ? $li(q("SELECT c.*, p.prenom FROM conges c JOIN personnel p ON p.id = c.personnel_id WHERE c.statut = 'demande' ORDER BY c.du LIMIT 6")->fetchAll(), fn($c) => e($c['prenom']) . ' · du ' . date('d/m', strtotime($c['du'])) . ' au ' . date('d/m', strtotime($c['au'])), 'Aucune demande en attente') : '';
$xBord = $li(q("SELECT * FROM bordereaux ORDER BY cree_le DESC LIMIT 6")->fetchAll(), fn($b) => e($b['nom']) . ($b['client'] ? ' <span class="muted">' . e($b['client']) . '</span>' : ''), 'Aucun bordereau');
$xContrats = $tout ? $li(q("SELECT c.* FROM contrats c WHERE c.statut = 'signe' ORDER BY c.id DESC LIMIT 6")->fetchAll(), fn($c) => e($c['client_nom']) . ($c['objet'] ? ' <span class="muted">' . e($c['objet']) . '</span>' : ''), 'Aucun contrat signé') : '';
$ic = fn(string $n) => '<svg class="pi"><use href="#pi-' . $n . '"/></svg>';
$w('planning', 'Chantiers', 'planning.php', (string)$nbAuj, "intervention(s) aujourd'hui", 'Planning des chantiers et rendez-vous', false, '', $xPlanning);
$w('mon_planning', 'Chantiers', 'mon-planning.php', $moiPl ? (string)$nbMoi : $ic('route'), $moiPl ? 'dans mon planning (7 jours)' : 'Mon planning', 'Mes chantiers, adresses et itinéraires', false, '', $xMoi);
if ($tout) $w('a_planifier', 'Chantiers', 'planning.php?vue=liste', (string)$nbAPl, 'à planifier', 'Devis signés et visites en attente de date', false, '', $xAPl);
if ($tout) $w('charge', 'Chantiers', 'charge.php', $ic('clipboard'), 'Tableau de charge', "Statuts, chiffre d'affaires planifié, occupation");
if ($tout) $w('contrats', 'Chantiers', 'contrats.php', (string)(int)q("SELECT COUNT(*) c FROM contrats WHERE statut = 'signe'")->fetch()['c'], "contrats d'entretien", 'Signature en ligne, passages automatiques', false, '', $xContrats);
$w('materiel', 'Chantiers', 'materiel.php', (string)count(array_filter(materiel_echeances(), fn($x) => $x['retard'])), 'matériel : retard(s)', 'Contrôles, entretiens, pannes', false, '', $xMat);
if ($tout) $w('livrets', 'Chantiers', 'livret.php', (string)count(livret_echeances(90)), 'habilitation(s) à renouveler (90 j)', 'Livrets du personnel : SST, CACES, formations', false, '', $xLiv); else $w('mon_livret', 'Chantiers', 'livret.php', $ic('doc'), 'Mon livret', 'Mes titres, habilitations et formations');
$w('clients', 'Chantiers', 'clients.php', (string)(int)q("SELECT COUNT(*) c FROM clients WHERE actif = 1")->fetch()['c'], 'clients', 'Contacts, adresses, porte-doc', false, '', $xCli);
$w('conges', 'Chantiers', 'conges.php', $tout ? (string)(int)q("SELECT COUNT(*) c FROM conges WHERE statut = 'demande'")->fetch()['c'] : $ic('calendar'), $tout ? 'demande(s) de congés' : 'Mes congés', $tout ? 'Demander, valider, poser au planning' : 'Demander un congé, voir mes absences', false, '', $xCong);
if ($tout) $w('platanes', 'Chantiers', 'phyto.php', $ic('tree'), 'Chantiers platanes', 'Registre DRAAF, passeports');
if (acces_chiffrage()) {
    $w('catalogue', 'Chiffrage', 'bpu.php', (string)$nbLignes, 'prestations au catalogue', 'Temps, taux, prix de référence');
    $w('importer', 'Chiffrage', 'importer.php', $ic('upload'), 'Importer un bordereau reçu', 'Le BPU / DQE de la collectivité, chiffré automatiquement');
    $w('nouveau_bordereau', 'Chiffrage', 'bordereaux.php#nouveau', $ic('plus'), 'Nouveau bordereau', "Vide ou à partir d'un pack");
    $w('bordereaux', 'Chiffrage', 'bordereaux.php', (string)$nbBord, 'bordereaux', $dernier ? 'Dernier : ' . $dernier['nom'] : "Aucun pour l'instant", false, '', $xBord);
    if (est_admin()) $w('coef', 'Chiffrage', 'parametres.php', (string)setting('coef'), 'coefficient actuel', 'Taux des équipes, marge, arrondi');
}
foreach ($liens as [$n, $url, $d, $icn]) $w('lien_' . strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $n))), 'Outils', $url ?: '', '', $n, $url ? $d : (est_admin() ? 'Lien à renseigner dans Paramètres' : 'Lien pas encore renseigné'), true, $icn);
// préférences du compte : {"ordre":[...], "masques":[...]}
try { db()->exec("ALTER TABLE utilisateurs ADD COLUMN tdb TEXT NULL"); } catch (Throwable $t) {}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tdb') {
    $in = json_decode((string)($_POST['prefs'] ?? ''), true) ?: []; $ordre = array_values(array_filter((array)($in['ordre'] ?? []), fn($k) => isset($W[$k]))); $masques = array_values(array_filter((array)($in['masques'] ?? []), fn($k) => isset($W[$k])));
    $tailles = []; foreach ((array)($in['tailles'] ?? []) as $k => $v) if (isset($W[$k]) && in_array($v, ['l', 'xl'], true)) $tailles[$k] = $v; $groupes = array_values(array_filter((array)($in['groupes'] ?? []), fn($g) => in_array($g, ['Chantiers', 'Chiffrage', 'Outils'], true)));
    q("UPDATE utilisateurs SET tdb = ? WHERE id = ?", [json_encode(['ordre' => $ordre, 'masques' => $masques, 'tailles' => $tailles, 'groupes' => $groupes]), user()['id']]); header('Content-Type: application/json'); echo '{"ok":1}'; exit;
}
$prefs = json_decode((string)(q("SELECT tdb FROM utilisateurs WHERE id = ?", [user()['id']])->fetch()['tdb'] ?? ''), true) ?: [];
$ordre = array_values(array_filter((array)($prefs['ordre'] ?? []), fn($k) => isset($W[$k]))); foreach (array_keys($W) as $k) if (!in_array($k, $ordre, true)) $ordre[] = $k;
$masques = array_values(array_filter((array)($prefs['masques'] ?? []), fn($k) => isset($W[$k])));
$tailles = array_filter((array)($prefs['tailles'] ?? []), fn($v, $k) => isset($W[$k]) && in_array($v, ['l', 'xl'], true), ARRAY_FILTER_USE_BOTH);
$GROUPES = ['Chantiers' => 'Chantiers et agenda', 'Chiffrage' => 'Chiffrage', 'Outils' => "Outils de l'entreprise"];
$gOrdre = array_values(array_filter((array)($prefs['groupes'] ?? []), fn($g) => isset($GROUPES[$g]))); foreach (array_keys($GROUPES) as $g) if (!in_array($g, $gOrdre, true)) $gOrdre[] = $g;
page_start('Tableau de bord', 'index.php');
?>
<?= pictos_sprite() ?>
<style>.tdb-barre{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.tdb-barre .btn.on{background:var(--sapin)}
.tdb-grp h2{display:flex;align-items:center;gap:8px}.tdb-grp h2 .gmv{display:none;gap:2px}.edit .tdb-grp h2 .gmv{display:inline-flex}.gmv button{border:1px solid #d5dccd;background:#fff;border-radius:6px;width:26px;height:26px;cursor:pointer;font-size:.8rem;line-height:1}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));grid-auto-rows:minmax(120px,auto);grid-auto-flow:dense;gap:14px;margin:0 0 8px}.tile.cache{display:none}
.tile.l{grid-column:span 2}.tile.xl{grid-column:span 2;grid-row:span 2}.tile.xl strong{font-size:3rem}
.tile .extra{display:none;margin-top:10px;border-top:1px solid #eef1ea;padding-top:8px}.tile.l .extra,.tile.xl .extra{display:block}.tile .plus{list-style:none;margin:0;padding:0;font-size:.85rem}.tile .plus li{padding:3px 0;border-bottom:1px dashed #eef1ea}.tile .plus li:last-child{border:0}.tile.l .plus li:nth-child(n+4){display:none}.tile .plus a{color:var(--sapin)}
.tiles .tile{position:relative;padding-top:22px}.edit .tile{cursor:grab;outline:2px dashed #c9d2bd;outline-offset:2px;user-select:none}.edit .tile.drag{opacity:.35}.edit .tile.cible{outline:3px solid var(--vert,#8caa31)}
.tile .outils{display:none;position:absolute;left:8px;right:8px;top:6px;justify-content:space-between;align-items:center;gap:4px}.edit .tile .outils{display:flex}.edit .tile .tile-ico{display:none}
.tile .outils button,.tile .outils span{border:1px solid #d5dccd;background:#fff;color:#666;border-radius:6px;height:24px;min-width:24px;padding:0 6px;cursor:pointer;font:inherit;font-size:.75rem;line-height:22px}.tile .outils .poignee{cursor:grab;letter-spacing:-2px;font-size:1rem;color:#999}
.tile strong .pi{width:30px;height:30px;color:var(--sapin)}
#tdbMasques{display:none;flex-wrap:wrap;gap:6px;margin:8px 0 0}.edit #tdbMasques{display:flex}#tdbMasques button{border:1px dashed #c9d2bd;background:#fff;border-radius:999px;padding:5px 12px;cursor:pointer;font:inherit;font-size:.85rem}
@media(max-width:600px){.tile.l,.tile.xl{grid-column:span 1;grid-row:span 1}}</style>
<div class="tdb-barre"><p class="lead" style="margin:0">Bonjour <?= e(user()['prenom']) ?>. Que veux-tu faire ?</p><button type="button" class="btn secondary" id="tdbPerso" title="Déplacer les widgets, changer leur taille, en masquer, en remettre">Personnaliser le tableau de bord</button></div>
<div id="tdb">
<?php foreach ($gOrdre as $g): $lst = array_filter($ordre, fn($k) => $W[$k]['groupe'] === $g); if (!$lst) continue; ?>
<section class="tdb-grp" data-g="<?= $g ?>"><h2><?= e($GROUPES[$g]) ?> <span class="gmv"><button type="button" class="gh" title="Monter cette famille">▲</button><button type="button" class="gb" title="Descendre cette famille">▼</button></span></h2>
<div class="tiles">
<?php foreach ($lst as $k): $t = $W[$k]; $cache = in_array($k, $masques, true); $taille = $tailles[$k] ?? ''; $img = $t['img'] ? '<img class="tile-ico" src="' . e($t['img']) . '" alt="" loading="lazy">' : '';
  $outils = '<div class="outils"><span class="poignee" title="Glisser pour déplacer">⠿</span><span><button type="button" class="taille" title="Taille : normale → large → grande">' . ['' => 'Normal', 'l' => 'Large', 'xl' => 'Grand'][$taille] . '</button> <button type="button" class="masq" title="Masquer">✕</button></span></div>'; ?>
  <?php if ($t['ext'] && !$t['href']): ?><div class="tile link off <?= $taille ?><?= $cache ? ' cache' : '' ?>" data-w="<?= $k ?>" data-titre="<?= e($t['titre']) ?>"><?= $outils . $img ?><span><?= e($t['titre']) ?></span><em><?= e($t['detail']) ?></em></div>
  <?php else: ?><a class="tile<?= $t['ext'] ? ' link' : '' ?> <?= $taille ?><?= $cache ? ' cache' : '' ?>" href="<?= e($t['href']) ?>" <?= $t['ext'] ? 'target="_blank" rel="noopener"' : '' ?> data-w="<?= $k ?>" data-titre="<?= e($t['titre']) ?>"><?= $outils . $img ?><?= $t['valeur'] !== '' ? '<strong>' . (str_starts_with($t['valeur'], '<svg') ? $t['valeur'] : e($t['valeur'])) . '</strong>' : '' ?><span><?= e($t['titre']) ?></span><em><?= e($t['detail']) ?></em><?= $t['extra'] ? '<div class="extra">' . $t['extra'] . '</div>' : '' ?></a><?php endif; ?>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
</div>
<div id="tdbMasques"></div>
<p class="small muted" id="tdbAide" hidden>Glisse un widget par sa poignée ⠿ pour l'ordonner dans sa famille (appui long sur téléphone), « Normal / Large / Grand » change sa taille, ✕ le masque ; ▲▼ ordonnent les familles. Tout est propre à ton compte et enregistré au fur et à mesure.</p>
<?php if ($alertesPl): ?><div class="flash err" style="margin-top:14px"><strong>Planning : <?= count($alertesPl) ?> point(s) à regarder</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach (array_slice($alertesPl, 0, 5) as $a): ?><li><?= $a['id'] ? '<a href="planning.php?fiche=' . $a['id'] . '">' . e($a['txt']) . '</a>' : (!empty($a['url']) ? '<a href="' . e($a['url']) . '">' . e($a['txt']) . '</a>' : e($a['txt'])) ?></li><?php endforeach; ?></ul><a class="mini" href="planning.php" style="margin-top:6px">Ouvrir le planning</a></div><?php endif; ?>
<script>
(() => {
  const D = document.querySelector('#tdb'), B = document.querySelector('#tdbPerso'), M = document.querySelector('#tdbMasques'), CSRF = document.querySelector('meta[name=csrf]').content; let edit = false, drag = null;
  const tuiles = () => [...D.querySelectorAll('.tile')];
  const prefs = () => { const t = {}; tuiles().forEach(x => { if (x.classList.contains('xl')) t[x.dataset.w] = 'xl'; else if (x.classList.contains('l')) t[x.dataset.w] = 'l'; }); return {ordre: tuiles().map(x => x.dataset.w), masques: tuiles().filter(x => x.classList.contains('cache')).map(x => x.dataset.w), tailles: t, groupes: [...D.querySelectorAll('.tdb-grp')].map(g => g.dataset.g)}; };
  const sauver = () => fetch('index.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF': CSRF}, body: 'action=tdb&csrf=' + encodeURIComponent(CSRF) + '&prefs=' + encodeURIComponent(JSON.stringify(prefs()))}).catch(() => {});
  const rendreMasques = () => { const l = tuiles().filter(x => x.classList.contains('cache')); M.innerHTML = l.length ? '<span class="small muted" style="align-self:center">Masqués :</span>' + l.map(t => `<button type="button" data-w="${t.dataset.w}">+ ${t.dataset.titre}</button>`).join('') : '<span class="small muted">Aucun widget masqué.</span>'; M.querySelectorAll('button').forEach(b => b.onclick = () => { D.querySelector(`.tile[data-w="${b.dataset.w}"]`).classList.remove('cache'); rendreMasques(); sauver(); }); };
  B.onclick = () => { edit = !edit; document.body.classList.toggle('edit', edit); B.classList.toggle('on', edit); B.textContent = edit ? 'Terminer' : 'Personnaliser le tableau de bord'; document.querySelector('#tdbAide').hidden = !edit; tuiles().forEach(t => { t.draggable = edit; }); if (edit) rendreMasques(); };
  D.addEventListener('click', ev => {
    if (!edit) return; const t = ev.target.closest('.tile');
    if (ev.target.closest('.masq')) { ev.preventDefault(); t.classList.add('cache'); rendreMasques(); sauver(); return; }
    if (ev.target.closest('.taille')) { ev.preventDefault(); const b = ev.target.closest('.taille'); const suiv = t.classList.contains('xl') ? '' : (t.classList.contains('l') ? 'xl' : 'l'); t.classList.remove('l', 'xl'); if (suiv) t.classList.add(suiv); b.textContent = {'': 'Normal', l: 'Large', xl: 'Grand'}[suiv]; sauver(); return; }
    const g = ev.target.closest('.gh, .gb'); if (g) { const sec = g.closest('.tdb-grp'); if (g.classList.contains('gh') && sec.previousElementSibling) sec.parentNode.insertBefore(sec, sec.previousElementSibling); if (g.classList.contains('gb') && sec.nextElementSibling) sec.parentNode.insertBefore(sec.nextElementSibling, sec); sauver(); return; }
    if (t) ev.preventDefault(); // pas de navigation en mode édition
  });
  const deposer = (src, cible, apres) => { if (!cible || cible === src || cible.closest('.tdb-grp') !== src.closest('.tdb-grp')) return false; cible.parentNode.insertBefore(src, apres ? cible.nextSibling : cible); return true; };
  D.addEventListener('dragstart', ev => { if (!edit) { ev.preventDefault(); return; } drag = ev.target.closest('.tile'); if (!drag) return; drag.classList.add('drag'); ev.dataTransfer.effectAllowed = 'move'; try { ev.dataTransfer.setData('text/plain', drag.dataset.w); } catch (e) {} });
  D.addEventListener('dragover', ev => { if (!edit || !drag) return; ev.preventDefault(); const c = ev.target.closest('.tile'); D.querySelectorAll('.cible').forEach(x => x.classList.remove('cible')); if (c && c !== drag && c.closest('.tdb-grp') === drag.closest('.tdb-grp')) c.classList.add('cible'); });
  D.addEventListener('drop', ev => { if (!edit || !drag) return; ev.preventDefault(); const c = ev.target.closest('.tile'); if (c) { const r = c.getBoundingClientRect(); if (deposer(drag, c, (ev.clientX - r.left) > r.width / 2)) sauver(); } });
  D.addEventListener('dragend', () => { D.querySelectorAll('.cible, .drag').forEach(x => x.classList.remove('cible', 'drag')); drag = null; });
  let tp = null, tt = null; // tactile : appui long puis déplacement
  D.addEventListener('touchstart', ev => { if (!edit) return; const t = ev.target.closest('.tile'); if (!t) return; tt = setTimeout(() => { tp = t; t.classList.add('drag'); }, 250); }, {passive: true});
  D.addEventListener('touchmove', ev => { if (!edit) return; if (!tp) { clearTimeout(tt); return; } ev.preventDefault(); const p = ev.touches[0]; const c = document.elementFromPoint(p.clientX, p.clientY)?.closest('.tile'); if (c) { const r = c.getBoundingClientRect(); deposer(tp, c, p.clientY > r.top + r.height / 2); } }, {passive: false});
  D.addEventListener('touchend', () => { clearTimeout(tt); if (tp) { tp.classList.remove('drag'); tp = null; sauver(); } });
})();
</script>
<?php page_end(); ?>
