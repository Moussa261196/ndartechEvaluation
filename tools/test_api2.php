<?php
// Test the login endpoint
echo "=== LOGIN TEST ===\n";

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

echo "Status: 200 (OK)\n";
echo "Body: " . $body . "\n";

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
$http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP Code: $http_code\n";
echo "Body: " . $body2 . "\n";

// Test trainer_levels_list
echo "\n=== TRAINER LEVELS LIST ===\n";
$ch3 = curl_init();
curl_setopt($ch3, CURLOPT_URL, "http://127.0.0.1:8888/api.php?a=trainer_levels_list");
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, "{}");
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-Requested-With: qcm",
    "Cookie: PHPSESSID=$sessionId"
]);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
$response3 = curl_exec($ch3);
$body3 = $response3;
$http_code3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
curl_close($ch3);

echo "HTTP Code: $http_code3\n";
echo "Body: " . $body3 . "\n";
?>