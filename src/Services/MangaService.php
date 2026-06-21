<?php
// src/Services/MangaService.php

class MangaService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getMangaById($mangaId) {
        $stmt = $this->pdo->prepare("SELECT * FROM cp_titles WHERE manga_id = ?");
        $stmt->execute([$mangaId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function checkBookmarkStatus($userId, $mangaId) {
        if (!$userId) return false;
        $bmStmt = $this->pdo->prepare("SELECT 1 FROM cp_bookmarks WHERE user_id = ? AND manga_id = ?");
        $bmStmt->execute([$userId, $mangaId]);
        return (bool)$bmStmt->fetchColumn();
    }

    public function getUserReadingHistory($userId, $mangaId) {
        if (!$userId) return null;
        
        $stmt = $this->pdo->prepare("SELECT chapter_id, chapter_num FROM cp_reading_history WHERE user_id = ? AND manga_id = ? LIMIT 1");
        $stmt->execute([$userId, $mangaId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getRecommendations($mangaId, $allowedRatings) {
        $ratingPlaceholders = "'" . implode("','", $allowedRatings) . "'";
        $stmtRecs = $this->pdo->query("SELECT manga_id, title, cover_url, genres FROM cp_titles WHERE manga_id != '$mangaId' AND content_rating IN ($ratingPlaceholders) AND en_chapter_count >= 3 ORDER BY RAND() LIMIT 5");
        return $stmtRecs->fetchAll(PDO::FETCH_ASSOC);
    }

    public function parseAltTitles($altTitlesJson) {
        $altTitlesRaw = json_decode($altTitlesJson ?? '[]', true);
        $altTitlesList = [];
        if (is_array($altTitlesRaw)) { 
            foreach ($altTitlesRaw as $alt) { 
                $altTitlesList[] = current($alt); 
            } 
        }
        return implode(' / ', $altTitlesList);
    }
}
?>
