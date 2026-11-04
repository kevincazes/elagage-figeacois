<?php
// Journal des versions de l'espace pro : la plus récente en premier.
// Chaque entrée : numéro, date, liste des changements. APP_VERSION = première entrée.
return [
    ['2.4.1', '2026-11-04', [
        'Contrôle complet des calculs : 14 000 vérifications automatiques (catalogue, blocs, actions, variantes, bordereaux, totaux, export, figement) sur plusieurs réglages ; correction d\'un arrondi qui donnait certains prix 1 € trop bas (ex. 85,50 € affiché 85 € au lieu de 86 €)',
        'Import de BPU : colonnes reconnues par leur intitulé, dans n\'importe quel ordre (Excel, Word, CSV) ; codes de toute forme ; quantités du DQE toutes récupérées ; classe de hauteur juste quand le libellé décrit aussi le houppier ; « Ignorer » respecté pour les titres ; mémoire des choix par rubrique',
        'Le choix « Coût avec » / « Prix avec » est conservé après un enregistrement et d\'une page à l\'autre',
        'Bordereau : un enregistrement ne détache plus jamais une ligne de sa prestation ; PU imposé à 0 ou négatif et quantités négatives comptés comme à l\'export ; prestation supprimée : prix conservé',
        'Paramètres : un changement de pourcentage de progression recalcule aussitôt les variantes ; l\'équipe principale ne peut plus être retirée par erreur',
        'Actions : ajouter, retirer, modifier ou supprimer une action recalcule aussi les variantes des prestations concernées',
        'Assistant : ne modifie plus un bordereau figé ou ouvert par quelqu\'un d\'autre ; prix donnés avec l\'équipe du bordereau',
        'Catalogue : codes en double signalés ; moins-values (forfait négatif) conservées ; variantes détachées si leur base est supprimée ; colonnes de la base élargies (codes et libellés longs)',
    ]],
    ['2.4', '2026-11-04', [
        'Assistant disponible sur toutes les pages : bouton ✦ en bas à droite, panneau latéral qui reste ouvert d\'une page à l\'autre ; il sait sur quel écran tu es ; ses propositions affichent les noms (prestation, ressource, temps avant → après) ; le menu « Assistant » disparaît (page complète via « Historique ↗ »)',
        'Guide entièrement refait, avec 24 captures d\'écran annotées : chaque bouton et chaque colonne expliqués, parcours pas à pas',
        'Paramètres : correction de l\'enregistrement des règles de calcul (coefficient, TVA, arrondi, progression) et des liens du tableau de bord, qui ne s\'enregistraient pas',
        'Grands tableaux (catalogue, bordereau, import) : envoi groupé pour ne jamais perdre de lignes à l\'enregistrement, même avec plusieurs centaines de lignes',
        'Bordereaux : liste en premier, colonne équipe ; création avec choix de l\'équipe et départ depuis un pack (prix calculés) ou copie complète d\'un bordereau',
        'Bordereau : colonne « Prestation du catalogue » pour rattacher ou changer la prestation d\'une ligne (prix mis à jour aussitôt) ; clic sur le PU calculé pour ouvrir la prestation dans le catalogue ; désignations en entier et figées à gauche ; raccourcis « Ajouter » ; impression épurée',
        'Import : les prix proposés suivent l\'équipe choisie',
        'Packs : l\'ordre des lignes est conservé à l\'enregistrement ; boutons « Régler les temps » et « → Bordereau »',
        'Catalogue : filtres Pack, prestation seule, action ; l\'équipe de « Prix avec » est conservée après enregistrement ; une ligne ajoutée s\'affiche aussitôt',
        'Actions : clic sur « N prestations » pour les voir dans le catalogue',
        'Tableau de bord : tuile « Importer un bordereau reçu »',
        'Pages à grand tableau : le bandeau ne recouvre plus l\'en-tête des colonnes ; le tableau occupe tout l\'écran',
    ]],
    ['2.3.5', '2026-11-04', ['Catalogue : filtre « Pack » pour n\'afficher et ne régler que les lignes d\'un pack', 'En-tête des colonnes en vert foncé, bien distinct des lignes', 'Titres de famille et de hauteur toujours visibles à gauche, même tableau poussé tout à droite sur grand écran', 'Catalogue et bordereaux : le tableau est une fenêtre qui défile dans les deux sens (molette, barre, souris) sans entraîner la page ; l\'en-tête des colonnes reste figé en haut, la désignation à gauche', 'Le menu « Prix avec » s\'applique dès le choix ; position de la page et du tableau conservée après un rechargement']],
    ['2.3.4', '2026-11-04', ['Catalogue : une seule colonne « Heures d\'équipe » (les colonnes par équipe ont disparu : les heures sont les mêmes quelle que soit l\'équipe, seul le taux change, via « Prix avec » ici ou « Équipe appliquée aux prix » dans le bordereau)']],
    ['2.3.3', '2026-11-04', ['Catalogue et bordereaux : on peut faire glisser le tableau à la souris (clic maintenu hors des champs) pour le déplacer à droite, à gauche, en haut ou en bas']],
    ['2.3.2', '2026-11-04', ['Catalogue : la colonne Désignation reste figée à gauche pendant le défilement horizontal, on voit toujours quelle ligne on modifie', 'Actions : menu « Coût avec » pour afficher le coût indicatif avec une autre équipe ; champ Précision élargi']],
    ['2.3.1', '2026-11-04', [
        'Catalogue et bordereaux : barre de défilement horizontal qui reste visible en bas de l\'écran, avec boutons ⇤ ⇥ pour aller au début ou à la fin du tableau',
        'Guide : sommaire fixé sur le côté (surligne la partie en cours), lien « Sommaire » sur téléphone ; les liens du sommaire s\'arrêtent juste sous le bandeau',
    ]],
    ['2.3', '2026-11-04', [
        'Guide d\'utilisation complet dans le menu « Guide » : logique du chiffrage, paramètres, actions, catalogue, packs, bordereaux, assistant, travail à plusieurs, questions fréquentes',
        'Catalogue et bordereaux : page élargie sur grand écran, barre de défilement horizontal toujours visible, en-têtes de bloc qui restent lisibles en défilant',
    ]],
    ['2.2.1', '2026-11-04', ['Paramètres : le rognage se règle dans les taux horaires des ressources, plus dans le cadre de conversion']],
    ['2.2', '2026-11-04', [
        'Assistant intégré (menu « Assistant », bouton ✦ dans chaque bordereau) : questions de chiffrage, temps par action, comparaison aux prix remis ; il connaît le catalogue, les taux, les actions et le bordereau ouvert',
        'Actions proposées par l\'assistant (modifier un temps, créer une prestation, ajuster une ligne du bordereau, mémoriser une règle) appliquées seulement après ton clic « Appliquer », tracées dans l\'historique',
        'Mémoire d\'entreprise partagée et conversations conservées par personne ; plafond mensuel d\'échanges',
    ]],
    ['2.1', '2026-11-04', [
        'Import d\'un bordereau reçu (Excel, Word, CSV, PDF texte) : lecture des lignes, appariement automatique au catalogue avec indice de confiance, validation ligne par ligne, création du bordereau au format de la collectivité',
        'Les corrections d\'appariement sont mémorisées pour les bordereaux suivants',
    ]],
    ['2.0.4', '2026-11-04', ['Lien « Site » dans le bandeau pour revenir au site public', 'Fil d\'Ariane sous le bandeau ; la cloche ouvre les nouveautés dans un panneau sans quitter la page', 'Catalogue : fin du tableau accessible (colonne PU HT plus figée), défilement horizontal ; désignations affichées en entier (champ qui s\'agrandit)']],
    ['2.0.3', '2026-11-04', ['Affichage sur téléphone : menu à trois traits, tuiles et formulaires sur une colonne, tableaux défilants']],
    ['2.0.2', '2026-11-04', ['Paramètres : champs chiffrés alignés en bout de ligne ; liens du tableau de bord séparés des règles de calcul']],
    ['2.0.1', '2026-11-04', ['Paramètres : mise en page sur deux colonnes, champs chiffrés alignés sur une même colonne, noms de ressources lisibles ; la conversion réutilise une ressource « équipe » créée à la main']],
    ['2.0', '2026-11-04', [
        'Nouveau modèle de chiffrage : heures d\'équipe (tout compris) + rognage + moyens spécifiques (enlèvement du bois, grue…) ; conversion du catalogue en un clic dans Paramètres, prix inchangés',
        'Plusieurs équipes à taux différents (standard, marché public) : l\'équipe est choisie par bordereau et s\'applique à toutes ses lignes',
        'Bibliothèque d\'actions dans l\'arbre (menu « Actions ») : une prestation se compose d\'actions indépendantes, son temps d\'équipe est la somme',
        'Progression par taille : pourcentages de temps par classe de hauteur et de houppier (Paramètres) ; bouton ↕ pour générer les variantes d\'une ligne de base, recalculées automatiquement',
    ]],
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
