<?php
// src/Services/ChapterFeedService.php

class ChapterFeedService {
    
    private $cacheDir;

    public function __construct() {
        // Define the cache directory path at the root of the project
        $this->cacheDir = __DIR__ . '/../../cache/';
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    private function fetchWithCache($url, $ttlSeconds = 900) {
        $cacheKey = md5($url);
        $cacheFile = $this->cacheDir . $cacheKey . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        $data = fetchMangadexApi($url);

        if ($data && !isset($data['error'])) {
            file_put_contents($cacheFile, json_encode($data));
        }

        return $data;
    }

    public function getFeed($manga, $page = 1, $limit = 20, $allowedRatings = ['safe', 'suggestive']) {
        $isExternal = !empty($manga['consumet_id']);    
        $offset = ($page - 1) * $limit;
        
        $contentRatingQuery = '';
        foreach ($allowedRatings as $rating) {
            $contentRatingQuery .= '&contentRating[]=' . urlencode(trim($rating));
        }

        if ($isExternal) {
            // Use the new Waterfall Feed method
            $feed = $this->getExternalFeed($manga, $offset, $limit); 
            if ($feed['totalChapters'] > 0) {
                return $feed;
            }
            // If external feed failed or format was invalid (e.g. "1"), fall back to MangaDex
        }
        
        return $this->getMangaDexFeed($manga['manga_id'], $offset, $limit, $contentRatingQuery);
    }

    private function getMangaDexFeed($mangaId, $offset, $limit, $contentRatingQuery) {
        $allChapters = [];
        $totalChapters = 0;
        
        // MangaDex API allows max 500 per request. We chunk it.
        $apiLimit = min($limit, 500);
        $currentOffset = $offset;
        $fetchedCount = 0;
        
        // Cache the aggregated result so we don't spam MangaDex on every refresh
        $cacheKey = md5("mdex_aggregated_{$mangaId}_{$offset}_{$limit}_{$contentRatingQuery}");
        $cacheFile = $this->cacheDir . $cacheKey . '.json';
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            $allChapters = $cachedData['chapters'] ?? [];
            $totalChapters = $cachedData['totalChapters'] ?? 0;
        } else {
            do {
                $feedUrl = "https://api.mangadex.org/manga/$mangaId/feed?limit=$apiLimit&offset=$currentOffset&order[chapter]=desc&translatedLanguage[]=ms&translatedLanguage[]=id&includes[]=scanlation_group" . $contentRatingQuery;
                
                // We use standard fetch here to bypass the 15-min cache for individual chunks
                $feedData = fetchMangadexApi($feedUrl);
                
                if (isset($feedData['result']) && $feedData['result'] === 'ok') {
                    $batch = $feedData['data'] ?? [];
                    $totalChapters = $feedData['total'] ?? 0;
                    
                    $allChapters = array_merge($allChapters, $batch);
                    $fetchedCount += count($batch);
                    $currentOffset += count($batch);
                    
                    // Break if we reached the requested limit, or if the API ran out of chapters
                    if ($fetchedCount >= $limit || count($batch) < $apiLimit || $currentOffset >= $totalChapters) {
                        break;
                    }
                    
                    // Crucial: 200ms delay between loops to prevent MangaDex from IP banning the server
                    usleep(200000); 
                } else {
                    break; // Break on error
                }
            } while (true);
            
            // Trim the array to the exact requested limit
            $allChapters = array_slice($allChapters, 0, $limit);
            
            file_put_contents($cacheFile, json_encode([
                'chapters' => $allChapters,
                'totalChapters' => $totalChapters
            ]));
        }

        // --- Start Reading Button Logic ---
        $firstChapterUrl = '#';
        $startTarget = '_self';
        
        $firstChapUrlApi = "https://api.mangadex.org/manga/$mangaId/feed?limit=50&order[chapter]=asc&translatedLanguage[]=ms&translatedLanguage[]=id" . $contentRatingQuery;
        $firstChapData = $this->fetchWithCache($firstChapUrlApi, 3600); 
        
        if (!empty($firstChapData['data'])) {
            $fChap = null;
            foreach ($firstChapData['data'] as $c) {
                if (empty($c['attributes']['externalUrl'])) { $fChap = $c; break; }
            }
            if (!$fChap) $fChap = $firstChapData['data'][0];
            
            if (!empty($fChap['attributes']['externalUrl'])) {
                $firstChapterUrl = $fChap['attributes']['externalUrl'];
                $startTarget = '_blank';
            } else {
                $firstChapterUrl = "read.php?manga_id={$mangaId}&chapter_id={$fChap['id']}";
            }
        }

        return [
            'chapters' => $allChapters,
            'totalChapters' => $totalChapters,
            'firstChapterUrl' => $firstChapterUrl,
            'startTarget' => $startTarget
        ];
    }

