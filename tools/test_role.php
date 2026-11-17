<?php
// Test connection
echo "Testing connection...\n";
$db = new PDO("sqlite:C:/xampp/htdocs/ndartechTests/data/qcm-c69302a2a9ce569f.sqlite");
echo "Connection OK\n";

// Test role() function with simulated session
echo "\n=== Testing role() function ===\n";

// Simulate trainer login session
$_SESSION = array();
$_SESSION['admin'] = false;
$_SESSION['staff_role'] = 'formateur';
$_SESSION['staff_username'] = 'F_moussa';

// Include api.php
require_once 'C:/xampp/htdocs/ndartechTests/api.php';

echo "Role() = " . role() . "\n";
echo "CanTrainer() = " . (canTrainer() ? 'true' : 'false') . "\n";
echo "CanReadStaff() = " . (canReadStaff() ? 'true' : 'false') . "\n";
echo "StaffRole() = " . staffRole() . "\n";
?>