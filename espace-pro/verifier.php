<?php
// 2e étape de connexion : code de l'application ou code reçu par e-mail
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
if (user()) redirect('index.php');
$u = pending_user();
if (!$u) redirect('login.php');
if ($u['mfa_type'] === 'none') redirect('securite.php');
$err = '';
// Envoi automatique du code e-mail à l'arrivée sur la page (une fois), ou sur demande
if ($u['mfa_type'] === 'email' && ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['code_envoye']) || isset($_POST['renvoyer']))) {
    envoyer_code_email($u); $_SESSION['code_envoye'] = time();
    if (isset($_POST['renvoyer'])) { check_csrf(); flash('Nouveau code envoyé.'); redirect('verifier.php'); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['renvoyer'])) {
    check_csrf(); frein();
    $code = $_POST['code'] ?? '';
    $ok = $u['mfa_type'] === 'totp' ? totp_verify($u['mfa_secret'], $code) : verifier_code_email($u, $code);
    if ($ok) { unset($_SESSION['code_envoye']); connecter($u); }
    $err = 'Code incorrect ou expiré.';
}
page_simple_start('Vérification');
?>
<h1>Bonjour <?= e($u['prenom']) ?></h1>
<?php if (!empty($_SESSION['flash'])) { [$m, $t] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="flash ' . e($t) . '">' . e($m) . '</div>'; } ?>
<?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
<?php if ($u['mfa_type'] === 'totp'): ?>
  <p class="muted">Saisis le code à 6 chiffres affiché par ton application d'authentification.</p>
<?php else: ?>
  <p class="muted">Un code à 6 chiffres vient d'être envoyé à <strong><?= e($u['email']) ?></strong> (valable 10 minutes).</p>
<?php endif; ?>
<form method="post"><?= csrf_field() ?>
  <label>Code <input name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" maxlength="7" required autofocus style="font-size:1.6rem;letter-spacing:.3em;text-align:center"></label>
  <button class="btn" type="submit">Valider</button>
</form>
<?php if ($u['mfa_type'] === 'email'): ?>
<form method="post" style="text-align:center"><?= csrf_field() ?><button class="mini" name="renvoyer" value="1">Renvoyer un code</button></form>
<?php endif; ?>
<p class="muted" style="text-align:center"><a href="logout.php">Annuler</a></p>
<?php page_simple_end(); ?>
