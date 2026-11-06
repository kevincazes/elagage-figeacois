<?php
// Agenda synchronisé dans les deux sens avec les téléphones (CalDAV : Calendrier de l'iPhone, DAVx⁵ / Google Agenda sur Android).
// Chaque personne voit SES chantiers et rendez-vous. Rendez-vous et visites : déplaçables / modifiables depuis le téléphone (repris dans le planning).
// Chantiers, urgences, absences, entretiens : lecture seule (le téléphone reçoit un refus et remet l'horaire du planning).
// Adresse du compte : https://…/espace-pro/caldav.php  — identifiant : e-mail de la personne ; mot de passe : « mot de passe agenda » créé dans Mon compte.
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
planning_migrate();

const DAV_MODIFIABLES = ['rdv', 'visite'];
$apres = preg_match('#/caldav\.php(/[^?]*)?#', (string)$_SERVER['REQUEST_URI'], $mm) ? rawurldecode($mm[1] ?? '') : (string)($_SERVER['PATH_INFO'] ?? '');
$chemin = '/' . trim($apres, '/');
$script = (string)parse_url(preg_replace('#/caldav\.php.*$#', '/caldav.php', (string)$_SERVER['REQUEST_URI']), PHP_URL_PATH); // adresse de base, ex. /espace-pro/caldav.php
$methode = $_SERVER['REQUEST_METHOD'];

function dav_x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function dav_fin(int $code, string $corps = '', string $type = 'application/xml; charset=utf-8', array $h = []): never {
    http_response_code($code); header('DAV: 1, 3, calendar-access'); if ($corps !== '') header("Content-Type: $type"); foreach ($h as $k => $v) header("$k: $v"); echo $corps; exit;
}
function dav_ms(array $reponses): never {
    dav_fin(207, '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ic="http://apple.com/ns/ical/">' . implode('', $reponses) . '</d:multistatus>');
}
function dav_rep(string $href, string $props): string { return '<d:response><d:href>' . dav_x($href) . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'; }

if ($methode === 'OPTIONS') dav_fin(200, '', 'text/plain', ['Allow' => 'OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, REPORT']);

// ---- Authentification (Basic) : e-mail + mot de passe agenda ----
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!isset($_SERVER['PHP_AUTH_USER']) && stripos($auth, 'basic ') === 0) { [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] = array_pad(explode(':', (string)base64_decode(substr($auth, 6)), 2), 2, ''); }
$login = mb_strtolower(trim((string)($_SERVER['PHP_AUTH_USER'] ?? ''))); $mdp = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
$P = null;
if ($login !== '' && $mdp !== '') foreach (q("SELECT p.* FROM personnel p LEFT JOIN utilisateurs u ON u.id = p.utilisateur_id WHERE p.actif = 1 AND p.caldav_mdp IS NOT NULL AND (LOWER(p.email) = ? OR LOWER(u.email) = ?)", [$login, $login]) as $x) if (password_verify($mdp, $x['caldav_mdp'])) { $P = $x; break; }
if (!$P) dav_fin(401, 'Identifiant ou mot de passe agenda incorrect', 'text/plain', ['WWW-Authenticate' => 'Basic realm="Planning Elagage Figeacois", charset="UTF-8"']);
$pid = (int)$P['id'];
$uPrincipal = "$script/p/$pid/"; $uHome = "$script/c/$pid/"; $uCal = "$script/c/$pid/planning/";

