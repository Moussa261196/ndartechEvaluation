<?php
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
echo "=== FORMATIONS TABLE SCHEMA ===\n";
$result = $db->query("SELECT sql FROM sqlite_master WHERE type=\"table\" AND name=\"formations\"");
while($row = $result->fetch()){
    echo $row["sql"] . "\n";
}
echo "\n=== STAFF FORMATIONS ===\n";
$result = $db->query("SELECT sf.staff_id, s.username, sf.formation_id, f.id, f.name 
                      FROM staff_formations sf 
                      JOIN staff s ON s.id=sf.staff_id 
                      JOIN formations f ON f.id=sf.formation_id 
                      ORDER BY s.username, f.name");
while($row = $result->fetch()){
    echo "Trainer: " . $row['username'] . " -> Formation: " . $row['id'] . " (" . $row['name'] . ")\n";
}
echo "\n=== LEVELS per FORMATION ===\n";
$result2 = $db->query("SELECT formation_id, COUNT(*) as cnt FROM test_levels WHERE active=1 GROUP BY formation_id");
while($row = $result2->fetch()){
    echo $row['formation_id'] . ": " . $row['cnt'] . " niveaux actifs\n";
}
?>
