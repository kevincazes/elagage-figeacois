<?php
// Activation / changement de la double authentification.
// Accessible soit pendant la connexion (obligatoire si aucune méthode), soit depuis le menu une fois connecté.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
migrate();
$connecte = user() !== null;
$u = $connecte ? q("SELECT * FROM utilisateurs WHERE id = ?", [user()['id']])->fetch() : pending_user();
if (!$u) redirect('login.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') check_csrf();
$err = ''; $etape = $_POST['etape'] ?? 'choix';
$methode = $_POST['methode'] ?? '';

if ($etape === 'totp_start') {
    $_SESSION['totp_tmp'] = base32_encode(random_bytes(20)); $etape = 'totp';
} elseif ($etape === 'totp_confirm') {
    frein();
    if (!empty($_SESSION['totp_tmp']) && totp_verify($_SESSION['totp_tmp'], $_POST['code'] ?? '')) {
        q("UPDATE utilisateurs SET mfa_type = 'totp', mfa_secret = ? WHERE id = ?", [$_SESSION['totp_tmp'], $u['id']]);
        unset($_SESSION['totp_tmp']);
        if ($connecte) { flash('Application d\'authentification activée.'); redirect('index.php'); }
        connecter($u);
    }
    $err = 'Code incorrect. Vérifie que l\'heure de ton téléphone est juste et réessaie.'; $etape = 'totp';
} elseif ($etape === 'email_start') {
    envoyer_code_email($u); $etape = 'email';
} elseif ($etape === 'email_confirm') {
    frein();
    if (verifier_code_email($u, $_POST['code'] ?? '')) {
        q("UPDATE utilisateurs SET mfa_type = 'email', mfa_secret = NULL WHERE id = ?", [$u['id']]);
        if ($connecte) { flash('Code par e-mail activé.'); redirect('index.php'); }
        connecter($u);
    }
    $err = 'Code incorrect ou expiré.'; $etape = 'email';
}
$otpauth = '';
if ($etape === 'totp') $otpauth = 'otpauth://totp/' . rawurlencode('Élagage Figeacois:' . $u['email']) . '?secret=' . $_SESSION['totp_tmp'] . '&issuer=' . rawurlencode('Élagage Figeacois') . '&digits=6&period=30';

if ($connecte) page_start('Sécurité', 'securite.php', ['compte.php', 'Mon compte']); else { page_simple_start('Sécurité'); echo '<h1>Sécuriser votre compte</h1>'; }
if ($err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<?php if ($etape === 'choix'): ?>
  <?php if (!$connecte): ?><p class="muted">Dernière étape : choisissez comment recevoir votre code de vérification à chaque connexion.</p>
  <?php else: ?><p class="lead">Méthode actuelle : <strong><?= ['none' => 'aucune', 'totp' => 'application d\'authentification', 'email' => 'code par e-mail'][$u['mfa_type']] ?></strong>. Tu peux en changer ci-dessous.</p><?php endif; ?>
  <div class="tiles">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="etape" value="totp_start">
      <button class="tile" type="submit" style="width:100%;text-align:left;cursor:pointer;border:0;border-left:5px solid var(--anis);font:inherit"><span>Application d'authentification</span><em>QR code à scanner avec Google Authenticator, 1Password, Bitwarden, Apple Mots de passe… Recommandé.</em></button>
    </form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="etape" value="email_start">
      <button class="tile" type="submit" style="width:100%;text-align:left;cursor:pointer;border:0;border-left:5px solid var(--sapin);font:inherit"><span>Code par e-mail</span><em>Un code à 6 chiffres envoyé à <?= e($u['email']) ?> à chaque connexion.</em></button>
    </form>
  </div>
<?php elseif ($etape === 'totp'): ?>
  <h2>1. Scannez ce QR code</h2>
  <div id="qr" style="background:#fff;padding:12px;display:inline-block;border-radius:12px"></div>
  <p class="muted">Ou saisissez cette clé à la main : <code style="word-break:break-all"><?= e(chunk_split($_SESSION['totp_tmp'], 4, ' ')) ?></code></p>
  <h2>2. Entrez le code affiché</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="etape" value="totp_confirm">
    <label>Code à 6 chiffres <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus style="font-size:1.4rem;letter-spacing:.3em;text-align:center;max-width:220px"></label>
    <button class="btn" type="submit">Activer</button> <a class="btn secondary" href="securite.php">Retour</a>
  </form>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>new QRCode(document.getElementById('qr'), {text: <?= json_encode($otpauth) ?>, width: 200, height: 200});</script>
<?php elseif ($etape === 'email'): ?>
  <p class="muted">Un code vient d'être envoyé à <strong><?= e($u['email']) ?></strong>. Saisissez-le pour confirmer.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="etape" value="email_confirm">
    <label>Code à 6 chiffres <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus style="font-size:1.4rem;letter-spacing:.3em;text-align:center;max-width:220px"></label>
    <button class="btn" type="submit">Activer</button> <a class="btn secondary" href="securite.php">Retour</a>
  </form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="etape" value="email_start"><button class="mini" type="submit">Renvoyer un code</button></form>
<?php endif; ?>
<?php if ($connecte) page_end(); else { echo '<p class="muted" style="text-align:center"><a href="logout.php">Annuler</a></p>'; page_simple_end(); } ?>
