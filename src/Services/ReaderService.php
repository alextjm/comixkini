<?php
// src/Services/ReaderService.php

class ReaderService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getChapterContext($chapters, $requestedChapterId) {
        $currentIndex = -1;
        foreach ($chapters as $index => $c) {
            if ($c['id'] === $requestedChapterId) {
                $currentIndex = $index;
                break;
            }
        }

        $prevChapterId = null;
        $nextChapterId = null;
        $chapterNum = '?';

        if ($currentIndex !== -1) {
            $currentChapter = $chapters[$currentIndex];
            $chapterNum = $currentChapter['attributes']['chapter'] ?? 'Oneshot';
            
            // Fix: API returns descending order [Ch3, Ch2, Ch1].
            // Therefore, Next Chapter is index - 1, and Prev Chapter is index + 1
            $nextChapterId = ($currentIndex > 0) ? $chapters[$currentIndex - 1]['id'] : null;
            $prevChapterId = ($currentIndex < count($chapters) - 1) ? $chapters[$currentIndex + 1]['id'] : null;
        }

        return [
            'currentIndex' => $currentIndex,
            'chapterNum' => $chapterNum,
            'prevChapterId' => $prevChapterId,
            'nextChapterId' => $nextChapterId
        ];
    }

    public function ownsTitle($userId, $mangaId) {
        if (!$userId || !$mangaId) return false;
        $stmt = $this->pdo->prepare("SELECT 1 FROM cp_user_titles WHERE user_id = ? AND manga_id = ? AND expires_at > NOW()");
        $stmt->execute([$userId, $mangaId]);
        return (bool)$stmt->fetchColumn();
    }

    public function isChapterLocked($chapterNum, $isVIP, $userId = null, $mangaId = null) {
        $isFreeChapter = ($chapterNum === 'Oneshot' || floatval($chapterNum) < 2);
        
        // If it's a free chapter or they have VIP, unlock it
        if ($isFreeChapter || $isVIP) {
            return false;
        }
        
        // If they aren't VIP, check if they bought this specific title
        if ($userId && $mangaId && $this->ownsTitle($userId, $mangaId)) {
            return false;
        }

        // Otherwise, locked
        return true;
    }

    public function logActivity($userId, $mangaId, $chapterId, $chapterNum) {
        if (!$userId || $chapterNum === '?') return;

        $logHistory = $this->pdo->prepare("
            INSERT INTO cp_reading_history (user_id, manga_id, chapter_id, chapter_num) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            chapter_id = VALUES(chapter_id), 
            chapter_num = VALUES(chapter_num), 
            read_at = CURRENT_TIMESTAMP
        ");
        $logHistory->execute([$userId, $mangaId, $chapterId, $chapterNum]);
    }

    // THE FIX: Now accepts the dynamic $provider string
    public function fetchPages($providerString, $chapterId, $chapterNum, $isVIP) {
        // If it's a MangaDex UUID, just fetch it normally
        if ($providerString === 'mangadex') {
            $mdHomeUrl = "https://api.mangadex.org/at-home/server/$chapterId";
            $mdHomeData = fetchMangadexApi($mdHomeUrl);
            
            if (isset($mdHomeData['result']) && $mdHomeData['result'] === 'ok') {
                $pages = [];
                $baseUrl = $mdHomeData['baseUrl'];
                $hash = $mdHomeData['chapter']['hash'];
                $useHighQuality = $isVIP || empty($mdHomeData['chapter']['dataSaver']);
                $folder = $useHighQuality ? 'data' : 'data-saver';
                $imageQualityArray = $useHighQuality ? $mdHomeData['chapter']['data'] : $mdHomeData['chapter']['dataSaver'];
                
                foreach ($imageQualityArray as $filename) {
                    $pages[] = "$baseUrl/$folder/$hash/$filename";
                }
                return ['success' => true, 'pages' => $pages];
            }
            return ['success' => false, 'pages' => []];
        }

        // ==========================================
        // EXTERNAL WATERFALL FALLBACK
        // ==========================================
        
        // $providerString looks like: "weebcentral|01J76,mangapill|3011/blue-lock,mangakatana|blue-lock.21049"
        $sources = explode(',', $providerString);
        $nodeBaseUrl = "http://localhost:3000/manga"; // Your Micro-Scraper

        foreach ($sources as $source) {
            $parts = explode('|', $source);
            if (count($parts) !== 2) continue;
            
            $provider = trim($parts[0]);
            $mangaSlug = trim($parts[1]);
            
            // 1. WEEBCENTRAL NATIVE FETCH
            if ($provider === 'weebcentral') {
                // Prevent routing clash: If the ID contains slashes (MangaPill) or starts with 'c' (Katana), skip WeebCentral
                if (strpos($chapterId, '/') === false && substr($chapterId, 0, 1) !== 'c') {
                    $readUrl = "https://weebcentral.com/chapters/" . urlencode($chapterId) . "/images?reading_style=long_strip";
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $readUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/html', 'HX-Request: true', 'Referer: https://weebcentral.com/']);
                    $html = curl_exec($ch);
                    curl_close($ch);
                    
                    if ($html && preg_match_all('/<img[^>]*?src=["\']([^"\']+)["\']/is', $html, $matches)) {
                        $pages = [];
                        foreach ($matches[1] as $imgUrl) {
                            if (strpos($imgUrl, 'http') === 0 && strpos($imgUrl, 'logo') === false && strpos($imgUrl, 'avatar') === false) {
                                $pages[] = trim($imgUrl);
                            }
                        }
                        if (!empty($pages)) return ['success' => true, 'pages' => $pages];
                    }
                }
            }
            
            // 2. MANGAPILL MICRO-SCRAPER FETCH
            elseif ($provider === 'mangapill') {
                $targetId = null;
                
                // If the user clicked from a MangaPill feed, the ID is already perfect! (Contains a slash)
                if (strpos($chapterId, '/') !== false) {
                    $targetId = $chapterId;
                } else {
                    // Waterfall Fallback: WeebCentral failed, so we scrape the exact ID
                    $targetId = $this->scrapeExactMangaPillId($mangaSlug, $chapterNum);
                }
                
                if ($targetId) {
                    $requestUrl = "{$nodeBaseUrl}/mangapill/read?chapterId=" . urlencode($targetId);
                    $pages = $this->fetchFromMicroScraper($requestUrl);
                    if (!empty($pages)) return ['success' => true, 'pages' => $pages];
                }
            }
            
            // 3. MANGAKATANA MICRO-SCRAPER FETCH
            elseif ($provider === 'mangakatana') {
                $targetId = null;
                
                // If the user clicked from a Katana feed, the ID is already perfect! (Starts with 'c')
                if (substr($chapterId, 0, 1) === 'c') {
                    $targetId = "{$mangaSlug}/{$chapterId}";
                } else {
                    // Waterfall Fallback: Katana's slugs are completely predictable, so we can generate it
                    $targetId = "{$mangaSlug}/c{$chapterNum}";
                }
                
                if ($targetId) {
                    $requestUrl = "{$nodeBaseUrl}/mangakatana/read?chapterId=" . urlencode($targetId);
                    $pages = $this->fetchFromMicroScraper($requestUrl);
                    if (!empty($pages)) return ['success' => true, 'pages' => $pages];
                }
            }
            
            // 4. KOMIKU NATIVE FETCH
            elseif ($provider === 'komiku') {
                $readUrl = "https://komiku.org/" . urlencode($chapterId) . "/";
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $readUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $html = curl_exec($ch);
                curl_close($ch);
                
                if ($html && preg_match('/<div[^>]*id=["\']Baca_Komik["\'][^>]*>(.*?)<div[^>]*class=["\']clear["\']/is', $html, $bacaMatch)) {
                    $imgArea = $bacaMatch[1];
                    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/is', $imgArea, $matches)) {
                        $pages = [];
                        foreach ($matches[1] as $imgUrl) {
                            if (strpos($imgUrl, 'http') === 0) $pages[] = trim($imgUrl);
                        }
                        if (!empty($pages)) return ['success' => true, 'pages' => $pages];
                    }
                } else if ($html && preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/is', $html, $matches)) {
                    // Fallback to grabbing all images if #Baca_Komik is not found
                    $pages = [];
                    foreach ($matches[1] as $imgUrl) {
                        if (strpos($imgUrl, 'http') === 0 && preg_match('/\.(jpg|jpeg|png|webp)/i', $imgUrl) && strpos($imgUrl, 'logo') === false && strpos($imgUrl, 'avatar') === false) {
                            $pages[] = trim($imgUrl);
                        }
                    }
                    if (!empty($pages)) return ['success' => true, 'pages' => $pages];
                }
            }
            
            // 5. KOMIKCAST NATIVE FETCH
            elseif ($provider === 'komikcast') {
                $readUrl = "https://komikcast.io/chapter/" . urlencode($chapterId) . "/";
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $readUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $html = curl_exec($ch);
                curl_close($ch);
                
                if ($html && preg_match('/<div[^>]+class=["\'][^"\']*main-reading-area[^"\']*["\'][^>]*>(.*?)<\/div>\s*<\/div>/is', $html, $areaMatch)) {
                    $imgArea = $areaMatch[1];
                    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/is', $imgArea, $matches)) {
                        $pages = [];
                        foreach ($matches[1] as $imgUrl) {
                            if (strpos($imgUrl, 'http') === 0) $pages[] = trim($imgUrl);
                        }
                        if (!empty($pages)) return ['success' => true, 'pages' => $pages];
                    }
                } else if ($html && preg_match_all('/<img[^>]+class=["\']aligncenter[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/is', $html, $matches)) {
                    // Fallback to aligncenter images
                    $pages = [];
                    foreach ($matches[1] as $imgUrl) {
                        if (strpos($imgUrl, 'http') === 0) $pages[] = trim($imgUrl);
                    }
                    if (!empty($pages)) return ['success' => true, 'pages' => $pages];
                }
            }
        }
        
        return ['success' => false, 'pages' => []];
    }

    // Helper method to ping your local Node server
    private function fetchFromMicroScraper($requestUrl) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 35); 
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data) && count($data) > 0) {
                // Extract just the URLs from the response array
                return array_map(function($img) { return $img['img']; }, $data);
            }
        }
        return false;
    }
    // Helper to find the exact MangaPill URL to prevent math-guessing errors
    private function scrapeExactMangaPillId($slug, $chapterNum) {
        $url = "https://mangapill.com/manga/" . $slug;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $html = curl_exec($ch);
        curl_close($ch);
        
        if ($html && preg_match_all('/<a[^>]+href="\/chapters\/([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $titleText = strip_tags($matches[2][$i]);
                // Look for EXACT chapter match (e.g., "Chapter 1" or "Chapter 1.5")
                if (preg_match('/Chapter\s*' . preg_quote($chapterNum, '/') . '(?:\s|$)/i', $titleText)) {
                    return $matches[1][$i];
                }
            }
        }
        return false;
    }
}
?>
