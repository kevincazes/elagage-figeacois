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
$w = function (string $cle, string $groupe, string $href, string $valeur, string $titre, string $detail, bool $ext = false, string $img = '') use (&$W) { $W[$cle] = compact('cle', 'groupe', 'href', 'valeur', 'titre', 'detail', 'ext', 'img'); };
$ic = fn(string $n) => '<svg class="pi"><use href="#pi-' . $n . '"/></svg>';
$w('planning', 'Chantiers', 'planning.php', (string)$nbAuj, "intervention(s) aujourd'hui", 'Planning des chantiers et rendez-vous');
$w('mon_planning', 'Chantiers', 'mon-planning.php', $moiPl ? (string)$nbMoi : $ic('route'), $moiPl ? 'dans mon planning (7 jours)' : 'Mon planning', 'Mes chantiers, adresses et itinéraires');
if ($tout) $w('a_planifier', 'Chantiers', 'planning.php?vue=liste', (string)$nbAPl, 'à planifier', 'Devis signés et visites en attente de date');
if ($tout) $w('charge', 'Chantiers', 'charge.php', $ic('clipboard'), 'Tableau de charge', "Statuts, chiffre d'affaires planifié, occupation");
if ($tout) $w('contrats', 'Chantiers', 'contrats.php', (string)(int)q("SELECT COUNT(*) c FROM contrats WHERE statut = 'signe'")->fetch()['c'], "contrats d'entretien", 'Signature en ligne, passages automatiques');
$w('materiel', 'Chantiers', 'materiel.php', (string)count(array_filter(materiel_echeances(), fn($x) => $x['retard'])), 'matériel : retard(s)', 'Contrôles, entretiens, pannes');
if ($tout) $w('livrets', 'Chantiers', 'livret.php', (string)count(livret_echeances(90)), 'habilitation(s) à renouveler (90 j)', 'Livrets du personnel : SST, CACES, formations'); else $w('mon_livret', 'Chantiers', 'livret.php', $ic('doc'), 'Mon livret', 'Mes titres, habilitations et formations');
$w('clients', 'Chantiers', 'clients.php', (string)(int)q("SELECT COUNT(*) c FROM clients WHERE actif = 1")->fetch()['c'], 'clients', 'Contacts, adresses, porte-doc');
$w('conges', 'Chantiers', 'conges.php', $tout ? (string)(int)q("SELECT COUNT(*) c FROM conges WHERE statut = 'demande'")->fetch()['c'] : $ic('calendar'), $tout ? 'demande(s) de congés' : 'Mes congés', $tout ? 'Demander, valider, poser au planning' : 'Demander un congé, voir mes absences');
if ($tout) $w('platanes', 'Chantiers', 'phyto.php', $ic('tree'), 'Chantiers platanes', 'Registre DRAAF, passeports');
if (acces_chiffrage()) {
    $w('catalogue', 'Chiffrage', 'bpu.php', (string)$nbLignes, 'prestations au catalogue', 'Temps, taux, prix de référence');
    $w('importer', 'Chiffrage', 'importer.php', $ic('upload'), 'Importer un bordereau reçu', 'Le BPU / DQE de la collectivité, chiffré automatiquement');
    $w('nouveau_bordereau', 'Chiffrage', 'bordereaux.php#nouveau', $ic('plus'), 'Nouveau bordereau', "Vide ou à partir d'un pack");
    $w('bordereaux', 'Chiffrage', 'bordereaux.php', (string)$nbBord, 'bordereaux', $dernier ? 'Dernier : ' . $dernier['nom'] : "Aucun pour l'instant");
    if (est_admin()) $w('coef', 'Chiffrage', 'parametres.php', (string)setting('coef'), 'coefficient actuel', 'Taux des équipes, marge, arrondi');
}
foreach ($liens as [$n, $url, $d, $icn]) $w('lien_' . strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $n))), 'Outils', $url ?: '', '', $n, $url ? $d : (est_admin() ? 'Lien à renseigner dans Paramètres' : 'Lien pas encore renseigné'), true, $icn);
// préférences du compte : {"ordre":[...], "masques":[...]}
try { db()->exec("ALTER TABLE utilisateurs ADD COLUMN tdb TEXT NULL"); } catch (Throwable $t) {}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tdb') {
    $in = json_decode((string)($_POST['prefs'] ?? ''), true) ?: []; $ordre = array_values(array_filter((array)($in['ordre'] ?? []), fn($k) => isset($W[$k]))); $masques = array_values(array_filter((array)($in['masques'] ?? []), fn($k) => isset($W[$k])));
    q("UPDATE utilisateurs SET tdb = ? WHERE id = ?", [json_encode(['ordre' => $ordre, 'masques' => $masques]), user()['id']]); header('Content-Type: application/json'); echo '{"ok":1}'; exit;
}
$prefs = json_decode((string)(q("SELECT tdb FROM utilisateurs WHERE id = ?", [user()['id']])->fetch()['tdb'] ?? ''), true) ?: [];
$ordre = array_values(array_filter((array)($prefs['ordre'] ?? []), fn($k) => isset($W[$k]))); foreach (array_keys($W) as $k) if (!in_array($k, $ordre, true)) $ordre[] = $k;
$masques = array_values(array_filter((array)($prefs['masques'] ?? []), fn($k) => isset($W[$k])));
page_start('Tableau de bord', 'index.php');
?>
<?= pictos_sprite() ?>
<style>.tdb-barre{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.tdb-barre .mini.on{background:var(--sapin);color:#fff;border-color:var(--sapin)}
.tiles .tile{position:relative}.tiles.edit .tile{cursor:grab;outline:2px dashed #c9d2bd;outline-offset:2px}.tiles.edit .tile.drag{opacity:.4}.tiles.edit .tile.cible{outline-color:var(--vert,#8caa31);outline-style:solid}
.tile .grp{position:absolute;right:12px;top:10px;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:var(--doux);font-weight:700}.tile.link .grp{display:none}
.tile .masq{display:none;position:absolute;right:8px;top:8px;width:26px;height:26px;border-radius:50%;border:1px solid #d5dccd;background:#fff;color:#999;cursor:pointer;font-size:.9rem;line-height:1}.tiles.edit .tile .masq{display:block}.tiles.edit .tile .grp,.tiles.edit .tile .tile-ico{display:none}
.tile strong .pi{width:30px;height:30px;color:var(--sapin)}
#tdbMasques{display:none;flex-wrap:wrap;gap:6px;margin:8px 0 0}#tdbMasques.on{display:flex}#tdbMasques button{border:1px dashed #c9d2bd;background:#fff;border-radius:999px;padding:5px 12px;cursor:pointer;font:inherit;font-size:.85rem}
.tiles .tile.cache{display:none}.tiles.edit .tile.cache{display:none}</style>
<div class="tdb-barre"><p class="lead" style="margin:0">Bonjour <?= e(user()['prenom']) ?>. Que veux-tu faire ?</p><button type="button" class="mini" id="tdbPerso" title="Déplacer les widgets, en masquer, en remettre">Personnaliser</button></div>
<div class="tiles" id="tiles">
<?php foreach ($ordre as $k): $t = $W[$k]; $cache = in_array($k, $masques, true); $img = $t['img'] ? '<img class="tile-ico" src="' . e($t['img']) . '" alt="" loading="lazy">' : ''; ?>
  <?php if ($t['ext'] && !$t['href']): ?><div class="tile link off<?= $cache ? ' cache' : '' ?>" data-w="<?= $k ?>" data-titre="<?= e($t['titre']) ?>"><?= $img ?><span><?= e($t['titre']) ?></span><em><?= e($t['detail']) ?></em><button type="button" class="masq" title="Masquer">✕</button></div>
  <?php else: ?><a class="tile<?= $t['ext'] ? ' link' : '' ?><?= $cache ? ' cache' : '' ?>" href="<?= e($t['href']) ?>" <?= $t['ext'] ? 'target="_blank" rel="noopener"' : '' ?> data-w="<?= $k ?>" data-titre="<?= e($t['titre']) ?>"><?= $img ?><?= $t['valeur'] !== '' ? '<strong>' . (str_starts_with($t['valeur'], '<svg') ? $t['valeur'] : e($t['valeur'])) . '</strong>' : '' ?><span><?= e($t['titre']) ?></span><em><?= e($t['detail']) ?></em><i class="grp"><?= e($t['groupe']) ?></i><button type="button" class="masq" title="Masquer">✕</button></a><?php endif; ?>
<?php endforeach; ?>
</div>
<div id="tdbMasques"></div>
<p class="small muted" id="tdbAide" hidden>Glisse les widgets pour les ordonner, ✕ pour en masquer un ; les widgets masqués se remettent en un clic ci-dessus. L'ordre est propre à ton compte.</p>
<?php if ($alertesPl): ?><div class="flash err" style="margin-top:14px"><strong>Planning : <?= count($alertesPl) ?> point(s) à regarder</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach (array_slice($alertesPl, 0, 5) as $a): ?><li><?= $a['id'] ? '<a href="planning.php?fiche=' . $a['id'] . '">' . e($a['txt']) . '</a>' : (!empty($a['url']) ? '<a href="' . e($a['url']) . '">' . e($a['txt']) . '</a>' : e($a['txt'])) ?></li><?php endforeach; ?></ul><a class="mini" href="planning.php" style="margin-top:6px">Ouvrir le planning</a></div><?php endif; ?>
<script>
(() => {
  const T = document.querySelector('#tiles'), B = document.querySelector('#tdbPerso'), M = document.querySelector('#tdbMasques'), CSRF = document.querySelector('meta[name=csrf]').content; let edit = false, drag = null;
  const prefs = () => ({ordre: [...T.querySelectorAll('.tile')].map(t => t.dataset.w), masques: [...T.querySelectorAll('.tile.cache')].map(t => t.dataset.w)});
  const sauver = () => fetch('index.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF': CSRF}, body: 'action=tdb&csrf=' + encodeURIComponent(CSRF) + '&prefs=' + encodeURIComponent(JSON.stringify(prefs()))}).catch(() => {});
  const rendreMasques = () => { const l = [...T.querySelectorAll('.tile.cache')]; M.innerHTML = l.length ? '<span class="small muted" style="align-self:center">Masqués :</span>' + l.map(t => `<button type="button" data-w="${t.dataset.w}">+ ${t.dataset.titre}</button>`).join('') : '<span class="small muted">Aucun widget masqué.</span>'; M.querySelectorAll('button').forEach(b => b.onclick = () => { T.querySelector(`.tile[data-w="${b.dataset.w}"]`).classList.remove('cache'); rendreMasques(); sauver(); }); };
  B.onclick = () => { edit = !edit; T.classList.toggle('edit', edit); B.classList.toggle('on', edit); B.textContent = edit ? 'Terminer' : 'Personnaliser'; M.classList.toggle('on', edit); document.querySelector('#tdbAide').hidden = !edit; T.querySelectorAll('.tile').forEach(t => { t.draggable = edit; if (t.tagName === 'A') t.onclick = ev => { if (edit) ev.preventDefault(); }; }); if (edit) rendreMasques(); };
  T.addEventListener('click', ev => { const b = ev.target.closest('.masq'); if (!b || !edit) return; ev.preventDefault(); ev.stopPropagation(); b.closest('.tile').classList.add('cache'); rendreMasques(); sauver(); });
  T.addEventListener('dragstart', ev => { if (!edit) return; drag = ev.target.closest('.tile'); drag.classList.add('drag'); ev.dataTransfer.effectAllowed = 'move'; try { ev.dataTransfer.setData('text/plain', drag.dataset.w); } catch (e) {} });
  T.addEventListener('dragover', ev => { if (!edit || !drag) return; ev.preventDefault(); const c = ev.target.closest('.tile'); if (!c || c === drag) return; T.querySelectorAll('.cible').forEach(x => x.classList.remove('cible')); c.classList.add('cible'); });
  T.addEventListener('drop', ev => { if (!edit || !drag) return; ev.preventDefault(); const c = ev.target.closest('.tile'); if (c && c !== drag) { const r = c.getBoundingClientRect(); const apres = (ev.clientX - r.left) > r.width / 2; c.parentNode.insertBefore(drag, apres ? c.nextSibling : c); sauver(); } });
  T.addEventListener('dragend', () => { T.querySelectorAll('.cible, .drag').forEach(x => x.classList.remove('cible', 'drag')); drag = null; });
  // tactile : appui long puis déplacement
  let tp = null; T.addEventListener('touchstart', ev => { if (!edit) return; const t = ev.target.closest('.tile'); if (!t) return; tp = t; t.classList.add('drag'); }, {passive: true});
  T.addEventListener('touchmove', ev => { if (!edit || !tp) return; ev.preventDefault(); const p = ev.touches[0]; const c = document.elementFromPoint(p.clientX, p.clientY)?.closest('.tile'); if (c && c !== tp) { const r = c.getBoundingClientRect(); c.parentNode.insertBefore(tp, (p.clientY > r.top + r.height / 2) ? c.nextSibling : c); } }, {passive: false});
  T.addEventListener('touchend', () => { if (tp) { tp.classList.remove('drag'); tp = null; sauver(); } });
})();
</script>
<?php page_end(); ?>
