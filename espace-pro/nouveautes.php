<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$vue = q("SELECT version_vue FROM utilisateurs WHERE id = ?", [user()['id']])->fetch()['version_vue'] ?? '';
q("UPDATE utilisateurs SET version_vue = ? WHERE id = ?", [app_version(), user()['id']]);
if (isset($_GET['panneau'])) { // fragment pour le panneau de la cloche
    foreach (versions() as [$num, $date, $changements]) { $neuf = $vue === '' || version_compare($num, $vue, '>');
        echo '<div class="nv' . ($neuf ? ' neuf' : '') . '"><strong>Version ' . e($num) . '</strong> <span class="muted">· ' . date('d/m/Y', strtotime($date)) . '</span>' . ($neuf ? ' <span class="ok" style="font-size:.75rem">NOUVEAU</span>' : '') . '<ul class="dots">';
        foreach ($changements as $c) echo '<li>' . e($c) . '</li>'; echo '</ul></div>'; }
    exit;
}
page_start('Nouveautés', 'nouveautes.php');
?>
<p class="lead">Version actuelle : <strong><?= e(app_version()) ?></strong>. Les versions que tu n'avais pas encore vues sont marquées.</p>
<?php foreach (versions() as [$num, $date, $changements]): $neuf = $vue === '' || version_compare($num, $vue, '>'); ?>
<section class="card" style="<?= $neuf ? 'border-left:5px solid var(--anis)' : '' ?>">
  <h2 style="margin-top:0">Version <?= e($num) ?> <span class="muted" style="font-weight:400;font-size:.9rem">· <?= date('d/m/Y', strtotime($date)) ?></span> <?= $neuf ? '<span class="ok" style="font-size:.8rem">NOUVEAU</span>' : '' ?></h2>
  <ul class="dots"><?php foreach ($changements as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul>
</section>
<?php endforeach; ?>
<?php page_end(); ?>
