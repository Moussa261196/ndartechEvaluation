<?php
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
echo "=== TABLES ===\n";
$tables = $db->query("SELECT name FROM sqlite_master WHERE type=\"table\"")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t){ echo " - " . $t . "\n"; }
echo "\n=== COUNTS ===\n";
$tables2 = ["staff","learner_accounts","formations","test_levels","questions","cfg"];
foreach($tables2 as $t){
  try{ $c = $db->query("SELECT COUNT(*) FROM $t")->fetchColumn(); echo "$t: $c\n"; }
  catch(Exception $e){ echo "$t: ERROR - " . $e->getMessage() . "\n"; }
}
echo "\n=== TEST LEVELS ===\n";
try{
  $r = $db->query("SELECT id,formation_id,module_id,name,active FROM test_levels ORDER BY position");
  while($row = $r->fetch()){ print_r($row); }
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
echo "\n=== CONFIG ===\n";
try{
  $r = $db->query("SELECT * FROM cfg");
  while($row = $r->fetch()){ print_r($row); }
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
?>

echo "\n=== STAFF ===\n";
try{
  $r = $db->query("SELECT id, username, password_hash, role, active, created_at FROM staff ORDER BY id");
  while($row = $r->fetch()){ print_r($row); }
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
echo "\n=== LEARNER ACCOUNTS ===\n";
try{
  $r = $db->query("SELECT id, uid, username, email, formation_id, active, password_hash, temp_password_hash FROM learner_accounts ORDER BY id");
  while($row = $r->fetch()){ print_r($row); }
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
echo "\n=== FORMATIONS ===\n";
try{
  $r = $db->query("SELECT id, code, name, active FROM formations ORDER BY id");
  while($row = $r->fetch()){ print_r($row); }
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
echo "\n=== STAFF FORMATIONS ===\n";
try{
  $r = $db->query("SELECT sf.staff_id, s.username, sf.formation_id, f.code FROM staff_formations sf JOIN staff s ON s.id=sf.staff_id JOIN formations f ON f.id=sf.formation_id ORDER BY sf.staff_id");
  while($row = $r->fetch()){ print_r($row); }
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
echo "\n=== TEST LEVELS (active=0) ===\n";
try{
  $r = $db->query("SELECT id,formation_id,module_id,name,active FROM test_levels WHERE active=0 ORDER BY position");
  $count = 0;
  while($row = $r->fetch()){ $count++; echo "  - $row[id] (active=$row[active])\n"; }
  if($count==0) echo "  (aucun niveau inactive)\n";
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
echo "\n=== QUESTIONS COUNT per level ===\n";
try{
  $r = $db->query("SELECT level_id, COUNT(*) as cnt FROM questions GROUP BY level_id ORDER BY level_id");
  while($row = $r->fetch()){ echo "  $row[level_id]: $row[cnt]\n"; }
  echo "\n";
}catch(Exception $e){ echo "Error: " . $e->getMessage() . "\n"; }
?>

