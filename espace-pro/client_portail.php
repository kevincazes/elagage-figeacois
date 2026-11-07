<?php
// Espace client (elagage-figeacois.com/espace-client/) : chaque client retrouve ses interventions, ses documents
// (rapports, bons d'intervention, contrats, devis…), ses photos de chantier, peut faire une demande, et accède à ses outils
// (Grality pour le suivi de son patrimoine arboré, Urgence Élagage pour les collectivités).
// Connexion sans mot de passe : un lien valable 30 minutes envoyé par e-mail ; l'appareil reste ensuite reconnu 60 jours.
declare(strict_types=1);
if (!defined('EF_PORTAIL')) { http_response_code(404); exit; }
require_once __DIR__ . '/planning_lib.php';
planning_migrer_tout();

const PORTAIL_TYPES = ['chantier', 'visite', 'rdv', 'urgence']; // rendez-vous visibles par le client
const PORTAIL_OBJETS = ['devis' => 'Demande de devis', 'intervention' => 'Intervention ou entretien', 'rdv' => 'Prendre ou déplacer un rendez-vous', 'question' => 'Question', 'autre' => 'Autre'];

function portail_cookie(string $v, int $jours = 60): void {
    setcookie('ef_client', $v, ['expires' => $v === '' ? time() - 3600 : time() + $jours * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}
function portail_verifier(string $brut, string $type): ?array {
    if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $brut, $m)) return null;
    $j = q("SELECT j.*, a.client_id, a.email, a.nom, a.actif FROM client_jetons j JOIN client_acces a ON a.id = j.acces_id WHERE j.selecteur = ? AND j.type = ? AND j.expire > NOW()", [$m[1], $type])->fetch();
    if (!$j || !(int)$j['actif'] || !hash_equals($j['jeton_hash'], hash('sha256', $m[2]))) return null;
    if ($type === 'lien' && $j['utilise_le'] !== null) return null;
    return $j;
}
/** Comptes d'accès actifs pour cette adresse e-mail (une même personne peut suivre plusieurs clients) */
function portail_acces_email(string $email): array {
    return q("SELECT a.*, c.nom client_nom FROM client_acces a JOIN clients c ON c.id = a.client_id WHERE a.email = ? AND a.actif = 1 AND c.actif = 1 ORDER BY c.nom", [mb_strtolower(trim($email))])->fetchAll();
}
function portail_ouvrir(array $j): void {
    session_regenerate_id(true);
    $_SESSION['portail'] = ['email' => $j['email'], 'acces_id' => (int)($j['acces_id'] ?? $j['id']), 'client_id' => (int)$j['client_id']];
    q("UPDATE client_acces SET derniere_connexion = NOW() WHERE email = ?", [$j['email']]);
}
function portail_session(): ?array {
    if (!empty($_SESSION['portail'])) {
        $a = q("SELECT a.id FROM client_acces a JOIN clients c ON c.id = a.client_id WHERE a.id = ? AND a.actif = 1 AND c.actif = 1", [$_SESSION['portail']['acces_id']])->fetch();
        if ($a) return $_SESSION['portail'];
        unset($_SESSION['portail']);
    }
    if (!empty($_COOKIE['ef_client']) && ($j = portail_verifier((string)$_COOKIE['ef_client'], 'session'))) {
        q("UPDATE client_jetons SET expire = DATE_ADD(NOW(), INTERVAL 60 DAY) WHERE id = ?", [$j['id']]); portail_cookie((string)$_COOKIE['ef_client']);
        portail_ouvrir($j); return $_SESSION['portail'];
    }
    return null;
}
function portail_csrf_ok(): bool { return hash_equals(csrf(), (string)($_POST['csrf'] ?? '')); }

