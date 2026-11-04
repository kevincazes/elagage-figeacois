<?php
// Catalogue de démarrage : lignes issues des trois bordereaux analysés le 04/11/2026.
// Chaque ligne : [code, famille, libellé, unité, hauteur, houppier, circonférence, prix de référence HT, source, profil de temps]
// Profil de temps = répartition du coût entre ressources (voir PROFILS). Les prix "estimation" sont à vérifier.
declare(strict_types=1);

const PROFILS = [ // index de ressource (ordre) => part du coût
    'grimpe'  => [0 => .45, 1 => .35, 4 => .20],            // grimpeur, homme de pied, broyeur
    'grimpe+' => [0 => .40, 1 => .30, 2 => .10, 4 => .20],  // + encadrement
    'nacelle' => [3 => .40, 0 => .30, 1 => .30],
    'sol'     => [1 => .55, 5 => .45],                      // homme de pied + camion
    'souche'  => [1 => .40, 6 => .60],
    'manuel'  => [1 => 1.0],
    'heure'   => [0 => .5, 1 => .5],
    'equipe'  => [0 => .35, 1 => .35, 5 => .30],
];

$H = ['<10' => 'Arbre de moins de 10 m de haut', '10-20' => 'Arbre de 10 à 20 m de haut', '>20' => 'Arbre de plus de 20 m de haut'];
$D = ['<=5' => 'houppier ≤ 5 m', '5-10' => 'houppier de 5 à 10 m', '>10' => 'houppier > 10 m'];
$L = [];
$m33 = function (string $pref, string $fam, string $base, array $prix, string $src, string $profil = 'grimpe') use (&$L, $H, $D) {
    $i = 0;
    foreach (['<10', '10-20', '>20'] as $h) foreach (['<=5', '5-10', '>10'] as $d) {
        $L[] = ["$pref-" . substr($h === '<10' ? 'H1' : ($h === '10-20' ? 'H2' : 'H3'), 0) . '-' . ($d === '<=5' ? 'D1' : ($d === '5-10' ? 'D2' : 'D3')),
                $fam, "$base – " . lcfirst($H[$h]) . ", " . $D[$d], 'U', $h, $d, '', $prix[$i++], $src, $profil];
    }
};
$m3h = function (string $pref, string $fam, string $base, array $prix, string $src, string $profil = 'grimpe', string $unite = 'U') use (&$L, $H) {
    $i = 0;
    foreach (['<10', '10-20', '>20'] as $h) $L[] = ["$pref-" . ($h === '<10' ? 'H1' : ($h === '10-20' ? 'H2' : 'H3')), $fam, "$base – " . lcfirst($H[$h]), $unite, $h, '', '', $prix[$i++], $src, $profil];
};

$EB = 'BPU Abattage EB (prix remis)'; $LH = 'Lot Habitat 2026 (estimation)'; $DEP = 'Département – Entretien plantations 2026 (prix remis)'; $EST = 'Estimation à vérifier';

// ---- Urgences ----
$L[] = ['URG-H-JOUR', 'Urgences', 'Intervention urgente entre 8 h et 17 h', 'h', '', '', '', 170, $EB, 'heure'];
$L[] = ['URG-H-NUIT', 'Urgences', 'Intervention urgente entre 17 h et 8 h', 'h', '', '', '', 255, $EB, 'heure'];
$L[] = ['URG-H-WE', 'Urgences', 'Intervention urgente week-end et jours fériés', 'h', '', '', '', 340, $EB, 'heure'];
$L[] = ['URG-F-JOUR', 'Urgences', 'Intervention urgente en heures ouvrées (8 h–17 h), équipe adaptée', 'Forfait', '', '', '', 450, $LH, 'equipe'];
$L[] = ['URG-F-NUIT', 'Urgences', 'Intervention urgente hors heures ouvrées (17 h–8 h), équipe adaptée', 'Forfait', '', '', '', 650, $LH, 'equipe'];
$L[] = ['URG-F-WE', 'Urgences', 'Intervention urgente week-end / jour férié, équipe adaptée', 'Forfait', '', '', '', 850, $LH, 'equipe'];

