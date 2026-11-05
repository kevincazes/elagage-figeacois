<?php
// Appli de terrain : mes rendez-vous du jour (km et temps depuis ma position), fiche avec boutons rapides, rapports
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$moi = q("SELECT * FROM personnel WHERE utilisateur_id = ? AND actif = 1 ORDER BY id LIMIT 1", [user()['id']])->fetch();
$persL = personnel_liste();
$pid = (int)($_GET['p'] ?? ($moi['id'] ?? 0));
$p = null; foreach ($persL as $x) if ((int)$x['id'] === $pid) $p = $x;
$jour = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['jour'] ?? '')) ? $_GET['jour'] : date('Y-m-d');
$evs = [];
if ($p) foreach (evt_periode("$jour 00:00:00", date('Y-m-d 00:00:00', strtotime("$jour +1 day"))) as $e) if (in_array($pid, $e['personnel'], true)) $evs[] = $e;
$noms = []; foreach (personnel_liste(true) as $x) $noms[(int)$x['id']] = $x['prenom'];
$mats = []; foreach (materiel_liste(true) as $m) $mats[(int)$m['id']] = $m['nom'];
$PICTOS = pictos_liste(); $mesTaches = ($moi && (int)$moi['id'] === $pid) ? mes_taches() : [];
$rapports = []; if ($evs) foreach (q("SELECT evenement_id, modele, signe_le, (SELECT COUNT(*) FROM rapport_photos ph WHERE ph.evenement_id = r.evenement_id) nb FROM rapports r WHERE evenement_id IN (" . implode(',', array_map(fn($e) => (int)$e['id'], $evs)) . ")") as $x) $rapports[(int)$x['evenement_id']] = $x;
$libJour = ($jour === date('Y-m-d') ? "Aujourd'hui " : ($jour === date('Y-m-d', strtotime('+1 day')) ? 'Demain ' : ($jour === date('Y-m-d', strtotime('-1 day')) ? 'Hier ' : ''))) . (function ($j) { $s = date_fr($j, false); return $s; })($jour);
$donnees = array_map(function ($e) use ($noms, $mats, $pid, $rapports, $PICTOS) {
    return ['id' => (int)$e['id'], 'type' => $e['type'], 'titre' => $e['titre'], 'statut' => $e['statut'], 'debut' => $e['debut'], 'fin' => $e['fin'], 'journee' => (int)$e['journee'],
        'client' => $e['client_nom'], 'tel' => $e['client_tel'], 'email' => $e['client_email'], 'client_id' => $e['client_id'] ? (int)$e['client_id'] : null,
        'adresse' => $e['adresse'], 'cp' => $e['cp'], 'ville' => $e['ville'], 'lat' => $e['lat'] !== null ? (float)$e['lat'] : null, 'lng' => $e['lng'] !== null ? (float)$e['lng'] : null,
        'consignes' => (string)$e['consignes'], 'phyto' => (int)$e['phyto'], 'motif' => (string)$e['motif'], 'pictos' => array_values(array_map(fn($c) => $PICTOS[$c], pictos_evt($e))), 'taches' => taches_liste((int)$e['id']), 'prestations' => prestations_liste((int)$e['id']), 'avec' => array_values(array_filter(array_map(fn($i) => $noms[$i] ?? null, array_diff($e['personnel'], [$pid])))),
        'materiel' => array_values(array_filter(array_map(fn($i) => $mats[$i] ?? null, $e['materiel']))), 'rapport' => $rapports[(int)$e['id']] ?? null, 'dict' => dict_alertes($e)];
}, $evs);
if ($p) { try { foreach (externes_periode("$jour 00:00:00", date('Y-m-d 00:00:00', strtotime("$jour +1 day")), $pid) as $x) $donnees[] = ['id' => $x['id'], 'externe' => 1, 'agenda' => $x['agenda'], 'couleur' => $x['couleur'], 'type' => 'externe', 'titre' => $x['titre'], 'statut' => 'confirme', 'debut' => $x['debut'], 'fin' => $x['fin'], 'journee' => $x['journee'], 'client' => '', 'tel' => '', 'email' => '', 'client_id' => null, 'adresse' => $x['adresse'], 'cp' => '', 'ville' => '', 'lat' => null, 'lng' => null, 'consignes' => '', 'phyto' => 0, 'motif' => '', 'pictos' => [], 'taches' => [], 'prestations' => [], 'avec' => [], 'materiel' => [], 'rapport' => null, 'dict' => []]; } catch (Throwable $t) {} }
usort($donnees, fn($a, $b) => strcmp($a['debut'], $b['debut']));
page_start('Mon planning', 'mon-planning.php');
?>
<style>
main{max-width:720px}
.lst{list-style:none;margin:6px 0 0;padding:0}.lst li{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px dashed #e4e8de;font-size:.92rem}.lst li.fait .l{text-decoration:line-through;opacity:.6}.lst .l{flex:1;min-width:0}.lst .qui{font-size:.78rem;color:var(--doux);white-space:nowrap}.lst li.retard .qui{color:#e6332a;font-weight:700}.lst input[type=checkbox]{width:24px!important;height:24px;margin:0!important;flex:none}
.rdv.externe{opacity:.8;background-image:repeating-linear-gradient(45deg,rgba(0,0,0,.025) 0 6px,transparent 6px 12px)}
.pictos{letter-spacing:1px;margin-right:4px}.ft-bloc .pictos{font-size:1.3rem}
.mp-haut{background:var(--brun);color:#fff;border-radius:0 0 26px 26px;margin:-22px -20px 16px;padding:14px 16px 16px;text-align:center}
.mp-haut h2{color:#fff;margin:0 0 8px;font-size:1.25rem}.mp-nav{display:flex;align-items:center;justify-content:space-between;gap:10px}
.mp-nav a{color:#fff;text-decoration:none;font-size:1.6rem;font-weight:900;padding:0 12px}.mp-nav span{font-weight:700;text-transform:capitalize}
.mp-outils{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center;margin:0 0 12px}.mp-outils select{width:auto;margin:0}
.rdv{display:grid;grid-template-columns:96px 1fr;background:var(--blanc);border:1px solid #e1e5da;border-radius:16px;margin:0 0 12px;overflow:hidden;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.rdv .h{background:#f1efe9;display:grid;place-items:center;align-content:center;gap:4px;padding:10px 4px;font-size:.78rem;color:var(--doux);text-align:center}
.rdv .h svg{width:58px;height:58px}.rdv .c{padding:10px 12px;min-width:0}
.rdv .c .l1{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}.rdv .c .hr{font-weight:900;font-size:1.15rem;color:var(--brun)}
.rdv .c .t{font-size:1rem;margin:2px 0;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.rdv .c .v{color:var(--doux);font-size:.92rem;text-transform:uppercase}.rdv .c .km{display:flex;flex-wrap:wrap;gap:4px 14px;font-weight:700;font-size:.9rem;margin-top:4px;color:var(--texte)}.rdv .c .km span{white-space:nowrap}
main>h1{position:absolute;left:-9999px}
.badge{border-radius:999px;padding:3px 10px;font-size:.78rem;font-weight:700;white-space:nowrap;color:#fff;background:#3b8f9a}.badge.prochain{background:var(--rouge)}.badge.cours{background:var(--anis)}.badge.fini{background:#9aa29c}
.vide{text-align:center;color:var(--doux);padding:30px 0}
.fiche-t{position:fixed;inset:0;z-index:120;background:var(--creme);overflow:auto;display:none}.fiche-t.on{display:block}
.fiche-t .ft-haut{background:var(--brun);color:#fff;padding:12px 16px 18px;padding-top:max(12px,env(safe-area-inset-top));border-radius:0 0 26px 26px}
.fiche-t .ft-haut button{background:none;border:0;color:#fff;font:inherit;font-weight:700;font-size:1rem;cursor:pointer;padding:0}
.fiche-t h3{color:#fff;margin:10px 0 2px;font-size:1.1rem;text-align:center}.fiche-t .ft-haut small{display:block;text-align:center;opacity:.85}
.boutons{display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin:-4px 0 14px;padding:0 2px}.boutons a{width:46px;height:46px;border-radius:50%;background:#efe3d5;display:grid;place-items:center;font-size:1.35rem;text-decoration:none;box-shadow:0 2px 6px rgba(0,0,0,.08)}
.boutons a.off{opacity:.3;pointer-events:none}
.ft-corps{padding:0 16px 40px;max-width:720px;margin:0 auto}.ft-bloc{background:var(--blanc);border:1px solid #e1e5da;border-radius:14px;padding:12px 14px;margin:0 0 10px}
.ft-bloc b{color:var(--brun)}.gros{display:block;text-align:center;border-radius:999px;padding:12px;font-weight:700;text-decoration:none;margin:10px auto;max-width:360px;background:#efe3d5;color:var(--brun)}
.notif-ok{color:var(--anis);font-weight:700}
@media(max-width:900px){.mp-haut{margin-left:-12px;margin-right:-12px;margin-top:-14px}}
</style>
<div class="mp-haut">
  <h2><?= count($evs) ?> rendez-vous</h2>
  <div class="mp-nav"><a href="?p=<?= $pid ?>&jour=<?= date('Y-m-d', strtotime("$jour -1 day")) ?>" aria-label="Jour précédent">‹</a><span><?= e($libJour) ?></span><a href="?p=<?= $pid ?>&jour=<?= date('Y-m-d', strtotime("$jour +1 day")) ?>" aria-label="Jour suivant">›</a></div>
</div>
<div class="mp-outils">
  <form><input type="hidden" name="jour" value="<?= e($jour) ?>"><select name="p" onchange="this.form.submit()" aria-label="Agenda de"><option value="">— Choisir —</option><?php foreach ($persL as $x): ?><option value="<?= $x['id'] ?>" <?= (int)$x['id'] === $pid ? 'selected' : '' ?>><?= e($x['prenom'] . ' ' . $x['nom']) ?><?= $moi && (int)$moi['id'] === (int)$x['id'] ? ' (moi)' : '' ?></option><?php endforeach; ?></select></form>
  <?php if ($jour !== date('Y-m-d')): ?><a class="mini" href="?p=<?= $pid ?>">Aujourd'hui</a><?php endif; ?>
  <button class="mini" type="button" id="notif" hidden>🔔 Activer les notifications</button>
  <?php if ($p && $p['ics_token'] && (($moi && (int)$moi['id'] === $pid) || planning_niveau() === 'tout')): ?><button type="button" class="mini" onclick="navigator.clipboard.writeText(this.dataset.l).then(()=>this.textContent='Lien copié !')" data-l="<?= e(lien_base() . '/planning-ics.php?t=' . $p['ics_token']) ?>" title="Google Agenda : « À partir de l'URL ». iPhone : Réglages › Calendrier › Comptes › Ajouter un compte › Autre › Calendrier avec abonnement.">📅 Lien agenda</button><?php endif; ?>
</div>
<?php if (!$moi): ?><p class="muted small" style="text-align:center">Ton compte n'est relié à aucune fiche du personnel : demande à l'administrateur de le relier (Planning › Personnel, matériel, équipes).</p><?php endif; ?>
<?php if ($mesTaches): ?><div class="card" id="mt" style="padding:10px 14px;margin-bottom:12px"><b>☐ Mes actions à faire (<?= count($mesTaches) ?>)</b>
  <ul class="lst"><?php foreach ($mesTaches as $t): $ret = $t['echeance'] && $t['echeance'] < date('Y-m-d'); ?><li class="<?= $ret ? 'retard' : '' ?>"><input type="checkbox" data-t="<?= $t['id'] ?>" aria-label="Fait"><span class="l"><?= e($t['libelle']) ?><br><small class="muted"><?= e($t['titre']) ?><?= $t['ville'] ? ' – ' . e($t['ville']) : '' ?></small></span><span class="qui"><?= $t['echeance'] ? 'avant le ' . date('d/m', strtotime($t['echeance'])) : ($t['debut'] ? 'chantier ' . date('d/m', strtotime($t['debut'])) : '') ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
<div id="liste"><?php if (!$p): ?><p class="vide">Choisis une personne pour voir ses rendez-vous.</p><?php elseif (!$evs): ?><p class="vide">Rien de prévu ce jour-là.</p><?php endif; ?></div>
<div class="fiche-t" id="ft" aria-modal="true" role="dialog"></div>
<script>
const EVS = <?= json_encode($donnees, JSON_UNESCAPED_UNICODE) ?>, TYPES = Object.assign(<?= json_encode(TYPES_EVT, JSON_UNESCAPED_UNICODE) ?>, {externe: ['Autre agenda', '#9aa5b1']}), JOUR = <?= json_encode($jour) ?>;
const FACT = <?= (float)setting('planning_facteur', '1.3') ?>, VIT = <?= (float)setting('planning_vitesse', '55') ?>, DEP = <?= json_encode(depot()) ?>;
const $ = s => document.querySelector(s), esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);
const p2 = n => String(n).padStart(2, '0'), hm = s => s.slice(11, 16), parse = s => new Date(s.replace(' ', 'T'));
const km = (a, b, c, d) => { const r = 6371, x = (c - a) * Math.PI / 180, y = (d - b) * Math.PI / 180, h = Math.sin(x / 2) ** 2 + Math.cos(a * Math.PI / 180) * Math.cos(c * Math.PI / 180) * Math.sin(y / 2) ** 2; return 2 * r * Math.asin(Math.min(1, Math.sqrt(h))) * FACT; };
let ICI = null;
function anneau(e) { // portion de la journée occupée (comme un cadran)
  const d = parse(e.debut), f = parse(e.fin), a0 = ((d.getHours() + d.getMinutes() / 60) % 12) / 12 * 360, a1 = a0 + Math.min(359, (f - d) / 36e5 / 12 * 360);
  const pt = a => { const r = (a - 90) * Math.PI / 180; return [29 + 23 * Math.cos(r), 29 + 23 * Math.sin(r)]; }; const [x0, y0] = pt(a0), [x1, y1] = pt(a1);
  const c = e.externe ? (e.couleur || '#9aa5b1') : (TYPES[e.type] ? TYPES[e.type][1] : '#888');
  return `<svg viewBox="0 0 58 58"><circle cx="29" cy="29" r="23" fill="none" stroke="#d7d2c8" stroke-width="2" stroke-dasharray="1.5 3"/><path d="M${x0} ${y0} A23 23 0 ${a1 - a0 > 180 ? 1 : 0} 1 ${x1} ${y1}" fill="none" stroke="${c}" stroke-width="5" stroke-linecap="round"/></svg>`;
}
function badge(e, prochain) {
  const n = new Date(), d = parse(e.debut), f = parse(e.fin);
  if (e.statut === 'termine' || f < n) return '<span class="badge fini">Terminé</span>';
  if (d <= n && f >= n) return '<span class="badge cours">En cours</span>';
  return e.id === prochain ? '<span class="badge prochain">➜ Prochain</span>' : '<span class="badge">À venir</span>';
}
function rendre() {
  if (!EVS.length) return;
  const n = new Date(), proch = (EVS.find(e => parse(e.debut) > n && e.statut !== 'termine') || {}).id;
  let prec = ICI || (DEP.lat ? {lat: DEP.lat, lng: DEP.lng} : null);
  $('#liste').innerHTML = EVS.map((e, i) => {
    let dist = '';
    if (e.lat && prec) { const k = km(prec.lat, prec.lng, e.lat, e.lng); dist = `<span>📍 ${k < 10 ? k.toFixed(1) : Math.round(k)} km</span><span>⏱ ${Math.max(1, Math.round(k / VIT * 60))} min</span>`; }
    if (e.lat && i === 0 && !ICI) dist += '<span class="muted" style="font-weight:400">depuis le dépôt</span>';
    if (e.lat) prec = {lat: e.lat, lng: e.lng};
    return `<div class="rdv${e.externe ? ' externe' : ''}" data-i="${i}"><div class="h">${anneau(e)}${e.journee ? 'Journée' : hm(e.debut) + ' – ' + hm(e.fin)}</div><div class="c">
      <div class="l1"><span class="hr">${e.journee ? (TYPES[e.type] || ['RDV'])[0] : hm(e.debut)}</span>${badge(e, proch)}</div>
      <div class="t">${e.pictos.length ? '<span class="pictos" title="' + esc(e.pictos.map(p => p.libelle).join('\n')) + '">' + e.pictos.map(p => p.emoji).join('') + '</span>' : ''}${esc(e.client || e.titre)}${e.taches.some(t => !t.fait_le) ? ' <span class="muted">☐' + e.taches.filter(t => !t.fait_le).length + '</span>' : ''}${e.client && e.titre !== e.client ? ' <span class="muted">· ' + esc(e.titre) + '</span>' : ''}</div><div class="v">${esc([e.cp, e.ville].filter(Boolean).join(' '))}${e.motif ? ' · <span style="text-transform:none">' + esc(e.motif) + '</span>' : ''}</div>
      <div class="km">${dist}${e.rapport ? `<span title="Rapport">📝${e.rapport.nb ? ' ' + e.rapport.nb + ' 📷' : ''}${e.rapport.signe_le ? ' ✓' : ''}</span>` : ''}${e.dict.length ? '<span style="color:#e6332a" title="' + esc(e.dict.join(' ')) + '">⚠ DT-DICT</span>' : ''}</div></div></div>`;
  }).join('');
  document.querySelectorAll('.rdv').forEach(d => d.onclick = () => ouvrir(EVS[+d.dataset.i]));
}
function ouvrir(e) {
  if (e.externe) { $('#ft').innerHTML = `<div class="ft-haut"><button type="button" id="ftR">‹ Retour</button><h3>${esc(e.titre)}</h3><small>${esc(e.agenda || 'Autre agenda')} · ${e.journee ? 'journée' : hm(e.debut) + ' – ' + hm(e.fin)}</small></div><div class="ft-corps">${e.adresse ? `<div class="ft-bloc">📍 ${esc(e.adresse)}</div>` : ''}<p class="muted small" style="text-align:center">Événement de ton autre calendrier (lecture seule) : il se modifie dans l'application d'origine.</p></div>`; $('#ft').classList.add('on'); document.body.style.overflow = 'hidden'; history.pushState({f: e.id}, '', '#x'); $('#ftR').onclick = () => history.back(); return; }
  const tel = (e.tel || '').replace(/[^0-9+]/g, ''), dest = e.lat ? e.lat + ',' + e.lng : encodeURIComponent([e.adresse, e.cp, e.ville].filter(Boolean).join(' '));
  const k = e.lat && ICI ? km(ICI.lat, ICI.lng, e.lat, e.lng) : null;
  const b = (h, ic, t, ok = true) => `<a href="${esc(h)}" ${h.startsWith('http') ? 'target="_blank" rel="noopener"' : ''} class="${ok ? '' : 'off'}" title="${t}" aria-label="${t}">${ic}</a>`;
  $('#ft').innerHTML = `<div class="ft-haut"><button type="button" id="ftR">‹ Retour</button><h3>${esc(e.titre)}</h3><small>${esc(e.motif || (TYPES[e.type] || ['RDV'])[0])} · ${e.journee ? 'journée' : hm(e.debut) + ' – ' + hm(e.fin)}${k ? ` · ${Math.round(k)} km · ${Math.round(k / VIT * 60)} min, arrivée vers ${(() => { const t = new Date(Date.now() + k / VIT * 36e5); return p2(t.getHours()) + ':' + p2(t.getMinutes()); })()}` : ''}</small></div>
    <div class="ft-corps"><div class="boutons" style="margin-top:14px">
      ${b('https://www.google.com/maps/dir/?api=1&destination=' + dest, '🧭', 'Itinéraire Google Maps', !!dest)}${b(e.lat ? 'https://waze.com/ul?ll=' + e.lat + ',' + e.lng + '&navigate=yes' : '#', '🚗', 'Waze', !!e.lat)}
      ${b(e.lat ? 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' + e.lat + ',' + e.lng : '#', '🏠', 'Street View (voir la rue)', !!e.lat)}${b('tel:' + tel, '📞', 'Appeler', !!tel)}${b('sms:' + tel, '💬', 'SMS', !!tel)}${b('mailto:' + (e.email || ''), '✉️', 'E-mail', !!e.email)}${b('rapport.php?evt=' + e.id, '📷', 'Photos et rapport')}</div>
      ${e.client || e.adresse || e.ville ? `<div class="ft-bloc"><b>${esc(e.client || 'Lieu')}</b>${e.adresse ? '<br>' + esc(e.adresse) : ''}${e.cp || e.ville ? '<br>' + esc([e.cp, e.ville].filter(Boolean).join(' ')) : ''}${tel ? '<br>📞 ' + esc(e.tel) : ''}${e.email ? '<br>✉️ ' + esc(e.email) : ''}${e.client_id ? `<br><a href="clients.php?id=${e.client_id}">Fiche client ›</a>` : ''}</div>` : ''}
      ${e.avec.length ? `<div class="ft-bloc">👥 Avec <b>${esc(e.avec.join(', '))}</b></div>` : ''}
      ${e.materiel.length ? `<div class="ft-bloc">🚜 ${esc(e.materiel.join(', '))}</div>` : ''}
      ${e.pictos.length ? `<div class="ft-bloc"><span class="pictos">${e.pictos.map(p => p.emoji).join(' ')}</span><br><small>${e.pictos.map(p => esc(p.libelle)).join(' · ')}</small></div>` : ''}
      ${e.taches.length ? `<div class="ft-bloc"><b>☐ Actions à faire avant</b><ul class="lst">${e.taches.map(t => `<li class="${t.fait_le ? 'fait' : ''} ${t.retard ? 'retard' : ''}"><input type="checkbox" data-t="${t.id}" ${t.fait_le ? 'checked' : ''}><span class="l">${esc(t.libelle)}</span><span class="qui">${t.prenom ? esc(t.prenom) : ''}${t.echeance ? ' · ' + t.echeance.slice(8, 10) + '/' + t.echeance.slice(5, 7) : ''}</span></li>`).join('')}</ul></div>` : ''}
      ${e.prestations.length ? `<div class="ft-bloc"><b>✓ Relevé de chantier – prestations du devis</b> <small class="muted">(${e.prestations.filter(p => p.fait_le).length}/${e.prestations.length})</small><ul class="lst">${e.prestations.map(p => `<li class="${p.fait_le ? 'fait' : ''}"><input type="checkbox" data-p="${p.id}" ${p.fait_le ? 'checked' : ''}><span class="l">${esc(p.libelle)}${p.quantite !== null ? ' <span class="muted">(' + (+p.quantite).toLocaleString('fr-FR') + (p.unite ? ' ' + esc(p.unite) : '') + ')</span>' : ''}</span></li>`).join('')}</ul></div>` : ''}
      ${e.consignes ? `<div class="ft-bloc" style="background:#fff8e6">⚠ ${esc(e.consignes).replace(/\n/g, '<br>')}</div>` : ''}
      ${e.dict.length ? `<div class="ft-bloc" style="color:#e6332a">${e.dict.map(esc).join('<br>')}</div>` : ''}
      ${e.phyto ? `<a class="gros" href="phyto.php?passeport=${e.id}&ecran=1" style="background:#e6f0d8">🌳 Passeport phytosanitaire (platane) ›</a>` : ''}
      ${['chantier', 'visite', 'rdv'].includes(e.type) ? `<a class="gros" href="rapport.php?evt=${e.id}">📋 ${e.type === 'chantier' ? 'Bon de fin d\'intervention' : 'Rapport'} ›</a>` : ''}
      <a class="gros" href="planning.php?fiche=${e.id}&jour=${JOUR}" style="background:#fff;border:1px solid #e1e5da">Ouvrir dans le planning ›</a></div>`;
  $('#ft').classList.add('on'); document.body.style.overflow = 'hidden';
  history.pushState({f: e.id}, '', '#evt' + e.id);
  $('#ftR').onclick = () => history.back();
  cocher($('#ft'), e);
}
async function apiPost(a, corps) { const r = await fetch('planning_api.php?a=' + a, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': document.querySelector('meta[name=csrf]').content}, body: JSON.stringify(corps)}); const j = await r.json().catch(() => ({erreur: 'Réponse illisible.'})); if (j.erreur) throw new Error(j.erreur); return j; }
function cocher(zone, e) {
  zone.querySelectorAll('input[data-t]').forEach(c => c.onchange = async () => { const li = c.closest('li'); try { const r = await apiPost('tache', {op: c.checked ? 'fait' : 'defait', id: +c.dataset.t}); li.classList.toggle('fait', c.checked); if (e) { e.taches = r.taches; rendre(); } else if (c.checked) setTimeout(() => li.remove(), 600); } catch (err) { alert(err.message); c.checked = !c.checked; } });
  zone.querySelectorAll('input[data-p]').forEach(c => c.onchange = async () => { const li = c.closest('li'); try { const r = await apiPost('prestation', {op: c.checked ? 'fait' : 'defait', id: +c.dataset.p}); li.classList.toggle('fait', c.checked); if (e) e.prestations = r.prestations; } catch (err) { alert(err.message); c.checked = !c.checked; } });
}
if ($('#mt')) cocher($('#mt'), null);
window.addEventListener('popstate', () => { $('#ft').classList.remove('on'); document.body.style.overflow = ''; });
rendre();
const deep = (location.hash.match(/evt(\d+)/) || new URLSearchParams(location.search).get('evt') && [0, new URLSearchParams(location.search).get('evt')] || [])[1];
if (deep) { const e = EVS.find(x => x.id === +deep); if (e) { history.replaceState(null, '', location.pathname + location.search.replace(/[?&]evt=\d+/, '')); ouvrir(e); } }
if (navigator.geolocation && EVS.some(e => e.lat)) navigator.geolocation.getCurrentPosition(p => { ICI = {lat: p.coords.latitude, lng: p.coords.longitude}; rendre(); }, () => {}, {maximumAge: 300000, timeout: 8000});
</script>
<?php page_end(); ?>
