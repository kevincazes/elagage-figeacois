<?php
// Lecture d'un bordereau reçu (xlsx, docx, pdf texte, csv) → liste de lignes brutes
// Chaque ligne : ['numero' => '', 'libelle' => '', 'unite' => '', 'quantite' => null, 'prix' => null, 'section' => bool]
declare(strict_types=1);

function lire_fichier(string $chemin, string $nomOriginal): array {
    $ext = strtolower(pathinfo($nomOriginal, PATHINFO_EXTENSION));
    $tableaux = match ($ext) {
        'xlsx', 'xlsm' => lire_xlsx($chemin),
        'docx' => lire_docx($chemin),
        'csv', 'txt' => lire_csv($chemin),
        'pdf' => lire_pdf($chemin),
        default => throw new RuntimeException("Format non pris en charge : .$ext (xlsx, docx, pdf ou csv)"),
    };
    return interpreter_lignes($tableaux, $ext);
}

// ---- XLSX : toutes les feuilles, cellules en texte ----
function lire_xlsx(string $f): array {
    $z = new ZipArchive(); if ($z->open($f) !== true) throw new RuntimeException('Fichier Excel illisible.');
    $shared = [];
    if (($xml = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml); foreach ($sx->si as $si) { $t = ''; foreach ($si->xpath('.//*[local-name()="t"]') as $tt) $t .= (string)$tt; $shared[] = $t; }
    }
    $rows = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i); if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) continue;
        $sx = simplexml_load_string($z->getFromIndex($i)); if (!$sx) continue;
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r']; preg_match('/^([A-Z]+)/', $ref, $m); $col = $m[1] ?? 'A';
                $t = (string)$c['t']; $v = isset($c->v) ? (string)$c->v : '';
                if ($t === 's') $v = $shared[(int)$v] ?? ''; elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
                $idx = 0; foreach (str_split($col) as $ch) $idx = $idx * 26 + (ord($ch) - 64);
                $cells[$idx - 1] = trim($v);
            }
            if (array_filter($cells, fn($x) => $x !== '')) { ksort($cells); $rows[] = $cells; }
        }
        $rows[] = ['__FEUILLE__'];
    }
    $z->close(); return $rows;
}

// ---- DOCX : tableaux Word, une ligne par <w:tr> ----
function lire_docx(string $f): array {
    $z = new ZipArchive(); if ($z->open($f) !== true) throw new RuntimeException('Fichier Word illisible.');
    $xml = $z->getFromName('word/document.xml'); $z->close(); if (!$xml) throw new RuntimeException('Document Word vide.');
    $d = new DOMDocument(); $d->loadXML($xml); $x = new DOMXPath($d); $x->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $rows = [];
    // Paragraphes hors tableau (titres, en-têtes de colonnes) → une pseudo-ligne d'en-tête pour la détection prix/quantité
    $hors = []; foreach ($x->query('//w:body/w:p') as $p) { $t = trim($p->textContent); if ($t !== '') $hors[] = $t; }
    if ($hors) $rows[] = ['ENTETE: ' . implode(' ', array_slice($hors, 0, 30))];
    foreach ($x->query('//w:tbl') as $tbl) { foreach ($x->query('./w:tr', $tbl) as $tr) {
        $cells = [];
        foreach ($x->query('./w:tc', $tr) as $tc) {
            $t = trim(preg_replace('/\s+/', ' ', implode(' ', array_map(fn($p) => $p->textContent, iterator_to_array($x->query('./w:p', $tc))))));
            // Première ligne de la cellule = libellé ; le reste est descriptif
            $first = trim(explode("\n", trim(implode("\n", array_map(fn($p) => $p->textContent, iterator_to_array($x->query('./w:p', $tc))))))[0]);
            $val = $first !== '' ? $first : $t;
            $span = 1; foreach ($x->query('./w:tcPr/w:gridSpan', $tc) as $g) $span = max(1, (int)$g->getAttribute('w:val'));
            $cells[] = $val; for ($k = 1; $k < $span; $k++) $cells[] = '';
        }
        if (array_filter($cells, fn($v) => $v !== '')) $rows[] = $cells;
    } $rows[] = ['__FEUILLE__']; }
    return $rows;
}

