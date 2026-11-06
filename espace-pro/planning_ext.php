<?php
// Planning – compléments : rapports (visite / fin d'intervention) en PDF, contrats d'entretien, congés, secteurs,
// DT-DICT, notifications sur le téléphone (web push), SMS (OVH), avis Google, lien avec Urgence Élagage.
declare(strict_types=1);

const CONTRAT_MODELE_DEFAUT = <<<TXT
CONTRAT D'ENTRETIEN N° {{numero}}

Entre {{entreprise}}, ci-après « le Prestataire », et {{client_nom}}, demeurant {{adresse}}, ci-après « le Client ».

Article 1 – Objet
Le Prestataire assure pour le Client les prestations suivantes, sur le site situé {{adresse}} :
{{objet}}

Article 2 – Calendrier des interventions
Les interventions ont lieu chaque année aux périodes suivantes : {{periodes}}.
La date précise de chaque passage est proposée au Client au moins une semaine à l'avance ; le Client la confirme ou en demande une autre.

Article 3 – Prix
Le prix annuel est de {{montant_ht}} HT, soit {{montant_ttc}} TTC (TVA {{tva}} %), facturé après chaque intervention au prorata des passages ({{nb_passages}} passage(s) par an).
Les prix sont révisables chaque année à la date anniversaire ; le Client en est informé par écrit au moins un mois avant.

Article 4 – Durée
Le contrat prend effet le {{date_debut}} pour une durée de {{duree_mois}} mois.{{reconduction}}

Article 5 – Obligations des parties
Le Client garantit l'accès au site (portail ouvert, véhicules déplacés) aux dates convenues. Le Prestataire intervient dans le respect des règles de l'art et de sécurité, et évacue les déchets verts sauf mention contraire.
En cas d'intempéries (vent fort, orage), le Prestataire peut reporter l'intervention ; une nouvelle date est alors proposée.

Article 6 – Résiliation
Chaque partie peut résilier le contrat par lettre recommandée ou par e-mail avec accusé de réception, en respectant un préavis d'un mois avant la date d'échéance.

Article 7 – Droit de rétractation
Lorsque le contrat est conclu à distance ou hors établissement avec un consommateur, celui-ci dispose d'un délai de 14 jours à compter de la signature pour se rétracter, sans avoir à se justifier, par simple écrit adressé au Prestataire.

Article 8 – Médiation
En cas de litige, le Client consommateur peut recourir gratuitement à un médiateur de la consommation.

Fait le {{date_jour}}.
TXT;

const MODELES_RAPPORT = ['visite' => 'Rapport de visite (devis)', 'fin' => "Bon de fin d'intervention"];
const RESSENTIS = ['bien' => ['Très bien', '#4f9d5d'], 'moyen' => ['Moyen', '#e0874a'], 'mal' => ['Mal', '#d24a57']];
const TYPES_CONGES = ['conges' => 'Congés payés', 'rtt' => 'RTT / récupération', 'formation' => 'Formation', 'sans_solde' => 'Sans solde', 'autre' => 'Autre absence'];
const MOIS_FR = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

// ===================== Fichiers (photos, PDF) =====================
function dossier_fichiers(): string {
    $d = __DIR__ . '/fichiers';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n");
    return $d;
}
/** Enregistre une image envoyée en data URL (JPEG/PNG) ; retourne le nom du fichier */
function enregistrer_image_dataurl(string $data, string $prefixe = 'ph'): ?string {
    if (!preg_match('#^data:image/(jpeg|png);base64,(.+)$#s', $data, $m)) return null;
    $bin = base64_decode($m[2], true); if ($bin === false || strlen($bin) > 8_000_000 || !@getimagesizefromstring($bin)) return null;
    $nom = $prefixe . '-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . ($m[1] === 'png' ? '.png' : '.jpg');
    file_put_contents(dossier_fichiers() . '/' . $nom, $bin);
    return $nom;
}
function chemin_fichier(string $nom): ?string { return preg_match('/^[a-z]+-\d{8}-[a-f0-9]{16}\.(jpg|png|pdf|docx|xlsx|odt|ods|txt|csv|heic)$/', $nom) && is_file(dossier_fichiers() . "/$nom") ? dossier_fichiers() . "/$nom" : null; }

