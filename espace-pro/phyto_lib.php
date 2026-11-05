<?php
// Chancre coloré du platane : registre de traçabilité des chantiers « platane » (modèle DRAAF) et passeport phytosanitaire pour le transport du bois
declare(strict_types=1);

function phyto_migrate(): void {
    $fait = setting('schema_phyto') === '1';
    if (!$fait) db()->exec("CREATE TABLE IF NOT EXISTS phyto_chantiers (evenement_id INT PRIMARY KEY, travaux VARCHAR(200) NOT NULL DEFAULT '', surv_avant VARCHAR(200) NOT NULL DEFAULT '', surv_pendant VARCHAR(200) NOT NULL DEFAULT '',
        code_c VARCHAR(40) NOT NULL DEFAULT '', destination VARCHAR(200) NOT NULL DEFAULT '', usage_bois VARCHAR(120) NOT NULL DEFAULT '', volume_m3 DECIMAL(8,2) NULL, desinfection TINYINT NOT NULL DEFAULT 0, notes VARCHAR(500) NOT NULL DEFAULT '',
        maj_le DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (["ALTER TABLE phyto_chantiers ADD COLUMN desinf_entree TINYINT NOT NULL DEFAULT 0", "ALTER TABLE phyto_chantiers ADD COLUMN desinf_sortie TINYINT NOT NULL DEFAULT 0", "ALTER TABLE phyto_chantiers ADD COLUMN desinf_arbres TINYINT NOT NULL DEFAULT 0", "ALTER TABLE phyto_chantiers ADD COLUMN produit VARCHAR(60) NOT NULL DEFAULT ''", "ALTER TABLE phyto_chantiers ADD COLUMN griffes TINYINT NOT NULL DEFAULT 0", "ALTER TABLE phyto_chantiers ADD COLUMN declaration_le DATE NULL", "ALTER TABLE phyto_chantiers ADD COLUMN nb_arbres INT NULL", "ALTER TABLE phyto_chantiers ADD COLUMN transport TINYINT NOT NULL DEFAULT 1", "ALTER TABLE phyto_chantiers ADD COLUMN passeport_num VARCHAR(20) NOT NULL DEFAULT ''", "ALTER TABLE phyto_chantiers ADD COLUMN verrou_le DATETIME NULL", "ALTER TABLE phyto_chantiers ADD COLUMN pp_b VARCHAR(40) NOT NULL DEFAULT ''", "ALTER TABLE phyto_chantiers ADD COLUMN pp_c VARCHAR(40) NOT NULL DEFAULT ''"] as $sql) { try { db()->exec($sql); } catch (Throwable $t) {} } // colonnes ajoutées après coup : toujours tentées
    if ($fait) return;
    if (setting('phyto_inupp') === '') set_setting('phyto_inupp', 'FR-0460072V');
    if (setting('phyto_produit') === '') set_setting('phyto_produit', 'Sitonal');
    set_setting('schema_phyto', '1');
}
const PHYTO_SURV = ['' => '—', 'rien' => 'Aucun symptôme observé', 'suspicion' => 'Symptômes suspects (signalés)', 'na' => 'Non concerné'];
const PHYTO_USAGES = ['' => '—', 'broyage' => 'Broyage (plaquettes / paillage)', 'chauffage' => 'Bois de chauffage', 'incineration' => 'Incinération / destruction', 'laisse' => 'Laissé sur place au propriétaire', 'decheterie' => 'Déchèterie / plateforme verte'];
/** Code C de traçabilité : le numéro de devis (sans espaces ni tirets), sinon EF + n° de fiche */
function phyto_code(array $e, string $force = ''): string { if ($force !== '') return $force; $d = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$e['devis_ref'])); return $d !== '' ? $d : 'EF' . str_pad((string)$e['id'], 6, '0', STR_PAD_LEFT); }
function phyto_charger(int $evtId): array {
    $p = q("SELECT * FROM phyto_chantiers WHERE evenement_id = ?", [$evtId])->fetch();
    return $p ?: ['evenement_id' => $evtId, 'travaux' => '', 'surv_avant' => '', 'surv_pendant' => '', 'code_c' => '', 'destination' => '', 'usage_bois' => '', 'volume_m3' => null, 'desinfection' => 0, 'notes' => '', 'desinf_entree' => 0, 'desinf_sortie' => 0, 'desinf_arbres' => 0, 'produit' => setting('phyto_produit', 'Sitonal'), 'griffes' => 0, 'declaration_le' => null, 'nb_arbres' => null, 'transport' => 1, 'passeport_num' => '', 'verrou_le' => null, 'pp_b' => '', 'pp_c' => ''];
}
function phyto_verrouille(int $evtId): bool { return (bool)(q("SELECT verrou_le FROM phyto_chantiers WHERE evenement_id = ?", [$evtId])->fetch()['verrou_le'] ?? null); }
/** Numéro de passeport (PP-AAAA-NNN), attribué une fois pour toutes au chantier ; le code C reste le numéro de devis */
function phyto_numero(int $evtId): string {
    $p = q("SELECT passeport_num FROM phyto_chantiers WHERE evenement_id = ?", [$evtId])->fetch(); if (!$p) return '';
    if ($p['passeport_num'] !== '') return $p['passeport_num'];
    $an = date('Y'); $n = (int)q("SELECT COUNT(*) c FROM phyto_chantiers WHERE passeport_num LIKE ?", ["PP-$an-%"])->fetch()['c'] + 1;
    $num = sprintf('PP-%s-%03d', $an, $n); q("UPDATE phyto_chantiers SET passeport_num = ? WHERE evenement_id = ? AND passeport_num = ''", [$num, $evtId]);
    return q("SELECT passeport_num FROM phyto_chantiers WHERE evenement_id = ?", [$evtId])->fetch()['passeport_num'];
}
/** Chantier terminé : la fiche platane et le passeport sont figés (codes B et C mémorisés tels qu'ils étaient) */
function phyto_verrouiller(int $evtId): void {
    $e = evt_charger($evtId); $p = q("SELECT * FROM phyto_chantiers WHERE evenement_id = ?", [$evtId])->fetch(); if (!$e || !$p || $p['verrou_le']) return;
    $num = phyto_numero($evtId); $pp = phyto_passeport($e, $p['code_c']);
    q("UPDATE phyto_chantiers SET verrou_le = NOW(), pp_b = ?, pp_c = ?, code_c = ?, passeport_num = ? WHERE evenement_id = ?", [$pp['B'], $pp['C'], $pp['C'], $num, $evtId]);
    evt_journal($evtId, "Fiche platane verrouillée avec le passeport $num (code C {$pp['C']})");
}
function phyto_enregistrer(int $evtId, array $in): void {
    if (phyto_verrouille($evtId)) return; // chantier terminé : fiche figée
    $dt = fn($v) => ($v = trim((string)$v)) !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
    $v = ['travaux' => mb_substr(trim((string)($in['travaux'] ?? '')), 0, 200), 'surv_avant' => array_key_exists($x = (string)($in['surv_avant'] ?? ''), PHYTO_SURV) ? $x : '', 'surv_pendant' => array_key_exists($x = (string)($in['surv_pendant'] ?? ''), PHYTO_SURV) ? $x : '',
          'code_c' => strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['code_c'] ?? '')), 0, 40)), 'destination' => mb_substr(trim((string)($in['destination'] ?? '')), 0, 200), 'usage_bois' => array_key_exists($x = (string)($in['usage_bois'] ?? ''), PHYTO_USAGES) ? $x : '',
          'volume_m3' => ($in['volume_m3'] ?? '') === '' || $in['volume_m3'] === null ? null : nb($in['volume_m3']), 'desinfection' => !empty($in['desinf_entree']) && !empty($in['desinf_sortie']) ? 1 : 0, 'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500),
          'desinf_entree' => !empty($in['desinf_entree']) ? 1 : 0, 'desinf_sortie' => !empty($in['desinf_sortie']) ? 1 : 0, 'desinf_arbres' => !empty($in['desinf_arbres']) ? 1 : 0, 'produit' => mb_substr(trim((string)($in['produit'] ?? '')), 0, 60), 'griffes' => !empty($in['griffes']) ? 1 : 0,
          'declaration_le' => $dt($in['declaration_le'] ?? ''), 'nb_arbres' => ($in['nb_arbres'] ?? '') === '' || $in['nb_arbres'] === null ? null : (int)$in['nb_arbres'], 'transport' => isset($in['transport']) && empty($in['transport']) ? 0 : 1];
    $cols = array_keys($v);
    q("INSERT INTO phyto_chantiers (evenement_id, " . implode(', ', $cols) . ") VALUES (?" . str_repeat(',?', count($cols)) . ") ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols)), array_merge([$evtId], array_values($v)));
}
/** Communes en zone délimitée (réglage, une par ligne) : la commune du chantier y est-elle ? */
function phyto_zone_delimitee(string $ville, string $cp = ''): bool {
    $n = fn($s) => strtoupper(trim(preg_replace('/[\s\-\']+/', ' ', (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $s))));
    foreach (preg_split('/\R/', setting('phyto_zones')) as $l) { $l = trim($l); if ($l === '') continue; if ($n($l) === $n($ville) || ($cp !== '' && $l === $cp)) return true; }
    return false;
}
/** Alertes réglementaires d'un chantier platane (pour evt_conflits) */
function phyto_alertes(array $e): array {
    if (($e['type'] ?? '') !== 'chantier' || empty($e['phyto'])) return [];
    $al = [];
    if (phyto_zone_delimitee((string)($e['ville'] ?? ''), (string)($e['cp'] ?? ''))) {
        $p = phyto_charger((int)($e['id'] ?? 0));
        if (!$p['declaration_le']) $al[] = 'Platane en zone délimitée : déclaration préalable à la DRAAF obligatoire au moins 15 jours ouvrés avant le chantier (art. 19), et désinfection entre chaque arbre.';
        elseif (!empty($e['debut']) && count(jours_ouvres($p['declaration_le'], 16)) && jours_ouvres($p['declaration_le'], 16)[15] > substr($e['debut'], 0, 10)) $al[] = 'Platane en zone délimitée : moins de 15 jours ouvrés entre la déclaration DRAAF et le chantier.';
        if ((int)$p['transport']) $al[] = 'Platane en zone délimitée : le bois ne circule pas avec un passeport simple (incinération sur place ou dérogation préfectorale).';
    }
    return $al;
}
/** Colonnes disponibles pour le registre et les exports (clé => [en-tête DRAAF, fonction de valeur]) */
function phyto_colonnes(): array {
    return [
        'proprietaire' => ["Propriétaire des arbres\nNom Prénom/Société", fn($e) => $e['client_nom']],
        'coordonnees' => ["Coordonnées du propriétaire\nTéléphone/Mail", fn($e) => trim($e['client_tel'] . ' ' . $e['client_email'])],
        'du' => ["Date réalisation du chantier\nDu", fn($e) => $e['debut'] ? date('d/m/Y', strtotime($e['debut'])) : ''],
        'au' => ["Au", fn($e) => $e['fin'] ? date('d/m/Y', strtotime($e['fin']) - 1) : ''],
        'travaux' => ["Type de travaux (abattage, élagage, ramassage bois mort,…)", fn($e) => $e['travaux']],
        'nb_arbres' => ["Nombre de platanes", fn($e) => $e['nb_arbres'] !== null ? (int)$e['nb_arbres'] : ''],
        'localisation' => ["Localisation du chantier adresse ou coordonnées GPS", fn($e) => trim($e['adresse'] . ' ' . $e['cp'] . ' ' . $e['ville']) . ($e['lat'] !== null ? " ({$e['lat']}, {$e['lng']})" : '')],
        'surv_avant' => ["Surveillance phytosanitaire visuelle\nAvant chantier", fn($e) => PHYTO_SURV[$e['surv_avant']] ?? ''],
        'surv_pendant' => ["Surveillance phytosanitaire visuelle\nEn cours de chantier", fn($e) => PHYTO_SURV[$e['surv_pendant']] ?? ''],
        'code_c' => ["Code de traçabilité C\npour le passeport phytosanitaire", fn($e) => $e['code_c']],
        'passeport' => ["N° de passeport", fn($e) => $e['passeport_num']],
        'verrou' => ["Fiche verrouillée le", fn($e) => $e['verrou_le'] ? date('d/m/Y', strtotime($e['verrou_le'])) : ''],
        'destination' => ["Destination géographique du bois de coupe", fn($e) => $e['destination']],
        'usage' => ["Usage du bois de coupe", fn($e) => PHYTO_USAGES[$e['usage_bois']] ?? ''],
        'volume' => ["Estimation du volume du bois coupé (m3)", fn($e) => $e['volume_m3'] !== null ? (float)$e['volume_m3'] : ''],
        'desinfection' => ["Désinfection des outils et engins (entrée / sortie du site)", fn($e) => ($e['desinf_entree'] && $e['desinf_sortie'] ? 'Oui' : 'Non') . ($e['produit'] ? ' – ' . $e['produit'] : '') . ($e['desinf_arbres'] ? ' – entre chaque arbre' : '')],
        'griffes' => ["Griffes / crampons", fn($e) => $e['griffes'] ? 'Oui (démontage)' : 'Non'],
        'transport' => ["Bois transporté avec passeport", fn($e) => $e['transport'] ? 'Oui' : 'Non'],
        'declaration' => ["Déclaration préalable DRAAF (zone délimitée)", fn($e) => $e['declaration_le'] ? date('d/m/Y', strtotime($e['declaration_le'])) : ''],
        'equipe' => ["Intervenants", fn($e) => implode(', ', array_map(fn($p) => $p['prenom'] . ' ' . $p['nom'], array_filter(personnel_liste(true), fn($p) => in_array((int)$p['id'], $e['personnel'], true))))],
        'devis' => ["Devis", fn($e) => $e['devis_ref']],
        'notes' => ["Observations", fn($e) => $e['notes']],
    ];
}
const PHYTO_COLS_DRAAF = ['proprietaire', 'coordonnees', 'du', 'au', 'travaux', 'localisation', 'surv_avant', 'surv_pendant', 'code_c', 'destination', 'usage', 'volume'];
/** Registre : chantiers platane entre deux dates (incluses), avec leur fiche */
function phyto_registre(string $du, string $au, string $client = ''): array {
    $l = q("SELECT e.*, p.travaux, p.surv_avant, p.surv_pendant, p.code_c, p.destination, p.usage_bois, p.volume_m3, p.desinfection, p.desinf_entree, p.desinf_sortie, p.desinf_arbres, p.produit, p.griffes, p.declaration_le, p.nb_arbres, p.transport, p.notes pnotes, p.passeport_num, p.verrou_le, p.pp_b, p.pp_c
           FROM evenements e JOIN phyto_chantiers p ON p.evenement_id = e.id WHERE e.statut <> 'annule' AND COALESCE(DATE(e.debut), DATE(e.cree_le)) BETWEEN ? AND ?" . ($client !== '' ? " AND e.client_nom LIKE ?" : '') . " ORDER BY e.debut", array_merge([$du, $au], $client !== '' ? ["%$client%"] : []))->fetchAll();
    foreach ($l as &$e) { $e['code_c'] = $e['pp_c'] !== '' ? $e['pp_c'] : phyto_code($e, $e['code_c']); $e['notes'] = $e['pnotes']; $e['personnel'] = array_map('intval', array_column(q("SELECT personnel_id FROM evenement_personnel WHERE evenement_id = ?", [$e['id']])->fetchAll(), 'personnel_id')); } unset($e);
    return $l;
}
/** Passeport phytosanitaire d'un chantier : [A, B, C, D] */
function phyto_passeport(array $e, string $codeC = ''): array {
    $p = q("SELECT pp_b, pp_c, passeport_num FROM phyto_chantiers WHERE evenement_id = ?", [(int)($e['id'] ?? 0)])->fetch() ?: [];
    return ['A' => 'Platanus sp.', 'B' => ($p['pp_b'] ?? '') !== '' ? $p['pp_b'] : setting('phyto_inupp', 'FR-0460072V'), 'C' => ($p['pp_c'] ?? '') !== '' ? $p['pp_c'] : phyto_code($e, $codeC), 'D' => 'FR', 'num' => $p['passeport_num'] ?? ''];
}
/** Passeport en PDF (format carte à imprimer, deux exemplaires par page) */
function phyto_pdf(array $e, string $codeC = ''): string {
    if (($e['id'] ?? 0) && !phyto_verrouille((int)$e['id'])) phyto_numero((int)$e['id']);
    $pp = phyto_passeport($e, $codeC);
    $p = new FPDF('P', 'mm', 'A4'); $p->SetMargins(20, 20, 20); $p->SetAutoPageBreak(false); $p->AddPage();
    $drapeau = __DIR__ . '/phyto-ue.jpg';
    for ($k = 0; $k < 2; $k++) {
        $y = 25 + $k * 125;
        $p->SetDrawColor(30, 30, 30); $p->SetLineWidth(.6); $p->Rect(25, $y, 160, 100);
        if (is_file($drapeau)) try { $p->Image($drapeau, 32, $y + 8, 34); } catch (Throwable $t) {}
        $p->SetFont('Helvetica', 'B', 15); $p->SetTextColor(0, 0, 0); $p->SetXY(72, $y + 10); $p->Cell(110, 8, pdf_txt('Passeport Phytosanitaire'), 0, 1); $p->SetX(72); $p->SetFont('Helvetica', '', 13); $p->Cell(110, 7, 'Plant Passport', 0, 1);
        $p->SetFont('Helvetica', 'B', 17); $yy = $y + 38;
        foreach (['A' => $pp['A'], 'B' => $pp['B'], 'C' => $pp['C'], 'D' => $pp['D']] as $l => $v) { $p->SetXY(32, $yy); $p->Cell(14, 11, $l, 0, 0); $p->SetFont('Helvetica', '', 17); $p->Cell(120, 11, pdf_txt($v), 0, 1); $p->SetFont('Helvetica', 'B', 17); $yy += 13; }
        $p->SetFont('Helvetica', '', 8); $p->SetTextColor(90, 90, 90); $p->SetXY(32, $y + 92); $p->Cell(150, 5, pdf_txt(($pp['num'] ? 'N° ' . $pp['num'] . ' - ' : '') . setting('ent_nom') . ' - ' . $e['titre'] . ' - ' . ($e['debut'] ? date('d/m/Y', strtotime($e['debut'])) : '') . ($e['devis_ref'] ? ' - devis ' . $e['devis_ref'] : '')), 0, 0);
    }
    $p->SetFont('Helvetica', 'I', 8); $p->SetTextColor(90, 90, 90); $p->SetXY(25, 278); $p->MultiCell(160, 4, pdf_txt("A : nom botanique du bois transporté. B : numéro d'enregistrement professionnel (INUPP). C : code de traçabilité propre au chantier (numéro de devis). D : pays d'origine. Modèle DRAAF Occitanie, transport de bois de platane élagué en zone saine."));
    return $p->Output('S');
}
/** Export paramétrable du registre : colonnes choisies, période, format xlsx / csv */
function phyto_export(string $du, string $au, array $cols, string $format = 'xlsx', string $client = ''): array {
    $def = phyto_colonnes(); $cols = array_values(array_filter($cols, fn($c) => isset($def[$c]))) ?: PHYTO_COLS_DRAAF;
    $tetes = array_map(fn($c) => $def[$c][0], $cols);
    $lignes = array_map(fn($e) => array_map(fn($c) => $def[$c][1]($e), $cols), phyto_registre($du, $au, $client));
    $titre = 'Liste des chantiers « Interventions sur platane » réalisés ' . (substr($du, 0, 4) === substr($au, 0, 4) && $du === substr($du, 0, 4) . '-01-01' && $au === substr($au, 0, 4) . '-12-31' ? 'en ' . substr($du, 0, 4) : 'du ' . date('d/m/Y', strtotime($du)) . ' au ' . date('d/m/Y', strtotime($au))) . ' – ' . setting('ent_nom') . ' – ' . setting('phyto_inupp');
    $nom = 'Chantiers-platanes-' . $du . '-' . $au;
    if ($format === 'pdf') {
        $p = new FPDF('L', 'mm', 'A4'); $p->SetMargins(10, 12, 10); $p->SetAutoPageBreak(true, 12); $p->AddPage(); $p->SetFont('Helvetica', 'B', 11); $p->SetTextColor(104, 60, 17); $p->MultiCell(277, 6, pdf_txt($titre)); $p->Ln(2); $p->SetTextColor(20, 20, 20);
        $w = array_map(fn($c) => in_array($c, ['du', 'au', 'volume', 'nb_arbres', 'passeport', 'verrou', 'griffes', 'transport'], true) ? 17 : 0, $cols); $fixe = array_sum($w); $nb = count(array_filter($w, fn($x) => $x === 0)); foreach ($w as $i => $x) if ($x === 0) $w[$i] = (277 - $fixe) / max(1, $nb);
        $ligne = function (array $cells, bool $tete) use ($p, $w) { $p->SetFont('Helvetica', $tete ? 'B' : '', $tete ? 7 : 7.5); $p->SetFillColor(226, 239, 218); $h = 0; $txt = [];
            foreach (array_values($cells) as $i => $v) { $t = pdf_txt(str_replace("\n", ' ', (string)$v)); $txt[$i] = $t; $h = max($h, 3.6 * max(1, (int)ceil($p->GetStringWidth($t) / max(1, $w[$i] - 2)))); }
            if ($p->GetY() + $h > 195) $p->AddPage(); $x0 = 10; $y0 = $p->GetY();
            foreach ($txt as $i => $t) { $p->SetXY($x0, $y0); $p->Rect($x0, $y0, $w[$i], $h, $tete ? 'DF' : 'D'); $p->MultiCell($w[$i], 3.6, $t, 0, 'L'); $x0 += $w[$i]; } $p->SetXY(10, $y0 + $h); };
        $ligne($tetes, true); foreach ($lignes as $l) $ligne($l, false);
        if (!$lignes) { $p->SetFont('Helvetica', 'I', 9); $p->Cell(0, 8, pdf_txt('Aucun chantier platane sur la période.'), 0, 1); }
        $p->SetFont('Helvetica', 'I', 7); $p->SetTextColor(90, 90, 90); $p->Ln(3); $p->MultiCell(277, 4, pdf_txt('Registre établi le ' . date('d/m/Y') . ' par ' . setting('ent_nom') . ' (enregistrement ' . setting('phyto_inupp') . ')' . (setting('phyto_responsables') ? ' - responsable(s) phytosanitaire(s) : ' . setting('phyto_responsables') : '') . '. Arrêté du 31 janvier 2025 relatif à la lutte contre Ceratocystis platani.'));
        return ["$nom.pdf", 'application/pdf', $p->Output('S')];
    }
    if ($format === 'csv') { $o = fopen('php://temp', 'w+'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, [$titre], ';'); fputcsv($o, array_map(fn($t) => str_replace("\n", ' ', $t), $tetes), ';'); foreach ($lignes as $l) fputcsv($o, $l, ';'); rewind($o); return ["$nom.csv", 'text/csv; charset=utf-8', stream_get_contents($o)]; }
    return ["$nom.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', xlsx_simple($titre, $tetes, $lignes)];
}
/** Classeur Excel minimal (une feuille, titre fusionné, en-têtes en gras) sans bibliothèque */
function xlsx_simple(string $titre, array $tetes, array $lignes): string {
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = fn(int $i) => ($i >= 26 ? chr(64 + intdiv($i, 26)) : '') . chr(65 + $i % 26);
    $n = count($tetes); $rows = '<row r="1"><c r="A1" t="inlineStr" s="1"><is><t>' . $x($titre) . '</t></is></c></row><row r="2">';
    foreach ($tetes as $i => $t) $rows .= '<c r="' . $col($i) . '2" t="inlineStr" s="2"><is><t>' . $x($t) . '</t></is></c>'; $rows .= '</row>';
    foreach ($lignes as $r => $cells) { $rows .= '<row r="' . ($r + 3) . '">'; foreach ($cells as $i => $v) $rows .= is_float($v) || is_int($v) ? '<c r="' . $col($i) . ($r + 3) . '" s="3"><v>' . $v . '</v></c>' : '<c r="' . $col($i) . ($r + 3) . '" t="inlineStr" s="3"><is><t>' . $x($v) . '</t></is></c>'; $rows .= '</row>'; }
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>' . implode('', array_map(fn($i) => '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="24" customWidth="1"/>', array_keys($tetes))) . '</cols><sheetData>' . $rows . '</sheetData><mergeCells count="1"><mergeCell ref="A1:' . $col($n - 1) . '1"/></mergeCells></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/></border><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders><cellXfs count="4"><xf/><xf fontId="1"/><xf fontId="1" fillId="2" borderId="1" applyAlignment="1"><alignment wrapText="1" vertical="center" horizontal="center"/></xf><xf borderId="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs></styleSheet>';
    $tmp = tempnam(sys_get_temp_dir(), 'xl'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Chantiers platanes" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet); $z->addFromString('xl/styles.xml', $styles); $z->close();
    $d = file_get_contents($tmp); @unlink($tmp); return $d;
}
