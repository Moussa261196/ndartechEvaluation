<?php
// Check staff_formations table
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
echo "=== STAFF_FORMATIONS TABLE SCHEMA ===\n";
$r = $db->query("SELECT sql FROM sqlite_master WHERE type=\"table\" AND name=\"staff_formations\"");
while($row = $r->fetch()){
    echo $row["sql"] . "\n";
}
echo "\n=== STAFF_FORMATIONS ROWS ===\n";
$r = $db->query("SELECT * FROM staff_formations");
while($row = $r->fetch()){
    print_r($row);
}
?>
