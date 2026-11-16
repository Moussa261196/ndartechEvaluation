# Plateforme de tests NdarTech — version corrigée

## Espace apprenant
Le tableau de bord propose l’accueil, le parcours ordonné, l’entraînement, l’examen général de la formation, les résultats, les badges, l’aide et le profil. L’examen général est programmé une seule fois par formation et tire au hasard jusqu’à 25 questions dans les niveaux actifs de cette formation. L’entraînement est conseillé avant chaque nouveau niveau ; les résultats indiquent clairement la réussite ou l’échec, et le profil permet de changer son mot de passe.

## Espaces administrateur et formateur
Les espaces de gestion disposent d’une navigation adaptée à l’écran, d’indicateurs et d’un aperçu des résultats récents. Les résultats des évaluations sont enregistrés automatiquement ; l’écran Résultats permet de les actualiser et de les exporter en CSV sans coller de codes. L’administrateur gère la plateforme et les comptes apprenants existants ; la création d’un apprenant reste réservée au formateur, qui demeure limité à ses formations attribuées. Seul l’administrateur peut supprimer définitivement un apprenant ou un formateur. La suppression d’un apprenant efface ses résultats, sa progression et son historique enregistré sur la plateforme ; elle ne peut pas effacer les éventuels caches locaux déjà présents sur ses appareils. Sa désactivation reste réversible et conserve ces données. L’administrateur peut réaffecter un apprenant à un formateur actif affecté à la même formation. Un formateur ne peut être supprimé qu’après réaffectation de ses apprenants. Les nouveaux identifiants du personnel sont générés à partir du prénom : `F_moussa` pour un formateur et `A_admin` pour un administrateur. Si l’identifiant existe déjà, un suffixe numérique est ajouté. Les comptes existants conservent leur identifiant.

## Interface et styles
Bootstrap 5.3.9 est utilisé dans l’ensemble des espaces public, QCM, apprenant et gestion : grilles responsives, navigation, boutons, formulaires, cartes, alertes, badges, tableaux, fenêtres de connexion et indicateurs de progression. Les composants conservent les couleurs et la marque NdarTech grâce à des styles ciblés. En mode sombre, les espaces public et connexion gardent une palette claire contrastée, tandis que les espaces apprenant et gestion suivent le thème actif, y compris le texte, le fond et le survol des tableaux de gestion. Des widgets donnent une vue immédiate sur la progression, les tentatives, les résultats et les examens ; les icônes Bootstrap sont servies localement depuis `vendor/bootstrap-icons/`. Bootstrap et ses icônes ne nécessitent aucun accès à un CDN. `package.json` et `package-lock.json` consignent les versions utilisées pour la maintenance.

## Évaluation des projets
Les grilles détaillées sont adaptées aux formations Informatique bureautique, Infographie et Création de Sites Web — Front-End. Chaque sous-critère est borné par son barème ; le total de la grille est converti sur 20 par `points obtenus × 20 ÷ points maximum`. Les commentaires-types sont facultatifs. Les critères ont un poids égal par défaut ; un formateur peut définir un poids de 0,1 à 10 pour chaque critère, applicable à toute la formation.

La note du projet est la moyenne des critères évalués, pondérée seulement si des poids ont été configurés. La présentation est déjà un critère du projet : elle n’est pas ajoutée une seconde fois. Tant que les grilles détaillées ne sont pas toutes complètes, le projet et la moyenne finale sont signalés comme provisoires avec le nombre de critères évalués. La note finale suit `(Parcours /10 + Examen général /10 + Projet /20) ÷ 2`. Les 10 points du parcours restent conditionnés par la validation officielle des six étapes actives. La note d’examen est calculée proportionnellement sur 10 à partir du meilleur résultat soumis ; le seuil de réussite continue de déterminer le statut « réussi/échoué ». Une tentative en cours ne remplace pas un résultat déjà soumis.

Dans le mode Parcours uniquement, après épuisement des tentatives d’un niveau sans validation, l’apprenant attend une heure et peut s’entraîner pendant ce délai. Le compteur est alors renouvelé automatiquement avec trois tentatives, sans déblocage du formateur ; les actions d’octroi manuel ne peuvent pas contourner ce délai. Une tentative laissée ouverte est considérée expirée à sa date d’expiration ; le délai commence à ce moment-là. Cette règle ne s’applique pas aux examens généraux ni au mode Entraînement.

## Installation neuve (hébergement ou ordinateur)
1. Configurez NDARTECH_SETUP_KEY dans l’environnement PHP du serveur avec une valeur aléatoire d’au moins 32 caractères. Aucune clé de repli n’est fournie par le code.
2. Envoyez les fichiers du site ainsi que les dossiers `image/`, `banks/` et `vendor/` (avec `bootstrap/` et `bootstrap-icons/`), en conservant les fichiers `.htaccess` à la racine et dans `banks/`.
3. Il n’y a pas de page `install.html`. Créez le premier compte administrateur en envoyant une requête POST JSON à `api.php` avec `X-Requested-With: qcm` et l’action `install` (`key`, `u`, `p`). La clé doit correspondre à `NDARTECH_SETUP_KEY` (au moins 32 caractères), et l’opération est refusée si un administrateur existe déjà. La valeur `u` sert à générer l’identifiant `A_...` ; la réponse renvoie l’identifiant exact dans `username`. Utilisez HTTPS sur un serveur distant et ne mettez pas la clé dans une page publique.
4. Connectez-vous depuis `index.html`.

## Mise à jour de votre installation actuelle
1. Configurez NDARTECH_SETUP_KEY dans l’environnement PHP du serveur avec une valeur aléatoire d’au moins 32 caractères. Aucune clé de repli n’est fournie par le code.
2. Remplacez `api.php`, `index.html`, `shim.js` par les nouveaux fichiers et ajoutez les dossiers `banks/` et `vendor/` (avec `bootstrap/` et `bootstrap-icons/`). Gardez votre dossier `data/`.
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
