<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'ajout') {
        $email = trim($_POST['email'] ?? ''); $mdp = $_POST['mdp'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($mdp) < 10 || trim($_POST['prenom'] ?? '') === '') flash('Prénom, e-mail valide et mot de passe de 10 caractères minimum.', 'err');
        elseif (q("SELECT id FROM utilisateurs WHERE email = ?", [$email])->fetch()) flash('Cet e-mail existe déjà.', 'err');
        else { q("INSERT INTO utilisateurs (prenom, email, mdp, role) VALUES (?,?,?,?)", [trim($_POST['prenom']), $email, password_hash($mdp, PASSWORD_DEFAULT), ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user']); flash('Compte créé.'); }
    } elseif ($a === 'mdp') {
        if (strlen($_POST['mdp'] ?? '') < 10) flash('Mot de passe trop court (10 minimum).', 'err');
        else { q("UPDATE utilisateurs SET mdp = ? WHERE id = ?", [password_hash($_POST['mdp'], PASSWORD_DEFAULT), (int)$_POST['id']]); flash('Mot de passe modifié.'); }
    } elseif ($a === 'toggle') {
        $id = (int)$_POST['id'];
        if ($id === (int)user()['id']) flash('Tu ne peux pas désactiver ton propre compte.', 'err');
        else { q("UPDATE utilisateurs SET actif = 1 - actif WHERE id = ?", [$id]); flash('Statut modifié.'); }
    }
    redirect('utilisateurs.php');
}
$users = q("SELECT * FROM utilisateurs ORDER BY cree_le")->fetchAll();
page_start('Comptes', 'utilisateurs.php');
?>
<table class="tbl">
<tr><th>Prénom</th><th>E-mail</th><th>Rôle</th><th>Statut</th><th>Nouveau mot de passe</th><th></th></tr>
<?php foreach ($users as $u): ?>
<tr>
  <td><?= e($u['prenom']) ?></td><td><?= e($u['email']) ?></td><td><?= $u['role'] === 'admin' ? 'Administrateur' : 'Utilisateur' ?></td>
  <td><?= $u['actif'] ? '<span class="ok">Actif</span>' : '<span class="off">Désactivé</span>' ?></td>
  <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="mdp"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="password" name="mdp" placeholder="10 caractères min." minlength="10"> <button class="mini">Changer</button></form></td>
  <td><?php if ($u['id'] !== user()['id']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="mini"><?= $u['actif'] ? 'Désactiver' : 'Réactiver' ?></button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table>
<section class="card">
  <h2>Ajouter un compte</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ajout">
    <div class="cols">
      <label>Prénom <input name="prenom" required></label>
      <label>E-mail <input type="email" name="email" required></label>
      <label>Mot de passe <input type="password" name="mdp" minlength="10" required></label>
      <label>Rôle <select name="role"><option value="user">Utilisateur</option><option value="admin">Administrateur</option></select></label>
    </div>
    <button class="btn" type="submit">Créer le compte</button>
  </form>
  <p class="muted">Un utilisateur peut tout faire sauf gérer les comptes et supprimer des grilles.</p>
</section>
<?php page_end(); ?>
