<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
if (user()) redirect('index.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    // Frein anti force brute : 1,5 s d'attente après 3 échecs
    $_SESSION['echecs'] = $_SESSION['echecs'] ?? 0;
    if ($_SESSION['echecs'] >= 3) usleep(1500000);
    $u = q("SELECT * FROM utilisateurs WHERE email = ? AND actif = 1", [trim($_POST['email'] ?? '')])->fetch();
    if ($u && password_verify($_POST['mdp'] ?? '', $u['mdp'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $u['id'], 'prenom' => $u['prenom'], 'email' => $u['email'], 'role' => $u['role']];
        $_SESSION['echecs'] = 0;
        redirect('index.php');
    }
    $_SESSION['echecs']++;
    $err = 'Identifiants incorrects.';
}
?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Connexion – <?= e(APP_NAME) ?></title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css"></head>
<body class="centered"><main class="box">
<img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois" class="box-logo">
<h1>Espace pro</h1>
<?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
  <label>E-mail <input type="email" name="email" required autofocus></label>
  <label>Mot de passe <input type="password" name="mdp" required></label>
  <button class="btn" type="submit">Se connecter</button>
</form>
<p class="muted"><a href="../index.html">← Retour au site</a></p>
</main></body></html>
