<?php
// Assistant : page de discussion + point d'entrée AJAX (?api=1)
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/assistant_lib.php';
require_chiffrage();
assistant_tables();
$uid = (int)user()['id'];
$bordereauId = isset($_GET['bordereau']) ? (int)$_GET['bordereau'] : (isset($_POST['bordereau']) ? (int)$_POST['bordereau'] : null);

// ---- API JSON ----
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $GLOBALS['assistant_page'] = preg_replace('/[^a-z.]/', '', (string)($in['page'] ?? ''));
    if (!hash_equals(csrf(), $in['csrf'] ?? '')) { echo json_encode(['erreur' => 'Session expirée, recharge la page.']); exit; }
    try {
        if (!assistant_ok()) throw new RuntimeException("Clé API non configurée : ajoute CLAUDE_API_KEY dans config-espace-pro.php.");
        $action = $in['action'] ?? 'message';
        if ($action === 'rediger') { // mise en forme de notes de terrain en rapport de visite (appel unique, sans conversation)
            $notes = trim((string)($in['texte'] ?? '')); if ($notes === '') throw new RuntimeException('Notes vides.');
            $ctx = trim((string)($in['contexte'] ?? ''));
            $fin = ($in['modele'] ?? '') === 'fin';
            if (setting('assistant_notes_style', 'fidele') === 'detaille' && !$fin)
                $rep = assistant_appel([['role' => 'user', 'content' => [['type' => 'text', 'text' => "Mets en forme ces notes de terrain en « Rapport de visite – préparation devis » (version courte si la visite est simple), selon la compétence rapport de visite et la doctrine. Réponds uniquement par le rapport, en texte simple sans mise en forme Markdown (titres en majuscules, tirets), prêt à être enregistré tel quel. N'invente rien : ce qui manque va dans « À confirmer ».\n\n" . ($ctx ? "Contexte du rendez-vous : $ctx\n\n" : '') . "Notes :\n$notes"]]]], null);
            else
                $rep = assistant_appel([['role' => 'user', 'content' => [['type' => 'text', 'text' => ($fin ? "Il s'agit d'un bon de fin d'intervention : rubriques possibles TRAVAUX RÉALISÉS puis REMARQUES, uniquement avec ce qui est dans les notes.\n\n" : "Il s'agit d'un compte rendu de visite avant devis.\n\n") . ($ctx ? "Contexte du rendez-vous : $ctx\n\n" : '') . "Notes :\n$notes"]]]], null, assistant_consigne_notes());
            $texte = ''; foreach ($rep['content'] ?? [] as $bloc) if ($bloc['type'] === 'text') $texte .= $bloc['text'];
            echo json_encode(['texte' => trim($texte)], JSON_UNESCAPED_UNICODE); exit;
        }
        $cid = (int)($in['conversation_id'] ?? 0);
        if ($cid) { $c = q("SELECT * FROM conversations WHERE id = ? AND utilisateur_id = ?", [$cid, $uid])->fetch(); if (!$c) throw new RuntimeException('Conversation introuvable.'); }
        if ($action === 'message') {
            $texte = trim($in['texte'] ?? ''); if ($texte === '') throw new RuntimeException('Message vide.');
            if (!$cid) { q("INSERT INTO conversations (utilisateur_id, titre, bordereau_id) VALUES (?,?,?)", [$uid, mb_substr($texte, 0, 80), $bordereauId]); $cid = (int)db()->lastInsertId(); }
            q("INSERT INTO messages (conversation_id, role, contenu) VALUES (?,?,?)", [$cid, 'user', json_encode([['type' => 'text', 'text' => $texte]], JSON_UNESCAPED_UNICODE)]);
        } elseif ($action === 'outil') {
            // Résultat d'un outil confirmé ou refusé par l'utilisateur
            $res = !empty($in['accepte']) ? assistant_appliquer($in['nom'], $in['input'] ?? [], $bordereauId) : "L'utilisateur a refusé cette action.";
            q("INSERT INTO messages (conversation_id, role, contenu) VALUES (?,?,?)", [$cid, 'user', json_encode([['type' => 'tool_result', 'tool_use_id' => $in['tool_use_id'], 'content' => $res]], JSON_UNESCAPED_UNICODE)]);
        }
        // Historique (20 derniers messages) → API
        $hist = array_reverse(q("SELECT role, contenu FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 20", [$cid])->fetchAll());
        // Le premier message doit être un 'user' texte ; on retire un éventuel tool_result orphelin en tête
        while ($hist && ($hist[0]['role'] !== 'user' || str_contains($hist[0]['contenu'], 'tool_result'))) array_shift($hist);
        $messages = array_map(fn($m) => ['role' => $m['role'], 'content' => json_decode($m['contenu'], true)], $hist);
        $rep = assistant_appel($messages, $bordereauId);
        $contenu = $rep['content'] ?? [];
        q("INSERT INTO messages (conversation_id, role, contenu) VALUES (?,?,?)", [$cid, 'assistant', json_encode($contenu, JSON_UNESCAPED_UNICODE)]);
        $texte = ''; $outils = [];
        foreach ($contenu as $bloc) { if ($bloc['type'] === 'text') $texte .= $bloc['text']; elseif ($bloc['type'] === 'tool_use') $outils[] = ['id' => $bloc['id'], 'nom' => $bloc['name'], 'input' => $bloc['input'], 'resume' => assistant_resume($bloc['name'], (array)$bloc['input'])]; }
        echo json_encode(['conversation_id' => $cid, 'texte' => $texte, 'outils' => $outils, 'fin' => $rep['stop_reason'] ?? ''], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $t) { echo json_encode(['erreur' => $t->getMessage()], JSON_UNESCAPED_UNICODE); }
    exit;
}

