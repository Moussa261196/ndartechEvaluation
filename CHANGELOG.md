# Journal des corrections

## Espace apprenant
- Ajout d’un tableau de bord avec Accueil, Mon parcours, Entraînement, Examens, Résultats, Badges, Aide et Profil, adapté aux petits écrans.
- L’accueil recommande un entraînement avant le Parcours d’un niveau ; il permet aussi de reprendre une tentative en cours ou de continuer après un entraînement terminé.
- Ajout d’un changement de mot de passe apprenant avec vérification du mot de passe actuel et confirmation du nouveau.

## Espaces administrateur et formateur
- Refonte de l’espace de gestion avec navigation latérale sur ordinateur et barre fixe adaptée au mobile, indicateurs, activité récente et raccourcis contextualisés selon le rôle.
- Les données du tableau de bord restent limitées aux périmètres autorisés par l’API ; les mots de passe temporaires ne sont pas affichés dans les cartes d’activité.
- L’ancien générateur de codes locaux de déblocage, sans effet sur les tentatives enregistrées par le serveur, est remplacé par un lien vers les actions de tentatives journalisées ; le texte rappelle qu’aucun délai automatique n’est appliqué après un échec.
- L’administrateur peut consulter et gérer les comptes apprenants existants ; la création d’un apprenant reste réservée au formateur, conformément aux permissions de l’API.
- La saisie manuelle de codes de résultats est supprimée ; les résultats enregistrés par la plateforme sont consultables, actualisables et exportables en CSV.

## Parcours apprenant
- Avant chaque test, l’apprenant voit le niveau choisi et les règles applicables au mode (seuil, tentatives, durée, corrections et déblocage), et doit confirmer qu’il les a lues.
- La progression affiche la liste ordonnée des niveaux, leur module, leur état, le meilleur score et les tentatives restantes.
- Après une tentative, un message distingue clairement réussite et échec. La validation et le déblocage restent réservés au mode Parcours.
- Si l’enregistrement d’une réponse ou du résultat échoue, l’apprenant en est averti et peut réessayer.
- Le test reprend les réponses déjà enregistrées, n’affiche plus un choix de nombre de questions ignoré par le serveur, et distingue les bonnes réponses des erreurs sans divulguer le corrigé après un échec.
- Les questions à réponses multiples sont maintenant sélectionnables et notées comme telles. Un niveau sans question active n’ouvre plus de tentative.
- L’enregistrement d’un résultat, de la progression et de l’historique est atomique afin d’éviter des résultats partiels.
- La création d’un apprenant et de sa fiche de suivi est atomique ; deux apprenants portant le même nom restent visibles et gérables séparément.
- La modification d’un apprenant vérifie que la formation existe et évite les collisions de noms normalisés.
- L’insertion de la progression et du sélecteur de module utilise le bon élément de référence DOM.

## Configuration d’installation
- La clé d’installation codée en dur a été remplacée par une valeur d’exemple inutilisable ; une clé privée d’au moins 32 caractères est requise pour une nouvelle installation.

Vérifications faites : 39 tests automatiques du serveur (PHP 8.3), 16 tests de l’écran formateur et 8 tests du parcours apprenant, exécutés sur la vraie page dans un navigateur simulé (jsdom). Aucun test sur un vrai navigateur ni sur InfinityFree.

## Sécurité (graves)
1. **Bonnes réponses visibles dans le navigateur.** Au démarrage et à la reprise d’une tentative, le serveur renvoyait les bonnes réponses (`is_correct`) et un champ `attempt.snapshot` complet, en Parcours comme en Examen. Corrigé : seules les questions et les choix sont envoyés (`publicQuestions`). En Entraînement, la correction reste visible, comme prévu.
2. **Anciens points d’entrée dangereux supprimés** (`module_questions`, `evaluation_submit`). Ils permettaient de répondre à une seule question et d’obtenir 100 %, de soumettre sans limite et de récupérer toutes les bonnes réponses en un appel. L’interface ne les utilisait pas.
3. **Droits des formateurs.** Un formateur pouvait accorder des tentatives, débloquer un niveau, configurer un examen ou lire les résultats de n’importe quelle formation ou apprenant. Il est maintenant limité à ses formations et à ses apprenants. Les formation/module d’un examen viennent du niveau en base, pas du navigateur.
4. **Journal des actions formateur.** L’identifiant du formateur n’était jamais enregistré (toujours 0). Corrigé.
5. **Mots de passe en clair dans le navigateur.** Les mots de passe temporaires étaient copiés dans `localStorage` sans limite de durée. Ils ne sont plus stockés, et ceux déjà présents sont effacés à l’ouverture de la page.
6. **Premier administrateur.** Création via l’action API `install` (clé d’installation exigée, utilisable une seule fois).
7. **Clé secrète retirée du code livré** (`SETUP_KEY`) : voir `URGENT-SECURITE.md`.
8. **Sauvegardes** : copie cohérente (`VACUUM INTO`) avec un nom imprévisible.
9. **Erreurs PHP jamais affichées** dans les réponses ; une erreur serveur renvoie un message propre.

