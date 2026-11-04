<?php
// Formulaire de contact – Élagage Figeacois
// Adresse de réception (à adapter si besoin)
$destinataire = "contact@elagage-figeacois.com";

if ($_SERVER["REQUEST_METHOD"] !== "POST") { header("Location: contact.html"); exit; }
if (!empty($_POST["site"])) { exit; } // piège anti-robot : champ caché rempli = spam

$nom     = trim(strip_tags($_POST["nom"] ?? ""));
$email   = trim($_POST["email"] ?? "");
$tel     = trim(strip_tags($_POST["tel"] ?? ""));
$commune = trim(strip_tags($_POST["commune"] ?? ""));
$message = trim(strip_tags($_POST["message"] ?? ""));

if ($nom === "" || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === "") {
    echo "<p>Formulaire incomplet. <a href='contact.html'>Retour</a></p>"; exit;
}

$sujet = "Demande de contact via le site – " . $nom;
$corps = "Nom : $nom\nE-mail : $email\nTéléphone : $tel\nCommune : $commune\n\nMessage :\n$message\n";
$entetes = "From: site@elagage-figeacois.com\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n";

mail($destinataire, "=?UTF-8?B?" . base64_encode($sujet) . "?=", $corps, $entetes);
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Message envoyé – Élagage Figeacois</title><link rel="stylesheet" href="style.css"></head>
<body><main class="wrap section narrow" style="text-align:center;padding-top:90px">
<h2>Merci, votre message a bien été envoyé.</h2>
<p>Nous vous recontactons rapidement.</p>
<a class="btn" href="index.html">Retour à l'accueil</a>
</main></body></html>