// ---------- Lien de connexion reçu par e-mail ----------
if (isset($_GET['t'])) {
    $j = portail_verifier((string)$_GET['t'], 'lien');
    if ($j) {
        q("UPDATE client_jetons SET utilise_le = NOW() WHERE id = ?", [$j['id']]);
        portail_ouvrir($j); portail_cookie(portail_jeton((int)$j['acces_id'], 'session', '60 DAY'));
        header('Location: ' . portail_url()); exit;
    }
    $_SESSION['portail_msg'] = 'Ce lien de connexion a expiré ou a déjà servi. Demandez-en un nouveau ci-dessous.';
    header('Location: ' . portail_url()); exit;
}
$moi = portail_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'vis_envoyer') {
    if (!portail_csrf_ok()) { $_SESSION['portail_msg'] = 'La page a expiré, recommencez.'; header('Location: ' . portail_url()); exit; }
    $a = $_POST['action'] ?? '';
    if ($a === 'lien') {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && ($acc = portail_acces_email($email))) {
            $recents = (int)q("SELECT COUNT(*) c FROM client_jetons WHERE acces_id = ? AND type = 'lien' AND cree_le > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$acc[0]['id']])->fetch()['c'];
            if ($recents < 5) {
                $lien = portail_url('t=' . portail_jeton((int)$acc[0]['id'], 'lien', '30 MINUTE'));
                envoyer_mail($email, 'Votre lien de connexion – Espace client ' . setting('ent_nom', 'Élagage Figeacois'),
                    "Bonjour,\n\nVoici votre lien pour ouvrir votre espace client " . setting('ent_nom', 'Élagage Figeacois') . " (valable 30 minutes, à usage unique) :\n\n$lien\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez simplement ce message.\n\nBien cordialement,\n" . setting('ent_nom', 'Élagage Figeacois') . "\n" . setting('ent_tel'));
            }
        }
        $_SESSION['portail_envoye'] = $email; header('Location: ' . portail_url()); exit; // même réponse que l'adresse soit connue ou non
    }
    if ($a === 'sortir') {
        if (!empty($_COOKIE['ef_client']) && preg_match('/^([a-f0-9]{24})\./', (string)$_COOKIE['ef_client'], $m)) q("DELETE FROM client_jetons WHERE selecteur = ?", [$m[1]]);
        portail_cookie(''); unset($_SESSION['portail']); header('Location: ' . portail_url()); exit;
    }
    if (!$moi) { header('Location: ' . portail_url()); exit; }
    if ($a === 'client') { // changer de client suivi (même adresse e-mail)
        foreach (portail_acces_email($moi['email']) as $x) if ((int)$x['client_id'] === (int)($_POST['cid'] ?? 0)) { $_SESSION['portail']['client_id'] = (int)$x['client_id']; $_SESSION['portail']['acces_id'] = (int)$x['id']; }
        header('Location: ' . portail_url()); exit;
    }
    if ($a === 'demande') {
        $objet = array_key_exists($_POST['objet'] ?? '', PORTAIL_OBJETS) ? $_POST['objet'] : 'autre';
        $msg = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 5000); $adr = mb_substr(trim((string)($_POST['adresse'] ?? '')), 0, 255);
        if ($msg === '') { $_SESSION['portail_msg'] = 'Écrivez quelques mots pour décrire votre demande.'; header('Location: ' . portail_url('p=demande')); exit; }
        q("INSERT INTO client_demandes (client_id, acces_id, objet, message, adresse) VALUES (?,?,?,?,?)", [$moi['client_id'], $moi['acces_id'], $objet, $msg, $adr]);
        $cl = q("SELECT nom FROM clients WHERE id = ?", [$moi['client_id']])->fetch();
        notifier_gestionnaires('Demande client : ' . PORTAIL_OBJETS[$objet], ($cl['nom'] ?? '') . ' : ' . mb_substr($msg, 0, 200), 'clients.php?id=' . $moi['client_id'] . '&o=portail');
        if (setting('ent_email') !== '') envoyer_mail(setting('ent_email'), 'Espace client – ' . PORTAIL_OBJETS[$objet] . ' – ' . ($cl['nom'] ?? ''), "Nouvelle demande depuis l'espace client.\n\nClient : " . ($cl['nom'] ?? '') . "\nDe : {$moi['email']}\nObjet : " . PORTAIL_OBJETS[$objet] . ($adr ? "\nAdresse : $adr" : '') . "\n\n$msg\n\n" . preg_replace('#/espace-client/?$#', '', portail_url()) . "/espace-pro/clients.php?id={$moi['client_id']}&o=portail");
        $_SESSION['portail_msg_ok'] = 'Votre demande est bien envoyée. Nous revenons vers vous rapidement.'; header('Location: ' . portail_url()); exit;
    }
    header('Location: ' . portail_url()); exit;
}

