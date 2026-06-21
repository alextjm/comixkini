<?php
// Returns a PDO instance
$dbHost = 'localhost';
$dbName = 'comixkini';
$dbUser = 'comixpass';
$dbPass = 'uRJm54p$&5Ez';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}
?>

