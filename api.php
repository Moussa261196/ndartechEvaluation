<?php
// Serveur du site de QCM — PHP 7.4+ avec SQLite (extension pdo_sqlite).
// 1) Configurez NDARTECH_SETUP_KEY avant la première installation.
// 2) SALT doit rester identique à la valeur SALT utilisée pour créer les identifiants (ne pas modifier si des identifiants existent).
// Configure NDARTECH_SETUP_KEY in the server environment before first installation.
// The fallback deliberately disables setup until an administrator supplies a secret.
if (!defined('SETUP_KEY')) {
    $setupKey = getenv('NDARTECH_SETUP_KEY');
    define('SETUP_KEY', $setupKey !== false ? $setupKey : 'REMPLACEZ-CETTE-CLE');
}
const SALT = 'ndartech-c4';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')]);
session_start();

$inactiveLimit = !empty($_SESSION['admin']) ? 1800 : 7200;
if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $inactiveLimit) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
}
$_SESSION['last_activity'] = time();

ini_set('display_errors', '0');
set_exception_handler(function ($e) { error_log('qcm: ' . $e->getMessage()); echo json_encode(['error' => 'server_error', 'msg' => 'Erreur du serveur, réessayez.']); exit; });
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function out(array $a): void {
    if (isset($a['error'])) {
        $status = [
            'bad_request' => 400,
            'authentication_required' => 401,
            'forbidden' => 403,
            'password_change_required' => 403,
            'formation_unavailable' => 403,
            'not_found' => 404,
            'bad_data' => 422,
            'bad_path' => 422,
            'too_big' => 413,
            'result_immutable' => 409,
            'server_error' => 500
        ][$a['error']] ?? null;
        if ($status !== null) http_response_code($status);
    }
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
function setupKeyReady(): bool { return SETUP_KEY !== 'REMPLACEZ-CETTE-CLE' && strlen(SETUP_KEY) >= 32; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'qcm') out(['error' => 'bad_request']);

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
    file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    file_put_contents($dir . '/index.html', '');
}
// Le nom du fichier est aléatoire et conservé dans data/.dbname pour rester stable si le dossier est déplacé.
$nameFile = $dir . '/.dbname';
$dbName = is_file($nameFile) ? trim((string)@file_get_contents($nameFile)) : '';
if (!preg_match('/^qcm-[a-f0-9]{16}\.sqlite$/', $dbName)) {
    $found = glob($dir . '/qcm-*.sqlite') ?: [];
    usort($found, function ($x, $y) { return [filesize($y), filemtime($y)] <=> [filesize($x), filemtime($x)]; }); // la plus grosse base = celle qui contient les données
    $dbName = $found ? basename($found[0]) : 'qcm-' . bin2hex(random_bytes(8)) . '.sqlite';
    @file_put_contents($nameFile, $dbName, LOCK_EX);
}
$dbFile = $dir . '/' . $dbName;
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_TIMEOUT, 5);
$db->exec('CREATE TABLE IF NOT EXISTS kv(path TEXT PRIMARY KEY, data TEXT NOT NULL, updated TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS cfg(k TEXT PRIMARY KEY, v TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS staff(id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ("admin","formateur","lecture")), active INTEGER NOT NULL DEFAULT 1, created TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS learners(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, normalized_name TEXT UNIQUE NOT NULL, learner_uid TEXT UNIQUE NOT NULL, learner_id TEXT UNIQUE NOT NULL, active INTEGER NOT NULL DEFAULT 1, created TEXT NOT NULL, created_by TEXT NOT NULL DEFAULT "")');
$db->exec('CREATE TABLE IF NOT EXISTS learner_accounts(id INTEGER PRIMARY KEY AUTOINCREMENT, learner_uid TEXT UNIQUE, first_name TEXT NOT NULL, last_name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, phone TEXT NOT NULL DEFAULT "", password_hash TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS formations(id TEXT PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL, icon TEXT NOT NULL DEFAULT "📚", active INTEGER NOT NULL DEFAULT 1, position INTEGER NOT NULL DEFAULT 0, created TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS modules(id TEXT PRIMARY KEY, formation_id TEXT NOT NULL, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", active INTEGER NOT NULL DEFAULT 1, position INTEGER NOT NULL DEFAULT 0, duration_minutes INTEGER NOT NULL DEFAULT 10, question_count INTEGER NOT NULL DEFAULT 10, pass_percent INTEGER NOT NULL DEFAULT 70, FOREIGN KEY(formation_id) REFERENCES formations(id))');
$db->exec('CREATE TABLE IF NOT EXISTS questions(id INTEGER PRIMARY KEY AUTOINCREMENT, module_id TEXT NOT NULL, question TEXT NOT NULL, type TEXT NOT NULL DEFAULT "single", points INTEGER NOT NULL DEFAULT 1, explanation TEXT NOT NULL DEFAULT "", active INTEGER NOT NULL DEFAULT 1, position INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(module_id) REFERENCES modules(id))');
$db->exec('CREATE TABLE IF NOT EXISTS answers(id INTEGER PRIMARY KEY AUTOINCREMENT, question_id INTEGER NOT NULL, answer TEXT NOT NULL, is_correct INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS evaluations(id TEXT PRIMARY KEY, formation_id TEXT NOT NULL, module_id TEXT, learner_uid TEXT NOT NULL, learner_name TEXT NOT NULL, mode TEXT NOT NULL, score INTEGER NOT NULL, total INTEGER NOT NULL, percentage INTEGER NOT NULL, duration_seconds INTEGER NOT NULL DEFAULT 0, submission_id TEXT UNIQUE NOT NULL, created TEXT NOT NULL, FOREIGN KEY(formation_id) REFERENCES formations(id))');

foreach ([
    'ALTER TABLE staff ADD COLUMN first_name TEXT NOT NULL DEFAULT ""',
    'ALTER TABLE staff ADD COLUMN last_name TEXT NOT NULL DEFAULT ""',
    'ALTER TABLE staff ADD COLUMN temporary_password INTEGER NOT NULL DEFAULT 0',
    'ALTER TABLE staff ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0',
    'ALTER TABLE learner_accounts ADD COLUMN identifier TEXT',
    'ALTER TABLE learner_accounts ADD COLUMN formation_id TEXT',
    'ALTER TABLE learner_accounts ADD COLUMN trainer_staff_id INTEGER',
    'ALTER TABLE learner_accounts ADD COLUMN temporary_password INTEGER NOT NULL DEFAULT 0',
    'ALTER TABLE learner_accounts ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0',
    'ALTER TABLE staff ADD COLUMN credential_cipher TEXT NOT NULL DEFAULT ""',
    'ALTER TABLE learner_accounts ADD COLUMN credential_cipher TEXT NOT NULL DEFAULT ""',
    'ALTER TABLE questions ADD COLUMN level_id TEXT',
    'ALTER TABLE level_progress ADD COLUMN manual_unlock INTEGER NOT NULL DEFAULT 0'
] as $sql) { try { $db->exec($sql); } catch (PDOException $e) {} }
$db->exec('CREATE TABLE IF NOT EXISTS staff_formations(staff_id INTEGER NOT NULL, formation_id TEXT NOT NULL, PRIMARY KEY(staff_id,formation_id), FOREIGN KEY(staff_id) REFERENCES staff(id) ON DELETE CASCADE, FOREIGN KEY(formation_id) REFERENCES formations(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS test_levels(id TEXT PRIMARY KEY, formation_id TEXT NOT NULL, module_id TEXT NOT NULL, name TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, attempts_allowed INTEGER NOT NULL DEFAULT 3, pass_percent INTEGER NOT NULL DEFAULT 90, created TEXT NOT NULL, FOREIGN KEY(formation_id) REFERENCES formations(id), FOREIGN KEY(module_id) REFERENCES modules(id))');
$db->exec('CREATE TABLE IF NOT EXISTS level_progress(id INTEGER PRIMARY KEY AUTOINCREMENT, learner_uid TEXT NOT NULL, level_id TEXT NOT NULL, module_id TEXT NOT NULL, formation_id TEXT NOT NULL, attempts_used INTEGER NOT NULL DEFAULT 0, extra_attempts INTEGER NOT NULL DEFAULT 0, attempts_allowed INTEGER NOT NULL DEFAULT 3, best_percentage INTEGER NOT NULL DEFAULT -1, validated INTEGER NOT NULL DEFAULT 0, validated_at TEXT, updated_at TEXT NOT NULL, UNIQUE(learner_uid,level_id))');
$db->exec('CREATE TABLE IF NOT EXISTS test_attempts(id TEXT PRIMARY KEY, learner_uid TEXT NOT NULL, formation_id TEXT NOT NULL, module_id TEXT NOT NULL, level_id TEXT NOT NULL, mode TEXT NOT NULL CHECK(mode IN ("parcours","entrainement","examen")), attempt_number INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT "started" CHECK(status IN ("started","submitted","expired","cancelled")), started_at TEXT NOT NULL, expires_at TEXT, submitted_at TEXT, score INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0, percentage INTEGER NOT NULL DEFAULT -1, duration_seconds INTEGER NOT NULL DEFAULT 0, snapshot TEXT NOT NULL DEFAULT "[]", submission_id TEXT UNIQUE, created TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS test_attempt_answers(attempt_id TEXT NOT NULL, question_id INTEGER NOT NULL, answer_id INTEGER, multiple_answer_ids TEXT NOT NULL DEFAULT "[]", saved_at TEXT NOT NULL, PRIMARY KEY(attempt_id,question_id), FOREIGN KEY(attempt_id) REFERENCES test_attempts(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE IF NOT EXISTS exam_configs(id TEXT PRIMARY KEY, formation_id TEXT NOT NULL, module_id TEXT NOT NULL, level_id TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT "desactive" CHECK(state IN ("desactive","programme","actif","ferme","termine")), starts_at TEXT, closes_at TEXT, attempts_allowed INTEGER NOT NULL DEFAULT 1, pass_percent INTEGER NOT NULL DEFAULT 90, correction_mode TEXT NOT NULL DEFAULT "manuel" CHECK(correction_mode IN ("immediate","programme","manuel")), correction_at TEXT, correction_published INTEGER NOT NULL DEFAULT 0, updated_by TEXT NOT NULL DEFAULT "", updated_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS trainer_actions(id INTEGER PRIMARY KEY AUTOINCREMENT, trainer_staff_id INTEGER NOT NULL, action TEXT NOT NULL, learner_uid TEXT NOT NULL, level_id TEXT, module_id TEXT, details TEXT NOT NULL DEFAULT "", created_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT, actor_staff_id INTEGER NOT NULL DEFAULT 0, actor_username TEXT NOT NULL DEFAULT "", actor_role TEXT NOT NULL, action TEXT NOT NULL, resource_type TEXT NOT NULL, resource_id TEXT NOT NULL DEFAULT "", details TEXT NOT NULL DEFAULT "{}", created_at TEXT NOT NULL)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at DESC)');
try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_attempt_number ON test_attempts(learner_uid,level_id,mode,attempt_number) WHERE mode<>'entrainement'"); } catch (PDOException $e) {}
$db->exec("CREATE INDEX IF NOT EXISTS idx_test_attempts_learner ON test_attempts(learner_uid,formation_id,module_id,level_id,mode,status)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_level_progress_learner ON level_progress(learner_uid,validated)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_attempt_answers_attempt ON test_attempt_answers(attempt_id)");

// Configuration centrale : exactement six niveaux ordonnés par formation.
// Les anciens niveaux en doublons restent conservés mais sont désactivés; ils ne participent plus à la progression.
$seedDone = (string)cfg($db, 'seed_v2') === '1';
if (!$seedDone) $db->exec('UPDATE test_levels SET active=0 WHERE formation_id IN ("bureautique","infographie")');
$levelSeed = [
 ['developpement-web','web-general','culture','Culture générale du développement web',0],
 ['developpement-web','web-general','html','HTML',1],
 ['developpement-web','web-general','css','CSS',2],
 ['developpement-web','web-general','javascript','Initiation à JavaScript',3],
 ['developpement-web','web-general','bootstrap','Bootstrap',4],
 ['developpement-web','web-general','git','Git et GitHub',5],
 ['bureautique','word','bureau-culture','Culture générale informatique',0],
 ['bureautique','word','bureau-word-fond','Microsoft Word - Connaissances fondamentales',1],
 ['bureautique','word','bureau-word-format','Microsoft Word - Mise en forme et fonctionnalités',2],
 ['bureautique','excel','bureau-excel-fond','Microsoft Excel - Connaissances fondamentales',3],
 ['bureautique','excel','bureau-excel-formules','Microsoft Excel - Formules et traitement des données',4],
 ['bureautique','powerpoint','bureau-powerpoint','Microsoft PowerPoint - Présentation et conception',5],
 ['infographie','illustration','info-culture','Culture générale du graphisme numérique',0],
 ['infographie','illustration','info-illustration-fond','Illustration - Connaissances fondamentales',1],
 ['infographie','illustration','info-illustration-tech','Illustration - Techniques graphiques',2],
 ['infographie','photoshop','info-photoshop-fond','Photoshop - Connaissances fondamentales',3],
 ['infographie','photoshop','info-photoshop-retouche','Photoshop - Retouche et manipulation d images',4],
 ['infographie','photoshop','info-infographie-production','Infographie - Techniques et production graphique',5]
];
$levelInsert = $db->prepare('INSERT OR IGNORE INTO test_levels(id,formation_id,module_id,name,position,active,attempts_allowed,pass_percent,created) VALUES(?,?,?,?,?,1,3,90,?)');
foreach ($levelSeed as $level) {
    $levelId='level-'.$level[2];
    $levelInsert->execute([$levelId,$level[0],$level[1],$level[3],$level[4],$now ?? gmdate('c')]);
    if (!$seedDone) { $activate=$db->prepare('UPDATE test_levels SET formation_id=?,module_id=?,name=?,position=?,active=1,attempts_allowed=3,pass_percent=90 WHERE id=?'); $activate->execute([$level[0],$level[1],$level[3],$level[4],$levelId]); }
}
// Un examen reste désactivé par défaut, avec les mêmes seuils pour toutes les formations.
$examDefault = $db->prepare('INSERT OR IGNORE INTO exam_configs(id,formation_id,module_id,level_id,active,state,attempts_allowed,pass_percent,correction_mode,updated_by,updated_at) VALUES(?,?,?,?,0,"desactive",3,90,"manuel","system",?)');
foreach ($levelSeed as $level) $examDefault->execute(['exam-'.$level[2],$level[0],$level[1],'level-'.$level[2],$now ?? gmdate('c')]);

// Vérifier à chaque requête qu'un compte personnel staff existe encore, reste actif et n'a pas changé de rôle.
if (!empty($_SESSION['admin']) && !empty($_SESSION['staff_username'])) {
    $sessionStaffName = (string)$_SESSION['staff_username'];
    $sessionStaff = $db->prepare('SELECT role,active FROM staff WHERE LOWER(username)=LOWER(?) LIMIT 1');
    $sessionStaff->execute([$sessionStaffName]);
    $sessionStaffRow = $sessionStaff->fetch(PDO::FETCH_ASSOC);
    $primaryAdminName = (string)cfg($db, 'admin_user');
    if (hash_equals(strtolower($primaryAdminName), strtolower($sessionStaffName))) {
        $_SESSION['staff_role'] = 'admin';
    } elseif ($sessionStaffRow && (int)$sessionStaffRow['active'] === 1 && in_array((string)$sessionStaffRow['role'], ['admin', 'formateur'], true)) {
        $_SESSION['staff_role'] = (string)$sessionStaffRow['role'];
    } else {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
    }
}

// Revalider chaque apprenant à chaque requête, notamment après désactivation ou changement de formation.
if (!empty($_SESSION['uid'])) {
    $sessionUid = (string)$_SESSION['uid'];
    $learnerSession = $db->prepare('SELECT first_name,last_name,active,formation_id,must_change_password FROM learner_accounts WHERE learner_uid=? LIMIT 1');
    $learnerSession->execute([$sessionUid]);
    $learnerSessionRow = $learnerSession->fetch(PDO::FETCH_ASSOC);
    if (!$learnerSessionRow || (int)$learnerSessionRow['active'] !== 1) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
    } else {
        unset($_SESSION['admin'], $_SESSION['staff_role'], $_SESSION['staff_username']);
        $_SESSION['name'] = trim((string)$learnerSessionRow['first_name'] . ' ' . (string)$learnerSessionRow['last_name']);
        $_SESSION['formation_id'] = (string)($learnerSessionRow['formation_id'] ?? '');
        $_SESSION['must_change_password'] = (int)$learnerSessionRow['must_change_password'] === 1;
    }
}

