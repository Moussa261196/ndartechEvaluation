<?php
/**
 * Tests unitaires pour la plateforme NdarTech
 * Exécuter via: php tests/run.php
 */

// Simulation de l'environnement nécessaire pour tester api.php
if (!defined('SETUP_KEY')) define('SETUP_KEY', 'TEST_KEY_LONG_ENOUGH_FOR_THE_SETUP_CHECK');
if (!defined('SALT')) define('SALT', 'ndartech-c4');

// Inclusion manuelle des fonctions à tester depuis api.php
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

// Couleurs pour le terminal
$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";

echo "Demarrage des tests NdarTech...\n\n";
$fails = 0;

function assert_test($cond, $msg) {
    global $green, $red, $reset, $fails;
    if ($cond) echo "{$green}PASS{$reset}: $msg\n";
    else { echo "{$red}FAIL{$reset}: $msg\n"; $fails++; }
}

// 1. Test de normalisation (nm)
assert_test(nm("Moussa FALL") === "moussa fall", "Normalisation minuscule et espaces");
assert_test(nm("Éléphant à la fête") === "elephant a la fete", "Retrait des accents");

// 2. Test de hachage d'identifiant (hs)
assert_test(strlen(hs("test")) > 0, "Generation du hash");
assert_test(hs("user1") === hs("user1"), "Stabilite du hash");

// 3. Test de protection des données (publicQuestions)
$snapshot = [
    [
        'id' => 1, 'question' => 'Q1', 'explanation' => 'E1',
        'answers' => [['id' => 10, 'answer' => 'A1', 'is_correct' => 1]]
    ]
];

$public = publicQuestions($snapshot, false);
assert_test(!isset($public[0]['explanation']), "Masquage de l'explication (reveal=false)");
assert_test(!isset($public[0]['answers'][0]['is_correct']), "Masquage de la bonne réponse (reveal=false)");

$revealed = publicQuestions($snapshot, true);
assert_test(isset($revealed[0]['explanation']), "Affichage de l'explication (reveal=true)");
assert_test($revealed[0]['answers'][0]['is_correct'] === 1, "Affichage de la bonne réponse (reveal=true)");

echo "\nTests termines. Resultat: " . ($fails === 0 ? "{$green}SUCCESS" : "{$red}FAILED ({$fails} erreur(s))") . "{$reset}\n";
exit($fails === 0 ? 0 : 1);
