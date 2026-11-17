<?php
// Enable error display
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "=== TEST API (with error display) ===\n\n";

// Test login
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:8888/api.php?a=login");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"identifier":"F_moussa","password":"moussatech@2026"}');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-Requested-With: qcm"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $header_size);
$body = substr($response, $header_size);
curl_close($ch);

echo "LOGIN: $body\n\n";

// Extract session ID
preg_match('/PHPSESSID=([^;]+)/', $headers, $matches);
$sessionId = $matches[1];
echo "Session: $sessionId\n\n";

// Test test_levels_list
echo "=== TEST LEVELS LIST ===\n";
$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, "http://127.0.0.1:8888/api.php?a=test_levels_list");
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, "{}");
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-Requested-With: qcm",
    "Cookie: PHPSESSID=$sessionId"
]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$response2 = curl_exec($ch2);
$body2 = $response2;
$http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP Code: $http_code2\n";
echo "Body: $body2\n\n";

// Get PHP error log
echo "=== PHP ERROR LOG ===\n";
echo shell_exec('type C:\\laragon\\bin\\php-8.3.30-Win32-vs16-x64\\php.ini 2^>nul | findstr /i "error_log"') ?: "Not found\n";
echo shell_exec('type C:\\xampp\\php\\php.ini 2^>nul | findstr /i "error_log"') ?: "Not found\n";
?>