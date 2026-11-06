<?php
// Réglages du planning (administrateur) : entreprise (PDF), SMS, avis Google, notifications, Pennylane / GoCardless, Urgence Élagage
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_admin();
planning_migrate();
const SECRETS = ['ovh_app_secret', 'ovh_consumer_key', 'pl_token', 'urgence_cle', 'xb_cle'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    $champs = ['entreprise' => ['ent_nom', 'ent_tel', 'ent_email', 'ent_adresse', 'ent_mentions'],
               'sms' => ['ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key', 'ovh_sms_service', 'sms_expediteur'],
               'avis' => ['avis_lien', 'avis_delai', 'avis_canal'],
               'pennylane' => ['pl_token', 'pl_echeance', 'pl_tva'],
               'urgence' => ['urgence_api', 'urgence_cle'], 'extrabat' => ['xb_api', 'xb_cle', 'xb_auth', 'xb_entete', 'xb_third', 'xb_login_chemin', 'xb_login_corps']][$a] ?? [];
    foreach ($champs as $k) { if (!isset($_POST[$k])) continue; $v = trim((string)$_POST[$k]); if (in_array($k, SECRETS, true) && $v === '') continue; set_setting($k, $v); }
    $cases = ['sms' => ['sms_actif', 'sms_proposition', 'sms_rappel', 'sms_avis', 'sms_contrat'], 'avis' => ['avis_actif'], 'notifications' => ['push_actif'], 'pennylane' => ['pl_mandat_auto']][$a] ?? [];
    foreach ($cases as $k) set_setting($k, !empty($_POST[$k]) ? '1' : '0');
    if (!empty($_POST['effacer'])) foreach ((array)$_POST['effacer'] as $k) if (in_array($k, SECRETS, true)) set_setting($k, '');
    if ($a === 'sms_test') { $m = sms_envoyer((string)($_POST['tel'] ?? ''), setting('ent_nom') . ' : SMS de test de l\'espace pro.', null, 'test'); flash($m === '' ? 'SMS de test envoyé.' : "Échec : $m", $m === '' ? 'ok' : 'err'); }
    if ($a === 'pl_test') { [$c, $j, $t] = pl_api('GET', '/customers', null, ['limit' => 1]); flash($c === 200 ? 'Pennylane répond : connexion OK.' : "Pennylane refuse (HTTP $c) : " . pl_erreur($j, $t), $c === 200 ? 'ok' : 'err'); }
    if ($a === 'urgence_test') { $n = urgence_sync(true); flash(setting('urgence_cle') === '' ? 'Renseigne la clé.' : "Urgence Élagage : " . setting('urgence_etat') . " – $n intervention(s) reprise(s).", str_starts_with(setting('urgence_etat'), 'OK') ? 'ok' : 'err'); }
    if ($a === 'xb_jeton') { [$tok, $log] = xb_jeton(true); set_setting('xb_dernier_test', mb_substr(date('d/m/Y H:i') . " – recherche du point de connexion (jeton JWT)\nForme de la clé : " . xb_diag_cle() . "\n\n" . $log, 0, 6000)); flash($tok !== '' ? 'Jeton obtenu : tu peux maintenant tester /clients avec le mode « Jeton JWT ».' : 'Pas de jeton obtenu : voir le détail ci-dessous.', $tok !== '' ? 'ok' : 'err'); }
    if ($a === 'xb_test') { [$c, $corps, $m] = setting('xb_auth', 'bearer') === 'jwt' ? xb_get((string)($_POST['chemin'] ?? '/')) : xb_appel((string)($_POST['chemin'] ?? '/')); set_setting('xb_dernier_test', mb_substr(date('d/m/Y H:i') . " – " . ($_POST['chemin'] ?? '/') . " → " . ($c ?: 'échec') . ($m ? " ($m)" : '') . "\n\n" . $corps, 0, 6000)); flash($c >= 200 && $c < 300 ? "Extrabat répond (HTTP $c) : voir la réponse ci-dessous." : "Extrabat : " . ($c ?: 'pas de réponse') . ($m ? " – $m" : ''), $c >= 200 && $c < 300 ? 'ok' : 'err'); }
    if ($a === 'xb_explorer') { $out = date('d/m/Y H:i') . " – exploration de " . setting('xb_api', 'https://api.extrabat.com/v1') . "\n"; $trouves = 0;
        foreach (['', '/me', '/user', '/users/me', '/account', '/docs', '/doc', '/openapi.json', '/swagger.json', '/api-docs', '/devis', '/quotes', '/quotations', '/estimates', '/clients', '/customers', '/contacts', '/prospects', '/societes', '/utilisateurs', '/agenda', '/events', '/evenements', '/rendez-vous', '/appointments', '/planning', '/chantiers', '/interventions', '/affaires', '/dossiers', '/projects', '/documents', '/factures', '/commandes', '/articles', '/produits', '/catalogue', '/clients/1'] as $c) { [$code, $corps, $m] = setting('xb_auth', 'bearer') === 'jwt' ? xb_get($c ?: '/') : xb_appel($c ?: '/'); $ok = $code >= 200 && $code < 300; if ($ok) $trouves++; $out .= str_pad(($c ?: '/'), 16) . ' → ' . ($code ?: 'échec') . ($m && !$code ? " ($m)" : '') . ($ok || $code === 400 || $code === 422 ? '   ' . mb_substr(preg_replace('/\s+/', ' ', preg_replace('/^\[[^\]]*\]\n/', '', $corps)), 0, 160) : '') . "\n"; if (!$code) break; }
        set_setting('xb_dernier_test', mb_substr($out, 0, 6000)); flash($trouves ? "$trouves chemin(s) répondent : voir ci-dessous." : "Aucun chemin n'a répondu positivement : voir les codes ci-dessous (401/403 = clé ou mode d'envoi à revoir, 404 = chemin inconnu).", $trouves ? 'ok' : 'err'); }
    if ($a === 'xb_profond') { // plusieurs adresses de base × modes d'envoi de la clé × quelques chemins : on cherche tout ce qui n'est pas 404
        $out = date('d/m/Y H:i') . " – exploration approfondie (réponses autres que 404 seulement)\n"; $n = 0; $vus = [];
        foreach (['https://api.extrabat.com/v1', 'https://api.extrabat.com', 'https://api.extrabat.com/v2', 'https://api.extrabat.com/api/v1', 'https://api.extrabat.com/api', 'https://myextrabat.com/api/v1', 'https://www.myextrabat.com/api'] as $b) {
            foreach (['bearer', 'entete', 'basic', 'param'] as $m) { foreach (['/clients', '/', '/me', '/devis', '/utilisateurs', '/agenda'] as $c) {
                [$code, $corps, $msg] = xb_appel($c, 'GET', null, $b, $m, 8); $n++;
                if ($code === 0) { $out .= "$b : injoignable ($msg)\n"; continue 3; }
                if ($code !== 404) { $k = "$b$c"; $out .= str_pad($k, 52) . " [clé: $m] → $code  " . mb_substr(preg_replace('/\s+/', ' ', preg_replace('/^\[[^\]]*\]\n/', '', $corps)), 0, 140) . "\n"; $vus[] = $code; }
                if ($m !== 'bearer' && $c === '/' && $code === 404) { /* même 404 sans clé particulière : inutile d'insister sur ce mode */ }
            } } }
        if (!$vus) $out .= "Rien d'autre que des 404 après $n appels : l'API ne reconnaît aucun de ces chemins. Il faut la documentation d'Extrabat (support) ou le nom exact des points d'accès.\n";
        set_setting('xb_dernier_test', mb_substr($out, 0, 6000)); flash($vus ? count($vus) . ' réponse(s) intéressante(s) : voir ci-dessous.' : "Toujours 404 partout ($n essais) : voir ci-dessous.", $vus ? 'ok' : 'err'); }
    if ($a === 'push_test') { notifier([(int)user()['id']], 'Notification de test', 'Si tu lis ceci sur ton téléphone, tout fonctionne.', 'notifications.php'); flash('Notification de test envoyée à tes appareils (' . (int)q("SELECT COUNT(*) c FROM push_abonnements WHERE utilisateur_id = ?", [user()['id']])->fetch()['c'] . ').'); }
    if ($champs || $cases) flash('Réglages enregistrés.');
    redirect('reglages-planning.php#' . $a);
}
$s = fn(string $k, string $d = '') => e(setting($k, $d));
$secret = fn(string $k) => setting($k) !== '' ? '<span class="ok">enregistré</span> <label style="display:inline-flex;gap:4px;font-weight:400;margin:0"><input type="checkbox" name="effacer[]" value="' . $k . '" style="width:auto;margin:0">effacer</label>' : '<span class="muted">non renseigné</span>';
$chk = fn(string $k) => '<input type="checkbox" name="' . $k . '" value="1" style="width:auto;margin:0" ' . (setting($k) === '1' ? 'checked' : '') . '>';
page_start('Réglages du planning', 'reglages-planning.php', ['planning.php', 'Planning']);
?>
<p class="lead">Tout ce qui part vers l'extérieur (SMS, avis, factures) est désactivé tant que tu ne l'actives pas ici.</p>
<p><a class="mini" href="#entreprise">Entreprise</a> <a class="mini" href="#sms">SMS</a> <a class="mini" href="#avis">Avis Google</a> <a class="mini" href="#notifications">Notifications</a> <a class="mini" href="#pennylane">Pennylane / GoCardless</a> <a class="mini" href="#urgence">Urgence Élagage</a> <a class="mini" href="#extrabat">Extrabat</a></p>

