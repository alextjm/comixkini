<?php
// 1. Define a private folder for ComixPass sessions
$sessionPath = __DIR__ . '/../cache/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}

// 2. Tell PHP to use this private folder
session_save_path($sessionPath);

// 3. Set the 30-day lifetime
ini_set('session.gc_maxlifetime', 2592000);
session_set_cookie_params(2592000);
session_start();

$pdo = require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Security check: Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mangaId = $input['manga_id'] ?? '';
$userId = $_SESSION['user_id'];

if (!$mangaId) {
    echo json_encode(['success' => false, 'message' => 'Missing Manga ID']);
    exit;
}

try {
    // Check if it's already bookmarked
    $stmt = $pdo->prepare("SELECT 1 FROM cp_bookmarks WHERE user_id = ? AND manga_id = ?");
    $stmt->execute([$userId, $mangaId]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        // Un-bookmark it
        $del = $pdo->prepare("DELETE FROM cp_bookmarks WHERE user_id = ? AND manga_id = ?");
        $del->execute([$userId, $mangaId]);
        echo json_encode(['success' => true, 'bookmarked' => false]);
    } else {
        // Bookmark it
        $ins = $pdo->prepare("INSERT INTO cp_bookmarks (user_id, manga_id) VALUES (?, ?)");
        $ins->execute([$userId, $mangaId]);
        echo json_encode(['success' => true, 'bookmarked' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