// ---- Événements de la personne ----
function dav_nom(array $e): string { return $e['caldav_nom'] ?: 'evt-' . $e['id'] . '.ics'; }
function dav_evs(int $pid): array {
    $l = [];
    foreach (q("SELECT e.id FROM evenements e JOIN evenement_personnel ep ON ep.evenement_id = e.id WHERE ep.personnel_id = ? AND e.statut <> 'annule' AND e.debut IS NOT NULL AND e.fin > DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY e.debut LIMIT 1500", [$pid]) as $x) { $e = evt_charger((int)$x['id']); if ($e) $l[dav_nom($e)] = $e; }
    return $l;
}
function dav_etag(array $e): string { return '"' . md5(implode('|', [$e['id'], $e['type'], $e['titre'], $e['statut'], $e['debut'], $e['fin'], $e['journee'], $e['adresse'], $e['cp'], $e['ville'], $e['client_nom'], $e['client_tel'], $e['consignes'] ?? '', $e['caldav_uid'] ?? ''])) . '"'; }
function dav_ics(array $e): string {
    $v = ics_evenement($e);
    if (!empty($e['caldav_uid'])) $v = preg_replace_callback('/^UID:[^\r\n]*/m', fn() => 'UID:' . str_replace(["\r", "\n"], '', $e['caldav_uid']), $v, 1);
    if (!in_array($e['type'], DAV_MODIFIABLES, true)) $v = preg_replace_callback('/^DESCRIPTION:(.*)$/m', fn($m) => 'DESCRIPTION:' . (TYPES_EVT[$e['type']][0] ?? 'Chantier') . ' : horaire fixé par le planning (non modifiable depuis le téléphone)\\n' . $m[1], $v, 1);
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Elagage Figeacois//Planning//FR\r\nCALSCALE:GREGORIAN\r\n" . $v . "END:VCALENDAR\r\n";
}
function dav_ctag(array $evs): string { return md5(implode(',', array_map('dav_etag', $evs))); }
function dav_props_evt(string $href, array $e, bool $donnees): string {
    return dav_rep($href, '<d:getetag>' . dav_x(dav_etag($e)) . '</d:getetag><d:getcontenttype>text/calendar; charset=utf-8; component=VEVENT</d:getcontenttype><d:resourcetype/>' . ($donnees ? '<c:calendar-data>' . dav_x(dav_ics($e)) . '</c:calendar-data>' : ''));
}
$propsCal = function (array $evs) use ($uCal, $P): string {
    return dav_rep($uCal, '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>' . dav_x('Élagage Figeacois – ' . $P['prenom']) . '</d:displayname>'
        . '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set><cs:getctag>' . dav_ctag($evs) . '</cs:getctag><d:sync-token>http://elagage-figeacois.com/ns/sync/' . dav_ctag($evs) . '</d:sync-token>'
        . '<ic:calendar-color>#2F6B45FF</ic:calendar-color><c:calendar-timezone>Europe/Paris</c:calendar-timezone>'
        . '<d:current-user-privilege-set><d:privilege><d:read/></d:privilege><d:privilege><d:write/></d:privilege><d:privilege><d:write-content/></d:privilege><d:privilege><d:bind/></d:privilege><d:privilege><d:unbind/></d:privilege></d:current-user-privilege-set>'
        . '<d:supported-report-set><d:supported-report><d:report><c:calendar-query/></d:report></d:supported-report><d:supported-report><d:report><c:calendar-multiget/></d:report></d:supported-report></d:supported-report-set>'
        );
};
$propsPrincipal = fn(string $href) => dav_rep($href, '<d:resourcetype><d:collection/>' . ($href === $uPrincipal ? '<d:principal/>' : '') . '</d:resourcetype><d:displayname>' . dav_x($P['prenom'] . ' ' . $P['nom']) . '</d:displayname><d:current-user-principal><d:href>' . dav_x($uPrincipal) . '</d:href></d:current-user-principal><d:principal-URL><d:href>' . dav_x($uPrincipal) . '</d:href></d:principal-URL><c:calendar-home-set><d:href>' . dav_x($uHome) . '</d:href></c:calendar-home-set><c:calendar-user-address-set><d:href>mailto:' . dav_x($login) . '</d:href></c:calendar-user-address-set>');

$depth = (string)($_SERVER['HTTP_DEPTH'] ?? '0'); $corps = (string)file_get_contents('php://input');

