# Héberger le site de QCM sur InfinityFree (gratuit)

Les intitulés exacts du panneau de contrôle peuvent changer : suivez l’idée, pas les mots.

## 1. Créer l’hébergement
1. Inscrivez-vous sur infinityfree.com, puis créez un compte d’hébergement gratuit avec un sous-domaine gratuit de votre choix (par exemple `ndartech-qcm`).
2. Attendez l’activation (quelques minutes), puis ouvrez le panneau de contrôle du compte.
3. Dans les réglages PHP du panneau, choisissez une version récente de PHP (8.x si disponible).

## 2. Préparer le serveur (sur votre ordinateur)
1. Choisissez une clé aléatoire d’au moins 32 caractères, puis configurez-la dans l’environnement PHP sous NDARTECH_SETUP_KEY. Si l’hébergeur ne permet pas cette configuration, ne lancez pas l’installation : le code ne contient aucune clé de repli.
2. Vérifiez que cette variable est disponible pour PHP avant d’envoyer le site. Ne placez pas la clé dans un fichier public ni dans un dépôt.
3. Vérifiez que PHP 8.x, `pdo_sqlite`, `mbstring` et `openssl` sont disponibles.

## 3. Envoyer les fichiers
1. Dans le panneau, ouvrez le gestionnaire de fichiers en ligne, ou connectez-vous en FTP (FileZilla) avec les informations « FTP » du compte.
2. Ouvrez le dossier **`htdocs`** (c’est la racine du site). Supprimez le fichier de bienvenue qui s’y trouve.
3. Envoyez dans `htdocs` les fichiers du site (`index.html`, `api.php`, `shim.js`, `ping.html`), les dossiers `image/`, `banks/` et `vendor/` (avec Bootstrap et Bootstrap Icons), ainsi que les fichiers `.htaccess` à la racine et dans `banks/`. N’envoyez pas `data/`, `.git/`, `tests/`, les journaux ni les sauvegardes. PHP doit pouvoir créer et écrire dans `data/`.

## 4. Vérifier
1. Activez le certificat SSL gratuit depuis le panneau (si ce n’est pas déjà fait) et utilisez toujours l’adresse en **https**.
2. Ouvrez `https://votre-site/data/` et vérifiez aussi qu’un nom de fichier SQLite connu dans `data/` ne peut pas être téléchargé : la réponse doit être 403 ou 404. Si vous voyez une liste ou pouvez récupérer un fichier, arrêtez le déploiement et corrigez la configuration Apache. Sur Nginx ou un serveur qui ignore `.htaccess`, ajoutez une règle de refus côté serveur avant la mise en ligne.
3. Vérifiez que `README.md`, `banks/` et `.gitignore` ne sont pas accessibles par URL (403/404).

## 5. Première utilisation
1. Depuis un client API de confiance (pas depuis une page publique), envoyez un POST JSON à `https://votre-site/api.php?a=install` avec l’en-tête `X-Requested-With: qcm` et les champs `key`, `u` et `p`. `key` est la clé de l’étape 2, `u` sert à générer l’identifiant administrateur et `p` doit contenir au moins 12 caractères. Ne mettez jamais la clé dans l’URL, le code du site ou un dépôt. L’action est refusée si l’installation a déjà été faite.
2. Après la création du compte, retirez `NDARTECH_SETUP_KEY` de l’environnement du site, puis connectez-vous par HTTPS depuis la page d’accueil.
3. Onglet **Apprenants** : collez la liste des noms, enregistrez, puis donnez à chacun son nom et son identifiant.

## Particularités d’InfinityFree à connaître
- **Vérification du navigateur :** l’hébergeur affiche parfois une page de contrôle (adresse avec `?i=1`). C’est normal. Si une page reste bloquée ou affiche un message d’erreur de connexion, rechargez-la.
- **Toujours la même adresse :** utilisez exactement la même adresse partout (avec ou sans `www`, toujours en https). Les requêtes du site ne passent que sur le même nom de domaine.
- **Cookie de contrôle expiré :** si l’onglet reste ouvert très longtemps, le site tente de renouveler le cookie tout seul. Si l’envoi échoue quand même, rechargez la page et recommencez : dans ce cas, un apprenant en cours de QCM doit refaire sa tentative.
- **Limites du plan gratuit :** le nombre de requêtes par jour est plafonné par l’hébergeur (vérifiez le chiffre actuel sur leur site). Votre espace formateur interroge le serveur toutes les 10 secondes tant qu’il est ouvert : fermez-le quand vous n’en avez pas besoin.
- **Limitation des connexions :** 10 échecs par adresse IP sur 15 minutes entraînent un blocage de 15 minutes. Les personnes derrière le même NAT ou proxy partagent cette limite ; vérifiez que le serveur transmet l’adresse IP réelle au PHP via sa configuration de proxy de confiance.
- **Sauvegarde :** créez des sauvegardes régulières, copiez-les hors du serveur et testez leur restauration avant de stocker des résultats réels. Ne rendez jamais `data/` ou ses fichiers accessibles par URL.

## En cas de problème
- Écran blanc ou « erreur 500 » : relisez `api.php` (une guillemet supprimée par erreur suffit), ou activez l’affichage des erreurs dans les réglages PHP du panneau.
- « Configurez NDARTECH_SETUP_KEY » : la variable d’environnement n’est pas définie, ou sa valeur contient moins de 32 caractères.
- Mot de passe formateur oublié : voir `README.md`.