// ---------- Fichiers (seulement ceux du client connecté, et visibles par lui) ----------
if ($moi && (isset($_GET['doc']) || isset($_GET['photo']))) {
    if (isset($_GET['doc'])) { $d = q("SELECT * FROM client_docs WHERE id = ? AND client_id = ? AND visible_client = 1", [(int)$_GET['doc'], $moi['client_id']])->fetch(); $f = $d ? chemin_fichier($d['fichier']) : null; $nom = $d['nom'] ?? ''; }
    else { $d = q("SELECT p.* FROM rapport_photos p JOIN evenements e ON e.id = p.evenement_id JOIN rapports r ON r.evenement_id = e.id WHERE p.id = ? AND e.client_id = ? AND p.dans_pdf = 1 AND r.envoye_le IS NOT NULL", [(int)$_GET['photo'], $moi['client_id']])->fetch(); $f = $d ? chemin_fichier($d['fichier']) : null; $nom = 'photo.' . pathinfo((string)$f, PATHINFO_EXTENSION); }
    if (!$f) { http_response_code(404); exit('Document introuvable'); }
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'txt' => 'text/plain; charset=utf-8'];
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: ' . (isset($types[$ext]) && empty($_GET['dl']) ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\n", "\r"], '', $nom) . '"');
    readfile($f); exit;
}