// ===================== PDF =====================
require_once __DIR__ . '/lib/fpdf/fpdf.php';
function pdf_txt(?string $s): string { $s = str_replace(['’', '‘', '“', '”', '–', '—', '…', "\u{00A0}", "\u{202F}"], ["'", "'", '"', '"', '-', '-', '...', ' ', ' '], (string)$s); $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s); return $r === false ? utf8_decode($s) : $r; }
class PdfEF extends FPDF {
    public string $titreDoc = ''; public string $sousTitre = '';
    function Header(): void {
        $this->SetFont('Helvetica', 'B', 15); $this->SetTextColor(104, 60, 17); $this->SetXY(15, 12); $this->Cell(120, 7, pdf_txt($this->titreDoc));
        $this->SetFont('Helvetica', '', 10); $this->SetTextColor(90, 100, 90); $this->SetXY(15, 19); $this->Cell(120, 6, pdf_txt($this->sousTitre));
        $logo = __DIR__ . '/../images/logo-elagage-figeacois.png'; if (is_file($logo)) try { $this->Image($logo, 170, 8, 25); } catch (Throwable $t) {}
        $this->SetDrawColor(140, 170, 49); $this->SetLineWidth(.6); $this->Line(15, 30, 195, 30); $this->SetLineWidth(.2); $this->SetY(35);
    }
    function Footer(): void {
        $this->SetY(-16); $this->SetFont('Helvetica', '', 7.5); $this->SetTextColor(110, 115, 110);
        foreach (explode("\n", setting('ent_mentions')) as $l) $this->Cell(0, 3.6, pdf_txt($l), 0, 1, 'C');
        $this->SetXY(170, -14); $this->SetFont('Helvetica', 'B', 9); $this->Cell(25, 5, $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
    function Bloc(string $titre, array $champs, bool $gris = false): void {
        $y0 = $this->GetY(); if ($y0 > 245) { $this->AddPage(); $y0 = $this->GetY(); }
        $this->SetX(19); $this->SetFont('Helvetica', 'B', 11.5); $this->SetTextColor(104, 60, 17); $this->MultiCell(172, 5.6, pdf_txt($titre)); $this->Ln(2);
        $col = 0; $yL = $this->GetY();
        foreach ($champs as $lib => $val) {
            if ($val === null || $val === '') continue;
            $x = $col ? 107 : 19; $this->SetXY($x, $yL);
            $this->SetFont('Helvetica', 'B', 8); $this->SetTextColor(120, 125, 140); $this->Cell(84, 4, pdf_txt($lib), 0, 2);
            $this->SetFont('Helvetica', '', 9.5); $this->SetTextColor(30, 30, 30); $this->MultiCell(84, 4.6, pdf_txt($val));
            $yMax[$col] = $this->GetY(); $col = 1 - $col; if ($col === 0) $yL = max($yMax) + 2.5;
        }
        $yF = isset($yMax) ? max($yMax) + 3 : $this->GetY() + 1;
        if ($gris) { $this->SetFillColor(242, 243, 245); $this->Rect(15, $y0 - 3, 180, $yF - $y0 + 3, 'F'); $this->SetY($y0); $gris = false; $this->Bloc($titre, $champs, false); return; }
        $this->SetY($yF + 4);
    }
    function Texte(string $titre, string $txt): void {
        if (trim($txt) === '') return; if ($this->GetY() > 250) $this->AddPage();
        $this->SetX(15); $this->SetFont('Helvetica', 'B', 11); $this->SetTextColor(104, 60, 17); $this->Cell(0, 6, pdf_txt($titre), 0, 1);
        $this->SetX(15); $this->SetFont('Helvetica', '', 10); $this->SetTextColor(30, 30, 30); $this->MultiCell(180, 5, pdf_txt($txt)); $this->Ln(4);
    }
    /** Grille de photos (3 par ligne) avec légendes */
    function Photos(array $photos): void {
        if (!$photos) return;
        $w = 56; $gap = 6; $col = 0; $this->AddPage(); $y = $this->GetY(); $hLigne = 0;
        foreach ($photos as $ph) {
            $f = chemin_fichier($ph['fichier']); if (!$f) continue;
            [$iw, $ih] = @getimagesize($f) ?: [4, 3]; $h = min(95, $w * $ih / max(1, $iw)); $wr = $h * $iw / max(1, $ih);
            if ($y + $h + 10 > 275) { $this->AddPage(); $y = $this->GetY(); $col = 0; $hLigne = 0; }
            $x = 15 + $col * ($w + $gap) + ($w - $wr) / 2;
            try { $this->Image($f, $x, $y, $wr, $h); } catch (Throwable $t) { continue; }
            if ($ph['legende'] !== '') { $this->SetXY(15 + $col * ($w + $gap), $y + $h + 1); $this->SetFont('Helvetica', '', 8); $this->SetTextColor(60, 60, 60); $this->MultiCell($w, 3.6, pdf_txt($ph['legende']), 0, 'C'); }
            $hLigne = max($hLigne, $h + ($ph['legende'] !== '' ? 9 : 3));
            if (++$col === 3) { $col = 0; $y += $hLigne + 4; $hLigne = 0; }
        }
        $this->SetY($y + $hLigne + 4);
    }
    function Signature(string $titre, ?string $dataUrl, string $nom, ?string $quand, string $empreinte = ''): void {
        if ($this->GetY() > 225) $this->AddPage();
        $this->SetX(15); $this->SetFont('Helvetica', 'B', 11); $this->SetTextColor(104, 60, 17); $this->Cell(0, 6, pdf_txt($titre), 0, 1);
        $this->SetFont('Helvetica', '', 9.5); $this->SetTextColor(30, 30, 30);
        $this->SetX(15); $this->Cell(0, 5, pdf_txt($nom . ($quand ? ' – signé le ' . date('d/m/Y à H:i', strtotime($quand)) : '')), 0, 1);
        if ($dataUrl && preg_match('#^data:image/(png|jpeg);base64,(.+)$#s', $dataUrl, $m)) {
            $base = tempnam(sys_get_temp_dir(), 'sig'); $tmp = $base . '.' . ($m[1] === 'png' ? 'png' : 'jpg'); @unlink($base); file_put_contents($tmp, base64_decode($m[2]));
            try { $this->Image($tmp, 15, $this->GetY() + 1, 60); } catch (Throwable $t) {} @unlink($tmp); $this->SetY($this->GetY() + 30);
        }
        if ($empreinte) { $this->SetX(15); $this->SetFont('Helvetica', '', 7); $this->SetTextColor(120, 120, 120); $this->MultiCell(180, 3.4, pdf_txt("Signature électronique simple – empreinte du document (SHA-256) : $empreinte")); }
    }
}
function pdf_nouveau(string $titre, string $sous): PdfEF { $p = new PdfEF('P', 'mm', 'A4'); $p->titreDoc = $titre; $p->sousTitre = $sous; $p->AliasNbPages(); $p->SetMargins(15, 35, 15); $p->SetAutoPageBreak(true, 22); $p->AddPage(); return $p; }
function pdf_entreprise(PdfEF $p): void {
    $p->Bloc(setting('ent_nom'), ['Téléphone' => setting('ent_tel'), 'E-mail' => setting('ent_email'), 'Adresse' => setting('ent_adresse')]);
}

// ===================== Rapports (visite de chiffrage / fin d'intervention) =====================
function rapport_charger(int $evtId): array {
    $r = q("SELECT * FROM rapports WHERE evenement_id = ?", [$evtId])->fetch();
    $e = evt_charger($evtId);
    if (!$r) $r = ['id' => 0, 'evenement_id' => $evtId, 'modele' => ($e && $e['type'] === 'chantier') ? 'fin' : 'visite', 'texte' => '', 'reserves' => '', 'dechets' => '', 'ressenti' => '', 'signataire' => $e['client_nom'] ?? '', 'signature' => null, 'signe_le' => null, 'ip' => '', 'empreinte' => '', 'envoye_le' => null, 'envoye_a' => ''];
    $r['photos'] = q("SELECT * FROM rapport_photos WHERE evenement_id = ? ORDER BY ordre, id", [$evtId])->fetchAll();
    if (!$r['id'] && $r['modele'] === 'fin' && $r['texte'] === '') { try { $r['texte'] = implode("\n", array_map(fn($x) => $x['libelle'] . ($x['quantite'] !== null ? ' (' . rtrim(rtrim(number_format((float)$x['quantite'], 2, ',', ' '), '0'), ',') . ($x['unite'] ? ' ' . $x['unite'] : '') . ')' : ''), array_filter(prestations_liste($evtId), fn($x) => $x['fait_le']))); } catch (Throwable $t) {} }
    return $r;
}
function rapport_pdf(int $evtId): string {
    $e = evt_charger($evtId); $r = rapport_charger($evtId);
    $fin = $r['modele'] === 'fin';
    $p = pdf_nouveau($fin ? "Bon de fin d'intervention" : 'Rapport de visite', 'du ' . ($e['debut'] ? date_fr($e['debut'], false) : date_fr(date('Y-m-d'), false)));
    pdf_entreprise($p);
    $p->Bloc($e['client_nom'] ?: 'Client', ['Téléphone' => $e['client_tel'], 'E-mail' => $e['client_email']], true);
    $noms = []; foreach (personnel_liste(true) as $x) if (in_array((int)$x['id'], $e['personnel'], true)) $noms[] = trim($x['prenom'] . ' ' . $x['nom']);
    $p->Bloc($e['titre'], ['Date' => $e['debut'] ? ucfirst(evt_quand($e)) : '', 'Type' => TYPES_EVT[$e['type']][0] ?? '', 'Adresse' => trim($e['adresse'] . "\n" . $e['cp'] . ' ' . $e['ville']), 'Intervenants' => implode(', ', $noms)], true);
    if ($fin && ($pr = prestations_liste($evtId))) $p->Texte('Prestations du devis', implode("\n", array_map(fn($x) => ($x['fait_le'] ? '[x] ' : '[ ] ') . $x['libelle'] . ($x['quantite'] !== null ? ' (' . rtrim(rtrim(number_format((float)$x['quantite'], 2, ',', ' '), '0'), ',') . ($x['unite'] ? ' ' . $x['unite'] : '') . ')' : '') . ($x['remarque'] ? ' – ' . $x['remarque'] : ''), $pr)));
    $p->Texte($fin ? 'Travaux réalisés' : 'Compte rendu', (string)$r['texte']);
    if ($fin) {
        $p->Texte('Déchets verts', ['evacues' => 'Évacués par nos soins', 'sur_place' => 'Laissés sur place à la demande du client', 'broyes' => 'Broyés et laissés en paillage', '' => ''][$r['dechets']] ?? $r['dechets']);
        $p->Texte('Remarques / réserves du client', (string)$r['reserves']);
        if ($r['signature']) $p->Signature('Signature du client', $r['signature'], $r['signataire'], $r['signe_le'], $r['empreinte']);
    }
    $p->Photos(array_values(array_filter($r['photos'], fn($x) => (int)$x['dans_pdf'])));
    return $p->Output('S');
}
/** Envoie le rapport en PDF au client (et en copie à l'entreprise) */
function rapport_envoyer(int $evtId, string $a): string {
    $e = evt_charger($evtId); if (!$e) return 'Fiche introuvable.';
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) return 'Adresse e-mail invalide.';
    $r = rapport_charger($evtId); $fin = $r['modele'] === 'fin';
    $pdf = rapport_pdf($evtId);
    $nomF = ($fin ? 'Bon-intervention' : 'Rapport-visite') . '-' . date('Y-m-d', strtotime($e['debut'] ?: 'now')) . '.pdf';
    $txt = "Bonjour" . ($e['client_nom'] ? " {$e['client_nom']}" : '') . ",\n\nVeuillez trouver ci-joint " . ($fin ? "le bon de fin d'intervention" : 'le compte rendu de notre visite') . " du " . date_fr($e['debut'] ?: date('Y-m-d'), false) . ".\n\nBien cordialement,\n" . setting('ent_nom') . "\n" . setting('ent_tel');
    if (!envoyer_mail_pj($a, ($fin ? "Bon de fin d'intervention" : 'Rapport de visite') . ' – ' . $e['titre'], $txt, [$nomF => ['application/pdf', $pdf]], setting('ent_email'))) return "L'e-mail n'a pas pu partir.";
    if ($r['id']) q("UPDATE rapports SET envoye_le = NOW(), envoye_a = ? WHERE evenement_id = ?", [$a, $evtId]);
    evt_journal($evtId, ($fin ? "Bon de fin d'intervention" : 'Rapport de visite') . " envoyé à $a");
    return '';
}
/** E-mail avec pièces jointes [nom => [type, contenu]], copie cachée facultative */
function envoyer_mail_pj(string $a, string $sujet, string $texte, array $pj, string $bcc = ''): bool {
    $de = defined('APP_EMAIL') ? APP_EMAIL : 'contact@elagage-figeacois.com';
    $b = 'ef' . bin2hex(random_bytes(8));
    $h = "From: " . setting('ent_nom', 'Élagage Figeacois') . " <$de>\r\nReply-To: " . (setting('ent_email') ?: $de) . "\r\n" . ($bcc && filter_var($bcc, FILTER_VALIDATE_EMAIL) ? "Bcc: $bcc\r\n" : '') . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n";
    $corps = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$texte\r\n";
    foreach ($pj as $nom => [$type, $contenu]) $corps .= "--$b\r\nContent-Type: $type; name=\"$nom\"\r\nContent-Disposition: attachment; filename=\"$nom\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($contenu));
    $corps .= "--$b--\r\n";
    if (defined('MAIL_TEST_DIR')) { @file_put_contents(MAIL_TEST_DIR . '/' . date('His') . '-' . bin2hex(random_bytes(3)) . '.eml', "To: $a\r\nSubject: $sujet\r\n$h\r\n$corps"); return true; }
    return @mail($a, "=?UTF-8?B?" . base64_encode($sujet) . "?=", $corps, $h);
}

// ===================== Clients =====================
const TYPES_CLIENT = ['particulier' => 'Particulier', 'professionnel' => 'Professionnel', 'collectivite' => 'Collectivité'];
const DOSSIERS_FINANCIERS = ['Devis', 'Contrats signés'];
const DOSSIERS_CLIENT = ['Devis', 'Contrats signés', 'Rapports de rendez-vous', "Bons d'intervention", 'Photos', 'Plans', 'Courriers', 'Documents'];
/** Le client accepte-t-il ce canal (consentement de sa fiche) ? */
function client_accepte(array $e, string $canal): bool {
    if (empty($e['client_id'])) return true;
    $c = q("SELECT ok_sms, ok_email FROM clients WHERE id = ?", [(int)$e['client_id']])->fetch();
    return !$c || (int)$c[$canal === 'sms' ? 'ok_sms' : 'ok_email'] === 1;
}
/** Retrouve ou crée la fiche client d'un événement (nom + téléphone ou e-mail), et ajoute l'adresse si elle est nouvelle */
function client_assurer(array $r): ?int {
    $nom = trim((string)($r['client_nom'] ?? '')); if ($nom === '' || in_array($r['type'] ?? '', ['absence', 'entretien', 'urgence'], true)) return !empty($r['client_id']) ? (int)$r['client_id'] : null;
    $id = !empty($r['client_id']) ? (int)$r['client_id'] : 0;
    if ($id && !q("SELECT id FROM clients WHERE id = ?", [$id])->fetch()) $id = 0;
    if (!$id) {
        $tel = preg_replace('/\D/', '', (string)($r['client_tel'] ?? '')); $mail = mb_strtolower(trim((string)($r['client_email'] ?? '')));
        foreach (q("SELECT * FROM clients WHERE nom = ?", [$nom]) as $c) {
            if (($mail !== '' && mb_strtolower($c['email']) === $mail) || ($tel !== '' && in_array($tel, [preg_replace('/\D/', '', $c['telephone']), preg_replace('/\D/', '', $c['mobile'])], true)) || ($mail === '' && $tel === '')) { $id = (int)$c['id']; break; }
        }
        if (!$id) {
            $mob = sms_mobile($r['client_tel'] ?? '');
            q("INSERT INTO clients (nom, telephone, mobile, email) VALUES (?,?,?,?)", [$nom, $mob ? '' : (string)($r['client_tel'] ?? ''), $mob ? (string)$r['client_tel'] : '', (string)($r['client_email'] ?? '')]);
            $id = (int)db()->lastInsertId();
        }
    }
    if (trim((string)($r['adresse'] ?? '') . ($r['ville'] ?? '')) !== '' && !q("SELECT id FROM client_adresses WHERE client_id = ? AND adresse = ? AND ville = ?", [$id, (string)$r['adresse'], (string)$r['ville']])->fetch()) {
        $n = (int)q("SELECT COUNT(*) c FROM client_adresses WHERE client_id = ?", [$id])->fetch()['c'];
        q("INSERT INTO client_adresses (client_id, libelle, adresse, cp, ville, lat, lng, ordre) VALUES (?,?,?,?,?,?,?,?)", [$id, $n ? 'Chantier' : 'Principale', (string)$r['adresse'], (string)($r['cp'] ?? ''), (string)($r['ville'] ?? ''), $r['lat'] ?? null, $r['lng'] ?? null, $n]);
    }
    return $id;
}
function client_vu(int $id): void { if (user()) q("REPLACE INTO client_vus (utilisateur_id, client_id, vu_le) VALUES (?,?,NOW())", [user()['id'], $id]); }

// ===================== DT-DICT =====================
/** Alertes réglementaires DT-DICT d'un chantier (réponses : 9 jours après envoi ; validité : 3 mois) */
function dict_alertes(array $e): array {
    if (empty($e['dict_requise']) || empty($e['debut']) || ($e['type'] ?? '') !== 'chantier') return [];
    $deb = strtotime(substr($e['debut'], 0, 10));
    if (empty($e['dict_envoi'])) return ['DT-DICT à faire avant le chantier (réponses sous 9 jours, jours fériés non compris).'];
    $env = strtotime($e['dict_envoi']); $limite = strtotime('+9 days', $env); foreach (jours_feries((int)date('Y', $env)) + jours_feries((int)date('Y', $env) + 1) as $j => $_) if (strtotime($j) > $env && strtotime($j) <= $limite) $limite = strtotime('+1 day', $limite);
    $al = [];
    if (empty($e['dict_retour'])) $al[] = $deb < $limite ? 'DT-DICT : le chantier commence avant la fin du délai de réponse (' . date('d/m', $limite) . ').' : 'DT-DICT : réponses des exploitants pas encore reçues.';
    if ($deb > strtotime('+3 months', $env)) $al[] = 'DT-DICT : plus de 3 mois entre la déclaration et le chantier, elle est à renouveler.';
    return $al;
}

// ===================== Secteurs (journées par zone) =====================
function secteurs_liste(): array { static $s = null; return $s ??= q("SELECT * FROM secteurs WHERE actif = 1 ORDER BY ordre, nom")->fetchAll(); }
/** Secteur le plus proche contenant le point (rayon), ou null */
function secteur_de(?float $lat, ?float $lng): ?array {
    if ($lat === null || $lng === null) return null; $best = null; $bd = INF;
    foreach (secteurs_liste() as $s) { if ($s['lat'] === null) continue; $d = distance_km($lat, $lng, (float)$s['lat'], (float)$s['lng']) / max(1, (float)setting('planning_facteur', '1.3')); if ($d <= (float)$s['rayon_km'] && $d < $bd) { $bd = $d; $best = $s; } }
    return $best;
}
/** Secteurs prévus pour un jour (jours 1 = lundi … 5 = vendredi) */
function secteurs_du_jour(string $j): array { $n = (string)date('N', strtotime($j)); return array_values(array_filter(secteurs_liste(), fn($s) => in_array($n, explode(',', $s['jours']), true))); }

// ===================== Congés =====================
/** Accepte une demande : pose l'absence au planning */
function conge_accepter(int $id, string $reponse = ''): string {
    $c = q("SELECT * FROM conges WHERE id = ?", [$id])->fetch(); if (!$c) return 'Demande introuvable.';
    if ($c['statut'] !== 'demande') return 'Demande déjà traitée (' . $c['statut'] . ').';
    $p = q("SELECT * FROM personnel WHERE id = ?", [$c['personnel_id']])->fetch();
    $deb = $c['du'] . ' ' . ($c['demi'] === 'apres' ? '13:00' : setting('planning_debut', '08:00')) . ':00';
    $fin = $c['au'] . ' ' . ($c['demi'] === 'matin' ? '12:00' : setting('planning_fin', '17:00')) . ':00';
    q("INSERT INTO evenements (type, titre, statut, debut, fin, journee, cree_par) VALUES ('absence', ?, 'confirme', ?, ?, ?, ?)", [(TYPES_CONGES[$c['type']] ?? 'Absence') . ' – ' . ($p['prenom'] ?? ''), $deb, $fin, $c['demi'] === '' ? 1 : 0, user()['id'] ?? null]);
    $eid = (int)db()->lastInsertId(); q("INSERT INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$eid, $c['personnel_id']]);
    evt_journal($eid, 'Absence posée depuis la demande de congés n° ' . $id);
    q("UPDATE conges SET statut = 'accepte', traite_par = ?, traite_le = NOW(), reponse = ?, evenement_id = ? WHERE id = ?", [user()['id'] ?? null, mb_substr($reponse, 0, 255), $eid, $id]);
    if ($c['demande_par']) notifier([(int)$c['demande_par']], 'Congés acceptés', 'Du ' . date('d/m', strtotime($c['du'])) . ' au ' . date('d/m', strtotime($c['au'])) . ($reponse ? " – $reponse" : ''), 'conges.php');
    return '';
}

// ===================== Contrats d'entretien =====================
function contrat_mois(array $c): array { return array_values(array_filter(array_map('intval', explode(',', (string)$c['mois'])), fn($m) => $m >= 1 && $m <= 12)); }
function contrat_fin(array $c): ?string { return $c['date_debut'] ? date('Y-m-d', strtotime($c['date_debut'] . ' +' . max(1, (int)$c['duree_mois']) . ' months -1 day')) : null; }
function contrat_variables(array $c): array {
    $mois = contrat_mois($c); $ht = (float)($c['montant_ht'] ?? 0); $tva = (float)$c['tva'];
    return [
        'numero' => $c['numero'], 'entreprise' => trim(setting('ent_nom') . ', ' . str_replace("\n", ' ', setting('ent_adresse')) . ', ' . str_replace("\n", ', ', setting('ent_mentions'))),
        'client_nom' => $c['client_nom'], 'adresse' => trim($c['adresse'] . ', ' . $c['cp'] . ' ' . $c['ville'], ' ,'), 'objet' => trim((string)$c['objet']),
        'periodes' => $mois ? implode(', ', array_map(fn($m) => MOIS_FR[$m], $mois)) : 'à convenir', 'nb_passages' => (string)max(1, count($mois)),
        'montant_ht' => money($ht), 'montant_ttc' => money(round($ht * (1 + $tva / 100), 2)), 'tva' => rtrim(rtrim(number_format($tva, 2, ',', ''), '0'), ','),
        'date_debut' => $c['date_debut'] ? date('d/m/Y', strtotime($c['date_debut'])) : '…', 'duree_mois' => (string)(int)$c['duree_mois'],
        'reconduction' => (int)$c['tacite'] ? " Il est ensuite reconduit tacitement par périodes de {$c['duree_mois']} mois, sauf résiliation dans les conditions de l'article 6. Conformément à l'article L215-1 du Code de la consommation, le Client consommateur est informé par écrit, au plus tôt trois mois et au plus tard un mois avant l'échéance, de la possibilité de ne pas reconduire le contrat." : ' Il prend fin à son terme sans reconduction.',
        'date_jour' => date('d/m/Y'),
    ];
}
function contrat_texte(array $c, ?string $modele = null): string {
    $m = $modele ?? (q("SELECT texte FROM contrat_modeles WHERE id = ?", [(int)$c['modele_id']])->fetch()['texte'] ?? CONTRAT_MODELE_DEFAUT);
    $v = contrat_variables($c);
    return preg_replace_callback('/\{\{(\w+)\}\}/', fn($x) => $v[$x[1]] ?? $x[0], $m);
}
function contrat_pdf(array $c): string {
    $p = pdf_nouveau("Contrat d'entretien", 'n° ' . $c['numero']);
    $txt = $c['texte_final'] ?: contrat_texte($c);
    $p->SetFont('Helvetica', '', 10); $p->SetTextColor(30, 30, 30);
    foreach (explode("\n", $txt) as $l) {
        if (preg_match('/^(Article \d+|CONTRAT)/', $l)) { $p->Ln(1.5); $p->SetFont('Helvetica', 'B', 10.5); $p->MultiCell(180, 5.2, pdf_txt($l)); $p->SetFont('Helvetica', '', 10); }
        else $p->MultiCell(180, 5, pdf_txt($l));
    }
    $p->Ln(4);
    if ($c['signature']) $p->Signature('Signature du client (bon pour accord)', $c['signature'], $c['signataire'], $c['signe_le'], $c['empreinte']);
    else { $p->SetFont('Helvetica', 'I', 9); $p->Cell(0, 6, pdf_txt('Document non signé.'), 0, 1); }
    return $p->Output('S');
}
/** Envoie le contrat au client avec le lien de signature */
function contrat_envoyer(int $id): string {
    $c = q("SELECT * FROM contrats WHERE id = ?", [$id])->fetch(); if (!$c) return 'Contrat introuvable.';
    if (!filter_var($c['client_email'], FILTER_VALIDATE_EMAIL)) return "Indique l'e-mail du client.";
    if (in_array($c['statut'], ['signe', 'resilie'], true)) return 'Ce contrat est déjà signé ou résilié.';
    if ($c['token'] === '') { $c['token'] = bin2hex(random_bytes(16)); }
    $texte = contrat_texte($c);
    q("UPDATE contrats SET token = ?, texte_final = ?, empreinte = ?, statut = 'envoye', envoye_le = NOW() WHERE id = ?", [$c['token'], $texte, hash('sha256', $texte), $id]);
    $c = q("SELECT * FROM contrats WHERE id = ?", [$id])->fetch();
    $lien = lien_base() . '/signer.php?t=' . $c['token'];
    $txt = "Bonjour {$c['client_nom']},\n\nVoici votre contrat d'entretien n° {$c['numero']}, à relire et signer en ligne (une minute, depuis un téléphone ou un ordinateur) :\n$lien\n\nLe contrat est aussi joint en PDF.\n\nBien cordialement,\n" . setting('ent_nom') . "\n" . setting('ent_tel');
    if (!envoyer_mail_pj($c['client_email'], "Contrat d'entretien n° {$c['numero']} à signer", $txt, ["Contrat-{$c['numero']}.pdf" => ['application/pdf', contrat_pdf($c)]])) return "L'e-mail n'a pas pu partir.";
    if (sms_actif('contrat') && sms_mobile($c['client_tel']) && client_accepte(['client_id' => $c['client_id']], 'sms')) sms_envoyer($c['client_tel'], setting('ent_nom') . " : votre contrat d'entretien est a signer en ligne : $lien", null, 'contrat');
    journal('contrats', "Contrat {$c['numero']} envoyé à {$c['client_email']}");
    return '';
}
/** Crée les interventions « à planifier » des contrats signés, pour les passages des 75 prochains jours */
function contrats_generer(int $horizon = 75): int {
    $n = 0; $lim = strtotime("+$horizon days");
    foreach (q("SELECT * FROM contrats WHERE statut = 'signe'") as $c) {
        $fin = contrat_fin($c); $mois = contrat_mois($c); if (!$mois || !$c['date_debut']) continue;
        for ($t = strtotime(date('Y-m-01')); $t <= $lim; $t = strtotime('+1 month', $t)) {
            $m = (int)date('n', $t); $cle = date('Y-m', $t);
            if (!in_array($m, $mois, true) || $cle < substr($c['date_debut'], 0, 7)) continue;
            if (!(int)$c['tacite'] && $fin && $cle > substr($fin, 0, 7)) continue;
            if (q("SELECT id FROM evenements WHERE contrat_id = ? AND contrat_mois = ?", [$c['id'], $cle])->fetch()) continue;
            $montant = $c['montant_ht'] !== null ? round((float)$c['montant_ht'] / count($mois), 2) : null;
            q("INSERT INTO evenements (type, titre, statut, client_nom, client_tel, client_email, adresse, cp, ville, lat, lng, devis_ref, devis_montant, devis_signe, equipe_id, duree_h, nb_jours, besoin_personnes, souhait_client, consignes, contrat_id, journee, priorite, contrat_mois, client_id)
               VALUES ('chantier', ?, 'a_planifier', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 2, ?, ?, ?, 1, 2, ?, ?)",
              ['Entretien – ' . $c['client_nom'] . ' (' . MOIS_FR[$m] . ')', $c['client_nom'], $c['client_tel'], $c['client_email'], $c['adresse'], $c['cp'], $c['ville'], $c['lat'], $c['lng'], 'Contrat ' . $c['numero'], $montant,
               $c['equipe_id'], $c['duree_h'], $c['nb_jours'], 'Passage de ' . MOIS_FR[$m] . " – contrat {$c['numero']}", trim((string)$c['objet']), $c['id'], $cle, $c['client_id']]);
            evt_journal((int)db()->lastInsertId(), "Créé automatiquement depuis le contrat {$c['numero']}"); $n++;
        }
    }
    return $n;
}
/** Avis d'échéance (loi Chatel) : 2 mois avant la reconduction tacite */
function contrats_echeances(): int {
    $n = 0;
    foreach (q("SELECT * FROM contrats WHERE statut = 'signe' AND tacite = 1 AND client_email <> ''") as $c) {
        $fin = contrat_fin($c); if (!$fin) continue;
        while (strtotime($fin) < time()) $fin = date('Y-m-d', strtotime($fin . ' +' . max(1, (int)$c['duree_mois']) . ' months'));
        $cle = 'contrat_avis_' . $c['id'] . '_' . $fin;
        if (strtotime($fin) - time() > 62 * 86400 || setting($cle) !== '') continue;
        $txt = "Bonjour {$c['client_nom']},\n\nVotre contrat d'entretien n° {$c['numero']} arrive à échéance le " . date('d/m/Y', strtotime($fin)) . ". Sauf avis contraire de votre part avant cette date, il sera reconduit tacitement pour {$c['duree_mois']} mois aux mêmes conditions.\n\nConformément à l'article L215-1 du Code de la consommation, vous pouvez ne pas le reconduire en nous le signalant simplement par retour de ce message.\n\nBien cordialement,\n" . setting('ent_nom');
        if (envoyer_mail_pj($c['client_email'], "Échéance de votre contrat d'entretien n° {$c['numero']}", $txt, [])) { set_setting($cle, date('Y-m-d')); $n++; }
    }
    return $n;
}

// ===================== Notifications (dans l'appli et sur le téléphone) =====================
function notifier(array $uids, string $titre, string $corps = '', string $url = ''): void {
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    foreach ($uids as $u) q("INSERT INTO notifications (utilisateur_id, titre, corps, url) VALUES (?,?,?,?)", [$u, mb_substr($titre, 0, 150), mb_substr($corps, 0, 500), mb_substr($url, 0, 255)]);
    if ($uids && setting('push_actif', '1') === '1') { $GLOBALS['__push_a_envoyer'] = array_unique(array_merge($GLOBALS['__push_a_envoyer'] ?? [], $uids)); if (empty($GLOBALS['__push_prevu'])) { $GLOBALS['__push_prevu'] = 1; register_shutdown_function('push_differe'); } }
}
/** Prévient les personnes d'un événement (via leur compte), sauf l'auteur */
function notifier_evt(array $e, string $titre, string $corps = ''): void {
    if (!$e['personnel']) return;
    $uids = array_column(q("SELECT utilisateur_id FROM personnel WHERE utilisateur_id IS NOT NULL AND id IN (" . implode(',', array_map('intval', $e['personnel'])) . ")")->fetchAll(), 'utilisateur_id');
    $moi = (int)(user()['id'] ?? 0);
    notifier(array_filter($uids, fn($u) => (int)$u !== $moi), $titre, $corps, 'mon-planning.php?jour=' . substr((string)$e['debut'], 0, 10) . '&evt=' . $e['id']);
}
/** Comptes qui gèrent tout le planning (administrateurs, secrétariat, droit « tout ») */
function gestionnaires(): array { return array_map('intval', array_column(q("SELECT id FROM utilisateurs WHERE actif = 1 AND (role IN ('admin','secretaire') OR planning_droit = 'tout')")->fetchAll(), 'id')); }
function notifier_gestionnaires(string $titre, string $corps = '', string $url = ''): void { notifier(gestionnaires(), $titre, $corps, $url); }

// ---- Web push (VAPID, sans contenu chiffré : le téléphone vient lire la notification) ----
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function vapid(): array {
    if (setting('vapid_prive') === '') {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); if (!$k) return [];
        openssl_pkey_export($k, $pem); $d = openssl_pkey_get_details($k);
        set_setting('vapid_prive', $pem); set_setting('vapid_public', b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)));
    }
    return ['prive' => setting('vapid_prive'), 'public' => setting('vapid_public')];
}
/** Signature DER ECDSA → format brut r||s (64 octets) attendu par JWT ES256 */
function der_vers_brut(string $der): string {
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $lr = ord($der[$o + 1]); $r = substr($der, $o + 2, $lr); $o += 2 + $lr; $ls = ord($der[$o + 1]); $s = substr($der, $o + 2, $ls);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}
function vapid_jwt(string $endpoint): string {
    $v = vapid(); $u = parse_url($endpoint);
    $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])); $c = b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . (setting('ent_email') ?: 'contact@elagage-figeacois.com')]));
    openssl_sign("$h.$c", $sig, $v['prive'], OPENSSL_ALGO_SHA256);
    return "$h.$c." . b64u(der_vers_brut($sig));
}
function push_envoyer(int $uid): int {
    $n = 0; if (!function_exists('curl_init') || !vapid()) return 0;
    foreach (q("SELECT * FROM push_abonnements WHERE utilisateur_id = ?", [$uid]) as $a) {
        if (defined('PUSH_TEST_DIR')) { @file_put_contents(PUSH_TEST_DIR . '/' . $uid . '-' . bin2hex(random_bytes(3)) . '.txt', $a['endpoint'] . "\n" . vapid_jwt($a['endpoint'])); $n++; continue; }
        $ch = curl_init($a['endpoint']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t=' . vapid_jwt($a['endpoint']) . ', k=' . vapid()['public']]]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (in_array($code, [404, 410], true)) q("DELETE FROM push_abonnements WHERE id = ?", [$a['id']]); elseif ($code >= 200 && $code < 300) $n++;
    }
    return $n;
}
/** Envoi après la réponse à l'utilisateur (ne ralentit pas l'écran) */
function push_differe(): void {
    $l = $GLOBALS['__push_a_envoyer'] ?? []; if (!$l) return; $GLOBALS['__push_a_envoyer'] = [];
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    foreach ($l as $u) try { push_envoyer((int)$u); } catch (Throwable $t) {}
}

// ===================== SMS (OVH) =====================
const SMS_MOTIFS = ['proposition' => 'Proposition de date', 'rappel' => 'Rappel la veille', 'avis' => 'Demande d\'avis Google', 'contrat' => 'Contrat à signer'];
function sms_pret(): bool { foreach (['ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key', 'ovh_sms_service'] as $k) if (setting($k) === '') return defined('SMS_TEST_DIR'); return true; }
function sms_actif(string $motif = ''): bool { return setting('sms_actif') === '1' && sms_pret() && ($motif === '' || setting("sms_$motif", '1') === '1'); }
function tel_e164(?string $t): ?string {
    $d = preg_replace('/[^0-9+]/', '', (string)$t); if ($d === '') return null;
    if (str_starts_with($d, '+')) return strlen($d) >= 11 ? $d : null;
    if (str_starts_with($d, '00')) return '+' . substr($d, 2);
    if (strlen($d) === 10 && $d[0] === '0') return '+33' . substr($d, 1);
    return null;
}
function sms_mobile(?string $t): bool { $e = tel_e164($t); return $e !== null && (bool)preg_match('/^\+33[67]\d{8}$/', $e); }
function ovh_api(string $method, string $path, ?array $body = null): array {
    $url = 'https://eu.api.ovh.com/1.0' . $path; $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://eu.api.ovh.com/1.0/auth/time'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]); $t = curl_exec($ch); curl_close($ch);
    $ts = is_numeric($t) ? (int)$t : time();
    $sig = '$1$' . sha1(setting('ovh_app_secret') . '+' . setting('ovh_consumer_key') . "+$method+$url+$json+$ts");
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'X-Ovh-Application: ' . setting('ovh_app_key'), 'X-Ovh-Consumer: ' . setting('ovh_consumer_key'), "X-Ovh-Timestamp: $ts", "X-Ovh-Signature: $sig"]] + ($body !== null ? [CURLOPT_POSTFIELDS => $json] : []));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$r, json_decode((string)$r, true)];
}
function sans_accents_sms(string $s): string {
    $t = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C', '’' => "'", '–' => '-', '€' => 'EUR', '«' => '"', '»' => '"']);
    return preg_replace('/[^\x20-\x7E\n]/', '', $t) ?? $t;
}
/** Envoie un SMS ; retourne '' si parti, sinon le motif de l'échec */
function sms_envoyer(string $tel, string $texte, ?int $evtId, string $motif): string {
    $num = tel_e164($tel); if (!$num) return 'Numéro invalide.';
    $txt = mb_substr(sans_accents_sms($texte), 0, 480);
    if (defined('SMS_TEST_DIR')) { @file_put_contents(SMS_TEST_DIR . '/' . date('His') . '-' . bin2hex(random_bytes(3)) . '.txt', "$num\n$txt"); $res = 'test'; }
    else {
        [$code, $raw, $j] = ovh_api('POST', '/sms/' . rawurlencode(setting('ovh_sms_service')) . '/jobs', ['message' => $txt, 'receivers' => [$num], 'noStopClause' => true, 'priority' => 'high', 'coding' => '7bit', 'charset' => 'UTF-8']
            + (setting('sms_expediteur') !== '' ? ['sender' => setting('sms_expediteur'), 'senderForResponse' => false] : ['senderForResponse' => true]));
        $res = $code >= 200 && $code < 300 && empty($j['invalidReceivers']) ? 'ok' : "HTTP $code " . mb_substr($raw, 0, 200);
    }
    q("INSERT INTO sms_journal (evenement_id, numero, message, motif, resultat) VALUES (?,?,?,?,?)", [$evtId, $num, $txt, $motif, $res]);
    if ($evtId && in_array($res, ['ok', 'test'], true)) evt_journal($evtId, 'SMS envoyé au client (' . (SMS_MOTIFS[$motif] ?? $motif) . ')');
    return in_array($res, ['ok', 'test'], true) ? '' : $res;
}

