<?php
// actions/update_schema.php
$pdo = require_once __DIR__ . '/../config/database.php';

echo "Updating database schema to support longer manga IDs...\n";

$tablesToUpdate = [
    'cp_titles' => 'manga_id',
    'cp_reading_history' => 'manga_id',
    'cp_user_titles' => 'manga_id'
];

foreach ($tablesToUpdate as $table => $column) {
    try {
        // Increase the size of manga_id to 255 characters
        $sql = "ALTER TABLE {$table} MODIFY {$column} VARCHAR(255) NOT NULL";
        $pdo->exec($sql);
        echo "Successfully updated {$table}.{$column} to VARCHAR(255).\n";
    } catch (PDOException $e) {
        // Table or column might not exist or already updated
        echo "Note for {$table}: " . $e->getMessage() . "\n";
    }
}

try {
    // Also update consumet_id in cp_titles as it might get long too
    $sql = "ALTER TABLE cp_titles MODIFY consumet_id VARCHAR(255)";
    $pdo->exec($sql);
    echo "Successfully updated cp_titles.consumet_id to VARCHAR(255).\n";
} catch (PDOException $e) {
    echo "Note for consumet_id: " . $e->getMessage() . "\n";
}

echo "Schema update complete!\n";
?>
