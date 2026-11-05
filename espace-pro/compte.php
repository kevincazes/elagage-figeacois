<?php
// Fiche d'un compte : la sienne (Mon compte) ou celle d'un autre (administrateur, via ?id=)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_login(); require_planning();
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
        if ($cible) $email = $u['email']; // l'identifiant de connexion ne se change pas soi-même (un administrateur peut le corriger depuis Comptes)
        if ($prenom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Prénom et e-mail valide sont obligatoires.', 'err');
        elseif (q("SELECT id FROM utilisateurs WHERE email = ? AND id <> ?", [$email, $id])->fetch()) flash('Cet e-mail est déjà utilisé par un autre compte.', 'err');
        else {
            q("UPDATE utilisateurs SET prenom = ?, nom = ?, email = ? WHERE id = ?", [$prenom, $nom, $email, $id]);
            if ($cible) { $_SESSION['user']['prenom'] = $prenom; $_SESSION['user']['email'] = $email; }
            flash('Informations enregistrées.');
        }
    } elseif ($a === 'agenda') {
        $mp = q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1", [$id])->fetchAll(); $m = agendas_post($moi['role'] === 'admin' ? null : array_map(fn($x) => (int)$x['id'], $mp));
        if ($m !== null) flash($m, str_contains($m, 'impossible') || str_contains($m, 'invalide') || str_contains($m, 'peux pas') ? 'err' : 'ok');
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
    <?php if ($cible && q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1", [$id])->fetch()): ?><p style="margin:0 0 8px"><a class="mini" href="livret.php">📘 Mon livret (titres, habilitations, formations)</a></p><?php endif; ?>
    <?php if ($cible): ?><p style="margin:6px 0 10px"><span class="small muted">E-mail (identifiant de connexion)</span><br><b><?= e($u['email']) ?></b> <span class="small muted">– ne se modifie pas ici : demande à un administrateur si besoin.</span></p>
    <?php else: ?><label>E-mail (identifiant de connexion) <input type="email" name="email" value="<?= e($u['email']) ?>" required></label><?php endif; ?>
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
<?php $mesPers = q("SELECT * FROM personnel WHERE utilisateur_id = ? AND actif = 1 ORDER BY id", [$id])->fetchAll(); if ($mesPers): ?>
<section class="card" id="agenda"><?= agendas_bloc($mesPers, count($mesPers) > 1) ?></section>
<?php elseif ($cible): ?><section class="card"><h2>Agenda et calendriers</h2><p class="muted small">Ton compte n'est relié à aucune fiche du personnel : demande à un administrateur de le relier (Chantiers & agenda › Personnel, matériel, équipes) pour recevoir ton planning dans ton calendrier et y rattacher tes autres agendas.</p></section><?php endif; ?>
<?php page_end(); ?>