// ---- Abattage / démontage ----
$m33('ABT', 'Abattage', 'Abattage', [55, 85, 130, 85, 340, 510, 675, 1560, 2720], $EB, 'grimpe+');
$m3h('ABT-H', 'Abattage', 'Abattage / démontage', [85, 340, 1560], $LH, 'grimpe+');
$L[] = ['ABT-C1', 'Abattage', 'Abattage d\'arbre, circonférence ≤ 1,50 m (mesurée à 1,50 m du sol)', 'U', '', '', '<=1.50', 134, $DEP, 'grimpe+'];
$L[] = ['ABT-C2', 'Abattage', 'Abattage d\'arbre, circonférence de 1,50 à 2,85 m', 'U', '', '', '1.50-2.85', 228, $DEP, 'grimpe+'];
$L[] = ['ABT-C3', 'Abattage', 'Abattage d\'arbre, circonférence ≥ 2,85 m', 'U', '', '', '>=2.85', 352, $DEP, 'grimpe+'];

// ---- Débitage et enlèvement ----
$m33('DEB', 'Débitage et enlèvement', 'Débitage et enlèvement d\'arbre au sol', [50, 85, 85, 110, 130, 255, 170, 340, 510], $EB, 'sol');
$m3h('DEB-H', 'Débitage et enlèvement', 'Débitage et enlèvement d\'arbre au sol', [85, 130, 340], $LH, 'sol');

// ---- Dessouchage ----
$L[] = ['SOU-CAR-1', 'Dessouchage', 'Essouchage à la carotteuse mécanique – circonférence < 50 cm', 'U', '', '', '<50cm', 30, $EB, 'souche'];
$L[] = ['SOU-CAR-2', 'Dessouchage', 'Essouchage à la carotteuse mécanique – circonférence > 50 cm', 'U', '', '', '>50cm', 150, $EB, 'souche'];
$L[] = ['SOU-GRI-1', 'Dessouchage', 'Rognage à la grignoteuse – circonférence < 50 cm', 'U', '', '', '<50cm', 55, $EB, 'souche'];
$L[] = ['SOU-GRI-2', 'Dessouchage', 'Rognage à la grignoteuse – circonférence > 50 cm', 'U', '', '', '>50cm', 90, $EB, 'souche'];
$L[] = ['SOU-MAN-1', 'Dessouchage', 'Essouchage manuel – circonférence < 50 cm', 'U', '', '', '<50cm', 20, $EB, 'manuel'];
$L[] = ['SOU-MAN-2', 'Dessouchage', 'Essouchage manuel – circonférence > 50 cm', 'U', '', '', '>50cm', 50, $EB, 'manuel'];
$L[] = ['SOU-EXT-1', 'Dessouchage', 'Extraction complète de la souche – circonférence < 50 cm', 'U', '', '', '<50cm', 50, $EST, 'sol'];
$L[] = ['SOU-EXT-2', 'Dessouchage', 'Extraction complète de la souche – circonférence > 50 cm', 'U', '', '', '>50cm', 120, $EST, 'sol'];
$L[] = ['SOU-C1', 'Dessouchage', 'Dessouchage, circonférence à 1,50 m ≤ 1,50 m', 'U', '', '', '<=1.50', 91, $DEP, 'souche'];
$L[] = ['SOU-C2', 'Dessouchage', 'Dessouchage, circonférence à 1,50 m de 1,50 à 2,85 m', 'U', '', '', '1.50-2.85', 108, $DEP, 'souche'];
$L[] = ['SOU-C3', 'Dessouchage', 'Dessouchage, circonférence à 1,50 m ≥ 2,85 m', 'U', '', '', '>=2.85', 137, $DEP, 'souche'];
$L[] = ['SOU-PV-REB', 'Dessouchage', 'Plus-value pour rebouchage de la cavité en 0/20', 'U', '', '', '', 38, $DEP, 'sol'];