// ---- Gestion mémoire (page) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'memoire_del') { check_csrf(); q("UPDATE memoire SET actif = 0 WHERE id = ?", [(int)$_POST['id']]); flash('Règle retirée de la mémoire.'); redirect('assistant.php'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'memoire_add' && trim($_POST['texte'] ?? '') !== '') { check_csrf(); q("INSERT INTO memoire (texte, ajoute_par) VALUES (?,?)", [mb_substr(trim($_POST['texte']), 0, 500), $uid]); flash('Règle ajoutée.'); redirect('assistant.php'); }

$convs = q("SELECT * FROM conversations WHERE utilisateur_id = ? ORDER BY maj DESC LIMIT 30", [$uid])->fetchAll();
$cidOuverte = isset($_GET['c']) ? (int)$_GET['c'] : 0;
$msgs = $cidOuverte ? q("SELECT m.* FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.conversation_id = ? AND c.utilisateur_id = ? ORDER BY m.id", [$cidOuverte, $uid])->fetchAll() : [];
$bord = $bordereauId ? q("SELECT id, nom FROM bordereaux WHERE id = ?", [$bordereauId])->fetch() : null;
$memoire = q("SELECT m.*, u.prenom FROM memoire m LEFT JOIN utilisateurs u ON u.id = m.ajoute_par WHERE m.actif = 1 ORDER BY m.id")->fetchAll();
$usage = q("SELECT * FROM assistant_usage WHERE mois = ?", [date('Y-m')])->fetch();
$LIBO = ['modifier_temps_catalogue' => 'Modifier un temps du catalogue', 'creer_ligne_catalogue' => 'Créer une prestation au catalogue', 'modifier_ligne_bordereau' => 'Modifier une ligne du bordereau', 'ajouter_ligne_bordereau' => 'Ajouter une ligne au bordereau', 'creer_action' => 'Créer une action dans l\'arbre', 'memoriser' => 'Mémoriser une règle d\'entreprise'];
$panneau = isset($_GET['panneau']); $pageCour = preg_replace('/[^a-z.]/', '', (string)($_GET['page'] ?? ''));
if ($panneau) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Assistant</title><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css?v=' . e(app_version()) . '.' . @filemtime(__DIR__ . '/pro.css') . '"></head><body class="panneau">' . pictos_sprite();
} else page_start('Assistant', 'assistant.php', $bord ? ['bordereau.php?id=' . $bord['id'], $bord['nom']] : null);
?>
<?php if ($panneau): ?>
<div class="pan-tete"><strong>✦ Assistant</strong><?= $bord ? ' <span class="muted">· ' . e($bord['nom']) . '</span>' : '' ?> <a class="mini" href="assistant.php?panneau=1<?= $bord ? '&bordereau=' . $bord['id'] : '' ?><?= $pageCour ? '&page=' . e($pageCour) : '' ?>" title="Nouvelle conversation" onclick="try{sessionStorage.removeItem('assistant_cid')}catch(e){}">+ Nouvelle</a> <a class="mini" href="assistant.php<?= $bord ? '?bordereau=' . $bord['id'] : '' ?>" target="_top" title="Page complète : conversations, mémoire">Historique ↗</a></div>
<?php endif; ?>
<?php if (!assistant_ok()): ?><div class="flash err">L'assistant n'est pas encore activé : la clé API doit être ajoutée dans <code>config-espace-pro.php</code> (lignes <code>CLAUDE_API_KEY</code> et <code>CLAUDE_MODELE</code>).</div><?php endif; ?>
<div class="chat-layout<?= $panneau ? ' seul' : '' ?>">
<?php if (!$panneau): ?><aside class="chat-side">
  <a class="btn secondary" href="assistant.php<?= $bord ? '?bordereau=' . $bord['id'] : '' ?>" style="display:block;text-align:center">+ Nouvelle conversation</a>
  <?php if ($bord): ?><p class="small">Contexte : bordereau « <?= e($bord['nom']) ?> »</p><?php endif; ?>
  <ul class="convs"><?php foreach ($convs as $c): ?><li><a href="assistant.php?c=<?= $c['id'] ?><?= $c['bordereau_id'] ? '&bordereau=' . $c['bordereau_id'] : '' ?>" class="<?= $c['id'] === $cidOuverte ? 'on' : '' ?>"><?= e($c['titre'] ?: 'Sans titre') ?><br><small class="muted"><?= date('d/m H:i', strtotime($c['maj'])) ?></small></a></li><?php endforeach; ?></ul>
  <details><summary>Mémoire d'entreprise (<?= count($memoire) ?>)</summary>
    <ul class="memo"><?php foreach ($memoire as $m): ?><li><?= e($m['texte']) ?> <span class="muted">· <?= e($m['prenom'] ?? '') ?></span> <form method="post" class="inline" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="memoire_del"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="chipx" title="Retirer">×</button></form></li><?php endforeach; ?></ul>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="memoire_add"><input name="texte" placeholder="Ajouter une règle…" maxlength="500"><button class="mini" type="submit">Ajouter</button></form>
  </details>
  <p class="small muted">Ce mois : <?= (int)($usage['echanges'] ?? 0) ?> échanges · modèle <?= e(assistant_modele()) ?></p>