    // ==========================================
    // THE NEW WATERFALL EXTERNAL FEED METHOD
    // ==========================================
    private function getExternalFeed($manga, $offset, $limit) {
        $consumetData = $manga['consumet_id'] ?? null;
        
        if (!$consumetData) {
            return ['chapters' => [], 'totalChapters' => 0, 'firstChapterUrl' => '#', 'startTarget' => '_self'];
        }

        $sources = explode(',', $consumetData);
        $mangaId = $manga['manga_id'];
        
        foreach ($sources as $source) {
            $parts = explode('|', trim($source));
            if (count($parts) !== 2) continue;

            $provider = strtolower(trim($parts[0]));
            $slug = trim($parts[1]);

            // 1. Try WeebCentral
            if ($provider === 'weebcentral') {
                $feed = $this->fetchWeebCentralList($slug, $offset, $limit, $mangaId); 
                if ($feed['totalChapters'] > 0) return $feed;
            } 
            // 2. Try MangaPill
            elseif ($provider === 'mangapill') {
                $feed = $this->fetchMangaPillFeed($slug, $offset, $limit, $mangaId);
                if ($feed['totalChapters'] > 0) return $feed;
            } 
            // 3. Try MangaKatana
            elseif ($provider === 'mangakatana') {
                $feed = $this->fetchMangaKatanaFeed($slug, $offset, $limit, $mangaId);
                if ($feed['totalChapters'] > 0) return $feed;
            }
            // 4. Try Komiku
            elseif ($provider === 'komiku') {
                $feed = $this->fetchKomikuFeed($slug, $offset, $limit, $mangaId);
                if ($feed['totalChapters'] > 0) return $feed;
            }
            // 5. Try Komikcast
            elseif ($provider === 'komikcast') {
                $feed = $this->fetchKomikcastFeed($slug, $offset, $limit, $mangaId);
                if ($feed['totalChapters'] > 0) return $feed;
            }
        }

        // If all fallbacks fail
        return ['chapters' => [], 'totalChapters' => 0, 'firstChapterUrl' => '#', 'startTarget' => '_self'];
    }

    private function fetchWeebCentralList($weebId, $offset, $limit, $mangaId) {
        $url = "https://weebcentral.com/series/{$weebId}/full-chapter-list";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $html = curl_exec($ch);
        curl_close($ch);

        $rawChapters = [];
        
        if (preg_match_all('/href="[^"]*\/chapters\/([A-Z0-9]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $chapterId = $match[1];
                $innerHtml = $match[2];
                $cleanText = trim(preg_replace('/\s+/', ' ', strip_tags($innerHtml)));
                
                $chapterNum = 'Oneshot';
                if (preg_match('/Chapter\s*([\d\.]+)/i', $cleanText, $cMatch)) {
                    $chapterNum = $cMatch[1];
                } elseif (preg_match('/(?:^|\s)([\d\.]+)(?:\s|$)/', $cleanText, $cMatch)) {
                    $chapterNum = $cMatch[1];
                }

                $updatedAt = date('Y-m-d\TH:i:s\Z');
                if (preg_match('/datetime="([^"]+)"/i', $innerHtml, $timeMatch)) {
                    $updatedAt = $timeMatch[1];
                }

                $rawChapters[] = [
                    'id' => $chapterId,
                    'attributes' => [
                        'chapter' => $chapterNum,
                        'title' => "Chapter " . $chapterNum,
                        'updatedAt' => $updatedAt
                    ],
                    'relationships' => [['type' => 'scanlation_group', 'attributes' => ['name' => 'WeebCentral']]]
                ];
            }
        }

        usort($rawChapters, function($a, $b) {
            $valA = floatval($a['attributes']['chapter'] !== 'Oneshot' ? $a['attributes']['chapter'] : 0);
            $valB = floatval($b['attributes']['chapter'] !== 'Oneshot' ? $b['attributes']['chapter'] : 0);
            return $valB <=> $valA;
        });

        $totalChapters = count($rawChapters);
        $pagedChapters = array_slice($rawChapters, $offset, $limit);

        $firstChapterUrl = '#';
        if (!empty($rawChapters)) {
            $fChap = end($rawChapters); 
            $firstChapterUrl = "read.php?manga_id={$mangaId}&chapter_id={$fChap['id']}";
        }

        return [
            'chapters' => $pagedChapters,
            'totalChapters' => $totalChapters,
            'firstChapterUrl' => $firstChapterUrl,
            'startTarget' => '_self'
        ];
    }