$now = gmdate('c');
$formations = [['developpement-web','Développement web','Découverte du web, HTML, CSS, JavaScript, Bootstrap, Git et bases des outils numériques.','🌐',1],['infographie','Infographie','Bases du dessin numérique, de Photoshop, des formes, couleurs, calques et retouches photo.','🎨',2],['bureautique','Informatique bureautique – Niveau débutant','Initiation à Word, Excel et PowerPoint pour produire et présenter des documents professionnels.','💼',3]];
$fi = $db->prepare('INSERT OR IGNORE INTO formations(id,name,description,icon,position,created) VALUES(?,?,?,?,?,?)');
foreach ($formations as $f) $fi->execute([$f[0],$f[1],$f[2],$f[3],$f[4],$now]);
$mi = $db->prepare('INSERT OR IGNORE INTO modules(id,formation_id,name,description,position,duration_minutes,question_count,pass_percent) VALUES(?,?,?,?,?,?,?,?)');
$modules = [['web-general','developpement-web','Formation générale','Parcours complet de découverte du développement web.',0,20,20,90],['illustration','infographie','Illustration','Bases du dessin numérique et des outils d’illustration.',0,20,20,90],['photoshop','infographie','Photoshop','Interface, sélection, calques, retouche et export Photoshop.',1,20,20,90],['word','bureautique','Word','Mise en forme, paragraphes, tableaux, images et impression.',0,20,20,90],['excel','bureautique','Excel','Cellules, lignes, colonnes, formules simples et tableaux.',1,20,20,90],['powerpoint','bureautique','PowerPoint','Diapositives, textes, images, thèmes, animations et présentation.',2,20,20,90]];
foreach ($modules as $m) $mi->execute([$m[0],$m[1],$m[2],$m[3],$m[4],$m[5],$m[6],$m[7]]);
// Reconciliation centralisée : les trois formations utilisent les mêmes règles de validation et de volume.
if (!$seedDone) {
    $db->exec('UPDATE modules SET pass_percent=90,question_count=20,duration_minutes=20');
    $db->exec('UPDATE test_levels SET pass_percent=90 WHERE pass_percent<>90');
    setCfg($db, 'seed_v2', '1');
}
importQuestionBanks($db);
function seedModuleQuestions(PDO $db, string $moduleId, array $questions): void {
    $qCount = $db->prepare('SELECT COUNT(*) FROM questions WHERE module_id=?'); $qCount->execute([$moduleId]);
    if ((int)$qCount->fetchColumn() > 0) return;
    seedQuestionsStartingAt($db,$moduleId,$questions,0);
}
function seedQuestionsStartingAt(PDO $db, string $moduleId, array $questions, int $startPosition): void {
    $qi = $db->prepare('INSERT INTO questions(module_id,question,type,points,explanation,active,position) VALUES(?,?,?,?,?,1,?)');
    $ai = $db->prepare('INSERT INTO answers(question_id,answer,is_correct,position) VALUES(?,?,?,?)');
    foreach ($questions as $offset => $q) {
        $position=$startPosition+$offset;$qi->execute([$moduleId,$q[0],'single',1,$q[5] ?? '',$position]);$qid=(int)$db->lastInsertId();
        foreach ([1,2,3,4] as $answerPosition)$ai->execute([$qid,$q[$answerPosition],$answerPosition===1?1:0,$answerPosition-1]);
    }
}
function ensureTwentyQuestions(PDO $db,string $moduleId,array $questions):void{
    $count=$db->prepare('SELECT COUNT(*) FROM questions WHERE module_id=? AND active=1');$count->execute([$moduleId]);$existing=(int)$count->fetchColumn();if($existing>=20)return;
    $max=$db->prepare('SELECT COALESCE(MAX(position),-1) FROM questions WHERE module_id=?');$max->execute([$moduleId]);$position=(int)$max->fetchColumn()+1;
    seedQuestionsStartingAt($db,$moduleId,array_slice($questions,0,20-$existing),$position);
}
$webGeneral = [
['Quel langage structure le contenu d’une page web ?','HTML','CSS','JavaScript','PHP','HTML définit la structure et les éléments de la page.'],
['Quel langage met en forme l’apparence d’une page ?','CSS','HTML','SQL','Python','CSS contrôle couleurs, espacements, polices et mise en page.'],
['Quel langage rend une page interactive dans le navigateur ?','JavaScript','PHP','SQL','XML','JavaScript exécute la logique côté navigateur.'],
['Que signifie le mot HTML ?','HyperText Markup Language','High Text Machine Language','Hyperlink Tool Modern Language','Home Transfer Markup Language','HTML est le langage de structure des documents web.'],
['Quelle balise crée un lien ?','A','link','href','url','La balise a utilise l’attribut href pour l’adresse de destination.'],
['Quelle balise insère une image ?','img','picture-src','image','media','img utilise l’attribut src pour l’adresse de l’image.'],
['Quelle propriété CSS change la couleur du texte ?','color','text-color','font-colour','foreground','La propriété color définit la couleur du contenu textuel.'],
['Quel symbole introduit un sélecteur de classe en CSS ?','Le point .','Le dièse #','Les deux-points :','Les crochets []','.classe sélectionne les éléments ayant cette classe.'],
['Quel symbole introduit un sélecteur d’identifiant en CSS ?','#','#','*','.', '#identifiant sélectionne un élément unique portant cet identifiant.'],
['Quel modèle organise les contenus en lignes et colonnes ?','Grid ou Flexbox','SQL','FTP','XML','CSS Grid et Flexbox sont les principaux systèmes de mise en page modernes.'],
['Que signifie responsive ?','La page s’adapte à la taille de l’écran','La page répond à toutes les requêtes','Le serveur est rapide','Le code est sécurisé','Le responsive adapte mises en page et composants aux appareils.'],
['Quel outil inspecte le HTML, le CSS et JavaScript dans le navigateur ?','Les outils de développement','Photoshop','Word','phpMyAdmin','Les DevTools permettent d’inspecter, debugger et mesurer une page.'],
['Quelle commande initialise un dépôt Git ?','git init','git start','git create','git new','git init crée le dépôt local.'],
['Quelle commande enregistre une nouvelle version Git ?','git commit','git save','git push','git upload','Chaque commit représente un instantané avec un message.'],
['Quelle commande envoie les commits vers un dépôt distant ?','git push','git send','git upload','git publish','git push transmet les commits vers le serveur distant.'],
['À quoi sert Bootstrap ?','Fournir des styles et composants prêts à l’emploi','Compiler du PHP','Gérer uniquement les bases de données','Créer des fichiers image','Bootstrap accélère la création d’interfaces responsive.'],
['Quelle classe Bootstrap rend une image adaptable ?','img-fluid','image-responsive','responsive-img','img-auto','img-fluid limite la largeur à 100 % tout en gardant les proportions.'],
['Quelle classe Bootstrap affiche un élément en flexbox ?','d-flex','flex-on','display-flex','bs-flex','d-flex applique display: flex.'],
['Quel protocole transport habituellement une page web ?','HTTP ou HTTPS','SMTP','FTP seul','SSH seul','HTTPS est la version sécurisée de HTTP.'],
['Que fait une requête POST depuis JavaScript ?','Elle envoie des données au serveur','Elle ferme le navigateur','Elle supprime HTML et CSS','Elle vide le cache serveur','fetch ou XMLHttpRequest peuvent envoyer des données avec POST.']];
seedModuleQuestions($db,'web-general',$webGeneral);
$illustration = [
['Quel outil permet de dessiner des formes géométriques simples ?','Les formes vectorielles','Le pinceau de perspective','L’outil tampon','La pipette','Les formes permettent de créer rectangles, ellipses et autres tracés.'],
['À quoi sert un calque ?','À séparer les éléments d’une création','À changer la résolution de l’image','À enregistrer le fichier','À imprimer directement','Un calque organise les éléments indépendamment les uns des autres.'],
['Quelle couleur est obtenue en mélangeant rouge et jaune ?','Orange','Vert','Violet','Bleu','Rouge et jaune produisent un orange selon le mélange.'],
['Que désigne le terme vectoriel ?','Une image composée de tracés modifiables','Une photographie compressée','Une couleur d’arrière-plan','Une police de caractères','Les objets vectoriels restent nets lorsqu’ils sont agrandis.'],
['À quoi sert l’outil plume ?','Créer des tracés précis et courbes','Ajouter du bruit à une image','Recadrer automatiquement','Changer le format du fichier','La plume place des points d’ancrage reliés par des courbes.'],
['Que permet la transparence ?','De laisser voir les éléments situés en dessous','De rendre l’image plus nette','De supprimer la couleur','D’imprimer en noir et blanc','L’opacité contrôle la transparence d’un objet ou d’un calque.'],
['Quel format est adapté à une illustration vectorielle ?','SVG','JPEG','MP3','GIF animé seulement','SVG conserve les formes et reste évolutif.'],
['Pourquoi nommer ses calques ?','Pour retrouver rapidement les éléments','Pour réduire la taille du fichier','Pour activer l’impression','Pour convertir les couleurs','Des calques nommés facilitent l’organisation du projet.']];
seedModuleQuestions($db,'illustration',$illustration);
$photoshop = [
['Quel panneau permet de gérer les calques ?','Le panneau Calques','La Bibliothèque de pinceaux','Le Navigateur d’impression','Le Gestionnaire de polices','Le panneau Calques affiche les éléments empilés du document.'],
['Quel outil sélectionne une zone rectangulaire ?','La sélection rectangulaire','Le tampon de duplication','Le pinceau','Le Dégradé','La sélection rectangulaire définit une zone aux angles droits.'],
['À quoi sert un masque de calque ?','Masquer ou révéler une partie sans supprimer les pixels','Changer le nom du fichier','Ajouter du texte automatiquement','Réduire l’historique','Le masque offre une modification non destructive.'],
['Quel espace colorimétrique convient au web ?','RGB','CMYK uniquement','Niveau gris imprimante','Pantone uniquement','RGB combine rouge, vert et bleu pour les écrans.'],
['Que permet le recadrage ?','Supprimer les zones situées hors du cadrage choisi','Dupliquer les calques','Modifier le DPI','Écrire un texte','Le recadrage définit une nouvelle zone visible du document.'],
['À quoi sert la commande Exporter ?','Créer un fichier adapté à son usage','Fermer Photoshop','Supprimer le document original','Créer un nouveau calque','L’exportation produit par exemple une copie JPEG ou PNG.'],
['Quelle fonction corrige automatiquement les imperfections ?','L’outil Correcteur ou Tampon selon le cas','La règle','Le zoom navigateur','Le fichier de police','Ces outils utilisent les pixels voisins pour retoucher.'],
['Quelle résolution convient généralement à une image destinée à l’écran ?','72 ou 96 ppi selon les usages','300 ppi obligatoire','1 ppi','6000 cm','La résolution écran est souvent faible comparée à l’impression.']];
seedModuleQuestions($db,'photoshop',$photoshop);
$word = [
['Quelle fonction modifiera l’apparence de plusieurs titres rapidement ?','Les styles','Les commentaires uniquement','La barre de recherche','L’imprimante','Les styles appliquent une mise en forme cohérente.'],
['À quoi sert un interligne ?','Régler l’espace vertical entre les lignes','Changer le papier','Supprimer les pages','Créer une table','L’interligne améliore la lisibilité du texte.'],
['Quel bouton ajoute des puces ?','Liste à puces','Justifier','Souligner','Encadrer','Une liste à puces présente plusieurs éléments sans ordre numérique.'],
['Comment insérer une image ?','Insertion puis Image','Révision puis Rechercher','Mise en page puis Marges','Accueil puis Police','La commande Image se trouve dans l’onglet Insertion.'],
['À quoi sert un tableau ?','Organiser des données en lignes et colonnes','Modifier les couleurs d’écran','Créer un en-tête uniquement','Chiffrer le document','Les tableaux facilitent la présentation structurée.'],
['Que permet la commande Enregistrer sous ?','Choisir un nouvel emplacement ou format','Imprimer instantanément','Ajouter une image','Effacer le texte','Enregistrer sous permet de dupliquer ou convertir le document.'],
['À quoi servent les marges ?','Définir l’espace entre le texte et les bords de page','Changer la police','Créer des liens','Ajouter un tableau','Les marges définissent la zone imprimable.'],
['Quel groupe permet l’aperçu avant impression ?','Fichier puis Imprimer','Accueil puis Police','Insertion puis Image','Révision puis Langue','La vue imprimante vérifie mise en page et pages avant impression.']];
seedModuleQuestions($db,'word',$word);
$excel = [
['Que contient une cellule ?','Une donnée ou une formule','Toujours une image','Uniquement un texte','Un document Word','Une cellule se trouve à l’intersection d’une ligne et d’une colonne.'],
['Comment commence généralement une formule Excel ?','Avec le signe égal (=)','Avec le dièse (#)','Avec une virgule','Avec le symbole Euro','Les calculs commencent par = puis les fonctions ou références.'],
['Quelle formule additionne une plage de cellules ?','SOMME','MOYENNE','MAXIMUM','GAUCHE','La formule SOMME additionne les valeurs sélectionnées.'],
['Que désigne la référence A1 ?','La cellule de la colonne A et de la ligne 1','La feuille A1','Toute la colonne 1','Une formule de texte','A indique la colonne et 1 indique la ligne.'],
['Quelle fonction calcule une moyenne ?','MOYENNE','SOMME','MIN','NB','MOYENNE additionne les valeurs puis divise par leur nombre.'],
['À quoi sert le format Nombre ?','Afficher et contrôler les valeurs numériques','Changer le nom de la feuille','Ajouter une ligne','Imprimer le classeur','Le format définit les décimales, séparateurs ou unités.'],
['Comment trié une colonne ?','Avec les commandes Trier et filtrer','Avec le bouton Mise en forme conditionnelle','Avec l’outil Dessin','Avec la commande Police','Le tri range les valeurs par ordre croissant ou décroissant.'],
['À quoi sert le filtre ?','Afficher uniquement les lignes répondant à un critère','Supprimer toutes les données','Renommer les cellules','Changer de feuille automatiquement','Le filtre masque temporairement les lignes non sélectionnées.']];
seedModuleQuestions($db,'excel',$excel);
$ppt = [
['Comment démarre-t-on une nouvelle présentation ?','À partir d’un thème ou d’une présentation vierge','Par une feuille Excel','Par un tableau Word','Par un fichier image ZIP','Une présentation peut commencer vide ou avec un thème.'],
['À quoi sert une disposition de diapositive ?','Organiser titre, texte, images et autres contenus','Modifier uniquement la police','Changer l’extension du fichier','Imprimer automatiquement','Les dispositions proposent des emplacements prédéfinis.'],
['Comment insérer une image ?','Insertion puis Images','Accueil puis Police','Révision puis Nouvelle note','Diaporama puis Enregistrer','Image se trouve généralement dans le groupe Insertion.'],
['Que permet un thème ?','Appliquer un ensemble cohérent de couleurs, polices et arrière-plans','Créer uniquement des animations','Convertir le fichier en PDF','Supprimer les diapositives','Un thème unifie l’apparence de toute la présentation.'],
['À quoi sert une animation ?','Animer l’apparition ou le mouvement d’un élément','Changer l’adresse du fichier','Insérer une feuille Excel','Créer une note de bas de page','Les animations contrôlent le comportement des objets pendant le diaporama.'],
['Quel mode permet de présenter en plein écran ?','Le mode Diaporama','Le mode Plan uniquement','Le mode Relecture','Le mode Impression','Le diaporama affiche les diapositives une par une.'],
['Pourquoi limiter le texte par diapositive ?','Pour garder une présentation lisible et concise','Pour réduire obligatoirement le nombre de pages','Pour augmenter la taille du fichier','Pour désactiver les images','Le public doit pouvoir lire et comprendre rapidement.'],
['Quel format exporte une présentation pour empêcher les modifications courantes ?','PDF','DOCX','CSV','PPTX','Le PDF est pratique pour partager une version fixe.']];
seedModuleQuestions($db,'powerpoint',$ppt);

$wordExtra=[
['Quelle combinaison permet de copier un texte sélectionné ?','Ctrl+C','Ctrl+V','Ctrl+X','Ctrl+Z','Le presse-papiers permet de copier le texte.'],
['Quelle commande colle un contenu copié ?','Coller','Couper','Remplacer','Supprimer','Coller insère le contenu du presse-papiers.'],
['À quoi sert la vérification orthographique ?','Corriger les fautes et proposer des suggestions','Modifier les marges','Créer un tableau','Changer le format vidéo','Le vérificateur signale les erreurs de langue.'],
['Quel outil permet de trouver un mot dans le document ?','Rechercher','Insérer','Réviser','Imprimer','La recherche localise du texte dans le document.'],
['Quel outil remplace un mot par un autre ?','Rechercher et remplacer','Styles','Marges','Aperçu','Cette fonction trouve et remplace plusieurs occurrences.'],
['À quoi sert un titre avec un style Heading ?','Structurer le document et faciliter la navigation','Ajouter uniquement une couleur','Insérer une image','Créer une formule','Les titres structurent aussi le plan du document.'],
['Comment insérer un saut de page ?','Insertion de saut de page','Changer la police','Ajouter une puce','Insérer une ligne','Le saut de page commence une nouvelle page.'],
['À quoi sert un pied de page ?','Afficher une information répétée en bas des pages','Remplacer le titre','Cacher le document','Modifier les cellules','Pied de page affiche numéro, logo ou texte répétitif.'],
['Que permet un en-tête ?','Afficher des informations en haut des pages','Supprimer les marges','Créer un graphique','Changer la langue','L’en-tête peut contenir titre ou identification.'],
['Comment aligner un paragraphe au centre ?','Centrage','Justification gauche','Liste à puces','Retrait négatif','Le centrage place le texte au milieu de la zone disponible.'],
['À quoi sert la pagination ?','Numéroter les pages','Ajouter une image','Mettre du texte en gras','Créer une liste','Les numéros de page facilitent la navigation et l’impression.'],
['Quel format document Word courant conserve mise en forme ?','DOCX','TXT seul','CSV','JPEG','DOCX est le format Office éditable le plus courant.']];
ensureTwentyQuestions($db,'word',$wordExtra);

$excelExtra=[
['Quel symbole permet de référencer une autre feuille ?','!','@','#','$','Dans une référence, le nom de feuille précède le !.'],
['À quoi sert le format pourcentage ?','Afficher une valeur sous forme de pourcentage','Créer une liste','Fusionner des cellules','Supprimer les nombres','Le format pourcentage multiplie visuellement la valeur par 100.'],
['Comment fusionner des cellules ?','Fusionner et centrer','Trier','Filtrer','Ajouter une note','La fusion combine des cellules adjacentes.'],
['Quelle commande ajoute une ligne dans un tableau ?','Insérer des lignes','Supprimer les feuilles','Exporter en PDF','Changer la police','Insertion ajoute des éléments à la feuille.'],
['À quoi sert Mise en forme conditionnelle ?','Mettre en évidence des valeurs selon une règle','Changer le nom du classeur','Créer une formule Word','Imprimer automatiquement','Les règles appliquent une couleur selon la valeur.'],
['Quelle fonction compte les cellules numériques ?','NB','SOMME','MOYENNE','GAUCHE','NB compte les nombres d’une plage.'],
['Que représente une plage B2:D10 ?','Toutes les cellules du rectangle B2 à D10','Une seule cellule','Une feuille entière','Une image','Une plage est définie par deux coins séparés par deux-points.'],
['Comment figer la première ligne ?','Figer les volets','Filtrer uniquement','Changer les marges','Supprimer le tableau','Figer les volets garde les en-têtes visibles.'],
['À quoi sert un graphique ?','Visualiser des données','Créer une formule textuelle','Modifier Word','Ajouter une police','Les graphiques rendent les tendances plus faciles à lire.'],
['Comment enregistrer le classeur ?','Ctrl+S','Ctrl+C','Ctrl+P','Alt+F4','Ctrl+S déclenche l’enregistrement.'],
['Quel format universel exporte des données séparées par des points-virgules ou virgules ?','CSV','XLSX','DOCX','PNG','CSV est un format tabulaire de texte simple.'],
['À quoi sert le filtre automatique ?','Afficher les lignes correspondant à des critères','Détruire les autres lignes','Changer les couleurs de police','Créer un fichier PDF','Le filtre masque temporairement les lignes non pertinentes.']];
ensureTwentyQuestions($db,'excel',$excelExtra);

$pptExtra=[
['Comment ajouter une nouvelle diapositive ?','Nouvelle diapositive','Nouvelle feuille','Nouveau mot','Nouvelle cellule','La commande insère une nouvelle page dans la présentation.'],
['À quoi sert le volet Commentaire ?','Ajouter des notes ou remarques','Modifier les pixels','Créer une formule','Changer l’extension','Les commentaires facilitent les échanges et la revue.'],
['Quel outil ajoute du texte à une diapositive ?','Zone de texte','Formule Excel','Masque de calque','Filtre','Une zone de texte permet de placer du contenu.'],
['À quoi servent les notes du présentateur ?','Préparer des indications visibles par le présentateur','Publier du texte sur la diapositive','Supprimer le titre','Chiffrer les images','Les notes servent d’aide pendant la présentation.'],
['Comment lancer un diaporama depuis la première diapositive ?','F5','Ctrl+C','Alt+F4','F8','F5 démarre généralement la présentation depuis la première diapositive.'],
['À quoi sert la disposition ?','Définir l’organisation des éléments de la page','Choisir une police globale','Imprimer automatiquement','Créer une feuille Excel','Une disposition propose titre, contenu et emplacements.'],
['Quel format rend une présentation fixe ?','PDF','PPTX','DOCX','XLSX','PDF conserve une copie stable pour le partage.'],
['Pourquoi utiliser peu de texte ?','Améliorer la lisibilité du public','Augmenter les couleurs','Réduire les marges du fichier','Masquer les images','Le public comprend mieux des messages courts.'],
['À quoi sert la transition ?','Animer le passage d’une diapositive à une autre','Modifier les pixels','Changer de classeur','Créer un tableau Word','Une transition agit au changement de diapositive.'],
['Comment insérer une forme ?','Insertion puis Formes','Révision puis Langue','Accueil puis Police','Fichier puis Nouvelle feuille','La galerie Formes se trouve dans Insertion.'],
['Quelle règle améliore la cohérence visuelle ?','Utiliser un thème et des styles constants','Utiliser des polices différentes partout','Limiter les titres','Supprimer les images','La cohérence facilite la lecture et la mémorisation.'],
['À quoi sert la vue Trieuse de diapositives ?','Voir et réorganiser plusieurs diapositives','Modifier uniquement les pixels','Créer une feuille Excel','Supprimer toute la présentation','La trieuse facilite la réorganisation de la structure.']];
ensureTwentyQuestions($db,'powerpoint',$pptExtra);

