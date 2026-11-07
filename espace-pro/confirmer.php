<?php
// Page publique : le client confirme la date proposée ou en demande une autre (lien reçu par e-mail)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
planning_migrer_tout();
header('X-Robots-Tag: noindex');
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? $_POST['t'] ?? ''));
$e = strlen($t) === 32 ? (q("SELECT id FROM evenements WHERE token = ?", [$t])->fetch() ?: null) : null;
$e = $e ? evt_charger((int)$e['id']) : null;
page_simple_start('Votre rendez-vous');
if (!$e || $e['statut'] === 'annule') { echo '<h1>Lien expiré</h1><p>Ce rendez-vous n\'est plus d\'actualité. Pour toute question, contactez-nous' . (defined('APP_EMAIL') ? ' : <a href="mailto:' . e(APP_EMAIL) . '">' . e(APP_EMAIL) . '</a>' : '') . '.</p>'; page_simple_end(); exit; }
$lieu = trim($e['adresse'] . ', ' . $e['cp'] . ' ' . $e['ville'], ' ,');
$passe = $e['debut'] && strtotime($e['debut']) < time();
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$passe && $e['debut']) {
    $rep = $_POST['reponse'] ?? ''; $txt = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 1000);
    $de = defined('APP_EMAIL') ? APP_EMAIL : '';
    if ($rep === 'confirme') {
        q("UPDATE evenements SET statut = 'confirme', client_reponse = 'confirme', client_message = ?, client_repondu_le = NOW() WHERE id = ?", [$txt, $e['id']]);
        evt_journal((int)$e['id'], 'Le client a confirmé la date' . ($txt ? " : « $txt »" : ''));
        if ($de) envoyer_mail($de, "✓ Date confirmée par le client – {$e['titre']}", "{$e['client_nom']} a confirmé : {$e['titre']} " . evt_quand($e) . ".\n" . ($txt ? "Message : $txt\n" : '') . "\nPlanning : " . lien_base() . "/planning.php?fiche={$e['id']}");
        $msg = 'ok';
    } elseif ($rep === 'autre') {
        if ($txt === '') $msg = 'vide';
        else {
            q("UPDATE evenements SET client_reponse = 'autre', client_message = ?, client_repondu_le = NOW() WHERE id = ?", [$txt, $e['id']]);
            evt_journal((int)$e['id'], "Le client demande une autre date : « $txt »");
            if ($de) envoyer_mail($de, "⚠ Le client demande une autre date – {$e['titre']}", "{$e['client_nom']} ne peut pas " . evt_quand($e) . ".\nSon message : $txt\n" . ($e['client_tel'] ? "Téléphone : {$e['client_tel']}\n" : '') . "\nPlanning : " . lien_base() . "/planning.php?fiche={$e['id']}");
            $msg = 'autre';
        }
    }
    $e = evt_charger((int)$e['id']);
}
?>
<h1><?= e(TYPES_EVT[$e['type']][0] ?? 'Rendez-vous') ?></h1>
<p>Bonjour<?= $e['client_nom'] ? ' ' . e($e['client_nom']) : '' ?>,</p>
<p style="font-size:1.15rem">Nous vous proposons <?= e(evt_libelle_client($e)) ?> <strong><?= e(evt_quand($e)) ?></strong><?= $lieu ? '<br><span class="muted">' . e($lieu) . '</span>' : '' ?>.</p>
<?php if ($msg === 'ok' || ($msg === '' && $e['client_reponse'] === 'confirme')): ?>
  <div class="flash ok">Merci, la date est confirmée. Vous recevrez un rappel la veille.</div>
<?php elseif ($msg === 'autre' || ($msg === '' && in_array($e['client_reponse'], ['autre', 'vu'], true))): ?>
  <div class="flash">Merci, nous avons bien reçu votre demande. Nous revenons vers vous rapidement avec une nouvelle date.</div>
<?php elseif ($passe): ?>
  <p class="muted">Cette date est passée.</p>
<?php elseif (!$e['debut']): ?>
  <p class="muted">La date est en cours de définition, nous revenons vers vous.</p>
<?php else: ?>
  <?php if ($msg === 'vide'): ?><div class="flash err">Indiquez-nous vos disponibilités pour que nous puissions vous proposer une autre date.</div><?php endif; ?>
  <form method="post"><input type="hidden" name="t" value="<?= e($t) ?>">
    <p><button class="btn" name="reponse" value="confirme" style="width:100%;font-size:1.05rem">✓ Je confirme cette date</button></p>
    <details <?= $msg === 'vide' ? 'open' : '' ?>><summary style="cursor:pointer;font-weight:700">Cette date ne me convient pas</summary>
      <label>Vos disponibilités (jours, matin / après-midi…)<textarea name="message" rows="3" placeholder="Ex. plutôt la semaine suivante, le matin"></textarea></label>
      <p><button class="btn secondary" name="reponse" value="autre" style="width:100%">Demander une autre date</button></p>
    </details>
  </form>
<?php endif; ?>
<?php if ($e['type'] === 'chantier'): ?><p class="muted small">Le jour de l'intervention, merci de veiller à ce que l'accès soit dégagé (véhicules déplacés, portail ouvert).</p><?php endif; ?>
<p class="muted small">Élagage Figeacois<?= defined('APP_EMAIL') ? ' · ' . e(APP_EMAIL) : '' ?></p>
<?php page_simple_end(); ?>
