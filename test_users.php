<?php
error_reporting(0);
$db = new PDO("sqlite:", [\ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);
// Try to open the database
try {
    $db = new PDO("sqlite:c:\xampp\htdocs\ndartechTests\data\qcm-c69302a2a9c569f.sqlite");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->query("SELECT * FROM users");
    while ($row = $stmt->fetch()) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>