# À faire AVANT d’utiliser le site avec de vrais apprenants

NDARTECH_SETUP_KEY doit être défini dans l’environnement PHP du serveur avec une valeur aléatoire d’au moins 32 caractères avant l’installation. Le code ne fournit aucune clé de repli ; ne publiez jamais la valeur réelle.

Le dossier examiné ne contient pas de dépôt Git ; son historique n’a donc pas pu être contrôlé. Si une copie antérieure du projet a été publiée, vérifiez séparément si elle contenait une base ou une clé.

1. **Vérifiez si le dépôt GitHub est public** : ouvrez son adresse sans être connecté. S’il est public, considérez que les comptes, noms, adresses e-mail, empreintes de mots de passe et résultats qui y figurent sont exposés.
2. Passez le dépôt en **privé** immédiatement. Rendre le dépôt privé n’efface pas les copies déjà faites.
3. **Changez la clé d’installation** et tous les mots de passe de formateurs et d’administrateurs ; faites réinitialiser ceux des apprenants.
4. **Retirez les bases de l’historique** (outil `git filter-repo` ou BFG), puis forcez la mise à jour du dépôt. Le nouveau `.gitignore` empêche que cela se reproduise.
5. Ne mettez **jamais** en ligne les dossiers `data/` et `.git/` ni les sauvegardes sur un hébergement public.
6. Sur les navigateurs de formateurs : l’ancienne version gardait des mots de passe temporaires dans le navigateur. La nouvelle page les efface à l’ouverture ; ouvrez-la une fois sur chaque ordinateur utilisé.
