# Plateforme de tests NdarTech — version corrigée

## Espace apprenant
Le tableau de bord propose l’accueil, le parcours ordonné, l’entraînement, les examens programmés, les résultats, les badges, l’aide et le profil. L’entraînement est conseillé avant chaque nouveau niveau ; les résultats indiquent clairement la réussite ou l’échec, et le profil permet de changer son mot de passe.

## Espaces administrateur et formateur
Les espaces de gestion disposent d’une navigation adaptée à l’écran, d’indicateurs et d’un aperçu des résultats récents. Les résultats des évaluations sont enregistrés automatiquement ; l’écran Résultats permet de les actualiser et de les exporter en CSV sans coller de codes. L’administrateur gère la plateforme et les comptes apprenants existants ; la création d’un apprenant reste réservée au formateur, qui demeure limité à ses formations attribuées.

## Installation neuve (hébergement ou ordinateur)
1. Configurez NDARTECH_SETUP_KEY dans l’environnement PHP du serveur avec une valeur aléatoire d’au moins 32 caractères. Aucune clé de repli n’est fournie par le code.
2. Envoyez les fichiers du site ainsi que les dossiers `image/` et `banks/`, en conservant les fichiers `.htaccess` à la racine et dans `banks/`.
3. Il n’y a pas de page `install.html`. Créez le premier compte administrateur en envoyant une requête POST JSON à `api.php` avec `X-Requested-With: qcm` et l’action `install` (`key`, `u`, `p`). La clé doit correspondre à `NDARTECH_SETUP_KEY` (au moins 32 caractères), et l’opération est refusée si un administrateur existe déjà. Utilisez HTTPS sur un serveur distant et ne mettez pas la clé dans une page publique.
4. Connectez-vous depuis `index.html`.

## Mise à jour de votre installation actuelle
1. Configurez NDARTECH_SETUP_KEY dans l’environnement PHP du serveur avec une valeur aléatoire d’au moins 32 caractères. Aucune clé de repli n’est fournie par le code.
2. Remplacez `api.php`, `index.html`, `shim.js` par les nouveaux fichiers et ajoutez le dossier `banks/`. Gardez votre dossier `data/`.
3. Configurez `NDARTECH_SETUP_KEY` dans l’environnement du serveur. Ne réutilisez pas une clé publiée dans un ancien déploiement.
4. Au premier accès, une remise à zéro unique des réglages de niveaux et de modules a lieu (tentatives 3, seuil 90 %), puis vos réglages sont conservés.
5. S’il y a plusieurs fichiers `qcm-*.sqlite` dans `data/`, la plus volumineuse est utilisée (voir `data/.dbname`) ; archivez les autres hors du dossier.

## Ajouter des questions
Voir `banks/MODELE-banque.txt` : un fichier `.php` déposé dans `banks/` est importé automatiquement une seule fois.

## À lire
- `URGENT-SECURITE.md` (avant toute mise en ligne), `CHANGELOG.md`, `A-DECIDER.md`, `PLAN-PEDAGOGIQUE.md` (structure des 633 questions).
- `GUIDE-INFINITYFREE.md` pour l’hébergement gratuit (la page de vérification du navigateur de l’hébergeur est gérée par `shim.js` et `ping.html`).

## Exigences
PHP 7.4 ou plus (testé en 8.3), extensions `pdo_sqlite`, `mbstring`, `openssl`, HTTPS.