## Bugs qui bloquaient le fonctionnement
10. **Niveaux bloqués sur PHP 8.1 et plus.** Un niveau validé n’était pas reconnu (`1` comparé à `'1'`) : le niveau suivant restait verrouillé pour toujours. Corrigé.
11. **Réglages remis à zéro à chaque requête.** Tentatives (3), seuil (90 %), nombre de questions et durée des modules étaient réécrits à chaque appel. Les réglages du formateur sont maintenant conservés : la remise à zéro n’a lieu qu’une seule fois (`seed_v2`).
12. **Examens : tentatives illimitées.** La requête de comptage n’avait qu’un paramètre sur deux. Corrigé.
13. **Seuil** : le niveau utilise son propre seuil (plus un 90 fixe), avec un calcul exact (plus d’arrondi qui valide 89,6 %). La réponse contient `passed`.
14. **Durée de la tentative** : calculée par le serveur (elle venait du navigateur).
15. **Deux démarrages simultanés** ne peuvent plus dépasser le nombre de tentatives (index unique).
16. **Le fichier de base ne dépend plus du dossier.** Son nom était calculé à partir du chemin : déplacer le projet faisait « disparaître » les données. Le nom est maintenant mémorisé dans `data/.dbname` (si plusieurs bases existent, la plus volumineuse est retenue). Même correction pour la clé du coffre d’identifiants (les anciens identifiants restent lisibles).
17. Délai d’attente SQLite (5 s) pour limiter les erreurs quand plusieurs apprenants écrivent en même temps.

## Interface
18. 89 textes mal encodés réparés (« Ã  la fin » → « à la fin », « × » → « × »).

## Préparation à l’emploi
19. **Banque de questions par niveau** : nouvelle colonne `questions.level_id`. Si des questions sont rattachées à un niveau, elles sont utilisées et 25 sont tirées au hasard par tentative (`QUESTIONS_PER_ATTEMPT`). Sinon, l’ancien découpage est conservé (voir `A-DECIDER.md`, point 1).
20. **Déblocage manuel** distingué d’une validation réelle (`level_progress.manual_unlock`).
21. `.gitignore` corrigé (`data/`, bases, clés). `index-inline.js` (copie inutilisée de la page) n’est plus livré.

## Deuxième lot : questions et écrans formateur
22. **Banque de questions sur le serveur.** Les 211 questions de l’ancienne version (36 en culture générale, 35 pour HTML, CSS, JavaScript, Bootstrap et Git) sont importées dans la base, rattachées à leur niveau (`banks/developpement-web.php`). Chaque tentative en tire **25 au hasard**. L’import est automatique, unique et sans doublon ; le modèle pour ajouter d’autres banques est dans `banks/MODELE-banque.txt`.
23. **Ancienne banque retirée de `index.html`** (35 Ko, bonnes réponses lisibles par tous dans le code de la page).
24. **Ordre des choix mélangé par le serveur** à chaque tentative (la bonne réponse était toujours en première position dans les données reçues, seul l’affichage la mélangeait). L’ordre reste le même si l’apprenant reprend sa tentative.
25. **Parcours : après un échec, les bonnes réponses ne sont plus affichées** (les questions fausses sont indiquées, avec « Revoyez cette notion »). Elles restent visibles après une réussite.
26. **Nouvel onglet « Tests » dans l’espace formateur** : réglage des tentatives par niveau (avec alerte quand un niveau a moins de 25 questions), tentative supplémentaire, déblocage exceptionnel avec **motif obligatoire**, configuration et programmation d’un examen, publication ou masquage des corrections, résultats des tests de ses apprenants, et journal des actions.
27. Nouveaux points d’entrée serveur : `trainer_levels_list` et `trainer_actions_list` (limités aux formations et aux actions du formateur).
28. La progression est renvoyée sous forme de nombres (affichage correct sur toutes les versions de PHP).

## Troisième lot : banques Bureautique et Infographie
29. **422 questions écrites** : `banks/bureautique.php` et `banks/infographie.php` (211 chacune). Les trois formations ont maintenant exactement la même structure : 36 questions à l'étape 1 et 35 aux étapes 2 à 6 (voir `PLAN-PEDAGOGIQUE.md`). Plus aucun niveau ne fonctionne avec l'ancien découpage de 3 à 4 questions.
30. **Bug corrigé : l'ordre des niveaux.** La liste des niveaux était triée par module avant la position. Pour Bureautique, l'écran proposait « Excel - fondamentaux » (verrouillé) comme premier niveau à passer au lieu de « Culture générale ». Le tri se fait maintenant par position dans la formation.
31. Contrôle automatique de la qualité des banques (effectifs, doublons, choix identiques, biais de longueur de la bonne réponse). Les choix de 150 questions ont été réécrits pour que la bonne réponse ne se repère plus à sa longueur.
32. Vérification de bout en bout : un apprenant de chacune des trois formations enchaîne les 6 étapes (25 questions, réussite, déblocage) ; test de l'écran dans un navigateur simulé pour Développement web et Bureautique.
