<?php
// Planning interactif des chantiers, visites et rendez-vous
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
planning_taches();
$bords = q("SELECT id, nom FROM bordereaux ORDER BY cree_le DESC LIMIT 200")->fetchAll();
$nouvClient = null;
if (!empty($_GET['nouveau_client']) && ($ncl = q("SELECT * FROM clients WHERE id = ?", [(int)$_GET['nouveau_client']])->fetch())) {
    $nad = q("SELECT * FROM client_adresses WHERE client_id = ? ORDER BY ordre, id LIMIT 1", [$ncl['id']])->fetch() ?: [];
    $nouvClient = ['client_id' => (int)$ncl['id'], 'client_nom' => $ncl['nom'], 'client_tel' => $ncl['mobile'] ?: $ncl['telephone'], 'client_email' => $ncl['email'], 'adresse' => $nad['adresse'] ?? '', 'cp' => $nad['cp'] ?? '', 'ville' => $nad['ville'] ?? '', 'lat' => $nad['lat'] ?? null, 'lng' => $nad['lng'] ?? null];
}
page_start('Planning', 'planning.php');
?>
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<style>
main.large{max-width:none}
.pl-barre{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}
.pl-barre .grp{display:inline-flex;border:1px solid #d5dccd;border-radius:999px;overflow:hidden;background:var(--blanc)}
.pl-barre .grp button{border:0;background:transparent;padding:7px 14px;font:inherit;font-weight:700;cursor:pointer;color:var(--sapin)}
.pl-barre .grp button.on{background:var(--sapin);color:#fff}
.pl-barre .periode{font-weight:900;font-size:1.1rem;color:var(--sapin);min-width:210px;text-align:center}
.pl-barre select{width:auto;margin:0;padding:6px 10px}
.pl-barre .grp.principal button{background:var(--vert,#8caa31);color:#fff}
.plus{display:contents}.plus summary{display:none;list-style:none;cursor:pointer}.plus summary::-webkit-details-marker{display:none}.plus-l{display:contents}
.filtres{margin:-4px 0 12px}.filtres summary{display:none;list-style:none;cursor:pointer}.filtres summary::-webkit-details-marker{display:none}
@media(max-width:700px){
  .pl-barre{gap:6px}.pl-barre #vues{flex:1 0 100%;overflow-x:auto;scrollbar-width:none;border-radius:12px}.pl-barre #vues button{flex:1 0 auto;padding:9px 11px;font-size:.92rem}
  .pl-barre .grp.principal{order:5;flex:1 0 62%}.pl-barre .grp.principal button{width:100%;padding:10px}.pl-barre #fPers{flex:1 1 0;min-width:0}.pl-barre .grp button{padding:8px 11px}
  .plus{display:block;order:6;position:relative}.plus summary{display:inline-block;padding:9px 14px}.plus[open] summary{background:var(--sapin);color:#fff;border-color:var(--sapin)}
  .plus-l{display:flex;flex-wrap:wrap;gap:6px;position:absolute;right:0;top:calc(100% + 6px);z-index:30;background:var(--blanc);border:1px solid #d5dccd;border-radius:12px;padding:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);min-width:260px}
  .filtres summary{display:inline-block;font-size:.8rem;padding:4px 10px}.filtres{margin:0 0 10px}.filtres .pl-filtres{margin-top:6px}
}
.pl-filtres{display:flex;gap:6px;flex-wrap:wrap}
.pl-filtres label{display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:700;font-size:.82rem;padding:4px 10px;border-radius:999px;border:1px solid #d5dccd;background:var(--blanc);cursor:pointer}
.pl-filtres input{width:auto;margin:0}
.pl-cadre{display:grid;grid-template-columns:200px minmax(0,1fr) 300px;gap:14px;align-items:start}.pl-gauche{position:sticky;top:calc(var(--bandeau,70px) + 10px)}.pl-gauche .card{padding:12px 14px;margin:0 0 12px}.pl-gauche h3{margin:0 0 8px;font-size:.95rem}.pl-filtres.col{flex-direction:column;gap:4px}.pl-filtres.col label{justify-content:flex-start}
@media(min-width:1101px){details.filtres,#fPers{display:none}}
.pl-cote{position:sticky;top:calc(var(--bandeau,0px) + 8px);display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - var(--bandeau,0px) - 20px);overflow:auto}
.pl-cote .card{margin:0;padding:12px 14px}.pl-cote h3{margin:0 0 8px;font-size:1rem}
.pl-zone{background:var(--blanc);border-radius:var(--rayon);box-shadow:0 4px 18px rgba(28,59,43,.06);overflow:auto;position:relative}
/* Semaine */
.sem{display:grid;grid-template-columns:52px repeat(7,minmax(110px,1fr));min-width:860px;user-select:none}
.sem .tete{position:sticky;top:0;z-index:4;background:#2f4f3a;color:#fff;padding:7px 6px;font-weight:700;font-size:.85rem;text-align:center;border-left:1px solid rgba(255,255,255,.15)}
.sem .tete.auj{background:var(--anis);color:#1c3b2b}.sem .tete small{display:block;font-weight:400;font-size:.75rem;opacity:.9}
.sem .bande{grid-column:1/-1;display:grid;grid-template-columns:52px repeat(7,minmax(110px,1fr));border-bottom:2px solid #d5dccd;background:#f6f8f2;min-height:34px;position:relative}
.sem .bande .lib{font-size:.7rem;color:var(--doux);padding:6px 4px}
.sem .bande .cel{border-left:1px solid #e6eadf;min-height:34px}
.sem .heures{position:relative}.sem .heures div{height:var(--h);font-size:.72rem;color:var(--doux);text-align:right;padding-right:6px;border-top:1px solid #edf0e8}
.sem .col{position:relative;border-left:1px solid #e6eadf;background:repeating-linear-gradient(#fff 0 calc(var(--h) - 1px),#edf0e8 calc(var(--h) - 1px) var(--h))}
.carte-vue #carteG{height:calc(100vh - var(--bandeau,70px) - 260px);min-height:420px;border-radius:12px;border:1px solid #e1e5da}
.sem .trajet{position:absolute;z-index:1;font-size:.66rem;line-height:1;padding:2px 4px;border-radius:4px;background:repeating-linear-gradient(135deg,#e3e6de 0 4px,#eef0ea 4px 8px);color:#4a5a4f;overflow:hidden;white-space:nowrap;pointer-events:auto}.sem .trajet.court{background:repeating-linear-gradient(135deg,#f9d3cf 0 4px,#fde8e6 4px 8px);color:#a12e28;font-weight:700}
.sem .col.we{background:repeating-linear-gradient(#fafbf8 0 calc(var(--h) - 1px),#edf0e8 calc(var(--h) - 1px) var(--h))}
.sem .col .horsh{position:absolute;left:0;right:0;background:rgba(28,59,43,.035);pointer-events:none}
.sem .maintenant{position:absolute;left:0;right:0;height:2px;background:#d64545;z-index:3;pointer-events:none}
.evt{position:absolute;left:3px;right:3px;border-radius:7px;padding:3px 6px;font-size:.78rem;line-height:1.25;color:#fff;overflow:hidden;cursor:grab;box-shadow:0 1px 3px rgba(0,0,0,.18);z-index:2;border-left:4px solid rgba(0,0,0,.25)}
.evt b{display:block;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.evt .h{opacity:.9;font-size:.72rem}
.evt.propose{background-image:repeating-linear-gradient(135deg,rgba(255,255,255,.18) 0 6px,transparent 6px 12px)}
.evt.termine{opacity:.55}.evt.a_planifier{outline:2px dashed #fff;outline-offset:-3px}
.evt .poignee{position:absolute;left:0;right:0;bottom:0;height:7px;cursor:ns-resize}
.evt.glisse{opacity:.85;z-index:20;box-shadow:0 8px 20px rgba(0,0,0,.3);cursor:grabbing}
.puce{display:block;border-radius:6px;padding:3px 7px;margin:3px;font-size:.76rem;color:#fff;cursor:grab;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-shadow:0 1px 2px rgba(0,0,0,.15)}
.puce.propose{background-image:repeating-linear-gradient(135deg,rgba(255,255,255,.2) 0 6px,transparent 6px 12px)}.puce.termine{opacity:.55}
.alerte-ic{display:inline-block;background:#fff;color:#c0392b;border-radius:50%;width:15px;height:15px;font-size:.7rem;line-height:15px;text-align:center;font-weight:900;margin-right:3px}
.meteo{font-size:.72rem;opacity:.95}.meteo.fort{color:#ffd43b;font-weight:900}
.leaflet-control-locate{width:34px;height:34px;line-height:34px;text-align:center;font-size:1.15rem;background:#fff;text-decoration:none;display:block}.leaflet-control-locate.attente{opacity:.5;animation:clig 1s infinite}@keyframes clig{50%{opacity:1}}
.evt.externe,.puce.externe{background-image:repeating-linear-gradient(45deg,rgba(255,255,255,.22) 0 5px,transparent 5px 10px);cursor:default}
.pictos{display:inline-flex;gap:3px;margin-right:5px;vertical-align:-3px}.pictos .pi{width:15px;height:15px;stroke-width:2.4}.chips .pi{width:20px;height:20px;color:var(--brun)}.lst .pi{width:16px;height:16px;color:var(--brun)}.badge-t{display:inline-block;font-size:.68rem;font-weight:800;background:rgba(255,255,255,.9);color:#444;border-radius:9px;padding:0 5px;margin-left:3px;vertical-align:middle}.badge-t.retard{background:#e6332a;color:#fff}.badge-t.ok{color:#5a7a1e}
.chips{display:flex;flex-wrap:wrap;gap:6px}.chips label{display:inline-flex;align-items:center;gap:4px;border:1px solid #d9dfd2;border-radius:16px;padding:3px 9px 3px 6px;font-size:.82rem;cursor:pointer;background:#fff}.chips label:has(input:checked){background:#eef3e4;border-color:var(--vert,#8caa31);font-weight:700}.chips input{width:auto!important;margin:0!important}
.lst{list-style:none;margin:0;padding:0}.lst li{display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px dashed #e4e8de;font-size:.88rem}.lst li.fait span.l{text-decoration:line-through;opacity:.6}.lst li .l{flex:1;min-width:0}.lst li .qui{font-size:.75rem;color:var(--doux);white-space:nowrap}.lst li.retard .qui{color:#e6332a;font-weight:700}.lst input[type=checkbox]{width:18px!important;height:18px;margin:0!important}.lst .x{background:none;border:0;color:#999;cursor:pointer;font-size:1rem;padding:0 4px}.lst .x:hover{color:#e6332a}
.ajout{display:grid;grid-template-columns:1fr auto auto auto;gap:6px;margin-top:6px;align-items:center}.ajout input,.ajout select{margin:0;padding:6px 8px;font-size:.85rem}@media(max-width:700px){.ajout{grid-template-columns:1fr 1fr}}
#mt .lst li{font-size:.82rem}
/* Mois */
.mois{display:grid;grid-template-columns:repeat(7,minmax(110px,1fr));min-width:780px}
.mois .tete{background:#2f4f3a;color:#fff;padding:7px;font-weight:700;font-size:.85rem;text-align:center}
.mois .jour{min-height:118px;border:1px solid #e6eadf;padding:2px;position:relative}
.mois .jour .n{font-size:.78rem;font-weight:700;color:var(--doux);padding:2px 5px}.mois .jour.hors{background:#fafbf8}.mois .jour.hors .n{opacity:.45}.mois .jour.auj .n{background:var(--anis);color:#1c3b2b;border-radius:999px;display:inline-block}
.cible{outline:3px solid var(--anis)!important;outline-offset:-3px;background:#f0f6e4!important}
/* Ressources */
.res{display:grid;min-width:1100px;user-select:none}
.res .tete{position:sticky;top:0;z-index:4;background:#2f4f3a;color:#fff;padding:6px 4px;font-weight:700;font-size:.78rem;text-align:center;border-left:1px solid rgba(255,255,255,.15)}
.res .tete.we{background:#4c6b57}.res .tete.auj{background:var(--anis);color:#1c3b2b}
.res .nom{position:sticky;left:0;z-index:3;background:var(--blanc);padding:7px 8px;font-weight:700;font-size:.85rem;border-top:1px solid #e6eadf;display:flex;gap:7px;align-items:center;box-shadow:4px 0 8px -6px rgba(0,0,0,.2)}
.res .nom small{display:block;font-weight:400;color:var(--doux);font-size:.72rem}
.res .nom i{width:10px;height:10px;border-radius:50%;flex:none}
.res .sect{grid-column:1/-1;background:var(--clair);font-weight:900;color:var(--sapin);font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;padding:6px 10px;position:sticky;left:0}
.res .cel{border-top:1px solid #e6eadf;border-left:1px solid #edf0e8;min-height:44px;padding:1px}.res .cel.we{background:#fafbf8}.res .cel.libre:hover{background:#f6f9f1}
/* Liste */
.liste-j{border-bottom:1px solid #e6eadf;padding:10px 14px}.liste-j h4{margin:0 0 6px;font-size:.95rem;color:var(--sapin)}
.liste-e{display:flex;gap:10px;align-items:flex-start;padding:7px 8px;border-radius:8px;cursor:pointer}.liste-e:hover{background:#f6f9f1}
.liste-e .bar{width:5px;align-self:stretch;border-radius:3px;flex:none}.liste-e .h{min-width:92px;font-weight:700;font-size:.85rem}.liste-e .t{flex:1;min-width:0}.liste-e small{color:var(--doux);display:block}
/* À planifier, alertes */
.ap{border:1px solid #e0e5da;border-left:5px solid #888;border-radius:8px;padding:7px 9px;margin:0 0 7px;background:#fff;cursor:grab;font-size:.85rem}
.ap b{display:block}.ap small{color:var(--doux)}.ap .mini{margin-top:5px}
.al{font-size:.82rem;padding:6px 8px;border-radius:7px;margin:0 0 6px;cursor:pointer;line-height:1.35}
.al.haut{background:#fbe9e7;color:#8c2f26}.al.moyen{background:#fff4dc;color:#7a5200}.al.info{background:var(--clair);color:var(--sapin)}
/* Fiche */
.fiche-fond{position:fixed;inset:0;background:rgba(28,59,43,.35);z-index:70}
.fiche{position:fixed;top:0;right:0;bottom:0;width:min(640px,100vw);background:var(--blanc);z-index:71;display:flex;flex-direction:column;box-shadow:-10px 0 30px rgba(0,0,0,.25)}
.fiche header{padding:14px 18px;border-bottom:1px solid #e6eadf;display:flex;gap:10px;align-items:center}.fiche header h2{margin:0;font-size:1.2rem;flex:1}
.fiche .corps{flex:1;overflow:auto;padding:14px 18px 30px}.fiche footer{padding:12px 18px;border-top:1px solid #e6eadf;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.fiche fieldset{border:1px solid #e6eadf;border-radius:10px;padding:10px 12px;margin:0 0 12px}.fiche legend{font-weight:900;color:var(--sapin);padding:0 6px;font-size:.9rem}
.fiche .g2{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}.fiche .g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 10px}
.fiche label{margin:6px 0}.fiche .types{display:flex;gap:6px;flex-wrap:wrap}
.fiche .types label,.fiche .choix label{display:inline-flex;gap:6px;align-items:center;margin:3px 4px 3px 0;font-weight:700;font-size:.83rem;padding:5px 10px;border:1px solid #d5dccd;border-radius:999px;cursor:pointer;background:#fff}
.fiche .types input,.fiche .choix input{width:auto;margin:0}.fiche .choix label.occ{border-color:#e5a29a;background:#fdf1ef}.fiche .choix label.occ::after{content:'occupé';font-size:.7rem;color:#e6332a;font-weight:700}
.fiche .choix label small{font-weight:400;color:var(--doux)}
.fiche .alertes{margin:0 0 10px}.fiche .alertes div{background:#fbe9e7;color:#8c2f26;border-radius:7px;padding:6px 9px;margin:0 0 5px;font-size:.85rem}
.fiche .ok-vert{background:#eaf4dc;color:#2f6b45;border-radius:7px;padding:6px 9px;font-size:.85rem;margin:0 0 10px}
.ac{position:relative}.ac ul{position:absolute;left:0;right:0;top:100%;z-index:5;background:#fff;border:1px solid #d5dccd;border-radius:8px;list-style:none;margin:2px 0 0;padding:4px;box-shadow:0 6px 16px rgba(0,0,0,.12)}
.ac li{padding:6px 8px;border-radius:6px;cursor:pointer;font-size:.88rem}.ac li:hover,.ac li.on{background:var(--clair)}
.creneaux button{display:block;width:100%;text-align:left;margin:4px 0;padding:8px 10px;border:1px solid #d5dccd;border-radius:8px;background:#fff;font:inherit;cursor:pointer}.creneaux button:hover{border-color:var(--anis);background:#f6f9f1}
.creneaux small{color:var(--doux)}
.reponse{border-radius:8px;padding:9px 11px;margin:0 0 10px;font-size:.9rem}.reponse.ok{background:#eaf4dc;color:#2f6b45}.reponse.autre{background:#fff4dc;color:#7a5200}
.journal{font-size:.8rem;color:var(--doux);margin:0;padding-left:16px}
/* Tournée */
.tournee{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:12px;padding:12px}
#carte{height:520px;border-radius:10px}
#carte,#carteG{position:relative;z-index:1;isolation:isolate} /* la carte reste sous l'en-tête et ses menus déroulants */
.etape{display:flex;gap:10px;align-items:center;padding:7px 9px;border:1px solid #e6eadf;border-radius:8px;margin:0 0 6px;background:#fff}
.etape .num{width:26px;height:26px;border-radius:50%;background:var(--sapin);color:#fff;font-weight:900;display:grid;place-items:center;flex:none}
.cmp{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 10px}.cmp div{background:var(--clair);border-radius:8px;padding:9px 11px}.cmp strong{font-size:1.2rem;color:var(--sapin)}
.legende{display:flex;gap:10px;flex-wrap:wrap;font-size:.78rem;color:var(--doux);margin:8px 0 0}.legende span{display:inline-flex;gap:5px;align-items:center}.legende i{width:12px;height:12px;border-radius:3px;display:inline-block}
@media(max-width:1100px){.pl-cadre{grid-template-columns:1fr}.pl-cote{position:static;max-height:none}.tournee{grid-template-columns:1fr}.pl-gauche{display:none}}
@media(max-width:700px){.fiche .g2,.fiche .g3{grid-template-columns:1fr}.pl-barre .periode{min-width:0;flex:1 0 100%;order:-1}}
</style>

<?= pictos_sprite() ?>
<div class="pl-barre">
  <div class="grp" id="vues"><button data-v="semaine">Semaine</button><button data-v="mois">Mois</button><button data-v="ressources">Ressources</button><button data-v="liste">Liste</button><button data-v="carte">Carte</button><button data-v="tournee">Tournée</button></div>
  <div class="grp"><button id="prec" title="Précédent">‹</button><button id="auj">Aujourd'hui</button><button id="suiv" title="Suivant">›</button></div>
  <span class="periode" id="periode"></span>
  <select id="fPers" title="N'afficher qu'une personne"><option value="">Tout le personnel</option></select>
  <div class="grp principal"><button id="nouveau" title="Créer">+ Nouveau</button></div>
  <details class="plus"><summary class="mini">Plus ▾</summary><div class="plus-l">
  <a class="mini" href="planning-ressources.php" id="lienRes">Personnel, matériel, équipes</a>
  <a class="mini" href="mon-planning.php">Mon planning</a>
  <a class="mini" href="clients.php">Clients</a><a class="mini" href="conges.php">Congés</a>
  <a class="mini" href="materiel.php">Matériel</a>
  <?php if (planning_niveau() === 'tout'): ?><a class="mini" href="contrats.php">Contrats</a><a class="mini" href="charge.php">Tableau de charge</a><a class="mini" href="phyto.php">Platanes</a><?php endif; ?>
  <?php if (est_admin()): ?><a class="mini" href="reglages-planning.php" title="SMS, avis Google, notifications, Pennylane, Urgence Élagage">⚙ Réglages</a><?php endif; ?>
  </div></details>
</div>
<p class="flash" id="astreinte" hidden style="margin:0 0 10px;background:#fdecea;border-color:#f3b4ae"></p>
<details class="filtres" open><summary class="mini">Types affichés</summary><div class="pl-filtres" id="fTypes"></div></details>
<p class="flash" id="lecture" hidden style="margin:0 0 10px">Tu consultes les agendas de toute l'équipe. Pour modifier, demande à un administrateur de t'en donner le droit.</p>
<div class="pl-cadre">
  <aside class="pl-gauche">
    <section class="card"><h3>Agendas</h3><div class="pl-filtres" id="fTypesG"></div><p class="small" style="margin:8px 0 0"><a href="#" id="typesTous">Tout</a> · <a href="#" id="typesAucun">Aucun</a></p></section>
    <section class="card"><h3>Personnel</h3><div class="pl-filtres col" id="fPersG"></div><p class="small" style="margin:8px 0 0"><a href="#" id="persTous">Tout le monde</a></p></section>
  </aside>
  <div class="pl-zone" id="zone"></div>
  <aside class="pl-cote">
    <section class="card gestion"><h3>À planifier <span class="muted" id="nbAp"></span></h3><p class="small muted" style="margin:0 0 8px">Glisse une fiche sur un jour (semaine, mois ou ressources), ou ouvre-la et clique « Trouver un créneau ».</p><div id="ap"></div></section>
    <section class="card" id="mt" hidden><h3>Mes actions à faire</h3><ul class="lst" id="mtL"></ul></section>
    <section class="card gestion"><h3>Alertes</h3><div id="alertes"></div></section>
    <section class="card"><h3>Légende</h3><div class="legende" id="legende"></div><p class="small muted" style="margin:8px 0 0">Hachuré : date proposée, pas encore confirmée par le client. Pointillés : à planifier. <span class="alerte-ic">!</span> conflit à vérifier. 🌬 rafales fortes annoncées.</p></section>
  </aside>
</div>
<div id="ficheHote"></div>

<script src="assets/leaflet/leaflet.js"></script>
<script>
const KLEYK = <?= json_encode(setting('lien_kleyk')) ?>;
const CSRF = <?= json_encode(csrf()) ?>;
const TYPES = Object.assign(<?= json_encode(TYPES_EVT, JSON_UNESCAPED_UNICODE) ?>, {externe: ['Autre agenda', '#9aa5b1']}), STATUTS = <?= json_encode(STATUTS_EVT, JSON_UNESCAPED_UNICODE) ?>;
const COMPS = <?= json_encode(COMPETENCES, JSON_UNESCAPED_UNICODE) ?>, TMAT = <?= json_encode(TYPES_MAT, JSON_UNESCAPED_UNICODE) ?>;
const BORDS = <?= json_encode($bords, JSON_UNESCAPED_UNICODE) ?>, PL = <?= pennylane_pret() && planning_niveau() === 'tout' ? 'true' : 'false' ?>, NOUV_CLIENT = <?= json_encode($nouvClient, JSON_UNESCAPED_UNICODE) ?>;
const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'], MOIS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
const H0 = 7, H1 = 20, PXH = 44; // grille horaire : 7 h à 20 h, 44 px par heure
let S = {vue: localStorage.getItem('pl_vue') || (innerWidth < 760 ? 'liste' : 'semaine'), ref: new Date(), data: null, types: JSON.parse(localStorage.getItem('pl_types') || 'null') || Object.keys(TYPES), pers: (JSON.parse(localStorage.getItem('pl_pers') || '[]') || []).map(Number).filter(Boolean)};
try { if (!localStorage.getItem('pl_types_x')) { if (!S.types.includes('externe')) S.types.push('externe'); localStorage.setItem('pl_types_x', '1'); } } catch (e) {}
try { const u = new URLSearchParams(location.search); if (u.get('jour')) S.ref = new Date(u.get('jour') + 'T12:00'); if (u.get('vue')) S.vue = u.get('vue'); } catch (e) {}

// ---------- outils ----------
const $ = s => document.querySelector(s), esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
const p2 = n => String(n).padStart(2, '0'), ymd = d => d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());
const dts = d => ymd(d) + ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes()) + ':00', parse = s => s ? new Date(s.replace(' ', 'T')) : null;
const plus = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; }, lundi = d => { const x = new Date(d); x.setHours(0, 0, 0, 0); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; };
const hm = d => p2(d.getHours()) + ':' + p2(d.getMinutes()), jourCourt = d => JOURS[d.getDay()] + ' ' + d.getDate() + '/' + p2(d.getMonth() + 1);
const memeJour = (a, b) => ymd(a) === ymd(b), auj = ymd(new Date());
const eur = v => v == null ? '' : Number(v).toLocaleString('fr-FR', {style: 'currency', currency: 'EUR'});
async function api(a, params = {}, corps = null) {
  const q = new URLSearchParams(Object.assign({a}, params));
  const r = await fetch('planning_api.php?' + q, corps ? {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': CSRF}, body: JSON.stringify(corps)} : {});
  const j = await r.json().catch(() => ({erreur: 'Réponse illisible du serveur.'}));
  if (j.erreur) throw new Error(j.erreur); return j;
}
function toast(m, err) { const t = document.createElement('div'); t.className = 'flash ' + (err ? 'err' : 'ok'); t.textContent = m; Object.assign(t.style, {position: 'fixed', left: '50%', bottom: '24px', transform: 'translateX(-50%)', zIndex: 90, maxWidth: '90vw', boxShadow: '0 6px 20px rgba(0,0,0,.2)'}); document.body.appendChild(t); setTimeout(() => t.remove(), 4200); }
const couleur = e => e.externe ? (e.couleur || '#9aa5b1') : (TYPES[e.type] ? TYPES[e.type][1] : '#666');
const persNom = id => { const p = (S.data?.personnel || []).find(x => +x.id === +id); return p ? p.prenom : '?'; };
const matNom = id => { const m = (S.data?.materiel || []).find(x => +x.id === +id); return m ? m.nom : '?'; };
// Droits : consultation de tous les agendas ; modification si toutes les personnes concernées sont autorisées
const peutModif = e => { if (e.externe) return false; const d = S.data?.droits; if (!d) return false; if (d.modifiables === null) return true; return e.personnel.length > 0 && e.personnel.every(p => d.modifiables.includes(+p)); };
const toutDroit = () => S.data?.droits?.niveau === 'tout';
// Matériel lié : outils montés sur un même porteur = une seule machine
const racineMat = id => { let m = (S.data?.materiel || []).find(x => +x.id === +id), n = 0; while (m && m.porteur_id && n++ < 10) m = S.data.materiel.find(x => +x.id === +m.porteur_id) || null; return m ? +m.id : +id; };
const equipeDe = id => (S.data?.equipes || []).find(x => +x.id === +id);

// ---------- période affichée ----------
function bornes() {
  if (S.vue === 'mois') { const d = new Date(S.ref.getFullYear(), S.ref.getMonth(), 1); const du = lundi(d); return [du, plus(du, 42)]; }
  if (S.vue === 'ressources' || S.vue === 'carte') { const du = lundi(S.ref); return [du, plus(du, 14)]; }
  if (S.vue === 'liste') { const du = new Date(S.ref); du.setHours(0, 0, 0, 0); return [du, plus(du, 21)]; }
  if (S.vue === 'tournee') { const du = new Date(S.ref); du.setHours(0, 0, 0, 0); return [du, plus(du, 1)]; }
  const du = lundi(S.ref); return [du, plus(du, 7)];
}
function libellePeriode() {
  const [du, au] = bornes(), f = plus(au, -1);
  if (S.vue === 'mois') return MOIS[S.ref.getMonth()] + ' ' + S.ref.getFullYear();
  if (S.vue === 'carte') { const f = plus(au, -1); return du.getDate() + ' ' + MOIS[du.getMonth()].slice(0, 3) + '. – ' + f.getDate() + ' ' + MOIS[f.getMonth()].slice(0, 3) + '. ' + f.getFullYear(); }
  if (S.vue === 'tournee') return JOURS[du.getDay()] + ' ' + du.getDate() + ' ' + MOIS[du.getMonth()];
  return du.getDate() + ' ' + MOIS[du.getMonth()].slice(0, 3) + '. – ' + f.getDate() + ' ' + MOIS[f.getMonth()].slice(0, 3) + '. ' + f.getFullYear();
}
async function charger() {
  const [du, au] = bornes();
  try { S.data = await api('liste', {du: ymd(du), au: ymd(au)}); } catch (e) { $('#zone').innerHTML = '<p class="flash err" style="margin:14px">' + esc(e.message) + '</p>'; return; }
  rendre(); meteo();
}
const visibles = () => (S.data?.evenements || []).filter(e => S.types.includes(e.type) && (!S.pers.length || e.personnel.some(p => S.pers.includes(+p))));
const persVisibles = () => (S.data?.personnel || []).filter(p => !S.pers.length || S.pers.includes(+p.id));

// ---------- rendu général ----------
function rendre() {
  document.querySelectorAll('#vues button').forEach(b => b.classList.toggle('on', b.dataset.v === S.vue));
  $('#periode').textContent = libellePeriode();
  const fp = $('#fPers'); if (fp.options.length <= 1) (S.data.personnel || []).forEach(p => fp.add(new Option(p.prenom + ' ' + p.nom, p.id)));
  fp.value = S.pers.length === 1 ? String(S.pers[0]) : ''; filtresGauche();
  ({semaine: vueSemaine, mois: vueMois, ressources: vueRessources, liste: vueListe, tournee: vueTournee, carte: vueCarte})[S.vue]();
  document.querySelectorAll('.pl-cote .card.gestion').forEach(c => c.style.display = toutDroit() ? '' : 'none');
  $('#nouveau').parentElement.style.display = S.data.droits.planifier ? '' : 'none';
  $('#lecture').hidden = S.data.droits.planifier;
  const ast = S.data.astreinte; $('#astreinte').hidden = !ast;
  if (ast) { const n = l => (l || []).map(p => p.nom).join(', ') || '—'; $('#astreinte').innerHTML = `🚨 <strong>D'astreinte Urgence Élagage</strong> (${esc(ast.creneau || '')}) : équipe ${esc(n(ast.equipe_1))} · régulation ${esc(n(ast.regulation_1))}`; }
  // à planifier
  rendreMesTaches(S.data.mes_taches || []);
  const ap = S.data.a_planifier || []; $('#nbAp').textContent = ap.length ? '(' + ap.length + ')' : '';
  $('#ap').innerHTML = ap.length ? ap.map(e => `<div class="ap" draggable="true" data-id="${e.id}" style="border-left-color:${couleur(e)}"><b>${pictosHtml(e)}${esc(e.titre)}${badgeTaches(e)}</b><small>${esc(TYPES[e.type][0])}${e.ville ? ' · ' + esc(e.ville) : ''}${e.nb_jours ? ' · ' + (+e.nb_jours) + ' j' : (e.duree_h ? ' · ' + (+e.duree_h) + ' h' : '')}${+e.devis_signe ? ' · devis signé' : ''}${e.devis_montant ? ' · ' + eur(e.devis_montant) : ''}${e.souhait_client ? '<br>Souhait : ' + esc(e.souhait_client) : ''}</small><br><button class="mini" data-cren="${e.id}">Trouver un créneau</button></div>`).join('') : '<p class="muted small" style="margin:0">Rien en attente.</p>';
  document.querySelectorAll('#ap .ap').forEach(c => { c.addEventListener('dragstart', ev => { ev.dataTransfer.setData('text/plain', 'ap:' + c.dataset.id); }); c.addEventListener('click', ev => { if (ev.target.dataset.cren) { ouvrirFiche(+c.dataset.id, {creneaux: true}); return; } ouvrirFiche(+c.dataset.id); }); });
  // alertes
  const al = S.data.alertes || [];
  $('#alertes').innerHTML = al.length ? al.slice(0, 40).map(a => `<div class="al ${a.niv}" data-id="${a.id}" data-url="${esc(a.url || '')}">${esc(a.txt)}</div>`).join('') : '<p class="muted small" style="margin:0">Aucune alerte.</p>';
  document.querySelectorAll('#alertes .al').forEach(d => d.addEventListener('click', () => { if (+d.dataset.id) ouvrirFiche(+d.dataset.id); else if (d.dataset.url) location.href = d.dataset.url; }));
  $('#legende').innerHTML = Object.entries(TYPES).map(([k, [l, c]]) => `<span><i style="background:${c}"></i>${l}</span>`).join('');
}
function filtresTypes() {
  $('#fTypes').innerHTML = Object.entries(TYPES).map(([k, [l, c]]) => `<label style="border-color:${c}"><input type="checkbox" value="${k}" ${S.types.includes(k) ? 'checked' : ''}><i style="width:9px;height:9px;border-radius:50%;background:${c};display:inline-block"></i>${l}</label>`).join('');
  if (matchMedia('(max-width:700px)').matches && S.types.length === Object.keys(TYPES).length) $('.filtres').open = false;
  $('#fTypes').querySelectorAll('input').forEach(i => i.addEventListener('change', () => { S.types = [...$('#fTypes').querySelectorAll('input:checked')].map(x => x.value); try { localStorage.setItem('pl_types', JSON.stringify(S.types)); } catch (e) {} rendre(); }));
}
function filtresGauche() {
  const g = $('#fTypesG'); g.innerHTML = Object.entries(TYPES).map(([k, [l, c]]) => `<label style="border-color:${c}"><input type="checkbox" value="${k}" ${S.types.includes(k) ? 'checked' : ''}><i style="width:9px;height:9px;border-radius:50%;background:${c};display:inline-block"></i>${esc(l)}</label>`).join('');
  g.querySelectorAll('input').forEach(i => i.addEventListener('change', () => { S.types = [...g.querySelectorAll('input:checked')].map(x => x.value); try { localStorage.setItem('pl_types', JSON.stringify(S.types)); } catch (e) {} filtresTypes(); rendre(); }));
  const pg = $('#fPersG'); pg.innerHTML = (S.data.personnel || []).map(p => `<label style="border-color:${p.couleur}"><input type="checkbox" value="${p.id}" ${!S.pers.length || S.pers.includes(+p.id) ? 'checked' : ''}><i style="width:9px;height:9px;border-radius:50%;background:${p.couleur};display:inline-block"></i>${esc(p.prenom)}</label>`).join('') || '<span class="muted small">Aucune personne enregistrée.</span>';
  pg.querySelectorAll('input').forEach(i => i.addEventListener('change', () => { const coches = [...pg.querySelectorAll('input:checked')].map(x => +x.value); S.pers = coches.length === (S.data.personnel || []).length ? [] : coches; try { localStorage.setItem('pl_pers', JSON.stringify(S.pers)); } catch (e) {} $('#fPers').value = S.pers.length === 1 ? String(S.pers[0]) : ''; S.vue === 'tournee' ? vueTournee() : rendre(); }));
  $('#typesTous').onclick = ev => { ev.preventDefault(); S.types = Object.keys(TYPES); try { localStorage.setItem('pl_types', JSON.stringify(S.types)); } catch (e) {} filtresTypes(); rendre(); };
  $('#typesAucun').onclick = ev => { ev.preventDefault(); S.types = []; try { localStorage.setItem('pl_types', '[]'); } catch (e) {} filtresTypes(); rendre(); };
  $('#persTous').onclick = ev => { ev.preventDefault(); S.pers = []; try { localStorage.setItem('pl_pers', '[]'); } catch (e) {} $('#fPers').value = ''; S.vue === 'tournee' ? vueTournee() : rendre(); };
}
const PICTO = c => (S.data?.pictos || []).find(p => p.code === c);
const pictosHtml = e => { const l = (e.pictos_l || []).map(PICTO).filter(Boolean); if (+e.phyto && !l.some(p => p.code === 'platane') && PICTO('platane')) l.push(PICTO('platane')); return l.length ? '<span class="pictos" title="' + esc(l.map(p => p.libelle).join('\n')) + '">' + l.map(p => p.html).join('') + '</span>' : ''; };
const badgeTaches = e => e.taches_restantes ? `<span class="badge-t ${e.taches_retard ? 'retard' : ''}" title="${e.taches_restantes} action(s) à faire avant le chantier${e.taches_retard ? ' – ' + e.taches_retard + ' en retard' : ''}">☐${e.taches_restantes}</span>` : (e.taches_nb ? '<span class="badge-t ok" title="Toutes les actions préparatoires sont faites">✓</span>' : '');
const titreEvt = e => (e.alertes && e.alertes.length ? '<span class="alerte-ic" title="' + esc(e.alertes.join('\n')) + '">!</span>' : '') + pictosHtml(e) + esc(e.titre) + badgeTaches(e) + (e._meteo ? ' <span class="meteo ' + (e._meteo.fort ? 'fort' : '') + '">' + e._meteo.txt + '</span>' : '');
const infoBulle = e => [(e.motif || TYPES[e.type][0]) + ' – ' + STATUTS[e.statut], e.titre, e.client_nom, [e.adresse, e.cp, e.ville].filter(Boolean).join(' '), e.personnel.length ? 'Équipe : ' + e.personnel.map(persNom).join(', ') : '', e.materiel.length ? 'Matériel : ' + e.materiel.map(matNom).join(', ') : '', ...(e.alertes || []).map(a => '⚠ ' + a)].filter(Boolean).join('\n');
const ferie = d => (S.data?.reglages?.feries || {})[ymd(d)] || '', we = d => d.getDay() % 6 === 0 || !!ferie(d);
const secteursJour = d => (S.data?.secteurs || []).filter(x => String(x.jours || '').split(',').includes(String(d.getDay()))).map(x => `<span style="display:inline-block;background:${x.couleur};color:#fff;border-radius:999px;padding:0 6px;font-size:.68rem;font-weight:700;margin:1px">${esc(x.nom)}</span>`).join('');
const FACT = <?= (float)setting('planning_facteur', '1.3') ?>, VIT = <?= max(10, (float)setting('planning_vitesse', '55')) ?>;
const kmVol = (a, b, c, d) => { const r = 6371, x = (c - a) * Math.PI / 180, y = (d - b) * Math.PI / 180, h = Math.sin(x / 2) ** 2 + Math.cos(a * Math.PI / 180) * Math.cos(c * Math.PI / 180) * Math.sin(y / 2) ** 2; return 2 * r * Math.asin(Math.min(1, Math.sqrt(h))) * FACT; };
const estBande = e => +e.journee === 1 || !memeJour(parse(e.debut), new Date(parse(e.fin) - 1));

// ---------- vue semaine ----------
function vueSemaine() {
  const [du] = bornes(), jours = [...Array(7)].map((_, i) => plus(du, i)), ev = visibles();
  const [hd, md] = S.data.reglages.debut.split(':').map(Number), [hf, mf] = S.data.reglages.fin.split(':').map(Number);
  let h = `<div class="sem" style="--h:${PXH}px"><div class="tete"></div>` + jours.map(d => `<div class="tete ${ymd(d) === auj ? 'auj' : ''}" data-j="${ymd(d)}" title="${esc(ferie(d))}">${jourCourt(d)}${ferie(d) ? ' <span style="font-weight:400">· férié</span>' : ''}<small class="mj" data-j="${ymd(d)}"></small>${secteursJour(d) ? '<div>' + secteursJour(d) + '</div>' : ''}</div>`).join('');
  h += `<div class="bande"><div class="lib">Journée</div>` + jours.map(d => `<div class="cel" data-j="${ymd(d)}"></div>`).join('') + `</div>`;
  h += `<div class="heures">` + [...Array(H1 - H0)].map((_, i) => `<div>${H0 + i} h</div>`).join('') + `</div>`;
  h += jours.map(d => `<div class="col ${we(d) ? 'we' : ''}" data-j="${ymd(d)}" style="height:${(H1 - H0) * PXH}px"><div class="horsh" style="top:0;height:${(hd + md / 60 - H0) * PXH}px"></div><div class="horsh" style="top:${(hf + mf / 60 - H0) * PXH}px;bottom:0"></div></div>`).join('') + `</div>`;
  $('#zone').innerHTML = h;
  // bande « journée » : chantiers sur journée(s) entière(s), plusieurs jours, absences
  const bande = $('.sem .bande'); const lignes = [];
  ev.filter(estBande).forEach(e => {
    const d0 = parse(e.debut), d1 = new Date(parse(e.fin) - 1);
    const c0 = Math.max(0, Math.round((new Date(ymd(d0)) - new Date(ymd(du))) / 864e5)), c1 = Math.min(6, Math.round((new Date(ymd(d1)) - new Date(ymd(du))) / 864e5));
    if (c1 < 0 || c0 > 6) return;
    let l = 0; while ((lignes[l] || []).some(([a, b]) => !(c1 < a || c0 > b))) l++; (lignes[l] = lignes[l] || []).push([c0, c1]);
    const x = document.createElement('div'); x.className = 'puce ' + e.statut + (e.externe ? ' externe' : ''); x.style.cssText = `position:absolute;left:calc(52px + (100% - 52px) * ${c0} / 7 + 2px);width:calc((100% - 52px) * ${c1 - c0 + 1} / 7 - 6px);top:${3 + l * 26}px;margin:0;background:${couleur(e)}`;
    x.innerHTML = titreEvt(e) + (e.personnel.length ? ' · <span style="opacity:.85">' + e.personnel.map(persNom).join(', ') + '</span>' : ''); x.title = infoBulle(e); x.dataset.id = e.id;
    bande.appendChild(x); glisserJour(x, e, d => { const n = Math.round((d - new Date(ymd(d0))) / 864e5); return n; });
  });
  bande.style.minHeight = (8 + Math.max(1, lignes.length) * 26) + 'px';
  // événements horaires, colonnes partagées en cas de chevauchement
  jours.forEach((d, ci) => {
    const col = document.querySelectorAll('.sem .col')[ci];
    const du2 = ev.filter(e => !estBande(e) && memeJour(parse(e.debut), d)).sort((a, b) => parse(a.debut) - parse(b.debut));
    const voies = []; du2.forEach(e => { let v = 0; while ((voies[v] || 0) > parse(e.debut)) v++; voies[v] = parse(e.fin); e._v = v; });
    du2.forEach(e => {
      const a = parse(e.debut), b = parse(e.fin), top = Math.max(0, (a.getHours() + a.getMinutes() / 60 - H0) * PXH), ht = Math.max(20, (b - a) / 36e5 * PXH - 2), nv = Math.max(1, voies.length);
      const x = document.createElement('div'); x.className = 'evt ' + e.statut + (e.externe ? ' externe' : ''); x.dataset.id = e.id;
      x.style.cssText = `top:${top}px;height:${ht}px;background:${couleur(e)};left:calc(${e._v} * 100% / ${nv} + 2px);width:calc(100% / ${nv} - 5px);right:auto`;
      x.innerHTML = `<b>${titreEvt(e)}</b><span class="h">${hm(a)}–${hm(b)}${e.ville ? ' · ' + esc(e.ville) : ''}</span>${e.personnel.length ? '<span class="h"> · ' + e.personnel.map(persNom).join(', ') + '</span>' : ''}<div class="poignee"></div>`;
      x.title = infoBulle(e); col.appendChild(x); glisserHoraire(x, e, du);
    });
    // temps de route entre deux rendez-vous consécutifs d'une même personne (bloc gris « 🚗 »), rouge si l'écart ne suffit pas
    const geo = du2.filter(e => e.lat && e.lng); const vus = new Set();
    geo.forEach(e => e.personnel.forEach(p => { const suiv = geo.filter(o => o !== e && o.personnel.includes(p) && parse(o.debut) >= parse(e.fin)).sort((a, b) => parse(a.debut) - parse(b.debut))[0]; if (!suiv) return;
      const k = e.id + '-' + suiv.id; if (vus.has(k)) return; vus.add(k);
      const km = kmVol(+e.lat, +e.lng, +suiv.lat, +suiv.lng), mn = Math.max(1, Math.round(km / VIT * 60)), ecart = (parse(suiv.debut) - parse(e.fin)) / 6e4; if (mn < 5) return;
      const t = document.createElement('div'); t.className = 'trajet' + (ecart < mn ? ' court' : ''); const a = parse(e.fin);
      t.style.cssText = `top:${(a.getHours() + a.getMinutes() / 60 - H0) * PXH}px;height:${Math.max(14, Math.min(ecart, mn) / 60 * PXH)}px;left:calc(${e._v} * 100% / ${Math.max(1, voies.length)} + 2px);width:calc(100% / ${Math.max(1, voies.length)} - 5px)`;
      t.textContent = '🚗 ' + mn + ' min'; t.title = `${persNom(p)} : ${Math.round(km)} km ≈ ${mn} min de route jusqu'à « ${suiv.titre} »` + (ecart < mn ? ` — écart de ${Math.round(ecart)} min seulement` : ''); col.appendChild(t); }));
    if (ymd(d) === auj) { const n = new Date(); const m = document.createElement('div'); m.className = 'maintenant'; m.style.top = ((n.getHours() + n.getMinutes() / 60 - H0) * PXH) + 'px'; col.appendChild(m); }
    // clic sur un créneau vide : nouvelle visite à cette heure
    col.addEventListener('dblclick', ev2 => { if (ev2.target !== col && !ev2.target.classList.contains('horsh')) return; const mn = Math.round(((ev2.offsetY / PXH) + H0) * 4) / 4; const t = new Date(d); t.setHours(Math.floor(mn), (mn % 1) * 60, 0, 0); nouvelle('visite', {debut: dts(t), fin: dts(new Date(+t + 36e5))}); });
    deposerAP(col, (ev2) => { const mn = Math.round(((ev2.offsetY / PXH) + H0) * 4) / 4; const t = new Date(d); t.setHours(Math.floor(mn), (mn % 1) * 60, 0, 0); return t; });
  });
  document.querySelectorAll('.sem .bande .cel').forEach(c => { deposerAP(c, () => null, c.dataset.j); c.addEventListener('dblclick', () => nouvelle('chantier', jourEntier(c.dataset.j, 1))); });
  const sc = $('#zone'); if (!sc.dataset.init) { sc.scrollTop = (7.5 - H0) * PXH; sc.dataset.init = 1; }
}
function jourEntier(j, n) { const d0 = new Date(j + 'T' + S.data.reglages.debut), d1 = new Date(plus(new Date(j + 'T12:00'), Math.max(1, Math.ceil(n || 1)) - 1)); const [h, m] = S.data.reglages.fin.split(':'); d1.setHours(+h, +m, 0, 0); return {debut: dts(d0), fin: dts(d1), journee: 1}; }

// Glisser un événement horaire (semaine) : déplacer (jour + heure) ou étirer (fin)
function glisserHoraire(el, e, du) {
  if (!peutModif(e)) { el.style.cursor = 'pointer'; el.querySelector('.poignee')?.remove(); el.addEventListener('click', () => ouvrirFiche(e.id)); return; }
  el.addEventListener('pointerdown', p0 => {
    if (p0.button !== 0) return; const redim = p0.target.classList.contains('poignee'); p0.preventDefault();
    const a = parse(e.debut), b = parse(e.fin), colW = el.parentElement.getBoundingClientRect().width; let dx = 0, dy = 0, bouge = false;
    el.setPointerCapture(p0.pointerId);
    const mv = p => { dx = p.clientX - p0.clientX; dy = p.clientY - p0.clientY; if (Math.abs(dx) + Math.abs(dy) > 4) bouge = true; if (!bouge) return; el.classList.add('glisse');
      if (redim) el.style.height = Math.max(20, (b - a) / 36e5 * PXH + Math.round(dy / (PXH / 4)) * (PXH / 4)) + 'px';
      else el.style.transform = `translate(${Math.round(dx / colW) * colW}px, ${Math.round(dy / (PXH / 4)) * (PXH / 4)}px)`; };
    const up = async () => { el.removeEventListener('pointermove', mv); el.removeEventListener('pointerup', up);
      if (!bouge) { ouvrirFiche(e.id); return; }
      const dq = Math.round(dy / (PXH / 4)) * 15 * 6e4, dj = redim ? 0 : Math.round(dx / colW);
      const na = redim ? a : new Date(+plus(a, dj) + dq), nb = redim ? new Date(Math.max(+a + 15 * 6e4, +b + dq)) : new Date(+plus(b, dj) + dq);
      await deplacer(e, na, nb); };
    el.addEventListener('pointermove', mv); el.addEventListener('pointerup', up);
  });
}
// Glisser une puce (jour entier, mois, ressources) : vers une autre case jour (et ressource)
function glisserJour(el, e, _f, opts = {}) {
  if (!peutModif(e)) { el.style.cursor = 'pointer'; el.addEventListener('click', () => ouvrirFiche(e.id)); return; }
  el.addEventListener('pointerdown', p0 => {
    if (p0.button !== 0) return; p0.preventDefault(); let bouge = false, cible = null; const fant = el.cloneNode(true);
    const mv = p => { if (!bouge && Math.abs(p.clientX - p0.clientX) + Math.abs(p.clientY - p0.clientY) < 5) return;
      if (!bouge) { bouge = true; Object.assign(fant.style, {position: 'fixed', pointerEvents: 'none', zIndex: 80, width: el.offsetWidth + 'px', opacity: .9}); document.body.appendChild(fant); }
      fant.style.left = (p.clientX - 20) + 'px'; fant.style.top = (p.clientY - 12) + 'px';
      const t = document.elementFromPoint(p.clientX, p.clientY)?.closest('[data-j]'); if (cible && cible !== t) cible.classList.remove('cible'); cible = t; if (cible) cible.classList.add('cible'); };
    const up = async () => { document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up); fant.remove(); if (cible) cible.classList.remove('cible');
      if (!bouge) { ouvrirFiche(e.id); return; } if (!cible) return;
      const a = parse(e.debut), b = parse(e.fin), cj = new Date(cible.dataset.j + 'T12:00'), oj = new Date((opts.jourOrigine || ymd(a)) + 'T12:00');
      const dj = Math.round((cj - oj) / 864e5); const vers = cible.dataset.res || null, de = opts.res || null;
      if (!dj && (!vers || vers === de)) return;
      await deplacer(e, plus(a, dj), plus(b, dj), de, vers); };
    document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
  });
}
async function deplacer(e, a, b, de, vers) {
  try { const r = await api('deplacer', {}, {id: e.id, debut: dts(a), fin: dts(b), de, vers});
    toast('Déplacé : ' + e.titre + ' → ' + jourCourt(a) + (estBande(e) ? '' : ' ' + hm(a)) + (r.reconfirmer ? ' — à reconfirmer par le client (statut repassé en « proposé »)' : '') + (r.alertes.length ? ' — ⚠ ' + r.alertes[0] : ''), r.alertes.length > 0); }
  catch (err) { toast(err.message, true); }
  charger();
}
// Déposer une fiche « à planifier » sur une case
function deposerAP(zone, heureDe, jourFixe, res) {
  zone.addEventListener('dragover', ev => { if ([...ev.dataTransfer.types].includes('text/plain')) { ev.preventDefault(); zone.classList.add('cible'); } });
  zone.addEventListener('dragleave', () => zone.classList.remove('cible'));
  zone.addEventListener('drop', async ev => {
    ev.preventDefault(); zone.classList.remove('cible'); const v = ev.dataTransfer.getData('text/plain'); if (!v.startsWith('ap:')) return;
    const id = +v.slice(3), e = (S.data.a_planifier || []).find(x => +x.id === id); if (!e) return;
    let deb, fin; const t = heureDe(ev);
    if (t && e.type !== 'chantier') { deb = t; fin = new Date(+t + (+e.duree_h || 1) * 36e5); }
    else { const j = jourFixe || ymd(t || new Date()); const r = jourEntier(j, +e.nb_jours || Math.ceil((+e.duree_h || 0) / 8) || 1); deb = parse(r.debut); fin = parse(r.fin); }
    try { await api('deplacer', {}, {id, debut: dts(deb), fin: dts(fin), vers: res || null, de: null}); if (e.type === 'chantier' && !res) toast('Date posée. Complète l\'équipe et le matériel dans la fiche.'); } catch (err) { toast(err.message, true); }
    await charger(); ouvrirFiche(id);
  });
}

// ---------- vue mois ----------
function vueMois() {
  const [du] = bornes(), m = S.ref.getMonth(), ev = visibles();
  let h = '<div class="mois">' + ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'].map(j => `<div class="tete">${j}</div>`).join('');
  for (let i = 0; i < 42; i++) { const d = plus(du, i); h += `<div class="jour ${d.getMonth() !== m ? 'hors' : ''} ${ymd(d) === auj ? 'auj' : ''}" data-j="${ymd(d)}"><span class="n">${d.getDate()}</span></div>`; }
  $('#zone').innerHTML = h + '</div>';
  document.querySelectorAll('.mois .jour').forEach(c => {
    const d = new Date(c.dataset.j + 'T12:00');
    ev.filter(e => parse(e.debut) < plus(d, 1) && parse(e.fin) > new Date(c.dataset.j + 'T00:00')).forEach(e => {
      const x = document.createElement('span'); x.className = 'puce ' + e.statut + (e.externe ? ' externe' : ''); x.style.background = couleur(e); x.dataset.id = e.id;
      x.innerHTML = (estBande(e) ? '' : hm(parse(e.debut)) + ' ') + titreEvt(e); x.title = infoBulle(e); c.appendChild(x); glisserJour(x, e, null, {jourOrigine: c.dataset.j});
    });
    deposerAP(c, () => null, c.dataset.j); c.addEventListener('dblclick', ev2 => { if (ev2.target === c || ev2.target.classList.contains('n')) nouvelle('chantier', jourEntier(c.dataset.j, 1)); });
  });
}

// ---------- vue ressources (équipes, personnel et matériel × jours) ----------
function vueRessources() {
  const [du] = bornes(), jours = [...Array(14)].map((_, i) => plus(du, i)), ev = visibles();
  const cols = `grid-template-columns:180px repeat(14,minmax(78px,1fr))`;
  let h = `<div class="res" style="${cols}"><div class="tete" style="position:sticky;left:0;z-index:5">Ressource</div>` + jours.map(d => `<div class="tete ${we(d) ? 'we' : ''} ${ymd(d) === auj ? 'auj' : ''}">${jourCourt(d)}${ferie(d) ? ' <span style="font-weight:400" title="' + esc(ferie(d)) + '">· férié</span>' : ''}<small class="mj" data-j="${ymd(d)}" style="display:block;font-weight:400"></small></div>`).join('');
  const ligne = (cle, nom, sous, coul, style = '') => `<div class="nom" style="${style}"><i style="background:${coul}"></i><span>${esc(nom)}<small>${esc(sous)}</small></span></div>` + jours.map(d => `<div class="cel libre ${we(d) ? 'we' : ''}" data-j="${ymd(d)}" data-res="${cle}"></div>`).join('');
  const persLigne = (p, ind) => ligne('p:' + p.id, p.prenom + ' ' + p.nom, p.poste || p.competences.split(',').filter(Boolean).map(c => COMPS[c] || c).join(', '), p.couleur, ind ? 'padding-left:22px' : '');
  const eqs = S.data.equipes || [], dansEq = new Set(eqs.flatMap(q => q.membres));
  if (eqs.length) h += `<div class="sect">Équipes <span style="font-weight:400;text-transform:none;letter-spacing:0">· glisse un chantier sur la ligne d'une équipe pour lui affecter toute l'équipe et son matériel</span></div>` + eqs.map(q => ligne('e:' + q.id, q.nom, q.membres.map(persNom).join(', ') + (q.materiel.length ? ' · ' + q.materiel.map(matNom).join(', ') : ''), q.couleur, 'font-weight:900') + S.data.personnel.filter(p => q.membres.includes(+p.id)).map(p => persLigne(p, true)).join('')).join('');
  const autres = persVisibles().filter(p => !dansEq.has(+p.id));
  h += `<div class="sect">${eqs.length ? 'Autres personnes' : 'Personnel'}</div>` + (autres.length ? autres.map(p => persLigne(p, false)).join('') : (S.data.personnel.length ? '' : `<div class="cel" style="grid-column:1/-1;padding:10px">Aucune personne : ajoute ton équipe dans <a href="planning-ressources.php">Personnel, matériel, équipes</a>.</div>`));
  const mats = S.data.materiel, porteurs = mats.filter(m => !m.porteur_id);
  h += `<div class="sect">Matériel</div>` + (mats.length ? porteurs.map(m => ligne('m:' + m.id, m.nom, (TMAT[m.type] || m.type) + (m.controle_le ? ' · contrôle ' + m.controle_le.split('-').reverse().join('/') : ''), m.couleur) + mats.filter(o => +o.porteur_id === +m.id).map(o => ligne('m:' + o.id, '↳ ' + o.nom, 'monté sur ' + m.nom + ' : même machine', o.couleur, 'padding-left:22px')).join('')).join('') + mats.filter(o => o.porteur_id && !mats.some(m => +m.id === +o.porteur_id)).map(o => ligne('m:' + o.id, o.nom, TMAT[o.type] || o.type, o.couleur)).join('') : `<div class="cel" style="grid-column:1/-1;padding:10px">Aucun matériel enregistré.</div>`);
  h += `<div class="sect">Non affecté</div><div class="nom"><i style="background:#bbb"></i><span>Sans personne<small>dates posées, équipe à compléter</small></span></div>` + jours.map(d => `<div class="cel ${we(d) ? 'we' : ''}" data-j="${ymd(d)}" data-res="x:0"></div>`).join('');
  $('#zone').innerHTML = h + '</div>';
  document.querySelectorAll('.res .cel[data-res]').forEach(c => {
    const [k, rid] = c.dataset.res.split(':'), d0 = new Date(c.dataset.j + 'T00:00'), d1 = plus(d0, 1);
    const fr = k === 'm' ? S.data.materiel.filter(o => racineMat(o.id) === racineMat(rid)).map(o => +o.id) : [];
    ev.filter(e => parse(e.debut) < d1 && parse(e.fin) > d0 && (k === 'p' ? e.personnel.includes(+rid) : k === 'm' ? e.materiel.includes(+rid) : k === 'e' ? +e.equipe_id === +rid : !e.personnel.length && !e.materiel.length)).forEach(e => {
      const x = document.createElement('span'); x.className = 'puce ' + e.statut + (e.externe ? ' externe' : ''); x.style.background = couleur(e); x.dataset.id = e.id;
      x.innerHTML = titreEvt(e); x.title = infoBulle(e) + (peutModif(e) ? '\n\nGlisse vers une autre ligne pour changer de personne, de machine ou d\'équipe ; vers un autre jour pour déplacer.' : '');
      c.appendChild(x); c.classList.remove('libre'); glisserJour(x, e, null, {res: ['x', 'e'].includes(k) ? null : c.dataset.res, jourOrigine: c.dataset.j});
    });
    // machine partagée : un outil monté sur un porteur occupé est indisponible (case hachurée)
    if (k === 'm' && !c.querySelector('.puce') && ev.some(e => parse(e.debut) < d1 && parse(e.fin) > d0 && e.materiel.some(m => fr.includes(+m)))) { c.classList.add('partage'); c.style.background = 'repeating-linear-gradient(135deg,#f3e3e1 0 5px,#fff 5px 10px)'; c.title = 'Machine déjà utilisée ce jour-là avec un autre outil'; c.classList.remove('libre'); }
    if (S.data.droits.planifier) { deposerAP(c, () => null, c.dataset.j, k === 'x' ? null : c.dataset.res);
      c.addEventListener('dblclick', ev2 => { if (ev2.target !== c) return; const base = jourEntier(c.dataset.j, 1); if (k === 'p') base.personnel = [+rid]; if (k === 'm') base.materiel = [+rid]; if (k === 'e') { const q = equipeDe(rid); base.equipe_id = +rid; base.personnel = q.membres.slice(); base.materiel = q.materiel.slice(); base.besoin_personnes = q.membres.length; } nouvelle('chantier', base); }); }
  });
}

// ---------- vue liste (agenda) ----------
function vueListe() {
  const [du, au] = bornes(), ev = visibles(); let h = '';
  for (let d = new Date(du); d < au; d = plus(d, 1)) {
    const l = ev.filter(e => parse(e.debut) < plus(d, 1) && parse(e.fin) > d); if (!l.length && we(d)) continue;
    h += `<div class="liste-j"><h4>${JOURS[d.getDay()]} ${d.getDate()} ${MOIS[d.getMonth()]}${ymd(d) === auj ? ' · aujourd\'hui' : ''}${ferie(d) ? ' · ' + esc(ferie(d)) : ''} <small class="mj" data-j="${ymd(d)}"></small></h4>` + (l.length ? l.map(e => `<div class="liste-e" data-id="${e.id}"><span class="bar" style="background:${couleur(e)}"></span><span class="h">${estBande(e) ? 'Journée' : hm(parse(e.debut)) + '–' + hm(parse(e.fin))}</span><span class="t"><strong>${titreEvt(e)}</strong><small>${esc(TYPES[e.type][0])} · ${esc(STATUTS[e.statut])}${e.ville ? ' · ' + esc([e.adresse, e.ville].filter(Boolean).join(', ')) : ''}</small><small>${e.personnel.map(persNom).join(', ')}${e.materiel.length ? ' · ' + e.materiel.map(matNom).join(', ') : ''}</small>${(e.alertes || []).map(a => '<small style="color:#e6332a">⚠ ' + esc(a) + '</small>').join('')}</span></div>`).join('') : '<p class="muted small" style="margin:0">—</p>') + '</div>';
  }
  $('#zone').innerHTML = h || '<p class="muted" style="padding:14px">Rien sur cette période.</p>';
  document.querySelectorAll('.liste-e').forEach(x => x.addEventListener('click', () => ouvrirFiche(+x.dataset.id)));
}

// ---------- vue tournée (journée : ordre optimisé, carte) ----------
let carte = null;
async function vueTournee() {
  const j = ymd(bornes()[0]);
  $('#zone').innerHTML = `<div class="tournee"><div><p class="muted small" style="margin:0 0 8px">Visites, rendez-vous et chantiers de la journée, dans l'ordre le plus court depuis le dépôt (${esc(S.data.reglages.depot.nom)}) et retour. Distances estimées sur la route (vol d'oiseau × facteur), à vérifier sur le GPS.</p><div id="tInfo">Calcul…</div></div><div id="carte"></div></div>`;
  let t; try { t = await api('tournee', {jour: j, personnel: S.pers.length === 1 ? S.pers[0] : ''}); } catch (e) { $('#tInfo').textContent = e.message; return; }
  const A = t.actuel, O = t.optimise, gain = Math.round((A.km - O.km) * 10) / 10;
  const etape = (x, i) => `<div class="etape"><span class="num">${i + 1}</span><span style="flex:1"><strong>${esc(x.titre)}</strong><br><small class="muted">${esc(x.adresse)} · ${x.km_depuis} km</small></span><span><strong>${x.arrivee.slice(11, 16)}</strong><br><small class="muted">${x.duree_min} min</small></span></div>`;
  $('#tInfo').innerHTML = (O.etapes.length ? `<div class="cmp"><div>Ordre actuel<br><strong>${A.km} km</strong><br><small>retour ${A.retour}</small></div><div>Ordre optimisé<br><strong>${O.km} km</strong><br><small>retour ${O.retour}${gain > 0.2 ? ' · ' + gain + ' km de moins' : ''}</small></div></div>` +
      O.etapes.map(etape).join('') + (O.etapes.some(x => x.type !== 'chantier') ? `<p><button class="btn" id="appTournee">Appliquer cet ordre et ces horaires</button></p><p class="small muted">Les chantiers gardent leurs horaires ; seuls les visites et rendez-vous sont recalés. Un rendez-vous déjà confirmé qui change d'heure repasse en « à reconfirmer ».</p>` : '')
    : '<p class="muted">Aucun rendez-vous géolocalisé ce jour-là.</p>') + (t.sans_adresse.length ? `<p class="flash err small">Sans adresse localisée (non comptés) : ${t.sans_adresse.map(esc).join(', ')}</p>` : '');
  const b = $('#appTournee'); if (b) b.onclick = async () => { try { const r = await api('appliquer_tournee', {}, {etapes: O.etapes}); toast(r.modifies + ' horaire(s) mis à jour.'); charger(); } catch (e) { toast(e.message, true); } };
  if (typeof L === 'undefined') { $('#carte').innerHTML = '<p class="muted" style="padding:14px">Carte indisponible (connexion).</p>'; return; }
  if (carte) { carte.remove(); carte = null; }
  carte = fondsCarte(L.map('carte'));
  const dep = [t.depot.lat, t.depot.lng], pts = [dep, ...O.etapes.map(x => [x.lat, x.lng]), dep];
  L.marker(dep, {title: 'Dépôt'}).addTo(carte).bindPopup('Dépôt : ' + esc(t.depot.nom));
  O.etapes.forEach((x, i) => L.marker([x.lat, x.lng], {icon: L.divIcon({className: '', html: `<div class="num" style="width:26px;height:26px;border-radius:50%;background:#1c3b2b;color:#fff;font-weight:900;display:grid;place-items:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)">${i + 1}</div>`, iconSize: [26, 26], iconAnchor: [13, 13]})}).addTo(carte).bindPopup(`<strong>${esc(x.titre)}</strong><br>${esc(x.adresse)}<br>${x.arrivee.slice(11, 16)}<br>${liensCarte(x.lat, x.lng)}`));
  L.polyline(pts, {color: '#8caa31', weight: 4}).addTo(carte);
  carte.fitBounds(L.latLngBounds(pts).pad(0.15));
}

// Fonds de carte : plan OpenStreetMap, plan IGN, photo aérienne IGN, satellite Esri ; sélecteur de calques en haut à droite
function fondsCarte(map) {
  const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '© OpenStreetMap'});
  const ign = L.tileLayer('https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM&FORMAT=image/png&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}', {maxZoom: 19, attribution: '© IGN'});
  const ortho = L.tileLayer('https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=ORTHOIMAGERY.ORTHOPHOTOS&STYLE=normal&TILEMATRIXSET=PM&FORMAT=image/jpeg&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}', {maxZoom: 20, attribution: '© IGN'});
  const esri = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {maxZoom: 19, attribution: '© Esri, Maxar'});
  const choix = {'Plan (OpenStreetMap)': osm, 'Plan IGN': ign, 'Photo aérienne IGN': ortho, 'Satellite (Esri)': esri};
  let actif = 'Plan (OpenStreetMap)'; try { actif = localStorage.getItem('pl_fond') || actif; } catch (e) {} (choix[actif] || osm).addTo(map);
  L.control.layers(choix, {}, {position: 'topright'}).addTo(map); map.on('baselayerchange', ev => { try { localStorage.setItem('pl_fond', ev.name); } catch (e) {} });
  // bouton « ma position » : centre la carte sur la position du téléphone / de l'ordinateur
  const Loc = L.Control.extend({onAdd() { const b = L.DomUtil.create('a', 'leaflet-bar leaflet-control-locate'); b.href = '#'; b.title = 'Me centrer sur ma position'; b.setAttribute('role', 'button'); b.innerHTML = '📍'; L.DomEvent.disableClickPropagation(b);
    L.DomEvent.on(b, 'click', ev => { L.DomEvent.preventDefault(ev); if (!navigator.geolocation) { toast('Géolocalisation indisponible sur cet appareil.', true); return; } b.classList.add('attente');
      navigator.geolocation.getCurrentPosition(p => { b.classList.remove('attente'); const ll = [p.coords.latitude, p.coords.longitude];
        if (!map._moi) { map._moi = L.circleMarker(ll, {radius: 9, color: '#fff', weight: 3, fillColor: '#1a73e8', fillOpacity: 1}).addTo(map).bindPopup('Vous êtes ici'); map._moiP = L.circle(ll, {radius: p.coords.accuracy || 30, color: '#1a73e8', weight: 1, fillOpacity: .08}).addTo(map); }
        else { map._moi.setLatLng(ll); map._moiP.setLatLng(ll).setRadius(p.coords.accuracy || 30); }
        map.setView(ll, Math.max(map.getZoom(), 14)); }, () => { b.classList.remove('attente'); toast('Position introuvable : autorise la localisation dans le navigateur.', true); }, {enableHighAccuracy: true, timeout: 10000, maximumAge: 60000}); });
    return b; }});
  new Loc({position: 'topleft'}).addTo(map);
  return map;
}
const liensCarte = (lat, lng) => `<a href="https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${lat},${lng}" target="_blank" rel="noopener">Street View ↗</a> · <a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}" target="_blank" rel="noopener">Itinéraire ↗</a>`;
// ---------- vue carte : tous les chantiers et rendez-vous de la période, par type ----------
let carteG = null;
function vueCarte() {
  const [du, au] = bornes(); const ev = visibles().filter(e => e.lat && e.lng); const dep = S.data.reglages.depot;
  $('#zone').innerHTML = `<div class="carte-vue"><p class="muted small" style="margin:0 0 8px">Chantiers et rendez-vous localisés du ${du.getDate()}/${p2(du.getMonth() + 1)} au ${plus(au, -1).getDate()}/${p2(plus(au, -1).getMonth() + 1)} (${ev.length}) : couleur = type, numéro = ordre dans la période. Clique un repère pour ouvrir la fiche. ${(S.data.a_planifier || []).filter(e => e.lat).length ? 'Les fiches <strong>à planifier</strong> localisées sont en pointillé gris.' : ''}</p><div id="carteG"></div></div>`;
  if (carteG) { carteG.remove(); carteG = null; }
  carteG = fondsCarte(L.map('carteG'));
  const pts = [[dep.lat, dep.lng]];
  L.marker([dep.lat, dep.lng], {title: 'Dépôt', icon: L.divIcon({className: '', html: '<div style="width:22px;height:22px;border-radius:4px;background:#683c11;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)" title="Dépôt"></div>', iconSize: [22, 22], iconAnchor: [11, 11]})}).addTo(carteG).bindPopup('Dépôt : ' + esc(dep.nom));
  (S.data.secteurs || []).filter(s => s.lat).forEach(s => L.circle([+s.lat, +s.lng], {radius: (+s.rayon_km) * 1000, color: s.couleur, weight: 1, fillOpacity: .06}).addTo(carteG).bindTooltip(s.nom + (s.jours ? ' · jours ' + s.jours.split(',').map(j => JOURS[j]).join(', ') : '')));
  const sorted = ev.slice().sort((a, b) => parse(a.debut) - parse(b.debut));
  sorted.forEach((e, i) => { pts.push([+e.lat, +e.lng]);
    L.marker([+e.lat, +e.lng], {icon: L.divIcon({className: '', html: `<div style="width:28px;height:28px;border-radius:50%;background:${couleur(e)};color:#fff;font-weight:900;font-size:.8rem;display:grid;place-items:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);${e.statut === 'termine' ? 'opacity:.55' : ''}">${i + 1}</div>`, iconSize: [28, 28], iconAnchor: [14, 14]})}).addTo(carteG)
      .bindPopup(`<strong>${esc(e.titre)}</strong><br>${esc(e.motif || TYPES[e.type][0])} · ${esc(STATUTS[e.statut])}<br>${esc(evtQuand(e))}${e.client_nom ? '<br>' + esc(e.client_nom) : ''}${e.personnel.length ? '<br>' + e.personnel.map(persNom).join(', ') : ''}${(e.alertes || []).length ? '<br><span style="color:#e6332a">⚠ ' + e.alertes.length + ' alerte(s)</span>' : ''}<br><a href="#" onclick="ouvrirFiche(${e.id});return false">Ouvrir la fiche</a> · ${liensCarte(e.lat, e.lng)}`); });
  (S.data.a_planifier || []).filter(e => e.lat).forEach(e => { pts.push([+e.lat, +e.lng]);
    L.circleMarker([+e.lat, +e.lng], {radius: 9, color: '#666', dashArray: '3 3', weight: 2, fillColor: '#fff', fillOpacity: .9}).addTo(carteG).bindPopup(`<strong>${esc(e.titre)}</strong><br>À planifier${e.devis_montant ? ' · ' + eur(e.devis_montant) : ''}<br><a href="#" onclick="ouvrirFiche(${e.id}, {creneaux: true});return false">Trouver un créneau</a>`); });
  carteG.fitBounds(L.latLngBounds(pts).pad(0.2));
}
const evtQuand = e => { const a = parse(e.debut), b = new Date(parse(e.fin) - 1); return memeJour(a, b) ? jourCourt(a) + (estBande(e) ? '' : ' ' + hm(a) + '–' + hm(parse(e.fin))) : 'du ' + jourCourt(a) + ' au ' + jourCourt(b); };
// ---------- météo (Open-Meteo) : rafales et pluie sur 7 jours ----------
const cacheMeteo = {};
async function prevision(lat, lng) {
  const k = (+lat).toFixed(1) + ',' + (+lng).toFixed(1); if (cacheMeteo[k]) return cacheMeteo[k];
  cacheMeteo[k] = fetch(`https://api.open-meteo.com/v1/forecast?latitude=${(+lat).toFixed(2)}&longitude=${(+lng).toFixed(2)}&daily=wind_gusts_10m_max,precipitation_sum&timezone=Europe%2FParis&forecast_days=10`).then(r => r.json()).then(j => { const o = {}; (j.daily?.time || []).forEach((d, i) => o[d] = {raf: j.daily.wind_gusts_10m_max[i], pluie: j.daily.precipitation_sum[i]}); return o; }).catch(() => ({}));
  return cacheMeteo[k];
}
async function meteo() {
  if (!S.data) return; const seuil = S.data.reglages.vent, dep = S.data.reglages.depot;
  const pd = await prevision(dep.lat, dep.lng);
  document.querySelectorAll('.mj').forEach(m => { const x = pd[m.dataset.j]; if (!x) return; m.textContent = '🌬 ' + Math.round(x.raf) + ' km/h' + (x.pluie >= 1 ? ' · 🌧 ' + Math.round(x.pluie) + ' mm' : ''); m.classList.add('meteo'); if (x.raf >= seuil) { m.classList.add('fort'); m.title = 'Rafales au-dessus du seuil (' + seuil + ' km/h)'; } });
  let change = false;
  for (const e of (S.data.evenements || []).filter(e => e.type === 'chantier' && e.lat)) {
    const p = await prevision(e.lat, e.lng); let pire = null;
    for (let d = new Date(ymd(parse(e.debut)) + 'T12:00'); d < parse(e.fin); d = plus(d, 1)) { const x = p[ymd(d)]; if (x && (!pire || x.raf > pire.raf)) pire = x; }
    if (pire && pire.raf >= seuil && !e._meteo) { e._meteo = {fort: true, txt: '🌬' + Math.round(pire.raf)}; e.alertes = (e.alertes || []).concat(['Rafales annoncées jusqu\'à ' + Math.round(pire.raf) + ' km/h (seuil ' + seuil + ')']); change = true; }
  }
  if (change && S.vue !== 'tournee') { ({semaine: vueSemaine, mois: vueMois, ressources: vueRessources, liste: vueListe})[S.vue](); document.querySelectorAll('.mj').forEach(m => { const x = pd[m.dataset.j]; if (x) { m.textContent = '🌬 ' + Math.round(x.raf) + ' km/h' + (x.pluie >= 1 ? ' · 🌧 ' + Math.round(x.pluie) + ' mm' : ''); m.classList.add('meteo'); if (x.raf >= seuil) m.classList.add('fort'); } }); }
}

// ---------- fiche (création / modification) ----------
const VIDE = {id: 0, type: 'chantier', titre: '', statut: 'a_planifier', debut: '', fin: '', journee: 1, client_nom: '', client_tel: '', client_email: '', adresse: '', cp: '', ville: '', lat: null, lng: null, devis_ref: '', devis_montant: '', devis_signe: 0, devis_lien: '', bordereau_id: '', equipe_id: '', duree_h: '', nb_jours: '', besoin_personnes: 2, besoin_materiel: '', priorite: 2, souhait_client: '', motif: '', notes: '', consignes: '', personnel: [], materiel: [], client_id: '', dict_requise: 0, dict_ref: '', dict_envoi: '', dict_retour: '', phyto: 0, phyto_fiche: null};
function nouvelle(type, base = {}) { ouvrirFiche(0, {neuf: Object.assign({}, VIDE, {type, journee: type === 'chantier' || type === 'absence' ? 1 : 0, besoin_personnes: type === 'chantier' ? 2 : 0, statut: base.debut ? 'propose' : 'a_planifier'}, base)}); }
function rendreMesTaches(l) {
  $('#mt').hidden = !l.length; if (!l.length) return;
  $('#mtL').innerHTML = l.map(t => `<li class="${t.echeance && t.echeance < aujourdhui() ? 'retard' : ''}"><input type="checkbox" data-id="${t.id}" title="Fait"><span class="l">${esc(t.libelle)}<br><a href="#" data-evt="${t.evenement_id}" class="small">${esc(t.titre)}</a></span><span class="qui">${t.echeance ? 'avant le ' + t.echeance.slice(8, 10) + '/' + t.echeance.slice(5, 7) : (t.debut ? 'chantier ' + t.debut.slice(8, 10) + '/' + t.debut.slice(5, 7) : '')}</span></li>`).join('');
  $('#mtL').querySelectorAll('input').forEach(c => c.onchange = async () => { try { const r = await api('tache', {}, {op: 'fait', id: +c.dataset.id}); rendreMesTaches(r.mes_taches); toast('Action cochée ✓'); charger(); } catch (err) { toast(err.message, true); c.checked = false; } });
  $('#mtL').querySelectorAll('a[data-evt]').forEach(a => a.onclick = ev => { ev.preventDefault(); ouvrirFiche(+a.dataset.evt); });
}
const aujourdhui = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
async function ouvrirFiche(id, opts = {}) {
  if (!S.data) { setTimeout(() => ouvrirFiche(id, opts), 300); return; }
  if (typeof id === 'string' && id[0] === 'x') { const x = (S.data.evenements || []).find(e => e.id === id); if (x) toast((x.agenda ? x.agenda + ' · ' : '') + x.titre + ' – ' + evtQuand(x) + (x.adresse ? ' · ' + x.adresse : '') + ' (agenda externe, lecture seule : se modifie dans l\'application d\'origine)'); return; }
  if (!id && !S.data.droits.planifier) return;
  let e;
  if (id) { try { e = await api('fiche', {id}); } catch (err) { toast(err.message, true); return; } } else e = opts.neuf;
  const eqs = S.data.personnel, mats = S.data.materiel, d0 = parse(e.debut), d1 = parse(e.fin);
  const dFin = d1 ? (+e.journee ? new Date(+d1 - 1) : d1) : null;
  const opt = (o, v) => Object.entries(o).map(([k, l]) => `<option value="${k}" ${k === v ? 'selected' : ''}>${esc(l)}</option>`).join('');
  $('#ficheHote').innerHTML = `<div class="fiche-fond"></div><div class="fiche" role="dialog" aria-label="Fiche">
   <header><h2>${id ? esc(e.titre) : 'Nouveau : ' + esc(TYPES[e.type][0])}</h2><button class="mini" id="fFerm" title="Fermer (Échap)">✕</button></header>
   <div class="corps"><form id="ff" autocomplete="off">
    ${e.client_reponse === 'confirme' ? `<div class="reponse ok">✓ Le client a confirmé la date le ${esc((e.client_repondu_le || '').slice(0, 16).split(' ')[0].split('-').reverse().join('/'))}.${e.client_message ? '<br>« ' + esc(e.client_message) + ' »' : ''}</div>` : ''}
    ${e.client_reponse === 'autre' ? `<div class="reponse autre">Le client demande une autre date${e.client_message ? ' : « ' + esc(e.client_message) + ' »' : '.'} <button class="mini" type="button" id="fVu">Marquer comme traité</button></div>` : ''}
    <div class="alertes" id="fAl"></div>
    <div class="types">${Object.entries(TYPES).filter(([k]) => k !== 'externe' && (k !== 'urgence' || e.type === 'urgence')).map(([k, [l, c]]) => `<label style="border-color:${c}"><input type="radio" name="type" value="${k}" ${k === e.type ? 'checked' : ''}>${l}</label>`).join('')}</div>
    <label class="f-rdv">Type de rendez-vous <input name="motif" list="motifs" value="${esc(e.motif || '')}" placeholder="Ex. Réunion de chantier, visite de fin de travaux, rendez-vous prospect…"><datalist id="motifs">${(S.data.motifs || []).map(m => `<option value="${esc(m)}">`).join('')}</datalist></label>
    <div class="g2"><label>Titre <input name="titre" value="${esc(e.titre)}" placeholder="Ex. Abattage 3 chênes – M. Dupont"></label><label>Statut <select name="statut">${opt(STATUTS, e.statut)}</select></label></div>
    ${(S.data.pictos || []).length && e.type !== 'absence' ? `<fieldset><legend>Pictogrammes <span class="muted small" style="font-weight:400">– points d'attention visibles d'un coup d'œil sur le créneau${S.data.droits.niveau === 'tout' ? ' (liste modifiable dans ⚙ Réglages)' : ''}</span></legend>
     <div class="chips">${S.data.pictos.map(p => `<label title="${esc(p.action ? 'Crée l\'action « ' + p.action + ' »' + (p.delai !== null ? ' (J-' + p.delai + ')' : '') : p.libelle)}"><input type="checkbox" name="pictos" value="${p.code}" ${(e.pictos_l || []).includes(p.code) ? 'checked' : ''}>${p.html} ${esc(p.libelle)}</label>`).join('')}</div></fieldset>` : ''}
    ${id && e.type !== 'absence' ? `<fieldset><legend>Actions à faire avant le chantier <span class="muted small" style="font-weight:400">– attribuées, avec rappel sur le téléphone</span></legend><ul class="lst" id="fTaches"></ul>
     <div class="ajout" id="fTAj"><input id="fTLib" placeholder="Ex. Réserver la nacelle, prévenir le voisin…"><select id="fTQui"><option value="">Qui ?</option>${eqs.filter(p => +p.actif).map(p => `<option value="${p.id}">${esc(p.prenom)}</option>`).join('')}</select><input type="date" id="fTEch" title="À faire avant le"><button type="button" class="mini" id="fTAdd">Ajouter</button></div></fieldset>
    <fieldset class="f-chantier"><legend>Prestations du devis – relevé de chantier <span class="muted small" style="font-weight:400">– à cocher sur place, repris dans le bon de fin</span></legend><ul class="lst" id="fPrest"></ul>
     <div class="ajout" id="fPAj"><input id="fPLib" placeholder="Prestation à réaliser…"><input id="fPQte" placeholder="Qté" style="width:64px"><input id="fPUni" placeholder="Unité" style="width:70px"><button type="button" class="mini" id="fPAdd">Ajouter</button></div>
     <p class="small" style="margin:6px 0 0"><button type="button" class="mini" id="fPDevis" ${e.bordereau_id ? '' : 'disabled title="Lie d\'abord un bordereau (ci-dessous)"'}>Reprendre les lignes du bordereau</button> <button type="button" class="mini" id="fPColler">Coller les lignes d'un devis (Extrabat, PDF…)</button></p>
     <textarea id="fPTexte" rows="4" hidden placeholder="Colle ici les lignes du devis, une par ligne. Les prix en fin de ligne et les totaux sont ignorés."></textarea></fieldset>` : (!id && e.type !== 'absence' ? '<p class="small muted">Enregistre la fiche pour ajouter les actions à faire et les prestations du devis.</p>' : '')}
    <fieldset class="f-client"><legend>Client et lieu</legend>
     <input type="hidden" name="client_id" value="${e.client_id || ''}">
     <div class="g3"><label class="ac">Nom <input name="client_nom" id="fCli" value="${esc(e.client_nom)}" placeholder="Tape pour chercher dans les clients"><ul id="fCliL" hidden></ul></label><label>Téléphone <input name="client_tel" type="tel" value="${esc(e.client_tel)}"></label><label>E-mail <input name="client_email" type="email" value="${esc(e.client_email)}"></label></div>
     <label class="ac">Adresse <input name="adresse" id="fAdr" value="${esc(e.adresse)}" placeholder="Tape l'adresse puis choisis-la dans la liste"><ul id="fAdrL" hidden></ul></label>
     <div class="g3"><label>Code postal <input name="cp" value="${esc(e.cp)}"></label><label>Ville <input name="ville" value="${esc(e.ville)}"></label><label>Position <input id="fPos" value="${e.lat ? (+e.lat).toFixed(4) + ', ' + (+e.lng).toFixed(4) : ''}" placeholder="non localisée" readonly></label></div>
     <input type="hidden" name="lat" value="${e.lat ?? ''}"><input type="hidden" name="lng" value="${e.lng ?? ''}">
     <p class="small" id="fCliLien" style="margin:2px 0 0">${e.client_id ? `<a href="clients.php?id=${e.client_id}">Fiche client ↗</a>` : ''}</p>
     <p class="small muted" id="fTrajet" style="margin:2px 0 0">${e.lat && S.data.reglages.depot ? (() => { const k = kmVol(S.data.reglages.depot.lat, S.data.reglages.depot.lng, e.lat, e.lng); return `Depuis le dépôt : ${k < 10 ? k.toFixed(1) : Math.round(k)} km, environ ${Math.max(1, Math.round(k / VIT * 60))} min de route`; })() : ''}</p>
     <p class="small" id="fGps" style="margin:2px 0 0">${e.lat ? `<a href="https://www.google.com/maps/dir/?api=1&destination=${e.lat},${e.lng}" target="_blank">Itinéraire Google Maps ↗</a> · <a href="https://waze.com/ul?ll=${e.lat},${e.lng}&navigate=yes" target="_blank">Waze ↗</a> · <a href="https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${e.lat},${e.lng}" target="_blank">Street View ↗</a>` : ''}</p>
    </fieldset>
    <fieldset><legend>Date</legend>
     <div class="g2"><label>Début <input type="date" name="d_deb" value="${d0 ? ymd(d0) : ''}"></label><label class="hrs">Heure <input type="time" name="h_deb" step="900" value="${d0 ? hm(d0) : '09:00'}"></label>
     <label>Fin <input type="date" name="d_fin" value="${dFin ? ymd(dFin) : ''}"></label><label class="hrs">Heure <input type="time" name="h_fin" step="900" value="${d1 ? hm(d1) : '10:00'}"></label></div>
     <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="journee" style="width:auto;margin:0" ${+e.journee ? 'checked' : ''}> Journée(s) entière(s) (horaires de l'équipe : ${S.data.reglages.debut}–${S.data.reglages.fin})</label>
     <div class="g3"><label>Durée estimée (h) <input name="duree_h" inputmode="decimal" value="${e.duree_h ?? ''}" placeholder="ex. 6"></label><label>Nombre de jours <input name="nb_jours" inputmode="decimal" value="${e.nb_jours ?? ''}" placeholder="ex. 2"></label><label>Priorité <select name="priorite">${opt({1: 'Basse', 2: 'Normale', 3: 'Urgente'}, String(e.priorite))}</select></label></div>
     <label>Souhait du client (dates, contraintes) <input name="souhait_client" value="${esc(e.souhait_client)}" placeholder="Ex. pas avant le 15, plutôt le matin, portail fermé le lundi"></label>
     <p><button class="btn secondary" type="button" id="fCren">Trouver un créneau</button></p><div class="creneaux" id="fCrenL"></div>
    </fieldset>
    <fieldset class="f-aff"><legend>Équipe et matériel</legend>
     <div class="g2 f-chantier"><label>Personnes nécessaires <input name="besoin_personnes" type="number" min="0" value="${+e.besoin_personnes || 0}"></label><div><span style="font-weight:700;font-size:.9rem">Matériel nécessaire</span><div class="choix">${Object.entries(TMAT).map(([k, l]) => `<label><input type="checkbox" name="besoin_materiel" value="${k}" ${String(e.besoin_materiel || '').split(',').includes(k) ? 'checked' : ''}>${l}</label>`).join('')}</div></div></div>
     ${(S.data.equipes || []).length ? `<label>Équipe <select name="equipe_id" id="fEq"><option value="">— au cas par cas —</option>${S.data.equipes.map(q => `<option value="${q.id}" ${+q.id === +e.equipe_id ? 'selected' : ''}>${esc(q.nom)} (${q.membres.map(persNom).join(', ')})</option>`).join('')}</select></label>` : ''}
     <div style="font-weight:700;font-size:.9rem;margin-top:6px">Personnel affecté</div><div class="choix" id="fPersL">${eqs.map(p => `<label data-p="${p.id}"><input type="checkbox" name="personnel" value="${p.id}" ${e.personnel.includes(+p.id) ? 'checked' : ''}><i style="width:9px;height:9px;border-radius:50%;background:${p.couleur};display:inline-block"></i>${esc(p.prenom)} <small>${esc(p.competences.split(',').filter(Boolean).map(c => COMPS[c] || c).join(', '))}</small></label>`).join('') || '<span class="muted small">Aucune personne enregistrée.</span>'}</div>
     <div style="font-weight:700;font-size:.9rem;margin-top:6px">Matériel affecté</div><div class="choix" id="fMat">${mats.map(m => `<label data-m="${m.id}"><input type="checkbox" name="materiel" value="${m.id}" ${e.materiel.includes(+m.id) ? 'checked' : ''}>${esc(m.nom)} <small>${esc(TMAT[m.type] || m.type)}${m.porteur_id ? ' · sur ' + esc(matNom(m.porteur_id)) : ''}</small></label>`).join('') || '<span class="muted small">Aucun matériel enregistré.</span>'}</div>
    </fieldset>
    <fieldset class="f-chantier"><legend>DT-DICT (travaux à proximité de réseaux)</legend>
     <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="dict_requise" style="width:auto;margin:0" ${+e.dict_requise ? 'checked' : ''}> DT-DICT nécessaire pour ce chantier</label>
     <div class="g3"><label>N° de déclaration <input name="dict_ref" value="${esc(e.dict_ref || '')}"></label><label>Envoyée le <input type="date" name="dict_envoi" value="${esc(e.dict_envoi || '')}"></label><label>Réponses reçues le <input type="date" name="dict_retour" value="${esc(e.dict_retour || '')}"></label></div>
     <p class="small muted" style="margin:0">Déclaration sur <a href="https://www.reseaux-et-canalisations.ineris.fr" target="_blank" rel="noopener">reseaux-et-canalisations.ineris.fr ↗</a>. Les exploitants ont 9 jours pour répondre ; la déclaration vaut 3 mois. Le planning t'alerte si le chantier tombe trop tôt.</p>
    </fieldset>
    <fieldset class="f-chantier"><legend>Platane – chancre coloré</legend>
     <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="phyto" style="width:auto;margin:0" ${+e.phyto ? 'checked' : ''}> Chantier sur platane (registre DRAAF, désinfection, passeport pour le bois)</label>
     <div id="fPhyto" ${+e.phyto ? '' : 'hidden'}>${(() => { const p = e.phyto_fiche || {}; const sv = ['', 'rien', 'suspicion', 'na'], svl = {'': '—', rien: 'Aucun symptôme observé', suspicion: 'Symptômes suspects (signalés)', na: 'Non concerné'}; const us = {'': '—', broyage: 'Broyage (plaquettes / paillage)', chauffage: 'Bois de chauffage', incineration: 'Incinération / destruction', laisse: 'Laissé sur place au propriétaire', decheterie: 'Déchèterie / plateforme verte'};
       return `${e.phyto_verrou ? `<p class="small" style="margin:4px 0">🔒 Chantier terminé : fiche platane et passeport${p.passeport_num ? ' n° ' + esc(p.passeport_num) : ''} verrouillés (code C ${esc(p.pp_c || p.code_c)}).</p>` : ''}${e.phyto_zone ? '<p class="small" style="color:#e6332a;margin:4px 0">⚠ Commune en zone délimitée : déclaration préalable à la DRAAF (15 jours ouvrés avant), désinfection entre chaque arbre, bois non transportable sans dérogation.</p>' : ''}
       <div class="g3"><label>Propriétaire des arbres (si différent du client) <input name="ph_proprietaire" value="${esc(p.proprietaire || '')}"></label><label>Coordonnées du propriétaire <input name="ph_coordonnees" value="${esc(p.coordonnees || '')}"></label><label>Localisation (si différente de l'adresse) <input name="ph_localisation" value="${esc(p.localisation || '')}" placeholder="parcelle, GPS…"></label></div>
       <div class="g3"><label>Type de travaux <input name="ph_travaux" value="${esc(p.travaux || '')}" placeholder="abattage, élagage, ramassage bois mort…"></label><label>Nombre de platanes <input name="ph_nb_arbres" type="number" min="0" value="${p.nb_arbres ?? ''}"></label><label>Code C (traçabilité) <input name="ph_code_c" value="${esc(p.code_c || '')}" placeholder="${esc(p.code_auto || 'n° de devis')}"></label></div>
       <div class="g3"><label>Surveillance avant chantier <select name="ph_surv_avant">${sv.map(k => `<option value="${k}" ${(p.surv_avant || '') === k ? 'selected' : ''}>${svl[k]}</option>`).join('')}</select></label><label>En cours de chantier <select name="ph_surv_pendant">${sv.map(k => `<option value="${k}" ${(p.surv_pendant || '') === k ? 'selected' : ''}>${svl[k]}</option>`).join('')}</select></label><label>Déclaration DRAAF le (zone délimitée) <input type="date" name="ph_declaration_le" value="${esc(p.declaration_le || '')}"></label></div>
       <div class="choix"><label><input type="checkbox" name="ph_transport" ${p.transport === 0 ? '' : 'checked'}>Bois / rémanents transportés (passeport)</label><label><input type="checkbox" name="ph_desinf_entree" ${+p.desinf_entree ? 'checked' : ''}>Outils et engins désinfectés à l'entrée</label><label><input type="checkbox" name="ph_desinf_sortie" ${+p.desinf_sortie ? 'checked' : ''}>… et à la sortie du site</label><label><input type="checkbox" name="ph_desinf_arbres" ${+p.desinf_arbres ? 'checked' : ''}>Entre chaque arbre</label><label><input type="checkbox" name="ph_griffes" ${+p.griffes ? 'checked' : ''}>Griffes utilisées (démontage seulement)</label></div>
       <div class="g3"><label>Produit de désinfection <input name="ph_produit" value="${esc(p.produit || '')}"></label><label>Destination du bois <input name="ph_destination" value="${esc(p.destination || '')}" placeholder="commune, plateforme…"></label><label>Usage du bois <select name="ph_usage_bois">${Object.entries(us).map(([k, l]) => `<option value="${k}" ${(p.usage_bois || '') === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label></div>
       <div class="g3"><label>Volume estimé (m³) <input name="ph_volume_m3" inputmode="decimal" value="${p.volume_m3 ?? ''}"></label><label>Notes <input name="ph_notes" value="${esc(p.notes || '')}"></label></div>
       ${id ? `<p class="small" style="margin:4px 0 0"><a href="phyto.php?passeport=${id}" target="_blank">Passeport phytosanitaire (PDF) ↗</a> · <a href="phyto.php">Registre des chantiers platanes ↗</a></p>` : ''}`; })()}</div>
    </fieldset>
    <fieldset class="f-devis"><legend>Devis</legend>
     <div class="g3"><label>Référence <input name="devis_ref" value="${esc(e.devis_ref)}"></label><label>Montant HT (€) <input name="devis_montant" inputmode="decimal" value="${e.devis_montant ?? ''}"></label><label style="display:flex;gap:8px;align-items:center;font-weight:700;margin-top:26px"><input type="checkbox" name="devis_signe" style="width:auto;margin:0" ${+e.devis_signe ? 'checked' : ''}> Devis signé</label></div>
     <div class="g2"><label>Lien du devis (Extrabat…) <input name="devis_lien" value="${esc(e.devis_lien)}" placeholder="https://…"></label><label>Bordereau lié <select name="bordereau_id"><option value="">—</option>${BORDS.map(b => `<option value="${b.id}" ${+b.id === +e.bordereau_id ? 'selected' : ''}>${esc(b.nom)}</option>`).join('')}</select></label></div>
     ${e.devis_lien ? `<p class="small" style="margin:0"><a href="${esc(e.devis_lien)}" target="_blank" rel="noopener">Ouvrir le devis ↗</a></p>` : ''}
     ${e.pl_facture_id ? `<p class="small" style="margin:4px 0 0">Pennylane : facture ${esc(e.pl_facture_num || '')} <strong>${esc({brouillon: 'en brouillon', emise: 'émise', payee: 'payée ✓', annulee: 'annulée'}[e.pl_statut] || e.pl_statut)}</strong></p>` : (PL && id && e.type === 'chantier' ? '<p style="margin:4px 0 0"><button class="mini" type="button" id="fPl">Facturer dans Pennylane (brouillon)</button></p>' : '')}
    </fieldset>
    <fieldset><legend>Notes</legend><label>Consignes pour l'équipe (visibles dans « Mon planning ») <textarea name="consignes" rows="2">${esc(e.consignes || '')}</textarea></label><label>Notes internes <textarea name="notes" rows="2">${esc(e.notes || '')}</textarea></label></fieldset>
    ${e.journal && e.journal.length ? `<details><summary class="small">Historique de la fiche (${e.journal.length})</summary><ul class="journal">${e.journal.map(j => `<li>${esc(j.quand.slice(0, 16).replace(/(\d+)-(\d+)-(\d+)/, '$3/$2/$1'))} ${esc(j.prenom || '')} : ${esc(j.quoi)}</li>`).join('')}</ul></details>` : ''}
   </form></div>
   <footer><button class="btn" id="fEnr">Enregistrer</button><button class="btn secondary" id="fEnv" title="Enregistre puis envoie au client un e-mail avec la date et un lien pour confirmer">Envoyer la date au client</button>
    ${id && ['chantier', 'visite', 'rdv'].includes(e.type) ? `<a class="mini" href="rapport.php?evt=${id}">${e.type === 'chantier' ? 'Bon de fin' : 'Rapport'}</a>` : ''}
    ${id ? `<button class="mini" id="fDup">Dupliquer</button><button class="mini danger" id="fSup">Supprimer</button>` : ''}<span class="muted small" id="fEtat"></span></footer></div>`;
  const F = $('#ff'), fermer = () => { $('#ficheHote').innerHTML = ''; document.removeEventListener('keydown', echap); };
  const echap = ev => { if (ev.key === 'Escape') fermer(); }; document.addEventListener('keydown', echap);
  $('.fiche-fond').onclick = fermer; $('#fFerm').onclick = fermer;
  const majType = () => { const t = F.type.value; F.querySelectorAll('.f-chantier').forEach(x => x.style.display = t === 'chantier' ? '' : 'none'); F.querySelectorAll('.f-rdv').forEach(x => x.style.display = ['visite', 'rdv'].includes(t) ? '' : 'none');  if (t === 'visite' && F.motif && !F.motif.value) F.motif.value = 'Visite de chiffrage'; $('.f-devis').style.display = ['chantier', 'visite', 'rdv'].includes(t) ? '' : 'none'; $('.f-client').style.display = ['chantier', 'visite', 'rdv'].includes(t) ? '' : 'none'; };
  const majHeures = () => F.querySelectorAll('.hrs').forEach(x => x.style.display = F.journee.checked ? 'none' : '');
  F.querySelectorAll('[name=type]').forEach(r => r.onchange = () => { majType(); if (!id && ['chantier', 'absence'].includes(F.type.value)) F.journee.checked = true; majHeures(); verifier(); }); majType(); majHeures();
  F.journee.onchange = () => { majHeures(); verifier(); };
  if (F.phyto) F.phyto.onchange = () => { $('#fPhyto').hidden = !F.phyto.checked; };
  if (e.phyto_verrou) { F.phyto.disabled = true; $('#fPhyto').querySelectorAll('input,select').forEach(x => x.disabled = true); }
  if (F.d_deb) F.d_deb.addEventListener('change', () => { if (!F.d_fin.value || F.d_fin.value < F.d_deb.value) F.d_fin.value = F.d_deb.value; });
  const lire = () => {
    const g = n => F[n] ? F[n].value : '', all = n => [...F.querySelectorAll(`[name=${n}]:checked`)].map(x => x.value);
    let debut = '', fin = '';
    if (g('d_deb')) { if (F.journee.checked) { debut = g('d_deb') + ' ' + S.data.reglages.debut + ':00'; fin = (g('d_fin') || g('d_deb')) + ' ' + S.data.reglages.fin + ':00'; } else { debut = g('d_deb') + ' ' + g('h_deb') + ':00'; fin = (g('d_fin') || g('d_deb')) + ' ' + g('h_fin') + ':00'; } }
    return {id, type: F.type.value, titre: g('titre'), statut: g('statut'), debut, fin, journee: F.journee.checked ? 1 : 0, client_nom: g('client_nom'), client_tel: g('client_tel'), client_email: g('client_email'),
      adresse: g('adresse'), cp: g('cp'), ville: g('ville'), lat: g('lat'), lng: g('lng'), devis_ref: g('devis_ref'), devis_montant: g('devis_montant'), devis_signe: F.devis_signe.checked ? 1 : 0, devis_lien: g('devis_lien'), bordereau_id: g('bordereau_id'),
      motif: ['visite', 'rdv'].includes(F.type.value) ? g('motif') : '', duree_h: g('duree_h'), nb_jours: g('nb_jours'), besoin_personnes: g('besoin_personnes'), besoin_materiel: all('besoin_materiel'), priorite: g('priorite'), souhait_client: g('souhait_client'), notes: g('notes'), consignes: g('consignes'),
      pictos: all('pictos'), phyto: F.phyto && F.phyto.checked ? 1 : 0, phyto_fiche: F.phyto && F.phyto.checked ? {proprietaire: g('ph_proprietaire'), coordonnees: g('ph_coordonnees'), localisation: g('ph_localisation'), travaux: g('ph_travaux'), nb_arbres: g('ph_nb_arbres'), code_c: g('ph_code_c'), surv_avant: g('ph_surv_avant'), surv_pendant: g('ph_surv_pendant'), declaration_le: g('ph_declaration_le'), transport: F.ph_transport.checked ? 1 : 0, desinf_entree: F.ph_desinf_entree.checked ? 1 : 0, desinf_sortie: F.ph_desinf_sortie.checked ? 1 : 0, desinf_arbres: F.ph_desinf_arbres.checked ? 1 : 0, griffes: F.ph_griffes.checked ? 1 : 0, produit: g('ph_produit'), destination: g('ph_destination'), usage_bois: g('ph_usage_bois'), volume_m3: g('ph_volume_m3'), notes: g('ph_notes')} : null,
      equipe_id: g('equipe_id'), client_id: g('client_id'), dict_requise: F.dict_requise && F.dict_requise.checked ? 1 : 0, dict_ref: g('dict_ref'), dict_envoi: g('dict_envoi'), dict_retour: g('dict_retour'), personnel: all('personnel').map(Number), materiel: all('materiel').map(Number), _date_posee: debut !== '' && e.statut === 'a_planifier'};
  };
  // disponibilités et conflits en direct
  let tv; const verifier = () => { clearTimeout(tv); tv = setTimeout(async () => {
    const x = lire(); if (!x.debut) { $('#fAl').innerHTML = ''; return; }
    try { const r = await api('verifier', {}, x); $('#fAl').innerHTML = r.alertes.length ? r.alertes.map(a => `<div>⚠ ${esc(a)}</div>`).join('') : '<div class="ok-vert">✓ Aucun conflit : équipe et matériel disponibles.</div>'; } catch (err) {}
    // occupés ce(s) jour(s) : repère les personnes et machines déjà prises
    const d0x = parse(x.debut), d1x = parse(x.fin), occP = new Set(), occM = new Set();
    (S.data.evenements || []).forEach(o => { if (+o.id === +id || o.statut === 'annule') return; if (parse(o.debut) < d1x && parse(o.fin) > d0x) { o.personnel.forEach(p => occP.add(+p)); o.materiel.forEach(m => S.data.materiel.forEach(x => { if (racineMat(x.id) === racineMat(m)) occM.add(+x.id); })); } });
    F.querySelectorAll('#fPersL label').forEach(l => l.classList.toggle('occ', occP.has(+l.dataset.p) && !l.querySelector('input').checked));
    F.querySelectorAll('#fMat label').forEach(l => l.classList.toggle('occ', occM.has(+l.dataset.m) && !l.querySelector('input').checked));
  }, 250); };
  F.addEventListener('change', verifier); verifier();
  if ($('#fEq')) $('#fEq').onchange = () => { const q = equipeDe($('#fEq').value); if (!q) return; F.querySelectorAll('[name=personnel]').forEach(i => i.checked = q.membres.includes(+i.value)); if (q.materiel.length) F.querySelectorAll('[name=materiel]').forEach(i => i.checked = q.materiel.includes(+i.value)); if (F.besoin_personnes && +F.besoin_personnes.value < q.membres.length) F.besoin_personnes.value = q.membres.length; verifier(); };
  // fiche en lecture seule si l'agenda n'est pas modifiable par ce compte
  if (id && e.modifiable === false) { F.querySelectorAll('input,select,textarea').forEach(x => x.disabled = true); ['#fEnr', '#fEnv', '#fDup', '#fSup', '#fCren', '#fVu'].forEach(s2 => { const b = $(s2); if (b) b.remove(); }); $('#fEtat').textContent = 'Consultation seule : tu n\'as pas le droit de modifier cet agenda.'; }
  if (!id && !S.data.droits.planifier) { fermer(); return; }
  // client : recherche dans le répertoire ; choisir remplit téléphone, e-mail et adresse
  let tc, cliL = []; const cli = $('#fCli'), cliU = $('#fCliL');
  cli.addEventListener('input', () => { clearTimeout(tc); F.client_id.value = ''; $('#fCliLien').innerHTML = ''; tc = setTimeout(async () => {
    if (cli.value.trim().length < 2) { cliU.hidden = true; return; }
    try { cliL = (await api('clients', {q: cli.value.trim()})).clients; } catch (err) { cliL = []; }
    const it = []; cliL.forEach((c, i) => { if (!c.adresses.length) it.push(`<li data-i="${i}" data-a="-1"><strong>${esc(c.nom)}</strong> <small>${esc(c.mobile || c.telephone || c.email)}</small></li>`); c.adresses.forEach((a, j) => it.push(`<li data-i="${i}" data-a="${j}"><strong>${esc(c.nom)}</strong> <small>${esc(a.libelle)} : ${esc([a.adresse, a.cp, a.ville].filter(Boolean).join(' '))}</small></li>`)); });
    cliU.innerHTML = it.join(''); cliU.hidden = !it.length; }, 250); });
  cliU.addEventListener('mousedown', ev => { const li = ev.target.closest('li'); if (!li) return; ev.preventDefault(); const c = cliL[+li.dataset.i], a = c.adresses[+li.dataset.a];
    cli.value = c.nom; F.client_id.value = c.id; F.client_tel.value = c.mobile || c.telephone || ''; F.client_email.value = c.email || '';
    if (a) { F.adresse.value = a.adresse; F.cp.value = a.cp; F.ville.value = a.ville; F.lat.value = a.lat ?? ''; F.lng.value = a.lng ?? ''; $('#fPos').value = a.lat ? (+a.lat).toFixed(4) + ', ' + (+a.lng).toFixed(4) : 'non localisée'; }
    $('#fCliLien').innerHTML = `<a href="clients.php?id=${c.id}">Fiche client ↗</a>`; cliU.hidden = true;
    if (!F.titre.value.trim()) F.titre.value = ((F.motif && F.motif.value && ['visite', 'rdv'].includes(F.type.value)) ? F.motif.value : TYPES[F.type.value][0].split(' ')[0]) + ' – ' + c.nom; verifier(); });
  cli.addEventListener('blur', () => setTimeout(() => cliU.hidden = true, 150));
  // adresse : base adresse nationale (api-adresse.data.gouv.fr)
  let ta; const adr = $('#fAdr'), liste = $('#fAdrL'); let props = [];
  adr.addEventListener('input', () => { clearTimeout(ta); F.lat.value = ''; F.lng.value = ''; $('#fPos').value = 'non localisée'; ta = setTimeout(async () => {
    const qv = adr.value.trim(); if (qv.length < 4) { liste.hidden = true; return; }
    try { const r = await (await fetch('https://api-adresse.data.gouv.fr/search/?limit=6&lat=44.6&lon=1.95&q=' + encodeURIComponent(qv))).json(); props = r.features || [];
      liste.innerHTML = props.map((f, i) => `<li data-i="${i}">${esc(f.properties.label)}</li>`).join(''); liste.hidden = !props.length; } catch (err) { liste.hidden = true; } }, 250); });
  liste.addEventListener('mousedown', ev => { const li = ev.target.closest('li'); if (!li) return; ev.preventDefault(); const f = props[+li.dataset.i], p = f.properties;
    adr.value = p.name; F.cp.value = p.postcode || ''; F.ville.value = p.city || ''; F.lat.value = f.geometry.coordinates[1]; F.lng.value = f.geometry.coordinates[0];
    $('#fPos').value = (+F.lat.value).toFixed(4) + ', ' + (+F.lng.value).toFixed(4); liste.hidden = true;
    if (!F.titre.value.trim()) F.titre.value = (TYPES[F.type.value][0].split(' ')[0]) + ' ' + (F.client_nom.value || p.city || '');
    $('#fGps').innerHTML = `<a href="https://www.google.com/maps/dir/?api=1&destination=${F.lat.value},${F.lng.value}" target="_blank">Itinéraire Google Maps ↗</a>`; });
  adr.addEventListener('blur', () => setTimeout(() => liste.hidden = true, 150));
  // créneaux proposés
  const creneaux = async () => {
    const x = lire(); $('#fCrenL').innerHTML = '<p class="muted small">Recherche…</p>';
    try { const r = await api('creneaux', id ? {id} : {}, x); const l = r.creneaux;
      if (!l.length) { $('#fCrenL').innerHTML = '<p class="muted small">Aucun créneau trouvé sur la période (personnel ou matériel insuffisant). Vérifie les besoins ou ajoute des ressources.</p>'; return; }
      $('#fCrenL').innerHTML = (r.type === 'chantier' ? '<p class="small muted" style="margin:4px 0">Premières dates où l\'équipe et le matériel sont libres (les jours où vous êtes déjà dans le secteur passent devant) :</p>' : '<p class="small muted" style="margin:4px 0">Jours où cette visite ajoute le moins de kilomètres aux tournées déjà prévues :</p>') +
        l.map((c, i) => r.type === 'chantier'
          ? `<button type="button" data-i="${i}"><strong>${c.debut.split('-').reverse().join('/')}${c.jours.length > 1 ? ' → ' + c.fin.split('-').reverse().join('/') : ''}</strong> · ${esc(c.noms.join(', '))}${c.materiel.length ? ' · ' + c.materiel.map(matNom).join(', ') : ''}<br><small>${c.proche ? 'déjà dans le secteur ce jour-là : ' + esc(c.proche.titre) + ' à ' + c.proche.km + ' km' : 'pas d\'autre intervention à proximité'}${c.manque.length ? ' · ⚠ compétence manquante : ' + esc(c.manque.join(', ')) : ''}</small></button>`
          : `<button type="button" data-i="${i}"><strong>${c.jour.split('-').reverse().join('/')} à ${c.heure}</strong> · détour ${c.detour_km} km<br><small>${c.nb_visites ? c.nb_visites + ' autre(s) visite(s) ce jour-là · tournée ' + c.km_total + ' km' : 'journée sans autre visite · aller-retour ' + c.km_total + ' km'}</small></button>`).join('');
      $('#fCrenL').querySelectorAll('button').forEach(b => b.onclick = () => { const c = l[+b.dataset.i];
        if (r.type === 'chantier') { F.d_deb.value = c.debut; F.d_fin.value = c.fin; F.journee.checked = true; F.querySelectorAll('[name=personnel]').forEach(i => i.checked = c.personnel.includes(+i.value)); F.querySelectorAll('[name=materiel]').forEach(i => i.checked = c.materiel.includes(+i.value)); }
        else { F.d_deb.value = c.jour; F.d_fin.value = c.jour; F.journee.checked = false; const dur = (+F.duree_h.value || 1) * 60; F.h_deb.value = c.heure; const t = new Date(c.jour + 'T' + c.heure); t.setMinutes(t.getMinutes() + dur); F.h_fin.value = hm(t); }
        if (F.statut.value === 'a_planifier') F.statut.value = 'propose'; majHeures(); verifier(); $('#fCrenL').innerHTML = '<p class="ok-vert">Créneau posé dans la fiche : vérifie puis « Enregistrer » ou « Envoyer la date au client ».</p>'; });
    } catch (err) { $('#fCrenL').innerHTML = '<p class="flash err small">' + esc(err.message) + '</p>'; }
  };
  if ($('#fCren')) { $('#fCren').onclick = creneaux; if (opts.creneaux) creneaux(); }
  const enregistrer = async () => { const x = lire(); if (x.debut && x.statut === 'a_planifier') x.statut = 'propose';
    try { const r = await api('enregistrer', {}, x); id = r.id; return r; } catch (err) { toast(err.message, true); return null; } };
  if ($('#fEnr')) $('#fEnr').onclick = async () => { const r = await enregistrer(); if (!r) return; toast('Enregistré.' + (r.alertes.length ? ' ⚠ ' + r.alertes.length + ' point(s) à vérifier.' : ''), r.alertes.length > 0); fermer(); charger(); };
  if ($('#fEnv')) $('#fEnv').onclick = async () => { const x = lire(); if (!x.client_email) { toast('Renseigne l\'e-mail du client.', true); return; } if (!x.debut) { toast('Pose d\'abord une date.', true); return; }
    const r = await enregistrer(); if (!r) return; try { await api('proposer', {}, {id: r.id}); toast('Date envoyée au client (' + x.client_email + '). Tu seras alerté de sa réponse.'); fermer(); charger(); } catch (err) { toast(err.message, true); } };
  if ($('#fTaches')) {
    const peutGerer = S.data.droits.planifier && e.modifiable, moiP = +e.personnel_lie || 0;
    const rT = l => { e.taches = l; $('#fTaches').innerHTML = l.length ? l.map(t => `<li class="${t.fait_le ? 'fait' : ''} ${t.retard ? 'retard' : ''}"><input type="checkbox" data-id="${t.id}" ${t.fait_le ? 'checked' : ''} ${peutGerer || +t.personnel_id === moiP || e.personnel.includes(moiP) ? '' : 'disabled'}><span class="l">${t.picto && PICTO(t.picto) ? PICTO(t.picto).html + ' ' : ''}${esc(t.libelle)}${t.picto === 'kleyk' && KLEYK ? ` <a href="${esc(KLEYK)}" target="_blank" rel="noopener" class="small">Ouvrir Kleyk ›</a>` : ''}</span><span class="qui">${t.prenom ? esc(t.prenom) : '<em>non attribué</em>'}${t.echeance ? ' · avant le ' + t.echeance.slice(8, 10) + '/' + t.echeance.slice(5, 7) : ''}${t.fait_le ? ' · fait ✓' : ''}</span>${peutGerer ? `<button type="button" class="x" data-attr="${t.id}" title="Attribuer / échéance">✎</button><button type="button" class="x" data-sup="${t.id}" title="Supprimer">✕</button>` : ''}</li>`).join('') : '<li class="muted small" style="border:0">Aucune action. Cocher un pictogramme avec action (location, arrêté…) en crée une automatiquement.</li>';
      $('#fTaches').querySelectorAll('input[data-id]').forEach(c => c.onchange = async () => { try { rT((await api('tache', {}, {op: c.checked ? 'fait' : 'defait', id: +c.dataset.id})).taches); } catch (err) { toast(err.message, true); c.checked = !c.checked; } });
      $('#fTaches').querySelectorAll('[data-sup]').forEach(b => b.onclick = async () => { try { rT((await api('tache', {}, {op: 'supprimer', id: +b.dataset.sup})).taches); } catch (err) { toast(err.message, true); } });
      $('#fTaches').querySelectorAll('[data-attr]').forEach(b => b.onclick = () => { const t = l.find(x => +x.id === +b.dataset.attr); $('#fTLib').value = t.libelle; $('#fTQui').value = t.personnel_id || ''; $('#fTEch').value = t.echeance || ''; $('#fTAdd').textContent = 'Modifier'; $('#fTAdd').dataset.id = t.id; $('#fTLib').focus(); }); };
    rT(e.taches || []); $('#fTAj').hidden = !(peutGerer || e.personnel.includes(moiP));
    $('#fTAdd').onclick = async () => { const lib = $('#fTLib').value.trim(); if (!lib) { $('#fTLib').focus(); return; } const idT = +($('#fTAdd').dataset.id || 0);
      try { rT((await api('tache', {}, idT ? {op: 'attribuer', id: idT, libelle: lib, personnel_id: $('#fTQui').value, echeance: $('#fTEch').value} : {op: 'ajouter', evenement_id: id, libelle: lib, personnel_id: $('#fTQui').value, echeance: $('#fTEch').value})).taches); $('#fTLib').value = ''; $('#fTEch').value = ''; $('#fTAdd').textContent = 'Ajouter'; delete $('#fTAdd').dataset.id; } catch (err) { toast(err.message, true); } };
    $('#fTLib').onkeydown = ev => { if (ev.key === 'Enter') { ev.preventDefault(); $('#fTAdd').click(); } };
  }
  if ($('#fPrest')) {
    const peutGerer = S.data.droits.planifier && e.modifiable, moiP = +e.personnel_lie || 0, peutCocher = peutGerer || e.personnel.includes(moiP);
    const qte = p => p.quantite !== null && p.quantite !== undefined ? ' <span class="muted">(' + (+p.quantite).toLocaleString('fr-FR') + (p.unite ? ' ' + esc(p.unite) : '') + ')</span>' : '';
    const rP = l => { e.prestations = l; $('#fPrest').innerHTML = l.length ? l.map(p => `<li class="${p.fait_le ? 'fait' : ''}"><input type="checkbox" data-id="${p.id}" ${p.fait_le ? 'checked' : ''} ${peutCocher ? '' : 'disabled'}><span class="l">${esc(p.libelle)}${qte(p)}${p.remarque ? '<br><small class="muted">' + esc(p.remarque) + '</small>' : ''}</span><span class="qui">${{bordereau: 'bordereau', devis: 'devis', manuel: ''}[p.source] || ''}</span>${peutGerer ? `<button type="button" class="x" data-sup="${p.id}" title="Retirer">✕</button>` : ''}</li>`).join('') : '<li class="muted small" style="border:0">Aucune prestation listée.</li>';
      $('#fPrest').querySelectorAll('input[data-id]').forEach(c => c.onchange = async () => { try { rP((await api('prestation', {}, {op: c.checked ? 'fait' : 'defait', id: +c.dataset.id})).prestations); } catch (err) { toast(err.message, true); c.checked = !c.checked; } });
      $('#fPrest').querySelectorAll('[data-sup]').forEach(b => b.onclick = async () => { try { rP((await api('prestation', {}, {op: 'supprimer', id: +b.dataset.sup})).prestations); } catch (err) { toast(err.message, true); } }); };
    rP(e.prestations || []); $('#fPAj').hidden = !peutCocher; $('#fPDevis').parentElement.hidden = !peutCocher;
    $('#fPAdd').onclick = async () => { const lib = $('#fPLib').value.trim(); if (!lib) return; try { rP((await api('prestation', {}, {op: 'ajouter', evenement_id: id, libelle: lib, quantite: $('#fPQte').value, unite: $('#fPUni').value})).prestations); $('#fPLib').value = $('#fPQte').value = $('#fPUni').value = ''; } catch (err) { toast(err.message, true); } };
    $('#fPLib').onkeydown = ev => { if (ev.key === 'Enter') { ev.preventDefault(); $('#fPAdd').click(); } };
    $('#fPDevis').onclick = async () => { try { const r = await api('prestation', {}, {op: 'devis', evenement_id: id}); rP(r.prestations); toast('Lignes du bordereau reprises.'); } catch (err) { toast(err.message, true); } };
    $('#fPColler').onclick = async () => { const t = $('#fPTexte'); if (t.hidden) { t.hidden = false; t.focus(); $('#fPColler').textContent = 'Ajouter ces lignes'; return; } if (!t.value.trim()) { t.hidden = true; $('#fPColler').textContent = 'Coller les lignes d\'un devis (Extrabat, PDF…)'; return; }
      try { rP((await api('prestation', {}, {op: 'coller', evenement_id: id, texte: t.value})).prestations); t.value = ''; t.hidden = true; $('#fPColler').textContent = 'Coller les lignes d\'un devis (Extrabat, PDF…)'; } catch (err) { toast(err.message, true); } };
  }
  if ($('#fDup')) $('#fDup').onclick = async () => { try { const r = await api('dupliquer', {}, {id}); toast('Copie créée dans « À planifier ».'); fermer(); await charger(); ouvrirFiche(r.id); } catch (err) { toast(err.message, true); } };
  if ($('#fSup')) $('#fSup').onclick = async (ev) => { const b = ev.currentTarget; if (b.dataset.ok !== '1') { b.dataset.ok = '1'; b.textContent = 'Confirmer la suppression'; return; } try { await api('supprimer', {}, {id}); toast('Supprimé.'); fermer(); charger(); } catch (err) { toast(err.message, true); } };
  if ($('#fPl')) $('#fPl').onclick = async () => { const r = await enregistrer(); if (!r) return; try { await api('facturer', {}, {id: r.id}); toast('Facture créée en brouillon dans Pennylane : relis-la et valide-la là-bas.'); fermer(); charger(); } catch (err) { toast(err.message, true); } };
  if ($('#fVu')) $('#fVu').onclick = async () => { await api('reponse_traitee', {}, {id}); $('#fVu').closest('.reponse').remove(); charger(); };
}

// ---------- barre d'outils ----------
document.querySelectorAll('#vues button').forEach(b => b.onclick = () => { S.vue = b.dataset.v; try { localStorage.setItem('pl_vue', S.vue); } catch (e) {} $('#zone').dataset.init = ''; charger(); });
const pas = () => ({mois: 'm', ressources: 14, liste: 7, tournee: 1, carte: 14})[S.vue] || 7;
$('#prec').onclick = () => { const p = pas(); S.ref = p === 'm' ? new Date(S.ref.getFullYear(), S.ref.getMonth() - 1, 15) : plus(S.ref, -p); charger(); };
$('#suiv').onclick = () => { const p = pas(); S.ref = p === 'm' ? new Date(S.ref.getFullYear(), S.ref.getMonth() + 1, 15) : plus(S.ref, p); charger(); };
$('#auj').onclick = () => { S.ref = new Date(); charger(); };
$('#fPers').onchange = ev => { S.pers = ev.target.value ? [+ev.target.value] : []; try { localStorage.setItem('pl_pers', JSON.stringify(S.pers)); } catch (e) {} S.vue === 'tournee' ? vueTournee() : rendre(); };
$('#nouveau').onclick = ev => { const m = document.createElement('div'); m.className = 'card'; Object.assign(m.style, {position: 'absolute', zIndex: 60, padding: '8px', margin: 0}); const r = ev.currentTarget.getBoundingClientRect(); m.style.left = (r.left + scrollX) + 'px'; m.style.top = (r.bottom + scrollY + 4) + 'px';
  m.innerHTML = Object.entries(TYPES).filter(([k]) => !['urgence', 'externe'].includes(k)).map(([k, [l, c]]) => `<button class="mini" style="display:block;width:100%;text-align:left;margin:3px 0;border-left:4px solid ${c}" data-t="${k}">${l}</button>`).join(''); document.body.appendChild(m);
  const f = e2 => { if (!m.contains(e2.target)) { m.remove(); document.removeEventListener('click', f, true); } }; setTimeout(() => document.addEventListener('click', f, true));
  m.querySelectorAll('button').forEach(b => b.onclick = () => { m.remove(); nouvelle(b.dataset.t); }); };
filtresTypes(); charger();
<?php if (!empty($_GET['fiche'])): ?>setTimeout(() => ouvrirFiche(<?= (int)$_GET['fiche'] ?>), 400);<?php endif; ?>
if (NOUV_CLIENT) setTimeout(() => nouvelle('visite', NOUV_CLIENT), 500);
</script>
<?php page_end(); ?>
