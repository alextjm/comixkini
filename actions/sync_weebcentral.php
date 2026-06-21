<?php
// actions/sync_weebcentral.php
$pdo = require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->prepare("SELECT manga_id, title FROM cp_titles WHERE is_active = 0 AND (consumet_id IS NULL OR consumet_id = '')");
$stmt->execute();
$titles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($titles)) {
    die("No deactivated titles found that require syncing.\n");
}

$updatedCount = 0;
echo "Found " . count($titles) . " titles to sync. Starting WeebCentral Data endpoint sync...\n\n";

foreach ($titles as $manga) {
    $title = $manga['title'];
    $mangaId = $manga['manga_id'];
    
    echo "Searching: " . $title . "\n";

    // Target the lazy-loaded data endpoint directly with all required parameters
    $queryParams = http_build_query([
        'limit' => 32,
        'offset' => 0,
        'text' => $title,
        'sort' => 'Best Match',
        'order' => 'Ascending',
        'official' => 'Any',
        'display_mode' => 'Minimal Display'
    ]);
    
    $searchUrl = "https://weebcentral.com/search/data?" . $queryParams;

    $ch = curl_init($searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_ENCODING, ""); // Handle decompression
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    
    // WeebCentral uses HTMX. Passing HX-Request tells it we want the raw HTML fragments.
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html, application/xhtml+xml',
        'Accept-Language: en-US,en;q=0.9',
        'HX-Request: true',
        'Referer: https://weebcentral.com/search'
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html && $httpCode === 200) {
        // Look for the ULID (WeebCentral's ID format, e.g., 01J76XYFX...)
        if (preg_match('/\/series\/([0-9A-Z]{10,})/i', $html, $match)) {
            $weebId = $match[1];
            $newFallbackString = "weebcentral|" . $weebId;

            $updateStmt = $pdo->prepare("UPDATE cp_titles SET consumet_id = ? WHERE manga_id = ?");
            if ($updateStmt->execute([$newFallbackString, $mangaId])) {
                echo "  -> SUCCESS: Linked to {$newFallbackString}\n";
                $updatedCount++;
            } else {
                echo "  -> ERROR: Failed to update database.\n";
            }
        } else {
            echo "  -> FAILED: No exact search results found on WeebCentral.\n";
            if (strpos($html, 'Just a moment...') !== false) {
                echo "     [Debug] WARNING: Cloudflare challenge detected!\n";
            }
        }
    } else {
        echo "  -> ERROR: Connection failed. HTTP Code: $httpCode\n";
    }

    sleep(2); // Polite delay to prevent 403 Forbidden errors from hitting the endpoint too fast
}

echo "\nSync Complete! Successfully updated {$updatedCount} titles.\n";
?>