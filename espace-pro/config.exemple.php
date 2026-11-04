<?php
// ============================================================
//  Espace pro Élagage Figeacois – configuration
//  Remplis uniquement DB_PASS avec le mot de passe de la base.
// ============================================================
define('DB_HOST', 'jdjhgfgefpro.mysql.db');
define('DB_NAME', 'jdjhgfgefpro');
define('DB_USER', 'jdjhgfgefpro');
define('DB_PASS', 'COLLE_ICI_LE_MOT_DE_PASSE');

// Nom affiché et adresse e-mail de l'entreprise (pour les exports)
define('APP_NAME', 'Élagage Figeacois – Espace pro');
define('APP_EMAIL', 'contact@elagage-figeacois.com');

// Ne rien modifier sous cette ligne
define('DB_DSN', 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4');
