<?php
error_reporting(0);
$content = file_get_contents("http://localhost:8000/api.php");
echo "Status: " . $content;
?>