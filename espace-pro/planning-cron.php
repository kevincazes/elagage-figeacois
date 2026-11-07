<?php
// Tâche planifiée (facultative) : rappels clients de la veille, contrats, relecture des agendas externes, rappels d'actions, avis…
// Deux façons de l'appeler : par l'adresse web planning-cron.php?cle=… (clé dans Personnel, matériel, équipes › Réglages)
// ou en ligne de commande depuis la tâche planifiée OVH (php www/…/espace-pro/planning-cron.php) : là, pas de clé nécessaire,
// seul le propriétaire de l'hébergement peut lancer la commande.
declare(strict_types=1);
if (PHP_SAPI === 'cli') { $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = 'elagage-figeacois.com'; $_SERVER['HTTPS'] = 'on'; $_SERVER['SCRIPT_NAME'] = '/espace-pro/planning-cron.php'; }
require_once __DIR__ . '/planning_lib.php';
planning_migrer_tout();
if (PHP_SAPI !== 'cli') {
    $cle = (string)($_GET['cle'] ?? '');
    if (setting('planning_cle_cron') === '' || !hash_equals(setting('planning_cle_cron'), $cle)) { http_response_code(403); exit('Clé invalide'); }
    header('Content-Type: text/plain; charset=utf-8');
}
echo date('d/m/Y H:i') . ' – rappels envoyés : ' . planning_taches() . "\n";