<form method="post" class="card" id="entreprise"><?= csrf_field() ?><input type="hidden" name="action" value="entreprise"><h2 style="margin-top:0">Entreprise (en-tête et pied des PDF, e-mails)</h2>
  <div class="cols"><label>Nom <input name="ent_nom" value="<?= $s('ent_nom') ?>"></label><label>Téléphone <input name="ent_tel" value="<?= $s('ent_tel') ?>"></label><label>E-mail (copie des rapports et contrats) <input name="ent_email" value="<?= $s('ent_email') ?>"></label></div>
  <div class="cols"><label>Adresse <textarea name="ent_adresse" rows="2"><?= $s('ent_adresse') ?></textarea></label><label>Mentions légales (pied de page) <textarea name="ent_mentions" rows="2"><?= $s('ent_mentions') ?></textarea></label></div>
  <button class="btn">Enregistrer</button></form>

<form method="post" class="card" id="sms"><?= csrf_field() ?><input type="hidden" name="action" value="sms"><h2 style="margin-top:0">SMS aux clients (OVH)</h2>
  <label style="display:flex;gap:8px;align-items:center;font-weight:900"><?= $chk('sms_actif') ?> Activer l'envoi de SMS</label>
  <div style="display:flex;flex-wrap:wrap;gap:14px;margin:6px 0"><?php foreach (SMS_MOTIFS as $k => $l): ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;margin:0"><input type="checkbox" name="sms_<?= $k ?>" value="1" style="width:auto;margin:0" <?= setting("sms_$k", '1') === '1' ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></div>
  <div class="cols"><label>Application key <input name="ovh_app_key" value="<?= $s('ovh_app_key') ?>"></label><label>Application secret <?= $secret('ovh_app_secret') ?><input name="ovh_app_secret" type="password" autocomplete="new-password"></label><label>Consumer key <?= $secret('ovh_consumer_key') ?><input name="ovh_consumer_key" type="password" autocomplete="new-password"></label></div>
  <div class="cols"><label>Compte SMS (ex. sms-ab12345-1) <input name="ovh_sms_service" value="<?= $s('ovh_sms_service') ?>"></label><label>Expéditeur (11 caractères, validé chez OVH) <input name="sms_expediteur" maxlength="11" value="<?= $s('sms_expediteur') ?>" placeholder="ELAGAGE"></label></div>
  <p class="muted small">Ce sont les mêmes clés que celles d'Urgence Élagage si tu utilises le même compte OVH. Les SMS respectent l'autorisation de chaque client (fiche client › Autres) et ne partent que vers des mobiles.</p>
  <button class="btn">Enregistrer</button></form>
