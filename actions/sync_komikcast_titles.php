<?php
// actions/sync_komikcast_titles.php
$pdo = require_once __DIR__ . '/../config/database.php';

echo "Starting Komikcast Titles Sync...\n";
echo "Note: Press CTRL+C to stop. Progress is safe to resume.\n\n";

$startPage = 1;
$maxPages = 50; // Adjust as needed
$totalProcessed = 0;

for ($page = $startPage; $page <= $maxPages; $page++) {
    $url = "https://komikcast.io/daftar-komik/page/{$page}/";
    echo "Fetching Page: {$page} -> {$url}\n";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($html)) {
        echo "Failed to fetch page {$page}. HTTP Code: {$httpCode}. Ending sync.\n";
        break;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Komikcast uses .list-update_item
    $mangaNodes = $xpath->query('//div[contains(@class, "list-update_item")]');
    
    if ($mangaNodes->length === 0) {
        echo "No more manga found on page {$page}. Sync complete.\n";
        break;
    }

    $syncedCount = 0;

    foreach ($mangaNodes as $node) {
        // 1. URL and Title
        $titleNode = $xpath->query('.//h3', $node)->item(0);
        $linkNode = $xpath->query('.//a[contains(@href, "/komik/")]', $node)->item(0);
        
        if (!$titleNode || !$linkNode) {
            // Try fallback selectors
            $linkNode = $xpath->query('.//a', $node)->item(0);
            if (!$linkNode) continue;
        }
        
        $title = trim($titleNode ? $titleNode->nodeValue : $linkNode->getAttribute('title'));
        if (empty($title)) continue;

        $mangaUrl = $linkNode->getAttribute('href');
        
        // Extract slug from URL (e.g. https://komikcast.io/komik/one-piece/ -> one-piece)
        $urlParts = explode('/komik/', rtrim($mangaUrl, '/'));
        if (count($urlParts) < 2) continue;
        
        $slug = trim($urlParts[1], '/');
        $mangaId = "komikcast-" . $slug;
        $consumetId = "komikcast|" . $slug;
        
        // 2. Cover Image
        $imgNode = $xpath->query('.//img', $node)->item(0);
        $coverUrl = '';
        if ($imgNode) {
            $coverUrl = $imgNode->getAttribute('src');
        }
        
        // 3. Default Metadata
        $description = "Read " . $title . " at KomixKini"; // Komikcast list view usually doesn't show desc
        $genres = 'Manga, Action'; 
        $originalLanguage = 'ja';
        $demographic = 'shounen';
        $contentRating = 'safe';
        $publishYear = date('Y');
        $status = 'ongoing';
        $author = 'Unknown';
        $artist = 'Unknown';
        $followers = rand(1000, 50000); // Dummy followers
        $lastUpdated = date('Y-m-d H:i:s');
        $enChapterCount = 100; // Placeholder
        $isActive = 1;

        $insertValues = [
            $mangaId, $title, $description, $genres, $originalLanguage, $demographic, $contentRating, $publishYear,
            $status, $author, $artist, $coverUrl, null, null, $followers, $lastUpdated, $enChapterCount, $isActive, $consumetId
        ];

        // UPSERT Title
        $insertTitle = $pdo->prepare("
            INSERT INTO cp_titles
            (manga_id, title, description, genres, original_language, demographic, content_rating, publish_year, status, author, artist, cover_url, external_links, alt_titles, followers, last_updated, en_chapter_count, is_active, consumet_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            title=VALUES(title), cover_url=VALUES(cover_url), last_updated=VALUES(last_updated), consumet_id=VALUES(consumet_id)
        ");

        try {
            $insertTitle->execute($insertValues);
            $syncedCount++;
            $totalProcessed++;
        } catch (PDOException $e) {
            echo "Failed to insert $slug: " . $e->getMessage() . "\n";
        }
    }

    echo "-> Processed $syncedCount titles on Page $page (Total: $totalProcessed)\n";
    usleep(500000); // Polite delay
}

echo "\nKomikcast Title Sync Complete! Total Titles Processed: $totalProcessed\n";
?>
