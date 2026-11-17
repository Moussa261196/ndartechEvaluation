<?php
error_reporting(0);
echo "=== TEST GESTION UTILISATEURS NdarTech ===\n\n";

// Test 1: Check if server is running and API responds
echo "--- Test 1: Vérification serveur ---\n";
$ch = curl_init("http://localhost:8000/api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "a=install&key=TEST_KEY_ADMIN123456789012345678901234567890&u=admin&p=admin12345");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Requested-With: qcm"]);
$install = curl_exec($ch);
curl_close($ch);
echo "Installation: " . substr($install, 0, 200) . "\n\n";

// Test 2: Try to create formateur as admin (assuming install succeeded)
echo "--- Test 2: Créer formateur ---\n";
$ch = curl_init("http://localhost:8000/api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "a=create_user&role=formateur&u=formateur_test&p=password12345");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Requested-With: qcm"]);
$formateur = curl_exec($ch);
curl_close($ch);
echo "Formateur creation: " . substr($formateur, 0, 200) . "\n\n";

// Test 3: Try to create lecture user
echo "--- Test 3: Créer lecture ---\n";
$ch = curl_init("http://localhost:8000/api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "a=create_user&role=lecture&u=lecture_test&p=password12345");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Requested-With: qcm"]);
$lecture = curl_exec($ch);
curl_close($ch);
echo "Lecture creation: " . substr($lecture, 0, 200) . "\n\n";

// Test 4: List users
echo "--- Test 4: Lister les utilisateurs ---\n";
$ch = curl_init("http://localhost:8000/api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "a=list_users");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Requested-With: qcm"]);
$users = curl_exec($ch);
curl_close($ch);
echo "Utilisateurs: " . substr($users, 0, 300) . "\n\n";

// Test 5: Try learner accessing a test
echo "--- Test 5: Apprenant accède test ---\n";
// First login as a user
$ch = curl_init("http://localhost:8000/api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "a=login&u=admin&p=admin12345");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Requested-With: qcm"]);
$login = curl_exec($ch);
curl_close($ch);
echo "Login admin: " . substr($login, 0, 200) . "\n\n";

echo "=== Fin des tests ===\n";
?>