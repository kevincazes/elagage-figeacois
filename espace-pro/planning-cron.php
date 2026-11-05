<?php
// Tâche planifiée OVH (facultative) : rappels clients de la veille. Appel : planning-cron.php?cle=…
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
planning_migrate();
$cle = (string)($_GET['cle'] ?? ($argv[1] ?? ''));
if (setting('planning_cle_cron') === '' || !hash_equals(setting('planning_cle_cron'), $cle)) { http_response_code(403); exit('Clé invalide'); }
header('Content-Type: text/plain; charset=utf-8');
echo 'Rappels envoyés : ' . planning_taches() . "\n";
