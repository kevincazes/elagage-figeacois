<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
page_start('Guide d\'utilisation', 'guide.php');
?>
<style>
.guide{max-width:900px}.guide h2{margin-top:2.2em;padding-top:1em;border-top:2px solid var(--clair);font-size:1.45rem}.guide h3{margin-top:1.4em;font-size:1.1rem}
.guide p,.guide li{line-height:1.6}.guide .ex{background:var(--clair);border-left:4px solid var(--anis);padding:12px 16px;border-radius:8px;margin:12px 0}
.guide .ex strong{color:var(--sapin)}.guide .som{columns:2;gap:30px}.guide .som a{text-decoration:none}.guide kbd{background:#fff;border:1px solid #cfd6c8;border-radius:5px;padding:1px 7px;font-size:.88rem;font-weight:700;color:var(--sapin)}
.guide table{width:100%;border-collapse:collapse;margin:10px 0}.guide td,.guide th{padding:7px 9px;border-bottom:1px solid #e6eadf;text-align:left;vertical-align:top}.guide th{background:#f8f9f4;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em}
@media(max-width:700px){.guide .som{columns:1}}
</style>
<div class="guide">
<p class="lead">Ce guide explique comment fonctionne l'espace pro, pourquoi il est organisé ainsi, et comment chiffrer un marché de A à Z. Lis d'abord la partie 1, elle donne la logique ; le reste se consulte au besoin.</p>

<div class="card"><strong>Sommaire</strong>
<ol class="som">
<li><a href="#logique">La logique du chiffrage</a></li>
<li><a href="#parametres">Paramètres</a></li>
<li><a href="#actions">Actions dans l'arbre</a></li>
<li><a href="#catalogue">Catalogue des prestations</a></li>
<li><a href="#packs">Packs</a></li>
<li><a href="#bordereaux">Bordereaux : composer ou importer</a></li>
<li><a href="#marche">Chiffrer un marché pas à pas</a></li>
<li><a href="#assistant">L'assistant</a></li>
<li><a href="#equipe">Travailler à plusieurs : verrou, historique, comptes</a></li>
<li><a href="#faq">Questions fréquentes</a></li>
</ol></div>

<h2 id="logique">1. La logique du chiffrage</h2>
<p>Tout repose sur une idée simple : <strong>un prix, c'est du temps multiplié par un taux horaire</strong>, avec une marge par-dessus.</p>
<div class="ex"><strong>Formule :</strong> PU HT = (Σ heures × taux horaire de chaque ressource + forfait éventuel) × coefficient, arrondi.<br>
<strong>Exemple :</strong> 2 h d'équipe à 190 €/h = 380 € ; × 1,25 = 475 € ; arrondi à l'euro = <strong>475 € HT</strong>.</div>
<p>Les briques, de la plus générale à la plus fine :</p>
<table>
<tr><th>Brique</th><th>C'est quoi</th><th>Où</th></tr>
<tr><td><strong>Ressources</strong></td><td>Ce qui coûte à l'heure : les équipes (tout compris : personnel, nacelle, broyeur, matériel), le rognage, et les moyens spécifiques (évacuation du bois, grue…).</td><td>Paramètres</td></tr>
<tr><td><strong>Actions</strong></td><td>Les gestes élémentaires dans l'arbre, avec leur durée en heures d'équipe (démonter une charpentière : 1,5 h ; broyer : 0,75 h…).</td><td>Actions</td></tr>
<tr><td><strong>Prestations</strong></td><td>Les lignes que l'on vend (abattage < 10 m, taille d'entretien 10–20 m…). Chacune porte ses heures par ressource, saisies directement ou composées d'actions.</td><td>Catalogue</td></tr>
<tr><td><strong>Packs</strong></td><td>Des groupes de prestations à ajouter d'un coup dans un bordereau (les 9 lignes d'abattage 3 × 3, le bordereau complet d'une collectivité…).</td><td>Packs</td></tr>
<tr><td><strong>Bordereaux</strong></td><td>Le document d'un marché : les lignes au format de la collectivité, l'équipe appliquée, les quantités, les prix. Figé quand l'offre part.</td><td>Bordereaux</td></tr>
</table>
<p><strong>Le point clé :</strong> le catalogue stocke des <em>heures</em>, pas des prix. Les prix se déduisent des taux. Changer un taux change tous les prix ; changer un temps ne change qu'une prestation.</p>

<h2 id="parametres">2. Paramètres</h2>
<h3>Taux horaires des ressources</h3>
<ul>
<li><strong>Équipe</strong> (type « Équipe ») : le prix horaire de l'équipe complète. Il peut y en avoir plusieurs à des taux différents : par exemple l'équipe standard au coût complet, et une équipe « marché public » à taux réduit. <strong>La première de la liste porte les heures du catalogue</strong> ; les autres servent de taux alternatifs, choisis par bordereau.</li>
<li><strong>Rognage</strong> (type « Rognage ») : opérateur + rogneuse, compté à part sur les lignes de dessouchage.</li>
<li><strong>Moyens spécifiques</strong> (type « Moyen spécifique ») : évacuation du bois, grue, signalisation… Chacun devient une colonne du catalogue, remplie seulement sur les lignes qui en ont besoin. Un moyen spécifique n'est jamais appliqué à tout un marché.</li>
</ul>
<div class="ex"><strong>Quand y revenir ?</strong> Seulement si un coût change (nouvelle machine, revalorisation annuelle). Pas à chaque marché.</div>
<h3>Règles de calcul</h3>
<ul>
<li><strong>Coefficient de marge et d'aléas</strong> : multiplie le coût. 1,25 = +25 % pour la marge, les frais généraux, la météo.</li>
<li><strong>TVA</strong> : pour l'affichage TTC et les exports.</li>
<li><strong>Arrondi</strong> : 1 = à l'euro, 5 = aux 5 €, 0 = au centime.</li>
</ul>
<h3>Progression du temps selon la taille de l'arbre</h3>
<p>Quatre pourcentages qui disent combien de temps en plus on passe quand l'arbre grandit : 10–20 m (+80 % par défaut), > 20 m (+200 %), houppier 5–10 m (+50 %), houppier > 10 m (+120 %). Ils servent à <strong>générer les variantes</strong> d'une prestation de base (voir Catalogue, bouton ↕) et à les recalculer quand on change la base.</p>
<div class="ex"><strong>Exemple :</strong> base « abattage < 10 m, houppier ≤ 5 m » = 2 h. La variante « 10–20 m, houppier 5–10 m » = 2 × 1,8 × 1,5 = 5,4 h.</div>
<h3>Liens du tableau de bord</h3>
<p>Les adresses ouvertes par les tuiles Google Drive, Extrabat, Urgence Élagage, Arbogestion. Sans rapport avec le chiffrage.</p>

<h2 id="actions">3. Actions dans l'arbre</h2>
<p>Une action = un geste et sa durée en heures d'équipe. Les actions sont <strong>indépendantes</strong> : une prestation en combine une, deux ou trois selon le cas, et son temps d'équipe est la somme.</p>
<ul>
<li>« Charger des actions d'exemple » donne une douzaine de gestes types à adapter.</li>
<li>Modifier la durée d'une action recalcule automatiquement toutes les prestations qui l'utilisent.</li>
<li>Dans le catalogue, la colonne « Actions dans l'arbre » montre, pour chaque ligne, les actions qui la composent (pastilles) et un menu « + action… » pour en ajouter. Quand une ligne est composée d'actions, son temps d'équipe devient calculé (champ verrouillé).</li>
</ul>
<div class="ex"><strong>Exemple :</strong> « Démontage 10–20 m avec rétention » = Grimpe et installation (0,5 h) + Démontage d'une charpentière × 3 (4,5 h) + Démontage du tronc (1 h) + Broyage (0,75 h) = 6,75 h d'équipe.</div>

<h2 id="catalogue">4. Catalogue des prestations</h2>
<h3>Lecture</h3>
<ul>
<li>Un <strong>bloc par famille</strong> (Abattage, Dessouchage, Taille d'entretien…), replié par défaut, avec le nombre de lignes et la fourchette de prix. Dans un bloc, les lignes sont rangées par hauteur d'arbre puis par houppier.</li>
<li>Filtres par famille, hauteur, recherche ; « Tout déplier / Tout replier ».</li>
<li>Menu « Prix avec : … » pour voir les prix avec une autre équipe.</li>
</ul>
<h3>Colonnes d'une ligne</h3>
<table>
<tr><th>Colonne</th><th>Rôle</th></tr>
<tr><td>Ord. / Code</td><td>Ordre d'affichage et code interne (ABT-H2-D1…). Le code est libre.</td></tr>
<tr><td>Désignation</td><td>Le libellé. S'agrandit pour tout afficher.</td></tr>
<tr><td>Famille, Haut., Houp., Circ.</td><td>Classement : famille, classe de hauteur, de houppier, de circonférence. Servent aux filtres, aux variantes et à l'import automatique.</td></tr>
<tr><td>Unité</td><td>U, h, ml, m³, Forfait…</td></tr>
<tr><td>Actions dans l'arbre</td><td>Composition par actions (voir partie 3).</td></tr>
<tr><td>Colonnes de ressources</td><td>Heures de chaque ressource. L'équipe porte le temps principal ; rognage et moyens spécifiques seulement là où c'est nécessaire.</td></tr>
<tr><td>Forfait €</td><td>Montant fixe ajouté avant le coefficient (fourniture, déplacement).</td></tr>
<tr><td>Réf. € / Écart</td><td>Le prix déjà remis sur un bordereau précédent, et l'écart avec le prix calculé. Rouge si l'écart dépasse 10 %.</td></tr>
<tr><td>PU HT</td><td>Le prix calculé, en direct.</td></tr>
</table>
<h3>Boutons de ligne</h3>
<ul>
<li><kbd>↕</kbd> génère les <strong>variantes de taille</strong> à partir d'une ligne de base (hauteur seule, ou hauteur × houppier = 9 lignes), avec les pourcentages de Paramètres. Les variantes restent liées à leur base.</li>
<li><kbd>⛓</kbd> signale une variante liée (temps calculés). Un clic la détache pour l'ajuster à la main.</li>
<li><kbd>⧉</kbd> duplique la ligne avec ses temps. <kbd>×</kbd> la supprime.</li>
</ul>
<p>Toujours cliquer <strong>« Enregistrer toutes les lignes »</strong> après des modifications de temps, libellés ou classes.</p>

<h2 id="packs">5. Packs</h2>
<p>Un pack est une sélection de lignes du catalogue, à ajouter en un clic dans un bordereau, dans l'ordre. Dix packs sont fournis (matrices 3 × 3 du bordereau EB, Lot Habitat complet, Département complet). Pour en créer un : nom, puis cocher les lignes. Supprimer un pack ne supprime pas les lignes du catalogue.</p>

<h2 id="bordereaux">6. Bordereaux : composer ou importer</h2>
<h3>Deux façons de démarrer</h3>
<ul>
<li><strong>Importer un bordereau reçu</strong> (recommandé) : tu déposes le fichier de la collectivité (Excel, Word, CSV, PDF texte). L'outil lit les lignes, propose pour chacune la prestation du catalogue, avec une pastille de confiance (vert : sûr ; orange : à vérifier ; rouge : rien trouvé). Tu corriges avec le menu, tu coches « Ignorer » pour les lignes hors sujet, puis « Créer le bordereau ». Les quantités du DQE sont récupérées si elles sont dans le fichier. <strong>Chaque correction est mémorisée</strong> : la même formulation sera reconnue d'office la prochaine fois.</li>
<li><strong>Nouveau bordereau vide</strong> : puis « Ajouter un pack », « Ajouter des lignes du catalogue » (avec filtres), « Ajouter un titre de section », « Ajouter une ligne libre ». On peut aussi partir d'un bordereau existant (copie des lignes).</li>
</ul>
<h3>Dans un bordereau</h3>
<table>
<tr><th>Élément</th><th>Rôle</th></tr>
<tr><td>Équipe appliquée aux prix</td><td>L'équipe dont le taux s'applique à toutes les lignes (standard ou marché public). Changeable tant que le bordereau n'est pas figé.</td></tr>
<tr><td>Tri / N°</td><td>« Tri » ordonne les lignes ; « N° » est le numéro affiché, libre (2.1.1, ARB0004…), celui de la collectivité.</td></tr>
<tr><td>Code, désignation, unité</td><td>À aligner sur le document reçu ; ils n'ont pas besoin d'être ceux du catalogue.</td></tr>
<tr><td>Qté (DQE)</td><td>La quantité estimative : donne le total de l'offre.</td></tr>
<tr><td>PU calculé</td><td>Le prix issu du catalogue avec l'équipe choisie.</td></tr>
<tr><td>PU imposé</td><td>Un prix forcé pour ce bordereau seulement (geste commercial, contrainte du marché). Le catalogue n'est pas touché.</td></tr>
<tr><td>Figer les prix</td><td>Gèle tous les prix à la date du jour : plus rien ne bouge ensuite, même si les taux changent. À faire quand l'offre part. « Défiger » pour reprendre.</td></tr>
<tr><td>Excel (CSV) / Imprimer</td><td>Export dans l'ordre du bordereau, prêt à recopier dans le document officiel.</td></tr>
</table>
<div class="ex"><strong>Global ou cas par cas ?</strong> Les ressources et les temps du catalogue sont le global, valable pour tous les marchés. Pour un cas particulier sur un marché précis, on agit dans le bordereau : « PU imposé » sur la ligne, ou ligne libre de plus-value. On ne modifie pas le catalogue pour un seul marché.</div>

<h2 id="marche">7. Chiffrer un marché pas à pas</h2>
<ol>
<li><strong>Bordereaux → Importer un fichier</strong> : dépose le BPU ou le DQE de la collectivité.</li>
<li><strong>Valide l'appariement</strong> : vérifie les lignes orange et rouges, corrige, ignore ce qui n'a rien à faire là. Nom du marché, collectivité, équipe. « Créer le bordereau ».</li>
<li><strong>Relis le bordereau</strong> : total estimatif en bas, lignes sans prestation à compléter (menu ou PU imposé), plus-values en lignes libres.</li>
<li><strong>Compare</strong> : ouvre l'assistant (bouton ✦) et demande-lui de comparer avec les prix déjà remis, ou de signaler ce qui paraît sous-évalué.</li>
<li><strong>Fige les prix</strong>, puis <strong>Excel</strong> : recopie dans le document officiel de la collectivité.</li>
</ol>
<p>Si le marché ressemble à un bordereau déjà fait, « Nouveau bordereau » en partant de l'existant est encore plus rapide.</p>

<h2 id="assistant">8. L'assistant</h2>
<p>Il connaît les taux, le catalogue, les actions, la mémoire d'entreprise, et le bordereau ouvert si tu l'ouvres depuis un bordereau (bouton ✦). Il répond aux questions de chiffrage et de métier, et peut <strong>agir</strong> : modifier un temps, créer une prestation ou une action, ajuster une ligne du bordereau, mémoriser une règle. <strong>Chaque action est proposée dans une bulle jaune et n'est appliquée qu'après ton clic « Appliquer »</strong> ; elle est tracée dans l'historique « (via l'assistant) ».</p>
<ul>
<li><strong>Mémoire d'entreprise</strong> (volet à gauche) : les règles durables (« on compte toujours 0,5 h d'installation », « plus-value accès difficile sur les talus »). Tout le monde les voit et peut les retirer.</li>
<li><strong>Conversations</strong> : conservées par personne, reprise en un clic.</li>
<li>Bonnes questions : « Combien de temps pour… ? », « Compare nos abattages avec le BPU EB », « Quelles lignes du bordereau sont sans prix ? », « Propose une décomposition en actions pour… ».</li>
</ul>

