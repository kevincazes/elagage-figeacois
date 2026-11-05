<?php
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_admin();
planning_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'inviter') {
        $prenom = trim($_POST['prenom'] ?? ''); $nom = trim($_POST['nom'] ?? ''); $email = trim($_POST['email'] ?? '');
        $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'user';
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
        else { q("UPDATE utilisateurs SET role = ? WHERE id = ?", [isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'user', $id]); flash('Rôle modifié.'); }
    } elseif ($a === 'planning') {
        $id = (int)$_POST['id']; $d = array_key_exists($_POST['planning_droit'] ?? '', DROITS_PLANNING) ? $_POST['planning_droit'] : 'lecture';
        q("UPDATE utilisateurs SET planning_droit = ? WHERE id = ?", [$d, $id]);
        q("DELETE FROM droits_agenda WHERE utilisateur_id = ?", [$id]);
        foreach ((array)($_POST['agendas'] ?? []) as $p) q("INSERT IGNORE INTO droits_agenda (utilisateur_id, personnel_id) VALUES (?,?)", [$id, (int)$p]);
        flash('Droits sur le planning enregistrés.');
    } elseif ($a === 'mfa_reset') {
        q("UPDATE utilisateurs SET mfa_type = 'none', mfa_secret = NULL WHERE id = ?", [(int)$_POST['id']]);
        flash('Double authentification réinitialisée : la personne choisira une nouvelle méthode à sa prochaine connexion.');
    } elseif ($a === 'toggle') {
        $id = (int)$_POST['id'];
        if ($id === (int)user()['id']) flash('Tu ne peux pas désactiver ton propre compte.', 'err');
        else { q("UPDATE utilisateurs SET actif = 1 - actif WHERE id = ?", [$id]); flash('Statut modifié.'); }
    }
    redirect('utilisateurs.php');
}
$users = q("SELECT * FROM utilisateurs ORDER BY nom, prenom")->fetchAll();
$persPl = personnel_liste();
$deleg = []; foreach (q("SELECT * FROM droits_agenda") as $x) $deleg[(int)$x['utilisateur_id']][] = (int)$x['personnel_id'];
$fiche = []; foreach (q("SELECT id, prenom, utilisateur_id FROM personnel WHERE utilisateur_id IS NOT NULL") as $x) $fiche[(int)$x['utilisateur_id']] = $x['prenom'];
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
      <label>Rôle <select name="role"><option value="user">Utilisateur</option><option value="secretaire">Secrétaire</option><option value="admin">Administrateur</option></select></label>
    </div>
    <button class="btn" type="submit">Créer le compte et envoyer le lien</button>
  </form>
  <p class="muted">La personne reçoit un e-mail avec un lien pour choisir elle-même son mot de passe.</p>
  <table class="tbl" style="max-width:760px"><tr><th>Rôle</th><th>Accès</th></tr>
  <tr><td><strong>Administrateur</strong></td><td>Tout : calculateur, Paramètres (taux, coefficient, TVA, ressources, liens), comptes, suppression de bordereaux, forçage d'un verrou.</td></tr>
  <tr><td><strong>Secrétaire</strong></td><td>Tout le calculateur (bordereaux, import, export, catalogue, actions, packs, historique, assistant, guide), sans Paramètres ni comptes.</td></tr>
  <tr><td><strong>Utilisateur</strong></td><td>Tableau de bord (liens Drive, Extrabat, Urgence Élagage, Arbogestion), planning et son compte. Pas d'accès aux prix.</td></tr></table>
  <p class="muted"><strong>Planning :</strong> tout le monde consulte les agendas de toute l'équipe. Administrateurs et secrétariat modifient tout. Pour un utilisateur, la colonne « Planning » règle ce qu'il peut modifier : rien, son propre agenda (sa fiche du personnel doit être reliée à son compte), ou tous les agendas ; tu peux aussi lui confier l'agenda de personnes précises (ex. le chef d'équipe gère celui de son équipe).</p>