// ===================== Avis Google =====================
function avis_envoyer(array $e): bool {
    $lien = setting('avis_lien'); if ($lien === '') return false;
    $ok = false; $canal = setting('avis_canal', 'email');
    $txt = "Bonjour" . ($e['client_nom'] ? " {$e['client_nom']}" : '') . ",\n\nMerci de nous avoir fait confiance pour « {$e['titre']} ». Si vous êtes satisfait(e) de notre travail, un avis Google nous aiderait beaucoup (30 secondes) :\n$lien\n\nMerci et à bientôt,\n" . setting('ent_nom');
    if (in_array($canal, ['email', 'les_deux'], true) && filter_var($e['client_email'], FILTER_VALIDATE_EMAIL) && client_accepte($e, 'email')) $ok = envoyer_mail($e['client_email'], 'Votre avis compte pour nous', $txt) || $ok;
    if (in_array($canal, ['sms', 'les_deux'], true) && sms_actif('avis') && sms_mobile($e['client_tel']) && client_accepte($e, 'sms')) $ok = sms_envoyer($e['client_tel'], setting('ent_nom') . " : merci pour votre confiance ! Votre avis nous aide beaucoup : $lien", (int)$e['id'], 'avis') === '' || $ok;
    q("UPDATE evenements SET avis_le = NOW() WHERE id = ?", [$e['id']]); // une seule tentative
    if ($ok) { q("UPDATE evenements SET avis_le = NOW() WHERE id = ?", [$e['id']]); evt_journal((int)$e['id'], "Demande d'avis Google envoyée au client"); }
    return $ok;
}

