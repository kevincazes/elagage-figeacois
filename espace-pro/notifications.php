<?php
// Mes notifications (les mêmes que celles reçues sur le téléphone)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$l = q("SELECT * FROM notifications WHERE utilisateur_id = ? ORDER BY id DESC LIMIT 60", [user()['id']])->fetchAll();
q("UPDATE notifications SET lu_le = NOW() WHERE utilisateur_id = ? AND lu_le IS NULL", [user()['id']]);
$nbApp = (int)q("SELECT COUNT(*) c FROM push_abonnements WHERE utilisateur_id = ?", [user()['id']])->fetch()['c'];
page_start('Mes notifications', 'notifications.php');
?>
<p><button class="mini" type="button" id="notif" hidden>🔔 Activer les notifications</button> <span class="muted small"><?= $nbApp ? "Notifications actives sur $nbApp appareil(s)." : "Active les notifications sur ton téléphone pour être prévenu des nouveaux chantiers et des changements. Sur iPhone : ouvre d'abord l'espace pro depuis l'écran d'accueil (Safari › Partager › Sur l'écran d'accueil)." ?></span></p>
<?php if (!$l): ?><p class="muted">Aucune notification pour l'instant.</p><?php endif; ?>
<?php foreach ($l as $n): ?><a class="card" href="<?= e($n['url'] ?: '#') ?>" style="display:block;text-decoration:none;color:inherit;<?= $n['lu_le'] ? '' : 'border-left:5px solid var(--rouge)' ?>"><span class="small"><?= e(date('d/m/Y H:i', strtotime($n['cree_le']))) ?></span><br><strong><?= e($n['titre']) ?></strong><?= $n['corps'] ? '<br>' . e($n['corps']) : '' ?></a><?php endforeach; ?>
<?php page_end(); ?>
