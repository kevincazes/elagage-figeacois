<?php
// Assistant intégré : appel à l'API Claude côté serveur, contexte de l'application, outils d'écriture avec confirmation, mémoire d'entreprise
declare(strict_types=1);
require_once __DIR__ . '/assistant_connaissances.php';

function assistant_ok(): bool { return defined('CLAUDE_API_KEY') && CLAUDE_API_KEY !== '' && CLAUDE_API_KEY !== 'COLLE_ICI_LA_CLE'; }
function assistant_modele(): string { return defined('CLAUDE_MODELE') ? CLAUDE_MODELE : 'claude-sonnet-5-5'; }
const ASSISTANT_PLAFOND_MOIS = 2000; // nombre d'échanges par mois, tous comptes confondus (≈ 30–50 $ selon le modèle)

function assistant_tables(): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS conversations (id INT AUTO_INCREMENT PRIMARY KEY, utilisateur_id INT NOT NULL, titre VARCHAR(190) NOT NULL DEFAULT '', bordereau_id INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, maj DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX (utilisateur_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS messages (id INT AUTO_INCREMENT PRIMARY KEY, conversation_id INT NOT NULL, role VARCHAR(10) NOT NULL, contenu MEDIUMTEXT, quand DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (conversation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS memoire (id INT AUTO_INCREMENT PRIMARY KEY, texte VARCHAR(500) NOT NULL, ajoute_par INT NULL, quand DATETIME DEFAULT CURRENT_TIMESTAMP, actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS assistant_usage (mois CHAR(7) PRIMARY KEY, echanges INT NOT NULL DEFAULT 0, tokens_in BIGINT NOT NULL DEFAULT 0, tokens_out BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $t) {}
}

/** Contexte de l'entreprise injecté dans chaque échange (compact) */
function assistant_contexte(?int $bordereauId): string {
    $u = user(); $lignes = [];
    $lignes[] = "Utilisateur connecté : {$u['prenom']} (" . mb_strtolower(role_libelle($u['role'])) . "). Date : " . date('d/m/Y H:i') . ".";
    $PAGES = ['index.php' => 'Tableau de bord', 'parametres.php' => 'Paramètres (taux horaires des ressources, coefficient, arrondi, progression par taille)', 'actions.php' => 'Actions dans l\'arbre', 'bpu.php' => 'Catalogue des prestations', 'packs.php' => 'Packs', 'bordereaux.php' => 'Liste des bordereaux', 'bordereau.php' => 'Un bordereau ouvert', 'planning.php' => 'Planning des chantiers et rendez-vous', 'mon-planning.php' => 'Mon planning (appli de terrain)', 'clients.php' => 'Répertoire clients', 'contrats.php' => 'Contrats d\'entretien', 'conges.php' => 'Congés', 'charge.php' => 'Tableau de charge', 'materiel.php' => 'Suivi du matériel', 'phyto.php' => 'Chantiers platanes (chancre coloré)', 'rapport.php' => 'Rapport de visite / bon d\'intervention', 'planning-ressources.php' => 'Personnel, matériel, équipes', 'reglages-planning.php' => 'Réglages du planning', 'importer.php' => 'Import d\'un bordereau reçu', 'historique.php' => 'Historique', 'guide.php' => 'Guide d\'utilisation', 'utilisateurs.php' => 'Comptes', 'compte.php' => 'Mon compte'];
    if (!empty($GLOBALS['assistant_page']) && isset($PAGES[$GLOBALS['assistant_page']])) $lignes[] = "Page où se trouve l'utilisateur : " . $PAGES[$GLOBALS['assistant_page']] . ". Si sa question porte sur l'écran, explique ce qu'il voit et où agir (menus : Tableau de bord, Planning, Clients, Paramètres, Actions, Catalogue, Packs, Bordereaux, Historique, Guide, Comptes ; dans Planning : Personnel-matériel-équipes, Mon planning, Congés, Contrats, Tableau de charge, Matériel, Platanes, Réglages).";
    $lignes[] = "Règles de calcul : PU HT = (Σ heures × taux horaire + forfait) × coefficient " . setting('coef') . ", arrondi à " . setting('arrondi') . " €, TVA " . setting('tva') . " %.";
    $lignes[] = "Progression du temps par taille : 10–20 m +" . setting('prog_h2', '80') . " %, > 20 m +" . setting('prog_h3', '200') . " %, houppier 5–10 m +" . setting('prog_d2', '50') . " %, houppier > 10 m +" . setting('prog_d3', '120') . " %.";
    $r = []; foreach (ressources() as $x) $r[] = "#{$x['id']} {$x['nom']} : {$x['taux']} €/h" . ($x['role'] ? " [{$x['role']}]" : '');
    $lignes[] = "Ressources (taux horaires) : " . implode(' ; ', $r) . '.';
    $a = []; foreach (actions_catalogue() as $x) $a[] = "#{$x['id']} {$x['nom']} ({$x['duree']} h)";
    if ($a) $lignes[] = "Actions dans l'arbre : " . implode(' ; ', $a) . '.';
    $m = []; foreach (q("SELECT texte FROM memoire WHERE actif = 1 ORDER BY id") as $x) $m[] = '- ' . $x['texte'];
    if ($m) $lignes[] = "Mémoire d'entreprise (règles validées par l'équipe) :\n" . implode("\n", $m);
    // Catalogue compact : code | libellé | unité | heures par ressource | PU
    $temps = temps_par_ligne(); $cat = [];
    $eqB = null; if ($bordereauId) { $bb = q("SELECT equipe_id FROM bordereaux WHERE id = ?", [$bordereauId])->fetch(); $eqB = $bb && $bb['equipe_id'] !== null ? (int)$bb['equipe_id'] : null; }
    $eqNom = equipe_choisie($eqB)['nom'] ?? 'équipe';
    foreach (q("SELECT * FROM bpu_lignes WHERE actif = 1 ORDER BY famille, ordre") as $l) {
        $h = []; foreach ($temps[$l['id']] ?? [] as $rid => $hh) $h[] = "r$rid=$hh";
        $cat[] = "#{$l['id']} {$l['code']} | {$l['libelle']} | {$l['unite']} | " . implode(',', $h) . ($l['forfait'] > 0 ? " | forfait {$l['forfait']}" : '') . " | PU " . prix_catalogue($l, $eqB) . ($l['prix_ref'] !== null ? " | réf {$l['prix_ref']}" : '');
    }
    $lignes[] = "Catalogue (" . count($cat) . " lignes ; rN = heures de la ressource N ; PU calculés avec : $eqNom ; les heures d'équipe sont toujours portées par l'équipe principale, les autres équipes ne changent que le taux) :\n" . implode("\n", $cat);
    if ($bordereauId) {
        $b = q("SELECT * FROM bordereaux WHERE id = ?", [$bordereauId])->fetch();
        if ($b) {
            $eq = equipe_choisie($b['equipe_id'] !== null ? (int)$b['equipe_id'] : null);
            $bl = []; $catParId = []; foreach (q("SELECT * FROM bpu_lignes") as $c) $catParId[$c['id']] = $c;
            foreach (q("SELECT * FROM bordereau_lignes WHERE bordereau_id = ? ORDER BY ordre, id", [$bordereauId]) as $l) {
                if ($l['type'] === 'section') { $bl[] = "## " . ($l['numero'] ?: $l['ordre']) . " {$l['libelle']}"; continue; }
                $pu = $b['statut'] === 'fige' ? (float)$l['pu_fige'] : ($l['pu_manuel'] !== null ? (float)$l['pu_manuel'] : (isset($catParId[$l['ligne_id']]) ? prix_catalogue($catParId[$l['ligne_id']], $eq ? (int)$eq['id'] : null) : 0));
                $bl[] = "(id {$l['id']}) " . ($l['numero'] ?: $l['ordre']) . " {$l['code']} | {$l['libelle']} | {$l['unite']} | qté " . ($l['quantite'] ?? '—') . " | PU $pu" . ($l['pu_manuel'] !== null ? ' (imposé)' : '') . ($l['ligne_id'] ? " | catalogue #{$l['ligne_id']}" : ' | sans prestation');
            }
            $lignes[] = "Bordereau ouvert #{$b['id']} « {$b['nom']} » (" . ($b['client'] ?: 'client non renseigné') . ", statut {$b['statut']}, équipe : " . ($eq['nom'] ?? '—') . ") :\n" . implode("\n", $bl);
        }
    }
    return implode("\n\n", $lignes);
}

/** Doctrine arboricole V3 (fichier lib/doctrine-ef-v3.md, hors accès web) */
/** Compétence « rapport de visite de devis » (lib/rapport-visite-devis.md) */
function assistant_skill_rapport(): string { $f = __DIR__ . '/lib/rapport-visite-devis.md'; return is_file($f) ? preg_replace('/^---.*?---\s*/s', '', (string)file_get_contents($f)) : ''; }
function assistant_doctrine(): string { $f = __DIR__ . '/lib/doctrine-ef-v3.md'; return is_file($f) ? (string)file_get_contents($f) : '(doctrine absente)'; }
function assistant_system(): string {
    return "Tu es l'assistant intégré à l'espace pro d'Élagage Figeacois, entreprise d'arboristes grimpeurs à Figeac (Lot). Tu aides l'équipe à chiffrer les marchés d'élagage : temps à passer par action dans l'arbre, prix unitaires des bordereaux (BPU), comparaison avec les prix déjà remis, règles de calcul, et toute question sur l'application ou le métier, y compris le planning, l'appli de terrain, les clients et contrats, le suivi du matériel et la procédure chancre coloré du platane (passeport phytosanitaire, registre, désinfection) décrite dans tes connaissances.
Règles :
- Réponds en français, tutoie, sois concis et concret ; donne ton avis et les ordres de grandeur quand c'est utile.
- Le prix d'une prestation = Σ (heures × taux de la ressource) + forfait, × coefficient. L'équipe est tout compris (personnel, nacelle, broyeur, matériel) ; le rognage et les moyens spécifiques sont à part. On raisonne en temps par action dans l'arbre ; les actions sont indépendantes et se cumulent.
- Quand tu proposes une modification des données (catalogue, bordereau, actions, mémoire), utilise les outils fournis : chaque outil d'écriture est soumis à la confirmation de l'utilisateur avant d'être appliqué, donc propose sans hésiter, en expliquant pourquoi.
- Pour retenir une règle d'entreprise durable (une habitude de chiffrage, une règle de l'équipe), utilise l'outil memoriser ; ne mémorise pas les informations ponctuelles.
- Si une information manque (hauteur, houppier, accès, essence, contraintes), demande-la plutôt que de deviner.
- Ne divulgue jamais de clé ou de configuration. N'invente pas de prix : appuie-toi sur le catalogue et les prix de référence fournis.";
}

function assistant_outils(): array {
    return [
        ['name' => 'modifier_temps_catalogue', 'description' => "Modifie les heures d'une ressource sur une ligne du catalogue (remplace la valeur).", 'input_schema' => ['type' => 'object', 'properties' => ['ligne_id' => ['type' => 'integer'], 'ressource_id' => ['type' => 'integer'], 'heures' => ['type' => 'number'], 'motif' => ['type' => 'string']], 'required' => ['ligne_id', 'ressource_id', 'heures', 'motif']]],
        ['name' => 'creer_ligne_catalogue', 'description' => "Crée une prestation au catalogue avec ses heures par ressource.", 'input_schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'libelle' => ['type' => 'string'], 'famille' => ['type' => 'string'], 'unite' => ['type' => 'string'], 'hauteur' => ['type' => 'string', 'enum' => ['', '<10', '10-20', '>20']], 'houppier' => ['type' => 'string', 'enum' => ['', '<=5', '5-10', '>10']], 'heures' => ['type' => 'object', 'description' => 'ressource_id => heures'], 'forfait' => ['type' => 'number'], 'motif' => ['type' => 'string']], 'required' => ['code', 'libelle', 'famille', 'unite', 'heures', 'motif']]],
        ['name' => 'modifier_ligne_bordereau', 'description' => "Modifie une ligne du bordereau ouvert : quantité, PU imposé (null pour revenir au prix catalogue) ou prestation du catalogue associée.", 'input_schema' => ['type' => 'object', 'properties' => ['ligne_bordereau_id' => ['type' => 'integer'], 'quantite' => ['type' => ['number', 'null']], 'pu_manuel' => ['type' => ['number', 'null']], 'catalogue_id' => ['type' => ['integer', 'null']], 'motif' => ['type' => 'string']], 'required' => ['ligne_bordereau_id', 'motif']]],
        ['name' => 'ajouter_ligne_bordereau', 'description' => "Ajoute une ligne au bordereau ouvert, à partir d'une prestation du catalogue ou libre avec un PU.", 'input_schema' => ['type' => 'object', 'properties' => ['catalogue_id' => ['type' => ['integer', 'null']], 'libelle' => ['type' => 'string'], 'unite' => ['type' => 'string'], 'quantite' => ['type' => ['number', 'null']], 'pu_manuel' => ['type' => ['number', 'null']], 'motif' => ['type' => 'string']], 'required' => ['libelle', 'motif']]],
        ['name' => 'creer_action', 'description' => "Crée une action dans l'arbre (geste élémentaire) avec sa durée en heures d'équipe.", 'input_schema' => ['type' => 'object', 'properties' => ['nom' => ['type' => 'string'], 'duree' => ['type' => 'number'], 'description' => ['type' => 'string'], 'motif' => ['type' => 'string']], 'required' => ['nom', 'duree', 'motif']]],
        ['name' => 'memoriser', 'description' => "Enregistre une règle d'entreprise durable dans la mémoire partagée de l'assistant (visible et modifiable par l'équipe).", 'input_schema' => ['type' => 'object', 'properties' => ['texte' => ['type' => 'string']], 'required' => ['texte']]],
    ];
}

/** Applique un outil confirmé. Retourne un message. */
function assistant_appliquer(string $nom, array $in, ?int $bordereauId): string {
    $via = ' (via l\'assistant)';
    // Bordereau : mêmes règles que pour une personne (figé = lecture seule ; ouvert par quelqu'un d'autre = lecture seule)
    if (in_array($nom, ['modifier_ligne_bordereau', 'ajouter_ligne_bordereau'], true) && $bordereauId) {
        $b = q("SELECT * FROM bordereaux WHERE id = ?", [$bordereauId])->fetch();
        if ($b && $b['statut'] === 'fige') return "Refusé : ce bordereau est figé. Défige-le d'abord si tu veux le modifier.";
        if ($b && $b['verrou_par'] && (int)$b['verrou_par'] !== (int)user()['id'] && strtotime((string)$b['verrou_le']) > time() - VERROU_MINUTES * 60) return "Refusé : ce bordereau est ouvert par quelqu'un d'autre.";
    }
    switch ($nom) {
        case 'modifier_temps_catalogue':
            $lig = q("SELECT * FROM bpu_lignes WHERE id = ?", [(int)$in['ligne_id']])->fetch(); if (!$lig) return "Prestation introuvable.";
            if ($lig['base_id']) return "Refusé : cette prestation est une variante calculée depuis la ligne #{$lig['base_id']} ; modifie plutôt le temps de la ligne de base (ou détache la variante dans le catalogue).";
            $eqP = ressource_equipe(); $res = q("SELECT * FROM ressources WHERE id = ? AND actif = 1", [(int)$in['ressource_id']])->fetch(); if (!$res) return "Ressource introuvable.";
            if ($res['role'] === 'equipe' && $eqP) $in['ressource_id'] = $eqP['id']; // les heures d'équipe sont toujours portées par l'équipe principale
            if ((int)$in['ressource_id'] === (int)($eqP['id'] ?? 0) && q("SELECT COUNT(*) c FROM ligne_actions WHERE ligne_id = ?", [(int)$in['ligne_id']])->fetch()['c']) return "Refusé : le temps d'équipe de cette prestation est la somme de ses actions ; modifie les actions (page Actions) ou retire-les de la prestation.";
            q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?) ON DUPLICATE KEY UPDATE heures = VALUES(heures)", [(int)$in['ligne_id'], (int)$in['ressource_id'], (float)$in['heures']]);
            appliquer_variantes((int)$in['ligne_id']);
            journal('catalogue', "Temps modifié sur la ligne #{$in['ligne_id']} : ressource #{$in['ressource_id']} = {$in['heures']} h — {$in['motif']}$via");
            return "Temps enregistré.";
        case 'creer_ligne_catalogue':
            $o = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM bpu_lignes")->fetch()['o'];
            q("INSERT INTO bpu_lignes (code, libelle, unite, forfait, ordre, famille, hauteur, houppier, source) VALUES (?,?,?,?,?,?,?,?,?)", [$in['code'], $in['libelle'], $in['unite'] ?: 'U', (float)($in['forfait'] ?? 0), $o, $in['famille'], $in['hauteur'] ?? '', $in['houppier'] ?? '', 'Créée via l\'assistant']);
            $id = (int)db()->lastInsertId();
            $eqIds = array_map(fn($r) => (int)$r['id'], ressources_equipes()); $eqP = ressource_equipe();
            foreach ((array)$in['heures'] as $rid => $h) if ((float)$h > 0) { $rid = in_array((int)$rid, $eqIds, true) && $eqP ? (int)$eqP['id'] : (int)$rid; q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?) ON DUPLICATE KEY UPDATE heures = heures + VALUES(heures)", [$id, $rid, (float)$h]); }
            journal('catalogue', "Ligne créée #{$id} {$in['code']} « {$in['libelle']} » — {$in['motif']}$via");
            return "Ligne {$in['code']} créée (#$id).";
        case 'modifier_ligne_bordereau':
            if (!$bordereauId) return "Aucun bordereau ouvert.";
            $l = q("SELECT * FROM bordereau_lignes WHERE id = ? AND bordereau_id = ?", [(int)$in['ligne_bordereau_id'], $bordereauId])->fetch(); if (!$l) return "Ligne introuvable.";
            $sets = []; $p = [];
            if (array_key_exists('quantite', $in)) { $sets[] = 'quantite = ?'; $p[] = $in['quantite'] === null ? null : (float)$in['quantite']; }
            if (array_key_exists('pu_manuel', $in)) { $sets[] = 'pu_manuel = ?'; $p[] = $in['pu_manuel'] === null ? null : (float)$in['pu_manuel']; }
            if (array_key_exists('catalogue_id', $in)) { $sets[] = 'ligne_id = ?'; $p[] = $in['catalogue_id'] === null ? null : (int)$in['catalogue_id']; }
            if (!$sets) return "Rien à modifier."; $p[] = (int)$l['id'];
            q("UPDATE bordereau_lignes SET " . implode(', ', $sets) . " WHERE id = ?", $p);
            journal('modif', "[{$l['numero']} " . mb_substr($l['libelle'], 0, 40) . "] " . json_encode(array_intersect_key($in, array_flip(['quantite', 'pu_manuel', 'catalogue_id'])), JSON_UNESCAPED_UNICODE) . " — {$in['motif']}$via", $bordereauId);
            return "Ligne modifiée.";
        case 'ajouter_ligne_bordereau':
            if (!$bordereauId) return "Aucun bordereau ouvert.";
            $o = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM bordereau_lignes WHERE bordereau_id = ?", [$bordereauId])->fetch()['o'];
            $c = !empty($in['catalogue_id']) ? q("SELECT * FROM bpu_lignes WHERE id = ?", [(int)$in['catalogue_id']])->fetch() : null;
            q("INSERT INTO bordereau_lignes (bordereau_id, ordre, type, code, libelle, unite, ligne_id, quantite, pu_manuel) VALUES (?,?,'ligne',?,?,?,?,?,?)", [$bordereauId, $o, $c['code'] ?? '', $in['libelle'] ?: ($c['libelle'] ?? ''), $in['unite'] ?? ($c['unite'] ?? 'U'), $c ? (int)$c['id'] : null, isset($in['quantite']) ? (float)$in['quantite'] : null, isset($in['pu_manuel']) ? (float)$in['pu_manuel'] : null]);
            journal('ajout', "Ligne « {$in['libelle']} » — {$in['motif']}$via", $bordereauId);
            return "Ligne ajoutée au bordereau.";
        case 'creer_action':
            $o = (int)q("SELECT COALESCE(MAX(ordre),0)+1 o FROM actions")->fetch()['o'];
            q("INSERT INTO actions (nom, duree, description, ordre) VALUES (?,?,?,?)", [$in['nom'], (float)$in['duree'], $in['description'] ?? '', $o]);
            journal('catalogue', "Action créée « {$in['nom']} » ({$in['duree']} h) — {$in['motif']}$via");
            return "Action créée.";
        case 'memoriser':
            q("INSERT INTO memoire (texte, ajoute_par) VALUES (?,?)", [mb_substr($in['texte'], 0, 500), user()['id']]);
            journal('catalogue', "Mémoire de l'assistant : « {$in['texte']} »");
            return "Règle mémorisée.";
    }
    return "Outil inconnu.";
}

/** Appel API. $messages : [['role'=>'user'|'assistant','content'=>string|array]] */
function assistant_appel(array $messages, ?int $bordereauId): array {
    $mois = date('Y-m');
    $u = q("SELECT * FROM assistant_usage WHERE mois = ?", [$mois])->fetch();
    if ($u && (int)$u['echanges'] >= ASSISTANT_PLAFOND_MOIS) throw new RuntimeException("Plafond mensuel de l'assistant atteint (" . ASSISTANT_PLAFOND_MOIS . " échanges). Un administrateur peut l'augmenter.");
    $body = ['model' => assistant_modele(), 'max_tokens' => 1500, 'system' => [['type' => 'text', 'text' => assistant_system()], ['type' => 'text', 'text' => "Fonctionnement de l'espace pro et procédures de l'entreprise (planning, terrain, clients, contrats, matériel, chancre coloré du platane) :\n\n" . assistant_connaissances()],
        ['type' => 'text', 'text' => "COMPÉTENCE « RAPPORT DE VISITE DE DEVIS » — quand on te demande un rapport de visite, de mettre en forme des notes de terrain ou de préparer un devis, applique cette méthode :\n\n" . assistant_skill_rapport()],
        ['type' => 'text', 'text' => "DOCTRINE ARBORICOLE D'ÉLAGAGE FIGEACOIS (V3, 15 septembre 2026) — source de vérité pour tout raisonnement, préconisation, devis, rapport, diagnostic, consolidation (distinguer haubanage et brochage), travaux, biodiversité, avifaune et espèces protégées. Applique-la telle qu'elle est écrite : ne la résume pas, ne la réinterprète pas, ne la complète pas en la présentant comme doctrine ; si une information en est absente, dis-le et présente-la comme complément éventuel. En cas de conflit avec une habitude de rédaction, la doctrine prévaut. Pour les devis, rapports de visite, diagnostics, préparations de chantier et échanges clients, utilise-la comme cadre de décision avant de rédiger.\n\n" . assistant_doctrine(), 'cache_control' => ['type' => 'ephemeral']], ['type' => 'text', 'text' => "Contexte de l'application :\n\n" . assistant_contexte($bordereauId), 'cache_control' => ['type' => 'ephemeral']]], 'tools' => assistant_outils(), 'messages' => $messages];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . CLAUDE_API_KEY], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) throw new RuntimeException("Connexion à l'API impossible : $err");
    $j = json_decode($res, true);
    if ($code >= 400) throw new RuntimeException("API (HTTP $code) : " . ($j['error']['message'] ?? $res));
    $in = (int)($j['usage']['input_tokens'] ?? 0) + (int)($j['usage']['cache_creation_input_tokens'] ?? 0) + (int)($j['usage']['cache_read_input_tokens'] ?? 0); $out = (int)($j['usage']['output_tokens'] ?? 0);
    q("INSERT INTO assistant_usage (mois, echanges, tokens_in, tokens_out) VALUES (?,1,?,?) ON DUPLICATE KEY UPDATE echanges = echanges + 1, tokens_in = tokens_in + VALUES(tokens_in), tokens_out = tokens_out + VALUES(tokens_out)", [$mois, $in, $out]);
    return $j;
}

/** Résumé lisible d'une action proposée par l'assistant (noms au lieu de numéros) */
function assistant_resume(string $nom, array $i): string {
    $lig = fn($id) => ($l = q("SELECT code, libelle FROM bpu_lignes WHERE id = ?", [(int)$id])->fetch()) ? trim($l['code'] . ' – ' . $l['libelle'], ' –') : "prestation n° $id";
    $res = fn($id) => q("SELECT nom FROM ressources WHERE id = ?", [(int)$id])->fetch()['nom'] ?? "ressource n° $id";
    $bl = fn($id) => ($l = q("SELECT numero, libelle FROM bordereau_lignes WHERE id = ?", [(int)$id])->fetch()) ? trim(($l['numero'] ? $l['numero'] . ' ' : '') . $l['libelle']) : "ligne n° $id";
    $h = fn($v) => str_replace('.', ',', (string)(float)$v) . ' h';
    $t = match ($nom) {
        'modifier_temps_catalogue' => $lig($i['ligne_id'] ?? 0) . "\n" . $res($i['ressource_id'] ?? 0) . ' : ' . (($a = q("SELECT heures FROM bpu_temps WHERE ligne_id = ? AND ressource_id = ?", [(int)($i['ligne_id'] ?? 0), (int)($i['ressource_id'] ?? 0)])->fetch()) ? $h($a['heures']) : '—') . ' → ' . $h($i['heures'] ?? 0),
        'creer_ligne_catalogue' => ($i['code'] ?? '') . ' – ' . ($i['libelle'] ?? '') . (isset($i['famille']) ? ' (' . $i['famille'] . ')' : ''),
        'modifier_ligne_bordereau' => $bl($i['ligne_bordereau_id'] ?? 0) . (array_key_exists('quantite', $i) ? "\nQuantité → " . ($i['quantite'] ?? '—') : '') . (array_key_exists('pu_manuel', $i) ? "\nPU imposé → " . ($i['pu_manuel'] === null ? 'prix du catalogue' : $i['pu_manuel'] . ' €') : '') . (array_key_exists('catalogue_id', $i) ? "\nPrestation → " . ($i['catalogue_id'] ? $lig($i['catalogue_id']) : 'aucune') : ''),
        'ajouter_ligne_bordereau' => ($i['libelle'] ?? '') . (!empty($i['quantite']) ? ' × ' . $i['quantite'] : '') . (!empty($i['pu_manuel']) ? ' à ' . $i['pu_manuel'] . ' €' : ''),
        'creer_action' => ($i['nom'] ?? '') . ' (' . $h($i['duree'] ?? 0) . ')',
        'memoriser' => '« ' . ($i['texte'] ?? '') . ' »',
        default => json_encode($i, JSON_UNESCAPED_UNICODE),
    };
    return $t . (!empty($i['motif']) ? "\nMotif : " . $i['motif'] : '');
}
