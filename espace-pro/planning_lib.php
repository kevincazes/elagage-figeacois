<?php
// Planning des chantiers et rendez-vous : données, conflits, créneaux, tournées, e-mails clients, agenda (ICS)
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const TYPES_EVT = [
    'chantier'  => ['Chantier', '#2f6b45'],
    'visite'    => ['Visite de chiffrage', '#c77a12'],
    'rdv'       => ['Rendez-vous', '#3b6ea8'],
    'absence'   => ['Absence', '#8a8f96'],
    'entretien' => ['Entretien matériel', '#7a4fa0'],
    'urgence'   => ['Urgence', '#e6332a'],
];
const STATUTS_EVT = [
    'a_planifier' => 'À planifier', 'propose' => 'Date proposée au client', 'confirme' => 'Confirmé',
    'termine' => 'Terminé', 'annule' => 'Annulé',
];
const COMPETENCES = ['grimpeur' => 'Grimpeur', 'nacelle' => 'CACES nacelle', 'pl' => 'Permis poids lourd', 'abattage' => 'Abattage', 'broyeur' => 'Broyeur', 'rogneuse' => 'Rogneuse', 'chef' => "Chef d'équipe"];
const TYPES_MAT = ['nacelle' => 'Nacelle', 'broyeur' => 'Broyeur', 'camion' => 'Camion', 'rogneuse' => 'Rogneuse', 'grue' => 'Grue', 'vehicule' => 'Véhicule léger', 'autre' => 'Autre'];

function planning_alters(): array { return ["ALTER TABLE personnel ADD COLUMN caldav_mdp VARCHAR(255) NULL", "ALTER TABLE evenements ADD COLUMN caldav_nom VARCHAR(190) NULL", "ALTER TABLE evenements ADD COLUMN caldav_uid VARCHAR(190) NULL", "ALTER TABLE agendas_externes ADD COLUMN identifiant VARCHAR(120) NOT NULL DEFAULT ''", "ALTER TABLE agendas_externes ADD COLUMN mdp TEXT NULL", "ALTER TABLE evenements ADD COLUMN kleyk_init TINYINT NOT NULL DEFAULT 0", "ALTER TABLE materiel ADD COLUMN porteur_id INT NULL", "ALTER TABLE evenements ADD COLUMN equipe_id INT NULL", "ALTER TABLE utilisateurs ADD COLUMN planning_droit VARCHAR(10) NOT NULL DEFAULT 'lecture'",
              "ALTER TABLE evenements ADD COLUMN dict_requise TINYINT NOT NULL DEFAULT 0", "ALTER TABLE evenements ADD COLUMN dict_ref VARCHAR(60) NOT NULL DEFAULT ''", "ALTER TABLE evenements ADD COLUMN dict_envoi DATE NULL", "ALTER TABLE evenements ADD COLUMN dict_retour DATE NULL",
              "ALTER TABLE evenements ADD COLUMN contrat_id INT NULL", "ALTER TABLE evenements ADD COLUMN urgence_ref VARCHAR(40) NOT NULL DEFAULT ''", "ALTER TABLE evenements ADD COLUMN termine_le DATETIME NULL", "ALTER TABLE evenements ADD COLUMN avis_le DATETIME NULL",
              "ALTER TABLE evenements ADD COLUMN secteur_id INT NULL", "ALTER TABLE evenements ADD COLUMN contrat_mois VARCHAR(7) NOT NULL DEFAULT ''", "ALTER TABLE evenements ADD COLUMN phyto TINYINT NOT NULL DEFAULT 0", "ALTER TABLE evenements ADD COLUMN motif VARCHAR(60) NOT NULL DEFAULT ''", "ALTER TABLE evenements ADD COLUMN client_id INT NULL", "ALTER TABLE evenements ADD COLUMN pictos VARCHAR(200) NOT NULL DEFAULT ''",
              "CREATE TABLE IF NOT EXISTS agendas_externes (id INT AUTO_INCREMENT PRIMARY KEY, personnel_id INT NOT NULL, nom VARCHAR(80) NOT NULL, url VARCHAR(500) NOT NULL, couleur VARCHAR(7) NOT NULL DEFAULT '#9aa5b1', prive TINYINT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1, nb INT NOT NULL DEFAULT 0, maj_le DATETIME NULL, erreur VARCHAR(255) NOT NULL DEFAULT '', cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (personnel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
              "CREATE TABLE IF NOT EXISTS trajets_cache (cle VARCHAR(64) PRIMARY KEY, km DECIMAL(7,1) NOT NULL, min INT NOT NULL, maj DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
              "CREATE TABLE IF NOT EXISTS evenements_externes (id INT AUTO_INCREMENT PRIMARY KEY, agenda_id INT NOT NULL, uid VARCHAR(190) NOT NULL DEFAULT '', debut DATETIME NOT NULL, fin DATETIME NOT NULL, journee TINYINT NOT NULL DEFAULT 0, titre VARCHAR(190) NOT NULL DEFAULT '', lieu VARCHAR(190) NOT NULL DEFAULT '', INDEX (agenda_id), INDEX (debut)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
              "CREATE TABLE IF NOT EXISTS evt_prestations (id INT AUTO_INCREMENT PRIMARY KEY, evenement_id INT NOT NULL, ordre INT NOT NULL DEFAULT 0, libelle VARCHAR(255) NOT NULL, quantite DECIMAL(10,2) NULL, unite VARCHAR(30) NOT NULL DEFAULT '', source VARCHAR(20) NOT NULL DEFAULT 'manuel', fait_le DATETIME NULL, fait_par INT NULL, remarque VARCHAR(255) NOT NULL DEFAULT '', INDEX (evenement_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
              "CREATE TABLE IF NOT EXISTS evt_taches (id INT AUTO_INCREMENT PRIMARY KEY, evenement_id INT NOT NULL, libelle VARCHAR(150) NOT NULL, personnel_id INT NULL, echeance DATE NULL, delai_j INT NULL, picto VARCHAR(30) NOT NULL DEFAULT '', fait_le DATETIME NULL, fait_par INT NULL, rappel_le DATETIME NULL, cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (evenement_id), INDEX (personnel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "ALTER TABLE contrats ADD COLUMN client_id INT NULL", "ALTER TABLE evenements ADD INDEX (client_id)",
              "ALTER TABLE evenements ADD COLUMN pl_facture_id VARCHAR(40) NOT NULL DEFAULT ''", "ALTER TABLE evenements ADD COLUMN pl_facture_num VARCHAR(40) NOT NULL DEFAULT ''", "ALTER TABLE evenements ADD COLUMN pl_statut VARCHAR(20) NOT NULL DEFAULT ''",
              "ALTER TABLE clients ADD COLUMN pl_client_id INT NULL", "ALTER TABLE clients ADD COLUMN sepa_statut VARCHAR(30) NOT NULL DEFAULT ''", "ALTER TABLE clients ADD COLUMN sepa_ref VARCHAR(60) NOT NULL DEFAULT ''"]; }
function planning_defauts(): array { return ['planning_debut' => '08:00', 'planning_fin' => '17:00', 'planning_vitesse' => '55', 'planning_facteur' => '1.3', 'planning_vent' => '50',
    'planning_pictos' => "plantation|plant|Plantation d'arbres|Commander les plants, tuteurs et paillage|10\ncirculation|cone|Arrêté de circulation / signalisation|Demander l'arrêté de circulation en mairie|15\nlocation|box|Location de matériel spécifique|Réserver la location|7\nnacelle|crane|Grue ou nacelle de location|Réserver la grue / nacelle|7\nbenne|log|Évacuation du bois / benne|Prévoir la benne ou la remorque|3\nreseaux|bolt|Proximité réseaux (lignes électriques)|Vérifier la DT-DICT et la mise hors tension|9\ntoiture|house|Proximité habitation / toiture|Prévenir le client (protections, accès)|2\nacces|key|Accès à prévoir (clé, badge, portail)|Récupérer les clés / l'accès|1\nnidification|bird|Période de nidification à vérifier||\nsouche|stump|Rognage de souche||\nmairie|clipboard|Autorisation mairie / voirie|Déposer la demande d'autorisation|20\nplatane|tree|Platane (chancre coloré)||\nkleyk|kleyk|Fiche d'intervention Kleyk|Remplir la fiche d'intervention dans Kleyk|0|chantier,urgence",

    'planning_motifs' => "Visite de chiffrage\nRendez-vous prospect\nRéunion de chantier\nVisite de fin de travaux\nRéception de travaux\nVisite de suivi / contrôle\nRendez-vous collectivité\nRendez-vous fournisseur\nFormation / sécurité",
    'planning_depot' => '133 rue Madeleine Brès, 46100 Cambes', 'planning_depot_lat' => '44.6001', 'planning_depot_lng' => '1.9475']; }
