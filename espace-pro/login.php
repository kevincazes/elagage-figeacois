<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
if (user()) redirect('index.php');
migrate();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    frein();
    $u = q("SELECT * FROM utilisateurs WHERE email = ? AND actif = 1", [trim($_POST['email'] ?? '')])->fetch();
    if ($u && password_verify($_POST['mdp'] ?? '', $u['mdp'])) {
        session_regenerate_id(true);
        $_SESSION['pending'] = $u['id'];
        $_SESSION['souvenir'] = !empty($_POST['souvenir']);
        // Double authentification obligatoire : à configurer si absente, sinon à vérifier
        redirect($u['mfa_type'] === 'none' ? 'securite.php' : 'verifier.php');
    }
    $err = 'Identifiants incorrects.';
}
page_simple_start('Connexion');
?>
<h1>Espace pro</h1>
<?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
  <label>E-mail <input type="email" name="email" required autofocus></label>
  <label>Mot de passe <input type="password" name="mdp" required></label>
  <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="souvenir" value="1" checked style="width:auto;margin:0"> Rester connecté sur cet appareil (60 jours, renouvelés à chaque visite)</label>
  <button class="btn" type="submit">Se connecter</button>
</form>
<p class="muted"><a href="mdp-oublie.php">Mot de passe oublié ?</a> · <a href="../index.html">Retour au site</a></p>
<?php page_simple_end(); ?>
