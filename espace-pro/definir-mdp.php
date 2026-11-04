<?php
// Page de choix du mot de passe (invitation ou mot de passe oublié), accessible via le lien reçu par e-mail
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
migrate();
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$u = $token ? q("SELECT * FROM utilisateurs WHERE token = ? AND token_expire > NOW() AND actif = 1", [$token])->fetch() : null;
$err = ''; $ok = false;
if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $mdp = $_POST['mdp'] ?? ''; $mdp2 = $_POST['mdp2'] ?? '';
    if (strlen($mdp) < 10) $err = 'Le mot de passe doit faire au moins 10 caractères.';
    elseif ($mdp !== $mdp2) $err = 'Les deux mots de passe ne sont pas identiques.';
    else {
        q("UPDATE utilisateurs SET mdp = ?, token = NULL, token_expire = NULL WHERE id = ?", [password_hash($mdp, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        $_SESSION['pending'] = $u['id']; $_SESSION['souvenir'] = true;
        redirect($u['mfa_type'] === 'none' ? 'securite.php' : 'verifier.php');
    }
}
?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Choisir un mot de passe – <?= e(APP_NAME) ?></title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css?v=<?= e(app_version()) ?>"></head>
<body class="centered"><main class="box">
<img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois" class="box-logo">
<?php if (!$u): ?>
  <h1>Lien invalide ou expiré</h1>
  <p class="muted">Ce lien n'est plus valable (il dure 48 heures). Demandez-en un nouveau à un administrateur, ou utilisez « Mot de passe oublié » sur la page de connexion.</p>
  <a class="btn" href="login.php">Page de connexion</a>
<?php else: ?>
  <h1>Bonjour <?= e($u['prenom']) ?></h1>
  <p class="muted">Choisis ton mot de passe pour l'identifiant <strong><?= e($u['email']) ?></strong>.</p>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <label>Mot de passe (10 caractères minimum) <input type="password" name="mdp" minlength="10" required autofocus></label>
    <label>Confirme le mot de passe <input type="password" name="mdp2" minlength="10" required></label>
    <button class="btn" type="submit">Enregistrer et me connecter</button>
  </form>
<?php endif; ?>
</main></body></html>
