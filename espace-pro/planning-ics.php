<?php
// Abonnement d'agenda (téléphone) : chantiers et rendez-vous d'une personne, par lien secret
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
planning_migrer_tout();
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$p = strlen($t) === 32 ? q("SELECT * FROM personnel WHERE ics_token = ? AND actif = 1", [$t])->fetch() : null;
if (!$p) { http_response_code(404); exit('Lien inconnu'); }
$evs = [];
foreach (q("SELECT e.id FROM evenements e JOIN evenement_personnel ep ON ep.evenement_id = e.id WHERE ep.personnel_id = ? AND e.statut <> 'annule' AND e.debut IS NOT NULL AND e.fin > DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY e.debut LIMIT 1000", [$p['id']]) as $x) {
    $e = evt_charger((int)$x['id']); if ($e) $evs[] = $e;
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="planning.ics"');
header('Cache-Control: no-cache');
echo ics_calendrier($evs, 'Élagage Figeacois – ' . $p['prenom']);
