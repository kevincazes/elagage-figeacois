<?php
// Notifications : abonnement du téléphone (web push), lecture par le service worker, liste dans l'appli
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
$a = $_GET['a'] ?? '';
if ($a === 'suivant') { // appelé par le téléphone à la réception d'une notification (sans session) : l'adresse d'abonnement sert de clé
    planning_migrate();
    $ab = q("SELECT * FROM push_abonnements WHERE cle = ?", [hash('sha256', (string)($_GET['ep'] ?? ''))])->fetch();
    if (!$ab) { echo json_encode(['notifications' => []]); exit; }
    $l = q("SELECT id, titre, corps, url FROM notifications WHERE utilisateur_id = ? AND cree_le > ? ORDER BY id DESC LIMIT 3", [$ab['utilisateur_id'], $ab['vu_le'] ?: date('Y-m-d H:i:s', time() - 86400)])->fetchAll();
    q("UPDATE push_abonnements SET vu_le = NOW() WHERE id = ?", [$ab['id']]);
    echo json_encode(['notifications' => array_reverse($l)], JSON_UNESCAPED_UNICODE); exit;
}
require_planning();
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
switch ($a) {
    case 'cle': echo json_encode(['cle' => vapid()['public'] ?? '']); exit;
    case 'abonner':
        $ep = (string)($in['endpoint'] ?? ''); if (!preg_match('#^https://#', $ep) || strlen($ep) > 600) { http_response_code(400); echo '{"erreur":"Adresse invalide"}'; exit; }
        q("INSERT INTO push_abonnements (utilisateur_id, endpoint, cle, appareil, vu_le) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE utilisateur_id = VALUES(utilisateur_id), appareil = VALUES(appareil)", [user()['id'], $ep, hash('sha256', $ep), mb_substr((string)($in['appareil'] ?? ''), 0, 120)]);
        echo '{"ok":1}'; exit;
    case 'desabonner': q("DELETE FROM push_abonnements WHERE cle = ? AND utilisateur_id = ?", [hash('sha256', (string)($in['endpoint'] ?? '')), user()['id']]); echo '{"ok":1}'; exit;
    case 'liste':
        $l = q("SELECT * FROM notifications WHERE utilisateur_id = ? ORDER BY id DESC LIMIT 30", [user()['id']])->fetchAll();
        q("UPDATE notifications SET lu_le = NOW() WHERE utilisateur_id = ? AND lu_le IS NULL", [user()['id']]);
        echo json_encode(['notifications' => $l], JSON_UNESCAPED_UNICODE); exit;
}
http_response_code(400); echo '{"erreur":"Action inconnue"}';