    private function fetchMangaPillFeed($slug, $offset, $limit, $mangaId) {
        $url = "https://mangapill.com/manga/" . $slug;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $html = curl_exec($ch);
        curl_close($ch);

        $rawChapters = [];
        
        if ($html && preg_match_all('/<a[^>]+href="\/chapters\/([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $chapterId = $matches[1][$i];
                $titleText = strip_tags($matches[2][$i]);
                
                if (preg_match('/Chapter\s*([0-9.]+)/i', $titleText, $numMatch)) {
                    $chapNum = $numMatch[1];
                    $rawChapters[] = [
                        'id' => $chapterId, // Provide a chapter ID for the router
                        'attributes' => [
                            'chapter' => $chapNum,
                            'title' => trim($titleText),
                            'updatedAt' => date('Y-m-d\TH:i:s\Z')
                        ],
                        'relationships' => [['type' => 'scanlation_group', 'attributes' => ['name' => 'MangaPill']]]
                    ];
                }
            }
        }

        usort($rawChapters, function($a, $b) {
            $valA = floatval($a['attributes']['chapter'] !== 'Oneshot' ? $a['attributes']['chapter'] : 0);
            $valB = floatval($b['attributes']['chapter'] !== 'Oneshot' ? $b['attributes']['chapter'] : 0);
            return $valB <=> $valA;
        });

        $totalChapters = count($rawChapters);
        $pagedChapters = array_slice($rawChapters, $offset, $limit);

        $firstChapterUrl = '#';
        if (!empty($rawChapters)) {
            $fChap = end($rawChapters); 
            $firstChapterUrl = "read.php?manga_id={$mangaId}&chapter_id={$fChap['id']}";
        }

        return [
            'chapters' => $pagedChapters,
            'totalChapters' => $totalChapters,
            'firstChapterUrl' => $firstChapterUrl,
            'startTarget' => '_self'
        ];
    }

    private function fetchMangaKatanaFeed($slug, $offset, $limit, $mangaId) {
        $url = "https://mangakatana.com/manga/" . $slug;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $html = curl_exec($ch);
        curl_close($ch);

        $rawChapters = [];
        
        if ($html && preg_match_all('/<a[^>]+href="[^"]+\/c([0-9.]+)"[^>]*>(.*?)<\/a>/is', $html, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $chapNum = $matches[1][$i];
                $titleText = strip_tags($matches[2][$i]);
                
                $rawChapters[] = [
                    'id' => "c" . $chapNum, // Provide a chapter ID for the router
                    'attributes' => [
                        'chapter' => $chapNum,
                        'title' => trim($titleText),
                        'updatedAt' => date('Y-m-d\TH:i:s\Z')
                    ],
                    'relationships' => [['type' => 'scanlation_group', 'attributes' => ['name' => 'MangaKatana']]]
                ];
            }
        }

        usort($rawChapters, function($a, $b) {
            $valA = floatval($a['attributes']['chapter'] !== 'Oneshot' ? $a['attributes']['chapter'] : 0);
            $valB = floatval($b['attributes']['chapter'] !== 'Oneshot' ? $b['attributes']['chapter'] : 0);
            return $valB <=> $valA;
        });

        $totalChapters = count($rawChapters);
        $pagedChapters = array_slice($rawChapters, $offset, $limit);

        $firstChapterUrl = '#';
        if (!empty($rawChapters)) {
            $fChap = end($rawChapters); 
            $firstChapterUrl = "read.php?manga_id={$mangaId}&chapter_id={$fChap['id']}";
        }

        return [
            'chapters' => $pagedChapters,
            'totalChapters' => $totalChapters,
            'firstChapterUrl' => $firstChapterUrl,
            'startTarget' => '_self'
        ];
    }