</section>

<h2>Comptes existants</h2>
<div class="scroll"><table class="tbl">
<tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Planning</th><th>Statut</th><th>2FA</th><th>Mot de passe</th><th></th></tr>
<?php foreach ($users as $u): $moi = (int)$u['id'] === (int)user()['id']; $enAttente = $u['token'] && strtotime($u['token_expire']) > time(); ?>
<tr>
  <td><a href="compte.php<?= $moi ? '' : '?id=' . $u['id'] ?>"><strong><?= e($u['prenom'] . ' ' . $u['nom']) ?></strong></a><?= $moi ? ' <span class="muted">(toi)</span>' : '' ?><br><a class="mini" href="compte.php<?= $moi ? '' : '?id=' . $u['id'] ?>">Modifier</a></td>
  <td><?= e($u['email']) ?></td>
  <td><?php if ($moi): ?><?= e(role_libelle($u['role'])) ?><?php else: ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= $u['id'] ?>">
    <select name="role" onchange="this.form.submit()"><?php foreach (ROLES as $k => $lib): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= $lib ?></option><?php endforeach; ?></select></form><?php endif; ?></td>
  <td><?php if (in_array($u['role'], ['admin', 'secretaire'], true)): ?><span class="muted small">Modifie tous les agendas</span><?php else: ?>
    <form method="post" class="inline" style="display:block"><?= csrf_field() ?><input type="hidden" name="action" value="planning"><input type="hidden" name="id" value="<?= $u['id'] ?>">
    <select name="planning_droit"><?php foreach (DROITS_PLANNING as $k => $lib): ?><option value="<?= $k ?>" <?= ($u['planning_droit'] ?? 'lecture') === $k ? 'selected' : '' ?>><?= e($lib) ?></option><?php endforeach; ?></select>
    <details style="margin-top:4px"><summary class="small" style="cursor:pointer">Agendas confiés<?= !empty($deleg[(int)$u['id']]) ? ' (' . count($deleg[(int)$u['id']]) . ')' : '' ?></summary>
    <?php foreach ($persPl as $p): ?><label class="small" style="display:block;font-weight:400;margin:2px 0"><input type="checkbox" style="width:auto" name="agendas[]" value="<?= $p['id'] ?>" <?= in_array((int)$p['id'], $deleg[(int)$u['id']] ?? [], true) ? 'checked' : '' ?>> <?= e($p['prenom'] . ' ' . $p['nom']) ?></label><?php endforeach; ?><?= !$persPl ? '<span class="muted small">Aucune personne au planning.</span>' : '' ?></details>
    <button class="mini" type="submit">Enregistrer</button></form>
    <span class="muted small"><?= isset($fiche[(int)$u['id']]) ? 'Fiche planning : ' . e($fiche[(int)$u['id']]) : 'Pas de fiche planning reliée' ?></span><?php endif; ?></td>
  <td><?= $u['actif'] ? '<span class="ok">Actif</span>' : '<span class="off">Désactivé</span>' ?><?= $enAttente ? '<br><span class="muted">lien en attente</span>' : '' ?></td>
  <td><?= ['none' => '<span class="off">à configurer</span>', 'totp' => 'Application', 'email' => 'E-mail'][$u['mfa_type']] ?>
    <?php if ($u['mfa_type'] !== 'none' && !$moi): ?><form method="post" class="inline" onsubmit="return confirm('Réinitialiser la double authentification de cette personne ?')"><?= csrf_field() ?><input type="hidden" name="action" value="mfa_reset"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="mini" type="submit">Réinitialiser</button></form><?php endif; ?></td>
  <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="renvoyer"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="mini" type="submit">Envoyer un lien de (ré)initialisation</button></form></td>
  <td><?php if (!$moi): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="mini <?= $u['actif'] ? 'danger' : '' ?>" type="submit"><?= $u['actif'] ? 'Désactiver' : 'Réactiver' ?></button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table></div>
<?php page_end(); ?>
