<?php
// Visionneuse des documents clients (porte-doc) et des photos de rapport : aperçu, Télécharger, Imprimer, Partager, Envoyer par e-mail
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
$gere = planning_niveau() === 'tout';
if (isset($_GET['photo'])) {
    $ph = q("SELECT p.*, e.titre, e.client_id, e.debut, c.email cl_email, c.nom cl_nom FROM rapport_photos p JOIN evenements e ON e.id = p.evenement_id LEFT JOIN clients c ON c.id = e.client_id WHERE p.id = ?", [(int)$_GET['photo']])->fetch();
    if (!$ph || !evt_visible(evt_charger((int)$ph['evenement_id'])['personnel'] ?? [])) { http_response_code(404); exit('Photo introuvable'); }
    $nom = 'Photo – ' . $ph['titre'] . ($ph['legende'] ? ' – ' . $ph['legende'] : ''); $chemin = chemin_fichier($ph['fichier']);
    $url = "rapport.php?evt={$ph['evenement_id']}&photo={$ph['id']}"; $email = (string)$ph['cl_email']; $retour = $ph['client_id'] ? "clients.php?id={$ph['client_id']}&o=doc" : "rapport.php?evt={$ph['evenement_id']}";
} else {
    $d = q("SELECT d.*, c.email cl_email, c.nom cl_nom FROM client_docs d JOIN clients c ON c.id = d.client_id WHERE d.id = ?", [(int)($_GET['doc'] ?? 0)])->fetch();
    if ($d && !$gere && in_array($d['dossier'], DOSSIERS_FINANCIERS, true)) $d = null;
    if (!$d) { http_response_code(404); exit('Document introuvable'); }
    $nom = preg_replace('/\.[a-z0-9]{2,4}$/i', '', $d['nom']); $chemin = chemin_fichier($d['fichier']);
    $url = "clients.php?doc={$d['id']}"; $email = (string)$d['cl_email']; $retour = "clients.php?id={$d['client_id']}&o=doc";
}
$msg = ''; $err = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vis_envoyer') {
    $a = trim((string)($_POST['a'] ?? ''));
    if ($chemin && visionneuse_envoyer($a, (string)($_POST['texte'] ?? ''), $nom, $chemin)) { $msg = "Document envoyé à $a."; journal('clients', "Document « $nom » envoyé par e-mail à $a"); }
    else { $msg = "L'e-mail n'est pas parti : vérifie l'adresse."; $err = true; }
}
page_start($nom, 'clients.php', ['clients.php', 'Clients']);
echo '<style>main>h1{display:none}</style>';
echo visionneuse_html(['nom' => $nom, 'url' => $url, 'url_dl' => $url . '&dl=1', 'ext' => $chemin ? pathinfo($chemin, PATHINFO_EXTENSION) : '', 'retour' => $retour, 'retour_libelle' => 'Fiche client',
    'envoi' => (bool)$chemin, 'email' => $email, 'texte_mail' => '', 'message' => $msg, 'message_err' => $err]);
page_end();