function lire_csv(string $f): array {
    $rows = []; $h = fopen($f, 'r'); $sep = ';';
    $first = fgets($h); rewind($h); if ($first && substr_count($first, ',') > substr_count($first, ';')) $sep = ',';
    while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) { $r = array_map(fn($v) => trim((string)$v), $r); if (array_filter($r, fn($x) => $x !== '')) $rows[] = $r; }
    fclose($h); return $rows;
}

// ---- PDF texte : extraction minimale (pdftotext si disponible, sinon flux texte) ----
function lire_pdf(string $f): array {
    $txt = '';
    if (function_exists('shell_exec') && trim((string)shell_exec('command -v pdftotext')) !== '') $txt = (string)shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
    if ($txt === '') {
        $raw = file_get_contents($f);
        if (preg_match_all('/\((.*?)(?<!\\\\)\)\s*Tj/s', $raw, $m)) $txt = implode("\n", $m[1]);
    }
    if (trim($txt) === '') throw new RuntimeException('PDF sans texte exploitable (scan ?). Utilise le fichier Excel ou Word d\'origine.');
    $rows = [];
    foreach (preg_split('/\r?\n/', $txt) as $l) { $l = trim(preg_replace('/\s{2,}/', "\t", $l)); if ($l !== '') $rows[] = explode("\t", $l); }
    return $rows;
}

