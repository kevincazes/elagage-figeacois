<?php
// Réglages du planning (administrateur) : entreprise (PDF), SMS, avis Google, notifications, Pennylane / GoCardless, Urgence Élagage
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_admin();
planning_migrate();
const SECRETS = ['ovh_app_secret', 'ovh_consumer_key', 'pl_token', 'urgence_cle'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    $champs = ['entreprise' => ['ent_nom', 'ent_tel', 'ent_email', 'ent_adresse', 'ent_mentions'],
               'sms' => ['ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key', 'ovh_sms_service', 'sms_expediteur'],
               'avis' => ['avis_lien', 'avis_delai', 'avis_canal'],
               'pennylane' => ['pl_token', 'pl_echeance', 'pl_tva'],
               'urgence' => ['urgence_api', 'urgence_cle']][$a] ?? [];
    foreach ($champs as $k) { if (!isset($_POST[$k])) continue; $v = trim((string)$_POST[$k]); if (in_array($k, SECRETS, true) && $v === '') continue; set_setting($k, $v); }
    $cases = ['sms' => ['sms_actif', 'sms_proposition', 'sms_rappel', 'sms_avis', 'sms_contrat'], 'avis' => ['avis_actif'], 'notifications' => ['push_actif'], 'pennylane' => ['pl_mandat_auto']][$a] ?? [];
    foreach ($cases as $k) set_setting($k, !empty($_POST[$k]) ? '1' : '0');
    if (!empty($_POST['effacer'])) foreach ((array)$_POST['effacer'] as $k) if (in_array($k, SECRETS, true)) set_setting($k, '');
    if ($a === 'sms_test') { $m = sms_envoyer((string)($_POST['tel'] ?? ''), setting('ent_nom') . ' : SMS de test de l\'espace pro.', null, 'test'); flash($m === '' ? 'SMS de test envoyé.' : "Échec : $m", $m === '' ? 'ok' : 'err'); }
    if ($a === 'pl_test') { [$c, $j, $t] = pl_api('GET', '/customers', null, ['limit' => 1]); flash($c === 200 ? 'Pennylane répond : connexion OK.' : "Pennylane refuse (HTTP $c) : " . pl_erreur($j, $t), $c === 200 ? 'ok' : 'err'); }
    if ($a === 'urgence_test') { $n = urgence_sync(true); flash(setting('urgence_cle') === '' ? 'Renseigne la clé.' : "Urgence Élagage : " . setting('urgence_etat') . " – $n intervention(s) reprise(s).", str_starts_with(setting('urgence_etat'), 'OK') ? 'ok' : 'err'); }
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
<p><a class="mini" href="#entreprise">Entreprise</a> <a class="mini" href="#sms">SMS</a> <a class="mini" href="#avis">Avis Google</a> <a class="mini" href="#notifications">Notifications</a> <a class="mini" href="#pennylane">Pennylane / GoCardless</a> <a class="mini" href="#urgence">Urgence Élagage</a></p>

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
  <p class="muted small">Chaque salarié les active sur son téléphone depuis « Mon planning » ou « Mes notifications » (bouton 🔔). Sur iPhone, l'espace pro doit d'abord être ajouté à l'écran d'accueil.</p>
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
<?php page_end(); ?>
