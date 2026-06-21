<?php
// actions/sync_komiku_details.php
$pdo = require_once __DIR__ . '/../config/database.php';

echo "Starting Komiku.org Deep Sync (Alt Titles)...\n";
echo "Note: Press CTRL+C to stop. Progress is safe to resume.\n\n";

// 1. Ensure `is_deep_synced` column exists
try {
    $pdo->query("SELECT is_deep_synced FROM cp_titles LIMIT 1");
} catch (PDOException $e) {
    echo "Adding `is_deep_synced` column to cp_titles...\n";
    $pdo->exec("ALTER TABLE cp_titles ADD COLUMN is_deep_synced TINYINT(1) DEFAULT 0");
}

// 2. Fetch comics that haven't been deep synced
$stmt = $pdo->prepare("SELECT manga_id, title, consumet_id, alt_titles FROM cp_titles WHERE is_deep_synced = 0 AND consumet_id LIKE 'komiku|%' LIMIT 500");
$stmt->execute();
$mangas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($mangas)) {
    echo "All Komiku titles have been deep synced!\n";
    exit;
}

$updateStmt = $pdo->prepare("UPDATE cp_titles SET title = ?, alt_titles = ?, is_deep_synced = 1 WHERE manga_id = ?");
$markSyncedStmt = $pdo->prepare("UPDATE cp_titles SET is_deep_synced = 1 WHERE manga_id = ?");

$processed = 0;
$swapped = 0;

foreach ($mangas as $manga) {
    $slug = str_replace('komiku|', '', $manga['consumet_id']);
    $url = "https://komiku.org/manga/{$slug}/";
    
    echo "Fetching: {$manga['title']} -> $url\n";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($html)) {
        echo "  [!] Failed to fetch HTTP $httpCode. Marking as synced to skip next time.\n";
        $markSyncedStmt->execute([$manga['manga_id']]);
        usleep(1500000); // 1.5 seconds polite delay
        continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Extract Judul Alternatif
    $altTitleNode = $xpath->query('//table[contains(@class, "inftable")]//tr[td[contains(text(), "Judul Alternatif")]]/td[2]')->item(0);
    
    if ($altTitleNode) {
        $extractedAltTitle = trim($altTitleNode->nodeValue);
        
        // If alt title exists, is not empty, not a dash, and not identical to the current english title
        if (!empty($extractedAltTitle) && $extractedAltTitle !== '-' && strtolower($extractedAltTitle) !== strtolower($manga['title'])) {
            
            // SWAP LOGIC:
            // New Title = Judul Alternatif (Indonesian/Malay)
            // New Alt Title = Current Title (English)
            $newTitle = $extractedAltTitle;
            
            // If it already had an alt_titles string, append to it, otherwise just use the english title
            $newAltTitles = empty($manga['alt_titles']) ? $manga['title'] : $manga['alt_titles'] . ', ' . $manga['title'];
            
            echo "  [+] Swapping Title! \n";
            echo "      Main Title: {$newTitle}\n";
            echo "      Alt Title: {$newAltTitles}\n";
            
            $updateStmt->execute([$newTitle, $newAltTitles, $manga['manga_id']]);
            $swapped++;
        } else {
            // Nothing to swap, just mark as synced
            echo "  [-] No valid alternative title found to swap.\n";
            $markSyncedStmt->execute([$manga['manga_id']]);
        }
    } else {
        // No alt title row found
        echo "  [-] No alternative title row found.\n";
        $markSyncedStmt->execute([$manga['manga_id']]);
    }

    $processed++;
    // Polite delay to avoid DDOS-Guard ban
    usleep(1500000); // 1.5 seconds
}

echo "\nDeep Sync Complete! Processed: $processed | Titles Swapped: $swapped\n";
echo "Run this script again later to process the next batch.\n";
?>
