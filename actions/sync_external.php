<?php
// actions/sync_external.php
$pdo = require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->prepare("SELECT manga_id, title FROM cp_titles WHERE is_active = 0");
$stmt->execute();
$titles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($titles)) {
    die("No deactivated titles found that require syncing.\n");
}

$updatedCount = 0;
echo "Found " . count($titles) . " titles to sync. Starting Highly-Accurate Multi-Source search...\n\n";

// --- HELPER FUNCTION MOVED OUTSIDE THE LOOP ---
function isValidMatch($dbTitle, $foundTitle) {
    $cleanDb = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $dbTitle)));
    $cleanFound = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $foundTitle)));
    
    similar_text($cleanDb, $cleanFound, $percent);
    // Accept if they are 35% similar, OR if one string is completely contained inside the other
    return ($percent > 35 || strpos($cleanFound, $cleanDb) !== false || strpos($cleanDb, $cleanFound) !== false);
}

foreach ($titles as $manga) {
    $dbTitle = $manga['title'];
    $mangaId = $manga['manga_id'];
    
    echo "Searching: " . $dbTitle . "\n";
    $foundSources = [];

    // --- 1. SEARCH WEEBCENTRAL ---
    $weebUrl = "https://weebcentral.com/search/data?" . http_build_query([
        'limit' => 32, 'offset' => 0, 'text' => $dbTitle, 'sort' => 'Best Match', 'order' => 'Ascending', 'official' => 'Any', 'display_mode' => 'Minimal Display'
    ]);
    $ch = curl_init($weebUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['HX-Request: true']);
    $html = curl_exec($ch);
    curl_close($ch);

    if ($html && preg_match('/\/series\/([0-9A-Z]{10,})/i', $html, $match)) {
        $foundSources[] = "weebcentral|" . $match[1];
        echo "  -> [OK] WeebCentral: {$match[1]}\n";
    }

    // --- 2. SEARCH MANGAPILL ---
    $pillUrl = "https://mangapill.com/search?q=" . urlencode($dbTitle);
    $ch = curl_init($pillUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    curl_close($ch);

    if ($html && preg_match('/<a href="(\/manga\/\d+\/[^"]+)".*?<img[^>]+alt="([^"]+)"/is', $html, $match)) {
        $slug = $match[1];
        $foundTitle = $match[2];
        
        if (isValidMatch($dbTitle, $foundTitle)) {
            $foundSources[] = "mangapill|" . ltrim($slug, '/manga/');
            echo "  -> [OK] MangaPill: " . ltrim($slug, '/manga/') . "\n";
        } else {
            echo "  -> [REJECTED] MangaPill fuzzy match: '{$foundTitle}' too different from '{$dbTitle}'\n";
        }
    }

    // --- 3. SEARCH MANGAKATANA ---
    $katanaUrl = "https://mangakatana.com/?search=" . urlencode($dbTitle);
    $ch = curl_init($katanaUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!empty($html)) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        $katanaNodes = $xpath->query('//div[@id="book_list"]//h3[@class="title"]/a');
        
        if ($katanaNodes->length > 0) {
            $foundUrl = $katanaNodes->item(0)->getAttribute('href');
            $foundTitle = $katanaNodes->item(0)->nodeValue;
            $slug = str_replace('https://mangakatana.com/manga/', '', $foundUrl);

            if (isValidMatch($dbTitle, $foundTitle)) {
                $foundSources[] = "mangakatana|" . $slug;
                echo "  -> [OK] MangaKatana: {$slug}\n";
            } else {
                 echo "  -> [REJECTED] MangaKatana mismatch: '{$foundTitle}' too different from '{$dbTitle}'\n";
            }
        }
    } else {
        echo "  -> [SKIPPED] MangaKatana blocked the request or returned empty.\n";
    }

    // --- UPDATE DATABASE ---
    if (!empty($foundSources)) {
        $consumetString = implode(',', $foundSources);
        $updateStmt = $pdo->prepare("UPDATE cp_titles SET consumet_id = ? WHERE manga_id = ?");
        if ($updateStmt->execute([$consumetString, $mangaId])) {
            echo "  [SAVED] String: {$consumetString}\n";
            $updatedCount++;
        }
    } else {
        echo "  [FAILED] No accurate sources found.\n";
    }

    sleep(2); // Polite delay
    echo "----------------------------------------\n";
}

echo "\nSync Complete! Successfully updated {$updatedCount} titles with accurate data.\n";
?>