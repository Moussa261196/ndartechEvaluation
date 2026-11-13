# Héberger le site de QCM sur InfinityFree (gratuit)

Les intitulés exacts du panneau de contrôle peuvent changer : suivez l’idée, pas les mots.

## 1. Créer l’hébergement
1. Inscrivez-vous sur infinityfree.com, puis créez un compte d’hébergement gratuit avec un sous-domaine gratuit de votre choix (par exemple `ndartech-qcm`).
2. Attendez l’activation (quelques minutes), puis ouvrez le panneau de contrôle du compte.
3. Dans les réglages PHP du panneau, choisissez une version récente de PHP (8.x si disponible).

## 2. Préparer le serveur (sur votre ordinateur)
1. Choisissez une clé aléatoire d’au moins 32 caractères, puis configurez-la dans l’environnement PHP sous NDARTECH_SETUP_KEY. Si l’hébergeur ne permet pas cette configuration, ne lancez pas l’installation : le code ne contient aucune clé de repli.
2. Vérifiez que cette variable est disponible pour PHP avant d’envoyer le site. Ne placez pas la clé dans un fichier public ni dans un dépôt.

## 3. Envoyer les fichiers
1. Dans le panneau, ouvrez le gestionnaire de fichiers en ligne, ou connectez-vous en FTP (FileZilla) avec les informations « FTP » du compte.
2. Ouvrez le dossier **`htdocs`** (c’est la racine du site). Supprimez le fichier de bienvenue qui s’y trouve.
3. Envoyez dans `htdocs` les fichiers du site, les dossiers `image/` et `banks/`, ainsi que les fichiers `.htaccess` à la racine et dans `banks/`. N’envoyez pas `data/`, `.git/` ni les sauvegardes.

## 4. Vérifier
1. Activez le certificat SSL gratuit depuis le panneau (si ce n’est pas déjà fait) et utilisez toujours l’adresse en **https**.
2. Ouvrez `https://votre-site/data/` : la réponse doit être 403 ou 404. Si vous voyez une liste, arrêtez le déploiement et corrigez la configuration Apache.
3. Vérifiez que `README.md`, `banks/` et `.gitignore` ne sont pas accessibles par URL (403/404).

## 5. Première utilisation
1. Ouvrez `https://votre-site/install.html`, créez le compte administrateur et saisissez la clé configurée à l’étape 2. Après confirmation, **supprimez `install.html` du serveur**, puis connectez-vous depuis la page d’accueil.
2. Onglet **Apprenants** : collez la liste des noms, enregistrez, puis donnez à chacun son nom et son identifiant.

## Particularités d’InfinityFree à connaître
- **Vérification du navigateur :** l’hébergeur affiche parfois une page de contrôle (adresse avec `?i=1`). C’est normal. Si une page reste bloquée ou affiche un message d’erreur de connexion, rechargez-la.
- **Toujours la même adresse :** utilisez exactement la même adresse partout (avec ou sans `www`, toujours en https). Les requêtes du site ne passent que sur le même nom de domaine.
- **Cookie de contrôle expiré :** si l’onglet reste ouvert très longtemps, le site tente de renouveler le cookie tout seul. Si l’envoi échoue quand même, rechargez la page et recommencez : dans ce cas, un apprenant en cours de QCM doit refaire sa tentative.
- **Limites du plan gratuit :** le nombre de requêtes par jour est plafonné par l’hébergeur (vérifiez le chiffre actuel sur leur site). Votre espace formateur interroge le serveur toutes les 10 secondes tant qu’il est ouvert : fermez-le quand vous n’en avez pas besoin.
- **Sauvegarde :** téléchargez régulièrement le dossier `data/` (fichier `qcm-….sqlite`) par FTP.

## En cas de problème
- Écran blanc ou « erreur 500 » : relisez `api.php` (une guillemet supprimée par erreur suffit), ou activez l’affichage des erreurs dans les réglages PHP du panneau.
- « Configurez NDARTECH_SETUP_KEY » : la variable d’environnement n’est pas définie, ou sa valeur contient moins de 32 caractères.
- Mot de passe formateur oublié : voir `README.md`.
