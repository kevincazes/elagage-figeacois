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
    try { contrats_echeances(); } catch (Throwable $t) {}
    if (pennylane_pret() && setting('pl_synchro_le') !== date('Y-m-d')) { set_setting('pl_synchro_le', date('Y-m-d')); try { pl_synchro_factures(); pl_synchro_mandats(); } catch (Throwable $t) {} }
    if (setting('avis_actif') === '1' && setting('avis_lien') !== '') {
        $d = max(0, (int)setting('avis_delai', '1'));
        foreach (q("SELECT * FROM evenements WHERE type = 'chantier' AND statut = 'termine' AND avis_le IS NULL AND termine_le IS NOT NULL AND termine_le <= ? AND termine_le > DATE_SUB(NOW(), INTERVAL 30 DAY) AND (client_email <> '' OR client_tel <> '') LIMIT 20", [date('Y-m-d H:i:s', strtotime("-$d days"))]) as $e) avis_envoyer($e);
    }
}