// ---- Routage ----
if ($methode === 'PROPFIND') {
    if ($chemin === '/' || preg_match('#^/p/\d+$#', $chemin) || $chemin === '/p') dav_ms([$propsPrincipal($chemin === '/' ? "$script/" : $uPrincipal)]);
    if ($chemin === "/c/$pid") { $r = [dav_rep($uHome, '<d:resourcetype><d:collection/></d:resourcetype><d:current-user-principal><d:href>' . dav_x($uPrincipal) . '</d:href></d:current-user-principal>')]; if ($depth !== '0') $r[] = $propsCal(dav_evs($pid)); dav_ms($r); }
    if ($chemin === "/c/$pid/planning") { $evs = dav_evs($pid); $r = [$propsCal($evs)]; if ($depth !== '0') foreach ($evs as $nom => $e) $r[] = dav_props_evt($uCal . $nom, $e, false); dav_ms($r); }
    if (preg_match('#^/c/' . $pid . '/planning/([^/]+)$#', $chemin, $m)) { $evs = dav_evs($pid); if (!isset($evs[$m[1]])) dav_fin(404); dav_ms([dav_props_evt($uCal . $m[1], $evs[$m[1]], false)]); }
    dav_fin(404);
}
if ($methode === 'REPORT' && $chemin === "/c/$pid/planning") {
    $evs = dav_evs($pid); $r = [];
    if (stripos($corps, 'calendar-multiget') !== false) { // éléments demandés un par un
        preg_match_all('#<(?:[a-z0-9]+:)?href[^>]*>([^<]+)</#i', $corps, $m);
        foreach ($m[1] as $h) { $nom = basename(rawurldecode(html_entity_decode(trim($h)))); $r[] = isset($evs[$nom]) ? dav_props_evt($uCal . $nom, $evs[$nom], true) : '<d:response><d:href>' . dav_x(trim($h)) . '</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>'; }
    } else foreach ($evs as $nom => $e) $r[] = dav_props_evt($uCal . $nom, $e, stripos($corps, 'calendar-data') !== false); // calendar-query : toute la période
    dav_ms($r);
}
if (preg_match('#^/c/' . $pid . '/planning/([^/]+\.ics)$#i', $chemin, $m)) {
    $nom = $m[1]; $evs = dav_evs($pid); $e = $evs[$nom] ?? null;
    if ($methode === 'GET' || $methode === 'HEAD') { if (!$e) dav_fin(404); dav_fin(200, $methode === 'HEAD' ? '' : dav_ics($e), 'text/calendar; charset=utf-8', ['ETag' => dav_etag($e)]); }
    if ($methode === 'DELETE') {
        if (!$e) dav_fin(404);
        if (!in_array($e['type'], DAV_MODIFIABLES, true)) dav_fin(403, 'Les chantiers se gèrent dans le planning.', 'text/plain');
        q("UPDATE evenements SET statut = 'annule', maj_le = NOW() WHERE id = ?", [$e['id']]); evt_journal((int)$e['id'], 'Annulé depuis le téléphone (agenda de ' . $P['prenom'] . ')');
        dav_fin(204);
    }
    if ($methode === 'PUT') {
        if ($e && isset($_SERVER['HTTP_IF_MATCH']) && trim($_SERVER['HTTP_IF_MATCH']) !== '*' && trim($_SERVER['HTTP_IF_MATCH']) !== dav_etag($e)) dav_fin(412);
        if ($e && !in_array($e['type'], DAV_MODIFIABLES, true)) dav_fin(403, 'Ce chantier est fixé par le planning : il ne se déplace pas depuis le téléphone.', 'text/plain');
        $lus = ics_lire($corps, '1970-01-01', '2100-01-01'); $v = $lus[0] ?? null;
        if (!$v || empty($v['debut'])) dav_fin(400, 'Événement illisible', 'text/plain');
        $uid = preg_match('/^UID:(.+)$/mi', str_replace("\r", '', $corps), $u) ? trim($u[1]) : '';
        $titre = mb_substr(trim($v['titre']) ?: 'Rendez-vous', 0, 200); $fin = $v['fin'] ?: date('Y-m-d H:i:s', strtotime($v['debut']) + 3600);
        if ($e) {
            $chg = [];
            if ($e['debut'] !== $v['debut'] || $e['fin'] !== $fin) $chg[] = 'horaire ' . date('d/m H:i', strtotime($e['debut'])) . ' → ' . date('d/m H:i', strtotime($v['debut']));
            if ($e['titre'] !== $titre) $chg[] = "titre « $titre »";
            q("UPDATE evenements SET debut = ?, fin = ?, journee = ?, titre = ?, maj_le = NOW() WHERE id = ?", [$v['debut'], $fin, (int)$v['journee'], $titre, $e['id']]);
            if ($chg) evt_journal((int)$e['id'], 'Modifié depuis le téléphone (agenda de ' . $P['prenom'] . ') : ' . implode(', ', $chg));
            try { externes_repercuter((int)$e['id']); } catch (Throwable $t) {} // rendez-vous repris d'Extrabat : même changement là-bas
            $id = (int)$e['id']; $code = 204;
        } else { // créé sur le téléphone : nouveau rendez-vous de la personne
            q("INSERT INTO evenements (type, titre, statut, debut, fin, journee, adresse, notes, cree_le, maj_le, caldav_nom, caldav_uid) VALUES ('rdv', ?, 'planifie', ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)", [$titre, $v['debut'], $fin, (int)$v['journee'], mb_substr((string)$v['lieu'], 0, 255), 'Créé depuis le téléphone (' . $P['prenom'] . ')', mb_substr($nom, 0, 190), mb_substr($uid, 0, 190)]);
            $id = (int)db()->lastInsertId(); q("INSERT INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$id, $pid]);
            evt_journal($id, 'Créé depuis le téléphone (agenda de ' . $P['prenom'] . ')'); $code = 201;
        }
        $n = evt_charger($id); dav_fin($code, '', 'text/plain', ['ETag' => dav_etag($n)]);
    }
}
if ($methode === 'GET' && in_array($chemin, ['/', "/c/$pid/planning"], true)) dav_fin(200, ics_calendrier(array_values(dav_evs($pid)), 'Élagage Figeacois – ' . $P['prenom']), 'text/calendar; charset=utf-8');
dav_fin(405, '', 'text/plain', ['Allow' => 'OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, REPORT']);