</aside><?php endif; ?>
<section class="chat-main">
  <div id="fil" class="chat-fil">
    <?php if (!$msgs): ?><div class="bulle a">Bonjour <?= e(user()['prenom']) ?>. Je connais le catalogue, les taux, les actions<?= $bord ? ' et le bordereau ouvert' : '' ?>. Pose-moi une question de chiffrage, demande-moi de vérifier un prix ou de modifier une ligne : je te proposerai l'action, tu la valideras d'un clic.</div><?php endif; ?>
    <?php foreach ($msgs as $m): $blocs = json_decode($m['contenu'], true) ?: []; foreach ($blocs as $b): ?>
      <?php if ($b['type'] === 'text' && trim($b['text']) !== ''): ?><div class="bulle <?= $m['role'] === 'user' ? 'u' : 'a' ?>"><?= nl2br(e($b['text'])) ?></div>
      <?php elseif ($b['type'] === 'tool_use'): ?><div class="bulle outil fait"><strong><?= e($LIBO[$b['name']] ?? $b['name']) ?></strong><pre><?= e(assistant_resume($b['name'], (array)$b['input'])) ?></pre></div>
      <?php elseif ($b['type'] === 'tool_result'): ?><div class="bulle res"><?= e(is_string($b['content']) ? $b['content'] : json_encode($b['content'])) ?></div><?php endif; ?>
    <?php endforeach; endforeach; ?>
  </div>
  <form id="fchat" class="chat-saisie" onsubmit="envoyer(event)">
    <textarea id="texte" rows="2" placeholder="Ex. : combien de temps pour le démontage d'un platane de 22 m avec rétention, et quel prix avec l'équipe marché public ?"></textarea>
    <button class="mini" type="button" id="micro" title="Parler au lieu d'écrire (maintenir ou appuyer pour commencer, appuyer pour arrêter)" hidden><svg class="pi"><use href="#pi-mic"/></svg> Dicter</button>
    <button class="mini" type="button" id="lire" title="Lire les réponses à voix haute" hidden><svg class="pi"><use href="#pi-speaker"/></svg> Lire</button>
    <button class="btn" type="submit" id="btn">Envoyer</button>
  </form>
