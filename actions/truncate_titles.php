<?php
// actions/truncate_titles.php
$pdo = require_once __DIR__ . '/../config/database.php';

echo "WARNING: This will delete ALL manga titles and your reading history.\n";
echo "Are you sure you want to proceed? (yes/no): ";
$handle = fopen ("php://stdin","r");
$line = fgets($handle);
if(trim($line) != 'yes'){
    echo "Aborting.\n";
    exit;
}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE cp_reading_history;");
    $pdo->exec("TRUNCATE TABLE cp_user_titles;");
    $pdo->exec("TRUNCATE TABLE cp_titles;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "Successfully truncated cp_titles, cp_user_titles, and cp_reading_history!\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
