<?php
// Check modules table schema
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
echo "=== MODULES TABLE SCHEMA ===\n";
$r = $db->query("SELECT sql FROM sqlite_master WHERE type=\"table\" AND name=\"modules\"");
while($row = $r->fetch()){
    echo $row["sql"] . "\n";
}
echo "\n=== MODULES ROWS ===\n";
$r = $db->query("SELECT * FROM modules");
while($row = $r->fetch()){
    print_r($row);
}
?>
