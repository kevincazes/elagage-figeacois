<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'inviter') {
        $prenom = trim($_POST['prenom'] ?? ''); $nom = trim($_POST['nom'] ?? ''); $email = trim($_POST['email'] ?? '');
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($prenom === '' || $nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Prénom, nom et e-mail valide sont obligatoires.', 'err');
        elseif (q("SELECT id FROM utilisateurs WHERE email = ?", [$email])->fetch()) flash('Cet e-mail existe déjà.', 'err');
        else {
            // Mot de passe provisoire impossible à deviner : la personne en choisira un via le lien
            q("INSERT INTO utilisateurs (prenom, nom, email, mdp, role) VALUES (?,?,?,?,?)", [$prenom, $nom, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $role]);
            $u = q("SELECT * FROM utilisateurs WHERE email = ?", [$email])->fetch();
            $lien = envoyer_lien($u, 'invitation');
            $_SESSION['dernier_lien'] = $lien;
            flash("Compte créé et invitation envoyée à $email.");
        }
    } elseif ($a === 'renvoyer') {
        $u = q("SELECT * FROM utilisateurs WHERE id = ?", [(int)$_POST['id']])->fetch();
        if ($u) { $_SESSION['dernier_lien'] = envoyer_lien($u, 'invitation'); flash("Nouveau lien envoyé à {$u['email']}."); }
    } elseif ($a === 'role') {
        $id = (int)$_POST['id'];
        if ($id === (int)user()['id']) flash('Tu ne peux pas changer ton propre rôle.', 'err');
        else { q("UPDATE utilisateurs SET role = ? WHERE id = ?", [($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user', $id]); flash('Rôle modifié.'); }
    } elseif ($a === 'toggle') {
        $id = (int)$_POST['id'];
        if ($id === (int)user()['id']) flash('Tu ne peux pas désactiver ton propre compte.', 'err');
        else { q("UPDATE utilisateurs SET actif = 1 - actif WHERE id = ?", [$id]); flash('Statut modifié.'); }
    }
    redirect('utilisateurs.php');
}
$users = q("SELECT * FROM utilisateurs ORDER BY nom, prenom")->fetchAll();
$dernierLien = $_SESSION['dernier_lien'] ?? null; unset($_SESSION['dernier_lien']);
page_start('Comptes', 'utilisateurs.php');
?>
<?php if ($dernierLien): ?>
<div class="card"><strong>Lien d'invitation</strong> (valable 48 h). Il a été envoyé par e-mail ; si le message n'arrive pas, tu peux le transmettre toi-même :<br>
<input id="lien" value="<?= e($dernierLien) ?>" readonly onclick="this.select()"> <button class="mini" type="button" onclick="navigator.clipboard.writeText(document.getElementById('lien').value).then(()=>this.textContent='Copié !')">Copier</button></div>
<?php endif; ?>

<section class="card">
  <h2>Inviter une personne</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="inviter">
    <div class="cols">
      <label>Prénom <input name="prenom" required></label>
      <label>Nom <input name="nom" required></label>
      <label>E-mail (servira d'identifiant) <input type="email" name="email" required></label>
      <label>Rôle <select name="role"><option value="user">Utilisateur</option><option value="admin">Administrateur</option></select></label>
    </div>
    <button class="btn" type="submit">Créer le compte et envoyer le lien</button>
  </form>
  <p class="muted">La personne reçoit un e-mail avec un lien pour choisir elle-même son mot de passe. Un utilisateur peut tout faire sauf gérer les comptes et supprimer des grilles.</p>
</section>

<h2>Comptes existants</h2>
<div class="scroll"><table class="tbl">
<tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Statut</th><th>Mot de passe</th><th></th></tr>
<?php foreach ($users as $u): $moi = (int)$u['id'] === (int)user()['id']; $enAttente = $u['token'] && strtotime($u['token_expire']) > time(); ?>
<tr>
  <td><strong><?= e($u['prenom'] . ' ' . $u['nom']) ?></strong><?= $moi ? ' <span class="muted">(toi)</span>' : '' ?></td>
  <td><?= e($u['email']) ?></td>
  <td><?php if ($moi): ?><?= $u['role'] === 'admin' ? 'Administrateur' : 'Utilisateur' ?><?php else: ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= $u['id'] ?>">
    <select name="role" onchange="this.form.submit()"><option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>Utilisateur</option><option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrateur</option></select></form><?php endif; ?></td>
  <td><?= $u['actif'] ? '<span class="ok">Actif</span>' : '<span class="off">Désactivé</span>' ?><?= $enAttente ? '<br><span class="muted">lien en attente</span>' : '' ?></td>
  <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="renvoyer"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="mini" type="submit">Envoyer un lien de (ré)initialisation</button></form></td>
  <td><?php if (!$moi): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="mini <?= $u['actif'] ? 'danger' : '' ?>" type="submit"><?= $u['actif'] ? 'Désactiver' : 'Réactiver' ?></button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table></div>
<?php page_end(); ?>
