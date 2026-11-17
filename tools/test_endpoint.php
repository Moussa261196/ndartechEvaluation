<?php
// Comprehensive test of the test_levels_list endpoint
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");

echo "=== TEST: TRAINER LEVELS LIST ===\n\n";

// Simulate session from trainer login
$_SESSION = [];
$_SESSION['admin'] = false;
$_SESSION['staff_role'] = 'formateur';
$_SESSION['staff_username'] = 'F_moussa';
$_SESSION['last_activity'] = time();

// Include api.php
require_once 'C:/xampp/htdocs/ndartechTests/api.php';

// Test role()
echo "Role: " . role() . "\n";
echo "CanTrainer: " . (canTrainer() ? 'true' : 'false') . "\n";
echo "CurrentStaffId: " . currentStaffId($db) . "\n\n";

// Build the exact query from test_levels_list
$formationId = '';
$moduleId = '';
$in = [];

$sql = 'SELECT l.*,m.name AS module_name,m.duration_minutes,m.question_count FROM test_levels l JOIN modules m ON m.id=l.module_id WHERE l.active=1';
$params = [];

if ($formationId !== '') { $sql .= ' AND l.formation_id=?'; $params[] = $formationId; }
if ($moduleId !== '') { $sql .= ' AND l.module_id=?'; $params[] = $moduleId; }

if (role() === 'learner') {
    $own = (string)($_SESSION['formation_id'] ?? '');
    if ($own === '') echo "Learner has no formation\n";
    $formationId = $own;
    $sql = 'SELECT l.*,m.name AS module_name,m.duration_minutes,m.question_count FROM test_levels l JOIN modules m ON m.id=l.module_id WHERE l.active=1 AND l.formation_id=? ORDER BY l.formation_id,l.position,l.name';
    $params = [$own];
}

if (canTrainer() && $formationId !== '' && !trainerCanFormation($db, currentStaffId($db), $formationId)) {
    echo "Forbidden: trainer cannot access this formation\n";
}

if (canTrainer()) {
    $staffId = currentStaffId($db);
    $sql .= ' AND EXISTS (SELECT 1 FROM staff_formations sf WHERE sf.staff_id=? AND sf.formation_id=l.formation_id)';
    $params[] = $staffId;
}

$sql .= ' ORDER BY l.formation_id,l.position,l.name';

echo "Final SQL: $sql\n";
echo "Params: [" . implode(', ', $params) . "]\n\n";

try {
    $s = $db->prepare($sql);
    $s->execute($params);
    $results = $s->fetchAll(PDO::FETCH_ASSOC);
    echo "SUCCESS! Found " . count($results) . " levels:\n";
    foreach ($results as $r) {
        echo "  - {$r['id']}: {$r['name']} (module: {$r['module_id']})\n";
    }
} catch (PDOException $e) {
    echo "PDOException: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>