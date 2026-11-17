<?php
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
$result = $db->query("SELECT k, v FROM cfg WHERE k LIKE 'login_attempt_user%' OR k LIKE 'login_lock_user%' ORDER BY k");
echo "=== LOGIN ATTEMPT CONFIGS ===\n";
while($row = $result->fetch()){
    echo $row['k'] . ": " . $row['v'] . "\n";
}
echo "\n=== CURRENT TIME ===\n";
echo time() . "\n";
?>
