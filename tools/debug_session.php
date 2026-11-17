<?php
// Enable error reporting
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Simulate trainer login
$_SESSION['admin'] = false;
$_SESSION['staff_role'] = 'formateur';
$_SESSION['staff_username'] = 'F_moussa';

// Include api.php (it will start session and set up functions)
require_once 'C:/xampp/htdocs/ndartechTests/api.php';

// Test role function
echo "Role: " . role() . "\n";
echo "Staff Role: " . staffRole() . "\n";
echo "Can Trainer: " . (canTrainer() ? 'true' : 'false') . "\n";
echo "Can Read Staff: " . (canReadStaff() ? 'true' : 'false') . "\n";
echo "Can Manage: " . (canManage() ? 'true' : 'false') . "\n";

// Check session variables
echo "\nSession variables:\n";
echo "admin: " . ($_SESSION['admin'] ?? 'not set') . "\n";
echo "staff_role: " . ($_SESSION['staff_role'] ?? 'not set') . "\n";
?>