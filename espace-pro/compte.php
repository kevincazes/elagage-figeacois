<?php
// Fiche d'un compte : la sienne (Mon compte) ou celle d'un autre (administrateur, via ?id=)
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$moi = user();
$id = isset($_GET['id']) && $moi['role'] === 'admin' ? (int)$_GET['id'] : (int)$moi['id'];
$cible = $id === (int)$moi['id'];
$u = q("SELECT * FROM utilisateurs WHERE id = ?", [$id])->fetch();
if (!$u) { flash('Compte introuvable.', 'err'); redirect('utilisateurs.php'); }
$retour = $cible ? 'compte.php' : 'compte.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'infos') {
        $prenom = trim($_POST['prenom'] ?? ''); $nom = trim($_POST['nom'] ?? ''); $email = trim($_POST['email'] ?? '');
        if ($prenom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Prénom et e-mail valide sont obligatoires.', 'err');
        elseif (q("SELECT id FROM utilisateurs WHERE email = ? AND id <> ?", [$email, $id])->fetch()) flash('Cet e-mail est déjà utilisé par un autre compte.', 'err');
        else {
            q("UPDATE utilisateurs SET prenom = ?, nom = ?, email = ? WHERE id = ?", [$prenom, $nom, $email, $id]);
            if ($cible) { $_SESSION['user']['prenom'] = $prenom; $_SESSION['user']['email'] = $email; }
            flash('Informations enregistrées.');
        }
    } elseif ($a === 'mdp' && $cible) {
        if (!password_verify($_POST['ancien'] ?? '', $u['mdp'])) flash('Mot de passe actuel incorrect.', 'err');
        elseif (strlen($_POST['nouveau'] ?? '') < 10) flash('Le nouveau mot de passe doit faire au moins 10 caractères.', 'err');
        elseif (($_POST['nouveau'] ?? '') !== ($_POST['nouveau2'] ?? '')) flash('Les deux nouveaux mots de passe ne sont pas identiques.', 'err');
        else { q("UPDATE utilisateurs SET mdp = ? WHERE id = ?", [password_hash($_POST['nouveau'], PASSWORD_DEFAULT), $id]); flash('Mot de passe modifié.'); }
    }
    redirect($retour);
}
page_start($cible ? 'Mon compte' : 'Compte de ' . $u['prenom'] . ' ' . $u['nom'], $cible ? 'compte.php' : 'utilisateurs.php', $cible ? null : ['utilisateurs.php', 'Comptes']);
?>
<div class="cols">
<section class="card">
  <h2>Informations</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="infos">
    <label>Prénom <input name="prenom" value="<?= e($u['prenom']) ?>" required></label>
    <label>Nom <input name="nom" value="<?= e($u['nom']) ?>"></label>
    <label>E-mail (identifiant de connexion) <input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
    <p class="muted">Rôle : <?= e(role_libelle($u['role'])) ?><?= $cible ? '' : ' (modifiable depuis la liste des comptes)' ?> · Double authentification : <?= ['none' => 'à configurer', 'totp' => 'application', 'email' => 'code par e-mail'][$u['mfa_type']] ?><?= $cible ? ' – <a href="securite.php">changer</a>' : '' ?></p>
    <button class="btn" type="submit">Enregistrer</button>
    <?php if (!$cible): ?><a class="btn secondary" href="utilisateurs.php">← Retour aux comptes</a><?php endif; ?>
  </form>
</section>
<?php if ($cible): ?>
<section class="card">
  <h2>Changer mon mot de passe</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mdp">
    <label>Mot de passe actuel <input type="password" name="ancien" required autocomplete="current-password"></label>
    <label>Nouveau mot de passe (10 caractères minimum) <input type="password" name="nouveau" minlength="10" required autocomplete="new-password"></label>
    <label>Confirmer le nouveau mot de passe <input type="password" name="nouveau2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn" type="submit">Modifier le mot de passe</button>
  </form>
</section>
<?php else: ?>
<section class="card">
  <h2>Mot de passe</h2>
  <p class="muted">Par sécurité, un administrateur ne saisit jamais le mot de passe d'un autre compte. Pour aider cette personne, envoie-lui un lien de réinitialisation depuis la liste des comptes.</p>
</section>
<?php endif; ?>
</div>
<?php page_end(); ?>
