<?php
// Numéro de version en ligne (public, sans aucune donnée) : sert à vérifier qu'une mise en ligne est bien arrivée.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$v = require __DIR__ . '/versions.php';
echo $v[0][0];