// ===================== Urgence Élagage =====================
/** Appel à l'API v1 d'Urgence Élagage (clé « ue_live_… » créée dans Administration › Entreprise › Intégrations) */
function urgence_api(string $route, array $params = []): ?array {
    $cle = setting('urgence_cle'); if ($cle === '' || !function_exists('curl_init')) return null;
    $url = rtrim(setting('urgence_api', 'https://urgence-elagage.fr/api/v1'), '/') . '/' . $route . ($params ? '?' . http_build_query($params) : '');
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ["Authorization: Bearer $cle", 'Accept: application/json']]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$r, true);
    if ($code !== 200 || empty($j['ok'])) { set_setting('urgence_etat', 'Erreur ' . $code . ' : ' . mb_substr((string)($j['error'] ?? $r), 0, 150)); return null; }
    set_setting('urgence_etat', 'OK le ' . date('d/m/Y H:i'));
    return $j;
}
/** Trouve la fiche du personnel d'après un nom renvoyé par Urgence Élagage (« Prénom NOM ») */
function personnel_par_nom(string $nom): ?int {
    $n = mb_strtolower(trim($nom));
    foreach (personnel_liste(true) as $p) { $a = mb_strtolower(trim($p['prenom'] . ' ' . $p['nom'])); $b = mb_strtolower(trim($p['nom'] . ' ' . $p['prenom'])); if ($n !== '' && ($n === $a || $n === $b || ($p['nom'] === '' && $n === mb_strtolower($p['prenom'])))) return (int)$p['id']; }
    return null;
}
/** Recopie les interventions d'urgence (en cours ou programmées) dans le planning : elles bloquent l'équipe concernée */
function urgence_sync(bool $forcer = false): int {
    if (setting('urgence_cle') === '' || (!$forcer && (int)setting('urgence_sync_t', '0') > time() - 600)) return 0;
    set_setting('urgence_sync_t', (string)time());
    $j = urgence_api('interventions'); if (!$j) return 0;
    $n = 0;
    foreach ($j['items'] ?? [] as $i) {
        $ref = (string)$i['ref']; $st = (string)$i['status'];
        $ex = q("SELECT * FROM evenements WHERE urgence_ref = ?", [$ref])->fetch();
        $t = $i['t'] ?? []; $pl = $i['planif'] ?? null;
        $deb = $pl['debut'] ?? ($t['prise'] ?? ($t['depart'] ?? null)); if (!$deb && !$ex) continue;
        $deb = $deb ? (int)$deb : strtotime($ex['debut']);
        $fin = $pl['fin'] ?? ($t['cloturee'] ?? ($t['annulee'] ?? null)); $fin = $fin ? (int)$fin : max($deb + 3 * 3600, time() + 3600);
        $statut = $st === 'cloturee' ? 'termine' : ($st === 'annulee' ? 'annule' : 'confirme');
        $titre = 'Urgence ' . $ref . ' – ' . mb_substr((string)($i['danger'] ?? ''), 0, 80);
        $vals = [$titre, $statut, date('Y-m-d H:i:s', $deb), date('Y-m-d H:i:s', $fin), mb_substr((string)$i['adresse'], 0, 255), $i['lat'] ?? null, $i['lon'] ?? null, mb_substr((string)($i['collectivite'] ?? ''), 0, 150), 'Urgence Élagage : ' . ($i['statut_label'] ?? $st)];
        if ($ex) { q("UPDATE evenements SET titre = ?, statut = ?, debut = ?, fin = ?, adresse = ?, lat = ?, lng = ?, client_nom = ?, notes = ? WHERE id = ?", array_merge($vals, [$ex['id']])); $id = (int)$ex['id']; }
        else { q("INSERT INTO evenements (titre, statut, debut, fin, adresse, lat, lng, client_nom, notes, type, urgence_ref, priorite) VALUES (?,?,?,?,?,?,?,?,?, 'urgence', ?, 3)", array_merge($vals, [$ref])); $id = (int)db()->lastInsertId(); evt_journal($id, "Intervention d'urgence $ref reprise d'Urgence Élagage"); }
        $pers = array_filter(array_map('personnel_par_nom', array_merge($pl['equipe'] ?? [], $i['equipe'] ?? [])));
        if ($pers) { q("DELETE FROM evenement_personnel WHERE evenement_id = ?", [$id]); foreach (array_unique($pers) as $p) q("INSERT IGNORE INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$id, $p]); }
        $n++;
    }
    return $n;
}
/** Qui est d'astreinte maintenant (mémorisé 10 minutes) */
function urgence_astreinte(): ?array {
    if (setting('urgence_cle') === '') return null;
    $c = json_decode(setting('urgence_astreinte_cache'), true);
    if ($c && ($c['_t'] ?? 0) > time() - 600) return $c;
    $j = urgence_api('astreinte'); if (!$j) return $c ?: null;
    $j['_t'] = time(); set_setting('urgence_astreinte_cache', json_encode($j, JSON_UNESCAPED_UNICODE));
    return $j;
}

// ===================== Pennylane (factures) et GoCardless (prélèvements SEPA, via Pennylane) =====================
function pennylane_pret(): bool { return setting('pl_token') !== ''; }
function pl_api(string $method, string $path, ?array $body = null, ?array $query = null): array {
    $url = rtrim(setting('pl_api', 'https://app.pennylane.com/api/external/v2'), '/') . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url); $h = ['Authorization: Bearer ' . setting('pl_token'), 'Accept: application/json'];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) { $o[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    $o[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($ch, $o);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $txt = $r === false ? $err : (string)$r;
    return [$code, json_decode($txt, true), $txt];
}
function pl_erreur(?array $j, string $txt = ''): string { if (is_array($j)) foreach (['message', 'error', 'errors'] as $k) if (!empty($j[$k])) return is_string($j[$k]) ? $j[$k] : json_encode($j[$k], JSON_UNESCAPED_UNICODE); return mb_substr($txt, 0, 200); }
function pl_filtre(string $champ, string $op, $v): string { return json_encode([['field' => $champ, 'operator' => $op, 'value' => $v]]); }
/** Client Pennylane : mémorisé, sinon retrouvé par la référence EF-CLI-n, sinon créé (particulier ou entreprise / collectivité) */
function pl_client(int $cid): array {
    $c = q("SELECT * FROM clients WHERE id = ?", [$cid])->fetch(); if (!$c) return [false, 'Client introuvable.'];
    if ($c['pl_client_id']) return [true, (int)$c['pl_client_id']];
    $ad = q("SELECT * FROM client_adresses WHERE client_id = ? ORDER BY (libelle = 'Correspondance') DESC, ordre, id LIMIT 1", [$cid])->fetch();
    if (!$ad || $ad['adresse'] === '' || $ad['cp'] === '' || $ad['ville'] === '') return [false, "Adresse incomplète pour {$c['nom']} (adresse, code postal, ville) : complète la fiche client."];
    $ref = 'EF-CLI-' . $cid;
    [$code, $j] = pl_api('GET', '/customers', null, ['filter' => pl_filtre('external_reference', 'eq', $ref)]);
    $id = (int)($j['items'][0]['id'] ?? 0);
    if (!$id) {
        $adr = ['address' => $ad['adresse'], 'postal_code' => $ad['cp'], 'city' => $ad['ville'], 'country_alpha2' => 'FR'];
        $base = ['external_reference' => $ref, 'billing_address' => $adr, 'billing_language' => 'fr_FR'] + (filter_var($c['email'], FILTER_VALIDATE_EMAIL) ? ['emails' => [$c['email']]] : []) + (($c['mobile'] ?: $c['telephone']) ? ['phone' => $c['mobile'] ?: $c['telephone']] : []);
        if ($c['type'] === 'particulier') { $mots = preg_split('/\s+/', trim($c['nom'])); $nom = array_shift($mots); [$code, $j, $txt] = pl_api('POST', '/individual_customers', $base + ['last_name' => $nom, 'first_name' => implode(' ', $mots) ?: $nom]); }
        else [$code, $j, $txt] = pl_api('POST', '/company_customers', $base + ['name' => $c['nom'], 'payment_conditions' => '30_days']);
        $id = (int)($j['id'] ?? 0);
        if (!$id) return [false, "Pennylane a refusé la création du client {$c['nom']} (HTTP $code) : " . pl_erreur($j, $txt ?? '')];
    }
    q("UPDATE clients SET pl_client_id = ? WHERE id = ?", [$id, $cid]);
    return [true, $id];
}
/** Facture brouillon dans Pennylane pour un chantier (ou un passage de contrat) */
function pl_facturer(int $evtId): array {
    if (!pennylane_pret()) return [false, 'Pennylane n\'est pas connecté (Réglages du planning › Pennylane).'];
    $e = evt_charger($evtId); if (!$e) return [false, 'Fiche introuvable.'];
    if ($e['pl_facture_id'] !== '') return [false, 'Déjà facturé dans Pennylane (' . ($e['pl_facture_num'] ?: 'brouillon') . ').'];
    if ($e['devis_montant'] === null || (float)$e['devis_montant'] <= 0) return [false, 'Renseigne le montant HT (bloc Devis) avant de facturer.'];
    $cid = $e['client_id'] ? (int)$e['client_id'] : client_assurer($e); if (!$cid) return [false, 'Pas de client sur ce chantier.'];
    [$ok, $plc] = pl_client($cid); if (!$ok) return [false, $plc];
    $r = rapport_charger($evtId);
    $ligne = ['label' => mb_substr(($e['contrat_id'] ? 'Entretien – ' : '') . $e['titre'], 0, 200), 'quantity' => 1, 'unit' => 'piece', 'raw_currency_unit_price' => number_format((float)$e['devis_montant'], 2, '.', ''), 'vat_rate' => setting('pl_tva', 'FR_200'), 'substance' => 'services'];
    $desc = trim(($e['devis_ref'] ? "Devis {$e['devis_ref']}. " : '') . ($r['texte'] ?: '')); if ($desc !== '') $ligne['description'] = mb_substr($desc, 0, 500);
    $cl = q("SELECT sepa_statut, sepa_ref FROM clients WHERE id = ?", [$cid])->fetch();
    $body = ['customer_id' => $plc, 'draft' => true, 'date' => date('Y-m-d'), 'deadline' => date('Y-m-d', strtotime('+' . max(0, (int)setting('pl_echeance', '30')) . ' days')), 'currency' => 'EUR', 'language' => 'fr_FR',
             'invoice_lines' => [$ligne], 'external_reference' => 'EF-EVT-' . $evtId, 'pdf_invoice_subject' => mb_substr($e['titre'], 0, 200),
             'special_mention' => ($cl['sepa_statut'] ?? '') === 'active' ? 'Règlement par prélèvement SEPA à l\'échéance' . ($cl['sepa_ref'] ? ', mandat ' . $cl['sepa_ref'] : '') . '.' : 'Règlement à réception, par virement ou chèque.',
             'pdf_invoice_free_text' => 'Intervention ' . evt_quand($e) . ($e['adresse'] ? ' – ' . trim($e['adresse'] . ' ' . $e['cp'] . ' ' . $e['ville']) : '') . '.'];
    [$code, $j, $txt] = pl_api('POST', '/customer_invoices', $body);
    if (empty($j['id'])) return [false, "Pennylane a refusé la facture (HTTP $code) : " . pl_erreur($j, $txt)];
    q("UPDATE evenements SET pl_facture_id = ?, pl_facture_num = ?, pl_statut = 'brouillon' WHERE id = ?", [(string)$j['id'], (string)($j['invoice_number'] ?? ''), $evtId]);
    evt_journal($evtId, 'Facture créée en brouillon dans Pennylane');
    return [true, (string)$j['id']];
}
function pl_synchro_factures(): int {
    if (!pennylane_pret()) return 0; $n = 0;
    foreach (q("SELECT id, pl_facture_id, pl_statut FROM evenements WHERE pl_facture_id <> '' AND pl_statut NOT IN ('payee','annulee') LIMIT 50") as $e) {
        [$c, $j] = pl_api('GET', '/customer_invoices/' . rawurlencode($e['pl_facture_id'])); if ($c !== 200 || !is_array($j)) continue;
        $st = !empty($j['draft']) ? 'brouillon' : (!empty($j['paid']) || ($j['status'] ?? '') === 'paid' ? 'payee' : (in_array($j['status'] ?? '', ['cancelled', 'archived'], true) ? 'annulee' : 'emise'));
        if ($st !== $e['pl_statut']) { q("UPDATE evenements SET pl_statut = ?, pl_facture_num = ? WHERE id = ?", [$st, (string)($j['invoice_number'] ?? ''), $e['id']]); $n++; }
    }
    return $n;
}
/** Demande de mandat de prélèvement SEPA (GoCardless) au client, envoyée par Pennylane */
function pl_mandat_demander(int $cid): array {
    if (!pennylane_pret()) return [false, 'Pennylane n\'est pas connecté.'];
    $c = q("SELECT * FROM clients WHERE id = ?", [$cid])->fetch(); if (!$c || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) return [false, 'Il faut l\'e-mail du client.'];
    [$ok, $plc] = pl_client($cid); if (!$ok) return [false, $plc];
    [$code, $j, $txt] = pl_api('POST', '/gocardless_mandates/mail_requests', ['customer_id' => $plc, 'email' => ['recipients' => [$c['email']], 'subject' => setting('ent_nom') . ' · mandat de prélèvement SEPA à signer en ligne',
        'body' => "Bonjour,\n\nPour régler votre contrat d'entretien par prélèvement SEPA, merci de signer le mandat en ligne via le lien ci-dessous (quelques minutes, sécurisé par GoCardless).\n\n" . setting('ent_nom')]]);
    if ($code < 200 || $code >= 300) return [false, "Pennylane n'a pas pu envoyer la demande de mandat (HTTP $code) : " . pl_erreur($j, $txt) . ' Vérifie que GoCardless est connecté dans Pennylane (Paramètres › Paiements).'];
    q("UPDATE clients SET sepa_statut = 'demande' WHERE id = ?", [$cid]);
    return [true, $c['email']];
}
function pl_synchro_mandats(): void {
    if (!pennylane_pret()) return;
    foreach (q("SELECT id, pl_client_id FROM clients WHERE pl_client_id IS NOT NULL AND sepa_statut IN ('demande','active')") as $c) {
        [$code, $j] = pl_api('GET', '/gocardless_mandates', null, ['filter' => pl_filtre('customer_id', 'eq', (int)$c['pl_client_id'])]); if ($code !== 200) continue;
        foreach ((array)($j['items'] ?? []) as $m) if (($m['status'] ?? '') === 'active') { q("UPDATE clients SET sepa_statut = 'active', sepa_ref = ? WHERE id = ?", [(string)($m['external_reference'] ?? '') ?: 'GC-' . $m['id'], $c['id']]); continue 2; }
    }
}

// ===================== Tâches automatiques complémentaires =====================
function planning_taches_ext(): void {
    try { contrats_generer(); } catch (Throwable $t) {}
    try { kleyk_init(); } catch (Throwable $t) {}
    try { trajets_periode(evt_periode(date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+8 days')))); } catch (Throwable $t) {} // prépare les temps de route de la semaine
    try { taches_rappels(); } catch (Throwable $t) {}
    try { agendas_sync_tous(); } catch (Throwable $t) {}
    if (setting('livret_taches_le') !== date('Y-m-d')) { set_setting('livret_taches_le', date('Y-m-d')); try { livret_taches(); } catch (Throwable $t) {} }
    try { contrats_echeances(); } catch (Throwable $t) {}
    if (pennylane_pret() && setting('pl_synchro_le') !== date('Y-m-d')) { set_setting('pl_synchro_le', date('Y-m-d')); try { pl_synchro_factures(); pl_synchro_mandats(); } catch (Throwable $t) {} }
    if (setting('avis_actif') === '1' && setting('avis_lien') !== '') {
        $d = max(0, (int)setting('avis_delai', '1'));
        foreach (q("SELECT * FROM evenements WHERE type = 'chantier' AND statut = 'termine' AND avis_le IS NULL AND termine_le IS NOT NULL AND termine_le <= ? AND termine_le > DATE_SUB(NOW(), INTERVAL 30 DAY) AND (client_email <> '' OR client_tel <> '') LIMIT 20", [date('Y-m-d H:i:s', strtotime("-$d days"))]) as $e) avis_envoyer($e);
    }
}

// ---------- Actions à faire avant le chantier (pense-bête attribué) et prestations du devis (relevé de chantier) ----------
function mon_personnel_id(): int { static $c = null; if ($c === null) { $r = q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1 ORDER BY id LIMIT 1", [user()['id'] ?? 0])->fetch(); $c = $r ? (int)$r['id'] : 0; } return $c; }
function taches_liste(int $eid): array {
    return array_map(fn($t) => $t + ['retard' => !$t['fait_le'] && $t['echeance'] && $t['echeance'] < date('Y-m-d')], q("SELECT t.*, p.prenom, p.nom AS pnom FROM evt_taches t LEFT JOIN personnel p ON p.id = t.personnel_id WHERE t.evenement_id = ? ORDER BY t.fait_le IS NOT NULL, COALESCE(t.echeance, '9999-12-31'), t.id", [$eid])->fetchAll());
}
function tache_echeance(array $e, ?int $delai): ?string { if ($delai === null || !$e['debut']) return null; return date('Y-m-d', strtotime(substr((string)$e['debut'], 0, 10) . " -$delai days")); }
function tache_ajouter(int $eid, string $libelle, ?int $pers, ?string $echeance, ?int $delai = null, string $picto = ''): int {
    $libelle = mb_substr(trim($libelle), 0, 150); if ($libelle === '') return 0;
    q("INSERT INTO evt_taches (evenement_id, libelle, personnel_id, echeance, delai_j, picto, cree_par) VALUES (?,?,?,?,?,?,?)", [$eid, $libelle, $pers ?: null, $echeance, $delai, $picto, user()['id'] ?? null]);
    $id = (int)db()->lastInsertId(); evt_journal($eid, "Action à faire : $libelle");
    if ($pers) tache_notifier($id, 'À faire');
    return $id;
}
function tache_notifier(int $id, string $prefixe): void {
    $t = q("SELECT t.*, e.titre, e.debut, p.utilisateur_id FROM evt_taches t JOIN evenements e ON e.id = t.evenement_id LEFT JOIN personnel p ON p.id = t.personnel_id WHERE t.id = ?", [$id])->fetch(); if (!$t) return;
    $uids = $t['utilisateur_id'] ? [(int)$t['utilisateur_id']] : gestionnaires();
    $moi = (int)(user()['id'] ?? 0); if ($prefixe === 'À faire') $uids = array_filter($uids, fn($u) => $u !== $moi);
    notifier($uids, "$prefixe : {$t['libelle']}", "Chantier « {$t['titre']} »" . ($t['echeance'] ? ' – avant le ' . date('d/m/Y', strtotime($t['echeance'])) : '') . ($t['debut'] ? ' (chantier le ' . date('d/m/Y', strtotime($t['debut'])) . ')' : ''), 'planning.php?evt=' . $t['evenement_id']);
    q("UPDATE evt_taches SET rappel_le = NOW() WHERE id = ?", [$id]);
}
/** Les pictogrammes cochés qui ont une action par défaut créent cette action (une seule fois par picto) */
function taches_depuis_pictos(array $e): int {
    $n = 0; $deja = array_column(q("SELECT picto FROM evt_taches WHERE evenement_id = ? AND picto <> ''", [$e['id']])->fetchAll(), 'picto'); $L = pictos_liste();
    foreach (pictos_evt($e) as $c) if (!in_array($c, $deja, true) && ($L[$c]['action'] ?? '') !== '') { tache_ajouter((int)$e['id'], $L[$c]['action'], null, tache_echeance($e, $L[$c]['delai']), $L[$c]['delai'], $c); $n++; }
    return $n;
}
/** Date du chantier changée : les échéances calculées « J-N » suivent */
function taches_recaler(array $e): void { foreach (q("SELECT id, delai_j FROM evt_taches WHERE evenement_id = ? AND delai_j IS NOT NULL AND fait_le IS NULL", [$e['id']]) as $t) q("UPDATE evt_taches SET echeance = ? WHERE id = ?", [tache_echeance($e, (int)$t['delai_j']), $t['id']]); }
/** Rappels quotidiens : action non faite dont l'échéance est demain ou dépassée (au plus un rappel tous les 2 jours), ou chantier dans 2 jours */
function taches_rappels(): void {
    foreach (q("SELECT t.id FROM evt_taches t JOIN evenements e ON e.id = t.evenement_id WHERE t.fait_le IS NULL AND e.statut NOT IN ('annule','termine') AND (t.rappel_le IS NULL OR t.rappel_le < DATE_SUB(NOW(), INTERVAL 2 DAY)) AND ((t.echeance IS NOT NULL AND t.echeance <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)) OR (e.debut IS NOT NULL AND e.debut BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 2 DAY)))") as $t) tache_notifier((int)$t['id'], 'Rappel');
}
/** Peut cocher / modifier une action : gestion du planning, ou personne attribuée, ou membre du chantier */
function peut_tache(array $t, array $e): bool { if (peut_planifier() && peut_modifier_evt($e['personnel'])) return true; $p = mon_personnel_id(); return $p && ((int)$t['personnel_id'] === $p || in_array($p, $e['personnel'], true)); }
function mes_taches(): array {
    $p = mon_personnel_id(); if (!$p) return [];
    return q("SELECT t.*, e.titre, e.debut, e.ville FROM evt_taches t JOIN evenements e ON e.id = t.evenement_id WHERE t.personnel_id = ? AND t.fait_le IS NULL AND e.statut NOT IN ('annule','termine') ORDER BY COALESCE(t.echeance, DATE(e.debut), '9999-12-31'), t.id LIMIT 50", [$p])->fetchAll();
}

function prestations_liste(int $eid): array { return q("SELECT * FROM evt_prestations WHERE evenement_id = ? ORDER BY ordre, id", [$eid])->fetchAll(); }
function prestation_ajouter(int $eid, string $libelle, $qte = null, string $unite = '', string $source = 'manuel'): int {
    $libelle = mb_substr(trim(preg_replace('/\s+/', ' ', $libelle)), 0, 255); if ($libelle === '') return 0;
    $o = (int)q("SELECT COALESCE(MAX(ordre), 0) + 1 o FROM evt_prestations WHERE evenement_id = ?", [$eid])->fetch()['o'];
    q("INSERT INTO evt_prestations (evenement_id, ordre, libelle, quantite, unite, source) VALUES (?,?,?,?,?,?)", [$eid, $o, $libelle, $qte === null || $qte === '' ? null : (float)$qte, mb_substr(trim($unite), 0, 30), $source]);
    return (int)db()->lastInsertId();
}
/** Reprend les lignes du bordereau (devis interne) comme prestations à réaliser ; ignore celles déjà présentes */
function prestations_depuis_bordereau(int $eid, int $bid): int {
    $deja = array_map('mb_strtolower', array_column(prestations_liste($eid), 'libelle')); $n = 0;
    foreach (q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? AND type = 'ligne' AND libelle <> '' ORDER BY ordre, id", [$bid]) as $l) {
        if (in_array(mb_strtolower(trim($l['libelle'])), $deja, true)) continue;
        if (prestation_ajouter($eid, $l['libelle'], $l['quantite'], (string)$l['unite'], 'bordereau')) $n++;
    }
    if ($n) evt_journal($eid, "$n prestation(s) reprise(s) du devis");
    return $n;
}
/** Lignes collées depuis un devis Extrabat / PDF : une prestation par ligne, « 3 u Abattage… » ou « Abattage… x3 » acceptés */
function prestations_depuis_texte(int $eid, string $texte): int {
    $n = 0;
    foreach (preg_split('/\R/', $texte) as $ln) { $ln = trim($ln); if ($ln === '' || preg_match('/^(total|tva|net|montant|remise|acompte|sous-total)\b/iu', $ln)) continue;
        $q = null; $u = '';
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(u|ml|m2|m²|m3|m³|h|j|f|ens|forfait|arbres?|unités?)?\s+(.+)$/iu', $ln, $m)) { $q = (float)str_replace(',', '.', $m[1]); $u = $m[2]; $ln = $m[3]; }
        elseif (preg_match('/^(.+?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*(u|ml|m2|m²|m3|m³|h|j|arbres?)?\s*$/iu', $ln, $m)) { $ln = $m[1]; $q = (float)str_replace(',', '.', $m[2]); $u = $m[3] ?? ''; }
        $ln = preg_replace('/\s+\d[\d\s.,]*\s*€.*$/u', '', $ln); // prix en fin de ligne retiré
        if (prestation_ajouter($eid, $ln, $q, $u, 'devis')) $n++; }
    if ($n) evt_journal($eid, "$n prestation(s) ajoutée(s) depuis le devis");
    return $n;
}
/** Texte « Travaux réalisés » proposé pour le bon de fin d'intervention */
function prestations_texte(int $eid): string {
    $l = []; foreach (prestations_liste($eid) as $p) $l[] = ($p['fait_le'] ? '✓ ' : '○ ') . $p['libelle'] . ($p['quantite'] !== null ? ' (' . rtrim(rtrim(number_format((float)$p['quantite'], 2, ',', ' '), '0'), ',') . ($p['unite'] ? ' ' . $p['unite'] : '') . ')' : '');
    return implode("\n", $l);
}

// ---------- Agendas externes (abonnement ICS : Google Agenda, iCloud, Outlook, Nextcloud…) rattachés à une personne ----------
function agendas_liste(?int $pid = null): array { return q("SELECT a.*, p.prenom FROM agendas_externes a LEFT JOIN personnel p ON p.id = a.personnel_id" . ($pid ? " WHERE a.personnel_id = ?" : '') . " ORDER BY p.prenom, a.nom", $pid ? [$pid] : [])->fetchAll(); }
function agenda_url_ok(string $u): bool { $u = preg_replace('#^webcals?://#i', 'https://', trim($u)); return (bool)preg_match('#^https?://[^\s]+$#i', $u) && (defined('ICS_TEST_DIR') && str_starts_with($u, 'http://127.0.0.1:') || !preg_match('#^https?://(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)#i', $u)); }
/** Mot de passe d'un agenda protégé : chiffré en base avec une clé propre au serveur (jamais affiché) */
function agenda_cle(): string { return hash('sha256', 'agenda|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|' . __DIR__, true); }
function agenda_chiffrer(string $m): string { $iv = random_bytes(16); return base64_encode($iv . openssl_encrypt($m, 'aes-256-cbc', agenda_cle(), OPENSSL_RAW_DATA, $iv)); }
function agenda_dechiffrer(?string $c): string { if (!$c) return ''; $b = base64_decode($c); return (string)openssl_decrypt(substr($b, 16), 'aes-256-cbc', agenda_cle(), OPENSSL_RAW_DATA, substr($b, 0, 16)); }
/** Requête HTTP authentifiée (Basic) : [code, corps] */
function caldav_req(string $url, string $user, string $mdp, string $methode = 'GET', ?string $corps = null, string $depth = '0'): array {
    $ch = curl_init($url); $h = ['User-Agent: ElagageFigeacois-Planning/1.0'];
    if ($corps !== null) { $h[] = 'Content-Type: application/xml; charset=utf-8'; $h[] = "Depth: $depth"; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_HTTPHEADER => $h, CURLOPT_USERPWD => "$user:$mdp", CURLOPT_HTTPAUTH => CURLAUTH_BASIC | CURLAUTH_DIGEST, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$r === false ? 0 : $code, $r === false ? $err : (string)$r];
}
/** Réponse multistatus WebDAV : [[href, DOMElement propstat/prop], ...] */
function caldav_multi(string $xml): array {
    $d = new DOMDocument(); if (!@$d->loadXML($xml)) return [];
    $out = []; foreach ($d->getElementsByTagNameNS('DAV:', 'response') as $r) { $h = $r->getElementsByTagNameNS('DAV:', 'href')->item(0); $out[] = [$h ? trim($h->textContent) : '', $r]; }
    return $out;
}
/** Lecture d'un agenda CalDAV (Extrabat, iCloud, Nextcloud…) avec identifiant et mot de passe : renvoie un texte iCalendar ou [false, message] */
function caldav_lire(string $url, string $user, string $mdp): array {
    [$c, $t] = caldav_req($url, $user, $mdp); // 1. l'adresse est peut-être déjà un fichier .ics protégé
    if ($c === 401 || $c === 403) return [false, 'identifiant ou mot de passe refusé'];
    if ($c >= 200 && $c < 300 && stripos($t, 'BEGIN:VCALENDAR') !== false) return [$t, ''];
    $p = parse_url($url); $racine = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $abs = fn($h) => preg_match('#^https?://#', $h) ? $h : $racine . '/' . ltrim($h, '/');
    $pf = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:resourcetype/><d:displayname/><d:current-user-principal/><c:calendar-home-set/></d:prop></d:propfind>';
    $cals = []; $vus = []; $file = [$url]; $tours = 0;
    while ($file && $tours++ < 4) { // 2. découverte : calendriers sous l'adresse, sinon principal → calendar-home-set
        $u = array_shift($file); if (isset($vus[$u])) continue; $vus[$u] = 1;
        [$c, $t] = caldav_req($u, $user, $mdp, 'PROPFIND', $pf, '1');
        if ($c === 401 || $c === 403) return [false, 'identifiant ou mot de passe refusé'];
        if ($c !== 207) continue;
        foreach (caldav_multi($t) as [$href, $r]) {
            if ($r->getElementsByTagNameNS('urn:ietf:params:xml:ns:caldav', 'calendar')->length && $r->getElementsByTagNameNS('DAV:', 'resourcetype')->length) $cals[$abs($href)] = 1;
            foreach (['calendar-home-set' => 'urn:ietf:params:xml:ns:caldav', 'current-user-principal' => 'DAV:'] as $tag => $ns) foreach ($r->getElementsByTagNameNS($ns, $tag) as $n) { $h = $n->getElementsByTagNameNS('DAV:', 'href')->item(0); if ($h && trim($h->textContent) !== '') $file[] = $abs(trim($h->textContent)); }
        }
        if ($cals) break;
    }
    if (!$cals) return [false, "aucun calendrier trouvé à cette adresse (vérifie l'adresse du serveur)"];
    $du = gmdate('Ymd\THis\Z', strtotime('-30 days')); $au = gmdate('Ymd\THis\Z', strtotime('+365 days'));
    $rq = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="' . $du . '" end="' . $au . '"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
    $blocs = []; $tz = []; $meta = [];
    foreach (array_slice(array_keys($cals), 0, 10) as $cal) { // 3. événements de chaque calendrier
        [$c, $t] = caldav_req($cal, $user, $mdp, 'REPORT', $rq, '1'); if ($c !== 207) continue;
        $d = new DOMDocument(); if (!@$d->loadXML($t)) continue;
        foreach ($d->getElementsByTagNameNS('DAV:', 'response') as $rr) { $n = $rr->getElementsByTagNameNS('urn:ietf:params:xml:ns:caldav', 'calendar-data')->item(0); if (!$n) continue;
            $hr = $rr->getElementsByTagNameNS('DAV:', 'href')->item(0); $et = $rr->getElementsByTagNameNS('DAV:', 'getetag')->item(0); $brut = $n->textContent; $ics = str_replace("\r\n", "\n", $brut);
            if (preg_match('/^UID:(.+)$/m', $ics, $mu)) $meta[trim($mu[1])] = ['href' => $hr ? $abs(trim($hr->textContent)) : null, 'etag' => $et ? trim($et->textContent) : null, 'ics' => $brut, 'recur' => (bool)preg_match('/^(RRULE|RECURRENCE-ID)/m', $ics)];
            if (preg_match_all('/BEGIN:VTIMEZONE.*?END:VTIMEZONE/s', $ics, $m)) foreach ($m[0] as $z) $tz[md5($z)] = $z;
            if (preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $ics, $m)) foreach ($m[0] as $v) $blocs[] = $v; }
    }
    $GLOBALS['caldav_meta'] = $meta;
    return ["BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Elagage Figeacois//CalDAV//FR\n" . implode("\n", $tz) . ($tz ? "\n" : '') . implode("\n", $blocs) . "\nEND:VCALENDAR\n", ''];
}
/** Modifie un rendez-vous d'un agenda externe protégé (Extrabat…) directement sur son serveur ; renvoie null si c'est fait, sinon le message d'erreur */
function externe_modifier(int $xid, string $debut, string $fin, int $journee, string $titre): ?string {
    $x = q("SELECT x.*, a.identifiant, a.mdp, a.personnel_id, a.visibilite, p.utilisateur_id AS proprio FROM evenements_externes x JOIN agendas_externes a ON a.id = x.agenda_id LEFT JOIN personnel p ON p.id = a.personnel_id WHERE x.id = ?", [$xid])->fetch();
    if (!$x) return 'Rendez-vous introuvable (agenda relu entre-temps) : actualise la page.';
    $u = user(); if (!in_array($u['role'] ?? '', ['admin', 'secretaire'], true) && (int)$x['proprio'] !== (int)($u['id'] ?? 0)) return "Seuls le propriétaire de l'agenda et le bureau peuvent le modifier.";
    $err = externe_ecrire($x, $debut, $fin, $journee, $titre);
    if ($err === null) { $n = q("SELECT id FROM evenements WHERE xb_agenda_id = ? AND xb_uid = ?", [$x['agenda_id'], $x['uid']])->fetch(); if ($n) q("UPDATE evenements SET debut = ?, fin = ?, journee = ?, titre = ?, maj_le = NOW() WHERE id = ?", [$debut, $fin, $journee, mb_substr($titre ?: $x['titre'], 0, 200), $n['id']]); }
    return $err;
}
/** Écrit un rendez-vous sur le serveur de son agenda (sans contrôle des droits : appelé après vérification) */
function externe_ecrire(array $x, string $debut, string $fin, int $journee, string $titre): ?string {
    $xid = (int)$x['id'];
    if ($x['identifiant'] === '' || !$x['href'] || !$x['ics']) return "Cet agenda n'est pas modifiable depuis le planning (lien en lecture seule).";
    if ((int)$x['recur']) return 'Rendez-vous répétitif : modifie-le dans son application.';
    if (!strtotime($debut) || !strtotime($fin) || strtotime($fin) <= strtotime($debut)) return 'Horaires invalides.';
    $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $x['ics'])); // lignes dépliées
    $f = fn($d) => gmdate('Ymd\THis\Z', strtotime($d)); $titre = trim(str_replace(["\r", "\n"], ' ', $titre)) ?: $x['titre'];
    $ics = preg_replace_callback('/BEGIN:VEVENT\n(.*?)END:VEVENT/s', function ($m) use ($debut, $fin, $journee, $f, $titre) {
        $l = array_values(array_filter(explode("\n", $m[1]), fn($ln) => $ln !== '' && !preg_match('/^(DTSTART|DTEND|DURATION|DTSTAMP|LAST-MODIFIED)[;:]/i', $ln)));
        $l = array_map(fn($ln) => preg_match('/^SUMMARY[;:]/i', $ln) ? 'SUMMARY:' . str_replace([',', ';'], ['\\,', '\\;'], $titre) : (preg_match('/^SEQUENCE:(\d+)/i', $ln, $s) ? 'SEQUENCE:' . ((int)$s[1] + 1) : $ln), $l);
        $dt = $journee ? ['DTSTART;VALUE=DATE:' . date('Ymd', strtotime($debut)), 'DTEND;VALUE=DATE:' . date('Ymd', strtotime(date('Y-m-d', strtotime($fin) - 1) . ' +1 day'))] : ['DTSTART:' . $f($debut), 'DTEND:' . $f($fin)];
        return "BEGIN:VEVENT\n" . implode("\n", array_merge($dt, ['DTSTAMP:' . gmdate('Ymd\THis\Z'), 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z')], $l)) . "\nEND:VEVENT";
    }, $ics, 1);
    $ch = curl_init($x['href']); $h = ['Content-Type: text/calendar; charset=utf-8', 'User-Agent: ElagageFigeacois-Planning/1.0']; if ($x['etag']) $h[] = 'If-Match: ' . $x['etag'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => str_replace("\n", "\r\n", $ics), CURLOPT_HTTPHEADER => $h, CURLOPT_USERPWD => $x['identifiant'] . ':' . agenda_dechiffrer($x['mdp']), CURLOPT_HTTPAUTH => CURLAUTH_BASIC | CURLAUTH_DIGEST]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($code === 412) { agenda_sync((int)$x['agenda_id']); return 'Ce rendez-vous a été modifié entre-temps dans son application : agenda relu, recommence.'; }
    if ($code === 401 || $code === 403) return 'Le serveur de l\'agenda refuse la modification (droits du compte en lecture seule ?).';
    if ($code < 200 || $code >= 300) return "Le serveur de l'agenda n'a pas accepté la modification (réponse $code).";
    $etag = $r !== false && preg_match('/^etag:\s*(.+)$/mi', substr((string)$r, 0, $hs), $m) ? trim($m[1]) : null;
    q("UPDATE evenements_externes SET debut = ?, fin = ?, journee = ?, titre = ?, etag = ?, ics = ? WHERE id = ?", [$debut, $fin, $journee, mb_substr($titre, 0, 190), $etag, str_replace("\n", "\r\n", $ics), $xid]);
    if (!$etag) try { agenda_sync((int)$x['agenda_id']); } catch (Throwable $t) {}
    return null;
}
/** Reprend des rendez-vous d'un agenda externe protégé (Extrabat…) comme vrais rendez-vous du planning (propriétaire de l'agenda affecté).
 *  Le lien est gardé : modifier le rendez-vous dans le planning (ou sur le téléphone) le modifie aussi dans l'agenda d'origine, et inversement. Renvoie le nombre repris. */
function externes_reprendre(int $agendaId, ?int $xid = null): int {
    $a = q("SELECT * FROM agendas_externes WHERE id = ?", [$agendaId])->fetch(); if (!$a) return 0; $n = 0;
    $sql = "SELECT * FROM evenements_externes WHERE agenda_id = ? AND recur = 0 AND " . ($xid ? 'id = ' . (int)$xid : 'fin >= NOW()') . " ORDER BY debut";
    foreach (q($sql, [$agendaId]) as $x) {
        if (q("SELECT id FROM evenements WHERE xb_agenda_id = ? AND xb_uid = ?", [$agendaId, $x['uid']])->fetch()) continue;
        $type = preg_match('/visite|devis|chiffrage|estimation/i', $x['titre']) ? 'visite' : 'rdv';
        q("INSERT INTO evenements (type, titre, statut, debut, fin, journee, adresse, notes, cree_par, cree_le, maj_le, xb_agenda_id, xb_uid, kleyk_init) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,1)",
          [$type, mb_substr($x['titre'] ?: 'Rendez-vous', 0, 200), 'confirme', $x['debut'], $x['fin'], (int)$x['journee'], mb_substr($x['lieu'], 0, 255), 'Repris de l\'agenda « ' . $a['nom'] . ' » : les changements sont répercutés des deux côtés.', user()['id'] ?? null, $agendaId, $x['uid']]);
        $id = (int)db()->lastInsertId(); q("INSERT INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$id, (int)$a['personnel_id']]);
        evt_journal($id, 'Repris de l\'agenda « ' . $a['nom'] . ' »'); $n++;
    }
    return $n;
}
/** Un rendez-vous du planning repris d'un agenda externe vient d'être modifié (planning, téléphone) : même changement dans l'agenda d'origine. Renvoie null ou le message d'erreur. */
function externes_repercuter(int $evtId): ?string {
    $e = q("SELECT * FROM evenements WHERE id = ?", [$evtId])->fetch(); if (!$e || !$e['xb_agenda_id'] || !$e['xb_uid']) return null;
    $x = q("SELECT x.*, a.identifiant, a.mdp FROM evenements_externes x JOIN agendas_externes a ON a.id = x.agenda_id WHERE x.agenda_id = ? AND x.uid = ? LIMIT 1", [$e['xb_agenda_id'], $e['xb_uid']])->fetch();
    if (!$x) return null;
    if ($x['debut'] === $e['debut'] && $x['fin'] === $e['fin'] && $x['titre'] === mb_substr($e['titre'], 0, 190)) return null;
    if ($e['statut'] === 'annule') return null; // une annulation ne supprime rien dans l'agenda d'origine
    return externe_ecrire($x, $e['debut'], $e['fin'], (int)$e['journee'], $e['titre']);
}
/** Télécharge et relit un agenda ; renvoie le nombre d'événements (ou false + message d'erreur enregistré) */
function agenda_sync(int $id): int|false {
    $a = q("SELECT * FROM agendas_externes WHERE id = ?", [$id])->fetch(); if (!$a) return false;
    $url = preg_replace('#^webcals?://#i', 'https://', trim($a['url'])); $txt = false; $err = '';
    if (($a['identifiant'] ?? '') !== '' && function_exists('curl_init')) { [$txt, $err] = caldav_lire($url, $a['identifiant'], agenda_dechiffrer($a['mdp'] ?? '')); } // agenda protégé (CalDAV)
    elseif (defined('ICS_TEST_DIR') && is_dir(ICS_TEST_DIR)) { $f = ICS_TEST_DIR . '/' . md5($url) . '.ics'; $txt = is_file($f) ? file_get_contents($f) : false; if ($txt === false) $err = 'fichier test absent'; }
    elseif (function_exists('curl_init')) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'ElagageFigeacois-Planning/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        $txt = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); if ($txt === false) $err = 'téléchargement impossible : ' . curl_error($ch); elseif ($code >= 400) { $err = "réponse $code du serveur"; $txt = false; } curl_close($ch);
    } else { $txt = @file_get_contents($url); if ($txt === false) $err = 'téléchargement impossible'; }
    if ($txt !== false && stripos($txt, 'BEGIN:VCALENDAR') === false) { $txt = false; $err = "ce n'est pas un agenda iCalendar (vérifie que le lien est l'adresse .ics / webcal)"; }
    if ($txt === false) { q("UPDATE agendas_externes SET erreur = ?, maj_le = NOW() WHERE id = ?", [mb_substr($err, 0, 255), $id]); return false; }
    $evs = ics_lire($txt, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+365 days')));
    db()->beginTransaction();
    $anciens = []; foreach (q("SELECT uid, debut, fin, titre FROM evenements_externes WHERE agenda_id = ?", [$id]) as $o) $anciens[$o['uid']] = $o;
    foreach ($evs as $e) { $o = $anciens[$e['uid']] ?? null; // modifié dans l'agenda d'origine depuis la dernière lecture : le rendez-vous repris suit
        if ($o && ($o['debut'] !== $e['debut'] || $o['fin'] !== $e['fin'] || $o['titre'] !== mb_substr($e['titre'], 0, 190))) {
            $nv = q("SELECT id FROM evenements WHERE xb_agenda_id = ? AND xb_uid = ?", [$id, $e['uid']])->fetch();
            if ($nv) { q("UPDATE evenements SET debut = ?, fin = ?, journee = ?, titre = ?, maj_le = NOW() WHERE id = ?", [$e['debut'], $e['fin'], $e['journee'], mb_substr($e['titre'], 0, 200), $nv['id']]); evt_journal((int)$nv['id'], 'Modifié dans l\'agenda « ' . $a['nom'] . ' » : repris dans le planning'); } } }
    q("DELETE FROM evenements_externes WHERE agenda_id = ?", [$id]);
    $meta = ($a['identifiant'] ?? '') !== '' ? ($GLOBALS['caldav_meta'] ?? []) : []; $nbUid = array_count_values(array_column($evs, 'uid'));
    foreach ($evs as $e) { $mt = $meta[$e['uid']] ?? null;
        q("INSERT INTO evenements_externes (agenda_id, uid, debut, fin, journee, titre, lieu, href, etag, ics, recur) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$id, mb_substr($e['uid'], 0, 190), $e['debut'], $e['fin'], $e['journee'], mb_substr($e['titre'], 0, 190), mb_substr($e['lieu'], 0, 190), $mt['href'] ?? null, $mt['etag'] ?? null, $mt['ics'] ?? null, ($mt['recur'] ?? false) || ($nbUid[$e['uid']] ?? 1) > 1 ? 1 : 0]); }
    q("UPDATE agendas_externes SET erreur = '', maj_le = NOW(), nb = ? WHERE id = ?", [count($evs), $id]);
    db()->commit();
    return count($evs);
}
function agendas_sync_tous(): void { foreach (q("SELECT id FROM agendas_externes WHERE actif = 1 AND (maj_le IS NULL OR maj_le < DATE_SUB(NOW(), INTERVAL 55 MINUTE))") as $a) try { agenda_sync((int)$a['id']); } catch (Throwable $t) { try { db()->rollBack(); } catch (Throwable $t2) {} } }
/** Événements externes qui chevauchent [du, au[, sous la forme d'événements du planning (lecture seule) */
function externes_periode(string $du, string $au, ?int $pid = null): array {
    $l = [];
    $u = user(); $bureau = in_array($u['role'] ?? '', ['admin', 'secretaire'], true);
    $repris = []; try { foreach (q("SELECT xb_agenda_id, xb_uid FROM evenements WHERE xb_agenda_id IS NOT NULL AND statut <> 'annule'") as $r) $repris[$r['xb_agenda_id'] . '|' . $r['xb_uid']] = 1; } catch (Throwable $t) {}
    foreach (q("SELECT x.*, a.personnel_id, a.nom AS agenda, a.couleur, a.prive, a.visibilite, a.identifiant, p.utilisateur_id AS proprio FROM evenements_externes x JOIN agendas_externes a ON a.id = x.agenda_id LEFT JOIN personnel p ON p.id = a.personnel_id WHERE a.actif = 1 AND x.debut < ? AND x.fin > ?" . ($pid ? " AND a.personnel_id = ?" : '') . " ORDER BY x.debut", $pid ? [$au, $du, $pid] : [$au, $du]) as $x) {
        $moi = $u && (int)$x['proprio'] === (int)$u['id'];
        if ($x['visibilite'] === 'bureau' && !$bureau && !$moi) continue;
        if (isset($repris[$x['agenda_id'] . '|' . $x['uid']])) continue; // déjà repris comme vrai rendez-vous du planning // agenda réservé au bureau (secrétariat, administrateur) et à son propriétaire
        $l[] = ['id' => 'x' . $x['id'], 'externe' => 1, 'modifiable' => ($bureau || $moi) && $x['identifiant'] !== '' && $x['href'] && !(int)$x['recur'] && !((int)$x['prive'] && !$moi && !$bureau) ? 1 : 0, 'agenda_nom' => $x['agenda'], 'agenda' => $x['agenda'], 'couleur' => $x['couleur'], 'type' => 'externe', 'titre' => (int)$x['prive'] ? 'Occupé (' . $x['agenda'] . ')' : $x['titre'], 'statut' => 'confirme', 'debut' => $x['debut'], 'fin' => $x['fin'], 'journee' => (int)$x['journee'],
                'client_nom' => '', 'client_tel' => '', 'client_email' => '', 'adresse' => (int)$x['prive'] ? '' : $x['lieu'], 'cp' => '', 'ville' => '', 'lat' => null, 'lng' => null, 'personnel' => [(int)$x['personnel_id']], 'materiel' => [], 'equipe_id' => null, 'devis_montant' => null, 'notes' => '', 'consignes' => '', 'motif' => '', 'pictos' => '', 'phyto' => 0, 'priorite' => 2, 'alertes' => []];
    }
    return $l;
}
/** Lecture d'un fichier iCalendar : VEVENT avec dates (jour entier ou horaire, fuseaux), RRULE simples (quotidien, hebdo avec BYDAY, mensuel, annuel ; INTERVAL, COUNT, UNTIL), EXDATE, occurrences modifiées (RECURRENCE-ID). Fenêtre [du, au]. */
function ics_lire(string $txt, string $du, string $au): array {
    $txt = str_replace(["\r\n", "\r"], "\n", $txt); $txt = preg_replace("/\n[ \t]/", '', $txt); // dépliage des lignes
    $tDu = strtotime("$du 00:00:00"); $tAu = strtotime("$au 23:59:59"); $out = []; $modifs = []; $tzDef = 'Europe/Paris';
    if (preg_match('/^X-WR-TIMEZONE:(.+)$/mi', $txt, $m) && in_array(trim($m[1]), timezone_identifiers_list(), true)) $tzDef = trim($m[1]);
    $lireDate = function (string $val, string $params, bool &$journee) use ($tzDef): ?int { // → timestamp
        $val = trim($val); $journee = str_contains(strtoupper($params), 'VALUE=DATE') && !str_contains($val, 'T') || preg_match('/^\d{8}$/', $val) === 1;
        if ($journee) { $d = DateTime::createFromFormat('Ymd', substr($val, 0, 8), new DateTimeZone($tzDef)); return $d ? $d->setTime(0, 0)->getTimestamp() : null; }
        $tz = $tzDef; if (preg_match('/TZID=([^;:]+)/i', $params, $m)) { $z = trim($m[1], '"'); if (in_array($z, timezone_identifiers_list(), true)) $tz = $z; }
        $utc = str_ends_with($val, 'Z'); $d = DateTime::createFromFormat('Ymd\THis', rtrim($val, 'Z'), new DateTimeZone($utc ? 'UTC' : $tz)); return $d ? $d->getTimestamp() : null;
    };
    preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $txt, $blocs);
    foreach ($blocs[1] as $bloc) {
        $p = []; foreach (explode("\n", $bloc) as $ln) { if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $ln, $m)) continue; $p[$m[1]][] = [$m[2], $m[3]]; }
        if (empty($p['DTSTART'])) continue;
        $uid = trim($p['UID'][0][1] ?? md5($bloc)); $titre = isset($p['SUMMARY']) ? trim(preg_replace('/\\\\([,;])/', '$1', str_replace('\\n', ' ', $p['SUMMARY'][0][1]))) : '(sans titre)';
        $lieu = isset($p['LOCATION']) ? trim(preg_replace('/\\\\([,;])/', '$1', str_replace('\\n', ' ', $p['LOCATION'][0][1]))) : '';
        if (isset($p['STATUS']) && strtoupper(trim($p['STATUS'][0][1])) === 'CANCELLED') continue;
        $j = false; $deb = $lireDate($p['DTSTART'][0][1], $p['DTSTART'][0][0], $j); if ($deb === null) continue;
        $fin = null; $jf = false; if (isset($p['DTEND'])) $fin = $lireDate($p['DTEND'][0][1], $p['DTEND'][0][0], $jf);
        elseif (isset($p['DURATION']) && preg_match('/P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?/', $p['DURATION'][0][1], $m)) $fin = $deb + (int)($m[1] ?? 0) * 604800 + (int)($m[2] ?? 0) * 86400 + (int)($m[3] ?? 0) * 3600 + (int)($m[4] ?? 0) * 60;
        if ($fin === null || $fin <= $deb) $fin = $j ? $deb + 86400 : $deb + 3600;
        $duree = $fin - $deb;
        $ajouter = function (int $d) use (&$out, $uid, $titre, $lieu, $j, $duree, $tDu, $tAu) { $f = $d + $duree; if ($f <= $tDu || $d > $tAu) return; $out[$uid . '@' . $d] = ['uid' => $uid, 'titre' => $titre, 'lieu' => $lieu, 'journee' => $j ? 1 : 0, 'debut' => date('Y-m-d H:i:s', $d), 'fin' => date('Y-m-d H:i:s', $f)]; };
        if (isset($p['RECURRENCE-ID'])) { $jr = false; $r = $lireDate($p['RECURRENCE-ID'][0][1], $p['RECURRENCE-ID'][0][0], $jr); if ($r !== null) $modifs[$uid][] = $r; $ajouter($deb); continue; }
        if (empty($p['RRULE'])) { $ajouter($deb); continue; }
        $r = []; foreach (explode(';', strtoupper($p['RRULE'][0][1])) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $r[$k] = $v; }
        $ex = []; foreach ($p['EXDATE'] ?? [] as [$pa, $va]) foreach (explode(',', $va) as $v) { $jx = false; $x = $lireDate($v, $pa, $jx); if ($x !== null) $ex[] = $x; }
        $int = max(1, (int)($r['INTERVAL'] ?? 1)); $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null; $until = null;
        if (isset($r['UNTIL'])) { $ju = false; $until = $lireDate($r['UNTIL'], str_contains($r['UNTIL'], 'T') ? '' : 'VALUE=DATE', $ju); if ($ju && $until !== null) $until += 86399; }
        $freq = $r['FREQ'] ?? ''; $jours = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
        $byday = []; if ($freq === 'WEEKLY' && !empty($r['BYDAY'])) foreach (explode(',', $r['BYDAY']) as $d) if (isset($jours[$d])) $byday[] = $jours[$d];
        $n = 0; $i = 0; $base = new DateTime('@' . $deb); $base->setTimezone(new DateTimeZone($tzDef)); $limite = min($tAu, $until ?? $tAu);
        $estExclu = fn(int $d) => in_array($d, $ex, true) || in_array(date('Y-m-d', $d), array_map(fn($x) => date('Y-m-d', $x), $ex), true) && $j;
        while ($i < 2000) {
            $occ = [];
            if ($freq === 'WEEKLY' && $byday) { $sem = (clone $base)->modify('+' . ($i * $int) . ' weeks'); $lundi = (clone $sem)->modify(($sem->format('N') === '1' ? 'this' : 'last') . ' monday'); foreach ($byday as $wd) { $d = (clone $lundi)->modify('+' . (($wd + 6) % 7) . ' days')->setTime((int)$base->format('H'), (int)$base->format('i'), (int)$base->format('s')); if ($d->getTimestamp() >= $deb) $occ[] = $d->getTimestamp(); } sort($occ); }
            else { $d = clone $base; $pas = ['DAILY' => 'days', 'WEEKLY' => 'weeks', 'MONTHLY' => 'months', 'YEARLY' => 'years'][$freq] ?? null; if (!$pas) break; if ($i) $d->modify('+' . ($i * $int) . ' ' . $pas); $occ[] = $d->getTimestamp(); }
            foreach ($occ as $t) { if ($t > $limite) break 2; if ($count !== null && $n >= $count) break 2; $n++; if ($estExclu($t) || in_array($t, $modifs[$uid] ?? [], true)) continue; $ajouter($t); }
            $i++; if ($occ && end($occ) > $limite) break;
        }
    }
    foreach ($modifs as $uid => $dates) foreach ($dates as $d) unset($out[$uid . '@' . $d]); // l'occurrence de base remplacée par sa version modifiée
    return array_values($out);
}

/** Traite les formulaires « agendas » (ajout, actualisation, suppression) ; $pids = personnes que l'utilisateur peut gérer (null = toutes) */
function agendas_post(?array $pids): ?string {
    if (($_POST['action'] ?? '') !== 'agenda') return null;
    $op = (string)($_POST['op'] ?? ''); $pid = (int)($_POST['personnel_id'] ?? 0); $id = (int)($_POST['id'] ?? 0);
    if ($id) { $a = q("SELECT * FROM agendas_externes WHERE id = ?", [$id])->fetch(); if (!$a) return 'Agenda introuvable.'; $pid = (int)$a['personnel_id']; }
    if ($pids !== null && !in_array($pid, $pids, true)) return "Tu ne peux pas gérer l'agenda de cette personne.";
    if ($op === 'ajouter') {
        $url = trim((string)($_POST['url'] ?? '')); $nom = mb_substr(trim((string)($_POST['nom'] ?? '')), 0, 80) ?: 'Agenda perso';
        if (!$pid) return 'Choisis la personne.'; if (!agenda_url_ok($url)) return "Adresse invalide : il faut l'adresse https:// ou webcal:// du calendrier (format iCal / .ics).";
        $idt = mb_substr(trim((string)($_POST['identifiant'] ?? '')), 0, 120); $mdp = (string)($_POST['mdp'] ?? '');
        q("INSERT INTO agendas_externes (personnel_id, nom, url, couleur, prive, identifiant, mdp, visibilite) VALUES (?,?,?,?,?,?,?,?)", [$pid, $nom, mb_substr($url, 0, 500), preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['couleur'] ?? '')) ? $_POST['couleur'] : '#9aa5b1', !empty($_POST['prive']) ? 1 : 0, $idt, $idt !== '' && $mdp !== '' ? agenda_chiffrer($mdp) : null, ($_POST['visibilite'] ?? '') === 'bureau' ? 'bureau' : 'tous']);
        $n = agenda_sync((int)db()->lastInsertId()); $a = q("SELECT erreur FROM agendas_externes WHERE id = LAST_INSERT_ID()")->fetch();
        return $n === false ? "Agenda ajouté mais impossible à lire : " . ($a['erreur'] ?? '') : "Agenda ajouté : $n événement(s) repris.";
    }
    if ($op === 'actualiser' && $id) { q("UPDATE agendas_externes SET maj_le = NULL WHERE id = ?", [$id]); $n = agenda_sync($id); $a = q("SELECT erreur FROM agendas_externes WHERE id = ?", [$id])->fetch(); return $n === false ? 'Lecture impossible : ' . $a['erreur'] : "Agenda actualisé : $n événement(s)."; }
    if ($op === 'supprimer' && $id) { q("DELETE FROM evenements_externes WHERE agenda_id = ?", [$id]); q("DELETE FROM agendas_externes WHERE id = ?", [$id]); return 'Agenda retiré.'; }
    if ($op === 'reprendre' && $id) { if (($a['identifiant'] ?? '') === '') return "Seuls les agendas protégés (Extrabat…) peuvent être repris : un lien .ics est en lecture seule."; $n = externes_reprendre($id); return $n ? "$n rendez-vous à venir repris dans le planning : modifiables ici et sur les téléphones, les changements repartent dans « {$a['nom']} »." : 'Rien de nouveau à reprendre.'; }
    if ($op === 'visibilite' && $id) { q("UPDATE agendas_externes SET visibilite = ? WHERE id = ?", [($_POST['visibilite'] ?? '') === 'bureau' ? 'bureau' : 'tous', $id]); return 'Visibilité de l\'agenda enregistrée.'; }
    if ($op === 'couleur' && $id) { $c = (string)($_POST['couleur'] ?? ''); if (!preg_match('/^#[0-9a-f]{6}$/i', $c)) return 'Couleur invalide.'; q("UPDATE agendas_externes SET couleur = ? WHERE id = ?", [$c, $id]); return 'Couleur de l\'agenda enregistrée.'; }
    if ($op === 'actif' && $id) { q("UPDATE agendas_externes SET actif = 1 - actif WHERE id = ?", [$id]); return 'Agenda mis à jour.'; }
    return 'Opération inconnue.';
}
/** Bloc HTML « Agenda » : lien d'abonnement sortant (le planning dans le téléphone) et agendas externes entrants, pour une ou plusieurs personnes */
function agendas_bloc(array $personnes, bool $choixPersonne): string {
    $h = '<h2>Agenda et calendriers</h2>';
    if (count($personnes) === 1) { $p = $personnes[0]; if ($p['ics_token']) { $l = lien_base() . '/planning-ics.php?t=' . $p['ics_token']; $h .= '<h3 style="margin:8px 0 4px">1. Voir mon planning dans le calendrier du téléphone</h3><p class="small muted" style="margin:0 0 6px">Mes chantiers et rendez-vous apparaissent dans Calendrier (iPhone), Google Agenda ou Outlook, mis à jour tout seuls. Ce lien est personnel : ne pas le transmettre.</p>'
        . '<p style="margin:0 0 4px"><button type="button" class="mini" onclick="navigator.clipboard.writeText(this.dataset.l).then(()=>this.textContent=\'Lien copié !\')" data-l="' . e($l) . '">Copier le lien d\'abonnement</button> <a class="mini" href="' . e(preg_replace('#^https?://#', 'webcal://', $l)) . '">Ouvrir dans l\'app Calendrier</a></p>'
        . '<details class="small"><summary>Comment l\'ajouter ?</summary><ul style="margin:4px 0 0 18px"><li><b>iPhone</b> : touche « Ouvrir dans l\'app Calendrier » (ou Réglages › Apps › Calendrier › Comptes › Nouveau compte › Autre › Ajouter un cal. avec abonnement, puis colle le lien).</li><li><b>Google Agenda</b> (sur ordinateur) : à gauche, « Autres agendas » › + › À partir de l\'URL › colle le lien.</li><li><b>Outlook</b> : Ajouter un calendrier › S\'abonner à partir du web.</li></ul></details>'; } }
    $h .= '<h3 style="margin:14px 0 4px">' . (count($personnes) === 1 ? '2. ' : '') . 'Afficher mes autres calendriers dans le planning</h3><p class="small muted" style="margin:0 0 6px">Rendez-vous perso, autre agenda pro… : ils s\'affichent en gris dans le planning (lecture seule) et comptent comme « occupé » quand on planifie. Il faut l\'adresse <b>iCal / .ics</b> du calendrier, ou pour un agenda protégé (Extrabat…) l\'adresse du serveur avec l\'identifiant et le mot de passe (lecture seule) : <b>Google Agenda</b> › paramètres de l\'agenda › « Adresse secrète au format iCal » ; <b>iCloud</b> › partager le calendrier › « Calendrier public » › copier le lien webcal ; <b>Outlook</b> › Calendrier partagé › Publier › lien ICS ; <b>Nextcloud</b> › lien d\'abonnement. Un lien CalDAV classique (avec mot de passe) n\'est pas pris en charge : utilise l\'adresse de publication iCal, c\'est le même contenu.</p>';
    $ids = array_map(fn($p) => (int)$p['id'], $personnes); $liste = array_filter(agendas_liste(), fn($a) => in_array((int)$a['personnel_id'], $ids, true));
    if ($liste) { $h .= '<div style="overflow-x:auto"><table class="tbl"><tr>' . ($choixPersonne ? '<th>Personne</th>' : '') . '<th>Calendrier</th><th>Événements</th><th>Dernière lecture</th><th></th></tr>';
        foreach ($liste as $a) $h .= '<tr' . ((int)$a['actif'] ? '' : ' style="opacity:.55"') . '>' . ($choixPersonne ? '<td>' . e($a['prenom'] ?? '') . '</td>' : '') . '<td><i class="conf" style="background:' . e($a['couleur']) . '"></i>' . e($a['nom']) . ((int)$a['prive'] ? ' <span class="small muted">(titres masqués)</span>' : '') . (($a['identifiant'] ?? '') !== '' ? ' <span class="small muted">(protégé : ' . e($a['identifiant']) . ')</span>' : '') . '<br><span class="small muted" style="word-break:break-all">' . e(mb_strimwidth($a['url'], 0, 70, '…')) . '</span></td><td>' . (int)$a['nb'] . '</td><td class="small">' . ($a['maj_le'] ? date('d/m H:i', strtotime($a['maj_le'])) : '—') . ($a['erreur'] ? '<br><span style="color:#e6332a">' . e($a['erreur']) . '</span>' : '') . '</td>'
            . '<td style="white-space:nowrap"><form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="agenda"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><select name="visibilite" title="Qui voit cet agenda dans le planning" style="width:auto;margin:0;padding:4px 6px;vertical-align:middle" onchange="const b=document.createElement(\'input\');b.type=\'hidden\';b.name=\'op\';b.value=\'visibilite\';this.form.appendChild(b);this.form.submit()"><option value="tous"' . (($a['visibilite'] ?? 'tous') === 'tous' ? ' selected' : '') . '>Visible par tous</option><option value="bureau"' . (($a['visibilite'] ?? '') === 'bureau' ? ' selected' : '') . '>Bureau seulement</option></select> <input type="color" name="couleur" value="' . e($a['couleur']) . '" title="Changer la couleur de cet agenda dans le planning" style="width:40px;height:30px;padding:2px;vertical-align:middle;margin:0" onchange="const b=document.createElement(\'input\');b.type=\'hidden\';b.name=\'op\';b.value=\'couleur\';this.form.appendChild(b);this.form.submit()"> <button class="mini" name="op" value="actualiser">Actualiser</button>' . (($a['identifiant'] ?? '') !== '' ? ' <button class="mini" name="op" value="reprendre" title="Crée de vrais rendez-vous du planning à partir des rendez-vous à venir de cet agenda ; les changements sont répercutés des deux côtés">Reprendre dans le planning</button>' : '') . ' <button class="mini" name="op" value="actif">' . ((int)$a['actif'] ? 'Masquer' : 'Afficher') . '</button> <button class="mini danger" name="op" value="supprimer" onclick="return confirm(\'Retirer ce calendrier du planning ?\')">Retirer</button></form></td></tr>';
        $h .= '</table></div>'; }
    $h .= '<form method="post" class="inline" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="action" value="agenda"><input type="hidden" name="op" value="ajouter">';
    $h .= $choixPersonne ? '<select name="personnel_id" required><option value="">Personne…</option>' . implode('', array_map(fn($p) => '<option value="' . (int)$p['id'] . '">' . e($p['prenom'] . ' ' . $p['nom']) . '</option>', $personnes)) . '</select>' : '<input type="hidden" name="personnel_id" value="' . (int)$personnes[0]['id'] . '">';
    $h .= '<input name="nom" placeholder="Nom (ex. Perso, Google)" style="max-width:170px"><input name="url" placeholder="https://… ou webcal://… (adresse iCal / .ics)" required style="min-width:280px;flex:1"><input type="color" name="couleur" value="#9aa5b1" title="Couleur" style="width:44px;padding:2px"><label class="small" style="display:inline-flex;gap:4px;align-items:center;margin:0"><input type="checkbox" name="prive" value="1" style="width:auto;margin:0"> masquer les titres (afficher « Occupé »)</label><details class="agenda-prot" style="flex-basis:100%"><summary class="small">Agenda protégé par un identifiant et un mot de passe (CalDAV : Extrabat, iCloud, Nextcloud…)</summary><div class="inline" style="margin-top:6px"><input name="identifiant" placeholder="Nom d\'utilisateur" autocomplete="off" style="max-width:220px"><input type="password" name="mdp" placeholder="Mot de passe" autocomplete="new-password" style="max-width:220px"><select name="visibilite" style="width:auto"><option value="tous">Visible par tous</option><option value="bureau">Bureau seulement (secrétariat, administrateur)</option></select><span class="small muted">Adresse du serveur dans le champ ci-dessus (ex. https://synchro.extrabat.com/identifiant/). Les rendez-vous de cet agenda se modifient depuis le planning (propriétaire et bureau). Le mot de passe est chiffré sur le serveur et n\'est jamais réaffiché.</span></div></details><button class="btn">Ajouter</button></form>';
    return $h;
}

// ---------- Extrabat : appel brut d'exploration (adresse, clé et mode d'authentification réglés dans Réglages) ----------
function xb_appel(string $chemin, string $methode = 'GET', ?array $corps = null, ?string $baseForce = null, ?string $authForce = null, int $timeout = 20, bool $form = false): array { // [code HTTP, corps (texte), message]
    $base = rtrim($baseForce ?? setting('xb_api', 'https://api.extrabat.com/v1'), '/'); $cle = setting('xb_cle'); if ($base === '' || $cle === '') return [0, '', "Renseigne l'adresse de l'API et la clé."];
    if (!preg_match('#^https://#i', $base)) return [0, '', "L'adresse doit commencer par https://"];
    $chemin = '/' . ltrim(trim($chemin), '/'); if (preg_match('/[\s<>"]/', $chemin)) return [0, '', 'Chemin invalide.'];
    $url = $base . $chemin; $h = ['Accept: application/json', 'User-Agent: ElagageFigeacois-EspacePro/1.0']; $auth = $authForce ?? setting('xb_auth', 'bearer'); $nom = trim(setting('xb_entete', 'X-API-KEY')) ?: 'X-API-KEY';
    if (str_starts_with($auth, 'jwt:')) $h[] = 'Authorization: Bearer ' . substr($auth, 4); elseif ($auth === 'aucun') {} elseif ($auth === 'basicbrut') $h[] = 'Authorization: Basic ' . $cle; elseif ($auth === 'bearer') $h[] = 'Authorization: Bearer ' . $cle; elseif ($auth === 'entete') $h[] = "$nom: $cle"; elseif ($auth === 'param') $url .= (str_contains($url, '?') ? '&' : '?') . rawurlencode($nom) . '=' . rawurlencode($cle);
    if (!function_exists('curl_init')) return [0, '', 'curl indisponible sur le serveur'];
    $ch = curl_init($url); $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $h, CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_HEADER => true];
    if ($auth === 'basic') { $o[CURLOPT_USERPWD] = 'api:' . $cle; }
    if ($corps !== null) { $o[CURLOPT_POSTFIELDS] = $form ? http_build_query($corps) : json_encode($corps); $h[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json'; $o[CURLOPT_HTTPHEADER] = $h; }
    curl_setopt_array($ch, $o); $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); $ht = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($r === false) return [0, '', $err ?: 'pas de réponse'];
    $entetes = substr($r, 0, $ht); $txt = substr($r, $ht); $ct = preg_match('/^content-type:\s*(.+)$/mi', $entetes, $m) ? trim($m[1]) : '';
    if (str_contains($ct, 'json') && ($j = json_decode($txt, true)) !== null) $txt = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [$code, "[$ct]\n" . mb_substr($txt, 0, 5000), $code >= 400 ? 'refusé' : ''];
}

/** Extrabat répond « JWT Token not found » : la clé s'échange d'abord contre un jeton (JWT) sur un point de connexion. Essaie les combinaisons usuelles, mémorise celle qui marche. */
function xb_jeton(bool $forcer = false): array { // [jeton|'' , journal]
    $cle = setting('xb_cle'); if ($cle === '') return ['', "Clé absente.\n"];
    if (!$forcer && setting('xb_jwt') !== '' && (int)setting('xb_jwt_exp') > time() + 60) return [setting('xb_jwt'), ''];
    $base = rtrim(setting('xb_api', 'https://api.extrabat.com/v1'), '/'); $tp = trim(setting('xb_third', '')); $racine = preg_replace('#/v\d+$#', '', $base);
    $bases = array_values(array_unique([$base, $racine, "$racine/api", "$base/api", "$racine/auth", "$racine/v2"]));
    $chemins = ['/login_check', '/login', '/auth', '/authenticate', '/token', '/auth/login', '/auth/token', '/oauth/token', '/oauth/v2/token', '/api-key/login', '/apikey', '/user/login', '/security/login', '/jwt', '/connect', '/session'];
    $corps = [['apiKey' => $cle], ['api_key' => $cle], ['key' => $cle], ['token' => $cle], ['userApiKey' => $cle], ['username' => $cle, 'password' => $cle], ['_username' => $cle, '_password' => $cle]];
    if (($dec = base64_decode($cle, true)) !== false && str_contains($dec, ':') && preg_match('/^[\x20-\x7e]+$/', $dec)) { [$u, $pw] = explode(':', $dec, 2); // clé = base64(identifiant:secret) → on essaie les deux parties séparément, en priorité
        $corps = array_merge([['username' => $u, 'password' => $pw], ['_username' => $u, '_password' => $pw], ['login' => $u, 'password' => $pw], ['email' => $u, 'password' => $pw], ['apiKey' => $u, 'apiSecret' => $pw], ['api_key' => $u, 'api_secret' => $pw], ['key' => $u, 'secret' => $pw], ['id' => $u, 'secret' => $pw], ['client_id' => $u, 'client_secret' => $pw, 'grant_type' => 'client_credentials'], ['publicKey' => $u, 'privateKey' => $pw], ['identifier' => $u, 'password' => $pw], ['uuid' => $u, 'token' => $pw]], $corps); }
    if ($tp !== '') $corps = array_merge([['username' => $tp, 'password' => $cle], ['_username' => $tp, '_password' => $cle], ['login' => $tp, 'password' => $cle], ['thirdParty' => $tp, 'apiKey' => $cle], ['third_party' => $tp, 'api_key' => $cle], ['client_id' => $tp, 'client_secret' => $cle, 'grant_type' => 'client_credentials']], $corps);
    if (setting('xb_login_corps') !== '' && ($j = json_decode(str_replace(['{CLE}', '{ID}', '{SECRET}'], [$cle, $u ?? '', $pw ?? ''], setting('xb_login_corps')), true))) array_unshift($corps, $j);
    if (setting('xb_login_chemin') !== '') array_unshift($chemins, setting('xb_login_chemin'));
    $log = ''; $n = 0; $extraire = function (string $txt) { $j = json_decode(preg_replace('/^\[[^\]]*\]\n/', '', $txt), true); $t = $j['token'] ?? $j['access_token'] ?? $j['jwt'] ?? $j['data']['token'] ?? $j['accessToken'] ?? ''; return is_string($t) && substr_count($t, '.') === 2 ? $t : ''; };
    foreach ($bases as $b) { $log .= "— $b\n";
        foreach ($chemins as $c) { $premier = true;
            foreach (['GET', 'POST'] as $meth) { $n++; [$code, $txt, $m] = xb_appel($c, $meth, $meth === 'POST' ? [] : null, $b, 'basicbrut', 10); if ($code === 0) { $log .= "  injoignable ($m)\n"; continue 3; }
                if ($code !== 404 && $code !== 405) { $log .= '  ' . str_pad($c, 18) . "[$meth, Authorization: Basic <clé>] → $code  " . mb_substr(preg_replace('/\s+/', ' ', preg_replace('/^\[[^\]]*\]\n/', '', $txt)), 0, 110) . "\n";
                    if ($code >= 200 && $code < 300 && ($tok = $extraire($txt)) !== '') { set_setting('xb_jwt', $tok); set_setting('xb_jwt_exp', (string)(time() + 3000)); set_setting('xb_login_chemin', $c); set_setting('xb_login_corps', 'BASIC'); if ($b !== $base) set_setting('xb_login_base', $b); $log .= "✔ Jeton obtenu via $b$c avec Authorization: Basic\n"; return [$tok, $log]; } } }
            foreach ($corps as $i => $bd) foreach ([false, true] as $form) {
                $n++; [$code, $txt, $m] = xb_appel($c, 'POST', $bd, $b, 'aucun', 10, $form);
                if ($code === 0) { $log .= "  injoignable ($m)\n"; continue 3; }
                $resume = mb_substr(preg_replace('/\s+/', ' ', preg_replace('/^\[[^\]]*\]\n/', '', $txt)), 0, 110);
                if ($code === 404 || $code === 405 && $premier) { $log .= '  ' . str_pad($c, 18) . "→ $code\n"; continue 3; } $premier = false;
                $log .= '  ' . str_pad($c, 18) . '[' . implode(',', array_keys($bd)) . ($form ? ' form' : ' json') . "] → $code  $resume\n";
                if ($code >= 200 && $code < 300 && ($tok = $extraire($txt)) !== '') { $exp = time() + 3000; if (($pl = json_decode(base64_decode(strtr(explode('.', $tok)[1], '-_', '+/')), true)) && !empty($pl['exp'])) $exp = (int)$pl['exp'];
                    set_setting('xb_jwt', $tok); set_setting('xb_jwt_exp', (string)$exp); set_setting('xb_login_chemin', $c); set_setting('xb_login_corps', json_encode(array_map(fn($v) => $v === $cle ? '{CLE}' : (isset($u) && $v === $u ? '{ID}' : (isset($pw) && $v === $pw ? '{SECRET}' : $v)), $bd))); if ($b !== $base) set_setting('xb_login_base', $b);
                    $log .= "✔ Jeton obtenu via $b$c (valable jusqu'au " . date('d/m H:i', $exp) . ")\n"; return [$tok, $log]; }
            } } }
    return ['', $log . "Aucun point de connexion n'a rendu de jeton ($n essais).\n"];
}
/** Forme de la clé (sans la révéler) : longueur, base64 ?, contenu décodé « a:b » ? */
function xb_diag_cle(): string {
    $cle = setting('xb_cle'); if ($cle === '') return 'clé absente';
    $d = ['longueur ' . strlen($cle)]; $d[] = substr_count($cle, '.') === 2 ? 'ressemble à un JWT (2 points)' : 'pas un JWT';
    $dec = base64_decode($cle, true);
    if ($dec !== false && strlen($dec) > 4) { $imp = preg_match('/^[\x20-\x7e]+$/', $dec); $d[] = 'base64 valide → ' . strlen($dec) . ' octets ' . ($imp ? 'lisibles' : 'binaires'); if ($imp && str_contains($dec, ':')) { [$g, $dr] = explode(':', $dec, 2); $d[] = "contenu de la forme « identifiant:secret » (identifiant de " . strlen($g) . " caractères, commence par « " . mb_substr($g, 0, 3) . "… », secret de " . strlen($dr) . " caractères)"; } elseif ($imp) $d[] = 'contenu décodé : ' . mb_substr($dec, 0, 4) . '… (' . (json_decode($dec, true) ? 'JSON' : 'texte') . ')'; }
    else $d[] = 'pas du base64';
    return implode(' ; ', $d);
}
/** Appel Extrabat avec jeton JWT (obtenu automatiquement) ; renouvelle le jeton si 401 */
function xb_get(string $chemin): array {
    [$tok, $log] = xb_jeton(); if ($tok === '') return [0, '', "Pas de jeton : " . trim($log)];
    [$code, $txt, $m] = xb_appel($chemin, 'GET', null, null, 'jwt:' . $tok);
    if ($code === 401) { [$tok] = xb_jeton(true); if ($tok !== '') [$code, $txt, $m] = xb_appel($chemin, 'GET', null, null, 'jwt:' . $tok); }
    return [$code, $txt, $m];
}