<form method="post" class="cols" style="align-items:end;max-width:560px;margin:-6px 0 18px"><?= csrf_field() ?><input type="hidden" name="action" value="sms_test"><label>Envoyer un SMS de test au <input name="tel" placeholder="06…"></label><button class="mini">Tester</button></form>

<form method="post" class="card" id="avis"><?= csrf_field() ?><input type="hidden" name="action" value="avis"><h2 style="margin-top:0">Demande d'avis Google</h2>
  <label style="display:flex;gap:8px;align-items:center;font-weight:900"><?= $chk('avis_actif') ?> Demander un avis au client après chaque chantier terminé</label>
  <div class="cols"><label>Lien d'avis Google (fiche Google › « Demander des avis ») <input name="avis_lien" value="<?= $s('avis_lien') ?>" placeholder="https://g.page/r/…/review"></label><label>Délai après la fin du chantier (jours) <input name="avis_delai" value="<?= $s('avis_delai', '1') ?>"></label>
    <label>Par <select name="avis_canal"><?php foreach (['email' => 'E-mail', 'sms' => 'SMS', 'les_deux' => 'E-mail et SMS'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('avis_canal', 'email') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label></div>
  <p class="muted small">Un seul envoi par chantier, uniquement si le client l'autorise. Un chantier est « terminé » quand le bon de fin est signé ou quand son statut passe à « Terminé ».</p>
  <button class="btn">Enregistrer</button></form>

<form method="post" class="card" id="notifications"><?= csrf_field() ?><input type="hidden" name="action" value="notifications"><h2 style="margin-top:0">Notifications sur le téléphone</h2>
  <label style="display:flex;gap:8px;align-items:center;font-weight:900"><?= $chk('push_actif') ?> Envoyer les notifications (nouveau chantier, changement de date, congés, réponse d'un client…)</label>
  <p class="muted small">Chaque salarié les active sur son téléphone depuis « Mon planning » ou « Mes notifications » (bouton « Activer les notifications »). Sur iPhone, l'espace pro doit d'abord être ajouté à l'écran d'accueil.</p>
  <button class="btn">Enregistrer</button></form>
<form method="post" style="margin:-6px 0 18px"><?= csrf_field() ?><input type="hidden" name="action" value="push_test"><button class="mini">M'envoyer une notification de test</button></form>

<form method="post" class="card" id="pennylane"><?= csrf_field() ?><input type="hidden" name="action" value="pennylane"><h2 style="margin-top:0">Pennylane (factures) et GoCardless (prélèvements)</h2>
  <div class="cols"><label>Jeton d'API Pennylane <?= $secret('pl_token') ?><input name="pl_token" type="password" autocomplete="new-password"></label><label>Échéance des factures (jours) <input name="pl_echeance" value="<?= $s('pl_echeance', '30') ?>"></label><label>Taux de TVA <select name="pl_tva"><?php foreach (['FR_200' => '20 %', 'FR_100' => '10 %', 'FR_55' => '5,5 %', 'exempt' => 'Exonéré'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('pl_tva', 'FR_200') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label></div>
  <label style="display:flex;gap:8px;align-items:center;font-weight:700"><?= $chk('pl_mandat_auto') ?> À la signature d'un contrat d'entretien, envoyer automatiquement au client la demande de mandat de prélèvement SEPA (GoCardless, depuis Pennylane)</label>
  <p class="muted small">Jeton à créer dans Pennylane › Paramètres › Connectivité › Développeurs, avec les droits : clients, factures clients, mandats GoCardless. GoCardless doit être connecté dans Pennylane (Paramètres › Paiements). Les factures sont créées en brouillon : tu les relis et les valides dans Pennylane.</p>
  <button class="btn">Enregistrer</button></form>
<form method="post" style="margin:-6px 0 18px"><?= csrf_field() ?><input type="hidden" name="action" value="pl_test"><button class="mini">Tester la connexion Pennylane</button></form>

<form method="post" class="card" id="urgence"><?= csrf_field() ?><input type="hidden" name="action" value="urgence"><h2 style="margin-top:0">Urgence Élagage</h2>
  <div class="cols"><label>Adresse de l'API <input name="urgence_api" value="<?= $s('urgence_api', 'https://urgence-elagage.fr/api/v1') ?>"></label><label>Clé d'API (ue_live_…) <?= $secret('urgence_cle') ?><input name="urgence_cle" type="password" autocomplete="new-password"></label></div>
  <p class="muted small">Clé à créer dans Urgence Élagage › Administration › Entreprise › Intégrations (droit « lecture »). Les interventions d'urgence prises en charge ou programmées apparaissent au planning et bloquent l'équipe concernée ; le planning affiche qui est d'astreinte. Dans l'autre sens, colle le « lien agenda » de chaque salarié (Planning › Personnel) dans son compte Urgence Élagage (agenda externe) : ses chantiers comptent alors dans sa disponibilité d'astreinte.<br>État : <?= $s('urgence_etat', 'jamais synchronisé') ?></p>
  <button class="btn">Enregistrer</button></form>
<form method="post" style="margin:-6px 0 18px"><?= csrf_field() ?><input type="hidden" name="action" value="urgence_test"><button class="mini">Synchroniser maintenant</button></form>

<form method="post" class="card" id="extrabat"><?= csrf_field() ?><input type="hidden" name="action" value="extrabat"><h2 style="margin-top:0">Extrabat (exploration de l'API)</h2>
  <div class="cols"><label>Adresse de l'API <input name="xb_api" value="<?= $s('xb_api', 'https://api.extrabat.com/v1') ?>"></label><label>Clé d'API (User API Key) <?= $secret('xb_cle') ?><input name="xb_cle" type="password" autocomplete="new-password"></label></div>
  <div class="cols"><label>Envoi de la clé <select name="xb_auth"><?php foreach (['jwt' => 'Jeton JWT obtenu avec la clé (recommandé si « JWT Token not found »)', 'bearer' => 'En-tête Authorization: Bearer <clé>', 'basicbrut' => 'En-tête Authorization: Basic <clé telle quelle>', 'entete' => 'En-tête personnalisé (nom ci-contre)', 'basic' => 'Authentification Basic (clé comme mot de passe)', 'param' => 'Paramètre d\'adresse (?nom=clé)'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('xb_auth', 'bearer') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label><label>Nom de l'en-tête ou du paramètre <input name="xb_entete" value="<?= $s('xb_entete', 'X-API-KEY') ?>"></label></div>
  <div class="cols"><label>Third-Party (nom choisi à la création de la clé, facultatif) <input name="xb_third" value="<?= $s('xb_third') ?>" placeholder="ex. altagem-b5f1"></label><label>Point de connexion trouvé <input name="xb_login_chemin" value="<?= $s('xb_login_chemin') ?>" placeholder="rempli automatiquement (ex. /login_check)"></label></div>
  <label>Corps JSON de connexion (facultatif, {CLE} = la clé) <input name="xb_login_corps" value="<?= $s('xb_login_corps') ?>" placeholder='ex. {"apiKey":"{CLE}"}'></label>
  <p class="muted small">Sert à tester ce que l'API Extrabat accepte (devis, clients, agenda) avant d'automatiser la reprise des devis. Rien n'est envoyé à Extrabat en dehors du bouton « Tester ». La clé reste sur le serveur.</p>
  <button class="btn">Enregistrer</button></form>
<form method="post" class="inline" style="margin:-6px 0 8px"><?= csrf_field() ?><input type="hidden" name="action" value="xb_test"><input name="chemin" value="<?= e($_GET['chemin'] ?? '/') ?>" placeholder="/chemin à appeler (ex. /devis, /clients, /me)" style="min-width:260px"><button class="mini">Tester l'appel</button></form>
<form method="post" style="margin:-4px 0 8px"><?= csrf_field() ?><input type="hidden" name="action" value="xb_explorer"><button class="mini" title="Essaie une vingtaine de chemins courants (/me, /devis, /clients, /agenda…) et affiche la réponse de chacun">Explorer automatiquement les chemins courants</button></form>
<form method="post" style="margin:-4px 0 8px"><?= csrf_field() ?><input type="hidden" name="action" value="xb_jeton"><button class="mini" title="Essaie les points de connexion usuels (/login_check, /login, /auth, /token…) avec la clé pour obtenir un jeton JWT">Obtenir un jeton JWT avec la clé</button></form>
<form method="post" style="margin:-4px 0 8px"><?= csrf_field() ?><input type="hidden" name="action" value="xb_profond"><button class="mini" title="Essaie plusieurs adresses de base et plusieurs façons d'envoyer la clé (une à deux minutes)">Exploration approfondie (adresses + modes d'envoi de la clé)</button></form>
<?php if (setting('xb_dernier_test') !== ''): ?><pre class="card" style="white-space:pre-wrap;word-break:break-all;font-size:.78rem;max-height:420px;overflow:auto;margin-bottom:18px"><?= e(setting('xb_dernier_test')) ?></pre><?php endif; ?>
<?php page_end(); ?>