$illustrationExtra=[
['À quoi sert une couleur de fond ?','Définir la couleur visible derrière les éléments','Changer le format de fichier','Supprimer tous les calques','Créer une formule','Le fond constitue la surface principale de la création.'],
['Quel format convient à une image sans perte de qualité ?','PNG','MP3','AVI','CSV','PNG conserve bien les détails et la transparence.'],
['Que fait un zoom avant ?','Rend une zone plus proche à l’écran','Augmente la taille du fichier','Modifie les couleurs','Supprime les pixels','Le zoom facilite l’inspection sans changer le document.'],
['À quoi sert l’alignement des objets ?','Les placer précisément les uns par rapport aux autres','Changer le format du logiciel','Ajouter une animation Excel','Imprimer en noir','Les commandes alignent gauche, centre ou droite.'],
['Que permet une copie d’objet ?','Dupliquer un élément','Effacer le fichier','Changer la police système','Créer un masque Word','La duplication conserve les propriétés de l’objet original.'],
['À quoi sert le groupe d’objets ?','Manipuler plusieurs éléments comme un seul ensemble','Supprimer les formes','Modifier les couleurs du bureau','Convertir en texte','Le groupe facilite déplacement, mise à l’échelle et verrouillage.'],
['Que signifie le contour ?','Le trait entourant une forme','La couleur intérieure','La taille de fichier','Le format d’image','Contour désigne le trait appliqué aux bords.'],
['À quoi sert le remplissage ?','Colorer l’intérieur d’une forme','Changer son nom de fichier','Ajouter une formule','Supprimer l’objet','Le remplissage définit la couleur intérieure.'],
['Quelle unité convient aux objets vectoriels ?','Les points d’ancrage et les courbes','Les cellules Excel','Les pixels seulement','Les diapositives Word','Les tracés vectoriels sont constitués de courbes mathématiques.'],
['Pourquoi conserver les calques séparés ?','Modifier chaque élément sans toucher aux autres','Réduire automatiquement le texte','Changer le format du fichier','Imprimer plus vite','Les calques permettent une modification indépendante et non destructive.'],
['Que permet l’export SVG ?','Conserver une illustration vectorielle évolutive','Créer une chanson','Créer un fichier Excel','Compresser en MP3','SVG conserve des tracés adaptables à différentes tailles.'],
['À quoi sert l’outil Sélection ?','Choisir et déplacer des objets','Dessiner des courbes','Changer la résolution','Créer un tableau','L’outil Sélection manipule les objets existants.']];
ensureTwentyQuestions($db,'illustration',$illustrationExtra);

$photoshopExtra=[
['À quoi sert l’outil Déplacement ?','Déplacer un calque ou une sélection','Changer la police','Créer une feuille','Exporter en CSV','Le déplacement modifie l’emplacement des éléments.'],
['Quel outil permet de dessiner un trait ?','Le pinceau','La sélection Word','La formule SOMME','Le tableur','Le pinceau peint sur le calque actif.'],
['À quoi sert la gomme ?','Rendre transparentes les zones effacées','Changer le format du document','Ajouter du texte Word','Créer un graphique Excel','La gomme efface des pixels selon les paramètres du calque.'],
['Quel outil sélectionne une couleur proche ?','La baguette magique','La règle Word','Le filtre Excel','La mise en page','La baguette sélectionne les zones de couleur similaire.'],
['À quoi sert l’historique ?','Revenir à des actions précédentes','Changer le nom du calque','Créer une nouvelle feuille','Exporter en PDF','Le panneau Historique conserve les étapes de la session.'],
['Que permet le recadrage ?','Définir une nouvelle zone visible','Ajouter un calque texte','Changer la police système','Créer une liste','Le recadrage enlève les zones hors du cadrage.'],
['À quoi sert le Dégradé ?','Créer une transition entre plusieurs couleurs','Changer uniquement la taille du fichier','Insérer une cellule Excel','Imprimer automatiquement','Le dégradé mélange les couleurs progressivement.'],
['Quel panneau règle l’opacité d’un calque ?','Le panneau Calques','La police Word','Les cellules Excel','Les commentaires PDF','Le panneau Calques affiche l’opacité et les modes de fusion.'],
['À quoi sert le mode Fusion ?','Définir comment un calque interagit avec les autres','Changer la résolution de l’écran','Ajouter un tableau Word','Supprimer les calques','Les modes de fusion combinent les pixels de plusieurs calques.'],
['Quel format conserve transparence et qualité web ?','PNG','CSV','DOCX','XLS','PNG prend en charge la transparence et offre une bonne qualité web.'],
['À quoi sert la balance des couleurs ?','Corriger les dominantes colorimétriques','Modifier les marges Word','Créer une diapositive','Changer le nom du fichier','La balance ajuste les rouges, verts et bleus de l’image.'],
['Pourquoi utiliser les calques ?','Modifier les éléments séparément sans écraser le reste','Réduire la résolution automatiquement','Ajouter des formules Excel','Changer l’extension en PDF','Les calques permettent des retouches indépendantes et réversibles.']];
ensureTwentyQuestions($db,'photoshop',$photoshopExtra);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$a = $_GET['a'] ?? '';

// Mêmes calculs que le JavaScript (nm, hs, mkid) pour vérifier les identifiants.
function nm(string $t): string {
    if (class_exists('Normalizer')) { $n = Normalizer::normalize($t, Normalizer::FORM_D); if ($n !== false) $t = $n; }
    $t = mb_strtolower($t, 'UTF-8');
    if (!class_exists('Normalizer')) $t = strtr($t, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ÿ'=>'y']);
    $t = preg_replace('/[\x{0300}-\x{036f}]/u', '', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}
function hs(string $t): string {
    $x = 2166136261;
    foreach (unpack('v*', mb_convert_encoding($t, 'UTF-16LE', 'UTF-8')) as $c) { $x ^= $c; $x = ($x * 16777619) & 0xFFFFFFFF; }
    return strtoupper(base_convert((string)$x, 10, 36));
}
function mkid(string $n): string { return 'AP-' . hs(nm($n) . '|id|' . SALT); }
function staffRole(): string { return !empty($_SESSION['staff_role']) ? (string)$_SESSION['staff_role'] : 'none'; }
function role(): string {
    if (!empty($_SESSION['uid'])) return 'learner';
    if (empty($_SESSION['admin'])) return 'none';
    $staffRole = staffRole();
    return $staffRole === 'formateur' ? 'formateur' : ($staffRole === 'admin' ? 'admin' : 'none');
}
function canManage(): bool { return !empty($_SESSION['admin']) && in_array(staffRole(), ['admin', 'formateur'], true); }
function canTrainer(): bool { return !empty($_SESSION['admin']) && staffRole() === 'formateur'; }
function canAdmin(): bool { return !empty($_SESSION['admin']) && staffRole() === 'admin'; }
function canReadStaff(): bool { return !empty($_SESSION['admin']) && in_array(staffRole(), ['admin', 'formateur'], true); }
function currentStaffId(PDO $db): int {
    $username = (string)($_SESSION['staff_username'] ?? '');
    if ($username === '') return 0;
    $s = $db->prepare('SELECT id FROM staff WHERE LOWER(username)=LOWER(?) LIMIT 1');
    $s->execute([$username]);
    $id = $s->fetchColumn();
    return $id === false ? 0 : (int)$id;
}
function auditLog(PDO $db, string $action, string $resourceType, string $resourceId = '', array $details = []): void {
    if (!canAdmin() && !canTrainer()) return;
    $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) throw new RuntimeException('Unable to encode audit details');
    $s = $db->prepare('INSERT INTO audit_log(actor_staff_id,actor_username,actor_role,action,resource_type,resource_id,details,created_at) VALUES(?,?,?,?,?,?,?,?)');
    $s->execute([currentStaffId($db), (string)($_SESSION['staff_username'] ?? ''), staffRole(), $action, $resourceType, mb_substr($resourceId, 0, 200), mb_substr($encoded, 0, 4000), gmdate('c')]);
}
function trainerCanFormation(PDO $db, int $staffId, string $formationId): bool {
    $s = $db->prepare('SELECT 1 FROM staff_formations sf JOIN formations f ON f.id=sf.formation_id WHERE sf.staff_id=? AND sf.formation_id=? AND f.active=1 LIMIT 1');
    $s->execute([$staffId, $formationId]);
    return (bool)$s->fetchColumn();
}
function trainerOwnsLearner(PDO $db, int $staffId, int $learnerId): bool {
    $s = $db->prepare('SELECT a.id FROM learner_accounts a JOIN learners l ON l.learner_uid=a.learner_uid JOIN staff_formations sf ON sf.staff_id=a.trainer_staff_id AND sf.formation_id=a.formation_id WHERE l.id=? AND a.trainer_staff_id=? LIMIT 1');
    $s->execute([$learnerId, $staffId]);
    return (bool)$s->fetchColumn();
}
// Importe les fichiers banks/*.php dans la table questions (une seule fois par fichier, sans doublons).
function importQuestionBanks(PDO $db): void {
    foreach (glob(__DIR__ . '/banks/*.php') ?: [] as $file) {
        $flag = 'bank_' . basename($file) . '_' . md5_file($file);
        if ((string)cfg($db, $flag) === '1') continue;
        $bank = require $file;
        if (!is_array($bank)) continue;
        $db->beginTransaction();
        try {
            $levelStmt = $db->prepare('SELECT module_id FROM test_levels WHERE id=?');
            $dup = $db->prepare('SELECT 1 FROM questions WHERE level_id=? AND question=?');
            $insQ = $db->prepare('INSERT INTO questions(module_id,question,type,points,explanation,active,position,level_id) VALUES(?,?,?,?,?,1,?,?)');
            $insA = $db->prepare('INSERT INTO answers(question_id,answer,is_correct,position) VALUES(?,?,?,?)');
            $position = [];
            foreach ($bank as $item) {
                $levelId = (string)($item['level'] ?? ''); $text = trim((string)($item['q'] ?? '')); $choices = $item['a'] ?? [];
                if ($levelId === '' || $text === '' || !is_array($choices) || count($choices) < 2) continue;
                $levelStmt->execute([$levelId]); $moduleId = $levelStmt->fetchColumn();
                if ($moduleId === false) continue;
                $dup->execute([$levelId, $text]); if ($dup->fetchColumn()) continue;
                $position[$levelId] = ($position[$levelId] ?? 0) + 1;
                $insQ->execute([$moduleId, $text, 'single', 1, (string)($item['e'] ?? ''), $position[$levelId], $levelId]);
                $qid = (int)$db->lastInsertId();
                foreach (array_values($choices) as $i => $choice) $insA->execute([$qid, (string)$choice, $i === 0 ? 1 : 0, $i]);
            }
            setCfg($db, $flag, '1');
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); error_log('qcm bank: ' . $e->getMessage()); }
    }
}
function trainerOwnsLearnerUid(PDO $db, int $staffId, string $uid): bool {
    $s = $db->prepare('SELECT 1 FROM learner_accounts a JOIN staff_formations sf ON sf.staff_id=a.trainer_staff_id AND sf.formation_id=a.formation_id WHERE a.learner_uid=? AND a.trainer_staff_id=? LIMIT 1');
    $s->execute([$uid, $staffId]);
    return (bool)$s->fetchColumn();
}
const QUESTIONS_PER_ATTEMPT = 25;
// Questions envoyées au navigateur : sans bonnes réponses ni explications, sauf en entraînement.
function publicQuestions(array $snapshot, bool $reveal): array {
    $out = [];
    foreach ($snapshot as $q) {
        $answers = [];
        foreach (($q['answers'] ?? []) as $a) {
            $item = ['id' => $a['id'], 'answer' => $a['answer'], 'position' => $a['position'] ?? 0];
            if ($reveal) $item['is_correct'] = $a['is_correct'] ?? 0;
            $answers[] = $item;
        }
        $row = ['id' => $q['id'], 'question' => $q['question'], 'type' => $q['type'] ?? 'single', 'points' => $q['points'] ?? 1, 'answers' => $answers];
        if ($reveal) $row['explanation'] = $q['explanation'] ?? '';
        $out[] = $row;
    }
    return $out;
}
function levelSnapshot(PDO $db, string $moduleId, string $levelId): array {
    $levelPos = 0;
    $level = $db->prepare('SELECT position,name FROM test_levels WHERE id=? AND module_id=?');
    $level->execute([$levelId, $moduleId]); $levelRow = $level->fetch(PDO::FETCH_ASSOC);
    if ($levelRow) $levelPos = (int)$levelRow['position'];
    $own = $db->prepare('SELECT id,question,type,points,explanation FROM questions WHERE module_id=? AND level_id=? AND active=1 ORDER BY position,id');
    $own->execute([$moduleId, $levelId]); $ownRows = $own->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->prepare('SELECT id,question,type,points,explanation FROM questions WHERE module_id=? AND active=1 ORDER BY position,id');
    $q->execute([$moduleId]); $all = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($ownRows) { shuffle($ownRows); $all = array_slice($ownRows, 0, QUESTIONS_PER_ATTEMPT); }
    elseif (substr($levelId, 0, 6) === 'level-') {
        $selected = [];
        foreach ($all as $index => $question) if (($index % 6) === ($levelPos % 6)) $selected[] = $question;
        $all = $selected;
    }
    foreach ($all as &$question) {
        $a = $db->prepare('SELECT id,answer,is_correct,position FROM answers WHERE question_id=? ORDER BY position,id');
        $a->execute([(int)$question['id']]); $question['answers'] = $a->fetchAll(PDO::FETCH_ASSOC);
        shuffle($question['answers']);
        foreach ($question['answers'] as $pos => &$shuffled) $shuffled['position'] = $pos;
        unset($shuffled);
    }
    unset($question);
    return $all;
}
function ensureProgress(PDO $db, string $uid, string $levelId, string $moduleId, string $formationId): array {
    $level = $db->prepare('SELECT attempts_allowed,pass_percent FROM test_levels WHERE id=?'); $level->execute([$levelId]); $levelRow = $level->fetch(PDO::FETCH_ASSOC);
    $allowed = $levelRow ? (int)$levelRow['attempts_allowed'] : 3; $threshold = $levelRow ? (int)$levelRow['pass_percent'] : 90;
    // SQLite RETURNING and some older SQLite builds are not reliable: use an explicit read/insert.
    $check = $db->prepare('SELECT * FROM level_progress WHERE learner_uid=? AND level_id=?'); $check->execute([$uid,$levelId]); $progress = $check->fetch(PDO::FETCH_ASSOC);
    if (!$progress) { $ins = $db->prepare('INSERT INTO level_progress(learner_uid,level_id,module_id,formation_id,attempts_used,extra_attempts,attempts_allowed,best_percentage,validated,updated_at) VALUES(?,?,?,?,0,0,?,-1,0,?)'); $ins->execute([$uid,$levelId,$moduleId,$formationId,$allowed,gmdate('c')]); $check->execute([$uid,$levelId]); $progress = $check->fetch(PDO::FETCH_ASSOC); }
    $progress['_threshold'] = $threshold;
    return $progress;
}
function levelIsOfficiallyValidated(array $progress, int $threshold): bool {
    if ((int)($progress['validated'] ?? 0) !== 1) return false;
    return (int)($progress['best_percentage'] ?? -1) >= $threshold;
}
function projectRubric(string $formationId): array {
    $rubrics = [
        'bureautique' => ['word'=>'Projet Word','excel'=>'Projet Excel','powerpoint'=>'Projet PowerPoint','presentation'=>'Présentation orale','jury'=>'Questions jury'],
        'infographie' => ['logo'=>'Logo','charte'=>'Charte graphique','carte_visite'=>'Carte de visite','affiche'=>'Affiche','flyer'=>'Flyer','etiquette'=>'Étiquette','mockup'=>'Mockup','portfolio'=>'Portfolio','presentation'=>'Présentation'],
        'developpement-web' => ['site1'=>'Site 1','site2'=>'Site 2','site3'=>'Site 3','soutenance'=>'Présentation + questions jury']
    ];
    return $rubrics[$formationId] ?? [];
}
function readProjectGrades(PDO $db, string $uid, string $formationId): array {
    $rubric = projectRubric($formationId); $scores = array_fill_keys(array_keys($rubric), null);
    $s = $db->prepare('SELECT data FROM kv WHERE path=?'); $s->execute(['project_grades/'.$uid]); $raw = $s->fetchColumn();
    if ($raw !== false) {
        $stored = json_decode((string)$raw, true);
        if (is_array($stored) && ($stored['formation_id'] ?? '') === $formationId && is_array($stored['scores'] ?? null)) {
            foreach ($scores as $key => $_) if (array_key_exists($key, $stored['scores']) && is_numeric($stored['scores'][$key]) && (float)$stored['scores'][$key] >= 0 && (float)$stored['scores'][$key] <= 20) $scores[$key] = (float)$stored['scores'][$key];
        }
    }
    $graded = array_filter($scores, static function ($score) { return $score !== null; });
    $presentKeys = $formationId === 'bureautique' ? ['presentation','jury'] : ($formationId === 'developpement-web' ? ['soutenance'] : ['presentation']);
    $present = array_filter(array_intersect_key($scores, array_flip($presentKeys)), static function ($score) { return $score !== null; });
    return ['rubric'=>$rubric,'scores'=>$scores,'graded_count'=>count($graded),'rubric_count'=>count($rubric),'complete'=>count($rubric)>0 && count($graded)===count($rubric),'project_score'=>$graded ? round(array_sum($graded)/count($graded),2) : 0,'presentation_score'=>$present ? round(array_sum($present)/count($present),2) : 0];
}
function calculateTestGrade(PDO $db, string $uid, string $formationId): array {
    $formation = $db->prepare('SELECT name FROM formations WHERE id=?'); $formation->execute([$formationId]); $formationName = (string)($formation->fetchColumn() ?: '');
    $levelsQuery = $db->prepare('SELECT id,name,position,pass_percent FROM test_levels WHERE formation_id=? AND active=1 ORDER BY position,id');
    $levelsQuery->execute([$formationId]); $levels = $levelsQuery->fetchAll(PDO::FETCH_ASSOC); $validatedCount = 0; $levelStates = [];
    foreach ($levels as $level) {
        $progressQuery = $db->prepare('SELECT validated,manual_unlock,best_percentage FROM level_progress WHERE learner_uid=? AND level_id=?');
        $progressQuery->execute([$uid, (string)$level['id']]); $progress = $progressQuery->fetch(PDO::FETCH_ASSOC) ?: [];
        $threshold = max(0, (int)$level['pass_percent']); $validated = levelIsOfficiallyValidated($progress, $threshold);
        if ($validated) $validatedCount++;
        $levelStates[] = ['level_id'=>(string)$level['id'],'name'=>(string)$level['name'],'validated'=>$validated,'manual_unlock'=>(int)($progress['manual_unlock']??0)===1,'best_percentage'=>(int)($progress['best_percentage']??-1),'threshold'=>$threshold];
    }
    $stepCount = count($levels); $pathwayComplete = $stepCount === 6 && $validatedCount === 6;
    $pathwayScore = $pathwayComplete ? 10 : 0;
    $exam = ['score'=>0,'status'=>'not_taken','passed'=>false,'percentage'=>null,'threshold'=>90,'attempt_id'=>null,'level_name'=>$stepCount ? (string)$levels[$stepCount-1]['name'] : null];
    if ($stepCount > 0) {
        $finalLevel = $levels[$stepCount-1]; $exam['threshold'] = max(0, (int)$finalLevel['pass_percent']);
        $attemptQuery = $db->prepare('SELECT id,status,score,total,percentage,submitted_at,started_at FROM test_attempts WHERE learner_uid=? AND formation_id=? AND level_id=? AND mode="examen" AND status="submitted" ORDER BY submitted_at DESC,id DESC LIMIT 1');
        $attemptQuery->execute([$uid,$formationId,(string)$finalLevel['id']]); $attempt = $attemptQuery->fetch(PDO::FETCH_ASSOC);
        $activeQuery = $db->prepare('SELECT id,started_at FROM test_attempts WHERE learner_uid=? AND formation_id=? AND level_id=? AND mode="examen" AND status="started" ORDER BY started_at DESC,id DESC LIMIT 1');
        $activeQuery->execute([$uid,$formationId,(string)$finalLevel['id']]); $activeAttempt = $activeQuery->fetch(PDO::FETCH_ASSOC);
        if ($activeAttempt && (!$attempt || (string)$activeAttempt['started_at'] > (string)$attempt['submitted_at'])) { $exam['attempt_id']=(string)$activeAttempt['id']; $exam['status']='in_progress'; }
        elseif ($attempt) {
            $exam['attempt_id'] = (string)$attempt['id'];
            if ((int)$attempt['total'] > 0) { $exam['percentage'] = (int)$attempt['percentage']; $exam['passed'] = (int)$attempt['score']*100 >= $exam['threshold']*(int)$attempt['total']; $exam['status'] = $exam['passed'] ? 'passed' : 'failed'; $exam['score'] = $exam['passed'] ? 10 : 0; }
        }
    }
    $project = readProjectGrades($db,$uid,$formationId); $testsTotal=$pathwayScore+(int)$exam['score']; $finalAverage=round(($testsTotal+$project['project_score'])/2,2);
    $mention=$finalAverage>=16?'Très Bien':($finalAverage>=14?'Bien':($finalAverage>=12?'Assez Bien':($finalAverage>=10?'Passable':'Insuffisant')));
    return ['learner_uid'=>$uid,'formation_id'=>$formationId,'formation_name'=>$formationName,'pathway'=>['score'=>$pathwayScore,'complete'=>$pathwayComplete,'validated_count'=>$validatedCount,'total_steps'=>$stepCount,'levels'=>$levelStates],'final_exam'=>$exam,'tests_total'=>$testsTotal,'project_score'=>$project['project_score'],'presentation_score'=>$project['presentation_score'],'project'=>$project,'final_average'=>$finalAverage,'mention'=>$mention,'total'=>$testsTotal,'maximum'=>20];
}
function activeExam(PDO $db, string $levelId): array {
    $s = $db->prepare('SELECT * FROM exam_configs WHERE level_id=? LIMIT 1'); $s->execute([$levelId]); $exam = $s->fetch(PDO::FETCH_ASSOC);
    if (!$exam) return ['exists'=>false,'active'=>false,'state'=>'desactive'];
    $now = time(); $start = $exam['starts_at'] ? strtotime((string)$exam['starts_at']) : null; $close = $exam['closes_at'] ? strtotime((string)$exam['closes_at']) : null;
    if ((int)$exam['active'] === 1 && $start !== null && $start <= $now && ($close === null || $close >= $now)) $exam['state'] = 'actif'; elseif ((int)$exam['active'] === 1 && $close !== null && $close < $now) $exam['state'] = 'ferme'; elseif ((int)$exam['active'] === 1 && $start !== null && $start > $now) $exam['state'] = 'programme'; elseif ((int)$exam['active'] === 0) $exam['state'] = 'desactive';
    $exam['_exists'] = true; $exam['_active_for_new'] = (int)$exam['active'] === 1 && ($start === null || $start <= $now) && ($close === null || $close >= $now);
    return $exam;
}
function path_of(array $in): string {
    $p = (string)($in['path'] ?? '');
    if (!preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $p)) out(['error' => 'bad_path']);
    return $p;
}
function allowed(PDO $db, string $p, bool $write = false): bool {
    if (canAdmin()) return true;
    if (!preg_match('#^results/([A-Za-z0-9_-]+)$#', $p, $m)) return false;
    if (role() === 'learner') return hash_equals((string)$_SESSION['uid'], $m[1]);
    return !$write && canTrainer() && trainerOwnsLearnerUid($db, currentStaffId($db), $m[1]);
}
function cfg(PDO $db, string $k) { $s = $db->prepare('SELECT v FROM cfg WHERE k=?'); $s->execute([$k]); return $s->fetchColumn(); }
function setCfg(PDO $db, string $k, string $v): void { $s = $db->prepare('INSERT INTO cfg(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v'); $s->execute([$k, $v]); }
function clearRecoverableCredentials(PDO $db): void {
    if ((string)cfg($db, 'credential_cipher_cleanup_v1') === '1') return;
    $db->beginTransaction();
    try {
        $db->exec('UPDATE staff SET credential_cipher="" WHERE credential_cipher<>""');
        $db->exec('UPDATE learner_accounts SET credential_cipher="" WHERE credential_cipher<>""');
        setCfg($db, 'credential_cipher_cleanup_v1', '1');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $keyFile = __DIR__ . '/data/.credential_key';
    if (is_file($keyFile) && !unlink($keyFile)) error_log('qcm: unable to remove obsolete credential key');
}
function enforceTemporaryPasswordChange(PDO $db, string $action): void {
    if (!empty($_SESSION['admin']) && !empty($_SESSION['staff_username'])) {
        $s = $db->prepare('SELECT must_change_password FROM staff WHERE LOWER(username)=LOWER(?) LIMIT 1');
        $s->execute([(string)$_SESSION['staff_username']]);
        $mustChange = $s->fetchColumn();
        if ($mustChange !== false) $_SESSION['must_change_password'] = (int)$mustChange === 1;
    } elseif (!empty($_SESSION['uid'])) {
        $s = $db->prepare('SELECT must_change_password FROM learner_accounts WHERE learner_uid=? AND active=1 LIMIT 1');
        $s->execute([(string)$_SESSION['uid']]);
        $mustChange = $s->fetchColumn();
        if ($mustChange !== false) $_SESSION['must_change_password'] = (int)$mustChange === 1;
    }
    if (empty($_SESSION['must_change_password'])) return;
    if (!in_array($action, ['whoami', 'public_settings', 'change_temporary_password', 'logout', 'logout_admin', 'logout_learner'], true)) {
        out(['error' => 'password_change_required', 'msg' => 'Changez votre mot de passe temporaire avant de continuer.']);
    }
}
clearRecoverableCredentials($db);
enforceTemporaryPasswordChange($db, (string)$a);

switch ($a) {
case 'whoami':
    $formations = [];
    $learnerIdentifier = null;
    if (role() === 'learner') {
        $identifierQuery = $db->prepare('SELECT identifier FROM learner_accounts WHERE learner_uid=? AND active=1 LIMIT 1');
        $identifierQuery->execute([(string)$_SESSION['uid']]);
        $learnerIdentifier = $identifierQuery->fetchColumn() ?: null;
    }
    if (!empty($_SESSION['admin']) && !empty($_SESSION['staff_username'])) {
        $sf = $db->prepare('SELECT formation_id FROM staff_formations sf JOIN staff st ON st.id=sf.staff_id WHERE LOWER(st.username)=LOWER(?)');
        $sf->execute([$_SESSION['staff_username']]);
        $formations = array_column($sf->fetchAll(PDO::FETCH_ASSOC), 'formation_id');
    }
    out(['role' => role(), 'staff_role' => staffRole(), 'uid' => $_SESSION['uid'] ?? null, 'name' => $_SESSION['name'] ?? null, 'identifier' => $learnerIdentifier, 'formation_id' => $_SESSION['formation_id'] ?? null, 'must_change_password' => !empty($_SESSION['must_change_password']), 'formations' => $formations]);

case 'public_settings':
    out(['public_registration' => (cfg($db, 'public_registration') ?? '0') === '1']);

case 'account_register':
    // Les comptes apprenants sont créés exclusivement par les formateurs autorisés.
    out(['ok' => false, 'msg' => 'Les comptes apprenants sont créés uniquement par les formateurs.']);
    $first = trim(preg_replace('/\s+/u', ' ', (string)($in['first_name'] ?? ''))); $last = trim(preg_replace('/\s+/u', ' ', (string)($in['last_name'] ?? ''))); $email = strtolower(trim((string)($in['email'] ?? ''))); $phone = trim((string)($in['phone'] ?? '')); $password = (string)($in['password'] ?? ''); $confirm = (string)($in['confirm'] ?? '');
    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) out(['ok' => false, 'msg' => 'Vérifiez votre prénom, votre nom et votre adresse e-mail.']);
    if (strlen($password) < 12 || !hash_equals($password, $confirm)) out(['ok' => false, 'msg' => 'Le mot de passe doit contenir au moins 12 caractères et les deux champs doivent être identiques.']);
    $name = $first . ' ' . $last; $normalized = nm($name); $uid = substr(sha1($normalized . '|account|' . $email), 0, 16);
    try { $s = $db->prepare('INSERT INTO learner_accounts(learner_uid,first_name,last_name,email,phone,password_hash,active,created) VALUES(?,?,?,?,?,?,1,?)'); $s->execute([$uid,$first,$last,$email,$phone,password_hash($password, PASSWORD_DEFAULT),gmdate('c')]); }
    catch (PDOException $e) { out(['ok' => false, 'msg' => 'Cette adresse e-mail est déjà utilisée.']); }
    session_regenerate_id(true); $_SESSION['uid'] = $uid; $_SESSION['name'] = $name; out(['ok' => true, 'name' => $name]);

case 'account_login':
    out(['ok' => false, 'deprecated' => true, 'msg' => 'Utilisez la connexion centrale.']);

case 'learner_dashboard':
    if (role() !== 'learner') out(['error' => 'forbidden']);
    $uid = (string)($_SESSION['uid'] ?? ''); $s = $db->prepare('SELECT e.id,e.formation_id,f.name formation_name,e.module_id,m.name module_name,e.score,e.total,e.percentage,e.duration_seconds,e.created FROM evaluations e LEFT JOIN formations f ON f.id=e.formation_id LEFT JOIN modules m ON m.id=e.module_id WHERE e.learner_uid=? ORDER BY e.created DESC LIMIT 100'); $s->execute([$uid]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $formations = $db->query('SELECT COUNT(*) FROM formations WHERE active=1')->fetchColumn(); out(['test_count' => count($rows), 'formation_count' => (int)$formations, 'latest' => array_slice($rows, 0, 5), 'history' => $rows]);

case 'test_grades':
    if (role() === 'learner') {
        $uid = (string)($_SESSION['uid'] ?? ''); $formationId = (string)($_SESSION['formation_id'] ?? '');
        if ($uid === '' || $formationId === '') out(['error'=>'not_found']);
        out(['grade'=>calculateTestGrade($db,$uid,$formationId)]);
    }
    if (!canReadStaff()) out(['error'=>'forbidden']);
    $sql = 'SELECT a.learner_uid,a.first_name,a.last_name,a.formation_id FROM learner_accounts a WHERE 1=1'; $params = [];
    if (canTrainer()) { $sql .= ' AND EXISTS (SELECT 1 FROM staff_formations sf WHERE sf.staff_id=a.trainer_staff_id AND sf.formation_id=a.formation_id) AND a.trainer_staff_id=?'; $params[] = currentStaffId($db); }
    $sql .= ' ORDER BY a.last_name COLLATE NOCASE,a.first_name COLLATE NOCASE'; $learners = $db->prepare($sql); $learners->execute($params); $grades = [];
    foreach ($learners->fetchAll(PDO::FETCH_ASSOC) as $learner) { $grade=calculateTestGrade($db,(string)$learner['learner_uid'],(string)$learner['formation_id']); $grade['learner_name']=trim((string)$learner['first_name'].' '.(string)$learner['last_name']); $grades[]=$grade; }
    out(['grades'=>$grades]);

case 'project_grades_get':
    $uid = trim((string)($in['learner_uid'] ?? ''));
    if (role() === 'learner') $uid = (string)($_SESSION['uid'] ?? '');
    elseif (!canReadStaff()) out(['error'=>'forbidden']);
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $uid)) out(['error'=>'bad_data']);
    $learner = $db->prepare('SELECT learner_uid,formation_id FROM learner_accounts WHERE learner_uid=? LIMIT 1'); $learner->execute([$uid]); $target = $learner->fetch(PDO::FETCH_ASSOC);
    if (!$target) out(['error'=>'not_found']);
    if (canTrainer() && !trainerOwnsLearnerUid($db,currentStaffId($db),$uid)) out(['error'=>'forbidden']);
    $project=readProjectGrades($db,$uid,(string)$target['formation_id']);
    out(['learner_uid'=>$uid,'formation_id'=>(string)$target['formation_id']]+$project);