// ---------- Visionneuse ----------
$vis = null;
if ($moi && (isset($_GET['voir']) || isset($_GET['voirphoto']))) {
    if (isset($_GET['voir'])) { $d = q("SELECT * FROM client_docs WHERE id = ? AND client_id = ? AND visible_client = 1", [(int)$_GET['voir'], $moi['client_id']])->fetch(); if ($d) $vis = ['nom' => preg_replace('/\.[a-z0-9]{2,4}$/i', '', $d['nom']), 'url' => "?doc={$d['id']}", 'chemin' => chemin_fichier($d['fichier']), 'retour' => '?p=documents']; }
    else { $d = q("SELECT p.*, e.titre FROM rapport_photos p JOIN evenements e ON e.id = p.evenement_id JOIN rapports r ON r.evenement_id = e.id WHERE p.id = ? AND e.client_id = ? AND p.dans_pdf = 1 AND r.envoye_le IS NOT NULL", [(int)$_GET['voirphoto'], $moi['client_id']])->fetch(); if ($d) $vis = ['nom' => 'Photo – ' . $d['titre'] . ($d['legende'] ? ' – ' . $d['legende'] : ''), 'url' => "?photo={$d['id']}", 'chemin' => chemin_fichier($d['fichier']), 'retour' => '?p=photos']; }
    if (!$vis || !$vis['chemin']) { http_response_code(404); exit('Document introuvable'); }
    $vis['message'] = ''; $vis['message_err'] = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vis_envoyer' && portail_csrf_ok()) {
        $_SESSION['portail_envois'] = array_values(array_filter($_SESSION['portail_envois'] ?? [], fn($t) => $t > time() - 3600));
        $a = trim((string)($_POST['a'] ?? ''));
        if (count($_SESSION['portail_envois']) >= 10) { $vis['message'] = "Trop d'envois en peu de temps : téléchargez le document pour le transmettre."; $vis['message_err'] = true; }
        elseif (visionneuse_envoyer($a, (string)($_POST['texte'] ?? ''), $vis['nom'], $vis['chemin'])) { $_SESSION['portail_envois'][] = time(); $vis['message'] = "Document envoyé à $a."; }
        else { $vis['message'] = "L'e-mail n'est pas parti : vérifiez l'adresse."; $vis['message_err'] = true; }
    }
}
// ---------- Données de la page ----------
$p = $moi ? ($_GET['p'] ?? 'accueil') : 'connexion';
if ($moi) {
    $cl = q("SELECT * FROM clients WHERE id = ?", [$moi['client_id']])->fetch();
    $autres = portail_acces_email($moi['email']);
    $in = "'" . implode("','", PORTAIL_TYPES) . "'";
    $aVenir = q("SELECT * FROM evenements WHERE client_id = ? AND type IN ($in) AND statut <> 'annule' AND (debut IS NULL OR debut >= CURDATE()) ORDER BY debut IS NULL, debut LIMIT 20", [$moi['client_id']])->fetchAll();
    $passes = q("SELECT * FROM evenements WHERE client_id = ? AND type IN ($in) AND statut <> 'annule' AND debut < CURDATE() ORDER BY debut DESC LIMIT 40", [$moi['client_id']])->fetchAll();
    $docs = q("SELECT * FROM client_docs WHERE client_id = ? AND visible_client = 1 ORDER BY cree_le DESC", [$moi['client_id']])->fetchAll();
    $aSigner = q("SELECT * FROM contrats WHERE client_id = ? AND statut = 'envoye' AND token <> '' ORDER BY envoye_le DESC", [$moi['client_id']])->fetchAll();
    $photos = q("SELECT p.id, p.legende, e.titre, e.debut FROM rapport_photos p JOIN evenements e ON e.id = p.evenement_id JOIN rapports r ON r.evenement_id = e.id WHERE e.client_id = ? AND p.dans_pdf = 1 AND r.envoye_le IS NOT NULL ORDER BY e.debut DESC, p.ordre LIMIT 120", [$moi['client_id']])->fetchAll();
    $demandes = q("SELECT * FROM client_demandes WHERE client_id = ? ORDER BY cree_le DESC LIMIT 10", [$moi['client_id']])->fetchAll();
    $adresses = q("SELECT * FROM client_adresses WHERE client_id = ? ORDER BY ordre, id", [$moi['client_id']])->fetchAll();
}
$msg = $_SESSION['portail_msg'] ?? ''; $ok = $_SESSION['portail_msg_ok'] ?? ''; $envoye = $_SESSION['portail_envoye'] ?? null; unset($_SESSION['portail_msg'], $_SESSION['portail_msg_ok'], $_SESSION['portail_envoye']);
$ent = setting('ent_nom', 'Élagage Figeacois'); $tel = setting('ent_tel'); $mail = setting('ent_email');
$pi = fn(string $i) => '<svg class="pi" aria-hidden="true"><use href="#pi-' . $i . '"/></svg>';
$quand = function (array $e): string { if (!$e['debut']) return 'Date à convenir'; $d = date_fr($e['debut'], false); return ucfirst($d) . ((int)$e['journee'] ? '' : ' · ' . date('H\hi', strtotime($e['debut']))); };
$icDoc = fn(string $dos) => ['Devis' => 'doc', 'Contrats signés' => 'clipboard', 'Rapports de rendez-vous' => 'clipboard', "Bons d'intervention" => 'clipboard', 'Plans' => 'pin', 'Courriers' => 'mail', 'Photos' => 'camera'][$dos] ?? 'doc';
header('Cache-Control: no-store');
?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex">
<title>Espace client – <?= e($ent) ?></title><link rel="icon" href="../espace-pro/icone-192.png"><meta name="theme-color" content="#1f3b2c">
<style>
:root{--sapin:#1f3b2c;--anis:#8aa33c;--creme:#f4f3ee;--blanc:#fff;--texte:#1d2621;--doux:#5f6b63;--ligne:#e1e5da;--clair:#eef3e2;--brun:#683c11;--rouge:#c8463b}
*{box-sizing:border-box}body{margin:0;background:var(--creme);color:var(--texte);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--sapin)}.pi{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
header.h{background:var(--sapin);color:#fff;padding:calc(14px + env(safe-area-inset-top,0px)) max(16px,env(safe-area-inset-right,0px)) 14px max(16px,env(safe-area-inset-left,0px))}
.h-in{max-width:980px;margin:0 auto;display:flex;align-items:center;gap:14px;flex-wrap:wrap}.h-in img{height:44px;background:#fff;border-radius:10px;padding:4px}.h-in strong{font-size:1.1rem;display:block}.h-in small{opacity:.8}
.h-in .esp{flex:1}.h-in form{margin:0}.h-in button{background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:999px;padding:8px 14px;font:inherit;font-weight:700;cursor:pointer;display:flex;gap:6px;align-items:center}
nav.o{background:var(--blanc);border-bottom:1px solid var(--ligne);position:sticky;top:0;z-index:5}.o-in{max-width:980px;margin:0 auto;display:flex;gap:4px;overflow-x:auto;padding:6px max(10px,env(safe-area-inset-left,0px))}
.o-in a{display:flex;gap:6px;align-items:center;padding:9px 14px;border-radius:10px;text-decoration:none;color:var(--doux);font-weight:700;white-space:nowrap}.o-in a.on{background:var(--clair);color:var(--sapin)}
main{max-width:980px;margin:0 auto;padding:20px max(16px,env(safe-area-inset-right,0px)) 60px max(16px,env(safe-area-inset-left,0px))}
h1{font-size:1.6rem;margin:0 0 4px;color:var(--sapin)}h2{font-size:1.15rem;margin:26px 0 10px;color:var(--sapin)}.muted{color:var(--doux)}.small{font-size:.88rem}
.carte{background:var(--blanc);border:1px solid var(--ligne);border-radius:16px;padding:14px 16px;margin:0 0 10px}
.grille{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
.evt{display:flex;gap:12px;align-items:flex-start}.evt .ic{width:42px;height:42px;border-radius:12px;background:var(--clair);color:var(--sapin);display:grid;place-items:center;flex:none}
.evt b{display:block}.tag{display:inline-block;font-size:.78rem;font-weight:800;padding:2px 9px;border-radius:999px;background:var(--clair);color:var(--sapin)}.tag.a{background:#fbecd7;color:#8a5a12}
.doc{display:flex;gap:12px;align-items:center;padding:11px 0;border-top:1px solid #f0f2ec;text-decoration:none;color:inherit}.doc:first-child{border-top:0}.doc span{flex:1;min-width:0}.doc b{display:block;overflow-wrap:anywhere}
.btn{display:inline-flex;gap:8px;align-items:center;background:var(--anis);color:#fff;border:0;border-radius:999px;padding:12px 20px;font:inherit;font-weight:800;text-decoration:none;cursor:pointer}.btn.s{background:var(--blanc);color:var(--sapin);border:1px solid var(--ligne)}
label{display:block;font-weight:700;margin:0 0 12px}input,select,textarea{display:block;width:100%;margin-top:6px;font:inherit;padding:12px 14px;border:1px solid #cfd6c6;border-radius:12px;background:#fbfbf8}textarea{min-height:140px}
.msg{padding:12px 14px;border-radius:12px;margin:0 0 14px;background:#fdecea;color:#8a2a22}.msg.ok{background:var(--clair);color:var(--sapin)}
.phg{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px}.phg a{display:block}.phg img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:10px;display:block}
.lien{display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit}.lien b,.lien .small{display:block}.lien .ic{width:46px;height:46px;border-radius:14px;background:var(--sapin);color:#fff;display:grid;place-items:center;flex:none}
.connex{max-width:440px;margin:30px auto}.vide{color:var(--doux);font-style:italic}
footer{text-align:center;color:var(--doux);font-size:.85rem;padding:20px 16px 40px}
</style></head><body>
<?= pictos_sprite() ?>
<header class="h"><div class="h-in"><img src="../images/logo-elagage-figeacois.png" alt="" onerror="this.remove()"><div><strong>Espace client</strong><small><?= e($ent) ?></small></div><span class="esp"></span>
<?php if ($moi): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button name="action" value="sortir"><?= $pi('sortie') ?>Se déconnecter</button></form><?php endif; ?></div></header>
<?php if ($moi): ?>
<nav class="o"><div class="o-in"><?php foreach (['accueil' => ['house', 'Accueil'], 'interventions' => ['calendar', 'Interventions'], 'documents' => ['doc', 'Documents'], 'photos' => ['camera', 'Photos'], 'demande' => ['mail', 'Faire une demande']] as $k => [$i, $l]): ?><a href="?p=<?= $k ?>" class="<?= $p === $k ? 'on' : '' ?>"><?= $pi($i) . e($l) ?></a><?php endforeach; ?></div></nav>
<?php endif; ?>
<main>
<?php if ($msg): ?><p class="msg"><?= e($msg) ?></p><?php endif; ?><?php if ($ok): ?><p class="msg ok"><?= e($ok) ?></p><?php endif; ?>

<?php if (!$moi): ?>
  <div class="connex carte">
  <?php if ($envoye !== null): ?>
    <h1>Vérifiez vos e-mails</h1>
    <p>Si l'adresse <strong><?= e($envoye) ?></strong> est enregistrée chez nous, vous allez recevoir un lien pour ouvrir votre espace client. Il est valable 30 minutes.</p>
    <p class="small muted">Rien reçu après quelques minutes ? Regardez dans les indésirables, ou contactez-nous<?= $tel ? ' au ' . e($tel) : '' ?> pour que nous ouvrions votre accès.</p>
    <p><a class="btn s" href="./">Recommencer</a></p>
  <?php else: ?>
    <h1>Votre espace client</h1>
    <p class="muted">Retrouvez vos interventions, rapports, contrats et photos de chantier, et faites vos demandes en ligne.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="lien">
      <label>Votre adresse e-mail<input type="email" name="email" required autocomplete="email" placeholder="vous@exemple.fr"></label>
      <button class="btn"><?= $pi('mail') ?>Recevoir mon lien de connexion</button></form>
    <p class="small muted" style="margin-top:14px">Pas de mot de passe à retenir : nous vous envoyons un lien par e-mail. Votre accès est ouvert par <?= e($ent) ?> ; si vous n'en avez pas encore, demandez-le-nous<?= $tel ? ' au ' . e($tel) : '' ?>.</p>
  <?php endif; ?></div>

<?php elseif ($vis): ?>
  <?= visionneuse_html(['nom' => $vis['nom'], 'url' => $vis['url'], 'url_dl' => $vis['url'] . '&dl=1', 'ext' => pathinfo($vis['chemin'], PATHINFO_EXTENSION), 'retour' => $vis['retour'], 'retour_libelle' => 'Retour', 'envoi' => true, 'email' => $moi['email'], 'message' => $vis['message'], 'message_err' => $vis['message_err']]) ?>
<?php elseif ($p === 'accueil'): ?>
  <h1>Bonjour</h1>
  <p class="muted"><?= e($cl['nom']) ?><?php if (count($autres) > 1): ?> · <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="client"><select name="cid" onchange="this.form.submit()" style="display:inline-block;width:auto;padding:4px 8px;margin:0"><?php foreach ($autres as $x): ?><option value="<?= $x['client_id'] ?>" <?= (int)$x['client_id'] === $moi['client_id'] ? 'selected' : '' ?>><?= e($x['client_nom']) ?></option><?php endforeach; ?></select></form><?php endif; ?></p>
  <?php foreach ($aSigner as $k): ?><div class="carte" style="border-color:#e8c27a;background:#fff8ec"><div class="lien"><span class="ic" style="background:#c77a12"><?= $pi('crayon') ?></span><span style="flex:1"><b>Contrat d'entretien n° <?= e($k['numero']) ?> à signer</b><span class="small muted">Lisez-le et signez-le en ligne en quelques secondes.</span></span><a class="btn" href="../espace-pro/signer.php?t=<?= e($k['token']) ?>">Signer</a></div></div><?php endforeach; ?>
  <h2>Prochaines interventions</h2>
  <?php if (!$aVenir): ?><p class="vide">Aucune intervention prévue pour le moment.</p><?php endif; ?>
  <div class="grille"><?php foreach (array_slice($aVenir, 0, 4) as $e): ?><div class="carte evt"><span class="ic"><?= $pi($e['type'] === 'chantier' ? 'tree' : 'calendar') ?></span><span><b><?= e($quand($e)) ?></b><?= e(TYPES_EVT[$e['type']][0] ?? '') ?><?= $e['ville'] ? ' · ' . e($e['ville']) : '' ?><br><span class="tag <?= $e['statut'] === 'confirme' ? '' : 'a' ?>"><?= e(STATUTS_EVT[$e['statut']] ?? '') ?></span></span></div><?php endforeach; ?></div>
  <h2>Derniers documents</h2>
  <div class="carte"><?php if (!$docs): ?><p class="vide" style="margin:0">Aucun document pour l'instant.</p><?php endif; ?><?php foreach (array_slice($docs, 0, 5) as $d): ?><a class="doc" href="?voir=<?= $d['id'] ?>"><?= $pi($icDoc($d['dossier'])) ?><span><b><?= e($d['nom']) ?></b><span class="small muted"><?= e($d['dossier']) ?> · <?= e(date('d/m/Y', strtotime($d['cree_le']))) ?></span></span><?= $pi('chevron') ?></a><?php endforeach; ?>
  <?php if (count($docs) > 5): ?><p style="margin:10px 0 0"><a href="?p=documents">Voir les <?= count($docs) ?> documents</a></p><?php endif; ?></div>
  <h2>Vos outils</h2>
  <div class="grille">
    <a class="carte lien" href="?p=demande"><span class="ic"><?= $pi('mail') ?></span><span><b>Faire une demande</b><span class="small muted">Devis, intervention, rendez-vous, question</span></span></a>
    <?php if (preg_match('#^https://#i', (string)$cl['grality_url'])): ?><a class="carte lien" href="<?= e($cl['grality_url']) ?>" target="_blank" rel="noopener"><span class="ic"><?= $pi('tree') ?></span><span><b>Mon patrimoine arboré</b><span class="small muted">Votre projet de gestion sur Grality (Arbogestion)</span></span></a><?php endif; ?>
    <?php if ($cl['type'] === 'collectivite'): ?><a class="carte lien" href="https://urgence-elagage.fr" target="_blank" rel="noopener"><span class="ic" style="background:var(--rouge)"><?= $pi('megaphone') ?></span><span><b>Urgence Élagage</b><span class="small muted">Arbre tombé ou dangereux : signalement 24 h/24</span></span></a><?php endif; ?>
    <?php if ($tel || $mail): ?><div class="carte lien"><span class="ic" style="background:var(--anis)"><?= $pi('personne') ?></span><span><b>Nous joindre</b><span class="small muted"><?= e($tel) ?><?= $tel && $mail ? '<br>' : '' ?><?= e($mail) ?></span></span></div><?php endif; ?>
  </div>

<?php elseif ($p === 'interventions'): ?>
  <h1>Vos interventions</h1>
  <h2>À venir</h2><?php if (!$aVenir): ?><p class="vide">Aucune intervention prévue.</p><?php endif; ?>
  <?php foreach ($aVenir as $e): ?><div class="carte evt"><span class="ic"><?= $pi($e['type'] === 'chantier' ? 'tree' : 'calendar') ?></span><span><b><?= e($quand($e)) ?></b><?= e(TYPES_EVT[$e['type']][0] ?? '') ?> · <span class="tag <?= $e['statut'] === 'confirme' ? '' : 'a' ?>"><?= e(STATUTS_EVT[$e['statut']] ?? '') ?></span><br><span class="small muted"><?= e(trim($e['adresse'] . ' ' . $e['cp'] . ' ' . $e['ville'])) ?></span></span></div><?php endforeach; ?>
  <h2>Réalisées</h2><?php if (!$passes): ?><p class="vide">Aucune intervention passée.</p><?php endif; ?>
  <?php foreach ($passes as $e): ?><div class="carte evt"><span class="ic" style="background:#f0f0ea;color:var(--doux)"><?= $pi($e['type'] === 'chantier' ? 'tree' : 'calendar') ?></span><span><b><?= e($quand($e)) ?></b><?= e(TYPES_EVT[$e['type']][0] ?? '') ?><br><span class="small muted"><?= e(trim($e['adresse'] . ' ' . $e['cp'] . ' ' . $e['ville'])) ?></span></span></div><?php endforeach; ?>

<?php elseif ($p === 'documents'): ?>
  <h1>Vos documents</h1>
  <?php $g = []; foreach ($docs as $d) $g[$d['dossier']][] = $d; if (!$g): ?><p class="vide">Aucun document pour l'instant. Vos rapports, contrats et devis apparaîtront ici.</p><?php endif; ?>
  <?php foreach (DOSSIERS_CLIENT as $dos): if (empty($g[$dos])) continue; ?><h2><?= e($dos) ?></h2><div class="carte"><?php foreach ($g[$dos] as $d): ?><a class="doc" href="?voir=<?= $d['id'] ?>"><?= $pi($icDoc($dos)) ?><span><b><?= e($d['nom']) ?></b><span class="small muted"><?= e(date('d/m/Y', strtotime($d['cree_le']))) ?></span></span><?= $pi('chevron') ?></a><?php endforeach; ?></div><?php endforeach; ?>

<?php elseif ($p === 'photos'): ?>
  <h1>Photos de vos chantiers</h1>
  <?php if (!$photos): ?><p class="vide">Les photos jointes à nos rapports apparaîtront ici.</p><?php endif; ?>
  <?php $t = null; foreach ($photos as $ph): if ($t !== $ph['titre'] . $ph['debut']): if ($t !== null) echo '</div>'; $t = $ph['titre'] . $ph['debut']; ?><h2><?= e($ph['debut'] ? ucfirst(date_fr($ph['debut'], false)) : $ph['titre']) ?></h2><div class="phg"><?php endif; ?>
    <a href="?voirphoto=<?= $ph['id'] ?>" title="<?= e($ph['legende']) ?>"><img src="?photo=<?= $ph['id'] ?>" alt="<?= e($ph['legende'] ?: 'Photo du chantier') ?>" loading="lazy"></a>
  <?php endforeach; if ($t !== null) echo '</div>'; ?>

<?php elseif ($p === 'demande'): ?>
  <h1>Faire une demande</h1>
  <p class="muted">Votre message arrive directement à notre secrétariat.<?= $cl['type'] === 'collectivite' ? ' Pour un arbre tombé ou dangereux, utilisez plutôt <a href="https://urgence-elagage.fr" target="_blank" rel="noopener">Urgence Élagage</a> (24 h/24).' : '' ?></p>
  <form method="post" class="carte"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="demande">
    <label>Objet<select name="objet"><?php foreach (PORTAIL_OBJETS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label>Adresse concernée (facultatif)<?php if ($adresses): ?><select name="adresse"><option value="">—</option><?php foreach ($adresses as $x): $v = trim($x['adresse'] . ', ' . $x['cp'] . ' ' . $x['ville'], ', '); ?><option value="<?= e($v) ?>"><?= e(($x['libelle'] ? $x['libelle'] . ' : ' : '') . $v) ?></option><?php endforeach; ?></select><?php else: ?><input name="adresse" placeholder="Adresse du chantier"><?php endif; ?></label>
    <label>Votre message<textarea name="message" required placeholder="Décrivez votre besoin : arbres concernés, période souhaitée, accès…"></textarea></label>
    <button class="btn"><?= $pi('mail') ?>Envoyer ma demande</button></form>
  <?php if ($demandes): ?><h2>Vos dernières demandes</h2><?php foreach ($demandes as $d): ?><div class="carte"><b><?= e(PORTAIL_OBJETS[$d['objet']] ?? 'Demande') ?></b> <span class="tag <?= $d['traitee_le'] ? '' : 'a' ?>"><?= $d['traitee_le'] ? 'Traitée' : 'Reçue' ?></span><br><span class="small muted"><?= e(date('d/m/Y à H\hi', strtotime($d['cree_le']))) ?></span><p style="margin:6px 0 0;white-space:pre-line"><?= e(mb_strimwidth($d['message'], 0, 400, '…')) ?></p></div><?php endforeach; ?><?php endif; ?>
<?php endif; ?>
</main>
<footer><?= e($ent) ?><?= $tel ? ' · ' . e($tel) : '' ?> · <a href="../">Site internet</a></footer>
</body></html>
