<?php
error_reporting(0);
$ch = curl_init("http://localhost:8000/api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "action=install&key=TEST_KEY_LONG_ENOUGH&u=moussa&p=password123");
$output = curl_exec($ch);
curl_close($ch);
echo $output;
?>