case 'project_grades_set':
    if (!canManage()) out(['error'=>'forbidden']);
    $uid=trim((string)($in['learner_uid']??'')); $inputScores=$in['scores']??null;
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/',$uid) || !is_array($inputScores)) out(['error'=>'bad_data']);
    $learner=$db->prepare('SELECT learner_uid,formation_id,active FROM learner_accounts WHERE learner_uid=? LIMIT 1');$learner->execute([$uid]);$target=$learner->fetch(PDO::FETCH_ASSOC);
    if(!$target)out(['error'=>'not_found']);
    if((int)$target['active']!==1)out(['error'=>'bad_data','msg'=>'Un compte désactivé ne peut pas être noté.']);
    $formationId=(string)$target['formation_id'];$rubric=projectRubric($formationId);
    if(!$rubric)out(['error'=>'bad_data','msg'=>'Formation non prise en charge pour la saisie des projets.']);
    if(canTrainer()&&!trainerOwnsLearnerUid($db,currentStaffId($db),$uid))out(['error'=>'forbidden']);
    foreach($inputScores as $key=>$value){
        if(!is_string($key)||!array_key_exists($key,$rubric))out(['error'=>'bad_data','msg'=>'Rubrique de note inconnue.']);
        if($value===null||$value==='')continue;
        if(!is_numeric($value)||(float)$value<0||(float)$value>20)out(['error'=>'bad_data','msg'=>'Chaque note doit être comprise entre 0 et 20.']);
    }
    $old=readProjectGrades($db,$uid,$formationId);$scores=$old['scores'];
    foreach($inputScores as $key=>$value)$scores[$key]=($value===null||$value==='')?null:round((float)$value,2);
    $data=json_encode(['formation_id'=>$formationId,'scores'=>$scores],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $db->beginTransaction();
    try{$save=$db->prepare('INSERT INTO kv(path,data,updated) VALUES(?,?,?) ON CONFLICT(path) DO UPDATE SET data=excluded.data,updated=excluded.updated');$save->execute(['project_grades/'.$uid,$data,gmdate('c')]);auditLog($db,'project_grades_update','learner',$uid,['before'=>$old['scores'],'after'=>$scores]);$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $project=readProjectGrades($db,$uid,$formationId);$test=calculateTestGrade($db,$uid,$formationId);
    out(['ok'=>true,'learner_uid'=>$uid,'formation_id'=>$formationId]+$project+['tests_total'=>$test['tests_total'],'final_average'=>$test['final_average'],'mention'=>$test['mention']]);

case 'formations_list':
    if (role() === 'learner') {
        $formationId = (string)($_SESSION['formation_id'] ?? '');
        if ($formationId === '') out(['formations' => []]);
        $s = $db->prepare('SELECT id,name,description,icon,position FROM formations WHERE active=1 AND id=? ORDER BY position,name'); $s->execute([$formationId]);
    } elseif (canTrainer()) {
        $s = $db->prepare('SELECT f.id,f.name,f.description,f.icon,f.position FROM formations f JOIN staff_formations sf ON sf.formation_id=f.id WHERE f.active=1 AND sf.staff_id=? ORDER BY f.position,f.name'); $s->execute([currentStaffId($db)]);
    } elseif (canReadStaff()) {
        $s = $db->query('SELECT id,name,description,icon,position FROM formations WHERE active=1 ORDER BY position,name');
    } else {
        out(['error' => 'authentication_required']);
    }
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$formation) {
        $m = $db->prepare('SELECT id,name,description,position,duration_minutes,question_count,pass_percent FROM modules WHERE formation_id=? AND active=1 ORDER BY position,name');
        $m->execute([$formation['id']]); $formation['modules'] = $m->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($formation); out(['formations' => $rows]);

case 'evaluations_list':
    if (!canReadStaff()) out(['error' => 'forbidden']);
    $formation = trim((string)($in['formation_id'] ?? '')); $module = trim((string)($in['module_id'] ?? ''));
    $sql = 'SELECT e.id,e.learner_name,e.formation_id,f.name AS formation_name,e.module_id,m.name AS module_name,e.mode,e.score,e.total,e.percentage,e.duration_seconds,e.created FROM evaluations e LEFT JOIN formations f ON f.id=e.formation_id LEFT JOIN modules m ON m.id=e.module_id WHERE 1=1'; $params = [];
    if (canTrainer()) {
        $staffId = currentStaffId($db);
        $sql .= ' AND EXISTS (SELECT 1 FROM learner_accounts la WHERE la.learner_uid=e.learner_uid AND la.trainer_staff_id=? ) AND EXISTS (SELECT 1 FROM staff_formations sf WHERE sf.staff_id=? AND sf.formation_id=e.formation_id)';
        $params[] = $staffId; $params[] = $staffId;
    }
    if ($formation !== '') { $sql .= ' AND e.formation_id=?'; $params[] = $formation; }
    if ($module !== '') { $sql .= ' AND e.module_id=?'; $params[] = $module; }
    $sql .= ' ORDER BY e.created DESC LIMIT 500'; $s = $db->prepare($sql); $s->execute($params);
    out(['rows' => $s->fetchAll(PDO::FETCH_ASSOC)]);

case 'evaluation_delete':
    if (!canAdmin()) out(['error' => 'forbidden']);
    out(['error' => 'result_immutable', 'msg' => 'Les résultats soumis sont conservés et ne peuvent pas être supprimés.']);

case 'test_levels_list':
    if (!in_array(role(), ['learner','admin','formateur'], true)) out(['error'=>'authentication_required']);
    $formationId = trim((string)($in['formation_id'] ?? ($_SESSION['formation_id'] ?? ''))); $moduleId = trim((string)($in['module_id'] ?? ''));
    $sql='SELECT l.*,m.name AS module_name,m.duration_minutes,m.question_count FROM test_levels l JOIN modules m ON m.id=l.module_id WHERE l.active=1'; $params=[];
    if ($formationId !== '') { $sql.=' AND l.formation_id=?'; $params[]=$formationId; }
    if ($moduleId !== '') { $sql.=' AND l.module_id=?'; $params[]=$moduleId; }
    if (role()==='learner') { $own=(string)($_SESSION['formation_id'] ?? ''); if ($own==='') out(['levels'=>[]]); $formationId=$own; $sql='SELECT l.*,m.name AS module_name,m.duration_minutes,m.question_count FROM test_levels l JOIN modules m ON m.id=l.module_id WHERE l.active=1 AND l.formation_id=?'; $params=[$own]; if($moduleId!==''){$sql.=' AND l.module_id=?';$params[]=$moduleId;} }
    if (canTrainer()) { $staffId=currentStaffId($db); $sql.=' AND EXISTS (SELECT 1 FROM staff_formations sf WHERE sf.staff_id=? AND sf.formation_id=l.formation_id)'; $params[]=$staffId; }
    $sql.=' ORDER BY l.formation_id,l.position,l.name'; $s=$db->prepare($sql); $s->execute($params); $levels=$s->fetchAll(PDO::FETCH_ASSOC);
    $uid=(string)($_SESSION['uid'] ?? ''); $levelsOut=[];
    foreach($levels as $level) {
        $progress=['attempts_used'=>0,'extra_attempts'=>0,'attempts_allowed'=>(int)$level['attempts_allowed'],'best_percentage'=>-1,'validated'=>0,'validated_at'=>null];
        if($uid!==''){$p=$db->prepare('SELECT * FROM level_progress WHERE learner_uid=? AND level_id=?');$p->execute([$uid,$level['id']]);$found=$p->fetch(PDO::FETCH_ASSOC);if($found){foreach(['attempts_used','extra_attempts','attempts_allowed','best_percentage','validated','manual_unlock'] as $numField)if(isset($found[$numField]))$found[$numField]=(int)$found[$numField];$progress=$found;}}
        $progress['official_validated']=levelIsOfficiallyValidated($progress,(int)$level['pass_percent']);$progress['validated']=$progress['official_validated']?1:0;
        $exam=activeExam($db,(string)$level['id']);
        $levelsOut[]=array_merge($level,['progress'=>$progress,'exam'=>$exam]);
    }
    out(['levels'=>$levelsOut]);

case 'test_attempt_start':
    if(role()!== 'learner') out(['error'=>'forbidden']);
    $levelId=trim((string)($in['level_id'] ?? '')); $mode=trim((string)($in['mode'] ?? ''));
    if(!in_array($mode,['parcours','entrainement','examen'],true)) out(['error'=>'bad_data']);
    $l=$db->prepare('SELECT * FROM test_levels WHERE id=? AND active=1');$l->execute([$levelId]);$level=$l->fetch(PDO::FETCH_ASSOC);
    if(!$level) out(['error'=>'not_found']);
    $formationId=(string)($_SESSION['formation_id'] ?? '');
    if ($formationId === '') out(['error'=>'formation_unavailable','msg'=>'Aucune formation active n’est associée à ce compte.']);
    $learnerFormation=$db->prepare('SELECT 1 FROM formations WHERE id=? AND active=1 LIMIT 1');$learnerFormation->execute([$formationId]);
    if (!$learnerFormation->fetchColumn()) out(['error'=>'formation_unavailable','msg'=>'La formation associée à ce compte n’est plus active.']);
    if(!hash_equals($formationId,(string)$level['formation_id']))out(['error'=>'forbidden']);
    if($mode==='parcours') {
        $previous=$db->prepare('SELECT l.id,l.pass_percent FROM test_levels l WHERE l.formation_id=? AND l.active=1 AND l.position<? ORDER BY l.position ASC');
        $previous->execute([$level['formation_id'],$level['position']]);
        $previousLevels=$previous->fetchAll(PDO::FETCH_ASSOC); $blocked=false;
        foreach($previousLevels as $previousLevel){
            $pv=$db->prepare('SELECT validated,manual_unlock,best_percentage FROM level_progress WHERE learner_uid=? AND level_id=? LIMIT 1');
            $pv->execute([(string)$_SESSION['uid'],(string)$previousLevel['id']]); $progress=$pv->fetch(PDO::FETCH_ASSOC) ?: [];
            if(!levelIsOfficiallyValidated($progress,(int)$previousLevel['pass_percent'])){$blocked=true;break;}
        }
        if($blocked){$own=$db->prepare('SELECT manual_unlock FROM level_progress WHERE learner_uid=? AND level_id=?');$own->execute([(string)$_SESSION['uid'],$levelId]);if((int)$own->fetchColumn()!==1)out(['error'=>'level_locked','msg'=>'Cette étape reste verrouillée jusqu’à validation des étapes précédentes ou à son déblocage exceptionnel.']);}
    }
    $uid=(string)$_SESSION['uid'];
    $db->exec('BEGIN IMMEDIATE');
    $resume=$db->prepare('SELECT * FROM test_attempts WHERE learner_uid=? AND level_id=? AND mode=? AND status="started" ORDER BY started_at DESC LIMIT 1');$resume->execute([$uid,$levelId,$mode]);$attempt=$resume->fetch(PDO::FETCH_ASSOC);
    if($attempt){ $expires=strtotime((string)$attempt['expires_at']); if($expires&&$expires<time()){ $up=$db->prepare('UPDATE test_attempts SET status="expired" WHERE id=?');$up->execute([$attempt['id']]); $attempt=null; } }
    if(!$attempt){
        // La fermeture empêche de démarrer un nouvel examen, mais ne doit pas interrompre
        // une tentative encore en cours que l’apprenant reprend après une actualisation.
        if($mode==='examen') {
            $exam=activeExam($db,$levelId); if(!(bool)$exam['_active_for_new'])out(['error'=>'exam_unavailable','msg'=>'Cet examen n’est pas ouvert en ce moment.']);
        }
        $progress=ensureProgress($db,$uid,$levelId,(string)$level['module_id'],(string)$level['formation_id']); $used=0;$allowed=($mode==='entrainement')?PHP_INT_MAX:(int)($progress['attempts_allowed']+(int)$progress['extra_attempts']);
        if($mode==='examen'){ $ec=$db->prepare('SELECT attempts_allowed FROM exam_configs WHERE level_id=? LIMIT 1');$ec->execute([$levelId]);$examAllowed=$ec->fetchColumn();if($examAllowed!==false)$allowed=(int)$examAllowed; $count=$db->prepare('SELECT COUNT(*) FROM test_attempts WHERE learner_uid=? AND level_id=? AND mode="examen" AND status IN ("started","submitted","expired")');$count->execute([$uid,$levelId]);$used=(int)$count->fetchColumn(); }
        elseif($mode==='parcours') { $used=(int)$progress['attempts_used']; }
        if($mode!=='entrainement'&&$used>= $allowed)out(['error'=>'attempts_exhausted','msg'=>'Le nombre de tentatives est épuisé. Demandez une tentative supplémentaire au formateur.']);
        $duration=$db->prepare('SELECT duration_minutes FROM modules WHERE id=?');$duration->execute([$level['module_id']]);$minutes=max(0,(int)$duration->fetchColumn());
        $started=time();$expires=$minutes>0?gmdate('c',$started+$minutes*60):null;$attemptNo=$mode==='entrainement'?1:$used+1;
        $snapshot=levelSnapshot($db,(string)$level['module_id'],$levelId);if(!$snapshot)out(['error'=>'no_questions','msg'=>'Aucune question active n’est disponible pour ce niveau. Contactez votre formateur.']);$attemptId=bin2hex(random_bytes(16));
        $ins=$db->prepare('INSERT INTO test_attempts(id,learner_uid,formation_id,module_id,level_id,mode,attempt_number,status,started_at,expires_at,snapshot,created) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$ins->execute([$attemptId,$uid,(string)$level['formation_id'],(string)$level['module_id'],$levelId,$mode,$attemptNo,'started',gmdate('c',$started),$expires,json_encode($snapshot,JSON_UNESCAPED_UNICODE),gmdate('c',$started)]);
        if($mode==='parcours'){$up=$db->prepare('UPDATE level_progress SET attempts_used=attempts_used+1,updated_at=? WHERE learner_uid=? AND level_id=?');$up->execute([gmdate('c'),$uid,$levelId]);}
        $attempt=['id'=>$attemptId,'learner_uid'=>$uid,'formation_id'=>$level['formation_id'],'module_id'=>$level['module_id'],'level_id'=>$levelId,'mode'=>$mode,'attempt_number'=>$attemptNo,'status'=>'started','started_at'=>gmdate('c',$started),'expires_at'=>$expires,'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE)];
    }
    $db->exec('COMMIT');
    $snapshot=json_decode((string)$attempt['snapshot'],true);if(!is_array($snapshot))$snapshot=[];
    $savedStmt=$db->prepare('SELECT question_id,answer_id,multiple_answer_ids FROM test_attempt_answers WHERE attempt_id=?');$savedStmt->execute([$attempt['id']]);$savedOut=[];
    foreach($savedStmt->fetchAll(PDO::FETCH_ASSOC) as $savedRow){$multiple=json_decode((string)$savedRow['multiple_answer_ids'],true);$savedOut[(string)$savedRow['question_id']]=['answer_id'=>(int)$savedRow['answer_id'],'multiple'=>is_array($multiple)?array_map('intval',$multiple):[]];}
    $questionsOut=publicQuestions($snapshot,$mode==='entrainement');unset($attempt['snapshot']);
    out(['ok'=>true,'attempt'=>$attempt,'questions'=>$questionsOut,'saved'=>$savedOut,'expires_at'=>$attempt['expires_at']??null,'mode'=>$mode]);

case 'test_attempt_answers_save':
    if(role()!== 'learner') out(['error'=>'forbidden']);
    $attemptId=trim((string)($in['attempt_id'] ?? '')); $questionId=filter_var($in['question_id'] ?? null,FILTER_VALIDATE_INT); $answerId=filter_var($in['answer_id'] ?? null,FILTER_VALIDATE_INT); $answerIds=$in['answer_ids'] ?? null;
    if(!preg_match('#^[a-f0-9]{32}$#',$attemptId)||!$questionId)out(['error'=>'bad_data']);
    $a=$db->prepare('SELECT * FROM test_attempts WHERE id=? AND learner_uid=? AND status="started" LIMIT 1');$a->execute([$attemptId,(string)$_SESSION['uid']]);$attempt=$a->fetch(PDO::FETCH_ASSOC);if(!$attempt)out(['error'=>'attempt_unavailable']);
    if($attempt['expires_at']&&strtotime((string)$attempt['expires_at'])<time()){$u=$db->prepare('UPDATE test_attempts SET status="expired" WHERE id=?');$u->execute([$attemptId]);out(['error'=>'attempt_expired']);}
    $snapshot=json_decode((string)$attempt['snapshot'],true);$question=null;if(is_array($snapshot))foreach($snapshot as $candidate){if((int)$candidate['id']===(int)$questionId){$question=$candidate;break;}}
    if(!$question)out(['error'=>'bad_data','msg'=>'La question ne fait pas partie de cette tentative.']);
    $validAnswerIds=array_map(static function(array $answer): int{return (int)$answer['id'];},$question['answers']??[]);
    $multiple=[];$isMultiple=strtolower((string)($question['type']??'single'))==='multiple';
    if($isMultiple){if(!is_array($answerIds)||!$answerIds)out(['error'=>'bad_data','msg'=>'Sélectionnez au moins une réponse.']);foreach($answerIds as $id){$valid=filter_var($id,FILTER_VALIDATE_INT);if($valid===false||!in_array((int)$valid,$validAnswerIds,true))out(['error'=>'bad_data']);$multiple[]=(int)$valid;}$multiple=array_values(array_unique($multiple));$answerId=null;}
    else{if($answerId===false||$answerId===null||!in_array((int)$answerId,$validAnswerIds,true))out(['error'=>'bad_data']);$multiple=[];}
    $s=$db->prepare('INSERT INTO test_attempt_answers(attempt_id,question_id,answer_id,multiple_answer_ids,saved_at) VALUES(?,?,?,?,?) ON CONFLICT(attempt_id,question_id) DO UPDATE SET answer_id=excluded.answer_id,multiple_answer_ids=excluded.multiple_answer_ids,saved_at=excluded.saved_at');$s->execute([$attemptId,(int)$questionId,$answerId?:null,json_encode(array_values(array_unique($multiple)),JSON_UNESCAPED_UNICODE),gmdate('c')]);
    $feedback=['ok'=>true];
    if($attempt['mode']==='entrainement'){$correct=[];$correctIds=[];$chosenCorrect=false;foreach(($question['answers']??[]) as $ar){if((int)$ar['is_correct']===1){$correct[]=(string)$ar['answer'];$correctIds[]=(int)$ar['id'];}if(!$isMultiple&&$answerId&&(int)$ar['id']===(int)$answerId&&(int)$ar['is_correct']===1)$chosenCorrect=true;}if($isMultiple){sort($correctIds);$selectedIds=$multiple;sort($selectedIds);$chosenCorrect=$selectedIds===$correctIds;}$feedback['feedback']=['correct'=>$chosenCorrect,'correct_answers'=>$correct,'explanation'=>(string)$question['explanation']];}
    out($feedback);

case 'test_attempt_submit':
    if(role()!== 'learner') out(['error'=>'forbidden']);
    $attemptId=trim((string)($in['attempt_id'] ?? ''));$submissionId=trim((string)($in['submission_id'] ?? ''));
    if(!preg_match('#^[a-f0-9]{32}$#',$attemptId)||!preg_match('#^[A-Za-z0-9_-]{8,64}$#',$submissionId))out(['error'=>'bad_data']);
    $a=$db->prepare('SELECT * FROM test_attempts WHERE id=? AND learner_uid=? LIMIT 1');$a->execute([$attemptId,(string)$_SESSION['uid']]);$attempt=$a->fetch(PDO::FETCH_ASSOC);if(!$attempt)out(['error'=>'not_found']);
    if($attempt['status']==='submitted'){$level=$db->prepare('SELECT pass_percent FROM test_levels WHERE id=?');$level->execute([(string)$attempt['level_id']]);$threshold=$level->fetchColumn();$threshold=$threshold===false?90:(int)$threshold;$passed=(int)$attempt['total']>0&&(int)$attempt['score']*100>=$threshold*(int)$attempt['total'];out(['ok'=>true,'duplicate'=>true,'percentage'=>(int)$attempt['percentage'],'score'=>(int)$attempt['score'],'total'=>(int)$attempt['total'],'threshold'=>$threshold,'passed'=>$passed]);}
    if(!in_array($attempt['status'],['started','expired'],true))out(['error'=>'attempt_unavailable']);
    $snapshot=json_decode((string)$attempt['snapshot'],true);if(!is_array($snapshot))$snapshot=[];
    $saved=$db->prepare('SELECT question_id,answer_id,multiple_answer_ids FROM test_attempt_answers WHERE attempt_id=?');
    $saved->execute([$attemptId]);$savedRows=$saved->fetchAll(PDO::FETCH_ASSOC);$selected=[];
    foreach($savedRows as $savedRow){
        $multiple=json_decode((string)$savedRow['multiple_answer_ids'],true);
        if(!is_array($multiple)){$multiple=[];}
        $qid=(int)$savedRow['question_id'];
        $selected[$qid]=['answer_id'=>(int)$savedRow['answer_id'],'multiple'=>array_map('intval',$multiple)];
    }
    $score=0;$total=0;$good=0;$bad=0;$corrections=[];
    foreach($snapshot as $question){$qid=(int)$question['id'];$points=max(1,(int)$question['points']);$total+=$points;$answerRows=$question['answers']??[];$correctIds=[];$correctLabels=[];$chosenLabels=[];$isCorrect=false;
        foreach($answerRows as $answerRow){if((int)$answerRow['is_correct']===1){$correctIds[]=(int)$answerRow['id'];$correctLabels[]=(string)$answerRow['answer'];}}
        $chosen=$selected[$qid]??['answer_id'=>0,'multiple'=>[]];
        if(strtolower((string)($question['type']??'single'))==='multiple'){$chosenIds=(array)$chosen['multiple'];sort($chosenIds);$expected=$correctIds;sort($expected);$isCorrect=$chosenIds===[]?false:$chosenIds===$expected;}else{$chosenId=(int)$chosen['answer_id'];$isCorrect=in_array($chosenId,$correctIds,true);} 
        foreach($answerRows as $answerRow){if((int)($chosen['answer_id']??0)===(int)$answerRow['id']||(in_array((int)$answerRow['id'],(array)$chosen['multiple'],true)))$chosenLabels[]=(string)$answerRow['answer'];}
        if($isCorrect){$score+=$points;$good++;}else{$bad++;}
        $corrections[]=['question'=>$question['question'],'chosen'=>$chosenLabels,'correct'=>$correctLabels,'correct_result'=>$isCorrect,'explanation'=>(string)$question['explanation'],'points'=>$isCorrect?$points:0];
    }
    $percentage=$total>0?(int)round(100*$score/$total):0;$duration=max(0,min(86400,time()-(int)strtotime((string)$attempt['started_at'])));$name=(string)($_SESSION['name']??'Apprenant');$now=gmdate('c');
    $level=$db->prepare('SELECT pass_percent FROM test_levels WHERE id=?');$level->execute([(string)$attempt['level_id']]);$threshold=$level->fetchColumn();$threshold=$threshold===false?90:(int)$threshold;
    $passed=$total>0&&$score*100>=$threshold*$total;
    try{
        $db->beginTransaction();
        $u=$db->prepare('UPDATE test_attempts SET status="submitted",submitted_at=?,score=?,total=?,percentage=?,duration_seconds=?,submission_id=? WHERE id=? AND status IN ("started","expired")');$u->execute([$now,$score,$total,$percentage,$duration,$submissionId,$attemptId]);
        if($u->rowCount()<1){$db->rollBack();out(['ok'=>false,'duplicate'=>true,'msg'=>'Cette tentative a déjà été soumise.']);}
        if($attempt['mode']==='parcours'&&$passed){$p=$db->prepare('UPDATE level_progress SET best_percentage=CASE WHEN best_percentage<0 OR best_percentage<? THEN ? ELSE best_percentage END,validated=1,validated_at=COALESCE(validated_at,?),updated_at=? WHERE learner_uid=? AND level_id=?');$p->execute([$percentage,$percentage,$now,$now,(string)$attempt['learner_uid'],(string)$attempt['level_id']]);}
        elseif($attempt['mode']==='parcours'){$p=$db->prepare('UPDATE level_progress SET best_percentage=CASE WHEN best_percentage<0 OR best_percentage<? THEN ? ELSE best_percentage END,updated_at=? WHERE learner_uid=? AND level_id=?');$p->execute([$percentage,$percentage,$now,(string)$attempt['learner_uid'],(string)$attempt['level_id']]);}
        $evalId=bin2hex(random_bytes(12));$ev=$db->prepare('INSERT INTO evaluations(id,formation_id,module_id,learner_uid,learner_name,mode,score,total,percentage,duration_seconds,submission_id,created) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$ev->execute([$evalId,(string)$attempt['formation_id'],(string)$attempt['module_id'],(string)$attempt['learner_uid'],$name,(string)$attempt['mode'],$score,$total,$percentage,$duration,$submissionId,$now]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $publish=false;if($attempt['mode']==='entrainement')$publish=true;if($attempt['mode']==='parcours')$publish=true;if($attempt['mode']==='examen'){ $exam=$db->prepare('SELECT correction_published,correction_mode,correction_at FROM exam_configs WHERE level_id=?');$exam->execute([(string)$attempt['level_id']]);$examRow=$exam->fetch(PDO::FETCH_ASSOC);if($examRow){if((int)$examRow['correction_published']===1)$publish=true;if($examRow['correction_mode']==='immediate')$publish=true;if($examRow['correction_mode']==='programme'&&$examRow['correction_at']&&strtotime((string)$examRow['correction_at'])<=time())$publish=true;}}
    // Parcours : après un échec, on indique quelles questions sont fausses, sans révéler les bonnes réponses (nouvelle tentative possible).
    $correctionsOut=$corrections;
    if($attempt['mode']==='parcours'&&!$passed){foreach($correctionsOut as &$c){$c['correct']=[];$c['explanation']='';}unset($c);}
    out(['ok'=>true,'attempt_id'=>$attemptId,'score'=>$score,'total'=>$total,'percentage'=>$percentage,'good'=>$good,'bad'=>$bad,'threshold'=>$threshold,'passed'=>$passed,'corrections'=>$publish?$correctionsOut:[],'correction_published'=>$publish]);

case 'test_attempt_resume':
    if(role()!== 'learner')out(['error'=>'forbidden']);
    $attemptId=trim((string)($in['attempt_id']??''));if(!preg_match('#^[a-f0-9]{32}$#',$attemptId))out(['error'=>'bad_data']);$a=$db->prepare('SELECT * FROM test_attempts WHERE id=? AND learner_uid=? LIMIT 1');$a->execute([$attemptId,(string)$_SESSION['uid']]);$attempt=$a->fetch(PDO::FETCH_ASSOC);if(!$attempt)out(['error'=>'not_found']);if($attempt['status']==='started'&&$attempt['expires_at']&&strtotime((string)$attempt['expires_at'])<time()){$u=$db->prepare('UPDATE test_attempts SET status="expired" WHERE id=?');$u->execute([$attemptId]);$attempt['status']='expired';}
    $snapshot=json_decode((string)$attempt['snapshot'],true);if(!is_array($snapshot))$snapshot=[];$answers=$db->prepare('SELECT question_id,answer_id,multiple_answer_ids FROM test_attempt_answers WHERE attempt_id=?');$answers->execute([$attemptId]);$saved=[];foreach($answers->fetchAll(PDO::FETCH_ASSOC) as $row)$saved[(int)$row['question_id']]=['answer_id'=>(int)$row['answer_id'],'multiple'=>json_decode((string)$row['multiple_answer_ids'],true)];
    $questionsOut=publicQuestions($snapshot,$attempt['mode']==='entrainement');unset($attempt['snapshot']);
    out(['ok'=>true,'attempt'=>$attempt,'questions'=>$questionsOut,'saved'=>$saved]);

case 'test_attempt_corrections':
    if (role() !== 'learner') out(['error' => 'forbidden']);
    $attemptId = trim((string)($in['attempt_id'] ?? ''));
    if (!preg_match('#^[a-f0-9]{32}$#', $attemptId)) out(['error' => 'bad_data']);
    $attemptQuery = $db->prepare('SELECT * FROM test_attempts WHERE id=? AND learner_uid=? AND status="submitted" AND mode="examen" LIMIT 1');
    $attemptQuery->execute([$attemptId, (string)$_SESSION['uid']]); $attempt = $attemptQuery->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) out(['error' => 'not_found']);
    $examQuery = $db->prepare('SELECT correction_published,correction_mode,correction_at FROM exam_configs WHERE level_id=?');
    $examQuery->execute([(string)$attempt['level_id']]); $exam = $examQuery->fetch(PDO::FETCH_ASSOC);
    $published = $exam && ((int)$exam['correction_published'] === 1 || $exam['correction_mode'] === 'immediate' || ($exam['correction_mode'] === 'programme' && $exam['correction_at'] && strtotime((string)$exam['correction_at']) <= time()));
    if (!$published) out(['ok' => false, 'msg' => 'Le corrigé de cet examen n’est pas encore publié.']);
    $snapshot = json_decode((string)$attempt['snapshot'], true); if (!is_array($snapshot)) $snapshot = [];
    $answerQuery = $db->prepare('SELECT question_id,answer_id,multiple_answer_ids FROM test_attempt_answers WHERE attempt_id=?');
    $answerQuery->execute([$attemptId]); $selected = [];
    foreach ($answerQuery->fetchAll(PDO::FETCH_ASSOC) as $answerRow) {
        $multiple = json_decode((string)$answerRow['multiple_answer_ids'], true);
        $selected[(int)$answerRow['question_id']] = ['answer_id' => (int)$answerRow['answer_id'], 'multiple' => is_array($multiple) ? array_map('intval', $multiple) : []];
    }
    $corrections = [];
    foreach ($snapshot as $question) {
        $answers = $question['answers'] ?? []; $correctIds = []; $correctLabels = []; $chosenLabels = [];
        foreach ($answers as $answer) if ((int)$answer['is_correct'] === 1) { $correctIds[] = (int)$answer['id']; $correctLabels[] = (string)$answer['answer']; }
        $choice = $selected[(int)$question['id']] ?? ['answer_id' => 0, 'multiple' => []];
        foreach ($answers as $answer) if ((int)$choice['answer_id'] === (int)$answer['id'] || in_array((int)$answer['id'], $choice['multiple'], true)) $chosenLabels[] = (string)$answer['answer'];
        if (strtolower((string)($question['type'] ?? 'single')) === 'multiple') { $chosen = $choice['multiple']; sort($chosen); sort($correctIds); $isCorrect = $chosen !== [] && $chosen === $correctIds; }
        else $isCorrect = in_array((int)$choice['answer_id'], $correctIds, true);
        $corrections[] = ['question' => (string)$question['question'], 'chosen' => $chosenLabels, 'correct' => $correctLabels, 'correct_result' => $isCorrect, 'explanation' => (string)($question['explanation'] ?? ''), 'points' => $isCorrect ? max(1, (int)($question['points'] ?? 1)) : 0];
    }
    out(['ok' => true, 'attempt_id' => $attemptId, 'corrections' => $corrections]);

case 'test_attempt_history':
    if(role()!== 'learner')out(['error'=>'forbidden']);$uid=(string)$_SESSION['uid'];$mode=trim((string)($in['mode']??''));$sql='SELECT ta.id,ta.formation_id,f.name formation_name,ta.module_id,m.name module_name,ta.level_id,l.name level_name,ta.mode,ta.attempt_number,ta.status,ta.started_at,ta.submitted_at,ta.score,ta.total,ta.percentage,ta.duration_seconds FROM test_attempts ta LEFT JOIN formations f ON f.id=ta.formation_id LEFT JOIN modules m ON m.id=ta.module_id LEFT JOIN test_levels l ON l.id=ta.level_id WHERE ta.learner_uid=?';$params=[$uid];if(in_array($mode,['parcours','entrainement','examen'],true)){$sql.=' AND ta.mode=?';$params[]=$mode;}$sql.=' ORDER BY ta.started_at DESC LIMIT 200';$s=$db->prepare($sql);$s->execute($params);out(['attempts'=>$s->fetchAll(PDO::FETCH_ASSOC)]);

case 'trainer_levels_list':
    if (!canReadStaff()) out(['error' => 'forbidden']);
    $sql = 'SELECT l.id,l.formation_id,f.name formation_name,l.module_id,l.name,l.position,l.attempts_allowed,l.pass_percent,(SELECT COUNT(*) FROM questions q WHERE q.level_id=l.id AND q.active=1) own_questions,e.active exam_active,e.state exam_state,e.starts_at,e.closes_at,e.attempts_allowed exam_attempts,e.correction_mode,e.correction_published FROM test_levels l JOIN formations f ON f.id=l.formation_id LEFT JOIN exam_configs e ON e.level_id=l.id WHERE l.active=1';
    $params = [];
    if (canTrainer()) { $sql .= ' AND l.formation_id IN (SELECT formation_id FROM staff_formations WHERE staff_id=?)'; $params[] = currentStaffId($db); }
    $s = $db->prepare($sql . ' ORDER BY l.formation_id,l.position'); $s->execute($params);
    out(['levels' => $s->fetchAll(PDO::FETCH_ASSOC)]);

case 'trainer_actions_list':
    if (!canReadStaff()) out(['error' => 'forbidden']);
    $sql = "SELECT ta.rowid id,ta.created_at,ta.action,ta.details,ta.level_id,ta.learner_uid,COALESCE(s.username,'administrateur') actor,(SELECT name FROM learners WHERE learner_uid=ta.learner_uid LIMIT 1) learner_name FROM trainer_actions ta LEFT JOIN staff s ON s.id=ta.trainer_staff_id";
    $params = [];
    if (canTrainer()) { $sql .= ' WHERE ta.trainer_staff_id=?'; $params[] = currentStaffId($db); }
    $s = $db->prepare($sql . ' ORDER BY ta.rowid DESC LIMIT 200'); $s->execute($params);
    out(['rows' => $s->fetchAll(PDO::FETCH_ASSOC)]);

case 'test_trainer_config_attempts':
case 'exam_config_set':
case 'test_trainer_extra_attempt':
case 'test_trainer_unlock_level':
case 'exam_corrections_publish':
case 'test_trainer_results':
    $action=$a;
    if(!canTrainer()&&!canAdmin())out(['error'=>'forbidden']);
    $scopeLevel=trim((string)($in['level_id']??''));
    if($scopeLevel!==''){
        $st=$db->prepare('SELECT formation_id,module_id FROM test_levels WHERE id=?');$st->execute([$scopeLevel]);$levelRow0=$st->fetch(PDO::FETCH_ASSOC);
        if(!$levelRow0)out(['error'=>'not_found']);
        if(canTrainer()&&!trainerCanFormation($db,currentStaffId($db),(string)$levelRow0['formation_id']))out(['error'=>'forbidden']);
        $in['formation_id']=$levelRow0['formation_id'];$in['module_id']=$levelRow0['module_id'];
    }
    $scopeLearner=trim((string)($in['learner_uid']??''));
    if($scopeLearner!==''&&canTrainer()&&!trainerOwnsLearnerUid($db,currentStaffId($db),$scopeLearner))out(['error'=>'forbidden']);
    if($action==='test_trainer_config_attempts'||$action==='exam_config_set'){
        if($action==='test_trainer_config_attempts'){
            $levelId=trim((string)($in['level_id']??''));$allowed=filter_var($in['attempts_allowed']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
            if(!preg_match('#^[a-zA-Z0-9_-]+$#',$levelId)||$allowed===false)out(['error'=>'bad_data']);
            $old=$db->prepare('SELECT attempts_allowed FROM test_levels WHERE id=?');$old->execute([$levelId]);$previous=$old->fetchColumn();
            $s=$db->prepare('UPDATE test_levels SET attempts_allowed=? WHERE id=?');$s->execute([(int)$allowed,$levelId]);
            $db->prepare('INSERT INTO trainer_actions(trainer_staff_id,action,learner_uid,level_id,module_id,details,created_at) VALUES(?,?,?,?,?,?,?)')->execute([currentStaffId($db),'config_attempts','',$levelId,'','attempts_allowed='.(int)$allowed,gmdate('c')]);
            auditLog($db,'attempts_limit_update','level',$levelId,['before'=>$previous===false?null:(int)$previous,'after'=>(int)$allowed]);
            out(['ok'=>true]);
        }
        $levelId=trim((string)($in['level_id']??''));$formationId=trim((string)($in['formation_id']??''));$moduleId=trim((string)($in['module_id']??''));$active=(int)($in['active']??0)===1?1:0;$starts=trim((string)($in['starts_at']??''));$closes=trim((string)($in['closes_at']??''));$allowed=filter_var($in['attempts_allowed']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);$correctionMode=trim((string)($in['correction_mode']??'manuel'));$correctionAt=trim((string)($in['correction_at']??''));
        if(!preg_match('#^[a-zA-Z0-9_-]+$#',$levelId)||!preg_match('#^[a-zA-Z0-9_-]+$#',$moduleId)||!preg_match('#^[a-zA-Z0-9_-]+$#',$formationId)||$allowed===false||!in_array($correctionMode,['immediate','programme','manuel'],true))out(['error'=>'bad_data']);
        $start=$starts!==''?gmdate('c',strtotime($starts)):null;$close=$closes!==''?gmdate('c',strtotime($closes)):null;$correctionDate=$correctionAt!==''?gmdate('c',strtotime($correctionAt)):null;if($start&&$close&&strtotime($close)<=strtotime($start))out(['error'=>'bad_period']);
        $id=hash('sha256',$levelId);$state=$active?($start&&strtotime($start)>time()?'programme':'actif'):'desactive';$by=(string)($_SESSION['uid']??$_SESSION['admin_name']??'');$now=gmdate('c');
        $existing=$db->prepare('SELECT active,state,starts_at,closes_at,attempts_allowed,correction_mode,correction_at FROM exam_configs WHERE level_id=?');$existing->execute([$levelId]);$previous=$existing->fetch(PDO::FETCH_ASSOC);
        $examId=$previous?true:false;
        if($examId){$up=$db->prepare('UPDATE exam_configs SET formation_id=?,module_id=?,active=?,state=?,starts_at=?,closes_at=?,attempts_allowed=?,correction_mode=?,correction_at=?,updated_by=?,updated_at=? WHERE level_id=?');$up->execute([$formationId,$moduleId,$active,$state,$start,$close,(int)$allowed,$correctionMode,$correctionDate,$by,$now,$levelId]);}
        else{$ins=$db->prepare('INSERT INTO exam_configs(id,formation_id,module_id,level_id,active,state,starts_at,closes_at,attempts_allowed,pass_percent,correction_mode,correction_at,correction_published,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,90,?,?,0,?,?)');$ins->execute([$id,$formationId,$moduleId,$levelId,$active,$state,$start,$close,(int)$allowed,$correctionMode,$correctionDate,$by,$now]);}
        $db->prepare('INSERT INTO trainer_actions(trainer_staff_id,action,learner_uid,level_id,module_id,details,created_at) VALUES(?,?,?,?,?,?,?)')->execute([currentStaffId($db),'exam_config','',$levelId,$moduleId,$active?'active':'desactive',gmdate('c')]);
        auditLog($db,'exam_config_update','exam',$levelId,['before'=>$previous,'after'=>['active'=>$active,'state'=>$state,'starts_at'=>$start,'closes_at'=>$close,'attempts_allowed'=>(int)$allowed,'correction_mode'=>$correctionMode,'correction_at'=>$correctionDate]]);
        out(['ok'=>true,'state'=>$state]);
    }
    if($action==='test_trainer_extra_attempt'||$action==='test_trainer_unlock_level'||$action==='exam_corrections_publish'||$action==='test_trainer_results'){
        if($action==='test_trainer_extra_attempt'||$action==='test_trainer_unlock_level'){
            $levelId=trim((string)($in['level_id']??''));$uid=trim((string)($in['learner_uid']??''));if($levelId===''||$uid==='')out(['error'=>'bad_data']);
            $level=$db->prepare('SELECT module_id,formation_id FROM test_levels WHERE id=?');$level->execute([$levelId]);$lr=$level->fetch(PDO::FETCH_ASSOC);if(!$lr)out(['error'=>'not_found']);
            $reason=mb_substr(trim((string)($in['reason']??'')),0,200);if($action==='test_trainer_unlock_level'&&mb_strlen($reason)<5)out(['error'=>'reason_required','msg'=>'Indiquez le motif du déblocage (5 caractères minimum).']);
            ensureProgress($db,$uid,$levelId,(string)$lr['module_id'],(string)$lr['formation_id']);
            if($action==='test_trainer_extra_attempt'){
                $db->prepare('UPDATE level_progress SET extra_attempts=extra_attempts+1,updated_at=? WHERE learner_uid=? AND level_id=?')->execute([gmdate('c'),$uid,$levelId]);$details='extra_attempt';
                auditLog($db,'extra_attempt_grant','learner',$uid,['level_id'=>$levelId,'reason'=>$reason]);
            }else{
                $progress=ensureProgress($db,$uid,$levelId,(string)$lr['module_id'],(string)$lr['formation_id']);
                if(!levelIsOfficiallyValidated($progress,(int)($progress['_threshold']??90)))$db->prepare('UPDATE level_progress SET manual_unlock=1,updated_at=? WHERE learner_uid=? AND level_id=?')->execute([gmdate('c'),$uid,$levelId]);
                $details='manual_unlock';
                auditLog($db,'manual_level_unlock','learner',$uid,['level_id'=>$levelId,'reason'=>$reason]);
            }
            $db->prepare('INSERT INTO trainer_actions(trainer_staff_id,action,learner_uid,level_id,module_id,details,created_at) VALUES(?,?,?,?,?,?,?)')->execute([currentStaffId($db),$details,$uid,$levelId,(string)$lr['module_id'],$reason,gmdate('c')]);out(['ok'=>true]);
        }
        if($action==='exam_corrections_publish'){
            $levelId=trim((string)($in['level_id']??''));$published=(int)($in['published']??1)===1?1:0;if($levelId==='')out(['error'=>'bad_data']);
            $old=$db->prepare('SELECT correction_published FROM exam_configs WHERE level_id=?');$old->execute([$levelId]);$previous=$old->fetchColumn();
            $db->prepare('UPDATE exam_configs SET correction_published=?,updated_by=?,updated_at=? WHERE level_id=?')->execute([$published,(string)($_SESSION['uid']??$_SESSION['admin_name']??''),gmdate('c'),$levelId]);
            auditLog($db,'exam_corrections_publish','exam',$levelId,['before'=>$previous===false?null:(int)$previous,'after'=>$published]);out(['ok'=>true]);
        }
        $mode=trim((string)($in['mode']??''));$moduleId=trim((string)($in['module_id']??''));$uid=trim((string)($in['learner_uid']??''));$sql='SELECT ta.id,ta.learner_uid,ta.formation_id,ta.module_id,ta.level_id,ta.mode,ta.attempt_number,ta.status,ta.started_at,ta.submitted_at,ta.score,ta.total,ta.percentage,ta.duration_seconds FROM test_attempts ta WHERE 1=1';$params=[];if(canTrainer()){$sql.=' AND ta.learner_uid IN (SELECT learner_uid FROM learner_accounts WHERE trainer_staff_id=?) AND ta.formation_id IN (SELECT formation_id FROM staff_formations WHERE staff_id=?)';$params[]=currentStaffId($db);$params[]=currentStaffId($db);}if($mode!==''&&in_array($mode,['parcours','entrainement','examen'],true)){$sql.=' AND ta.mode=?';$params[]=$mode;}if($moduleId!==''){$sql.=' AND ta.module_id=?';$params[]=$moduleId;}if($uid!==''){$sql.=' AND ta.learner_uid=?';$params[]=$uid;} $sql.=' ORDER BY ta.started_at DESC LIMIT 500';$s=$db->prepare($sql);$s->execute($params);out(['results'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    }

case 'module_questions':
    out(['error' => 'gone', 'msg' => 'Ce point d’entrée a été supprimé : utilisez test_attempt_start.']);
    if (role() !== 'learner') out(['error' => 'authentication_required']);
    $moduleId = trim((string)($in['module_id'] ?? ''));
    if (!preg_match('#^[a-zA-Z0-9_-]+$#', $moduleId)) out(['error' => 'bad_data']);
    $m = $db->prepare('SELECT m.id,m.name,m.duration_minutes,m.question_count,m.pass_percent,f.id formation_id,f.name formation_name FROM modules m JOIN formations f ON f.id=m.formation_id WHERE m.id=? AND m.active=1 AND f.active=1'); $m->execute([$moduleId]); $module = $m->fetch(PDO::FETCH_ASSOC);
    if (!$module) out(['error' => 'not_found']);
    $learnerFormation = (string)($_SESSION['formation_id'] ?? '');
    if ($learnerFormation === '' || !hash_equals($learnerFormation, (string)$module['formation_id'])) out(['error' => 'forbidden']);
    $q = $db->prepare('SELECT id,question,type,points,explanation FROM questions WHERE module_id=? AND active=1 ORDER BY position,id LIMIT 100'); $q->execute([$moduleId]); $questions = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($questions as &$question) {
        $a = $db->prepare('SELECT id,answer FROM answers WHERE question_id=? ORDER BY position,id'); $a->execute([$question['id']]); $question['answers'] = $a->fetchAll(PDO::FETCH_ASSOC);
        unset($question['explanation']);
    }
    unset($question); out(['module' => $module, 'questions' => $questions]);

case 'evaluation_submit':
    out(['error' => 'gone', 'msg' => 'Ce point d’entrée a été supprimé : utilisez test_attempt_submit.']);

case 'login_learner':
    out(['ok' => false, 'deprecated' => true, 'msg' => 'Utilisez la connexion centrale avec votre identifiant personnel et votre mot de passe.']);

case 'logout_learner':
    unset($_SESSION['uid'], $_SESSION['name']);
    out(['ok' => true]);

case 'login':
    $u = isset($in['identifier']) ? (string)$in['identifier'] : (isset($in['u']) ? (string)$in['u'] : (isset($in['email']) ? (string)$in['email'] : ''));
    $p = isset($in['password']) ? (string)$in['password'] : (isset($in['p']) ? (string)$in['p'] : '');
    $remember = !empty($in['remember']);
    $u = strtolower(trim($u));
    if ($u === '' || $p === '') out(['ok' => false, 'msg' => 'Entrez votre identifiant ou adresse e-mail, puis votre mot de passe.']);

    $primaryUser = (string)cfg($db, 'admin_user');
    $adminHash = cfg($db, 'admin_hash');
    $staffRole = ''; $mustChange = false;
    if (is_string($adminHash) && $adminHash !== false && $primaryUser !== '' && hash_equals($primaryUser, nm($u)) && password_verify($p, $adminHash)) {
        $staffRole = 'admin';
    } else {
        $s = $db->prepare('SELECT username,password_hash,role,active,must_change_password FROM staff WHERE LOWER(username)=?');
        $s->execute([$u]);
        $staff = $s->fetch(PDO::FETCH_ASSOC);
        if ($staff && (int)$staff['active'] && in_array((string)$staff['role'], ['admin', 'formateur'], true) && password_verify($p, $staff['password_hash'])) { $staffRole = $staff['role']; $mustChange = (int)$staff['must_change_password'] === 1; $u = (string)$staff['username']; }
    }
    if ($staffRole !== '') {
        session_regenerate_id(true);
        if ($remember) { $secure = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); session_set_cookie_params(['lifetime' => 2592000, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]); setcookie(session_name(), session_id(), time() + 2592000, '/', '', $secure, true); }
        $_SESSION['admin'] = true; $_SESSION['staff_role'] = $staffRole; $_SESSION['staff_username'] = $u; $_SESSION['must_change_password'] = $mustChange; $_SESSION['last_activity'] = time();
        unset($_SESSION['uid'], $_SESSION['name'], $_SESSION['formation_id']); out(['ok' => true, 'role' => $staffRole, 'must_change_password' => $mustChange]);
    }

    $s = $db->prepare('SELECT learner_uid,first_name,last_name,email,identifier,password_hash,active,formation_id,must_change_password FROM learner_accounts WHERE LOWER(email)=? OR LOWER(identifier)=?');
    $s->execute([$u,$u]); $account = $s->fetch(PDO::FETCH_ASSOC);
    if ($account && (int)$account['active'] && password_verify($p, $account['password_hash'])) {
        session_regenerate_id(true);
        if ($remember) { $secure = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); session_set_cookie_params(['lifetime' => 2592000, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]); setcookie(session_name(), session_id(), time() + 2592000, '/', '', $secure, true); }
        $_SESSION['uid'] = (string)$account['learner_uid']; $_SESSION['name'] = $account['first_name'].' '.$account['last_name']; $_SESSION['formation_id'] = (string)($account['formation_id'] ?? ''); $_SESSION['must_change_password'] = (int)$account['must_change_password'] === 1; $_SESSION['last_activity'] = time();
        unset($_SESSION['admin'], $_SESSION['staff_role'], $_SESSION['staff_username']); out(['ok' => true, 'role' => 'learner', 'name' => $_SESSION['name'], 'identifier' => (string)$account['identifier'], 'formation_id' => $_SESSION['formation_id'], 'must_change_password' => $_SESSION['must_change_password']]);
    }

    // Les anciens comptes historiques sans mot de passe ni formation ne peuvent plus se connecter.
    // Ils sont conservés uniquement pour la compatibilité des résultats existants.

    sleep(1); out(['ok' => false, 'msg' => 'Identifiant ou mot de passe incorrect.']);

case 'install':
    if (!setupKeyReady()) out(['ok' => false, 'msg' => 'Configurez NDARTECH_SETUP_KEY avec une clé secrète d’au moins 32 caractères, puis réessayez.']);
    if (cfg($db, 'admin_hash') !== false) out(['ok' => false, 'msg' => 'L’installation a déjà été effectuée.']);
    if (!hash_equals(SETUP_KEY, (string)($in['key'] ?? ''))) { sleep(1); out(['ok' => false, 'msg' => 'Clé d’installation incorrecte.']); }
    $u = nm((string)($in['u'] ?? '')); $p = (string)($in['p'] ?? '');
    if ($u === '' || mb_strlen($u) > 60 || strlen($p) < 12) out(['ok' => false, 'msg' => 'Identifiant obligatoire et mot de passe de 12 caractères minimum.']);
    setCfg($db, 'admin_user', $u); setCfg($db, 'admin_hash', password_hash($p, PASSWORD_DEFAULT));
    out(['ok' => true]);

case 'login_admin':
    out(['ok' => false, 'deprecated' => true, 'msg' => 'Utilisez la connexion centrale de la plateforme.']);

case 'change_temporary_password':
    $new = (string)($in['new'] ?? ''); $confirm = (string)($in['confirm'] ?? '');
    if (strlen($new) < 12) out(['ok' => false, 'msg' => 'Le nouveau mot de passe doit contenir au moins 12 caractères.']);
    if (!hash_equals($new, $confirm)) out(['ok' => false, 'msg' => 'Les mots de passe ne sont pas identiques.']);
    if (!empty($_SESSION['admin'])) {
        $u = (string)($_SESSION['staff_username'] ?? ''); $s = $db->prepare('UPDATE staff SET password_hash=?,must_change_password=0,temporary_password=0,credential_cipher="" WHERE username=?'); $s->execute([password_hash($new, PASSWORD_DEFAULT),$u]);
    } elseif (!empty($_SESSION['uid'])) {
        $uid = (string)$_SESSION['uid']; $s = $db->prepare('UPDATE learner_accounts SET password_hash=?,must_change_password=0,temporary_password=0,credential_cipher="" WHERE learner_uid=?'); $s->execute([password_hash($new, PASSWORD_DEFAULT),$uid]);
    } else out(['error' => 'forbidden']);
    $_SESSION['must_change_password'] = false; out(['ok' => true]);

case 'logout':
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']); }
    session_regenerate_id(true); out(['ok' => true]);

case 'change_password':
    if (!canReadStaff()) out(['error' => 'forbidden']);
    $current = (string)($in['current'] ?? ''); $new = (string)($in['new'] ?? ''); $confirm = (string)($in['confirm'] ?? '');
    if (strlen($new) < 12) out(['ok' => false, 'msg' => 'Le nouveau mot de passe doit contenir au moins 12 caractères.']);
    if (!hash_equals($new, $confirm)) out(['ok' => false, 'msg' => 'Les deux nouveaux mots de passe ne sont pas identiques.']);
    if (staffRole() === 'admin' && isset($_SESSION['staff_username']) && $_SESSION['staff_username'] === cfg($db, 'admin_user')) {
        $hash = cfg($db, 'admin_hash');
        if ($hash === false || !password_verify($current, $hash)) out(['ok' => false, 'msg' => 'Mot de passe actuel incorrect.']);
        setCfg($db, 'admin_hash', password_hash($new, PASSWORD_DEFAULT));
    } else {
        $u = (string)($_SESSION['staff_username'] ?? ''); $s = $db->prepare('SELECT password_hash FROM staff WHERE username=? AND active=1'); $s->execute([$u]); $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password_hash'])) out(['ok' => false, 'msg' => 'Mot de passe actuel incorrect.']);
        $s = $db->prepare('UPDATE staff SET password_hash=?,must_change_password=0,temporary_password=0,credential_cipher="" WHERE username=?'); $s->execute([password_hash($new, PASSWORD_DEFAULT), $u]);
    }
    out(['ok' => true]);

case 'learner_change_password':
    if (role() !== 'learner') out(['error' => 'forbidden']);
    $current = (string)($in['current'] ?? '');
    $new = (string)($in['new'] ?? '');
    $confirm = (string)($in['confirm'] ?? '');
    if (strlen($new) < 12) out(['ok' => false, 'msg' => 'Le nouveau mot de passe doit contenir au moins 12 caractères.']);
    if (!hash_equals($new, $confirm)) out(['ok' => false, 'msg' => 'Les deux nouveaux mots de passe ne sont pas identiques.']);
    $uid = (string)$_SESSION['uid'];
    $s = $db->prepare('SELECT password_hash FROM learner_accounts WHERE learner_uid=? AND active=1');
    $s->execute([$uid]);
    $account = $s->fetch(PDO::FETCH_ASSOC);
    if (!$account || !password_verify($current, (string)$account['password_hash'])) out(['ok' => false, 'msg' => 'Mot de passe actuel incorrect.']);
    if (password_verify($new, (string)$account['password_hash'])) out(['ok' => false, 'msg' => 'Choisissez un mot de passe différent de l’actuel.']);
    $update = $db->prepare('UPDATE learner_accounts SET password_hash=?,must_change_password=0,temporary_password=0,credential_cipher="" WHERE learner_uid=? AND active=1');
    $update->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    out(['ok' => true]);

case 'backup':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $backupDir = $dir . '/backups'; if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);
    $backupFile = $backupDir . '/qcm-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        $db->exec('PRAGMA wal_checkpoint(FULL)');
        $db->exec('VACUUM INTO ' . $db->quote($backupFile));
        $check = new PDO('sqlite:' . $backupFile);
        $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Backup integrity check failed');
    } catch (Throwable $e) {
        error_log('qcm backup: ' . $e->getMessage());
        if (is_file($backupFile) && !unlink($backupFile)) error_log('qcm backup: unable to remove incomplete backup');
        out(['ok' => false, 'msg' => 'Impossible de créer ou vérifier la sauvegarde.']);
    }
    $files = glob($backupDir . '/qcm-backup-*.sqlite'); sort($files, SORT_STRING);
    while (count($files) > 20) { @unlink(array_shift($files)); }
    auditLog($db, 'database_backup', 'backup', basename($backupFile), ['integrity' => 'ok']);
    out(['ok' => true, 'file' => basename($backupFile)]);

case 'logout_admin':
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    out(['ok' => true]);

case 'admin_settings_set':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $previous = (cfg($db, 'public_registration') ?? '0') === '1';
    $enabled = !empty($in['enabled']);
    setCfg($db, 'public_registration', $enabled ? '1' : '0');
    auditLog($db, 'public_registration_update', 'setting', 'public_registration', ['before' => $previous, 'after' => $enabled]);
    out(['ok' => true]);

case 'admin_dashboard':
    if (!canReadStaff()) out(['error' => 'forbidden']);
    if (canTrainer()) {
        $staffId = currentStaffId($db);
        $s = $db->prepare('SELECT COUNT(*) FROM learner_accounts a JOIN staff_formations sf ON sf.staff_id=a.trainer_staff_id AND sf.formation_id=a.formation_id WHERE a.active=1 AND a.trainer_staff_id=?'); $s->execute([$staffId]); $learnerCount = (int)$s->fetchColumn();
        $s = $db->prepare('SELECT COUNT(*) FROM staff_formations WHERE staff_id=?'); $s->execute([$staffId]); $formationCount = (int)$s->fetchColumn();
        $s = $db->prepare('SELECT COUNT(*) FROM modules m JOIN staff_formations sf ON sf.formation_id=m.formation_id WHERE sf.staff_id=? AND m.active=1'); $s->execute([$staffId]); $moduleCount = (int)$s->fetchColumn();
        $sql = 'SELECT COUNT(*) FROM evaluations e WHERE EXISTS (SELECT 1 FROM learner_accounts la JOIN staff_formations sf ON sf.staff_id=la.trainer_staff_id AND sf.formation_id=la.formation_id WHERE la.learner_uid=e.learner_uid AND la.trainer_staff_id=? AND sf.formation_id=e.formation_id)'; $s = $db->prepare($sql); $s->execute([$staffId]); $testCount = (int)$s->fetchColumn();
        $sql = 'SELECT e.learner_name,e.formation_id,e.module_id,e.score,e.total,e.percentage,e.created FROM evaluations e WHERE EXISTS (SELECT 1 FROM learner_accounts la JOIN staff_formations sf ON sf.staff_id=la.trainer_staff_id AND sf.formation_id=la.formation_id WHERE la.learner_uid=e.learner_uid AND la.trainer_staff_id=? AND sf.formation_id=e.formation_id) ORDER BY e.created DESC LIMIT 8'; $s = $db->prepare($sql); $s->execute([$staffId]); $latest = $s->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $learnerCount = (int)$db->query('SELECT COUNT(*) FROM learner_accounts WHERE active=1')->fetchColumn();
        $formationCount = (int)$db->query('SELECT COUNT(*) FROM formations WHERE active=1')->fetchColumn();
        $moduleCount = (int)$db->query('SELECT COUNT(*) FROM modules WHERE active=1')->fetchColumn();
        $testCount = (int)$db->query('SELECT COUNT(*) FROM evaluations')->fetchColumn();
        $latest = $db->query('SELECT learner_name,formation_id,module_id,score,total,percentage,created FROM evaluations ORDER BY created DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
    }
    out(['learner_count' => $learnerCount, 'formation_count' => $formationCount, 'module_count' => $moduleCount, 'test_count' => $testCount, 'latest' => $latest]);

case 'audit_log_list':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $limit = filter_var($in['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
    if ($limit === false) out(['error' => 'bad_data']);
    $s = $db->prepare('SELECT id,actor_staff_id,actor_username,actor_role,action,resource_type,resource_id,details,created_at FROM audit_log ORDER BY id DESC LIMIT ?');
    $s->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $s->execute();
    out(['rows' => $s->fetchAll(PDO::FETCH_ASSOC)]);

case 'staff_list':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $rows = $db->query('SELECT id,username,first_name,last_name,role,active,created,must_change_password FROM staff ORDER BY created DESC')->fetchAll(PDO::FETCH_ASSOC);
    $sf = $db->prepare('SELECT formation_id FROM staff_formations WHERE staff_id=?');
    foreach ($rows as &$row) { $sf->execute([(int)$row['id']]); $row['formations'] = array_column($sf->fetchAll(PDO::FETCH_ASSOC), 'formation_id'); }
    unset($row);
    out(['rows' => $rows]);

case 'staff_create':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $first = trim(preg_replace('/\s+/u', ' ', (string)($in['first_name'] ?? '')));
    $last = trim(preg_replace('/\s+/u', ' ', (string)($in['last_name'] ?? '')));
    if ($first === '' || $last === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) out(['ok' => false, 'msg' => 'Le prénom et le nom sont obligatoires et ne doivent pas dépasser 100 caractères chacun.']);
    $r = (string)($in['role'] ?? 'formateur');
    if (!in_array($r, ['admin', 'formateur'], true)) out(['ok' => false, 'msg' => 'Rôle invalide.']);
    $formations = is_array($in['formations'] ?? null) ? $in['formations'] : [];
    $formations = array_values(array_filter($formations, function($f){ return is_string($f) && $f !== ''; }));
    if ($r === 'formateur' && count($formations) === 0) out(['ok' => false, 'msg' => 'Un formateur doit être affecté à au moins une formation.']);
    $base = strtoupper(preg_replace('/[^A-Za-z]/', '', $last . substr($first, 0, 1))); if ($base === '') $base = 'FORM';
    $u = ''; for ($i = 0; $i < 20; $i++) { $candidate = 'F-' . substr($base, 0, 8) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)); $check = $db->prepare('SELECT 1 FROM staff WHERE username=?'); $check->execute([$candidate]); if (!$check->fetchColumn()) { $u = $candidate; break; } }
    if ($u === '') out(['ok' => false, 'msg' => 'Impossible de générer un identifiant unique.']);
    $p = bin2hex(random_bytes(6)); $p = substr($p, 0, 12);
    try { $s = $db->prepare('INSERT INTO staff(username,password_hash,role,active,created,first_name,last_name,must_change_password,temporary_password,credential_cipher) VALUES(?,?,?,?,?,?,?,?,?,"")'); $s->execute([$u, password_hash($p, PASSWORD_DEFAULT), $r, 1, gmdate('c'), $first, $last, 1, 1]); $staffId = (int)$db->lastInsertId(); }
    catch (PDOException $e) { out(['ok' => false, 'msg' => 'Cet identifiant existe déjà.']); }
    if (count($formations) > 0) { $valid = $db->prepare('SELECT 1 FROM formations WHERE id=? AND active=1'); $ins = $db->prepare('INSERT OR IGNORE INTO staff_formations(staff_id,formation_id) VALUES(?,?)'); foreach ($formations as $fid) { $valid->execute([(string)$fid]); if ($valid->fetchColumn()) $ins->execute([$staffId, (string)$fid]); } }
    auditLog($db, 'staff_create', 'staff', (string)$staffId, ['username' => $u, 'role' => $r, 'formations' => $formations]);
    out(['ok' => true, 'username' => $u, 'temporary_password' => $p, 'role' => $r, 'formations' => $formations]);

case 'staff_update':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT); $r = (string)($in['role'] ?? ''); $active = !empty($in['active']) ? 1 : 0;
    if (!$id || !in_array($r, ['admin', 'formateur'], true)) out(['ok' => false, 'msg' => 'Paramètres invalides.']);
    $beforeQuery = $db->prepare('SELECT username,role,active,first_name,last_name FROM staff WHERE id=?'); $beforeQuery->execute([$id]); $before = $beforeQuery->fetch(PDO::FETCH_ASSOC);
    if (!$before) out(['error' => 'not_found']);
    if (hash_equals((string)($_SESSION['staff_username'] ?? ''), (string)$before['username'])) out(['error' => 'forbidden', 'msg' => 'Vous ne pouvez pas modifier votre propre compte d’administrateur.']);
    $beforeFormationsQuery = $db->prepare('SELECT formation_id FROM staff_formations WHERE staff_id=? ORDER BY formation_id'); $beforeFormationsQuery->execute([$id]); $beforeFormations = array_column($beforeFormationsQuery->fetchAll(PDO::FETCH_ASSOC), 'formation_id');
    $formationsProvided = is_array($in['formations'] ?? null);
    $formations = $formationsProvided ? array_values(array_unique(array_filter($in['formations'], function($f){ return is_string($f) && $f !== ''; }))) : $beforeFormations;
    if ($r === 'formateur' && count($formations) === 0) out(['ok' => false, 'msg' => 'Un formateur doit être affecté à au moins une formation.']);
    $validFormation = $db->prepare('SELECT 1 FROM formations WHERE id=? AND active=1');
    foreach ($formations as $fid) { $validFormation->execute([(string)$fid]); if (!$validFormation->fetchColumn()) out(['ok' => false, 'msg' => 'Une formation attribuée n’est pas disponible.']); }
    $first = isset($in['first_name']) ? trim(preg_replace('/\s+/u', ' ', (string)$in['first_name'])) : null;
    $last = isset($in['last_name']) ? trim(preg_replace('/\s+/u', ' ', (string)$in['last_name'])) : null;
    $s = $db->prepare('UPDATE staff SET role=?,active=?,first_name=CASE WHEN ? IS NULL THEN first_name ELSE ? END,last_name=CASE WHEN ? IS NULL THEN last_name ELSE ? END WHERE id=?');
    $s->execute([$r, $active, $first, $first, $last, $last, $id]);
    if ($formationsProvided) {
        $db->prepare('DELETE FROM staff_formations WHERE staff_id=?')->execute([$id]);
        $ins = $db->prepare('INSERT OR IGNORE INTO staff_formations(staff_id,formation_id) VALUES(?,?)');
        foreach ($formations as $fid) $ins->execute([$id, (string)$fid]);
    }
    $afterFormationsQuery = $db->prepare('SELECT formation_id FROM staff_formations WHERE staff_id=? ORDER BY formation_id'); $afterFormationsQuery->execute([$id]); $afterFormations = array_column($afterFormationsQuery->fetchAll(PDO::FETCH_ASSOC), 'formation_id');
    auditLog($db, 'staff_update', 'staff', (string)$id, ['before' => ['role' => $before['role'], 'active' => (int)$before['active'], 'formations' => $beforeFormations], 'after' => ['role' => $r, 'active' => $active, 'formations' => $afterFormations]]);
    out(['ok' => true]);

case 'staff_reset_password':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) out(['error' => 'bad_data']);
    $row = $db->prepare('SELECT username FROM staff WHERE id=?'); $row->execute([$id]); $username = $row->fetchColumn();
    if (!$username) out(['ok' => false, 'msg' => 'Utilisateur introuvable.']);
    $p = substr(bin2hex(random_bytes(6)), 0, 12);
    $s = $db->prepare('UPDATE staff SET password_hash=?, must_change_password=1, temporary_password=1, credential_cipher="" WHERE id=?');
    $s->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
    auditLog($db, 'staff_password_reset', 'staff', (string)$id, ['username' => $username]);
    out(['ok' => true, 'username' => $username, 'temporary_password' => $p]);

case 'staff_delete':
    if (!canAdmin()) out(['error' => 'forbidden']);
    $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) out(['ok' => false, 'msg' => 'Utilisateur invalide.']);
    $usernameQuery = $db->prepare('SELECT username FROM staff WHERE id=?'); $usernameQuery->execute([$id]); $deletedUsername = $usernameQuery->fetchColumn();
    if ($deletedUsername === false) out(['error' => 'not_found']);
    if (hash_equals((string)($_SESSION['staff_username'] ?? ''), (string)$deletedUsername)) out(['error' => 'forbidden', 'msg' => 'Vous ne pouvez pas supprimer votre propre compte.']);
    $assignedLearners = $db->prepare('SELECT COUNT(*) FROM learner_accounts WHERE trainer_staff_id=?'); $assignedLearners->execute([$id]);
    if ((int)$assignedLearners->fetchColumn() > 0) out(['ok' => false, 'msg' => 'Ce compte encadre encore des apprenants. Réaffectez-les ou désactivez le formateur avant de le supprimer.']);
    auditLog($db, 'staff_delete', 'staff', (string)$id, ['username' => (string)$deletedUsername]);
    $s = $db->prepare('DELETE FROM staff WHERE id=?'); $s->execute([$id]);
    out(['ok' => true]);

case 'learner_create':
    if (!canTrainer()) out(['error' => 'forbidden']);
    $first = trim(preg_replace('/\s+/u', ' ', (string)($in['first_name'] ?? '')));
    $last = trim(preg_replace('/\s+/u', ' ', (string)($in['last_name'] ?? '')));
    $formation = trim((string)($in['formation_id'] ?? ''));
    if ($first === '' || $last === '') out(['ok' => false, 'msg' => 'Le prénom et le nom sont obligatoires.']);
    $staffId = currentStaffId($db);
    if (!$staffId || !trainerCanFormation($db, $staffId, $formation)) out(['ok' => false, 'msg' => 'Cette formation ne vous est pas attribuée.']);
    $staffUsername = (string)($_SESSION['staff_username'] ?? '');
    $firstSlug = preg_replace('/[^a-z0-9]+/', '_', nm($first));
    $firstSlug = trim((string)$firstSlug, '_');
    if ($firstSlug === '') $firstSlug = 'apprenant';
    $identifier = '';
    $base = $firstSlug . '_ndartech';
    for ($i = 0; $i <= 9999; $i++) {
        $candidate = $i === 0 ? $base : $firstSlug . '_' . ($i + 1) . '_ndartech';
        $check = $db->prepare('SELECT 1 FROM learner_accounts WHERE LOWER(identifier)=LOWER(?) UNION ALL SELECT 1 FROM learners WHERE LOWER(learner_id)=LOWER(?) LIMIT 1');
        $check->execute([$candidate,$candidate]);
        if (!$check->fetchColumn()) { $identifier = $candidate; break; }
    }
    if ($identifier === '') out(['ok' => false, 'msg' => 'Impossible de générer un identifiant unique.']);
    $password = substr(bin2hex(random_bytes(6)), 0, 12);
    $uid = substr(bin2hex(random_bytes(8)), 0, 16);
    $name = $first . ' ' . $last;
    try {
        $db->beginTransaction();
        $s = $db->prepare('INSERT INTO learner_accounts(learner_uid,first_name,last_name,email,phone,password_hash,active,created,identifier,formation_id,trainer_staff_id,must_change_password,temporary_password,credential_cipher) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,"")');
        $s->execute([$uid,$first,$last,strtolower($identifier).'@learner.ndartech',(string)($in['phone'] ?? ''),password_hash($password, PASSWORD_DEFAULT),1,gmdate('c'),$identifier,$formation,$staffId,1,1]);
        $normalized = nm($name);
        $nameCheck = $db->prepare('SELECT 1 FROM learners WHERE normalized_name=? LIMIT 1');$nameCheck->execute([$normalized]);
        if($nameCheck->fetchColumn())$normalized.=' '.$uid;
        $learn = $db->prepare('INSERT INTO learners(name,normalized_name,learner_uid,learner_id,active,created,created_by) VALUES(?,?,?,?,1,?,?)');
        $learn->execute([$name,$normalized,$uid,$identifier,gmdate('c'),$staffUsername]);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        error_log('qcm learner_create: '.$e->getMessage());
        out(['ok'=>false,'msg'=>'Impossible de créer cet apprenant. Réessayez ou contactez l’administrateur.']);
    }
    auditLog($db, 'learner_create', 'learner', $uid, ['identifier' => $identifier, 'formation_id' => $formation]);
    out(['ok' => true, 'identifier' => $identifier, 'temporary_password' => $password, 'formation_id' => $formation, 'name' => $name]);

case 'learner_create_bulk':
    // La création en masse est désactivée : chaque apprenant doit être créé par un formateur,
    // avec son prénom, son nom, sa formation autorisée et un compte individuel.
    if (!canTrainer()) out(['error' => 'forbidden']);
    out(['ok' => false, 'msg' => 'Utilisez la création individuelle de l’apprenant.']);
    $names = $in['names'] ?? [];
    if (!is_array($names)) out(['error' => 'bad_data']);
    $createdBy = (string)($_SESSION['staff_username'] ?? ''); $created = gmdate('c'); $results = [];
    foreach (array_slice($names, 0, 300) as $rawName) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$rawName));
        if ($name === '' || mb_strlen($name) > 100) continue;
        $normalized = nm($name); $uid = substr(sha1($normalized), 0, 16); $learnerId = mkid($name);
        $existing = $db->prepare('SELECT name,learner_uid,learner_id,active FROM learners WHERE normalized_name=?'); $existing->execute([$normalized]); $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) { $db->prepare('UPDATE learners SET active=1 WHERE normalized_name=?')->execute([$normalized]); $learnerId = (string)$row['learner_id']; $uid = (string)$row['learner_uid']; }
        else { $s = $db->prepare('INSERT INTO learners(name,normalized_name,learner_uid,learner_id,active,created,created_by) VALUES(?,?,?,?,1,?,?)'); $s->execute([$name,$normalized,$uid,$learnerId,$created,$createdBy]); }
        $results[] = ['name' => $name, 'id' => $learnerId, 'uid' => $uid];
    }
    out(['ok' => true, 'rows' => $results]);