// ---- Tailles ----
$m33('TEN', 'Taille d\'entretien et d\'éclaircie', 'Taille d\'entretien et d\'éclaircie de couronne', [85, 130, 170, 85, 255, 340, 170, 340, 510], $EB);
$m3h('TEN-H', 'Taille d\'entretien et d\'éclaircie', 'Entretien de la couronne', [130, 255, 340], $LH);
$m3h('ECL-H', 'Taille d\'entretien et d\'éclaircie', 'Éclaircie du houppier', [130, 255, 340], $LH);
$m33('TRP', 'Taille réparatrice', 'Taille réparatrice de couronne', [50, 85, 85, 85, 255, 340, 255, 340, 510], $EB);
$m3h('TRP-H', 'Taille réparatrice', 'Taille réparatrice de couronne', [85, 255, 340], $LH);
$m33('RED', 'Réduction de couronne', 'Taille de réduction de couronne', [55, 75, 85, 55, 170, 340, 85, 675, 1360], $EB, 'grimpe+');
$m3h('RED-H', 'Réduction de couronne', 'Réduction de houppier / couronne', [75, 170, 675], $LH, 'grimpe+');
$L[] = ['RED-30', 'Réduction de couronne', 'Élagage pour une réduction du houppier de 30 % ou réduction des charpentières', 'U', '', '', '', 368, $DEP, 'grimpe+'];
$L[] = ['FOR-D1', 'Taille de formation', 'Taille de formation – houppier ≤ 5 m', 'U', '', '<=5', '', 85, $EB];
$L[] = ['FOR-D2', 'Taille de formation', 'Taille de formation – houppier de 5 à 10 m', 'U', '', '5-10', '', 130, $EB];
$L[] = ['FOR-D3', 'Taille de formation', 'Taille de formation – houppier > 10 m', 'U', '', '>10', '', 170, $EB];
$m3h('FOR-H', 'Taille de formation', 'Taille de formation de couronne', [85, 130, 170], $LH);
$m33('REF', 'Taille de reformation', 'Taille de reformation de couronne', [55, 75, 85, 55, 130, 170, 55, 130, 170], $EB);
$L[] = ['REF-CYP-1', 'Taille de reformation', 'Cyprès – hauteur ≤ 5 m', 'U', '<10', '', '', 50, $EB, 'nacelle'];
$L[] = ['REF-CYP-2', 'Taille de reformation', 'Cyprès – hauteur de 5 à 10 m', 'U', '<10', '', '', 130, $EB, 'nacelle'];
$L[] = ['REF-CYP-3', 'Taille de reformation', 'Cyprès – hauteur > 10 m', 'U', '10-20', '', '', 340, $EB, 'nacelle'];
$m3h('REH-H', 'Rehaussement de couronne', 'Rehaussement de la couronne', [85, 170, 255], $LH);
$m3h('ALL-H', 'Allègement de charpentière', 'Allègement de charpentière', [130, 255, 510], $LH, 'grimpe+');
$m3h('CON-H', 'Conifères', 'Entretien de conifères', [85, 170, 340], $LH, 'nacelle');
$m3h('EXC-H', 'Taille exceptionnelle', 'Taille exceptionnelle : étêtage, ravalement, rapprochement', [85, 170, 340], $EB, 'grimpe+');
$m3h('TDC-H', 'Tête de chat', 'Taille d\'entretien sur « tête de chat »', [60, 120, 200], $LH, 'nacelle');
$L[] = ['ELA-SEC', 'Élagage de sécurité', 'Élagage avec sélection des rejets, coupe des branches basses, dangereuses ou mortes (sécurité des usagers)', 'U', '', '', '', 250, $EST . ' – prix absent du BPU Département', 'nacelle'];
$m3h('IND-H', 'Plantes indésirables', 'Élimination de plantes indésirables (lierre, gui…)', [25, 35, 45], $EB, 'manuel', 'Forfait');

// ---- Haubanage ----
foreach (['statique' => [35, 45, 60, 80], 'dynamique' => [40, 50, 65, 85]] as $type => $px) {
    $i = 0;
    foreach (['2 t', '4 t', '8 t', '> 8 t'] as $t) $L[] = ['HAU-' . strtoupper(substr($type, 0, 3)) . '-' . ($i + 1), 'Haubanage', "Haubanage $type sur diagnostic – $t", 'ml', '', '', '', $px[$i++], $EST, 'grimpe+'];
}

// ---- Chenilles et pièges ----
$m3h('CHE-H', 'Chenilles processionnaires', 'Chenilles processionnaires – retrait et élimination des nids', [90, 150, 220], $EST, 'nacelle', 'Forfait');
$L[] = ['CHE-PHE', 'Chenilles processionnaires', 'Piège à phéromones (fourniture et pose)', 'Forfait', '', '', '', 60, $EST, 'manuel'];
$L[] = ['CHE-ECO', 'Chenilles processionnaires', 'Éco-piège de descente – fourniture, pose, sac collecteur, suivi', 'Forfait', '', '', '', 120, $EST, 'manuel'];