// ---- Interprétation : repère numéro, libellé, unité, quantité, prix ; détecte les sections ----
/** Rôle d'un intitulé de colonne (numero, code, libelle, unite, quantite, prix, montant) ou null */
function role_colonne(string $c): ?string {
    $c = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $c)));
    if ($c === '' || mb_strlen($c) > 60) return null;
    if (preg_match('/^(n°|n °|no\b|n\.|num[ée]ro|n° prix|prix n°|art(\.|icle)|rep[èe]re|poste|item)/u', $c)) return 'numero';
    if (preg_match('/^(code|r[ée]f(\.|[ée]rence)?\b)/u', $c)) return 'code';
    if (preg_match('/^(d[ée]signation|libell[ée]|intitul[ée]|description|nature des|prestations?$)/u', $c)) return 'libelle';
    if (preg_match('/(montant|total)/u', $c)) return 'montant';
    if (preg_match('/(quantit|^qt[ée]s?\.?$|^q\.?$|^nombre|^nb\b)/u', $c)) return 'quantite';
    if (preg_match('/^(unit[ée]s?( de mesure)?|u\.?|ud)$/u', $c)) return 'unite';
    if (preg_match('/(prix|^p\.?\s?u\.?\b|^pu\b|tarif|€)/u', $c)) return 'prix';
    return null;
}
/** Nombre écrit dans une cellule (« 1 234,50 € ») ou null */
function nombre_cellule(string $c): ?float {
    $n = str_replace([' ', "\xc2\xa0", "\xe2\x80\xaf", '€'], '', $c); $n = str_replace(',', '.', $n);
    return is_numeric($n) ? (float)$n : null;
}
function interpreter_lignes(array $rows, string $ext = ''): array {
    if ($ext !== 'pdf') {
        // 1. Lecture par en-têtes : une ligne « N° | Désignation | Unité | Quantité | Prix… » fixe le rôle de chaque colonne,
        //    quel que soit leur ordre ; elle peut changer d'une feuille ou d'un tableau à l'autre (en-têtes répétés).
        $out = []; $carte = null; $trouve = false; $hors = [];
        foreach ($rows as $r) {
            if (($r[0] ?? '') === '__FEUILLE__' && count($r) === 1) { $carte = null; continue; }
            $roles = []; $nb = 0;
            foreach ($r as $i => $c) { if ($c === '') continue; $nb++; $ro = role_colonne((string)$c); if ($ro && !in_array($ro, $roles, true)) $roles[$i] = $ro; }
            if (in_array('libelle', $roles, true) && count(array_intersect($roles, ['unite', 'quantite', 'prix'])) >= 1 && count($roles) >= $nb - 1) { $carte = array_flip($roles); $trouve = true; continue; }
            if (!$carte) { $hors[] = $r; continue; }
            $val = fn($role) => isset($carte[$role]) ? trim((string)($r[$carte[$role]] ?? '')) : '';
            $remplies = array_filter($r, fn($c) => $c !== '');
            $libelle = $val('libelle'); $numero = rtrim($val('numero'), '.'); $code = $val('code');
            // Ligne réduite à un seul texte (titre de rubrique écrit dans la colonne N°, mention…) : ancienne méthode sur ce texte
            if ($libelle === '' && count($remplies) === 1) { foreach (interpreter_lignes([array_values($remplies)], 'pdf') as $x) $out[] = $x; continue; }
            if ($libelle === '') { $autres = array_filter($remplies, fn($c, $i) => !in_array($i, $carte, true) && nombre_cellule((string)$c) === null, ARRAY_FILTER_USE_BOTH); $libelle = (string)(reset($autres) ?: ''); }
            if ($libelle === '' || role_colonne($libelle) === 'libelle') continue; // en-tête répété
            $unite = trim(preg_replace('/\s+/', ' ', $val('unite')));
            $qte = $val('quantite') !== '' ? nombre_cellule($val('quantite')) : null;
            $prix = $val('prix') !== '' ? nombre_cellule($val('prix')) : null;
            if (str_contains($libelle, "\n")) $libelle = trim(explode("\n", $libelle)[0]);
            $libelle = trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $libelle));
            if (mb_strlen($libelle) < 3 || preg_match('/^(montant|tva|total|accord-cadre|bordereau de|identification du|date,? (signature|cachet)|l\'entrepreneur|chaque poste|les prix|nota|le classement|\(.*du cctp\)$)/iu', $libelle) || preg_match('/^\(.*\)$/u', $libelle)) continue;
            $section = ($unite === '' && $prix === null && $qte === null) && (mb_strtoupper($libelle) === $libelle || preg_match('/^(\d+(\.0)?|[IVX]+(\.\d+)?)$/', $numero) || mb_strlen($libelle) < 60 && preg_match('/^(abattage|taille|dessouchage|d[ée]bitage|prestations|haubanage|chenilles|signalisation|entretien|élimination|elimination|réduction|rehaussement|allègement|conif)/iu', $libelle));
            $out[] = ['numero' => $numero, 'code' => $code, 'libelle' => $libelle, 'unite' => $unite, 'quantite' => $qte, 'prix' => $prix, 'section' => $section];
        }
        if ($trouve) {
            // Titres et rubriques placés avant le premier en-tête : ancienne méthode
            $avant = interpreter_lignes(array_values(array_filter($hors, fn($r) => !(($r[0] ?? '') === '__FEUILLE__' && count($r) === 1))), 'pdf');
            return array_merge($avant, $out);
        }
    }
    // 2. Pas d'en-tête reconnu : méthode par position des nombres (cellules vides retirées)
    $rows = array_values(array_filter(array_map(function ($r) use ($ext) {
        if (($r[0] ?? '') === '__FEUILLE__' && count($r) === 1) return [];
        $v = array_values(array_filter($r, fn($c) => $c !== ''));
        if ($ext === 'docx') { $d = []; foreach ($v as $c) if (end($d) !== $c) $d[] = $c; $v = $d; } // cellules fusionnées répétées
        return $v;
    }, $rows)));
    return interpreter_heuristique($rows);
}
function interpreter_heuristique(array $rows): array {
    $entete = mb_strtolower(implode(' ', array_map(fn($r) => implode(' ', $r), array_slice($rows, 0, 40))));
    $aPrix = preg_match('/prix\s*(h\.?t|unitaire|u\b)/u', $entete) || str_contains($entete, 'p.u');
    $aQte = preg_match('/quantit/u', $entete);
    $unites = ['u', 'unité', 'unite', 'h', 'heure', 'ml', 'm', 'm²', 'm2', 'm³', 'm3', 'forfait', 'f', 'j', 'jour', 'journée', 'ens', 'kg', 't', 'forfait / intervention'];
    $out = [];
    foreach ($rows as $r) {
        $cells = array_values($r); $texte = implode(' | ', $cells);
        if (preg_match('/^(ENTETE:|n°\s*prix|désignation|designation|article|code|bordereau|identification|date,? cachet|nota|total)/iu', $cells[0] ?? '')) continue;
        if (preg_match('/^(N°|PRIX|DESIGNATION|UNITE|UNITÉ|Unité)\b/u', $cells[0] ?? '') && count($cells) <= 5 && preg_match('/d[ée]signation/iu', $texte)) continue;
        $numero = ''; $libelle = ''; $unite = ''; $qte = null; $prix = null; $nombres = [];
        // numéro : 1.2.3 / 2.0 / ARB0001 / II.5 / £ ...
        if (preg_match('/^(\d+(\.\d+)*|[IVX]+\.\d+(\.\d+)*|[A-Z]{2,4}\d{3,5})\.?$/u', $cells[0] ?? '')) { $numero = rtrim(array_shift($cells), '.'); }
        elseif (isset($cells[0]) && mb_strlen($cells[0]) <= 2 && !preg_match('/\w/u', $cells[0])) { array_shift($cells); $numero = '?'; }
        elseif (preg_match('/^([IVX]+\.\d+(\.\d+)*\.?|\d+(\.\d+)+)\s+(.+)$/u', $cells[0] ?? '', $m)) { $numero = rtrim($m[1], '.'); $cells[0] = $m[4]; }
        // code type ARB0001 en 2e position (Lot Habitat)
        $code = '';
        if (isset($cells[0]) && preg_match('/^[A-Z]{2,4}\d{3,5}$/', $cells[0])) { $code = array_shift($cells); }
        if (!$cells) continue;
        $libelle = array_shift($cells);
        if (str_contains($libelle, "\n")) $libelle = trim(explode("\n", $libelle)[0]);
        foreach ($cells as $c) {
            $cl = mb_strtolower(trim($c));
            if ($unite === '' && in_array($cl, $unites)) { $unite = $c; continue; }
            if (preg_match('/^(forfait|unité|ml|m[²³23]?|h|jour)\b/iu', $cl) && $unite === '' && mb_strlen($cl) < 25) { $unite = $c; continue; }
            $num = str_replace([' ', "\xc2\xa0", '€'], '', $c); $num = str_replace(',', '.', $num);
            if (is_numeric($num)) { $nombres[] = ['v' => (float)$num, 'euro' => str_contains($c, '€')]; }
        }
        // Nombres : avec deux nombres → quantité puis prix ; un seul → prix si € ou mode prix, sinon quantité (tranché plus tard selon l'en-tête)
        if (count($nombres) >= 2) { $qte = $nombres[0]['v']; $prix = $nombres[1]['v']; }
        elseif (count($nombres) === 1) { if ($nombres[0]['euro']) $prix = $nombres[0]['v']; else $qte = $nombres[0]['v']; }
        // Une ligne sans unité ni prix, en majuscules ou avec un numéro court (2.0, I.5) → section
        $section = ($unite === '' && $prix === null && $qte === null) && (mb_strtoupper($libelle) === $libelle || preg_match('/^(\d+\.0|[IVX]+\.\d+|\d+)$/', $numero) || mb_strlen($libelle) < 60 && preg_match('/^(abattage|taille|dessouchage|d[ée]bitage|prestations|haubanage|chenilles|signalisation|entretien|élimination|elimination|réduction|rehaussement|allègement|conif)/iu', $libelle));
        // Un seul nombre : c'est un prix si le document a une colonne prix et pas de colonne quantité
        if ($prix === null && $qte !== null && $aPrix && !$aQte) { $prix = $qte; $qte = null; }
        // Bordereau "prix remis" sans colonne quantité détectée mais avec des nombres ≥ 20 partout : ce sont des prix
        if ($prix === null && $qte !== null && !$aQte && $qte >= 20) { $prix = $qte; $qte = null; }
        if ($libelle === '' || mb_strlen($libelle) < 3 || preg_match('/^[IVX]+\.\d+(\.\d+)*\.?$/', $libelle)) continue;
        if (preg_match('/^(montant|tva|total|accord-cadre|bordereau de|identification du|date,? (signature|cachet)|l\'entrepreneur|chaque poste|les prix unitaires|nota|le classement|\(.*du cctp\)$)/iu', $libelle)) continue;
        if (preg_match('/^\(.*\)$/u', $libelle)) continue;
        $out[] = ['numero' => $numero, 'code' => $code, 'libelle' => trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $libelle)), 'unite' => trim(preg_replace('/\s+/', ' ', $unite)), 'quantite' => $qte, 'prix' => $prix, 'section' => $section];
    }
    return $out;
}