case 'learner_list':
    if (!canReadStaff()) out(['error' => 'forbidden']);
    if (canTrainer()) {
        $staffId = currentStaffId($db);
        $s = $db->prepare('SELECT l.id,l.name,l.learner_uid,l.learner_id,l.active,l.created,l.created_by,a.identifier,a.formation_id,a.must_change_password FROM learners l JOIN learner_accounts a ON a.learner_uid=l.learner_uid JOIN staff_formations sf ON sf.staff_id=a.trainer_staff_id AND sf.formation_id=a.formation_id WHERE a.trainer_staff_id=? ORDER BY l.name COLLATE NOCASE');
        $s->execute([$staffId]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $db->query('SELECT l.id,l.name,l.learner_uid,l.learner_id,l.active,l.created,l.created_by,a.identifier,a.formation_id,a.must_change_password FROM learners l LEFT JOIN learner_accounts a ON a.learner_uid=l.learner_uid ORDER BY l.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rows as &$row) $row['identifier'] = $row['identifier'] ?? $row['learner_id'];
    unset($row);
    out(['rows' => $rows]);

case 'learner_reset_password':
    if (!canTrainer() && !canAdmin()) out(['error' => 'forbidden']);
    $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) out(['error' => 'bad_data']);
    if (canTrainer() && !trainerOwnsLearner($db, currentStaffId($db), (int)$id)) out(['error' => 'forbidden']);
    $row = $db->prepare('SELECT learner_uid, learner_id FROM learners WHERE id=?'); $row->execute([$id]); $lr = $row->fetch(PDO::FETCH_ASSOC);
    if (!$lr) out(['ok' => false, 'msg' => 'Apprenant introuvable.']);
    $account = $db->prepare('SELECT identifier,learner_id FROM learner_accounts a LEFT JOIN learners l ON l.learner_uid=a.learner_uid WHERE a.learner_uid=?'); $account->execute([$lr['learner_uid']]); $credentials = $account->fetch(PDO::FETCH_ASSOC);
    $identifier = (string)($credentials['identifier'] ?? $lr['learner_id']);
    $p = substr(bin2hex(random_bytes(6)), 0, 12);
    $s = $db->prepare('UPDATE learner_accounts SET password_hash=?, must_change_password=1, temporary_password=1, credential_cipher="" WHERE learner_uid=?');
    $s->execute([password_hash($p, PASSWORD_DEFAULT), $lr['learner_uid']]);
    auditLog($db, 'learner_password_reset', 'learner', (string)$lr['learner_uid'], ['identifier' => $identifier]);
    out(['ok' => true, 'identifier' => $identifier, 'temporary_password' => $p]);

case 'learner_update':
    if (!canTrainer() && !canAdmin()) out(['error' => 'forbidden']);
    $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id && canTrainer() && !trainerOwnsLearner($db, currentStaffId($db), (int)$id)) out(['error' => 'forbidden']);
    if (!$id) out(['error' => 'bad_data']);
    $row = $db->prepare('SELECT l.learner_uid,l.name,a.formation_id FROM learners l LEFT JOIN learner_accounts a ON a.learner_uid=l.learner_uid WHERE l.id=?'); $row->execute([$id]); $lr = $row->fetch(PDO::FETCH_ASSOC);
    if (!$lr) out(['ok' => false, 'msg' => 'Apprenant introuvable.']);
    if (isset($in['formation_id'])) {
        $newFormation = (string)$in['formation_id'];
        if (canTrainer() && !trainerCanFormation($db, currentStaffId($db), $newFormation)) out(['ok' => false, 'msg' => 'Cette formation ne vous est pas attribuée.']);
        $formationCheck=$db->prepare('SELECT 1 FROM formations WHERE id=? AND active=1');$formationCheck->execute([$newFormation]);
        if(!$formationCheck->fetchColumn())out(['ok'=>false,'msg'=>'Cette formation n’est pas disponible.']);
    }
    if (isset($in['name'])) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$in['name']));
        if ($name !== '') {
            if(mb_strlen($name)>201)out(['ok'=>false,'msg'=>'Le nom ne doit pas dépasser 201 caractères.']);
            $normalized = nm($name);
            $nameCheck=$db->prepare('SELECT 1 FROM learners WHERE normalized_name=? AND id<>? LIMIT 1');$nameCheck->execute([$normalized,$id]);
            if($nameCheck->fetchColumn())$normalized.=' '.(string)$lr['learner_uid'];
            $db->prepare('UPDATE learners SET name=?, normalized_name=? WHERE id=?')->execute([$name, $normalized, $id]);
            $parts = preg_split('/\s+/u', $name, 2);
            $first = $parts[0] ?? ''; $last = $parts[1] ?? '';
            $db->prepare('UPDATE learner_accounts SET first_name=?, last_name=? WHERE learner_uid=?')->execute([$first, $last, $lr['learner_uid']]);
        }
    }
    if (isset($in['formation_id'])) {
        $db->prepare('UPDATE learner_accounts SET formation_id=? WHERE learner_uid=?')->execute([(string)$in['formation_id'], $lr['learner_uid']]);
    }
    $after = $db->prepare('SELECT l.name,a.formation_id FROM learners l LEFT JOIN learner_accounts a ON a.learner_uid=l.learner_uid WHERE l.id=?'); $after->execute([$id]); $updated = $after->fetch(PDO::FETCH_ASSOC);
    auditLog($db, 'learner_update', 'learner', (string)$lr['learner_uid'], ['before' => ['name' => $lr['name'], 'formation_id' => $lr['formation_id']], 'after' => ['name' => $updated['name'], 'formation_id' => $updated['formation_id']]]);
    out(['ok' => true]);