function planning_migrate(): void {
    $pdo = db();
    if (setting('schema_planning') === '4') { // colonnes et réglages ajoutés après coup : toujours tentés (sans effet s'ils existent)
        foreach (planning_alters() as $sql) { try { $pdo->exec($sql); } catch (Throwable $t) {} }
        foreach (planning_defauts() as $k => $v) if (setting($k) === '') set_setting($k, $v);
        kleyk_picto_ajouter();
        return;
    }
    foreach ([
        "CREATE TABLE IF NOT EXISTS personnel (id INT AUTO_INCREMENT PRIMARY KEY, prenom VARCHAR(60) NOT NULL, nom VARCHAR(60) NOT NULL DEFAULT '', poste VARCHAR(80) NOT NULL DEFAULT '',
            telephone VARCHAR(30) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '', couleur VARCHAR(7) NOT NULL DEFAULT '#2f6b45', competences VARCHAR(255) NOT NULL DEFAULT '',
            utilisateur_id INT NULL, ics_token VARCHAR(40) NOT NULL DEFAULT '', ordre INT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS materiel (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(100) NOT NULL, type VARCHAR(30) NOT NULL DEFAULT 'autre', immatriculation VARCHAR(30) NOT NULL DEFAULT '',
            competence_requise VARCHAR(30) NOT NULL DEFAULT '', controle_le DATE NULL, couleur VARCHAR(7) NOT NULL DEFAULT '#7a4fa0', notes VARCHAR(255) NOT NULL DEFAULT '',
            ordre INT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS evenements (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL DEFAULT 'chantier', titre VARCHAR(200) NOT NULL DEFAULT '', statut VARCHAR(20) NOT NULL DEFAULT 'a_planifier',
            debut DATETIME NULL, fin DATETIME NULL, journee TINYINT NOT NULL DEFAULT 0,
            client_nom VARCHAR(150) NOT NULL DEFAULT '', client_tel VARCHAR(30) NOT NULL DEFAULT '', client_email VARCHAR(190) NOT NULL DEFAULT '',
            adresse VARCHAR(255) NOT NULL DEFAULT '', cp VARCHAR(10) NOT NULL DEFAULT '', ville VARCHAR(100) NOT NULL DEFAULT '', lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL,
            devis_ref VARCHAR(60) NOT NULL DEFAULT '', devis_montant DECIMAL(12,2) NULL, devis_signe TINYINT NOT NULL DEFAULT 0, devis_lien VARCHAR(500) NOT NULL DEFAULT '', bordereau_id INT NULL,
            duree_h DECIMAL(6,2) NULL, nb_jours DECIMAL(4,1) NULL, besoin_personnes INT NOT NULL DEFAULT 0, besoin_materiel VARCHAR(255) NOT NULL DEFAULT '', priorite TINYINT NOT NULL DEFAULT 2,
            souhait_client VARCHAR(255) NOT NULL DEFAULT '', notes TEXT NULL, consignes TEXT NULL,
            token VARCHAR(40) NOT NULL DEFAULT '', proposition_le DATETIME NULL, client_reponse VARCHAR(20) NOT NULL DEFAULT '', client_message TEXT NULL, client_repondu_le DATETIME NULL, rappel_le DATETIME NULL,
            cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, maj_le DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (debut), INDEX (statut)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS evenement_personnel (evenement_id INT NOT NULL, personnel_id INT NOT NULL, PRIMARY KEY (evenement_id, personnel_id), INDEX (personnel_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS evenement_materiel (evenement_id INT NOT NULL, materiel_id INT NOT NULL, PRIMARY KEY (evenement_id, materiel_id), INDEX (materiel_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS evenement_journal (id INT AUTO_INCREMENT PRIMARY KEY, evenement_id INT NOT NULL, quand DATETIME DEFAULT CURRENT_TIMESTAMP, qui INT NULL, quoi VARCHAR(500) NOT NULL, INDEX (evenement_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS equipes_pl (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(80) NOT NULL, couleur VARCHAR(7) NOT NULL DEFAULT '#2f6b45', chef_id INT NULL, ordre INT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS equipe_membres (equipe_id INT NOT NULL, personnel_id INT NOT NULL, PRIMARY KEY (equipe_id, personnel_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS equipe_materiel (equipe_id INT NOT NULL, materiel_id INT NOT NULL, PRIMARY KEY (equipe_id, materiel_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS droits_agenda (utilisateur_id INT NOT NULL, personnel_id INT NOT NULL, PRIMARY KEY (utilisateur_id, personnel_id)) ENGINE=InnoDB",
        // v4 : bons d'intervention, contrats, congés, secteurs, notifications, SMS
        "CREATE TABLE IF NOT EXISTS rapports (id INT AUTO_INCREMENT PRIMARY KEY, evenement_id INT NOT NULL, modele VARCHAR(10) NOT NULL DEFAULT 'visite', texte TEXT NULL, reserves TEXT NULL, dechets VARCHAR(40) NOT NULL DEFAULT '', ressenti VARCHAR(10) NOT NULL DEFAULT '',
            signataire VARCHAR(150) NOT NULL DEFAULT '', signature MEDIUMTEXT NULL, signe_le DATETIME NULL, ip VARCHAR(45) NOT NULL DEFAULT '', empreinte VARCHAR(64) NOT NULL DEFAULT '', envoye_le DATETIME NULL, envoye_a VARCHAR(190) NOT NULL DEFAULT '',
            cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, maj_le DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE (evenement_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS rapport_photos (id INT AUTO_INCREMENT PRIMARY KEY, evenement_id INT NOT NULL, fichier VARCHAR(80) NOT NULL, original VARCHAR(80) NOT NULL DEFAULT '', legende VARCHAR(200) NOT NULL DEFAULT '', ordre INT NOT NULL DEFAULT 0, dans_pdf TINYINT NOT NULL DEFAULT 1,
            cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (evenement_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS contrat_modeles (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, texte MEDIUMTEXT NOT NULL, actif TINYINT NOT NULL DEFAULT 1, maj_le DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS contrats (id INT AUTO_INCREMENT PRIMARY KEY, numero VARCHAR(30) NOT NULL DEFAULT '', modele_id INT NULL, client_nom VARCHAR(150) NOT NULL DEFAULT '', client_email VARCHAR(190) NOT NULL DEFAULT '', client_tel VARCHAR(30) NOT NULL DEFAULT '',
            adresse VARCHAR(255) NOT NULL DEFAULT '', cp VARCHAR(10) NOT NULL DEFAULT '', ville VARCHAR(100) NOT NULL DEFAULT '', lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL,
            objet TEXT NULL, montant_ht DECIMAL(12,2) NULL, tva DECIMAL(5,2) NOT NULL DEFAULT 20, mois VARCHAR(40) NOT NULL DEFAULT '', duree_h DECIMAL(6,2) NULL, nb_jours DECIMAL(4,1) NULL, equipe_id INT NULL,
            date_debut DATE NULL, duree_mois INT NOT NULL DEFAULT 12, tacite TINYINT NOT NULL DEFAULT 1, statut VARCHAR(20) NOT NULL DEFAULT 'brouillon', token VARCHAR(40) NOT NULL DEFAULT '',
            texte_final MEDIUMTEXT NULL, empreinte VARCHAR(64) NOT NULL DEFAULT '', signataire VARCHAR(150) NOT NULL DEFAULT '', signature MEDIUMTEXT NULL, signe_le DATETIME NULL, ip VARCHAR(45) NOT NULL DEFAULT '', envoye_le DATETIME NULL,
            notes TEXT NULL, cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, maj_le DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS conges (id INT AUTO_INCREMENT PRIMARY KEY, personnel_id INT NOT NULL, du DATE NOT NULL, au DATE NOT NULL, demi VARCHAR(10) NOT NULL DEFAULT '', type VARCHAR(20) NOT NULL DEFAULT 'conges', motif VARCHAR(255) NOT NULL DEFAULT '',
            statut VARCHAR(20) NOT NULL DEFAULT 'demande', demande_par INT NULL, demande_le DATETIME DEFAULT CURRENT_TIMESTAMP, traite_par INT NULL, traite_le DATETIME NULL, reponse VARCHAR(255) NOT NULL DEFAULT '', evenement_id INT NULL, INDEX (personnel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS secteurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(80) NOT NULL, couleur VARCHAR(7) NOT NULL DEFAULT '#3b6ea8', lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL, rayon_km DECIMAL(6,1) NOT NULL DEFAULT 15,
            jours VARCHAR(20) NOT NULL DEFAULT '', ordre INT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, utilisateur_id INT NOT NULL, titre VARCHAR(150) NOT NULL, corps VARCHAR(500) NOT NULL DEFAULT '', url VARCHAR(255) NOT NULL DEFAULT '', cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, lu_le DATETIME NULL, INDEX (utilisateur_id, lu_le)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS push_abonnements (id INT AUTO_INCREMENT PRIMARY KEY, utilisateur_id INT NOT NULL, endpoint VARCHAR(600) NOT NULL, cle VARCHAR(64) NOT NULL, appareil VARCHAR(120) NOT NULL DEFAULT '', cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, vu_le DATETIME NULL, UNIQUE (cle)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS clients (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(150) NOT NULL, type VARCHAR(20) NOT NULL DEFAULT 'particulier', telephone VARCHAR(30) NOT NULL DEFAULT '', mobile VARCHAR(30) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '',
            ok_sms TINYINT NOT NULL DEFAULT 1, ok_email TINYINT NOT NULL DEFAULT 1, ref_externe VARCHAR(40) NOT NULL DEFAULT '', notes TEXT NULL, actif TINYINT NOT NULL DEFAULT 1, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, maj_le DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX (nom)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS client_adresses (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, libelle VARCHAR(60) NOT NULL DEFAULT 'Chantier', adresse VARCHAR(255) NOT NULL DEFAULT '', cp VARCHAR(10) NOT NULL DEFAULT '', ville VARCHAR(100) NOT NULL DEFAULT '',
            lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL, interlocuteur VARCHAR(150) NOT NULL DEFAULT '', tel VARCHAR(30) NOT NULL DEFAULT '', ordre INT NOT NULL DEFAULT 0, INDEX (client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS client_docs (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, dossier VARCHAR(40) NOT NULL DEFAULT 'Documents', nom VARCHAR(200) NOT NULL, fichier VARCHAR(80) NOT NULL, taille INT NOT NULL DEFAULT 0, cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS client_vus (utilisateur_id INT NOT NULL, client_id INT NOT NULL, vu_le DATETIME NOT NULL, PRIMARY KEY (utilisateur_id, client_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sms_journal (id INT AUTO_INCREMENT PRIMARY KEY, evenement_id INT NULL, numero VARCHAR(30) NOT NULL, message VARCHAR(500) NOT NULL, motif VARCHAR(30) NOT NULL DEFAULT '', resultat VARCHAR(255) NOT NULL DEFAULT '', cree_le DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $sql) $pdo->exec($sql);
    foreach (planning_alters() as $sql) { try { $pdo->exec($sql); } catch (Throwable $t) {} }
    foreach (planning_defauts() as $k => $v) if (setting($k) === '') set_setting($k, $v);
    foreach ([] + ['planning_debut' => '08:00', 'planning_fin' => '17:00', 'planning_vitesse' => '55', 'planning_facteur' => '1.3', 'planning_vent' => '50',
              'planning_pictos' => "plantation|plant|Plantation d'arbres|Commander les plants, tuteurs et paillage|10\ncirculation|cone|Arrêté de circulation / signalisation|Demander l'arrêté de circulation en mairie|15\nlocation|box|Location de matériel spécifique|Réserver la location|7\nnacelle|crane|Grue ou nacelle de location|Réserver la grue / nacelle|7\nbenne|log|Évacuation du bois / benne|Prévoir la benne ou la remorque|3\nreseaux|bolt|Proximité réseaux (lignes électriques)|Vérifier la DT-DICT et la mise hors tension|9\ntoiture|house|Proximité habitation / toiture|Prévenir le client (protections, accès)|2\nacces|key|Accès à prévoir (clé, badge, portail)|Récupérer les clés / l'accès|1\nnidification|bird|Période de nidification à vérifier||\nsouche|stump|Rognage de souche||\nmairie|clipboard|Autorisation mairie / voirie|Déposer la demande d'autorisation|20\nplatane|tree|Platane (chancre coloré)||\nkleyk|kleyk|Fiche d'intervention Kleyk|Remplir la fiche d'intervention dans Kleyk|0|chantier,urgence",

    'planning_motifs' => "Visite de chiffrage\nRendez-vous prospect\nRéunion de chantier\nVisite de fin de travaux\nRéception de travaux\nVisite de suivi / contrôle\nRendez-vous collectivité\nRendez-vous fournisseur\nFormation / sécurité",
              'planning_depot' => '133 rue Madeleine Brès, 46100 Cambes', 'planning_depot_lat' => '44.6001', 'planning_depot_lng' => '1.9475'] as $k => $v)
        if (setting($k) === '') set_setting($k, $v);
    foreach (['ent_nom' => 'Elagage Figeacois', 'ent_tel' => '05 54 54 16 99', 'ent_email' => 'elagage.figeacois@gmail.com', 'ent_adresse' => "133 Rue Madeleine Brès\n46100 CAMBES",
              'ent_mentions' => "SARL Elagage Figeacois au capital de 130 000 €\nCode APE 8130Z - Siret 52986608900061"] as $k => $v) if (setting($k) === '') set_setting($k, $v);
    foreach (['sms_actif' => '0', 'avis_actif' => '0', 'avis_delai' => '1', 'avis_canal' => 'email', 'push_actif' => '1'] as $k => $v) if (setting($k) === '') set_setting($k, $v);
    if (!q("SELECT COUNT(*) c FROM contrat_modeles")->fetch()['c']) q("INSERT INTO contrat_modeles (nom, texte) VALUES (?, ?)", ["Contrat d'entretien des arbres et espaces verts", CONTRAT_MODELE_DEFAUT]);
    set_setting('schema_planning', '4');
}

/**
 * Droits sur le planning. Tout compte connecté peut CONSULTER les agendas de tous (lecture croisée).
 * Pour MODIFIER : administrateurs et secrétariat sur tout ; sinon selon le droit du compte :
 *  - « tout » : comme le secrétariat ; « perso » : son propre agenda ; « lecture » : rien,
 *  plus, dans tous les cas, les agendas de personnes précises délégués (table droits_agenda).
 */
const DROITS_PLANNING = ['lecture' => 'Consultation seule', 'perso' => 'Modifie son propre agenda', 'tout' => 'Modifie tous les agendas'];
function planning_niveau(): string { static $n = null; if ($n !== null) return $n; if (acces_chiffrage()) return $n = 'tout'; $d = user()['planning_droit'] ?? ''; if ($d === '') { $d = q("SELECT planning_droit FROM utilisateurs WHERE id = ?", [user()['id']])->fetch()['planning_droit'] ?? 'lecture'; } return $n = in_array($d, ['lecture', 'perso', 'tout'], true) ? $d : 'lecture'; }
function acces_planning(): bool { return planning_niveau() === 'tout'; }
/** Personnes dont l'agenda est modifiable par le compte connecté (null = toutes) */
function agendas_modifiables(): ?array {
    if (planning_niveau() === 'tout') return null;
    static $ids = null; if ($ids !== null) return $ids;
    $ids = array_map('intval', array_column(q("SELECT personnel_id FROM droits_agenda WHERE utilisateur_id = ?", [user()['id']])->fetchAll(), 'personnel_id'));
    if (planning_niveau() === 'perso') foreach (q("SELECT id FROM personnel WHERE utilisateur_id = ?", [user()['id']]) as $p) $ids[] = (int)$p['id'];
    return $ids = array_values(array_unique($ids));
}
/** Un événement est modifiable si l'on a tout, ou si toutes les personnes concernées font partie des agendas autorisés */
function peut_modifier_evt(array $personnel): bool { $m = agendas_modifiables(); if ($m === null) return true; return $personnel && !array_diff(array_map('intval', $personnel), $m); }
function peut_planifier(): bool { $m = agendas_modifiables(); return $m === null || count($m) > 0; }
function require_planning(): void { require_login(); planning_migrate(); materiel_migrate(); phyto_migrate(); livret_migrate(); }
function equipes_liste(): array {
    $eq = q("SELECT * FROM equipes_pl WHERE actif = 1 ORDER BY ordre, nom")->fetchAll();
    foreach ($eq as &$e) { $e['membres'] = array_map('intval', array_column(q("SELECT personnel_id FROM equipe_membres WHERE equipe_id = ?", [$e['id']])->fetchAll(), 'personnel_id'));
        $e['materiel'] = array_map('intval', array_column(q("SELECT materiel_id FROM equipe_materiel WHERE equipe_id = ?", [$e['id']])->fetchAll(), 'materiel_id')); } unset($e);
    return $eq;
}

function evt_journal(int $id, string $quoi): void { try { q("INSERT INTO evenement_journal (evenement_id, qui, quoi) VALUES (?,?,?)", [$id, user()['id'] ?? null, mb_substr($quoi, 0, 500)]); } catch (Throwable $t) {} }

// Comptes actifs de l'espace pro qui ne sont rattachés à aucune personne du personnel
function comptes_hors_personnel(): array {
    return q("SELECT u.id, u.prenom, u.nom, u.email FROM utilisateurs u WHERE u.actif = 1 AND NOT EXISTS (SELECT 1 FROM personnel p WHERE p.utilisateur_id = u.id) ORDER BY u.prenom, u.nom")->fetchAll();
}
// Rattache d'office une personne sans compte au compte qui a le même e-mail, ou le même prénom + nom (s'il n'y en a qu'un)
function personnel_rattacher_auto(): int {
    $n = 0; $libres = comptes_hors_personnel();
    $norm = fn($x) => mb_strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$x)));
    foreach (q("SELECT id, prenom, nom, email FROM personnel WHERE utilisateur_id IS NULL")->fetchAll() as $p) {
        $c = array_values(array_filter($libres, fn($u) => $p['email'] !== '' && $norm($u['email']) === $norm($p['email'])));
        if (!$c) $c = array_values(array_filter($libres, fn($u) => $norm($u['prenom'] . ' ' . $u['nom']) === $norm($p['prenom'] . ' ' . $p['nom']) && trim($p['nom']) !== ''));
        if (count($c) !== 1) continue;
        q("UPDATE personnel SET utilisateur_id = ? WHERE id = ?", [$c[0]['id'], $p['id']]);
        $libres = array_values(array_filter($libres, fn($u) => (int)$u['id'] !== (int)$c[0]['id'])); $n++;
    }
    return $n;
}
function personnel_liste(bool $tous = false): array { return q("SELECT * FROM personnel" . ($tous ? '' : ' WHERE actif = 1') . " ORDER BY ordre, prenom, nom")->fetchAll(); }
function materiel_liste(bool $tous = false): array { return q("SELECT * FROM materiel" . ($tous ? '' : ' WHERE actif = 1') . " ORDER BY ordre, type, nom")->fetchAll(); }

/** Événement complet (avec ses affectations) */
function evt_charger(int $id): ?array {
    $e = q("SELECT * FROM evenements WHERE id = ?", [$id])->fetch(); if (!$e) return null;
    $e['personnel'] = array_map('intval', array_column(q("SELECT personnel_id FROM evenement_personnel WHERE evenement_id = ?", [$id])->fetchAll(), 'personnel_id'));
    $e['materiel'] = array_map('intval', array_column(q("SELECT materiel_id FROM evenement_materiel WHERE evenement_id = ?", [$id])->fetchAll(), 'materiel_id'));
    return $e;
}
/** Événements qui chevauchent [du, au[ (hors annulés), avec affectations */
function evt_periode(string $du, string $au, bool $avecAnnules = false): array {
    $ev = q("SELECT * FROM evenements WHERE debut < ? AND fin > ?" . ($avecAnnules ? '' : " AND statut <> 'annule'") . " ORDER BY debut", [$au, $du])->fetchAll();
    if (!$ev) return [];
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $ev));
    $p = []; foreach (q("SELECT * FROM evenement_personnel WHERE evenement_id IN ($ids)") as $x) $p[$x['evenement_id']][] = (int)$x['personnel_id'];
    $m = []; foreach (q("SELECT * FROM evenement_materiel WHERE evenement_id IN ($ids)") as $x) $m[$x['evenement_id']][] = (int)$x['materiel_id'];
    foreach ($ev as &$e) { $e['personnel'] = $p[$e['id']] ?? []; $e['materiel'] = $m[$e['id']] ?? []; } unset($e);
    return $ev;
}

/**
 * Matériel lié non dissociable : un outil « monté sur » un porte-outil (ex. tête forestière et rogneuse sur le Robo Evo)
 * partage la même machine. Racine = le porteur (ou le matériel lui-même). Deux réservations de même racine ne peuvent pas se chevaucher.
 */
function materiel_racines(): array {
    static $r = null; if ($r !== null) return $r;
    $par = []; foreach (q("SELECT id, porteur_id FROM materiel") as $m) $par[(int)$m['id']] = $m['porteur_id'] ? (int)$m['porteur_id'] : null;
    $r = []; foreach ($par as $id => $p) { $x = $id; $n = 0; while (!empty($par[$x]) && $n++ < 10) $x = $par[$x]; $r[$id] = $x; }
    return $r;
}
/** Ids des matériels qui partagent la même machine que $mid (lui compris) */
function materiel_freres(int $mid): array { $r = materiel_racines(); $rac = $r[$mid] ?? $mid; return array_keys(array_filter($r, fn($x) => $x === $rac)) ?: [$mid]; }

// ---- Distances et temps de trajet (estimation : vol d'oiseau × facteur route, vitesse moyenne) ----
function distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371; $dl = deg2rad($lat2 - $lat1); $dg = deg2rad($lng2 - $lng1);
    $a = sin($dl / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dg / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a))) * (float)setting('planning_facteur', '1.3');
}
function trajet_min(float $km): int { return (int)round($km / max(10, (float)setting('planning_vitesse', '55')) * 60); }
/** Temps de route réel entre deux points (calcul d'itinéraire OSRM, gratuit, mis en cache 120 jours), majoré pour les véhicules de chantier.
 *  Sans réseau ou si le service ne répond pas : estimation vol d'oiseau × facteur route / vitesse moyenne. Renvoie km, min, source (route|estim). */
function trajet(float $la1, float $lo1, float $la2, float $lo2, bool $reseau = true): array {
    static $appels = 0, $memo = [];
    $maj = 1 + max(0, (float)setting('planning_trajet_maj', '10')) / 100;
    $cle = sprintf('%.4f,%.4f|%.4f,%.4f', $la1, $lo1, $la2, $lo2);
    if (abs($la1 - $la2) < 0.0005 && abs($lo1 - $lo2) < 0.0005) return ['km' => 0.0, 'min' => 0, 'source' => 'route'];
    if (!isset($memo[$cle])) {
        $c = null; try { $c = q("SELECT km, min FROM trajets_cache WHERE cle = ? AND maj > DATE_SUB(NOW(), INTERVAL 120 DAY)", [$cle])->fetch(); } catch (Throwable $t) {}
        if (!$c && $reseau && setting('planning_routage', 'route') === 'route' && $appels < 30) {
            $appels++;
            $url = rtrim(setting('planning_osrm', 'https://router.project-osrm.org'), '/') . sprintf('/route/v1/driving/%.5f,%.5f;%.5f,%.5f?overview=false', $lo1, $la1, $lo2, $la2);
            $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true, 'header' => "User-Agent: EspacePro-ElagageFigeacois/1.0\r\n"]]);
            $rep = @file_get_contents($url, false, $ctx); $j = $rep ? json_decode($rep, true) : null;
            if (($j['code'] ?? '') === 'Ok' && isset($j['routes'][0]['duration'])) {
                $c = ['km' => round($j['routes'][0]['distance'] / 1000, 1), 'min' => (int)ceil($j['routes'][0]['duration'] / 60)];
                try { q("REPLACE INTO trajets_cache (cle, km, min, maj) VALUES (?,?,?,NOW())", [$cle, $c['km'], $c['min']]); } catch (Throwable $t) {}
            } elseif ($rep === false) $appels = 99; // service injoignable : estimations pour le reste de la page
        }
        $memo[$cle] = $c ? ['km' => (float)$c['km'], 'min' => (int)$c['min'], 'source' => 'route'] : null;
    }
    if ($memo[$cle]) return ['km' => $memo[$cle]['km'], 'min' => (int)round($memo[$cle]['min'] * $maj), 'source' => 'route'];
    $km = distance_km($la1, $lo1, $la2, $lo2);
    return ['km' => round($km, 1), 'min' => trajet_min($km), 'source' => 'estim'];
}
/** Trajets d'une période : pour chaque personne et chaque jour, le départ du dépôt vers le 1er rendez-vous à l'heure, puis chaque rendez-vous vers le suivant.
 *  [{pid, de (id ou 'depot'), vers, km, min, source, ecart (min entre la fin du précédent et le début du suivant), depart (heure conseillée)}] */
function trajets_periode(array $evs, bool $reseau = true): array {
    $parP = [];
    foreach ($evs as $e) {
        if (empty($e['lat']) || empty($e['lng']) || !empty($e['journee']) || !empty($e['externe']) || substr((string)$e['fin'], 0, 10) !== substr((string)$e['debut'], 0, 10) || in_array($e['type'] ?? '', ['absence', 'entretien'], true) || ($e['statut'] ?? '') === 'annule' || empty($e['debut'])) continue;
        foreach ($e['personnel'] ?? [] as $pid) $parP[(int)$pid][substr($e['debut'], 0, 10)][] = $e;
    }
    $dep = depot(); $out = [];
    foreach ($parP as $pid => $jours) foreach ($jours as $jour => $liste) {
        usort($liste, fn($a, $b) => strcmp($a['debut'], $b['debut']));
        $prec = null;
        foreach ($liste as $e) {
            if ($prec === null) { $t = trajet($dep['lat'], $dep['lng'], (float)$e['lat'], (float)$e['lng'], $reseau);
                $out[] = ['pid' => $pid, 'de' => 'depot', 'vers' => (int)$e['id'], 'km' => $t['km'], 'min' => $t['min'], 'source' => $t['source'], 'ecart' => null, 'depart' => date('H:i', strtotime($e['debut']) - $t['min'] * 60)]; }
            elseif (strtotime($e['debut']) >= strtotime($prec['fin'])) { $t = trajet((float)$prec['lat'], (float)$prec['lng'], (float)$e['lat'], (float)$e['lng'], $reseau);
                $out[] = ['pid' => $pid, 'de' => (int)$prec['id'], 'vers' => (int)$e['id'], 'km' => $t['km'], 'min' => $t['min'], 'source' => $t['source'], 'ecart' => (int)round((strtotime($e['debut']) - strtotime($prec['fin'])) / 60), 'depart' => date('H:i', strtotime($prec['fin']))]; }
            if ($prec === null || strtotime($e['fin']) > strtotime($prec['fin'])) $prec = $e;
        }
    }
    return $out;
}
/** Arrivée au plus tôt d'une personne à un rendez-vous : fin du rendez-vous précédent de la journée + route (ou départ du dépôt) */
function trajet_avant(int $pid, string $debut, float $lat, float $lng, ?int $sauf = null): ?array {
    $j = substr($debut, 0, 10);
    $o = q("SELECT ev.id, ev.titre, ev.fin, ev.lat, ev.lng FROM evenements ev JOIN evenement_personnel ep ON ep.evenement_id = ev.id WHERE ep.personnel_id = ? AND ev.id <> ? AND ev.statut <> 'annule' AND ev.journee = 0 AND ev.lat IS NOT NULL AND ev.type NOT IN ('absence','entretien') AND ev.fin <= ? AND ev.fin >= ? ORDER BY ev.fin DESC LIMIT 1", [$pid, (int)$sauf, $debut, "$j 00:00:00"])->fetch();
    if (!$o) return null;
    $t = trajet((float)$o['lat'], (float)$o['lng'], $lat, $lng);
    return ['de' => $o['titre'], 'fin' => substr($o['fin'], 11, 5), 'km' => $t['km'], 'min' => $t['min'], 'source' => $t['source'], 'arrivee' => date('Y-m-d H:i:s', strtotime($o['fin']) + $t['min'] * 60)];
}
function depot(): array { return ['lat' => (float)setting('planning_depot_lat', '44.6001'), 'lng' => (float)setting('planning_depot_lng', '1.9475'), 'nom' => setting('planning_depot', 'Dépôt')]; }

// ---- Conflits et alertes d'un événement ----
/** Retourne la liste des alertes (texte) pour un événement (tableau avec debut, fin, personnel, materiel, id éventuel) */
function evt_conflits(array $e): array {
    $al = []; if (empty($e['debut']) || empty($e['fin'])) return $al;
    $id = (int)($e['id'] ?? 0); $deb = $e['debut']; $fin = $e['fin'];
    if (strtotime($fin) <= strtotime($deb)) $al[] = "La fin est avant le début.";
    $pers = []; foreach (personnel_liste(true) as $p) $pers[(int)$p['id']] = $p;
    $mats = []; foreach (materiel_liste(true) as $m) $mats[(int)$m['id']] = $m;
    foreach ($e['personnel'] ?? [] as $pid) {
        foreach (q("SELECT ev.id, ev.titre, ev.type, ev.debut, ev.fin FROM evenements ev JOIN evenement_personnel ep ON ep.evenement_id = ev.id WHERE ep.personnel_id = ? AND ev.id <> ? AND ev.statut <> 'annule' AND ev.debut < ? AND ev.fin > ?", [$pid, $id, $fin, $deb]) as $o)
            $al[] = ($pers[$pid]['prenom'] ?? 'Personne') . ($o['type'] === 'absence' ? ' est absent(e) : ' : ' est déjà pris(e) : ') . "« {$o['titre']} » (" . date('d/m H:i', strtotime($o['debut'])) . ").";
        foreach (q("SELECT x.titre, x.debut, a.nom, a.prive FROM evenements_externes x JOIN agendas_externes a ON a.id = x.agenda_id WHERE a.actif = 1 AND a.personnel_id = ? AND x.debut < ? AND x.fin > ?", [$pid, $fin, $deb]) as $o)
            $al[] = ($pers[$pid]['prenom'] ?? 'Personne') . " a déjà quelque chose dans son agenda {$o['nom']} : « " . ((int)$o['prive'] ? 'occupé' : $o['titre']) . " » (" . date('d/m H:i', strtotime($o['debut'])) . ").";
        if (isset($pers[$pid]) && !(int)$pers[$pid]['actif']) $al[] = "{$pers[$pid]['prenom']} n'est plus actif(ve).";
    }
    $compsEquipe = []; foreach ($e['personnel'] ?? [] as $pid) foreach (array_filter(explode(',', $pers[$pid]['competences'] ?? '')) as $c) $compsEquipe[$c] = true;
    foreach ($e['materiel'] ?? [] as $mid) {
        $m = $mats[$mid] ?? null; if (!$m) continue;
        $freres = materiel_freres($mid);
        foreach (q("SELECT ev.id, ev.titre, ev.type, ev.debut, em.materiel_id FROM evenements ev JOIN evenement_materiel em ON em.evenement_id = ev.id WHERE em.materiel_id IN (" . implode(',', array_map('intval', $freres)) . ") AND ev.id <> ? AND ev.statut <> 'annule' AND ev.debut < ? AND ev.fin > ?", [$id, $fin, $deb]) as $o) {
            $autre = (int)$o['materiel_id'] !== $mid ? ' (même machine : ' . ($mats[(int)$o['materiel_id']]['nom'] ?? '?') . ')' : '';
            $al[] = "{$m['nom']}" . ($o['type'] === 'entretien' ? ' est en entretien' : ' est déjà réservé(e)') . "$autre : « {$o['titre']} » (" . date('d/m H:i', strtotime($o['debut'])) . ").";
        }
        $por = $m['porteur_id'] ? ($mats[(int)$m['porteur_id']] ?? null) : null;
        if ($por && $por['controle_le'] && strtotime($por['controle_le']) < strtotime(substr($fin, 0, 10))) $al[] = "{$m['nom']} : contrôle réglementaire du porteur {$por['nom']} dépassé.";
        if ($m['controle_le'] && strtotime($m['controle_le']) < strtotime(substr($fin, 0, 10))) $al[] = "{$m['nom']} : contrôle réglementaire dépassé (échéance " . date('d/m/Y', strtotime($m['controle_le'])) . ").";
        if ($m['competence_requise'] !== '' && ($e['type'] ?? '') === 'chantier' && empty($compsEquipe[$m['competence_requise']])) $al[] = "{$m['nom']} : personne dans l'équipe avec la compétence « " . (COMPETENCES[$m['competence_requise']] ?? $m['competence_requise']) . " ».";
    }
    if (($e['type'] ?? '') === 'chantier') {
        $n = count($e['personnel'] ?? []); $b = (int)($e['besoin_personnes'] ?? 0);
        if ($b > $n) $al[] = "Il manque " . ($b - $n) . " personne(s) (besoin : $b).";
        $typesAff = []; foreach ($e['materiel'] ?? [] as $mid) if (isset($mats[$mid])) $typesAff[$mats[$mid]['type']] = true;
        foreach (array_filter(explode(',', (string)($e['besoin_materiel'] ?? ''))) as $t) if (empty($typesAff[$t])) $al[] = "Matériel prévu manquant : " . (TYPES_MAT[$t] ?? $t) . ".";
    }
    // Temps de trajet : un rendez-vous à l'heure doit laisser le temps de route depuis le précédent (et vers le suivant) de la même personne
    if (!empty($e['lat']) && !empty($e['lng']) && !($e['journee'] ?? 0) && !in_array($e['type'] ?? '', ['absence', 'entretien'], true)) {
        foreach ($e['personnel'] ?? [] as $pid) {
            $j = substr($deb, 0, 10);
            foreach (q("SELECT ev.id, ev.titre, ev.debut, ev.fin, ev.lat, ev.lng FROM evenements ev JOIN evenement_personnel ep ON ep.evenement_id = ev.id WHERE ep.personnel_id = ? AND ev.id <> ? AND ev.statut <> 'annule' AND ev.lat IS NOT NULL AND ev.journee = 0 AND ev.type IN ('visite','rdv','chantier','urgence') AND DATE(ev.debut) = ?", [$pid, $id, $j]) as $o) {
                $tj = trajet((float)$o['lat'], (float)$o['lng'], (float)$e['lat'], (float)$e['lng']); $km = $tj['km']; $min = $tj['min']; if ($min < 5) continue;
                if (strtotime($o['fin']) <= strtotime($deb)) { $ecart = (strtotime($deb) - strtotime($o['fin'])) / 60; if ($ecart < $min) $al[] = ($pers[$pid]['prenom'] ?? 'Personne') . " : temps de trajet insuffisant après « {$o['titre']} » (" . round($km) . " km ≈ $min min, écart " . (int)$ecart . " min)."; }
                elseif (strtotime($o['debut']) >= strtotime($fin)) { $ecart = (strtotime($o['debut']) - strtotime($fin)) / 60; if ($ecart < $min) $al[] = ($pers[$pid]['prenom'] ?? 'Personne') . " : temps de trajet insuffisant avant « {$o['titre']} » (" . round($km) . " km ≈ $min min, écart " . (int)$ecart . " min)."; }
            }
        }
    }
    $al = array_merge($al, dict_alertes($e), phyto_alertes($e));
    foreach ($e['materiel'] ?? [] as $mid) if (isset($mats[$mid]) && ($mats[$mid]['etat'] ?? 'ok') === 'immobilise') $al[] = "{$mats[$mid]['nom']} est immobilisé(e) (panne ou contrôle non conforme).";
    if (!in_array($e['type'] ?? '', ['absence', 'urgence'], true)) for ($t = strtotime(substr($deb, 0, 10)); $t < strtotime($fin); $t = strtotime('+1 day', $t)) {
        if ((int)date('N', $t) >= 6) { $al[] = "Tombe un week-end (" . date('d/m', $t) . ")."; break; }
        if ($nf = est_ferie(date('Y-m-d', $t))) { $al[] = "Tombe un week-end ou jour férié : $nf (" . date('d/m', $t) . ")."; break; }
    }
    return array_values(array_unique($al));
}

// ---- Disponibilités ----
/** Ressources occupées un jour donné : ['p' => [id => true], 'm' => [id => true]] */
function occupes_jour(string $jour, int $sauf = 0): array {
    $o = ['p' => [], 'm' => []];
    $d = "$jour 00:00:00"; $f = date('Y-m-d', strtotime("$jour +1 day")) . ' 00:00:00';
    foreach (q("SELECT ep.personnel_id FROM evenement_personnel ep JOIN evenements ev ON ev.id = ep.evenement_id WHERE ev.id <> ? AND ev.statut <> 'annule' AND ev.debut < ? AND ev.fin > ?", [$sauf, $f, $d]) as $x) $o['p'][(int)$x['personnel_id']] = true;
    foreach (q("SELECT em.materiel_id FROM evenement_materiel em JOIN evenements ev ON ev.id = em.evenement_id WHERE ev.id <> ? AND ev.statut <> 'annule' AND ev.debut < ? AND ev.fin > ?", [$sauf, $f, $d]) as $x) foreach (materiel_freres((int)$x['materiel_id']) as $fr) $o['m'][$fr] = true; // la machine et tous ses outils
    return $o;
}
/** Jours fériés en France (dont Pâques, Ascension, Pentecôte) : 'Y-m-d' => nom */
function jours_feries(int $an): array {
    static $c = []; if (isset($c[$an])) return $c[$an];
    $p = easter_days_jour($an);
    $f = ["$an-01-01" => "Jour de l'an", "$an-05-01" => 'Fête du travail', "$an-05-08" => 'Victoire 1945', "$an-07-14" => 'Fête nationale', "$an-08-15" => 'Assomption', "$an-11-01" => 'Toussaint', "$an-11-11" => 'Armistice', "$an-12-25" => 'Noël',
          date('Y-m-d', strtotime("$p +1 day")) => 'Lundi de Pâques', date('Y-m-d', strtotime("$p +39 days")) => 'Ascension', date('Y-m-d', strtotime("$p +50 days")) => 'Lundi de Pentecôte'];
    ksort($f); return $c[$an] = $f;
}
/** Date de Pâques (algorithme de Meeus, sans l'extension calendar) */
function easter_days_jour(int $y): string { $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451); $mo = intdiv($h + $l - 7 * $m + 114, 31); $da = (($h + $l - 7 * $m + 114) % 31) + 1; return sprintf('%04d-%02d-%02d', $y, $mo, $da); }
function est_ferie(string $j): ?string { return jours_feries((int)substr($j, 0, 4))[substr($j, 0, 10)] ?? null; }
function jours_ouvres(string $depuis, int $n): array { $j = []; $t = strtotime($depuis); while (count($j) < $n) { $d = date('Y-m-d', $t); if ((int)date('N', $t) <= 5 && !est_ferie($d)) $j[] = $d; $t = strtotime('+1 day', $t); } return $j; }

/**
 * Créneaux pour un chantier : premiers jours ouvrés consécutifs où il y a assez de personnes et le matériel nécessaire,
 * en privilégiant les jours où l'on travaille déjà à proximité. Retourne jusqu'à $max propositions.
 */
function creneaux_chantier(array $e, int $max = 5, int $horizon = 60): array {
    $nbJ = max(1, (int)ceil((float)($e['nb_jours'] ?? 0) ?: ((float)($e['duree_h'] ?? 0) > 0 ? (float)$e['duree_h'] / max(1, heures_jour()) : 1)));
    $besoinP = max(1, (int)($e['besoin_personnes'] ?? 0) ?: 2);
    $types = array_values(array_filter(explode(',', (string)($e['besoin_materiel'] ?? ''))));
    $pers = personnel_liste(); $mats = materiel_liste(); $id = (int)($e['id'] ?? 0);
    if (!empty($e['equipe_id'])) { // équipe imposée : ses membres (tous libres) et son matériel habituel
        foreach (equipes_liste() as $eq) if ((int)$eq['id'] === (int)$e['equipe_id']) {
            $pers = array_values(array_filter($pers, fn($p) => in_array((int)$p['id'], $eq['membres'], true)));
            if ((int)($e['besoin_personnes'] ?? 0) <= 0) $besoinP = max(1, count($pers));
            foreach ($mats as $m) if (in_array((int)$m['id'], $eq['materiel'], true) && !in_array($m['type'], $types, true)) $types[] = $m['type'];
            $prefM = $eq['materiel'];
        }
    }
    $jours = jours_ouvres(date('Y-m-d', strtotime('+1 day')), $horizon);
    $occ = []; foreach ($jours as $j) $occ[$j] = occupes_jour($j, $id);
    // compétences utiles d'après le matériel demandé (ex. nacelle → CACES nacelle)
    $compReq = []; foreach ($mats as $m) if (in_array($m['type'], $types, true) && $m['competence_requise'] !== '') $compReq[$m['competence_requise']] = true;
    $props = [];
    for ($i = 0; $i + $nbJ <= count($jours) && count($props) < $max * 3; $i++) {
        $bloc = array_slice($jours, $i, $nbJ);
        if (strtotime(end($bloc)) - strtotime($bloc[0]) > ($nbJ + 2) * 86400) continue; // pas à cheval sur un long pont
        $libresP = array_values(array_filter($pers, function ($p) use ($bloc, $occ) { foreach ($bloc as $j) if (!empty($occ[$j]['p'][(int)$p['id']])) return false; return true; }));
        if (count($libresP) < $besoinP) continue;
        // matériel : un de chaque type demandé, libre, contrôle valide
        $choixM = []; $ok = true;
        foreach ($types as $t) {
            $c = null; $ordreM = $mats; if (!empty($prefM)) usort($ordreM, fn($a, $b) => (int)in_array((int)$b['id'], $prefM, true) <=> (int)in_array((int)$a['id'], $prefM, true));
            foreach ($ordreM as $m) { if ($m['type'] !== $t || in_array((int)$m['id'], $choixM, true)) continue;
                foreach ($bloc as $j) if (!empty($occ[$j]['m'][(int)$m['id']])) continue 2;
                if ($m['controle_le'] && strtotime($m['controle_le']) < strtotime(end($bloc))) continue;
                foreach ($mats as $pm) if ((int)$pm['id'] === (int)($m['porteur_id'] ?? 0) && $pm['controle_le'] && strtotime($pm['controle_le']) < strtotime(end($bloc))) continue 2;
                $c = (int)$m['id']; break; }
            if ($c === null) { $ok = false; break; } $choixM[] = $c;
        }
        if (!$ok) continue;
        // équipe : d'abord ceux qui ont les compétences requises, puis chefs d'équipe, puis les autres
        usort($libresP, function ($a, $b) use ($compReq) {
            $sa = 0; $sb = 0; foreach (array_keys($compReq) as $c) { $sa += str_contains(",{$a['competences']},", ",$c,") ? 2 : 0; $sb += str_contains(",{$b['competences']},", ",$c,") ? 2 : 0; }
            $sa += str_contains(",{$a['competences']},", ',chef,') ? 1 : 0; $sb += str_contains(",{$b['competences']},", ',chef,') ? 1 : 0;
            return $sb <=> $sa ?: (int)$a['ordre'] <=> (int)$b['ordre'];
        });
        $equipe = array_slice($libresP, 0, $besoinP);
        $manque = []; foreach (array_keys($compReq) as $c) { $a = false; foreach ($equipe as $p) if (str_contains(",{$p['competences']},", ",$c,")) $a = true; if (!$a) $manque[] = COMPETENCES[$c] ?? $c; }
        // proximité : distance au chantier le plus proche déjà prévu ces jours-là
        $proche = null;
        if (!empty($e['lat'])) foreach ($bloc as $j) foreach (q("SELECT lat, lng, titre FROM evenements WHERE id <> ? AND statut <> 'annule' AND type IN ('chantier','visite','rdv') AND lat IS NOT NULL AND debut < ? AND fin > ?", [$id, date('Y-m-d', strtotime("$j +1 day")), $j]) as $o) {
            $d = distance_km((float)$e['lat'], (float)$e['lng'], (float)$o['lat'], (float)$o['lng']); if ($proche === null || $d < $proche['km']) $proche = ['km' => round($d, 1), 'titre' => $o['titre']];
        }
        $score = $i * 1.0 + ($manque ? 30 : 0) - ($proche && $proche['km'] < 15 ? 6 : 0) - ($proche && $proche['km'] < 5 ? 4 : 0);
        $props[] = ['debut' => $bloc[0], 'fin' => end($bloc), 'jours' => $bloc, 'personnel' => array_map(fn($p) => (int)$p['id'], $equipe), 'noms' => array_map(fn($p) => $p['prenom'], $equipe),
                    'materiel' => $choixM, 'proche' => $proche, 'manque' => $manque, 'score' => $score];
    }
    usort($props, fn($a, $b) => $a['score'] <=> $b['score']);
    return array_slice($props, 0, $max);
}
function heures_jour(): float { $d = explode(':', setting('planning_debut', '08:00')); $f = explode(':', setting('planning_fin', '17:00')); return max(1, ((int)$f[0] * 60 + (int)($f[1] ?? 0) - (int)$d[0] * 60 - (int)($d[1] ?? 0)) / 60 - 1); }

// ---- Tournées ----
/** Ordre le plus court (plus proche voisin + 2-opt) depuis le dépôt et retour. $pts = [['id'=>, 'lat'=>, 'lng'=>], …] */
function tournee_ordre(array $pts): array {
    $dep = depot(); $n = count($pts); if ($n <= 1) return $pts;
    $P = array_merge([['lat' => $dep['lat'], 'lng' => $dep['lng']]], array_values($pts));
    $D = []; for ($i = 0; $i <= $n; $i++) for ($j = 0; $j <= $n; $j++) $D[$i][$j] = $i === $j ? 0 : distance_km((float)$P[$i]['lat'], (float)$P[$i]['lng'], (float)$P[$j]['lat'], (float)$P[$j]['lng']);
    $route = [0]; $reste = range(1, $n);
    while ($reste) { $last = end($route); usort($reste, fn($a, $b) => $D[$last][$a] <=> $D[$last][$b]); $route[] = array_shift($reste); }
    $route[] = 0;
    $amelio = true; $tours = 0;
    while ($amelio && $tours++ < 200) {
        $amelio = false;
        for ($i = 1; $i < count($route) - 2; $i++) for ($k = $i + 1; $k < count($route) - 1; $k++) {
            $delta = $D[$route[$i - 1]][$route[$k]] + $D[$route[$i]][$route[$k + 1]] - $D[$route[$i - 1]][$route[$i]] - $D[$route[$k]][$route[$k + 1]];
            if ($delta < -0.001) { $route = array_merge(array_slice($route, 0, $i), array_reverse(array_slice($route, $i, $k - $i + 1)), array_slice($route, $k + 1)); $amelio = true; }
        }
    }
    return array_map(fn($k) => $P[$k], array_slice($route, 1, -1));
}
/** Horaires d'une tournée ordonnée : départ du dépôt à $depart (HH:MM), durée de chaque arrêt en minutes */
function tournee_horaires(array $ordre, string $jour, string $depart = ''): array {
    $dep = depot(); $t = strtotime("$jour " . ($depart ?: setting('planning_debut', '08:00'))); $prev = $dep; $km = 0; $out = [];
    foreach ($ordre as $p) {
        $d = distance_km((float)$prev['lat'], (float)$prev['lng'], (float)$p['lat'], (float)$p['lng']); $km += $d; $t += trajet_min($d) * 60;
        $t = (int)(ceil($t / 300) * 300); // arrondi aux 5 minutes
        $dur = max(15, (int)($p['duree_min'] ?? 60));
        $out[] = $p + ['arrivee' => date('Y-m-d H:i:s', $t), 'depart' => date('Y-m-d H:i:s', $t + $dur * 60), 'km_depuis' => round($d, 1)];
        $t += $dur * 60; $prev = $p;
    }
    $dr = $ordre ? distance_km((float)$prev['lat'], (float)$prev['lng'], $dep['lat'], $dep['lng']) : 0; $km += $dr;
    return ['etapes' => $out, 'km' => round($km, 1), 'retour' => date('H:i', $t + trajet_min($dr) * 60)];
}
/** Meilleurs jours pour placer une nouvelle visite (détour minimal dans les tournées existantes de la personne) */
function creneaux_visite(float $lat, float $lng, int $dureeMin, ?int $personnelId, int $max = 5, int $horizon = 15, int $sauf = 0): array {
    $dep = depot(); $res = [];
    // Journées secteur : un jour réservé au secteur du point est favorisé, un jour réservé à un autre secteur est pénalisé
    $sect = secteur_de($lat, $lng);
    $bonusSecteur = function (string $j) use ($sect): float { $sj = secteurs_du_jour($j); if (!$sj) return 0; if ($sect && in_array((int)$sect['id'], array_map('intval', array_column($sj, 'id')), true)) return -15; return 20; };
    foreach (jours_ouvres(date('Y-m-d', strtotime('+1 day')), $horizon) as $j) {
        $sql = "SELECT ev.* FROM evenements ev" . ($personnelId ? " JOIN evenement_personnel ep ON ep.evenement_id = ev.id AND ep.personnel_id = " . (int)$personnelId : '') .
               " WHERE ev.id <> ? AND ev.statut <> 'annule' AND ev.debut < ? AND ev.fin > ?";
        $evs = q($sql, [$sauf, date('Y-m-d', strtotime("$j +1 day")), $j])->fetchAll();
        if (array_filter($evs, fn($x) => in_array($x['type'], ['chantier', 'absence'], true) && (strtotime($x['fin']) - strtotime($x['debut'])) >= 6 * 3600)) continue; // journée prise
        $visites = array_values(array_filter($evs, fn($x) => in_array($x['type'], ['visite', 'rdv'], true) && $x['lat'] !== null));
        $pts = array_map(fn($x) => ['id' => (int)$x['id'], 'lat' => (float)$x['lat'], 'lng' => (float)$x['lng'], 'duree_min' => (int)((strtotime($x['fin']) - strtotime($x['debut'])) / 60), 'titre' => $x['titre']], $visites);
        $avant = $pts ? tournee_horaires(tournee_ordre($pts), $j)['km'] : 0;
        $avec = tournee_ordre(array_merge($pts, [['id' => 0, 'lat' => $lat, 'lng' => $lng, 'duree_min' => $dureeMin, 'titre' => 'Nouvelle visite']]));
        $h = tournee_horaires($avec, $j);
        $fin = strtotime("$j " . setting('planning_fin', '17:00')) + 3600;
        if (strtotime("$j " . $h['retour']) > $fin) continue; // journée trop chargée
        $nouv = null; foreach ($h['etapes'] as $e) if ($e['id'] === 0) $nouv = $e;
        $res[] = ['jour' => $j, 'heure' => substr($nouv['arrivee'], 11, 5), 'detour_km' => round($h['km'] - $avant, 1), 'nb_visites' => count($pts), 'km_total' => $h['km'],
                  'score' => ($h['km'] - $avant) + (count($pts) ? 0 : 8) + array_search($j, jours_ouvres(date('Y-m-d', strtotime('+1 day')), $horizon)) * 0.8 + $bonusSecteur($j),
                  'secteur' => implode(', ', array_column(secteurs_du_jour($j), 'nom'))];
    }
    usort($res, fn($a, $b) => $a['score'] <=> $b['score']);
    return array_slice($res, 0, $max);
}

// ---- E-mails aux clients ----
function lien_base(): string {
    if (setting('site_url') !== '') return rtrim(setting('site_url'), '/');
    $h = (string)($_SERVER['HTTP_HOST'] ?? ''); if (!preg_match('/^(www\.)?elagage-figeacois\.com$|^127\.0\.0\.1(:\d+)?$|^localhost(:\d+)?$/', $h)) $h = 'elagage-figeacois.com'; // jamais un domaine forgé dans les e-mails
    return (!empty($_SERVER['HTTPS']) || $h === 'elagage-figeacois.com' ? 'https' : 'http') . '://' . $h . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/espace-pro/x'), '/');
}
function date_fr(string $dt, bool $heure = true): string {
    $j = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']; $m = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($dt); return $j[(int)date('N', $t)] . ' ' . (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t) . ($heure ? ' à ' . date('G\hi', $t) : '');
}
function evt_quand(array $e): string {
    if (!$e['debut']) return 'date à définir';
    $multi = substr($e['debut'], 0, 10) !== substr(date('Y-m-d H:i:s', strtotime($e['fin']) - 1), 0, 10);
    if ($multi) return 'du ' . date_fr($e['debut'], false) . ' au ' . date_fr(date('Y-m-d H:i:s', strtotime($e['fin']) - 1), false);
    return (int)$e['journee'] ? 'le ' . date_fr($e['debut'], false) : 'le ' . date_fr($e['debut']);
}
function ics_evenement(array $e, string $uidSuffixe = 'ef'): string {
    $f = fn($dt) => gmdate('Ymd\THis\Z', strtotime($dt)); $x = fn($s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], (string)$s);
    $lieu = trim($e['adresse'] . ', ' . $e['cp'] . ' ' . $e['ville'], ' ,');
    $o = "BEGIN:VEVENT\r\nUID:evt-{$e['id']}-$uidSuffixe@elagage-figeacois.com\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    if ((int)$e['journee']) $o .= "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($e['debut'])) . "\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime(date('Y-m-d', strtotime($e['fin']) - 1) . ' +1 day')) . "\r\n";
    else $o .= "DTSTART:" . $f($e['debut']) . "\r\nDTEND:" . $f($e['fin']) . "\r\n";
    $o .= "SUMMARY:" . $x($e['titre']) . "\r\nLOCATION:" . $x($lieu) . "\r\nDESCRIPTION:" . $x(trim(($e['client_nom'] ? "Client : {$e['client_nom']} " . ($e['client_tel'] ? "({$e['client_tel']})" : '') . "\n" : '') . ($e['consignes'] ?? ''))) . "\r\n";
    if ($e['lat'] !== null) $o .= "GEO:{$e['lat']};{$e['lng']}\r\n";
    return $o . "STATUS:" . ($e['statut'] === 'confirme' ? 'CONFIRMED' : 'TENTATIVE') . "\r\nEND:VEVENT\r\n";
}
function ics_calendrier(array $evs, string $nom): string {
    $o = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Elagage Figeacois//Planning//FR\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nX-WR-CALNAME:" . str_replace(',', '\\,', $nom) . "\r\nX-WR-TIMEZONE:Europe/Paris\r\n";
    foreach ($evs as $e) $o .= ics_evenement($e);
    return $o . "END:VCALENDAR\r\n";
}
/** Envoi d'un e-mail texte, avec pièce jointe .ics facultative */
function envoyer_mail(string $a, string $sujet, string $texte, ?string $ics = null): bool {
    $de = defined('APP_EMAIL') ? APP_EMAIL : 'contact@elagage-figeacois.com';
    $sujetEnc = "=?UTF-8?B?" . base64_encode($sujet) . "?=";
    $h = "From: Élagage Figeacois <$de>\r\nReply-To: $de\r\nMIME-Version: 1.0\r\n";
    if ($ics === null) { $h .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n"; $corps = $texte; }
    else {
        $b = 'ef' . bin2hex(random_bytes(8));
        $h .= "Content-Type: multipart/mixed; boundary=\"$b\"\r\n";
        $corps = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$texte\r\n--$b\r\nContent-Type: text/calendar; charset=UTF-8; method=PUBLISH; name=\"rendez-vous.ics\"\r\nContent-Disposition: attachment; filename=\"rendez-vous.ics\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($ics)) . "--$b--\r\n";
    }
    if (defined('MAIL_TEST_DIR')) { @file_put_contents(MAIL_TEST_DIR . '/' . date('His') . '-' . bin2hex(random_bytes(3)) . '.eml', "To: $a\r\nSubject: $sujet\r\n$h\r\n$corps"); return true; }
    return @mail($a, $sujetEnc, $corps, $h);
}
function evt_token(array &$e): string { if ($e['token'] === '') { $e['token'] = bin2hex(random_bytes(16)); q("UPDATE evenements SET token = ? WHERE id = ?", [$e['token'], $e['id']]); } return $e['token']; }
function evt_libelle_client(array $e): string {
    $m = mb_strtolower((string)($e['motif'] ?? ''));
    if ($m !== '' && $e['type'] !== 'chantier') return ['visite de chiffrage' => 'notre visite pour établir le devis', 'réunion de chantier' => 'notre réunion de chantier', 'visite de fin de travaux' => 'notre visite de fin de travaux', 'réception de travaux' => 'la réception des travaux', 'rendez-vous prospect' => 'notre rendez-vous'][$m] ?? "notre rendez-vous ($m)";
    return ['chantier' => 'notre intervention', 'visite' => 'notre visite pour établir le devis', 'rdv' => 'notre rendez-vous'][$e['type']] ?? 'notre rendez-vous';
}
/** Motifs de rendez-vous (réglage, un par ligne) */
const PICTO_EMOJI_VERS_ICONE = ['🌱' => 'plant', '🚧' => 'cone', '📦' => 'box', '🏗️' => 'crane', '🏗' => 'crane', '🪵' => 'log', '⚡' => 'bolt', '🏠' => 'house', '🔑' => 'key', '🐦' => 'bird', '🚜' => 'stump', '📋' => 'clipboard', '🌳' => 'tree', '🚚' => 'truck', '⚠️' => 'warning', '💧' => 'water', '📞' => 'phone', '🪚' => 'saw', '🪜' => 'ladder'];
/** Pictogrammes de chantier : code|icône|libellé|action à prévoir (optionnel)|délai en jours avant le chantier (optionnel) */
function pictos_liste(): array {
    $l = [];
    foreach (preg_split('/\R/', setting('planning_pictos')) as $ln) { $c = array_map('trim', explode('|', $ln)); if (isset($c[1])) $c[1] = PICTO_EMOJI_VERS_ICONE[$c[1]] ?? $c[1]; if (count($c) < 3 || $c[0] === '' || !preg_match('/^[a-z0-9_-]{1,30}$/', $c[0])) continue;
        $l[$c[0]] = ['code' => $c[0], 'emoji' => $c[1], 'icone' => isset(PICTO_ICONES[$c[1]]) ? $c[1] : '', 'html' => picto_html($c[1], $c[2]), 'libelle' => $c[2], 'action' => $c[3] ?? '', 'delai' => ($c[4] ?? '') !== '' ? (int)$c[4] : null, 'types' => array_values(array_intersect(array_filter(explode(',', $c[5] ?? '')), array_keys(TYPES_EVT)))]; }
    return $l;
}
/** Ajoute une fois le pictogramme « Fiche d'intervention Kleyk » aux réglages existants (supprimable ensuite) */
function kleyk_picto_ajouter(): void {
    if (setting('pictos_types_ok') !== '1') { // pictogrammes pré-cochés par type : Kleyk sur les chantiers et urgences
        set_setting('planning_pictos', preg_replace_callback('/^(kleyk\|[^|\n]*\|[^|\n]*\|[^|\n]*\|[^|\n]*)(\|[^\n]*)?$/m', fn($m) => $m[1] . '|' . (trim($m[2] ?? '', '|') !== '' ? trim($m[2], '|') : 'chantier,urgence'), setting('planning_pictos')));
        set_setting('pictos_types_ok', '1'); }
    if (setting('kleyk_picto_ok') === '1') return;
    if (!preg_match('/^kleyk\|/m', setting('planning_pictos'))) set_setting('planning_pictos', rtrim(setting('planning_pictos')) . "\nkleyk|kleyk|Fiche d'intervention Kleyk|Remplir la fiche d'intervention dans Kleyk|0|chantier,urgence");
    if (setting('kleyk_auto') === '') set_setting('kleyk_auto', '1');
    set_setting('kleyk_picto_ok', '1');
}
/** Chantiers d'élagage (chantier, urgence) : pictogramme Kleyk posé d'office une seule fois, avec son action « fiche à remplir ».
 *  Les chantiers passés ou annulés sont seulement marqués ; si on retire le picto ensuite, il ne revient pas. */
function kleyk_init(?int $id = null): int {
    $actif = setting('kleyk_auto') !== '0' && isset(pictos_liste()['kleyk']); $n = 0;
    $sql = "SELECT * FROM evenements WHERE kleyk_init = 0 AND type IN ('chantier','urgence')" . ($id ? " AND id = " . (int)$id : '') . " LIMIT 300";
    foreach (q($sql)->fetchAll() as $e) {
        $neuf = $actif && $e['statut'] !== 'annule' && $e['statut'] !== 'termine' && (!$e['debut'] || $e['debut'] >= date('Y-m-d 00:00:00'));
        if ($neuf) { $pc = array_values(array_unique(array_merge(array_filter(explode(',', (string)$e['pictos'])), ['kleyk'])));
            q("UPDATE evenements SET pictos = ?, kleyk_init = 1 WHERE id = ?", [implode(',', $pc), $e['id']]);
            try { taches_depuis_pictos(['id' => $e['id'], 'pictos' => implode(',', $pc), 'debut' => $e['debut']]); } catch (Throwable $t) {} $n++; }
        else q("UPDATE evenements SET kleyk_init = 1 WHERE id = ?", [$e['id']]);
    }
    return $n;
}
function pictos_evt(array $e): array { return array_values(array_intersect(array_filter(explode(',', (string)($e['pictos'] ?? ''))), array_keys(pictos_liste()))); }
function motifs_rdv(): array { return array_values(array_filter(array_map('trim', preg_split('/\R/', setting('planning_motifs'))))); }
/** Propose la date au client : e-mail avec lien de confirmation */
function envoyer_proposition(int $id): string {
    $e = evt_charger($id); if (!$e) return 'Fiche introuvable.';
    if (!filter_var($e['client_email'], FILTER_VALIDATE_EMAIL)) return "Pas d'adresse e-mail valide pour ce client.";
    if (!$e['debut']) return "Donne d'abord une date.";
    $lien = lien_base() . '/confirmer.php?t=' . evt_token($e);
    $lieu = trim($e['adresse'] . ', ' . $e['cp'] . ' ' . $e['ville'], ' ,');
    $txt = "Bonjour" . ($e['client_nom'] ? " {$e['client_nom']}" : '') . ",\n\nNous vous proposons " . evt_libelle_client($e) . " " . evt_quand($e) . ($lieu ? "\nAdresse : $lieu" : '') . ".\n\n"
         . "Merci de confirmer cette date, ou de nous en proposer une autre, en un clic :\n$lien\n\n"
         . "Pour toute question, répondez simplement à ce message.\n\nBien cordialement,\nÉlagage Figeacois\n" . (defined('APP_EMAIL') ? APP_EMAIL : '');
    $ok = envoyer_mail($e['client_email'], "Proposition de date – " . TYPES_EVT[$e['type']][0] . " " . evt_quand($e), $txt, ics_calendrier([$e], 'Élagage Figeacois'));
    if ($ok && sms_actif('proposition') && sms_mobile($e['client_tel']) && client_accepte($e, 'sms')) sms_envoyer($e['client_tel'], setting('ent_nom') . " vous propose " . evt_libelle_client($e) . " " . evt_quand($e) . ". Confirmez ici : $lien", $id, 'proposition');
    if (!$ok) return "L'e-mail n'a pas pu partir (serveur de messagerie).";
    q("UPDATE evenements SET proposition_le = NOW(), statut = IF(statut IN ('a_planifier','propose'), 'propose', statut), client_reponse = '', rappel_le = NULL WHERE id = ?", [$id]);
    evt_journal($id, "Date proposée au client par e-mail ({$e['client_email']}) : " . evt_quand($e));
    return '';
}
/** Tâches automatiques : rappel la veille aux clients (appelée à l'ouverture des pages et par la tâche planifiée) */
function planning_taches(): int {
    // au plus une fois par heure, verrou atomique (deux pages ouvertes en même temps ne doublent pas les envois)
    $h = date('Y-m-d H');
    if (setting('planning_taches_le') === $h) return 0;
    if (setting('planning_taches_le') === '') set_setting('planning_taches_le', '-');
    if (q("UPDATE parametres SET valeur = ? WHERE cle = 'planning_taches_le' AND valeur <> ?", [$h, $h])->rowCount() !== 1) return 0;
    $GLOBALS['__reglages']['planning_taches_le'] = $h;
    $n = 0; $demain = date('Y-m-d', strtotime('+1 day'));
    foreach (q("SELECT id FROM evenements WHERE DATE(debut) = ? AND statut IN ('confirme','propose') AND type IN ('chantier','visite','rdv') AND client_email <> '' AND rappel_le IS NULL AND client_reponse <> 'autre' AND (statut = 'confirme' OR proposition_le IS NOT NULL)", [$demain]) as $x) {
        $e = evt_charger((int)$x['id']); if (!$e || !filter_var($e['client_email'], FILTER_VALIDATE_EMAIL)) continue;
        $lieu = trim($e['adresse'] . ', ' . $e['cp'] . ' ' . $e['ville'], ' ,');
        $txt = "Bonjour" . ($e['client_nom'] ? " {$e['client_nom']}" : '') . ",\n\nPetit rappel : " . evt_libelle_client($e) . " est prévu(e) " . evt_quand($e) . ($lieu ? "\nAdresse : $lieu" : '') . ".\n\n"
             . ($e['type'] === 'chantier' ? "Merci de veiller à ce que l'accès soit dégagé (véhicules déplacés, portail ouvert).\n\n" : '')
             . "Un empêchement ? Prévenez-nous en répondant à ce message.\n\nBien cordialement,\nÉlagage Figeacois";
        if (client_accepte($e, 'email') && envoyer_mail($e['client_email'], "Rappel – " . TYPES_EVT[$e['type']][0] . " demain", $txt)) { q("UPDATE evenements SET rappel_le = NOW() WHERE id = ?", [$e['id']]); evt_journal((int)$e['id'], 'Rappel envoyé au client'); $n++; }
    }
    // Rappel par SMS (si activé), y compris pour les clients sans e-mail
    if (sms_actif('rappel')) foreach (q("SELECT * FROM evenements WHERE DATE(debut) = ? AND statut IN ('confirme','propose') AND type IN ('chantier','visite','rdv') AND client_tel <> '' AND client_reponse <> 'autre' AND (statut = 'confirme' OR proposition_le IS NOT NULL) AND id NOT IN (SELECT evenement_id FROM sms_journal WHERE motif = 'rappel' AND evenement_id IS NOT NULL AND cree_le > DATE_SUB(NOW(), INTERVAL 2 DAY))", [$demain]) as $e) {
        if (!sms_mobile($e['client_tel']) || !client_accepte($e, 'sms')) continue;
        sms_envoyer($e['client_tel'], setting('ent_nom') . " : rappel, " . evt_libelle_client($e) . " est prevu(e) " . evt_quand($e) . ". Un empechement ? " . setting('ent_tel'), (int)$e['id'], 'rappel');
    }
    planning_taches_ext();
    return $n;
}
/** Alertes du planning (pour le panneau et le tableau de bord) */
function planning_alertes(): array {
    $a = [];
    foreach (q("SELECT id, titre, client_message FROM evenements WHERE client_reponse = 'autre' AND statut <> 'annule' ORDER BY client_repondu_le DESC LIMIT 20") as $x) $a[] = ['niv' => 'haut', 'id' => (int)$x['id'], 'txt' => "Le client demande une autre date : « {$x['titre']} »" . ($x['client_message'] ? " — « " . mb_substr($x['client_message'], 0, 120) . " »" : '')];
    foreach (q("SELECT id, titre, proposition_le FROM evenements WHERE statut = 'propose' AND client_reponse = '' AND proposition_le < DATE_SUB(NOW(), INTERVAL 3 DAY) AND debut > NOW() LIMIT 20") as $x) $a[] = ['niv' => 'moyen', 'id' => (int)$x['id'], 'txt' => "Sans réponse du client depuis le " . date('d/m', strtotime($x['proposition_le'])) . " : « {$x['titre']} »"];
    foreach (evt_periode(date('Y-m-d'), date('Y-m-d', strtotime('+21 days'))) as $e) foreach (evt_conflits($e) as $c) if (!str_starts_with($c, 'Tombe un week-end')) $a[] = ['niv' => 'haut', 'id' => (int)$e['id'], 'txt' => date('d/m', strtotime($e['debut'])) . " « {$e['titre']} » : $c"];
    foreach (q("SELECT t.*, e.titre, e.debut FROM evt_taches t JOIN evenements e ON e.id = t.evenement_id WHERE t.fait_le IS NULL AND e.statut NOT IN ('annule','termine') AND ((t.echeance IS NOT NULL AND t.echeance <= CURDATE()) OR (e.debut IS NOT NULL AND e.debut < DATE_ADD(NOW(), INTERVAL 3 DAY))) ORDER BY COALESCE(t.echeance, e.debut) LIMIT 30") as $t) $a[] = ['niv' => $t['echeance'] && $t['echeance'] < date('Y-m-d') ? 'haut' : 'moyen', 'id' => (int)$t['evenement_id'], 'txt' => "Action à faire « {$t['libelle']} » pour « {$t['titre']} »" . ($t['echeance'] ? ' (avant le ' . date('d/m', strtotime($t['echeance'])) . ')' : '')];
    try { $a = array_merge($a, materiel_alertes()); } catch (Throwable $t) {}
    try { $a = array_merge($a, livret_alertes()); } catch (Throwable $t) {}
    $n = (int)q("SELECT COUNT(*) c FROM evenements WHERE statut = 'a_planifier'")->fetch()['c'];
    if ($n) $a[] = ['niv' => 'info', 'id' => 0, 'txt' => "$n chantier(s) ou visite(s) à planifier."];
    return $a;
}

require_once __DIR__ . '/planning_ext.php';
require_once __DIR__ . '/materiel_lib.php';
require_once __DIR__ . '/phyto_lib.php';
require_once __DIR__ . '/livret_lib.php';
require_once __DIR__ . '/pictos.php';
