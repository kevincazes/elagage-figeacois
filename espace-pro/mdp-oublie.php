<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
migrate();
$envoye = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $u = q("SELECT * FROM utilisateurs WHERE email = ? AND actif = 1", [trim($_POST['email'] ?? '')])->fetch();
    if ($u) envoyer_lien($u, 'reinit');
    $envoye = true; // même message dans tous les cas, pour ne pas révéler quels e-mails existent
}
?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Mot de passe oublié – <?= e(APP_NAME) ?></title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css?v=<?= e(app_version()) ?>"></head>
<body class="centered"><main class="box">
<img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois" class="box-logo">
<h1>Mot de passe oublié</h1>
<?php if ($envoye): ?>
  <div class="flash ok">Si cette adresse correspond à un compte, un lien vient d'être envoyé. Pensez à vérifier les indésirables.</div>
  <a class="btn" href="login.php">Retour à la connexion</a>
<?php else: ?>
  <form method="post"><?= csrf_field() ?>
    <label>Ton e-mail <input type="email" name="email" required autofocus></label>
    <button class="btn" type="submit">Recevoir un lien</button>
  </form>
  <p class="muted"><a href="login.php">← Retour à la connexion</a></p>
<?php endif; ?>
</main></body></html>
