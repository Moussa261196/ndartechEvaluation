<?php
// Direct test of the test_levels_list query
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
echo "=== DIRECT QUERY TEST ===\n\n";

// Test role function logic (mimic the session from a trainer login)
$_SESSION = array();
$_SESSION['admin'] = false;
$_SESSION['staff_role'] = 'formateur';
$_SESSION['staff_username'] = 'F_moussa';

// Include api.php (this runs the session setup and defines functions)
require_once 'C:/xampp/htdocs/ndartechTests/api.php';

// Test role()
echo "Role: " . role() . "\n";
echo "CanTrainer: " . (canTrainer() ? 'true' : 'false') . "\n";
echo "CanReadStaff: " . (canReadStaff() ? 'true' : 'false') . "\n\n";

// Test the query that test_levels_list uses
echo "=== QUERY TEST ===\n";
$in = [];
$formationId = '';
$moduleId = '';
$sql = 'SELECT l.*,m.name AS module_name,m.duration_minutes,m.question_count FROM test_levels l JOIN modules m ON m.id=l.module_id WHERE l.active=1 ORDER BY l.formation_id,l.position,l.name';
$params = [];

if ($formationId !== '') { $sql .= ' AND l.formation_id=?'; $params[] = $formationId; }
if ($moduleId !== '') { $sql .= ' AND l.module_id=?'; $params[] = $moduleId; }

if (role() === 'learner') {
    $own = (string)($_SESSION['formation_id'] ?? '');
    if ($own === '') { echo "Learner has no formation_id\n"; }
    $formationId = $own;
    $sql = 'SELECT l.*,m.name AS module_name,m.duration_minutes,m.question_count FROM test_levels l JOIN modules m ON m.id=l.module_id WHERE l.active=1 AND l.formation_id=? ORDER BY l.formation_id,l.position,l.name';
    $params = [$own];
    if ($moduleId !== '') { $sql .= ' AND l.module_id=?'; $params[] = $moduleId; }
}

if (canTrainer() && $formationId !== '' && !trainerCanFormation($db, currentStaffId($db), $formationId)) {
    echo "Forbidden: trainer cannot access this formation\n";
}

if (canTrainer()) {
    $staffId = currentStaffId($db);
    $sql .= ' AND EXISTS (SELECT 1 FROM staff_formations sf WHERE sf.staff_id=? AND sf.formation_id=l.formation_id)';
    $params[] = $staffId;
}

echo "SQL: $sql\n";
echo "Params: " . implode(', ', $params) . "\n\n";

try {
    $s = $db->prepare($sql);
    $s->execute($params);
    $levels = $s->fetchAll(PDO::FETCH_ASSOC);
    echo "Query OK! Found " . count($levels) . " levels\n";
    foreach ($levels as $level) {
        echo "  - {$level['id']}: {$level['name']} (module: {$level['module_id']})\n";
    }
} catch (PDOException $e) {
    echo "Query FAILED: " . $e->getMessage() . "\n";
}
?>