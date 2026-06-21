<?php
$pdo = require __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT manga_id, title, en_chapter_count FROM cp_titles WHERE is_active = 1 ORDER BY RAND() LIMIT 1");
$manga = $stmt->fetch();

if ($manga) {
    echo "========================================\n";
    echo " Random Active Title Verification \n";
    echo "========================================\n";
    echo "Title:        " . $manga['title'] . "\n";
    echo "Manga ID:     " . $manga['manga_id'] . "\n";
    echo "EN Chapters:  " . $manga['en_chapter_count'] . "\n";
    echo "----------------------------------------\n";
    echo "Verification URL:\n";
    echo "https://mangadex.org/title/" . $manga['manga_id'] . "\n";
    echo "========================================\n";
    echo "Click the link above, go to the Chapters tab, and ensure images load properly.\n";
} else {
    echo "No active titles found in the database. Did the sync script finish running?\n";
}
?>
