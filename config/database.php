<?php
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "u972459197_south_meridian"; 

$conn = new mysqli(
    $db_host,
    $db_user,
    $db_pass,
    $db_name
);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>