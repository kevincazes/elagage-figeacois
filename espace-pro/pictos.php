<?php
// Pictogrammes de chantier : icônes vectorielles (trait, couleur héritée). Nom d'icône utilisé dans le réglage « code|icône|libellé|action|délai ».
declare(strict_types=1);
const PICTO_ICONES = [
    'plant'     => '<path d="M12 21v-8"/><path d="M12 13c-4 0-6-3-6-7 4 0 6 2 6 7z"/><path d="M12 13c4 0 6-3 6-7-4 0-6 2-6 7z"/><path d="M7 21h10"/>',
    'cone'      => '<path d="M9 4h6l4 16H5z"/><path d="M7.5 10h9"/><path d="M6 15h12"/><path d="M3 20h18"/>',
    'box'       => '<path d="M3 8l9-4 9 4-9 4z"/><path d="M3 8v8l9 4 9-4V8"/><path d="M12 12v8"/>',
    'crane'     => '<path d="M4 21h8"/><path d="M8 21V9"/><path d="M8 9l10-5"/><path d="M18 4v6"/><path d="M15 10h6"/><path d="M16 10v3h4v-3"/><path d="M5 9h6"/>',
    'log'       => '<ellipse cx="6" cy="12" rx="3" ry="6"/><path d="M6 6h12c1.7 0 3 2.7 3 6s-1.3 6-3 6H6"/><ellipse cx="6" cy="12" rx="1.2" ry="2.5"/>',
    'bolt'      => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
    'house'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    'key'       => '<circle cx="8" cy="14" r="4"/><path d="M11 11l9-9"/><path d="M16 6l3 3"/><path d="M13 9l3 3"/>',
    'bird'      => '<path d="M3 14c4 0 7-3 9-7 1-2 3-3 5-3 0 2-1 3-2 4h6l-3 2c-1 5-5 8-11 8-2 0-3-1-4-4z"/><path d="M9 18v3"/>',
    'stump'     => '<path d="M5 9c0-2 3-3 7-3s7 1 7 3"/><path d="M5 9v9c0 2 3 3 7 3s7-1 7-3V9"/><ellipse cx="12" cy="9" rx="7" ry="3"/><circle cx="12" cy="9" r="1.5"/>',
    'kleyk'     => '<rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M8.5 11.5l2.3 2.3 4.7-4.7"/><path d="M10.5 18.5h3"/>',
    'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2"/><path d="M8 10h8"/><path d="M8 14h8"/><path d="M8 18h5"/>',
    'tree'      => '<path d="M9 15H7.5a4 4 0 0 1-.5-8 5.5 5.5 0 0 1 10-1.5A4.2 4.2 0 0 1 16.5 15H15"/><path d="M12 11v10"/><path d="M9 21h6"/>',
    'truck'     => '<path d="M2 7h11v9H2z"/><path d="M13 10h5l3 3v3h-8z"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    'warning'   => '<path d="M12 3l10 18H2z"/><path d="M12 10v4"/><path d="M12 17v.5"/>',
    'water'     => '<path d="M12 3s6 7 6 11a6 6 0 0 1-12 0c0-4 6-11 6-11z"/>',
    'phone'     => '<path d="M5 3h4l2 5-2 1a11 11 0 0 0 6 6l1-2 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
    'saw'       => '<path d="M3 10h13l4 2-4 2H3z"/><path d="M5 14l1 3 2-3"/><path d="M9 14l1 3 2-3"/><path d="M13 14l1 3 2-3"/><rect x="3" y="7" width="6" height="3" rx="1"/>',
    'route'     => '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h6a4 4 0 0 0 0-8H10a4 4 0 0 1 0-8h6"/>',
    'car'       => '<path d="M5 11l2-5h10l2 5"/><path d="M3 11h18v6h-2"/><path d="M3 17h2"/><path d="M7 17h10"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
    'eye'       => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'chat'      => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8"/><path d="M8 12h5"/>',
    'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'camera'    => '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
    'doc'       => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 13h6"/><path d="M9 17h6"/>',
    'pass'      => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/><circle cx="16.5" cy="16.5" r="1.5"/>',
    'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20a7 7 0 0 1 14 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14a5 5 0 0 1 6 5"/>',
    'pin'       => '<path d="M12 21s-6-6.5-6-11a6 6 0 0 1 12 0c0 4.5-6 11-6 11z"/><circle cx="12" cy="10" r="2.5"/>',
    'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'machine'   => '<path d="M3 15h4V9h6l3 6h5v4H3z"/><circle cx="7" cy="19" r="2"/><circle cx="17" cy="19" r="2"/><path d="M7 9V5h4"/>',
    'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
    'upload'    => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 20h16"/>',
    'plus'      => '<path d="M12 5v14"/><path d="M5 12h14"/>',
    'vent'      => '<path d="M3 8h11a3 3 0 1 0-3-3"/><path d="M3 12h16a3 3 0 1 1-3 3"/><path d="M3 16h7"/>',
    'pluie'     => '<path d="M7 15a5 5 0 1 1 1.6-9.7A6 6 0 0 1 20 9a4 4 0 0 1-1 6"/><path d="M8 18l-1 3"/><path d="M12 18l-1 3"/><path d="M16 18l-1 3"/>',
    'cadenas'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'bell'      => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
    'megaphone' => '<path d="M3 10v4h3l8 5V5l-8 5z"/><path d="M17 9a4 4 0 0 1 0 6"/><path d="M6 14l1 6h3l-1-6"/>',
    'mic'       => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/><path d="M9 21h6"/>',
    'speaker'   => '<path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M16 9a4 4 0 0 1 0 6"/><path d="M18.5 6.5a7.5 7.5 0 0 1 0 11"/>',
    'stop'      => '<rect x="6" y="6" width="12" height="12" rx="2"/>',
    'crayon'    => '<path d="M4 20l1-5L16 4l4 4L9 19z"/><path d="M14 6l4 4"/>',
    'magie'     => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
    'graphe'    => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-7"/>',
    'reglage'   => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1L7 17M17 7l2.1-2.1"/>',
    'livre'     => '<path d="M4 5a2 2 0 0 1 2-2h12v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/><path d="M8 7h6"/>',
    'lien'      => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    'euro'      => '<path d="M17 6a7 7 0 1 0 0 12"/><path d="M4 10h9"/><path d="M4 14h9"/>',
    'personne'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'sortie'    => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>',
    'chevron'   => '<path d="M9 6l6 6-6 6"/>',
    'fermer'    => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
    'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>',
    'aide'      => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7"/><path d="M12 17h.01"/>',
    'ladder'    => '<path d="M7 3v18"/><path d="M17 3v18"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h10"/>',
];
/** Feuille de symboles à insérer une fois dans la page */
function pictos_sprite(): string {
    $o = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">';
    foreach (PICTO_ICONES as $n => $d) $o .= '<symbol id="pi-' . $n . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</symbol>';
    return $o . '</svg>';
}
/** HTML d'un pictogramme : icône vectorielle si le nom est connu, sinon le texte (ancien emoji) */
function picto_html(string $icone, string $titre = ''): string {
    if (isset(PICTO_ICONES[$icone])) return '<svg class="pi"' . ($titre !== '' ? ' aria-label="' . e($titre) . '"' : '') . '><use href="#pi-' . $icone . '"/></svg>';
    return '<span class="pi-txt">' . e($icone) . '</span>';
}
