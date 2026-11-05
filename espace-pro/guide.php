<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_chiffrage();
page_start('Guide d\'utilisation', 'guide.php');
/** Figure du guide : capture annotée + légende numérotée (les numéros correspondent aux pastilles orange) */
function fig(string $img, string $titre, array $legende = [], string $classe = ''): void {
    echo '<figure class="cap ' . $classe . '"><a href="guide-img.php?f=' . e($img) . '.jpg" target="_blank" title="Ouvrir en grand"><img src="guide-img.php?f=' . e($img) . '.jpg" alt="' . e($titre) . '" loading="lazy"></a><figcaption><strong>' . e($titre) . '</strong>';
    if ($legende) { echo '<ol class="leg">'; foreach ($legende as $l) echo '<li>' . $l . '</li>'; echo '</ol>'; }
    echo '</figcaption></figure>';
}
?>
<style>
.guide{display:grid;grid-template-columns:240px minmax(0,1fr);gap:40px;align-items:start}
.guide .corps{max-width:980px;min-width:0}
.guide h2{margin-top:2.2em;padding-top:1em;border-top:2px solid var(--clair);font-size:1.5rem;scroll-margin-top:14px}.guide h3{margin-top:1.6em;font-size:1.12rem;scroll-margin-top:14px}
.guide p,.guide li{line-height:1.6}.guide .ex{background:var(--clair);border-left:4px solid var(--anis);padding:12px 16px;border-radius:8px;margin:14px 0}
.guide .ex strong{color:var(--sapin)}.guide .att{background:#fff6e6;border-left:4px solid #e8590c;padding:12px 16px;border-radius:8px;margin:14px 0}
.guide kbd{background:#fff;border:1px solid #cfd6c8;border-radius:5px;padding:1px 7px;font-size:.88rem;font-weight:700;color:var(--sapin);white-space:nowrap}
.guide table{width:100%;border-collapse:collapse;margin:10px 0}.guide td,.guide th{padding:7px 9px;border-bottom:1px solid #e6eadf;text-align:left;vertical-align:top}.guide th{background:#f8f9f4;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em}
.guide figure.cap{margin:18px 0 26px;background:var(--blanc);border-radius:12px;box-shadow:0 4px 18px rgba(28,59,43,.08);overflow:hidden}
.guide figure.cap img{display:block;width:100%;height:auto;border-bottom:1px solid #e6eadf}
.guide figure.cap a{display:block;cursor:zoom-in}
.guide figcaption{padding:12px 18px 14px;font-size:.95rem}
.guide ol.leg{margin:8px 0 0;padding:0;list-style:none;counter-reset:leg}
.guide ol.leg li{counter-increment:leg;position:relative;padding-left:34px;margin:6px 0;line-height:1.5}
.guide ol.leg li::before{content:counter(leg);position:absolute;left:0;top:0;width:24px;height:24px;border-radius:50%;background:#e8590c;color:#fff;font-weight:900;font-size:.82rem;line-height:24px;text-align:center}
.guide figure.tel{max-width:340px}
.guide .som{scroll-margin-top:14px;position:sticky;top:calc(var(--bandeau,70px) + 12px);max-height:calc(100vh - var(--bandeau,70px) - 24px);overflow-y:auto;padding:14px 16px;margin:0}
.guide .som strong{display:block;margin-bottom:8px;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--doux)}
.guide .som ol{margin:0;padding-left:24px}.guide .som li{margin:2px 0;line-height:1.35}
.guide .som a{text-decoration:none;color:var(--texte);display:block;padding:4px 6px;border-radius:6px;font-size:.92rem}
.guide .som a:hover{background:var(--clair)}.guide .som a.actif{background:var(--clair);color:var(--sapin);font-weight:700}
@media(max-width:1000px){
  .guide{display:block}
  .guide .som{position:static;max-height:none;overflow:visible;margin-bottom:10px}
  .guide .som ol{columns:2;gap:24px}
  .guide .retour{position:fixed;left:16px;bottom:16px;z-index:4;background:var(--sapin);color:#fff;border-radius:999px;padding:10px 16px;text-decoration:none;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,.2)}
}
@media(max-width:700px){.guide .som ol{columns:1}}
@media(min-width:1001px){.guide .retour{display:none}}
</style>
<div class="guide">
<aside class="card som" id="sommaire"><strong>Sommaire</strong>
<ol>
<li><a href="#demarrer">Prendre en main l'espace pro</a></li>
<li><a href="#logique">La logique du chiffrage</a></li>
<li><a href="#parametres">Paramètres</a></li>
<li><a href="#catalogue">Catalogue des prestations</a></li>
<li><a href="#actions">Actions dans l'arbre</a></li>
<li><a href="#packs">Packs</a></li>
<li><a href="#bordereaux">Bordereaux : la liste et la création</a></li>
<li><a href="#import">Importer un bordereau reçu</a></li>
<li><a href="#bordereau">Travailler dans un bordereau</a></li>
<li><a href="#marche">Chiffrer un marché pas à pas</a></li>
<li><a href="#assistant">L'assistant</a></li>
<li><a href="#equipe">Travailler à plusieurs</a></li>
<li><a href="#faq">Questions fréquentes</a></li>
<li><a href="#planning">Le planning</a></li>
<li><a href="#terrain">Mon planning : l'appli de terrain</a></li>
<li><a href="#clients">Clients, contrats, tableau de charge</a></li>
<li><a href="#materiel">Suivi du matériel</a></li>
<li><a href="#platanes">Chantiers platanes et passeport</a></li>
<li><a href="#reglages-planning">Réglages : SMS, avis, Pennylane…</a></li>
</ol></aside>
<div class="corps">
<p class="lead">Ce guide explique, écran par écran, où cliquer et à quoi sert chaque élément. Les <strong>pastilles orange numérotées</strong> sur les captures renvoient aux explications juste en dessous. Clique sur une image pour l'ouvrir en grand.</p>
<div class="ex"><strong>En une phrase :</strong> le catalogue contient le <em>temps</em> de chaque prestation ; un bordereau applique à ces temps le <em>taux horaire</em> de l'équipe choisie, plus la marge. Ton travail courant tient en trois écrans : <strong>Bordereaux → Importer</strong>, le <strong>bordereau</strong> lui-même, et le <strong>Catalogue</strong> quand un temps est à ajuster.</div>

<h2 id="demarrer">1. Prendre en main l'espace pro</h2>
<p>Le tableau de bord est la page d'accueil. On y revient à tout moment par le logo ou par « Tableau de bord ».</p>
<?php fig('tableau-de-bord', 'Le tableau de bord', [
    '<strong>Le menu</strong> : chaque rubrique de l\'espace pro. La rubrique où tu te trouves est surlignée en vert. « Site ↗ » ouvre le site public, « Déconnexion » ferme ta session.',
    '<strong>La cloche</strong> : les nouveautés de l\'espace pro. Le chiffre indique les versions que tu n\'as pas encore vues ; un clic ouvre la liste par-dessus la page, sans la quitter.',
    '<strong>Le bouton Assistant</strong> : présent en bas à droite de chaque page. Il ouvre un panneau latéral pour poser une question ou demander une modification (voir partie 11).',
    '<strong>Importer un bordereau reçu</strong> : le point de départ habituel, quand une collectivité t\'envoie son BPU ou son DQE.',
    '<strong>Nouveau bordereau</strong> : créer un bordereau vide ou à partir d\'un pack.',
    '<strong>Le fil d\'Ariane</strong> : indique où tu es (ex. Tableau de bord › Bordereaux › Lot Habitat 2026). Chaque élément est cliquable pour remonter d\'un niveau.',
]); ?>
<p>Les tuiles <strong>Outils de l'entreprise</strong> ouvrent Google Drive, Extrabat, Urgence Élagage et Arbogestion dans un nouvel onglet. Une tuile grisée signifie que son lien n'est pas encore renseigné dans Paramètres.</p>
<p><strong>Sur téléphone</strong>, le menu est replié derrière le bouton à trois traits ; toutes les pages s'affichent sur une colonne et les grands tableaux défilent avec le doigt.</p>
<?php fig('mobile-menu', 'Sur téléphone : le menu à trois traits', ['<strong>Bouton menu</strong> : ouvre et ferme la liste des rubriques.'], 'tel'); ?>

<h2 id="logique">2. La logique du chiffrage</h2>
<p>Tout repose sur une idée simple : <strong>un prix, c'est du temps multiplié par un taux horaire</strong>, avec une marge par-dessus.</p>
<div class="ex"><strong>Formule :</strong> PU HT = (heures × taux horaire de chaque ressource + forfait éventuel) × coefficient, arrondi.<br>
<strong>Exemple :</strong> 2 h d'équipe à 190 €/h = 380 € ; × 1,25 = 475 € ; arrondi à l'euro = <strong>475 € HT</strong>.<br>
<strong>Même ligne, équipe marché public à 60 €/h :</strong> 2 h × 60 = 120 € ; × 1,25 = <strong>150 € HT</strong>. Le temps ne change pas, seul le taux change.</div>
<table><tr><th>Brique</th><th>C'est quoi</th><th>Où</th></tr>
<tr><td><strong>Ressources</strong></td><td>Ce qui coûte à l'heure : les <strong>équipes</strong> (tout compris : personnel, nacelle, broyeur, matériel), le <strong>rognage</strong>, et d'éventuels <strong>moyens spécifiques</strong> (évacuation du bois, grue…).</td><td>Paramètres</td></tr>
<tr><td><strong>Prestations</strong></td><td>Les lignes que l'on vend (abattage &lt; 10 m, taille d'entretien 10–20 m…). Chacune porte ses <strong>heures</strong> : heures d'équipe, heures de rognage, etc.</td><td>Catalogue</td></tr>
<tr><td><strong>Actions</strong></td><td>Facultatif : les gestes dans l'arbre avec leur durée, pour construire le temps d'une prestation geste par geste.</td><td>Actions</td></tr>
<tr><td><strong>Packs</strong></td><td>Des listes de prestations à mettre d'un coup dans un bordereau.</td><td>Packs</td></tr>
<tr><td><strong>Bordereaux</strong></td><td>Le document d'un marché : les lignes au format de la collectivité, l'équipe appliquée, les quantités, les prix.</td><td>Bordereaux</td></tr></table>
<div class="att"><strong>À retenir :</strong> le catalogue stocke des <em>heures</em>, pas des prix. Changer un taux dans Paramètres change tous les prix (sauf les bordereaux figés) ; changer un temps dans le catalogue ne change qu'une prestation. L'équipe (standard ou marché public) se choisit <strong>par bordereau</strong>.</div>

<h2 id="parametres">3. Paramètres</h2>
<p>Réglés une fois au départ, puis à chaque révision de tarifs. Tout ce qui est réglé ici est commun à tous les comptes. <strong>Page réservée aux administrateurs.</strong></p>
<?php fig('parametres', 'La page Paramètres', [
    '<strong>Taux horaires des ressources</strong> : une ligne par ressource, avec son nom (modifiable) et son prix de l\'heure HT. La première ressource de type « Équipe » est l\'équipe standard.',
    '<strong>Type</strong> : <em>Équipe</em> (tout compris, choisie par bordereau), <em>Rognage</em>, ou <em>Moyen spécifique</em> (évacuation du bois, grue… ajoutés ligne par ligne au catalogue).',
    '<strong>Enregistrer les taux</strong> : à cliquer après toute modification du tableau. Tous les prix non figés sont recalculés.',
    '<strong>Ajouter une ressource</strong> : nom, type, taux, puis « Ajouter ». Elle apparaît ensuite comme une colonne d\'heures dans le catalogue (ou comme équipe dans les menus si son type est Équipe).',
    '<strong>Règles de calcul</strong> : le coefficient (marge, frais généraux, aléas : 1,25 = +25 %), la TVA, et l\'arrondi des prix (1 = à l\'euro, 5 = aux 5 €, 0 = au centime).',
    '<strong>Progression selon la taille</strong> : les pourcentages de temps en plus par classe de hauteur et de houppier, utilisés par le bouton ↕ du catalogue pour créer les variantes d\'une prestation. Bouton « Enregistrer les règles » en dessous.',
]); ?>
<?php fig('parametres-liens', 'Les liens du tableau de bord', ['<strong>Adresses des outils</strong> : Google Drive, Extrabat, Urgence Élagage, Arbogestion. Colle l\'adresse complète (commençant par https://) puis « Enregistrer les liens ». Sans rapport avec le chiffrage.']); ?>
<div class="ex"><strong>Exemple :</strong> le gasoil augmente, tu passes l'équipe standard de 190 à 200 €/h → « Enregistrer les taux ». Tous les bordereaux en brouillon et le catalogue sont recalculés ; les offres déjà figées ne bougent pas.</div>

<h2 id="catalogue">4. Catalogue des prestations</h2>
<p>Le catalogue contient toutes les prestations que tu vends, rangées en <strong>blocs par famille</strong> (Abattage, Taille d'entretien, Dessouchage…), et dans chaque bloc par <strong>classe de hauteur</strong>. C'est ici que tu règles le temps de chaque prestation.</p>
<h3>La barre de filtres</h3>
<?php fig('catalogue-filtres', 'Trouver une prestation', [
    '<strong>Famille</strong> : n\'afficher qu\'une famille (s\'applique dès le choix).',
    '<strong>Hauteur</strong> : n\'afficher qu\'une classe de hauteur.',
    '<strong>Recherche</strong> : un mot du libellé ou un code (ex. « platane », « ABT-H2 »), puis Entrée.',
    '<strong>Pack</strong> : n\'afficher que les lignes d\'un pack, pour régler leurs temps sans voir le reste.',
    '<strong>Prix avec</strong> : l\'équipe utilisée pour <em>afficher</em> les prix (standard ou marché public). C\'est un aperçu : rien n\'est modifié.',
    '<strong>Tout déplier / Tout replier</strong> : ouvrir ou fermer tous les blocs. « Tout afficher » enlève les filtres.',
    '<strong>Un bloc de famille</strong> : clic sur la bande verte pour l\'ouvrir ou le fermer. Elle indique le nombre de lignes et la fourchette de prix. L\'état ouvert/fermé est mémorisé.',
]); ?>
<h3>Le tableau : partie gauche (description de la prestation)</h3>
<?php fig('catalogue-gauche', 'Les colonnes de description', [
    '<strong>Bloc de famille</strong> ouvert (▾). Le titre reste visible à gauche même quand on fait défiler vers la droite.',
    '<strong>Sous-titre de hauteur</strong> : les lignes sont regroupées par classe (moins de 10 m, 10 à 20 m, plus de 20 m).',
    '<strong>Désignation</strong> : le libellé de la prestation, affiché en entier. Cette colonne reste figée à gauche quand tu fais défiler le tableau : tu sais toujours quelle ligne tu modifies.',
    '<strong>Haut.</strong> : la classe de hauteur de l\'arbre (sert au classement et aux variantes).',
    '<strong>Houp.</strong> : la classe de diamètre du houppier.',
    '<strong>Actions dans l\'arbre</strong> : facultatif. Le menu « + action… » ajoute un geste (voir partie 5) ; le temps d\'équipe devient alors la somme des gestes et sa case passe en grisé.',
]); ?>
<p>Les autres colonnes de gauche : <strong>Ord.</strong> (ordre d'affichage dans le bloc), <strong>Code</strong> (ton code interne, utilisé pour rattacher une ligne de bordereau), <strong>Famille</strong> (taper une famille existante ou une nouvelle : la ligne change de bloc à l'enregistrement), <strong>Circ.</strong> (classe de circonférence, pour les abattages au tronc), <strong>Unité</strong>.</p>
<h3>Le tableau : partie droite (temps et prix)</h3>
<?php fig('catalogue-droite', 'Les colonnes de temps et de prix', [
    '<strong>Heures d\'équipe</strong> : le temps de l\'équipe pour une unité de la prestation. C\'est le chiffre principal à régler. Il vaut quelle que soit l\'équipe : seul le taux change selon l\'équipe choisie.',
    '<strong>Rognage</strong> (et les éventuels moyens spécifiques) : heures de la machine en plus de l\'équipe. Laisser vide si la prestation n\'en a pas besoin.',
    '<strong>Forfait €</strong> : montant fixe ajouté avant la marge (fourniture d\'un hauban, d\'un piège, redevance de déchèterie…). Vide en général.',
    '<strong>Réf. €</strong> : le prix que tu as réellement remis sur un marché précédent. Simple mémoire, n\'entre dans aucun calcul.',
    '<strong>Écart</strong> : PU calculé − Réf. En rouge au-delà de 10 % d\'écart : un temps à vérifier, ou une référence chiffrée avec une autre équipe.',
    '<strong>Boutons de ligne</strong> : <kbd>↕</kbd> crée les variantes de taille à partir de cette ligne (10–20 m, > 20 m, houppiers) avec les pourcentages de Paramètres ; <kbd>⛓</kbd> remplace ↕ sur une variante liée (ses temps sont calculés depuis la ligne de base ; un clic la rend indépendante) ; <kbd>⧉</kbd> duplique ; <kbd>×</kbd> supprime.',
    '<strong>PU HT</strong> : le prix unitaire calculé, mis à jour en direct dès que tu tapes un temps, avec l\'équipe choisie dans « Prix avec ».',
    '<strong>Désignation figée</strong> : elle reste à gauche pendant le défilement.',
]); ?>
<h3>Se déplacer dans le tableau</h3>
<p>Le tableau est plus grand que l'écran : il défile dans <strong>sa propre fenêtre</strong>, sans entraîner la page. Quand tu arrives dessus avec la molette, la page se cale d'abord pour que le tableau occupe l'écran.</p>
<?php fig('catalogue-fenetre', 'La fenêtre du tableau', [
    '<strong>En-tête des colonnes</strong> : reste figé en haut quand tu descends dans les lignes.',
    '<strong>Désignation</strong> : reste figée à gauche quand tu vas vers la droite.',
    '<strong>Barre de défilement horizontal</strong> : toujours visible en bas de l\'écran, même avec toutes les familles dépliées. Glisse-la pour aller à droite ou à gauche.',
    '<strong>⇤ et ⇥</strong> : envoient le tableau d\'un coup tout à gauche ou tout à droite.',
]); ?>
<p>Autres façons de se déplacer : <kbd>molette</kbd> pour monter et descendre ; <kbd>⇧ Maj</kbd> + <kbd>molette</kbd> pour aller à droite ou à gauche ; ou <strong>clic maintenu</strong> sur une zone du tableau hors des champs (le curseur devient une main) et glisser dans n'importe quel sens. Après un enregistrement, la page revient exactement où tu étais.</p>
<h3>Enregistrer, ajouter une prestation</h3>
<?php fig('catalogue-ajout', 'Bas de la page Catalogue', [
    '<strong>Enregistrer toutes les lignes</strong> : à cliquer après avoir modifié des temps, des libellés ou des classes. Rien n\'est enregistré tant que tu n\'as pas cliqué (sauf les actions et les boutons de ligne, immédiats).',
    '<strong>Ajouter une ligne</strong> : code, désignation, famille, unité, puis « Ajouter ». La nouvelle ligne s\'affiche seule : renseigne ses heures, enregistre, puis « Tout afficher ».',
]); ?>
<div class="ex"><strong>Créer toute une gamme par taille :</strong> crée « Taille d'entretien – arbre de moins de 10 m, houppier ≤ 5 m » avec 1 h d'équipe, puis clique sur <kbd>↕</kbd> et accepte les houppiers. Les huit autres lignes (10–20 m, > 20 m × 3 houppiers) sont créées et calculées avec les pourcentages de Paramètres. Si tu changes ensuite le temps de la ligne de base, toutes ses variantes suivent.</div>

<h2 id="actions">5. Actions dans l'arbre <small class="muted">(facultatif)</small></h2>
<p>Une action est un geste avec sa durée en heures d'équipe : grimper, démonter une charpentière, broyer, nettoyer… Dans le catalogue, une prestation peut se composer de plusieurs actions ; son temps d'équipe devient la somme. Intérêt : si un geste prend plus de temps qu'estimé, tu corriges l'action et <strong>toutes les prestations qui l'utilisent</strong> sont recalculées. Si tu préfères saisir directement les heures dans le catalogue, tu peux ignorer cette page.</p>
<?php fig('actions', 'La page Actions', [
    '<strong>Coût avec</strong> : l\'équipe utilisée pour afficher le coût indicatif de chaque action. Rien n\'est modifié. Ce choix est retenu pendant ta session (après un enregistrement, et aussi pour « Prix avec » dans le catalogue).',
    '<strong>Durée (h)</strong> : le temps de l\'équipe pour ce geste. Bouton « Enregistrer » en bas après modification.',
    '<strong>Coût</strong> : durée × taux de l\'équipe choisie, avant marge.',
    '<strong>Utilisée</strong> : le nombre de prestations qui utilisent l\'action. Clic pour les voir dans le catalogue.',
    '<strong>Ajouter une action</strong> : nom, durée, précision, puis « Ajouter ».',
]); ?>

<h2 id="packs">6. Packs</h2>
<p>Un pack est une <strong>liste de prestations du catalogue, dans un ordre</strong> : par exemple « EB – Bordereau complet » reprend les 75 lignes du bordereau EB. Un pack ne contient pas de prix : les prix restent ceux du catalogue. Il sert à deux choses : créer un bordereau d'un coup, et régler les temps d'un groupe de lignes sans voir les autres.</p>
<?php fig('packs', 'La page Packs', [
    '<strong>Modifier</strong> : ouvre la composition du pack à droite (nom, description, lignes).',
    '<strong>Régler les temps</strong> : ouvre le catalogue filtré sur les lignes de ce pack.',
    '<strong>→ Bordereau</strong> : ouvre la création d\'un bordereau avec ce pack déjà choisi.',
    '<strong>Composition</strong> : coche les prestations à inclure. Les lignes déjà présentes gardent leur ordre ; celles que tu coches s\'ajoutent à la fin. Le champ « Filtrer les lignes… » au-dessus aide à les trouver.',
    '<strong>N\'afficher que les lignes cochées</strong> : pour relire le contenu du pack.',
    '<strong>Enregistrer le pack</strong> : valide la composition. Supprimer un pack ne supprime aucune ligne du catalogue.',
]); ?>

<h2 id="bordereaux">7. Bordereaux : la liste et la création</h2>
<?php fig('bordereaux-liste', 'La liste des bordereaux', [
    '<strong>Importer un bordereau reçu</strong> : le cas courant (partie 8).',
    '<strong>Nouveau bordereau</strong> : descend au formulaire de création.',
    '<strong>Une ligne par marché</strong> : date, nom (cliquable), client, équipe appliquée, nombre de lignes, statut (Brouillon ou Figé le …) et, si quelqu\'un l\'a ouvert, son nom.',
    '<strong>Ouvrir</strong> : pour le modifier. « Excel » télécharge directement le fichier ; × (administrateurs) le supprime.',
]); ?>
<?php fig('bordereaux-nouveau', 'Créer un bordereau', [
    '<strong>Importer un fichier</strong> : rappel du chemin habituel pour un BPU reçu.',
    '<strong>Équipe appliquée aux prix</strong> : standard ou marché public. Elle s\'applique à toutes les lignes et reste modifiable dans le bordereau.',
    '<strong>Partir d\'un pack</strong> : ses lignes arrivent dans l\'ordre, avec leurs prix calculés. « aucun » crée un bordereau vide.',
    '<strong>Ou copier un bordereau existant</strong> : reprend les lignes, numéros, quantités et prix imposés d\'un marché précédent (pratique pour un renouvellement).',
    '<strong>Créer et ouvrir</strong> : crée le bordereau et l\'ouvre.',
]); ?>

<h2 id="import">8. Importer un bordereau reçu</h2>
<p>La collectivité t'envoie son BPU ou son DQE : l'outil lit le fichier, rapproche chaque ligne d'une prestation de ton catalogue, et crée le bordereau <strong>au format de la collectivité</strong> (ses numéros, ses libellés, ses quantités).</p>
<p>Les colonnes sont reconnues <strong>par leur intitulé</strong> (« N° », « Code », « Désignation », « Unité », « Quantité », « Prix unitaire », « Montant »…), dans n'importe quel ordre, y compris quand le fichier a plusieurs feuilles (BPU puis DQE) ou répète l'en-tête à chaque rubrique. La classe de taille (hauteur, houppier, circonférence) est lue dans le libellé et dans la rubrique au-dessus.</p>
<?php fig('import-depot', 'Étape 1 : déposer le fichier', [
    '<strong>Choisir le fichier</strong> : Excel (.xlsx), Word (.docx), CSV ou PDF contenant du texte. Un PDF scanné (image) ne peut pas être lu : demande le fichier Excel ou Word.',
    '<strong>Lire le fichier</strong> : quelques secondes, puis l\'étape de vérification s\'affiche.',
]); ?>
<?php fig('import-validation', 'Étape 2 : vérifier l\'appariement', [
    '<strong>Équipe appliquée aux prix</strong> : la colonne « Prix catalogue » suit ce choix immédiatement. Renseigne aussi le nom du marché et la collectivité à gauche.',
    '<strong>Pastille de confiance</strong> : <span style="color:#5d8f1c">vert</span> = rapprochement sûr ; <span style="color:#d08a00">orange</span> = à vérifier ; <span style="color:#e6332a">rouge</span> = rien trouvé. Survole la ligne pour voir la raison.',
    '<strong>Prestation proposée</strong> : le menu permet de choisir une autre prestation du catalogue, ou « Sans prestation » si la ligne n\'existe pas chez toi.',
    '<strong>Prix catalogue</strong> : le prix que donnera la prestation choisie.',
    '<strong>Quantité</strong> : reprise du DQE quand le fichier en contient. Modifiable.',
    '<strong>PU imposé</strong> : un prix forcé pour cette ligne (rempli d\'office si le fichier contient déjà des prix). Laisser vide pour utiliser le prix du catalogue.',
    '<strong>Ignorer</strong> : coche les lignes ou les titres qui n\'ont rien à faire dans le bordereau (titre de la collectivité, mentions, prestation hors métier) : ils ne seront pas créés.',
    '<strong>Titres de section</strong> : les rubriques de la collectivité, conservées telles quelles.',
]); ?>
<p>En bas : <strong>Créer le bordereau</strong>. <strong>Chaque correction est mémorisée</strong>, avec sa rubrique : la même formulation sera reconnue d'office au prochain import (pastille verte « Déjà validé précédemment »), et un même libellé sous « moins de 10 m » et sous « 10 à 20 m » garde deux choix distincts. Une ligne que tu as laissée « Sans prestation » ressort en rouge avec la mention « Marquée sans prestation lors d'un import précédent ».</p>

<h2 id="bordereau">9. Travailler dans un bordereau</h2>
<h3>L'en-tête</h3>
<?php fig('bordereau-entete', 'En-tête d\'un bordereau', [
    '<strong>Équipe appliquée aux prix</strong> : change le taux de toutes les lignes d\'un coup (la page se recharge, les prix suivent).',
    '<strong>Enregistrer l\'en-tête</strong> : après avoir modifié le nom du marché, le client ou les notes.',
    '<strong>Excel (CSV)</strong> : télécharge le bordereau dans son ordre, avec N°, désignation, unité, PU HT et TTC, quantités et totaux, prêt à recopier dans le document officiel.',
    '<strong>Assistant ✦</strong> : ouvre l\'assistant, qui connaît ce bordereau.',
    '<strong>Imprimer / PDF</strong> : version imprimable (dans la fenêtre d\'impression, choisir « Enregistrer en PDF »).',
    '<strong>Figer les prix</strong> : gèle tous les prix à la date du jour. À faire quand l\'offre part. « Défiger » pour reprendre.',
    '<strong>Raccourcis Ajouter</strong> : descendent directement aux cadres d\'ajout (pack, lignes du catalogue, section, ligne libre). Rappel du sens des colonnes à droite.',
    '<strong>Tu as la main</strong> : personne d\'autre ne peut modifier ce dossier tant que tu l\'as ouvert (voir partie 12). « Libérer et revenir à la liste » le rend disponible.',
]); ?>
<h3>Les lignes</h3>
<?php fig('bordereau-tableau', 'Le tableau du bordereau', [
    '<strong>N°</strong> : le numéro affiché sur le bordereau (ex. I.5.5, 2.1.1), libre, celui de la collectivité. « Tri » à gauche règle l\'ordre des lignes.',
    '<strong>Titre de section</strong> : une rubrique du bordereau, en majuscules.',
    '<strong>Qté (DQE)</strong> : la quantité estimative ; elle donne le total de l\'offre.',
    '<strong>Prestation du catalogue</strong> : le code de la prestation qui donne le prix. Pour rattacher une ligne « sans prestation » ou en changer, efface et tape un code ou le début d\'un libellé : la liste propose les prestations avec leur prix. Le prix se met à jour tout de suite ; enregistre pour le conserver.',
    '<strong>PU calculé</strong> : temps du catalogue × taux de l\'équipe × coefficient. Clic sur le prix (↗) : la prestation s\'ouvre dans le catalogue, dans un nouvel onglet, pour voir ou régler son temps.',
    '<strong>PU imposé</strong> : un prix forcé pour ce marché seulement (geste commercial, contrainte du marché). 0 = prestation offerte ; un montant négatif = remise. Le catalogue n\'est pas touché. Vider la case pour revenir au prix calculé.',
    '<strong>Total HT</strong> : PU retenu × quantité. Tout à droite, la colonne <strong>PU retenu</strong> donne le prix qui part réellement dans l\'offre : le PU imposé s\'il existe, sinon le PU calculé.',
]); ?>
<?php fig('bordereau-total', 'Bas du tableau', [
    '<strong>Total estimatif</strong> : somme des lignes ayant une quantité, en HT et en TTC.',
    '<strong>Enregistrer le bordereau</strong> : enregistre numéros, libellés, quantités, prestations et PU imposés. Chaque modification est tracée dans l\'historique du dossier.',
    '<strong>Renuméroter 1, 2, 3…</strong> : remet tous les numéros à plat (à éviter sur un bordereau importé, dont les numéros sont ceux de la collectivité).',
]); ?>
<h3>Ajouter des lignes</h3>
<?php fig('bordereau-ajout', 'Les cadres d\'ajout, sous le tableau', [
    '<strong>Ajouter un pack</strong> : toutes ses lignes, à la fin du bordereau, avec ou sans titre de section.',
    '<strong>Ajouter un titre de section</strong> : une rubrique (ex. « 2.0 – Abattage d\'arbres »).',
    '<strong>Ajouter une ligne libre</strong> : une ligne hors catalogue, avec son prix (plus-value, fourniture particulière).',
    '<strong>Filtres</strong> du sélecteur : famille, hauteur, houppier, recherche.',
    '<strong>Liste du catalogue</strong> par famille et hauteur, avec le prix de chaque ligne pour l\'équipe du bordereau. Coche les lignes voulues.',
    '<strong>Ajouter les lignes cochées</strong> : elles arrivent à la fin du bordereau ; règle ensuite leur « Tri » et leur « N° ».',
]); ?>
<div class="ex"><strong>Global ou cas par cas ?</strong> Les ressources et les temps du catalogue valent pour tous les marchés. Pour un cas particulier sur un seul marché (évacuation du bois sur de gros démontages, accès difficile), agis dans le bordereau : « PU imposé » sur la ligne, ou ligne libre de plus-value. Ne modifie le catalogue que si le temps est faux pour tout le monde.</div>
<p><strong>Figé ou brouillon ?</strong> Tant qu'un bordereau est en brouillon, ses prix suivent le catalogue et les taux. Une fois figé, plus rien ne bouge : c'est l'état d'une offre envoyée. Les champs passent en lecture seule ; « Défiger » remet le bordereau en brouillon.</p>

<h2 id="marche">10. Chiffrer un marché pas à pas</h2>
<ol>
<li><strong>Bordereaux → Importer un bordereau reçu</strong> : dépose le BPU ou le DQE de la collectivité.</li>
<li><strong>Vérifie l'appariement</strong> : regarde les pastilles orange et rouges, corrige avec le menu, coche « Ignorer » pour ce qui n'a rien à faire là. Nom du marché, collectivité, <strong>équipe</strong> (standard ou marché public). « Créer le bordereau ».</li>
<li><strong>Relis le bordereau</strong> : la colonne « PU calculé ». Une ligne sans prix ? Rattache-la à une prestation (colonne « Prestation du catalogue ») ou mets un « PU imposé ».</li>
<li><strong>Un prix te semble faux ?</strong> Clic sur le prix ↗ : la prestation s'ouvre dans le catalogue ; corrige son temps et enregistre. Reviens sur l'onglet du bordereau et recharge (F5) : le prix a suivi. Ou demande à l'assistant.</li>
<li><strong>Cas particulier de ce marché</strong> : « PU imposé » sur la ligne, ou ligne libre.</li>
<li><strong>Compare</strong> au marché précédent : colonnes « Réf. » et « Écart » du catalogue, ou l'assistant (« compare ce bordereau aux prix remis l'an dernier »).</li>
<li><strong>Figer les prix</strong> quand l'offre est prête, puis <strong>Excel (CSV)</strong> pour recopier dans le document officiel, ou <strong>Imprimer / PDF</strong>.</li>
</ol>
<div class="ex"><strong>Variante sans fichier :</strong> Bordereaux → Nouveau bordereau → choisis l'équipe et un pack (ex. « EB – Bordereau complet ») → « Créer et ouvrir ». Les lignes arrivent avec leurs prix ; ajuste numéros et quantités, puis fige.</div>

<h2 id="assistant">11. L'assistant</h2>
<p>Bouton <strong>✦ Assistant</strong> en bas à droite de chaque page. Le panneau s'ouvre sur le côté et <strong>reste ouvert</strong> quand tu changes de page ; la conversation continue. Il connaît le catalogue, les taux, les actions, la mémoire d'entreprise, l'écran où tu te trouves et, dans un bordereau, ses lignes.</p>
<?php fig('assistant', 'Le panneau de l\'assistant', [
    '<strong>+ Nouvelle</strong> : commence une nouvelle conversation.',
    '<strong>Historique ↗</strong> : la page complète, avec tes anciennes conversations et la <em>mémoire d\'entreprise</em> (les règles retenues pour toute l\'équipe, que tu peux relire ou retirer).',
    '<strong>Ta question</strong>, en langage courant.',
    '<strong>Une action proposée</strong> : l\'assistant ne modifie jamais rien seul. Il décrit précisément le changement (quelle prestation, quel temps avant → après, pourquoi).',
    '<strong>Appliquer ou Refuser</strong> : rien n\'est modifié tant que tu n\'as pas cliqué sur « Appliquer ». Chaque action appliquée est tracée dans l\'historique, avec la mention « via l\'assistant ».',
    '<strong>Zone de saisie</strong> : <kbd>Entrée</kbd> envoie, <kbd>⇧ Maj</kbd> + <kbd>Entrée</kbd> va à la ligne.',
]); ?>
<p>Exemples de demandes : « combien de temps pour démonter un platane de 22 m avec rétention ? », « vérifie les lignes de ce bordereau qui sont 20 % au-dessus du prix remis l'an dernier », « mets la quantité 12 sur la ligne 2.1.3 », « retiens qu'on compte toujours 0,5 h de balisage en bord de route ». L'assistant peut modifier un temps du catalogue, créer une prestation ou une action, modifier ou ajouter une ligne du bordereau ouvert, et mémoriser une règle.</p>
<p>La croix en haut du panneau le ferme. Le bouton disparaît pendant que le panneau est ouvert ; sur grand écran, la page se décale pour rester entièrement visible.</p>

<h2 id="equipe">12. Travailler à plusieurs</h2>
<p>Les données (taux, catalogue, packs, bordereaux, mémoire de l'assistant) sont <strong>communes à tous les comptes</strong>. Pour éviter que deux personnes modifient le même dossier en même temps, chaque bordereau a un <strong>verrou</strong> : <strong>la première personne qui l'ouvre a la main</strong>.</p>
<?php fig('verrou', 'Un bordereau ouvert par quelqu\'un d\'autre', [
    '<strong>Lecture seule</strong> : le nom de la personne et l\'heure d\'ouverture. Tu peux consulter, pas modifier. Le dossier se libère quand elle quitte la page, ou après 10 minutes sans activité.',
    '<strong>Forcer la libération</strong> (administrateurs) : en cas d\'oubli. Ses modifications non enregistrées seraient perdues.',
]); ?>
<p><strong>Historique</strong> : le menu « Historique » liste les 300 dernières actions de tous les dossiers (qui, quand, quoi : création, modification de quantité, changement d'équipe, taux, catalogue…). Chaque bordereau a aussi son propre historique en bas de page.</p>
<?php fig('historique', 'La page Historique'); ?>
<?php fig('comptes', 'Comptes (administrateurs)', [
    '<strong>Inviter une personne</strong> : prénom, nom, e-mail, rôle. Elle reçoit un lien (valable 48 h) pour choisir elle-même son mot de passe, puis configure sa double authentification. Si l\'e-mail n\'arrive pas, le lien s\'affiche aussi pour que tu le lui transmettes.',
    '<strong>Rôle</strong> : <em>Administrateur</em> a accès à tout ; <em>Secrétaire</em> a tout le calculateur (bordereaux, import, export, catalogue, actions, packs, historique, assistant, guide) mais pas Paramètres ni les comptes ; <em>Utilisateur</em> ne voit que le tableau de bord (liens vers les outils) et son compte, sans aucun prix. Un changement de rôle s\'applique dès la page suivante de la personne.',
    '<strong>Comptes existants</strong> : modifier (clic sur le nom), changer le rôle, réinitialiser la double authentification, renvoyer un lien de mot de passe, désactiver un compte.',
]); ?>
<p><strong>Mon compte</strong> : chacun modifie son prénom, son nom, son e-mail et son mot de passe, et peut changer sa méthode de double authentification (application ou code par e-mail). L'option « Rester connecté sur cet appareil » à la connexion évite de se reconnecter pendant 30 jours.</p>
<?php fig('nouveautes', 'Les nouveautés (cloche)'); ?>

<h2 id="faq">13. Questions fréquentes</h2>
<h3>J'ai changé un taux, les anciens bordereaux ont bougé ?</h3><p>Seuls les bordereaux <em>en brouillon</em>. Les bordereaux figés ne bougent jamais : fige toujours avant de remettre une offre.</p>
<h3>Je veux chiffrer le même marché avec l'autre équipe pour comparer</h3><p>Dans la liste des bordereaux, crée un nouveau bordereau en choisissant « Ou copier un bordereau existant », avec l'autre équipe. Tu as les deux versions côte à côte.</p>
<h3>Une prestation a besoin d'un moyen spécifique sur un seul marché</h3><p>Dans le bordereau : « PU imposé » sur la ligne (heures × taux du moyen, × coefficient), ou ligne libre de plus-value. Si c'est récurrent, crée une prestation dédiée au catalogue (ex. « Abattage > 20 m avec évacuation ») en dupliquant la ligne (<kbd>⧉</kbd>) et en ajoutant les heures du moyen.</p>
<h3>L'import n'a pas reconnu une ligne</h3><p>À l'import, choisis la bonne prestation dans le menu : c'est mémorisé. Après coup, dans le bordereau, tape son code dans la colonne « Prestation du catalogue » et enregistre. Si la prestation n'existe pas, crée-la au catalogue d'abord.</p>
<h3>Le PDF n'est pas lu</h3><p>C'est un scan sans texte : demande le fichier Excel ou Word à la collectivité, ou crée le bordereau à partir d'un pack.</p>
<h3>Je ne retrouve pas une ligne que je viens d'ajouter au catalogue</h3><p>Elle est dans le bloc de sa famille (ou « Sans famille »), qui est peut-être replié. Tape son code dans la recherche, ou clique « Tout déplier ».</p>
<h3>Comment comparer avec ce qu'on a déjà remis ?</h3><p>Colonnes « Réf. » et « Écart » du catalogue, ou l'assistant depuis le bordereau.</p>
<h3>Le prix d'une ligne de bordereau vient de quelle prestation ?</h3><p>Colonne « Prestation du catalogue » : son code (ou « #numéro » si la prestation n'a pas de code). Donne un code unique à chaque prestation : l'enregistrement du catalogue te signale les codes en double.</p>
<h3>J'ai supprimé une prestation du catalogue qui était dans un bordereau</h3><p>La ligne garde son prix (calculé avec les derniers temps de la prestation) et affiche « prestation supprimée du catalogue ». Rattache-la à une autre prestation ou impose son prix.</p>
<h3>Comment sont arrondis les prix ?</h3><p>Le prix brut (heures × taux + forfait, × coefficient) est arrondi au pas choisi dans Paramètres, au plus proche, les demis vers le haut : 85,50 € donne 86 € à l'euro, 87,50 € donne 90 € aux 5 €. Le même calcul est fait partout : catalogue, bordereau, export, figement.</p>
<h3>Je ne peux pas modifier un bordereau</h3><p>Soit il est figé (bouton « Défiger »), soit quelqu'un d'autre l'a ouvert (bandeau rouge en haut, voir partie 12).</p>
<h3>Où changer le modèle d'IA ou la clé de l'assistant ?</h3><p>Dans le fichier <code>config-espace-pro.php</code> à la racine de l'hébergement (hors du site), lignes <code>CLAUDE_API_KEY</code> et <code>CLAUDE_MODELE</code>. Réservé à l'administrateur technique.</p>
<h2 id="planning">14. Le planning des chantiers et rendez-vous</h2>
<p>Le menu <kbd>Planning</kbd> organise les chantiers, les visites de chiffrage et les rendez-vous de toute l'équipe. Tout le monde voit tous les agendas ; l'administration et le secrétariat modifient tout, et chaque compte peut recevoir des droits précis (voir « Qui peut modifier quoi » plus bas).</p>
<?php fig('planning-semaine', 'La vue Semaine', [
 'Les vues : <strong>Semaine</strong> (grille horaire), <strong>Mois</strong>, <strong>Ressources</strong> (une ligne par équipe, personne et machine), <strong>Liste</strong> et <strong>Tournée</strong> (carte).',
 '« Aujourd\'hui » et les flèches ‹ › pour changer de période.',
 'N\'afficher que l\'agenda d\'une personne.',
 '<strong>+ Nouveau</strong> : chantier, visite de chiffrage, rendez-vous, absence ou entretien matériel. Tu peux aussi double-cliquer sur une case vide.',
 'Les cases à cocher masquent ou affichent chaque type. Les jours fériés sont marqués, les journées secteur aussi (étiquette bleue).',
 'La bande « Journée » : les chantiers sur une ou plusieurs journées entières. <strong>Glisse</strong> un événement pour le déplacer ; un point d\'exclamation signale un conflit (personne prise, machine réservée, contrôle dépassé…).',
 'Les événements à l\'heure : visites et rendez-vous. Tire le bas d\'un événement pour changer sa durée.',
 '<strong>À planifier</strong> : les chantiers et visites sans date (devis signés, passages de contrats…). Glisse une fiche sur un jour, ou clique « Trouver un créneau ».',
 '<strong>Alertes</strong> : réponses des clients, conflits à venir, contrôles de matériel, pannes. Un clic ouvre la fiche concernée.',
]); ?>
<h3>La fiche d'un événement</h3>
<?php fig('planning-fiche', 'La fiche (volet de droite)', [
 'Le type : chantier, visite de chiffrage, rendez-vous, absence, entretien matériel.',
 'Le client : tape son nom, le répertoire propose les fiches existantes avec leurs adresses ; sinon la fiche client est créée automatiquement à l\'enregistrement.',
 'L\'adresse : tape-la puis choisis-la dans la liste pour la localiser (itinéraire, tournées, créneaux).',
 'Les dates ; « Journée(s) entière(s) » prend les horaires de l\'équipe réglés dans Personnel, matériel, équipes.',
 '<strong>Trouver un créneau</strong> : pour un chantier, les premières dates où l\'équipe et le matériel sont libres (les jours où vous êtes déjà dans le secteur passent devant) ; pour une visite, les jours qui ajoutent le moins de kilomètres aux tournées déjà prévues.',
 'L\'équipe : la choisir coche d\'un coup ses membres et son matériel habituel.',
 'Les personnes affectées ; celles déjà prises ce jour-là apparaissent grisées. Les conflits s\'affichent en direct en haut de la fiche.',
 '<strong>Enregistrer</strong>.',
 '<strong>Envoyer la date au client</strong> : e-mail (et SMS si activé) avec la date et un lien pour confirmer en un clic ou demander une autre date. Un rappel part automatiquement la veille.',
]); ?>
<?php fig('planning-fiche-bas', 'Le bas de la fiche', [
 'Le matériel affecté : un outil « monté sur » un porte-outil (ex. tête forestière et rogneuse sur le Robo Evo) partage la même machine, réserver l\'un bloque les autres.',
 '<strong>DT-DICT</strong> : coche la case si le chantier touche des réseaux ; le planning t\'alerte si le chantier commence avant la fin du délai de réponse (9 jours) ou si la déclaration a plus de 3 mois.',
 'Le devis : référence, montant HT (il alimente le tableau de charge), devis signé, lien, bordereau lié. Si Pennylane est connecté, « Facturer dans Pennylane » crée la facture en brouillon.',
 'Les consignes pour l\'équipe (visibles dans Mon planning) et les notes internes (jamais visibles par les salariés).',
]); ?>
<div class="ex"><strong>Statuts :</strong> <em>À planifier</em> (pas de date) → <em>Date proposée au client</em> → <em>Confirmé</em> (par le client ou par toi) → <em>Terminé</em> (bon de fin signé ou statut changé) ou <em>Annulé</em>. Si tu déplaces un chantier confirmé, il repasse « à reconfirmer » et le client reçoit la nouvelle proposition quand tu cliques « Envoyer la date au client ».</div>
<?php fig('planning-creneaux', 'Trouver un créneau', [
 'La liste des créneaux proposés, avec les personnes et le matériel disponibles, et ce qui est déjà prévu à proximité.',
 'Un clic pose le créneau dans la fiche : vérifie, puis « Enregistrer » ou « Envoyer la date au client ».',
]); ?>
<h3>La vue Ressources et les équipes</h3>
<?php fig('planning-ressources', 'La vue Ressources', [
 'Les <strong>équipes</strong> : glisse un chantier sur la ligne d\'une équipe pour lui affecter toute l\'équipe et son matériel.',
 'Chaque personne : ses chantiers et absences. Glisser un chantier d\'une personne à une autre change l\'affectation.',
 'Le <strong>matériel</strong>, avec les outils sous leur porte-outil.',
 'Case hachurée : la machine est occupée par un outil frère (même porte-outil).',
 'Week-ends et jours fériés grisés.',
]); ?>
<?php fig('planning-tournee', 'La vue Tournée', [
 'La carte des visites, rendez-vous et chantiers du jour, numérotés dans l\'ordre le plus court depuis le dépôt.',
 'L\'ordre actuel et l\'ordre optimisé, avec les kilomètres gagnés ; « Appliquer » recale les horaires des visites (les chantiers ne bougent pas).',
]); ?>
<h3>Personnel, matériel, équipes</h3>
<?php fig('planning-personnel', 'Le personnel', [
 'Une ligne par personne : poste, compétences (grimpeur, CACES nacelle, poids lourd…), couleur.',
 'La dernière ligne sert à ajouter quelqu\'un.',
 '« Compte de l\'espace pro » relie la personne à son compte (elle voit alors ses chantiers dans Mon planning) ; « Lien agenda du téléphone » s\'ajoute dans Google Agenda ou l\'iPhone pour y voir ses chantiers automatiquement.',
]); ?>
<?php fig('planning-materiel', 'Le matériel (liste rapide)', [
 '« Monté sur » : déclare un outil monté sur un porte-outil ; ils ne pourront pas servir à deux chantiers en même temps.',
 'Le prochain contrôle réglementaire : s\'il est dépassé, le planning le signale comme conflit. Le suivi complet (contrôles, entretiens, pannes) se fait dans le menu <kbd>Matériel</kbd>.',
]); ?>
<?php fig('planning-equipes', 'Les équipes', [
 'Une carte par équipe : nom, chef, membres, matériel habituel, couleur. Une équipe se modifie à tout moment ; les chantiers déjà planifiés gardent leurs personnes.',
 'Coche les membres et le matériel.',
]); ?>
<p>Sur la même page : les <strong>journées secteur</strong> (une zone = une commune centrale et un rayon ; donne-lui des jours, et « Trouver un créneau » proposera ces jours pour les visites de la zone) et les <strong>réglages des tournées</strong> (adresse du dépôt, horaires de journée, vitesse moyenne, seuil d'alerte vent). Les administrateurs ont aussi <kbd>⚙ Réglages</kbd> pour les SMS, les avis Google, les notifications, Pennylane et Urgence Élagage.</p>
<h3>Qui peut modifier quoi</h3>
<table><tr><th>Compte</th><th>Planning</th></tr>
<tr><td>Administrateur, Secrétaire</td><td>Tout : création, déplacement, suppression, envoi aux clients, montants, personnel, matériel, équipes, contrats, tableau de charge, registre platanes.</td></tr>
<tr><td>Utilisateur – « Consultation seule »</td><td>Voit tous les agendas, sans montants ni notes internes. Peut signaler une panne et demander des congés.</td></tr>
<tr><td>Utilisateur – « Modifie son propre agenda »</td><td>Idem, plus ses propres événements (sa fiche du personnel doit être reliée à son compte).</td></tr>
<tr><td>Utilisateur – « Modifie tous les agendas »</td><td>Comme le secrétariat pour le planning (y compris les montants).</td></tr>
<tr><td>+ « Agendas confiés »</td><td>Dans <kbd>Comptes</kbd>, tu peux confier à un compte l'agenda de personnes précises (ex. le chef d'équipe gère celui de son équipe).</td></tr></table>

<h3>Mes calendriers (Mon compte › Agenda et calendriers)</h3>
<p><strong>Dans un sens</strong> : « Copier le lien d'abonnement » (ou « Ouvrir dans l'app Calendrier » sur iPhone) ajoute ton planning Élagage Figeacois dans le calendrier du téléphone, mis à jour tout seul. <strong>Dans l'autre</strong> : colle l'adresse iCal / .ics de tes autres calendriers (Google Agenda : paramètres de l'agenda › « Adresse secrète au format iCal » ; iCloud : partager › « Calendrier public » ; Outlook : publier le calendrier) : leurs événements apparaissent hachurés en gris dans le planning et dans Mon planning, en lecture seule, et comptent comme « occupé » quand on planifie (option « masquer les titres » pour n'afficher que « Occupé »). Les calendriers sont relus toutes les heures. L'administration voit et gère ceux de tout le monde dans <kbd>Personnel, matériel, équipes › Agenda et calendriers</kbd>.</p>
<h3>Types de rendez-vous, temps de route, vue Carte</h3>
<p>Un rendez-vous ou une visite a un <strong>type</strong> (visite de chiffrage, rendez-vous prospect, réunion de chantier, visite de fin de travaux, réception…) : il s'affiche sur le créneau et dans le rapport. La liste se modifie dans <kbd>Personnel, matériel, équipes › Réglages</kbd>. En vue Semaine, le <strong>temps de route</strong> estimé entre deux rendez-vous d'une même personne apparaît entre les créneaux, en rouge quand l'écart est trop court (et une alerte le signale dans la fiche). La vue <strong>Carte</strong> place tous les chantiers et rendez-vous de la période, numérotés par date, avec le choix du fond (OpenStreetMap, Plan IGN, photo aérienne IGN, satellite) et, dans chaque bulle, un lien Street View et l'itinéraire.</p>
<h3>Pictogrammes et actions à faire avant le chantier</h3>
<p>Dans la fiche, coche les <strong>pictogrammes</strong> qui résument les points d'attention (plantation, arrêté de circulation, location de matériel, grue ou nacelle, benne, réseaux, toiture, accès, nidification, souche, autorisation mairie, platane). Ils s'affichent sur le créneau pour un coup d'œil rapide. Ceux qui ont une action associée (ex. « Demander l'arrêté de circulation ») la créent automatiquement dans <strong>Actions à faire avant le chantier</strong>, avec une échéance calculée (ex. J-15) ; tu peux aussi ajouter une action à la main. Chaque action est <strong>attribuée à une personne</strong> (✎), qui reçoit une notification sur son téléphone, puis un rappel la veille de l'échéance et à l'approche du chantier. Tant qu'il reste des actions, le créneau porte un badge ☐ ; l'encadré « Mes actions à faire » (planning et Mon planning) permet de les cocher. La liste des pictogrammes et de leurs actions se règle dans <kbd>Personnel, matériel, équipes › Réglages</kbd>.</p>
<h3>Relevé de chantier : les prestations du devis</h3>
<p>La fiche d'un chantier liste les <strong>prestations du devis</strong> à réaliser : reprises automatiquement d'un bordereau lié, collées depuis un devis Extrabat ou PDF (une ligne par prestation, les prix et totaux sont ignorés), ou saisies. Sur place, l'équipe les coche dans Mon planning ; les prestations cochées pré-remplissent les « Travaux réalisés » du bon de fin d'intervention et figurent dans le PDF.</p>

<h2 id="terrain">15. Mon planning : l'appli de terrain</h2>
<p>Sur le téléphone, <kbd>Mon planning</kbd> est l'écran du jour de chaque salarié. Ajoute l'espace pro à l'écran d'accueil (Safari › Partager › « Sur l'écran d'accueil ») : il s'ouvre comme une appli et peut recevoir les notifications.</p>
<?php fig('terrain-jour', 'Les rendez-vous du jour', [
 'Le jour affiché ; ‹ › pour changer, « Aujourd\'hui » pour revenir.',
 '🔔 Activer les notifications : nouveaux chantiers, changements de date, réponses des clients arrivent sur le téléphone. « Lien agenda » ajoute tes chantiers à l\'agenda du téléphone.',
 'Chaque rendez-vous : horaire (cadran), client, commune, badge Prochain / À venir / En cours / Terminé.',
 'Kilomètres et minutes depuis ta position (ou depuis le dépôt pour le premier), et l\'état du rapport (📝 photos, ✓ signé).',
], 'tel'); ?>
<?php fig('terrain-fiche', 'La fiche terrain', [
 'Les boutons rapides : itinéraire Google Maps, Waze, appeler, SMS, e-mail, photos / rapport.',
 'Le rapport de visite ou le bon de fin d\'intervention, le passeport phytosanitaire pour un chantier platane, et « Ouvrir dans le planning ».',
], 'tel'); ?>
<?php fig('terrain-rapport', 'Le rapport de visite', [
 'Le modèle : « Rapport de visite (devis) » pour une visite, « Bon de fin d\'intervention » pour un chantier.',
 'Comment s\'est passé le rendez-vous (pour toi seulement, n\'apparaît pas sur le PDF).',
 'Le compte rendu, enregistré automatiquement.',
 'Les photos : prises ou choisies dans la galerie, puis touche une photo pour la légender, l\'<strong>annoter au doigt</strong> (cercles, flèches), la retirer du PDF ou la supprimer.',
 '<strong>Rapport PDF</strong> : voir le PDF, l\'envoyer sur le porte-document du client, par e-mail (adresse préremplie) ou le partager (WhatsApp, Mail, Fichiers…).',
], 'tel'); ?>
<?php fig('terrain-bon', 'Le bon de fin d\'intervention', [
 'Les travaux réalisés, le devenir des déchets verts et les remarques ou réserves du client.',
 'Le client signe sur l\'écran : la date, l\'heure et une empreinte du bon sont enregistrées, le bon ne peut plus être modifié, le chantier passe « Terminé » (et la demande d\'avis Google part si elle est activée).',
], 'tel'); ?>
<div class="ex"><strong>Panne ou anomalie sur une machine :</strong> depuis <kbd>Matériel</kbd> › « Signaler une panne » (photo, compteur, machine immobilisée ou non). Le responsable est prévenu aussitôt et la machine est retirée du planning si elle est immobilisée.</div>

<h2 id="clients">16. Clients, contrats et tableau de charge</h2>
<?php fig('clients-fiche', 'La fiche client', [
 'Les boutons rapides : itinéraire, appel, SMS, e-mail, nouveau rendez-vous pour ce client.',
 'Les onglets : Contact (téléphones, e-mail, plusieurs adresses), Rendez-vous (historique et à venir), Devis et contrats (administration et secrétariat), Porte-doc, Autres (autorisations SMS et e-mail : si le client refuse, aucun SMS ni rappel automatique ne lui est envoyé).',
 'Le <strong>porte-document</strong> : rapports de rendez-vous, bons d\'intervention, contrats signés et photos y sont rangés automatiquement.',
 'Tu peux y ajouter tes propres documents (PDF, photos, Word, Excel), dans le dossier de ton choix.',
]); ?>
<p>Les fiches clients se créent toutes seules à partir des rendez-vous (nom + téléphone ou e-mail) ; tu peux aussi en créer à la main depuis <kbd>Clients</kbd>.</p>
<h3>Contrats d'entretien</h3>
<?php fig('contrats-fiche', 'Un contrat d\'entretien', [
 'Les prestations (une par ligne), le montant annuel HT, la TVA et le modèle de contrat (le texte se remplit tout seul avec ces informations).',
 'Les mois des passages : à chaque mois coché, un chantier « à planifier » est créé automatiquement environ deux mois avant, avec le client, l\'adresse, l\'équipe et le montant au prorata.',
 '« Enregistrer », puis « Envoyer au client pour signature » : il reçoit le PDF et un lien pour relire et <strong>signer en ligne</strong> (sur téléphone ou ordinateur). Le contrat signé revient dans son porte-document et tu es prévenu.',
]); ?>
<p>Les modèles de contrat se modifient dans <kbd>Contrats</kbd> › « Modèles de contrat », avec des champs automatiques (<code>{{client_nom}}</code>, <code>{{montant_ht}}</code>, <code>{{periodes}}</code>…). Un contrat signé ne se modifie plus : fais-en un nouveau. Deux mois avant l'échéance d'un contrat à reconduction tacite, le client reçoit un avis d'échéance (loi Chatel). Si Pennylane est connecté, la demande de mandat de prélèvement SEPA (GoCardless) peut partir automatiquement à la signature.</p>
<?php fig('charge', 'Le tableau de charge', [
 'Les chiffres clés : à planifier, chiffre d\'affaires planifié, validé par les clients, en attente du client, et (si Pennylane est connecté) facturé et encaissé.',
 'Par semaine ou par mois : nombre de chantiers et montant HT par statut (planifié, validation client en cours, le client demande une autre date, validé, terminé).',
 'L\'occupation des équipes : jours-personne affectés par rapport aux jours disponibles (personnel actif, hors congés, week-ends et fériés). Rouge au-delà de 100 %.',
]); ?>
<?php fig('conges', 'Les congés', [
 'Le salarié fait sa demande (dates, type, demi-journée éventuelle) depuis son téléphone ; le responsable est notifié, accepte ou refuse, et l\'absence est posée au planning : personne ne pourra lui affecter un chantier ces jours-là. L\'administration peut poser une absence directement.',
]); ?>

<h3>Livret du salarié</h3>
<p><kbd>Chantiers & agenda › Livrets du personnel</kbd> (chaque salarié voit le sien via « Mon livret »). Pour chaque personne : <strong>titres et habilitations</strong> (SST, CACES, permis, autorisation de conduite employeur, AIPR, habilitation électrique, travail en hauteur…) avec date d'obtention, date de validité calculée d'après le type et <strong>scan ou photo du diplôme</strong> ; <strong>plan de formation</strong> (une formation « réalisée » crée le titre correspondant) ; <strong>documents</strong> par dossier ; <strong>fiche</strong> (poste, entrée, contrat). Un titre valide donne la compétence au planning, un titre expiré la retire : les échéances apparaissent sur le tableau de bord, dans les alertes du planning et en notification au salarié et à la gestion. La liste des types de titres (validité, compétence liée) se règle en bas de la vue d'ensemble. Les EPI restent dans Vérif EPI.</p>

<h2 id="materiel">17. Suivi du matériel</h2>
<?php fig('materiel-liste', 'Le tableau de bord du matériel', [
 '<strong>À faire</strong> : contrôles et entretiens en retard (rouge) ou à prévoir (orange), pannes en cours.',
 'Le parc : une carte par machine, avec son compteur et son état (à jour, en retard, immobilisé).',
 '« Signaler une panne » (accessible à tout le monde) et « Nouvelle machine ou outil ».',
]); ?>
<?php fig('materiel-controles', 'Les contrôles périodiques d\'une machine', [
 '« Contrôle réalisé » : date, résultat (conforme, réserves, non conforme), organisme, coût, rapport joint. L\'échéance suivante est calculée automatiquement ; un contrôle non conforme immobilise la machine.',
 'Les réglages du contrôle : périodicité, rappel, prestataire, obligatoire ou non (un contrôle obligatoire dépassé bloque la machine au planning).',
]); ?>
<p>Chaque fiche a aussi ses <strong>entretiens</strong> (par échéance de temps et/ou de compteur : vidange tous les 6 mois ou toutes les 250 h…), son <strong>carnet</strong> (contrôles, entretiens, réparations, pannes, notes, coûts, pièces jointes), ses <strong>documents</strong> (carte grise, notice, rapports…) et le coût des 12 derniers mois. À la création d'une machine, les contrôles et entretiens habituels de son type sont proposés d'office. « Poser un entretien au planning » réserve la machine ce jour-là. Les EPI (harnais, cordes, casques) restent suivis dans Vérif EPI.</p>

<h2 id="platanes">18. Chantiers platanes : chancre coloré et passeport phytosanitaire</h2>
<p>L'arrêté du 31 janvier 2025 impose, sur tout le territoire, des précautions pour toute intervention sur platane, et un <strong>passeport phytosanitaire</strong> pour transporter le bois ou les rémanents. L'espace pro tient le registre demandé par la DRAAF et fournit le passeport sur le téléphone.</p>
<?php fig('platane-fiche', 'Le bloc « Platane » de la fiche chantier', [
 'Coche <strong>Chantier sur platane</strong> : le bloc s\'ouvre et le chantier entre dans le registre.',
 'Le <strong>code C</strong> de traçabilité : le numéro du devis, repris automatiquement (modifiable). Le code B (notre numéro d\'enregistrement FR-0460072V) est un réglage.',
 'La check-list réglementaire : bois transporté (passeport), outils et engins désinfectés à l\'entrée et à la sortie du site, entre chaque arbre (zone délimitée), griffes utilisées (démontage seulement).',
 'Le devenir du bois : destination, usage, volume. Surveillance visuelle avant et pendant le chantier : en cas de symptômes suspects, on stoppe le chantier et on signale à la DRAAF.',
]); ?>
<?php fig('platane-passeport', 'Le passeport sur le téléphone', [
 'Depuis Mon planning, bouton « Passeport phytosanitaire » : A Platanus sp., B notre enregistrement, C le code du chantier, D FR, avec son numéro (PP-année-n°) et le devis. Reste lisible sans réseau une fois ouvert ; « PDF à imprimer » donne deux exemplaires par page pour le camion.',
], 'tel'); ?>
<?php fig('platane-registre', 'Le registre des chantiers platanes', [
 'La période (par défaut l\'année) et le filtre par propriétaire.',
 'Les colonnes de l\'export : les 12 du tableau DRAAF sont cochées d\'office, tu peux en ajouter (désinfection, n° de passeport, intervenants…) et mémoriser ton choix. Export en Excel, CSV ou PDF.',
 'Le registre : propriétaire, dates, travaux, localisation, surveillance, code C et devis, n° de passeport et date de verrouillage, destination, usage, volume, désinfection. Une ligne rouge = commune en zone délimitée.',
]); ?>
<div class="att"><strong>Verrouillage :</strong> dès que le chantier est terminé (bon signé ou statut « Terminé »), la fiche platane et le passeport sont figés avec leur numéro et leurs codes ; plus rien ne se modifie. Les <strong>zones délimitées</strong> (communes listées en bas de la page Platanes ; le Lot, l'Aveyron, le Cantal et la Corrèze ne sont pas concernés) déclenchent des alertes : déclaration préalable à la DRAAF 15 jours ouvrés avant, désinfection entre chaque arbre, bois non transportable sans dérogation.</div>
<p>L'assistant ✦ connaît toute la procédure (arrêtés, symptômes, mesures, passeport) ainsi que la doctrine arboricole de l'entreprise : pose-lui tes questions, en écrivant ou en parlant (bouton 🎤).</p>

<h3>Remplir le registre à la main</h3>
<p>Le registre se remplit normalement depuis la fiche du chantier (client, dates, adresse, n° de devis). Pour des travaux <strong>hors devis</strong> (platanes ajoutés sur place, évacuation imprévue) : <kbd>Platanes › Ajouter un chantier platane à la main</kbd> crée la ligne (et le chantier correspondant dans le planning) avec son passeport. Les colonnes propriétaire, coordonnées et localisation peuvent être saisies à la main quand elles diffèrent du client. Une ligne verrouillée se corrige avec « Déverrouiller pour corriger » (le numéro de passeport est conservé) ; elle se reverrouille à l'enregistrement si le chantier est terminé.</p>

<h2 id="reglages-planning">19. Réglages : SMS, avis, notifications, Pennylane, Urgence Élagage</h2>
<?php fig('reglages-planning', 'Les SMS (réglages administrateur)', [
 'Chaque envoi vers l\'extérieur se règle séparément et reste coupé tant que tu ne l\'actives pas : SMS aux clients (OVH, par motif : proposition de date, rappel, avis, contrat), avis Google après chantier, notifications, Pennylane (factures en brouillon, mandats GoCardless), Urgence Élagage (interventions et astreinte au planning).',
 'L\'interrupteur d\'activation, et un bouton de test pour chaque service.',
]); ?>
<table><tr><th>Service</th><th>Ce qu'il faut</th></tr>
<tr><td>SMS</td><td>Un compte SMS OVH (crédits) et ses clés d'API (application key, secret, consumer key, nom du compte sms-xxxx), un expéditeur validé chez OVH. Les SMS ne partent que vers des mobiles et si le client les accepte.</td></tr>
<tr><td>Avis Google</td><td>Le lien d'avis de la fiche Google (« Demander des avis »), le délai après la fin du chantier, le canal. Un seul envoi par chantier.</td></tr>
<tr><td>Notifications</td><td>Rien côté serveur ; chaque salarié active le 🔔 sur son téléphone (iPhone : espace pro ajouté à l'écran d'accueil).</td></tr>
<tr><td>Pennylane / GoCardless</td><td>Un jeton d'API Pennylane (Paramètres › Connectivité › Développeurs ; droits clients, factures clients, mandats) et GoCardless connecté dans Pennylane. Les factures sont créées en brouillon : tu les relis et les valides dans Pennylane.</td></tr>
<tr><td>Urgence Élagage</td><td>Une clé d'API « lecture » créée dans Urgence Élagage › Administration › Entreprise › Intégrations. Dans l'autre sens, colle le lien agenda de chaque salarié dans son compte Urgence Élagage pour que ses chantiers comptent dans sa disponibilité d'astreinte.</td></tr></table>
<p>Les rappels automatiques (veille), les passages de contrats, les avis et les synchronisations se font à l'ouverture des pages, au plus une fois par heure. Pour qu'ils tournent même sans ouverture, crée dans OVH une tâche planifiée (cron) qui appelle l'adresse indiquée dans Personnel, matériel, équipes › Réglages.</p>
</div>
<a class="retour" href="#sommaire">☰ Sommaire</a>
</div>
<script>
(function(){
  var liens=[].slice.call(document.querySelectorAll('.som a[href^="#"]'));
  var cibles=liens.map(function(a){return document.getElementById(a.getAttribute('href').slice(1));}).filter(Boolean);
  function maj(){
    var y=window.scrollY+(parseInt(getComputedStyle(document.documentElement).getPropertyValue('--bandeau'))||70)+40, cur=null;
    cibles.forEach(function(h){ if(h.offsetTop<=y) cur=h; });
    liens.forEach(function(a){ a.classList.toggle('actif', cur && a.getAttribute('href')==='#'+cur.id); });
  }
  window.addEventListener('scroll',maj,{passive:true}); maj();
})();
</script>
<?php page_end(); ?>
