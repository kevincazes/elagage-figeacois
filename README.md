# Site Élagage Figeacois

Site vitrine (HTML/CSS) + espace pro (PHP/MySQL) hébergé chez OVH.

- `index.html`, `elagage.html`, … : pages publiques
- `style.css` : charte graphique
- `contact.php` : envoi du formulaire de contact
- `espace-pro/` : connexion, comptes, calculateur de bordereau de prix unitaires (BPU)

## Mise en service de l'espace pro
1. Copier `espace-pro/config.exemple.php` sous le nom `config-espace-pro.php` **à la racine de l'hébergement** (au même niveau que `www`, pas dedans) et y coller le mot de passe de la base. Ce fichier n'est ni dans Git ni accessible depuis le web.
2. Ouvrir `https://elagage-figeacois.com/espace-pro/install.php`, créer le compte administrateur.
3. Supprimer `install.php` du serveur.

`config.php` n'est jamais versionné (voir `.gitignore`).
