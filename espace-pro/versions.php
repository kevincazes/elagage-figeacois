<?php
// Journal des versions de l'espace pro : la plus récente en premier.
// Chaque entrée : numéro, date, liste des changements. APP_VERSION = première entrée.
return [
    ['1.10', '2026-11-04', [
        'Logos Extrabat et Urgence Élagage sur le tableau de bord',
        'Catalogue : menus Hauteur / Houppier lisibles ; bouton pour supprimer les lignes d\'exemple du premier jour ; en-tête de colonnes stable, blocs ouverts ou fermés',
    ]],
    ['1.9', '2026-11-04', [
        'Catalogue en blocs repliables par famille, avec sous-titres par hauteur d\'arbre (état mémorisé) ; même présentation dans le sélecteur de lignes d\'un bordereau',
        'Correction de l\'affichage des logos sur le tableau de bord (ancienne feuille de style gardée en cache par le CDN)',
    ]],
    ['1.8', '2026-11-04', [
        'Numéro de version et cloche de notification des nouveautés dans le bandeau',
        'Catalogue : champs désignation et famille élargis, filtres plus compacts',
    ]],
    ['1.7', '2026-11-04', ['Logos des outils (Google Drive, Extrabat, Urgence Élagage, Arbogestion) sur le tableau de bord']],
    ['1.6', '2026-11-04', [
        'Option « Rester connecté sur cet appareil (30 jours) » à la connexion ; session active portée à 12 h',
        'Correction de l\'erreur 500 sur la page Bordereaux (ordre de création des tables)',
    ]],
    ['1.5', '2026-11-04', [
        'Verrou d\'édition : un bordereau ouvert par quelqu\'un est en lecture seule pour les autres (libération automatique après 10 min d\'inactivité, forçage possible par un administrateur)',
        'Historique des actions par bordereau et page « Historique » globale (taux, coefficient, catalogue)',
    ]],
    ['1.4', '2026-11-04', [
        'Configurateur de bordereaux : catalogue classé par famille / hauteur / houppier / circonférence avec prix de référence, packs, composition au format de la collectivité, quantités DQE, prix imposé, figement, export Excel',
        'Catalogue de démarrage : 143 prestations issues des bordereaux EB, Lot Habitat 2026 et Département 2026',
    ]],
    ['1.3', '2026-11-04', ['Page « Mon compte » (prénom, nom, e-mail, mot de passe) ; modification des comptes par les administrateurs']],
    ['1.2', '2026-11-04', [
        'Double authentification obligatoire : application d\'authentification (QR code) ou code par e-mail',
        'Bouton « Espace pro » dans le menu du site public',
    ]],
    ['1.1', '2026-11-04', ['Création de compte par invitation (lien e-mail valable 48 h) ; mot de passe oublié']],
    ['1.0', '2026-11-04', ['Mise en ligne du site vitrine et de l\'espace pro : comptes, paramètres, lignes BPU, grilles figées, export']],
];
