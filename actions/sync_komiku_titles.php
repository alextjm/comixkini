<?php
// actions/sync_komiku_titles.php
$pdo = require_once __DIR__ . '/../config/database.php';

echo "Starting Komiku.org Titles Sync...\n";
echo "Note: Press CTRL+C to stop. Progress is safe to resume.\n\n";

$startPage = 1;
$maxPages = 10000; // Will automatically stop when there are no more pages
$totalProcessed = 0;

for ($page = $startPage; $page <= $maxPages; $page++) {
    $url = "https://api.komiku.org/manga/page/" . $page . "/";
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

    // Komiku typically uses <div class="bge"> for manga items
    $mangaNodes = $xpath->query('//div[contains(@class, "bge")]');
    
    if ($mangaNodes->length === 0) {
        echo "No more manga found on page {$page}. Sync complete.\n";
        break;
    }

    $syncedCount = 0;

    foreach ($mangaNodes as $node) {
        // 1. URL and Title
        $titleNode = $xpath->query('.//h3', $node)->item(0);
        $linkNode = $xpath->query('.//a[contains(@href, "/manga/")]', $node)->item(0);
        
        if (!$titleNode || !$linkNode) continue;
        
        $title = trim($titleNode->nodeValue);
        $mangaUrl = $linkNode->getAttribute('href');
        
        // Extract slug from URL (e.g. https://komiku.org/manga/one-piece/ -> one-piece)
        $urlParts = explode('/manga/', rtrim($mangaUrl, '/'));
        if (count($urlParts) < 2) continue;
        
        $slug = trim($urlParts[1], '/');
        $mangaId = "komiku-" . $slug;
        $consumetId = "komiku|" . $slug;
        
        // 2. Cover Image
        $imgNode = $xpath->query('.//img', $node)->item(0);
        $coverUrl = '';
        if ($imgNode) {
            $coverUrl = $imgNode->getAttribute('src');
            // Sometimes they use data-src for lazy loading
            if (empty($coverUrl) || strpos($coverUrl, 'data:image') === 0) {
                $coverUrl = $imgNode->getAttribute('data-src');
            }
        }
        
        // 3. Description (Usually in <p>)
        $descNode = $xpath->query('.//p', $node)->item(0);
        $description = $descNode ? trim($descNode->nodeValue) : 'No description available.';
        
        // 4. Default Metadata
        $genres = 'Action'; 
        $originalLanguage = 'ja';
        $demographic = 'shounen';
        $contentRating = 'safe';
        $publishYear = date('Y');
        $status = 'ongoing';
        $author = 'Unknown';
        $artist = 'Unknown';
        $followers = 0; 
        $lastUpdated = date('Y-m-d H:i:s');
        $enChapterCount = 0; 
        $isActive = 1;

        // Extract Category and Genre from .tpe1_inf
        $tpeNode = $xpath->query('.//div[contains(@class, "tpe1_inf")]', $node)->item(0);
        if ($tpeNode) {
            $catNode = $xpath->query('.//b', $tpeNode)->item(0);
            $category = $catNode ? trim($catNode->nodeValue) : '';
            
            if (stripos($category, 'manhwa') !== false) $originalLanguage = 'ko';
            elseif (stripos($category, 'manhua') !== false) $originalLanguage = 'zh';
            
            // Text node is the genre
            $genreText = trim(str_replace($category, '', $tpeNode->nodeValue));
            if (!empty($genreText)) $genres = $genreText;
        }

        // Extract Readers & Colored from .judul2
        $judul2Node = $xpath->query('.//span[contains(@class, "judul2")]', $node)->item(0);
        if ($judul2Node) {
            $jText = $judul2Node->nodeValue;
            if (stripos($jText, 'berwarna') !== false) {
                $genres .= ', Colored';
            }
            
            // Extract readers (e.g. 53rb pembaca, 2.4jt pembaca)
            if (preg_match('/([0-9.]+)(rb|jt|k|m)?\s*pembaca/i', $jText, $m)) {
                $num = floatval($m[1]);
                $suffix = strtolower($m[2] ?? '');
                if ($suffix === 'rb' || $suffix === 'k') $num *= 1000;
                elseif ($suffix === 'jt' || $suffix === 'm') $num *= 1000000;
                $followers = (int) $num;
            }
        }

        // Extract Latest Chapter
        $new1Nodes = $xpath->query('.//div[contains(@class, "new1")]', $node);
        if ($new1Nodes->length > 0) {
            $latestNode = $new1Nodes->item($new1Nodes->length - 1);
            $latestText = $latestNode->nodeValue;
            if (preg_match('/Chapter\s*([0-9.]+)/i', $latestText, $m)) {
                $enChapterCount = floatval($m[1]);
            }
        }

        // Flatten array for PDO
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
            title=VALUES(title), description=VALUES(description), cover_url=VALUES(cover_url), last_updated=VALUES(last_updated), consumet_id=VALUES(consumet_id), genres=VALUES(genres), original_language=VALUES(original_language), followers=VALUES(followers), en_chapter_count=VALUES(en_chapter_count)
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

echo "\nKomiku Title Sync Complete! Total Titles Processed: $totalProcessed\n";
?>
