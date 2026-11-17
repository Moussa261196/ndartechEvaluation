<?php
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
function h($v){ return print_r($v, true); }
echo "== STAFF ==\n" . h($db->query("SELECT id, username, role, active FROM staff ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
echo "\n== LEARNER_ACCOUNTS ==\n" . h($db->query("SELECT id, username, email, formation_id, active, password_hash, temp_password_hash FROM learner_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
echo "\n== FORMATIONS ==\n" . h($db->query("SELECT id, code, name, active FROM formations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
echo "\n== STAFF_FORMATIONS ==\n" . h($db->query("SELECT s.username, f.code, sf.formation_id FROM staff_formations sf JOIN staff s ON s.id=sf.staff_id JOIN formations f ON f.id=sf.formation_id ORDER BY s.id")->fetchAll(PDO::FETCH_ASSOC));
echo "\n== ACTIVE LEVELS per formation ==\n";
$r = $db->query("SELECT formation_id, COUNT(*) as cnt FROM test_levels WHERE active=1 GROUP BY formation_id");
while($row = $r->fetch()){ echo "$row[formation_id]: $row[cnt] niveaux actifs\n"; }
echo "\n== INACTIVE LEVELS ==\n" . h($db->query("SELECT id, formation_id FROM test_levels WHERE active=0")->fetchAll(PDO::FETCH_ASSOC));
echo "\n== QUESTIONS per level ==\n";
$r = $db->query("SELECT level_id, COUNT(*) as cnt FROM questions GROUP BY level_id ORDER BY level_id");
while($row = $r->fetch()){ echo "$row[level_id]: $row[cnt]\n"; }
?>