</section>
</div>
<script>
const CSRF = <?= json_encode(csrf()) ?>; let CID = <?= (int)$cidOuverte ?>; const BORD = <?= (int)($bordereauId ?? 0) ?>; const PAGE = <?= json_encode($pageCour) ?>; const PANNEAU = <?= $panneau ? 'true' : 'false' ?>;
const LIBO = <?= json_encode($LIBO, JSON_UNESCAPED_UNICODE) ?>;
const resume = (nom, i) => ({
  modifier_temps_catalogue: `Ligne catalogue #${i.ligne_id} : ressource #${i.ressource_id} → ${i.heures} h`,
  creer_ligne_catalogue: `${i.code} – ${i.libelle} (${i.famille})`,
  modifier_ligne_bordereau: `Ligne #${i.ligne_bordereau_id}` + (i.quantite !== undefined ? `, quantité → ${i.quantite ?? '—'}` : '') + (i.pu_manuel !== undefined ? `, PU imposé → ${i.pu_manuel ?? 'prix catalogue'}` : '') + (i.catalogue_id !== undefined ? `, prestation → #${i.catalogue_id ?? 'aucune'}` : ''),
  ajouter_ligne_bordereau: `${i.libelle}` + (i.quantite ? ` × ${i.quantite}` : '') + (i.pu_manuel ? ` à ${i.pu_manuel} €` : ''),
  creer_action: `${i.nom} (${i.duree} h)`,
  memoriser: `« ${i.texte} »`,
}[nom] || JSON.stringify(i)) + (i.motif ? `\nMotif : ${i.motif}` : '');
const fil = document.getElementById('fil');
function bulle(cls, html) { const d = document.createElement('div'); d.className = 'bulle ' + cls; d.innerHTML = html; fil.appendChild(d); fil.scrollTop = fil.scrollHeight; return d; }
const esc = s => s.replace(/[&<>]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
async function api(data) {
  const r = await fetch('assistant.php?api=1' + (BORD ? '&bordereau=' + BORD : ''), {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': CSRF}, body: JSON.stringify(Object.assign({csrf: CSRF, conversation_id: CID, page: PAGE}, data))});
  return r.json();
}
async function traiter(rep) {
  if (rep.erreur) { bulle('err', esc(rep.erreur)); return; }
  CID = rep.conversation_id; if (PANNEAU) { try { sessionStorage.setItem('assistant_cid', CID); } catch (e) {} }
  if (rep.texte) { bulle('a', esc(rep.texte).replace(/\n/g, '<br>')); lireVoix(rep.texte); }
  for (const o of rep.outils || []) {
    const d = bulle('outil', '<strong>' + esc(LIBO[o.nom] || o.nom) + '</strong><pre>' + esc(o.resume || resume(o.nom, o.input)) + '</pre><div class="btns"><button class="btn" type="button">Appliquer</button> <button class="mini" type="button">Refuser</button></div>');
    const [ok, non] = d.querySelectorAll('button');
    const repondre = async (accepte) => { d.querySelector('.btns').remove(); d.classList.add('fait'); document.getElementById('btn').disabled = true; const r = await api({action: 'outil', tool_use_id: o.id, nom: o.nom, input: o.input, accepte}); document.getElementById('btn').disabled = false; await traiter(r); };
    ok.onclick = () => repondre(true); non.onclick = () => repondre(false);
  }
}
async function envoyer(e) {
  e.preventDefault(); const t = document.getElementById('texte'); const v = t.value.trim(); if (!v) return;
  bulle('u', esc(v).replace(/\n/g, '<br>')); t.value = ''; document.getElementById('btn').disabled = true;
  const att = bulle('a attente', '…');
  try { const r = await api({action: 'message', texte: v}); att.remove(); await traiter(r); } catch (err) { att.remove(); bulle('err', 'Erreur de connexion.'); }
  document.getElementById('btn').disabled = false; t.focus();
}
// ---- Voix : dictée (reconnaissance vocale du navigateur) et lecture des réponses ----
const SR = window.SpeechRecognition || window.webkitSpeechRecognition; let rec = null, ecoute = false, VOIX = false;
try { VOIX = localStorage.getItem('assistant_voix') === '1'; } catch (e) {}
if (SR) { const m = document.getElementById('micro'); m.hidden = false;
  m.onclick = () => { if (ecoute) { rec.stop(); return; }
    rec = new SR(); rec.lang = 'fr-FR'; rec.interimResults = true; rec.continuous = false; const t = document.getElementById('texte'); const base = t.value ? t.value.replace(/\s+$/, '') + ' ' : '';
    rec.onresult = ev => { let fin = '', enCours = ''; for (const r of ev.results) (r.isFinal ? (fin += r[0].transcript) : (enCours += r[0].transcript)); t.value = base + fin + enCours; };
    rec.onend = () => { ecoute = false; m.innerHTML = '<svg class="pi"><use href="#pi-mic"/></svg> Dicter'; m.classList.remove('on'); t.focus(); if (t.value.trim() && m.dataset.auto === '1') document.getElementById('fchat').requestSubmit(); };
    rec.onerror = ev => { ecoute = false; m.innerHTML = '<svg class="pi"><use href="#pi-mic"/></svg> Dicter'; m.classList.remove('on'); if (ev.error === 'not-allowed') bulle('err', "Le micro est bloqué : autorise-le pour ce site dans le navigateur."); };
    try { rec.start(); ecoute = true; m.innerHTML = '<svg class="pi"><use href="#pi-stop"/></svg> Stop'; m.classList.add('on'); m.dataset.auto = '1'; } catch (e) {} };
}
if ('speechSynthesis' in window) { const l = document.getElementById('lire'); l.hidden = false; const maj = () => { l.innerHTML = '<svg class="pi"><use href="#pi-speaker"/></svg> ' + (VOIX ? 'Lecture activée' : 'Lire'); l.title = VOIX ? 'Lecture à voix haute activée (cliquer pour couper)' : 'Lire les réponses à voix haute'; }; maj();
  l.onclick = () => { VOIX = !VOIX; try { localStorage.setItem('assistant_voix', VOIX ? '1' : '0'); } catch (e) {} if (!VOIX) speechSynthesis.cancel(); else { const u = new SpeechSynthesisUtterance('Lecture activée.'); u.lang = 'fr-FR'; speechSynthesis.speak(u); } maj(); }; }
function lireVoix(txt) { if (!VOIX || !('speechSynthesis' in window)) return; speechSynthesis.cancel(); const u = new SpeechSynthesisUtterance(txt.replace(/[*#_`]/g, '').slice(0, 1500)); u.lang = 'fr-FR'; u.rate = 1.05; speechSynthesis.speak(u); }
document.getElementById('texte').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); document.getElementById('fchat').requestSubmit(); } });
fil.scrollTop = fil.scrollHeight;
if (PANNEAU && !CID && !location.search.includes('nouvelle')) { try { const c = sessionStorage.getItem('assistant_cid'); if (c) location.replace('assistant.php?panneau=1&c=' + c + (BORD ? '&bordereau=' + BORD : '') + (PAGE ? '&page=' + PAGE : '')); } catch (e) {} }
</script>
<?php if ($panneau) echo '</body></html>'; else page_end(); ?>