<h2 id="equipe">9. Travailler à plusieurs</h2>
<ul>
<li><strong>Données communes</strong> : taux, catalogue, packs, bordereaux sont partagés par tous les comptes. Seuls le mot de passe, la double authentification et la fiche sont personnels.</li>
<li><strong>Verrou</strong> : celui qui ouvre un bordereau en premier a la main ; les autres le voient en lecture seule, avec son nom. Libération en quittant la page ou après 10 min d'inactivité ; un administrateur peut forcer.</li>
<li><strong>Historique</strong> : en bas de chaque bordereau (qui, quand, quoi, ligne par ligne) et dans le menu « Historique » pour tout (taux, catalogue, bordereaux).</li>
<li><strong>Comptes</strong> (administrateurs) : invitation par e-mail (la personne choisit son mot de passe et sa double authentification), rôle Utilisateur ou Administrateur, réinitialisation. Un utilisateur fait tout sauf gérer les comptes et supprimer des bordereaux.</li>
<li><strong>Nouveautés</strong> : la cloche signale les mises à jour de l'application et liste ce qui a changé.</li>
</ul>

<h2 id="faq">10. Questions fréquentes</h2>
<h3>J'ai changé un taux, les anciens bordereaux ont bougé ?</h3><p>Seuls les bordereaux <em>en brouillon</em>. Les bordereaux figés ne bougent jamais : fige toujours avant de remettre une offre.</p>
<h3>Une prestation a besoin d'un moyen spécifique sur un seul marché</h3><p>Dans le bordereau : « PU imposé » sur la ligne (calcule à la main : heures × taux, × coefficient), ou ligne libre « plus-value ». Si c'est récurrent, mets les heures du moyen directement sur la ligne du catalogue, ou crée une variante « avec évacuation ».</p>
<h3>L'import n'a pas reconnu une ligne</h3><p>Choisis la bonne prestation dans le menu : c'est mémorisé pour la prochaine fois. Si la prestation n'existe pas, laisse « Sans prestation », crée-la ensuite au catalogue et associe-la depuis le bordereau.</p>
<h3>Le PDF n'est pas lu</h3><p>C'est un scan sans texte : demande le fichier Excel ou Word à la collectivité, ou saisis les lignes.</p>
<h3>Comment comparer avec ce qu'on a déjà remis ?</h3><p>Colonne « Réf. » et « Écart » du catalogue, ou l'assistant depuis le bordereau.</p>
<h3>Où changer le modèle d'IA ou la clé ?</h3><p>Dans le fichier <code>config-espace-pro.php</code> à la racine de l'hébergement (hors du site), lignes <code>CLAUDE_API_KEY</code> et <code>CLAUDE_MODELE</code>. Réservé à l'administrateur technique.</p>
</div>
<?php page_end(); ?>
