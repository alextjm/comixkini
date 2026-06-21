<?php
$pdo = require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/ApiHelper.php';
require_once __DIR__ . '/../src/Services/MetadataExtractor.php';

echo "Starting Global Title Mapping & Sync (No Groups, Deep Image Check)...\n";
echo "Note: Press CTRL+C to stop. Progress is safe to resume.\n\n";

/**
 * Checks image viewability AND retrieves the total English chapter count.
 * Returns: ['is_viewable' => bool, 'chapter_count' => int]
 */
function getChapterDataAndCheckViewability($mangaId) {
    // Check the latest 10 chapters
    $url = "https://api.mangadex.org/manga/{$mangaId}/feed?limit=10&translatedLanguage[]=ms&translatedLanguage[]=id&contentRating[]=safe&contentRating[]=suggestive&contentRating[]=erotica&contentRating[]=pornographic";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ComixKini-SyncScript/5.2');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    usleep(250000); // Respect rate limits

    if ($httpCode !== 200) return ['is_viewable' => false, 'chapter_count' => 0];

    $data = json_decode($response, true);
    $totalChapters = $data['total'] ?? 0;

    if (!isset($data['data']) || empty($data['data'])) {
        return ['is_viewable' => false, 'chapter_count' => 0]; 
    }

    // Loop through chapters. If we find EVEN ONE local, readable chapter, the manga is approved!
    foreach ($data['data'] as $chapter) {
        $pages = $chapter['attributes']['pages'] ?? 0;
        $externalUrl = $chapter['attributes']['externalUrl'] ?? null;

        // Viewable = Has pages AND is NOT an external link
        if ($pages > 0 && $externalUrl === null) {
            return ['is_viewable' => true, 'chapter_count' => $totalChapters];
        }
    }

    return ['is_viewable' => false, 'chapter_count' => $totalChapters];
}

$extractor = new MetadataExtractor();

$limit = 100;
$offset = 0;
$totalProcessed = 0;

echo "Syncing all valid English titles from MangaDex globally (Bypassing 10k Limit)...\n";

$createdAtSince = '2000-01-01T00:00:00'; // Start from the very beginning of MangaDex

while (true) {
    // 1. Time-based strict URL Builder
    // We order by createdAt ASCENDING so we can track our exact spot in time
    $queryString = "limit={$limit}&offset={$offset}&hasAvailableChapters=true&order[createdAt]=asc&createdAtSince={$createdAtSince}";

    $includes = ['cover_art', 'author', 'artist'];
    foreach ($includes as $inc) $queryString .= "&includes[]={$inc}";

    $langs = ['ms', 'id'];
    foreach ($langs as $lang) $queryString .= "&availableTranslatedLanguage[]={$lang}";

    $ratings = ['safe', 'suggestive', 'erotica', 'pornographic'];
    foreach ($ratings as $rating) $queryString .= "&contentRating[]={$rating}";

    $mangaUrl = "https://api.mangadex.org/manga?" . $queryString;

    // Fetch via your Helper
    $mangaData = fetchMangadexApi($mangaUrl);

    if (empty($mangaData['data'])) {
        echo "No more data returned from API. Sync complete.\n";
        break;
    }

    // Batch Fetch Followers
    $mangaIds = array_column($mangaData['data'], 'id');
    $statsUrl = "https://api.mangadex.org/statistics/manga?" . http_build_query(['manga' => $mangaIds]);
    $statistics = fetchMangadexApi($statsUrl)['statistics'] ?? [];

    $syncedCount = 0;
    $lastCreatedAt = ''; // Variable to store the timestamp of the last item

    foreach ($mangaData['data'] as $manga) {
        $mangadexId = $manga['id'];
        $attributes = $manga['attributes'];

        // Track the created at time for our pagination bypass
        $lastCreatedAt = $attributes['createdAt'];

        // Title Extraction (MS > ID)
        $title = $attributes['title']['ms'] ?? $attributes['title']['id'] ?? '';
        if (empty($title) && !empty($attributes['altTitles'])) {
            foreach (['ms', 'id'] as $lang) {
                foreach ($attributes['altTitles'] as $alt) {
                    if (isset($alt[$lang])) { 
                        $title = $alt[$lang]; 
                        break 2;
                    }
                }
            }
        }

        // Description Extraction (MS > ID)
        $description = is_array($attributes['description']) ? ($attributes['description']['ms'] ?? $attributes['description']['id'] ?? '') : '';

        // Skip if no MS/ID title or description
        if (empty(trim($title)) || empty(trim($description))) {
            continue;
        }

        $state = $attributes['state'] ?? 'published';
        $isActive = 1;
        $enChaptersCount = 0;

        // Initial DMCA / Rejected check
        if ($state === 'rejected' || empty($attributes['availableTranslatedLanguages'])) {
            $isActive = 0;
        }

        // DEEP IMAGE VIEWABILITY CHECK
        if ($isActive === 1) {
            $feedData = getChapterDataAndCheckViewability($mangadexId);
            $enChaptersCount = $feedData['chapter_count'];

            if (!$feedData['is_viewable']) {
                $isActive = 0;
            }
        }

        $followers = $statistics[$mangadexId]['follows'] ?? 0;

        // Extract via your Service
        $data = $extractor->process($manga, $followers);

        // Override the chapter count with our deeply-checked data BEFORE flattening the array
        $data['en_chapter_count'] = $enChaptersCount;
        $data['is_active'] = $isActive;

        // Flatten array for PDO
        $insertValues = array_values($data);

        // UPSERT Title
        $insertTitle = $pdo->prepare("
            INSERT INTO cp_titles
            (manga_id, title, description, genres, original_language, demographic, content_rating, publish_year, status, author, artist, cover_url, external_links, alt_titles, followers, last_updated, en_chapter_count, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            title=VALUES(title), description=VALUES(description), genres=VALUES(genres),
            original_language=VALUES(original_language), demographic=VALUES(demographic),
            content_rating=VALUES(content_rating), publish_year=VALUES(publish_year),
            status=VALUES(status), author=VALUES(author), artist=VALUES(artist), cover_url=VALUES(cover_url),
            external_links=VALUES(external_links), alt_titles=VALUES(alt_titles),
            followers=VALUES(followers), last_updated=VALUES(last_updated),
            en_chapter_count=VALUES(en_chapter_count), is_active=IF(cp_titles.consumet_id IS NOT NULL AND cp_titles.consumet_id != '', 1, VALUES(is_active))
        ");

        try {
            $insertTitle->execute($insertValues);
            $syncedCount++;
            $totalProcessed++;
        } catch (PDOException $e) {
            echo "Failed to insert $mangadexId: " . $e->getMessage() . "\n";
        }
    }

    echo "-> Processed $syncedCount valid titles at Offset: $offset (Total: $totalProcessed)\n";

    $offset += $limit;

    // THE 10,000 BYPASS LOGIC:
    if ($offset >= 10000) {
        echo "\nReached 10,000 limit. Resetting offset and moving time forward to: $lastCreatedAt\n";
        // Reset the offset back to 0
        $offset = 0;

        // Strip the timezone/millisecond data so MangaDex accepts the strict date format (Y-m-d\TH:i:s)
        $createdAtSince = substr($lastCreatedAt, 0, 19);
    }
}

echo "\nGlobal Title Mapping Complete! Total Titles Processed: $totalProcessed\n";
?>
