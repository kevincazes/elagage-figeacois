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
    return interpreter_lignes($tableaux);
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
                $cells[$col] = trim($v);
            }
            if (array_filter($cells, fn($x) => $x !== '')) $rows[] = array_values(array_filter($cells, fn($x) => $x !== ''));
        }
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
    foreach ($x->query('//w:tbl/w:tr') as $tr) {
        $cells = []; $prev = null;
        foreach ($x->query('./w:tc', $tr) as $tc) {
            $t = trim(preg_replace('/\s+/', ' ', implode(' ', array_map(fn($p) => $p->textContent, iterator_to_array($x->query('./w:p', $tc))))));
            // Première ligne de la cellule = libellé ; le reste est descriptif
            $first = trim(explode("\n", trim(implode("\n", array_map(fn($p) => $p->textContent, iterator_to_array($x->query('./w:p', $tc))))))[0]);
            $val = $first !== '' ? $first : $t;
            if ($val !== '' && $val !== $prev) $cells[] = $val; $prev = $val;
        }
        if ($cells) $rows[] = $cells;
    }
    return $rows;
}

function lire_csv(string $f): array {
    $rows = []; $h = fopen($f, 'r'); $sep = ';';
    $first = fgets($h); rewind($h); if ($first && substr_count($first, ',') > substr_count($first, ';')) $sep = ',';
    while (($r = fgetcsv($h, 0, $sep)) !== false) { $r = array_values(array_filter(array_map('trim', $r), fn($x) => $x !== '')); if ($r) $rows[] = $r; }
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
function interpreter_lignes(array $rows): array {
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
        $out[] = ['numero' => $numero, 'code' => $code, 'libelle' => trim(preg_replace('/\s+/', ' ', $libelle)), 'unite' => trim(preg_replace('/\s+/', ' ', $unite)), 'quantite' => $qte, 'prix' => $prix, 'section' => $section];
    }
    return $out;
}
