# Site Élagage Figeacois

Site vitrine (HTML/CSS) + espace pro (PHP/MySQL) hébergé chez OVH.

- `index.html`, `elagage.html`, … : pages publiques
- `style.css` : charte graphique
- `contact.php` : envoi du formulaire de contact
- `espace-pro/` : connexion, comptes, calculateur de bordereau de prix unitaires (BPU)

## Mise en service de l'espace pro
1. Copier `espace-pro/config.exemple.php` en `espace-pro/config.php` **sur le serveur** et y coller le mot de passe de la base.
2. Ouvrir `https://elagage-figeacois.com/espace-pro/install.php`, créer le compte administrateur.
3. Supprimer `install.php` du serveur.

`config.php` n'est jamais versionné (voir `.gitignore`).