    private function fetchKomikuFeed($slug, $offset, $limit, $mangaId) {
        $url = "https://komiku.org/manga/" . $slug . "/";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $html = curl_exec($ch);
        curl_close($ch);

        $rawChapters = [];
        
        if ($html && preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $chapterUrl = $matches[1][$i];
                $titleText = strip_tags($matches[2][$i]);
                
                // Ensure it's a chapter link and has a chapter number
                if (stripos($chapterUrl, 'chapter') !== false && preg_match('/Chapter\s*([0-9.]+)/i', $titleText, $numMatch)) {
                    $chapNum = $numMatch[1];
                    $urlParts = array_filter(explode('/', rtrim($chapterUrl, '/')));
                    $chapterSlug = end($urlParts);
                    
                    // Prevent duplicates
                    $exists = false;
                    foreach ($rawChapters as $rc) {
                        if ($rc['id'] === $chapterSlug) { $exists = true; break; }
                    }
                    
                    if (!$exists) {
                        $rawChapters[] = [
                            'id' => $chapterSlug,
                            'attributes' => [
                                'chapter' => $chapNum,
                                'title' => "Chapter " . $chapNum,
                                'updatedAt' => date('Y-m-d\TH:i:s\Z')
                            ],
                            'relationships' => [['type' => 'scanlation_group', 'attributes' => ['name' => 'Komiku']]]
                        ];
                    }
                }
            }
        }

        usort($rawChapters, function($a, $b) {
            $valA = floatval($a['attributes']['chapter'] !== 'Oneshot' ? $a['attributes']['chapter'] : 0);
            $valB = floatval($b['attributes']['chapter'] !== 'Oneshot' ? $b['attributes']['chapter'] : 0);
            return $valB <=> $valA;
        });

        $totalChapters = count($rawChapters);
        $pagedChapters = array_slice($rawChapters, $offset, $limit);

        $firstChapterUrl = '#';
        if (!empty($rawChapters)) {
            $fChap = end($rawChapters); 
            $firstChapterUrl = "read.php?manga_id={$mangaId}&chapter_id={$fChap['id']}";
        }

        return [
            'chapters' => $pagedChapters,
            'totalChapters' => $totalChapters,
            'firstChapterUrl' => $firstChapterUrl,
            'startTarget' => '_self'
        ];
    }

    private function fetchKomikcastFeed($slug, $offset, $limit, $mangaId) {
        $url = "https://komikcast.io/komik/" . $slug . "/";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $html = curl_exec($ch);
        curl_close($ch);

        $rawChapters = [];
        
        if ($html && preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $chapterUrl = $matches[1][$i];
                $titleText = strip_tags($matches[2][$i]);
                if (stripos($chapterUrl, 'chapter') !== false && preg_match('/Chapter\s*([0-9.]+)/i', $titleText, $numMatch)) {
                    $chapNum = $numMatch[1];
                    $urlParts = array_filter(explode('/', rtrim($chapterUrl, '/')));
                    $chapterSlug = end($urlParts);
                    
                    $exists = false;
                    foreach ($rawChapters as $rc) {
                        if ($rc['id'] === $chapterSlug) { $exists = true; break; }
                    }
                    
                    if (!$exists) {
                        $rawChapters[] = [
                            'id' => $chapterSlug,
                            'attributes' => [
                                'chapter' => $chapNum,
                                'title' => "Chapter " . $chapNum,
                                'updatedAt' => date('Y-m-d\TH:i:s\Z')
                            ],
                            'relationships' => [['type' => 'scanlation_group', 'attributes' => ['name' => 'Komikcast']]]
                        ];
                    }
                }
            }
        }

        usort($rawChapters, function($a, $b) {
            $valA = floatval($a['attributes']['chapter'] !== 'Oneshot' ? $a['attributes']['chapter'] : 0);
            $valB = floatval($b['attributes']['chapter'] !== 'Oneshot' ? $b['attributes']['chapter'] : 0);
            return $valB <=> $valA;
        });

        $totalChapters = count($rawChapters);
        $pagedChapters = array_slice($rawChapters, $offset, $limit);

        $firstChapterUrl = '#';
        if (!empty($rawChapters)) {
            $fChap = end($rawChapters); 
            $firstChapterUrl = "read.php?manga_id={$mangaId}&chapter_id={$fChap['id']}";
        }

        return [
            'chapters' => $pagedChapters,
            'totalChapters' => $totalChapters,
            'firstChapterUrl' => $firstChapterUrl,
            'startTarget' => '_self'
        ];
    }
}
?>