<?php
// Enable error reporting for debugging
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Test API endpoints
// 1. Login
$postdata = json_encode([
    "identifier" => "F_moussa",
    "password" => "moussatech@2026"
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:8888/api.php?a=login");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
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

echo "=== LOGIN RESPONSE ===\n";
echo "Body: " . $body . "\n\n";

// Extract session ID
preg_match('/PHPSESSID=([^;]+)/', $headers, $matches);
$sessionId = $matches[1];
echo "Session ID: $sessionId\n\n";

// 2. Test test_levels_list
$postdata2 = json_encode([]);
$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, "http://127.0.0.1:8888/api.php?a=test_levels_list");
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $postdata2);
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

echo "=== TEST_LEVELS_LIST ===\n";
echo "HTTP Status: $http_code\n";
echo "Body: " . $body2 . "\n";

// Check if there's a PHP error in the response
if(strpos($body2, 'server_error') !== false){
    echo "\n⚠️  Server error - checking php.ini log\n";
}
?>