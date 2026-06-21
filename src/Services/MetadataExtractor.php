<?php
// src/Services/MetadataExtractor.php

class MetadataExtractor {
    
    // Processes raw MangaDex data into a clean, flat array for DB insertion
    public function process($manga, $followers = 0) {
        $mangaId = $manga['id'];
        $attributes = $manga['attributes'];

        // 1. Title Extraction (MS > ID)
        $title = $attributes['title']['ms'] ?? $attributes['title']['id'] ?? null;
        if (!$title && !empty($attributes['altTitles'])) {
            foreach (['ms', 'id'] as $lang) {
                foreach ($attributes['altTitles'] as $alt) {
                    if (isset($alt[$lang])) { 
                        $title = $alt[$lang]; 
                        break 2; // Break out of both loops
                    }
                }
            }
        }
        if (!$title) {
            $title = 'Unknown Title';
        }

        // 2. Description (MS > ID)
        $description = is_array($attributes['description']) ? ($attributes['description']['ms'] ?? $attributes['description']['id'] ?? '') : '';

        // 3. English Chapter Counter
        $aggUrl = "https://api.mangadex.org/manga/$mangaId/aggregate?translatedLanguage[]=ms&translatedLanguage[]=id";
        $aggData = fetchMangadexApi($aggUrl); // Relies on ApiHelper.php
        
        $en_chapter_count = 0;
        if (isset($aggData['volumes'])) {
            foreach ($aggData['volumes'] as $volume) {
                if (isset($volume['chapters']) && is_array($volume['chapters'])) {
                    $en_chapter_count += count($volume['chapters']);
                }
            }
        }
        
        // Prevent IP bans from MangaDex for hitting aggregate too fast
        usleep(250000); 

        // 4. Genres Extraction
        $genresArr = [];
        foreach ($attributes['tags'] as $tag) {
            if (isset($tag['attributes']['name']['en'])) {
                $genresArr[] = $tag['attributes']['name']['en'];
            }
        }
        $genres = implode(', ', $genresArr);

        // 5. Creators & Cover Art Mapping
        $author = 'Unknown'; 
        $artist = 'Unknown'; 
        $coverUrl = null;

        foreach ($manga['relationships'] as $rel) {
            if ($rel['type'] === 'author') $author = $rel['attributes']['name'] ?? 'Unknown';
            if ($rel['type'] === 'artist') $artist = $rel['attributes']['name'] ?? 'Unknown';
            if ($rel['type'] === 'cover_art' && isset($rel['attributes']['fileName'])) {
                $fileName = $rel['attributes']['fileName'];
                $coverUrl = "https://uploads.mangadex.org/covers/$mangaId/$fileName.512.jpg";
            }
        }

        // 6. Return mapped data (NOW WITH STATUS)
        return [
            'manga_id' => $mangaId,
            'title' => $title,
            'description' => $description,
            'genres' => $genres,
            'original_language' => $attributes['originalLanguage'] ?? 'unknown',
            'demographic' => $attributes['publicationDemographic'] ?? null,
            'content_rating' => $attributes['contentRating'] ?? 'safe',
            'publish_year' => $attributes['year'] ?? null,
            'status' => $attributes['status'] ?? 'ongoing', // <-- ADDED HERE
            'author' => $author,
            'artist' => $artist,
            'cover_url' => $coverUrl,
            'external_links' => !empty($attributes['links']) ? json_encode($attributes['links']) : null,
            'alt_titles' => !empty($attributes['altTitles']) ? json_encode($attributes['altTitles']) : null,
            'followers' => $followers,
            'last_updated' => date('Y-m-d H:i:s', strtotime($attributes['updatedAt'])),
            'en_chapter_count' => $en_chapter_count
        ];
    }
}
?>