case 'learner_set_active':
    if (!canTrainer() && !canAdmin()) out(['error' => 'forbidden']);
    $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id && canTrainer() && !trainerOwnsLearner($db, currentStaffId($db), (int)$id)) out(['error' => 'forbidden']);
    $active = isset($in['active']) ? (int)$in['active'] : null;
    if (!$id || $active === null) out(['error' => 'bad_data']);
    $value = $active ? 1 : 0;
    $row = $db->prepare('SELECT learner_uid,active FROM learners WHERE id=?'); $row->execute([$id]); $learner = $row->fetch(PDO::FETCH_ASSOC);
    if (!$learner) out(['error' => 'not_found']);
    try {
        $db->beginTransaction();
        $s = $db->prepare('UPDATE learners SET active=? WHERE id=?'); $s->execute([$value, $id]);
        $learnerUid = $learner['learner_uid'];
        if ($learnerUid !== false) { $a = $db->prepare('UPDATE learner_accounts SET active=? WHERE learner_uid=?'); $a->execute([$value, $learnerUid]); }
        auditLog($db, $value ? 'learner_activate' : 'learner_deactivate', 'learner', (string)$learnerUid, ['before_active' => (int)$learner['active'], 'after_active' => $value]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    out(['ok' => true]);

case 'learner_delete':
    if (!canTrainer() && !canAdmin()) out(['error' => 'forbidden']);
    $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) out(['error' => 'bad_data']);
    if (canTrainer() && !trainerOwnsLearner($db, currentStaffId($db), (int)$id)) out(['error' => 'forbidden']);
    $row = $db->prepare('SELECT learner_uid,active FROM learners WHERE id=?'); $row->execute([$id]); $learner = $row->fetch(PDO::FETCH_ASSOC);
    if (!$learner) out(['error' => 'not_found']);
    $learnerUid = $learner['learner_uid'];
    try {
        $db->beginTransaction();
        $db->prepare('UPDATE learners SET active=0 WHERE id=?')->execute([$id]);
        if ($learnerUid !== false) { $db->prepare('UPDATE learner_accounts SET active=0 WHERE learner_uid=?')->execute([$learnerUid]); }
        auditLog($db, 'learner_deactivate', 'learner', (string)$learnerUid, ['before_active' => (int)$learner['active'], 'after_active' => 0]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    out(['ok' => true]);

case 'delete_result':
    if (!canAdmin()) out(['error' => 'forbidden']);
    out(['error' => 'result_immutable', 'msg' => 'Les résultats enregistrés sont conservés et ne peuvent pas être supprimés.']);

case 'get':
    $p = path_of($in);
    if (!allowed($db, $p)) out(['error' => 'forbidden']);
    $s = $db->prepare('SELECT data FROM kv WHERE path=?'); $s->execute([$p]); $d = $s->fetchColumn();
    out($d === false ? ['exists' => false] : ['exists' => true, 'data' => json_decode($d, true)]);

case 'set':
    $p = path_of($in);
    if (!allowed($db, $p, true)) out(['error' => 'forbidden']);
    $d = $in['data'] ?? null;
    if (!is_array($d)) out(['error' => 'bad_data']);
    if (role() === 'learner') { // un apprenant ne peut écrire que des codes de résultat bien formés
        $codes = array_values(array_filter((array)($d['codes'] ?? []), fn($c) => is_string($c) && strlen($c) <= 3000 && preg_match('#^[A-Za-z0-9+/=]+$#', $c)));
        $submissionId = (string)($d['submission_id'] ?? '');
        if (!preg_match('#^[A-Za-z0-9_-]{8,64}$#', $submissionId)) $submissionId = '';
        $existing = $db->prepare('SELECT data FROM kv WHERE path=?'); $existing->execute([$p]); $existingRow = $existing->fetchColumn();
        $submissions = [];
        if ($existingRow !== false) { $old = json_decode($existingRow, true); if (is_array($old)) $submissions = array_values(array_filter((array)($old['submission_ids'] ?? []), fn($v) => is_string($v))); }
        if ($submissionId !== '' && !in_array($submissionId, $submissions, true)) $submissions[] = $submissionId;
        $d = ['name' => $_SESSION['name'], 'codes' => array_slice($codes, -80), 'submission_ids' => array_slice($submissions, -80), 't' => gmdate('c')];
    }
    $j = json_encode($d, JSON_UNESCAPED_UNICODE);
    if (strlen($j) > 200000) out(['error' => 'too_big']);
    $s = $db->prepare('INSERT INTO kv(path,data,updated) VALUES(?,?,?) ON CONFLICT(path) DO UPDATE SET data=excluded.data, updated=excluded.updated');
    $s->execute([$p, $j, gmdate('c')]);
    out(['ok' => true]);

case 'list':
    $pre = (string)($in['prefix'] ?? '');
    if (!preg_match('#^[A-Za-z0-9_-]+$#', $pre)) out(['error' => 'bad_path']);
    if (!canAdmin() && !(canTrainer() && $pre === 'results')) out(['error' => 'forbidden']);
    $s = $db->prepare('SELECT path,data FROM kv WHERE path LIKE ? ORDER BY path'); $s->execute([$pre . '/%']);
    $rows = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (canTrainer() && (!preg_match('#^results/([A-Za-z0-9_-]+)$#', (string)$r['path'], $match) || !trainerOwnsLearnerUid($db, currentStaffId($db), $match[1]))) continue;
        $rows[] = ['path' => $r['path'], 'data' => json_decode($r['data'], true)];
    }
    out(['rows' => $rows]);

default:
    out(['error' => 'unknown_action']);
}