// ---- Signalisation et sujétions (Département) ----
$L[] = ['SIG-MAN', 'Signalisation', 'Signalisation temporaire de chantier par alternat manuel', 'Jour', '', '', '', 380, $DEP, 'manuel'];
$L[] = ['SIG-FEU', 'Signalisation', 'Signalisation temporaire de chantier par alternat par feux', 'Jour', '', '', '', 200, $DEP, 'manuel'];
$L[] = ['SIG-DEV', 'Signalisation', 'Signalisation temporaire de chantier sous déviation', 'Forfait', '', '', '', 80, $DEP, 'manuel'];
$L[] = ['PV-DIFF', 'Plus et moins-values', 'Plus-value pour travaux en conditions particulières et difficiles', 'U', '', '', '', 30, $DEP, 'grimpe'];
$L[] = ['MV-HORS', 'Plus et moins-values', 'Moins-value pour chantier réalisé hors circulation (à déduire)', 'U', '', '', '', 9, $DEP, 'manuel'];
$L[] = ['PV-TRON', 'Plus et moins-values', 'Plus-value pour tronçonnage en 2 ml d\'arbre abattu de diamètre > 0,50 m', 'h', '', '', '', 48, $DEP, 'heure'];
$L[] = ['DIV-BSD', 'Divers', 'Fourniture des bordereaux de suivi et d\'élimination des déchets (par bon de commande)', 'Forfait', '', '', '', 180, $DEP, 'manuel'];
$L[] = ['DIV-DEP', 'Divers', 'Déplacement et installation de chantier', 'Forfait', '', '', '', 110, $EST, 'equipe'];

// ---- Packs de démarrage : nom => [description, préfixes ou codes de lignes] ----
$PACKS = [
    'EB – Abattage 3 × 3' => ['Abattage par hauteur et diamètre de houppier (9 lignes)', ['ABT-H1', 'ABT-H2', 'ABT-H3']],
    'EB – Débitage 3 × 3' => ['Débitage et enlèvement au sol (9 lignes)', ['DEB-H1', 'DEB-H2', 'DEB-H3']],
    'EB – Dessouchage' => ['Carotteuse, grignoteuse, manuel (6 lignes)', ['SOU-CAR', 'SOU-GRI', 'SOU-MAN']],
    'EB – Taille d\'entretien 3 × 3' => ['Entretien et éclaircie de couronne (9 lignes)', ['TEN-H1', 'TEN-H2', 'TEN-H3']],
    'EB – Taille réparatrice 3 × 3' => ['9 lignes', ['TRP-H1', 'TRP-H2', 'TRP-H3']],
    'EB – Réduction de couronne 3 × 3' => ['9 lignes', ['RED-H1', 'RED-H2', 'RED-H3']],
    'EB – Reformation 3 × 3 + cyprès' => ['12 lignes', ['REF-H1', 'REF-H2', 'REF-H3', 'REF-CYP']],
    'EB – Bordereau complet' => ['Les 11 sections du BPU Abattage EB', ['URG-H', 'ABT-H1', 'ABT-H2', 'ABT-H3', 'DEB-H1', 'DEB-H2', 'DEB-H3', 'SOU-CAR', 'SOU-GRI', 'SOU-MAN', 'TEN-H1', 'TEN-H2', 'TEN-H3', 'TRP-H1', 'TRP-H2', 'TRP-H3', 'RED-H1', 'RED-H2', 'RED-H3', 'FOR-D', 'REF-H1', 'REF-H2', 'REF-H3', 'REF-CYP', 'EXC-H', 'IND-H']],
    'Lot Habitat 2026 – Bordereau complet' => ['Les 61 lignes, par hauteur', ['URG-F', 'ABT-H-', 'DEB-H-', 'SOU-CAR', 'SOU-GRI', 'SOU-EXT', 'FOR-H-', 'TEN-H-', 'ECL-H-', 'REH-H-', 'ALL-H-', 'RED-H-', 'TRP-H-', 'CON-H-', 'EXC-H-', 'TDC-H-', 'IND-H-', 'HAU-', 'CHE-']],
    'Département – Entretien plantations 2026' => ['Les 16 lignes du marché', ['SIG-', 'ABT-C', 'RED-30', 'ELA-SEC', 'MV-HORS', 'PV-DIFF', 'SOU-C', 'SOU-PV-REB', 'DIV-BSD', 'PV-TRON']],
];
return ['lignes' => $L, 'packs' => $PACKS];
