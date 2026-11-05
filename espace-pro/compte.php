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
    } elseif ($a === 'appareil_oublier' && $cible) {
        q("DELETE FROM connexions_souvenir WHERE id = ? AND utilisateur_id = ?", [(int)($_POST['cid'] ?? 0), $id]); flash('Cet appareil devra se reconnecter.');
    } elseif ($a === 'appareils_autres' && $cible) {
        $sel = str_starts_with((string)($_COOKIE['ef_souvenir'] ?? ''), 's') ? substr(explode(':', (string)$_COOKIE['ef_souvenir'], 2)[0], 1) : '';
        q("DELETE FROM connexions_souvenir WHERE utilisateur_id = ? AND selecteur <> ?", [$id, $sel]); flash('Les autres appareils devront se reconnecter.');
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
    <?php if ($cible && q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1", [$id])->fetch()): ?><p style="margin:0 0 8px"><a class="mini" href="livret.php">Mon livret (titres, habilitations, formations)</a></p><?php endif; ?>
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
<?php if ($cible): $apps = q("SELECT * FROM connexions_souvenir WHERE utilisateur_id = ? AND expire > NOW() ORDER BY vu_le DESC", [$id])->fetchAll(); $selMoi = str_starts_with((string)($_COOKIE['ef_souvenir'] ?? ''), 's') ? substr(explode(':', (string)$_COOKIE['ef_souvenir'], 2)[0], 1) : ''; ?>
<section class="card"><h2>Appareils restés connectés</h2>
  <p class="muted small">Chaque appareil où « Rester connecté » était coché garde sa connexion 60 jours, renouvelés à chaque visite. Se connecter ailleurs ne le déconnecte pas.</p>
  <?php if (!$apps): ?><p class="muted">Aucun pour l'instant : coche « Rester connecté sur cet appareil » à la prochaine connexion.</p><?php else: ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Appareil</th><th>Dernière visite</th><th>Connecté depuis</th><th></th></tr></thead><tbody>
  <?php foreach ($apps as $c): ?><tr><td><strong><?= e($c['appareil']) ?></strong><?= $c['selecteur'] === $selMoi ? ' <span class="muted small">(cet appareil)</span>' : '' ?></td><td><?= e(date('d/m/Y H:i', strtotime($c['vu_le']))) ?></td><td><?= e(date('d/m/Y', strtotime($c['cree_le']))) ?></td>
    <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="appareil_oublier"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>"><button class="mini">Déconnecter</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if (count($apps) > 1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="appareils_autres"><button class="mini">Déconnecter tous les autres appareils</button></form><?php endif; endif; ?>
</section>
<?php endif; ?>
<?php page_end(); ?>
