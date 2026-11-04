<?php
// Appariement d'une ligne de bordereau reçu avec une prestation du catalogue
declare(strict_types=1);

function norm(string $s): string {
    $s = mb_strtolower($s);
    $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', '’' => "'", '–' => '-']);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Famille probable d'après le libellé (et le titre de section). Retourne [famille, score] */
function detecter_famille(string $texte): ?string {
    $t = norm($texte);
    $regles = [
        'Urgences' => ['urgen', 'astreinte', '8h et 17h', '17h et 8h', 'week-end', 'jour ferie', 'heures ouvr'],
        'Signalisation' => ['signalisation', 'alternat', 'deviation'],
        'Plus et moins-values' => ['plus-value', 'plus value', 'moins-value', 'moins value', 'troncon'],
        'Chenilles processionnaires' => ['chenille', 'processionnaire', 'pheromone', 'eco-piege', 'piege'],
        'Haubanage' => ['hauban'],
        'Dessouchage' => ['dessouch', 'essouch', 'souche', 'carott', 'grignot', 'rognage', 'rabotage'],
        'Débitage et enlèvement' => ['debitage', 'enlevement d\'arbre', 'enlevement arbre', 'arbre au sol'],
        'Abattage' => ['abattage', 'demontage', 'abattre'],
        'Plantes indésirables' => ['indesirable', 'lierre', 'gui'],
        'Élagage de sécurité' => ['selection de rejet', 'selection des rejet', 'branches basses', 'securite des usagers'],
        'Réduction de couronne' => ['reduction'],
        'Taille réparatrice' => ['reparatrice'],
        'Taille de formation' => ['formation'],
        'Taille de reformation' => ['reformation', 'cypres'],
        'Rehaussement de couronne' => ['rehaussement', 'remontee de couronne', 'relevage'],
        'Allègement de charpentière' => ['allegement'],
        'Conifères' => ['conifere'],
        'Tête de chat' => ['tete de chat'],
        'Taille exceptionnelle' => ['exceptionnelle', 'etetage', 'ravalement', 'rapprochement'],
        'Taille d\'entretien et d\'éclaircie' => ['entretien', 'eclaircie', 'couronne', 'houppier'],
        'Divers' => ['bordereau de suivi', 'dechet', 'deplacement', 'installation de chantier'],
    ];
    foreach ($regles as $fam => $mots) foreach ($mots as $m) if (str_contains($t, $m)) return $fam;
    return null;
}
function detecter_hauteur(string $texte): string {
    // On retire ce qui décrit le houppier, le diamètre ou la circonférence (« houppier de 5 à 10 m ») : ce n'est pas la hauteur
    $t = preg_replace('/(houppier|diametre|circonference|envergure|couronne)(?:(?!arbre|sujet|hauteur|haut\b)[^,;()])*/', ' ', norm($texte));
    if (preg_match('/(moins de|inferieur\w* (ou egal\w* )?a|<|jusqu\'a|<=) ?10 ?m/', $t) || preg_match('/\b(5|6|7|8|9) ?(a|et) ?10 ?m/', $t)) return '<10';
    if (preg_match('/10 ?(a|et|-|–) ?20 ?m/', $t) || preg_match('/(entre|de) 10 ?m/', $t)) return '10-20';
    if (preg_match('/(plus de|superieur\w* a|>|au-dela de) ?20 ?m/', $t)) return '>20';
    if (preg_match('/(plus de|superieur\w* a|>) ?10 ?m/', $t) && !str_contains($t, 'houppier') && !str_contains($t, 'diametre')) return '10-20';
    return '';
}
function detecter_houppier(string $texte): string {
    $t = norm($texte); if (!str_contains($t, 'houppier') && !str_contains($t, 'diametre')) return '';
    if (preg_match('/(inferieur\w* ou egal\w* a|inferieur\w* a|<=?|jusqu\'a) ?5 ?m/', $t)) return '<=5';
    if (preg_match('/(entre|de) ?5 ?(et|a|-|–) ?10 ?m/', $t)) return '5-10';
    if (preg_match('/(superieur\w* a|plus de|>) ?10 ?m/', $t)) return '>10';
    return '';
}
function detecter_circonference(string $texte): string {
    $t = norm($texte); if (!str_contains($t, 'circonf') && !str_contains($t, 'diametre')) return '';
    if (preg_match('/(inferieur\w*( ou egal\w*)? a|<=?) ?1[,.]50? ?m/', $t)) return '<=1.50';
    if (preg_match('/1[,.]50? ?m.*2[,.]85 ?m/', $t)) return '1.50-2.85';
    if (preg_match('/(superieur\w*( ou egal\w*)? a|>=?) ?2[,.]85 ?m/', $t)) return '>=2.85';
    if (preg_match('/(inferieur\w* a|<) ?50 ?cm/', $t)) return '<50cm';
    if (preg_match('/(superieur\w* a|>) ?50 ?cm/', $t)) return '>50cm';
    if (preg_match('/(inferieur\w* a|<) ?0[,.]50? ?m/', $t)) return '<50cm';
    if (preg_match('/(superieur\w* a|>) ?0[,.]50? ?m/', $t)) return '>50cm';
    return '';
}
/** Attributs discriminants, normalisés */
function attributs(string $s): array {
    $t = norm($s); $a = [];
    if (preg_match('/8 ?h.{0,8}17 ?h|heures ouvr|jour\b/', $t) && !str_contains($t, 'ferie')) $a[] = 'jour';
    if (preg_match('/17 ?h.{0,8}8 ?h|hors heures|nuit/', $t)) $a[] = 'nuit';
    if (preg_match('/week|ferie|dimanche/', $t)) $a[] = 'we';
    if (preg_match('/\b2 ?t(onnes)?\b/', $t)) $a[] = 't2'; if (preg_match('/\b4 ?t(onnes)?\b/', $t)) $a[] = 't4';
    if (preg_match('/\b8 ?t(onnes)?\b/', $t)) $a[] = str_contains($t, 'superieur') || str_contains($t, '> 8') ? 't8plus' : 't8';
    if (str_contains($t, 'statique')) $a[] = 'statique'; if (str_contains($t, 'dynamique')) $a[] = 'dynamique';
    if (preg_match('/carott/', $t)) $a[] = 'carotteuse'; if (preg_match('/grignot|rabot/', $t)) $a[] = 'grignoteuse';
    if (preg_match('/manuel/', $t) && str_contains($t, 'souch')) $a[] = 'manuel'; if (preg_match('/extraction/', $t)) $a[] = 'extraction';
    if (str_contains($t, 'cypres')) $a[] = 'cypres';
    if (str_contains($t, 'alternat manuel')) $a[] = 'alt-manuel'; if (str_contains($t, 'feu')) $a[] = 'alt-feux'; if (str_contains($t, 'deviation')) $a[] = 'deviation';
    if (str_contains($t, 'pheromone')) $a[] = 'pheromone'; if (str_contains($t, 'eco-piege') || str_contains($t, 'ecopiege') || str_contains($t, 'descente')) $a[] = 'ecopiege';
    if (str_contains($t, 'rebouch')) $a[] = 'rebouchage'; if (str_contains($t, 'troncon')) $a[] = 'tronconnage'; if (str_contains($t, 'hors circulation')) $a[] = 'horscircu'; if (str_contains($t, 'difficile')) $a[] = 'difficile';
    if (str_contains($t, 'moins-value') || str_contains($t, 'moins value')) $a[] = 'moinsvalue';
    return $a;
}
function mots_cles(string $s): array {
    $stop = ['de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'ou', 'a', 'au', 'aux', 'en', 'sur', 'pour', 'par', 'd', 'l', 'arbre', 'arbres', 'haut', 'm', 'metres', 'metre', 'ml', 'u', 'avec', 'dont', 'est', 'sol', 'cm'];
    $w = preg_split('/[^a-z0-9]+/', norm($s)); return array_values(array_diff(array_filter($w, fn($x) => mb_strlen($x) > 2), $stop));
}

/** Retourne [ligne_id|null, score 0-100, raison] */
function apparier(array $ligne, string $contexte, array $catalogue, array $memoire): array {
    $texte = $ligne['libelle']; $plein = $contexte . ' ' . $texte;
    // Mémoire : d'abord la même ligne dans le même contexte (ex. même libellé sous « 10 à 20 m »), sinon le libellé seul (anciennes validations)
    foreach ([cle_memoire($texte, $contexte), norm($texte)] as $cle) {
        if (!array_key_exists($cle, $memoire)) continue;
        if ($memoire[$cle] === null) return [null, 0, 'Marquée « sans prestation » lors d\'un import précédent'];
        return [$memoire[$cle], 100, 'Déjà validé précédemment'];
    }
    $fam = detecter_famille($texte) ?? detecter_famille($contexte);
    $h = detecter_hauteur($texte) ?: detecter_hauteur($contexte);
    if (str_contains(norm($plein), 'cypres')) { $tt = norm($texte); $h = preg_match('/(superieur|plus de|>) ?10/', $tt) ? '10-20' : '<10'; }
    $d = detecter_houppier($texte) ?: detecter_houppier($contexte);
    $c = detecter_circonference($texte) ?: detecter_circonference($contexte);
    $mots = mots_cles($plein);
    $best = null; $bestScore = 0; $raison = '';
    foreach ($catalogue as $cat) {
        $score = 0;
        if ($fam && $cat['famille'] === $fam) $score += 40; elseif ($fam && $cat['famille'] !== $fam) $score -= 30;
        if ($h !== '') $score += $cat['hauteur'] === $h ? 20 : ($cat['hauteur'] === '' ? -5 : -25);
        elseif ($cat['hauteur'] !== '' && $d === '' && $c === '') $score -= 8;
        if ($d !== '') $score += $cat['houppier'] === $d ? 20 : ($cat['houppier'] === '' ? -5 : -25);
        elseif ($cat['houppier'] !== '') $score -= 8;
        if ($c !== '') $score += $cat['circonference'] === $c ? 25 : ($cat['circonference'] === '' ? -5 : -30);
        elseif ($cat['circonference'] !== '') $score -= 10;
        // mots communs
        $mc = mots_cles($cat['libelle'] . ' ' . $cat['famille']); $commun = count(array_intersect($mots, $mc));
        $score += min(20, $commun * 4);
        // attributs discriminants : créneau horaire, tonnage, statique/dynamique, méthode de dessouchage, cyprès, type de signalisation, pièges
        foreach (attributs($plein) as $a) { $ca = attributs($cat['libelle']); if (in_array($a, $ca)) $score += 18; elseif ($ca) $score -= 18; }
        if ($score > $bestScore) { $bestScore = $score; $best = $cat; }
    }
    if (!$best || $bestScore < 25) return [null, max(0, $bestScore), $fam ? "Famille « $fam » reconnue, mais aucune classe ne correspond" : 'Aucune prestation proche'];
    $raison = ($fam ? "Famille $fam" : '') . ($h ? ", hauteur $h" : '') . ($d ? ", houppier $d" : '') . ($c ? ", circonférence $c" : '');
    return [(int)$best['id'], min(100, $bestScore), trim($raison, ', ')];
}
/** Clé de mémoire d'appariement : libellé + contexte (rubrique, classe de taille) */
function cle_memoire(string $libelle, string $contexte): string { $l = norm($libelle); if ($l === '') return ''; $c = norm($contexte); return mb_substr($c !== '' ? $c . ' | ' . $l : $l, 0, 250); }
