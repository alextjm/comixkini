<?php
// public/api.php
// 1. Define a private folder for ComixKini sessions
$sessionPath = __DIR__ . '/../cache/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
}

// 2. Tell PHP to use this private folder
if (is_writable($sessionPath)) { session_save_path($sessionPath); }

// 3. Set the 30-day lifetime
ini_set('session.gc_maxlifetime', 2592000);
session_set_cookie_params(2592000);
session_start();

$pdo = require_once __DIR__ . '/../config/database.php';

// Decode JSON payloads from frontend if available
$request = json_decode(file_get_contents('php://input'), true) ?: [];

// Determine action (Supports GET parameters, POST forms, or JSON body)
$action = $_GET['action'] ?? $_POST['action'] ?? $request['action'] ?? null;

if (!$action) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No action specified.']);
    exit;
}

// Helper function used by the load_section action
function timeAgo($datetime) {
    if (!$datetime) return 'Unknown';
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return floor($diff / 2592000) . 'mo, ' . floor(($diff % 2592000)/86400) . 'd';
}

try {
    switch ($action) {
        
        // ==========================================
        // 1. SEARCH COMICS (JSON)
        // ==========================================
        case 'search':
            header('Content-Type: application/json');
            $query = trim($_GET['q'] ?? $request['q'] ?? '');
            
            if (strlen($query) < 2) {
                echo json_encode([]);
                exit;
            }

            $allowedRatings = isset($_SESSION['content_filters']) ? explode(',', $_SESSION['content_filters']) : ['safe', 'suggestive'];
            if (in_array('safe', $allowedRatings)) array_push($allowedRatings, '10+', '13+', '15+', '16+', 'Semua Umur', 'Remaja');
            if (in_array('suggestive', $allowedRatings)) array_push($allowedRatings, '17+', 'Dewasa');
            if (in_array('erotica', $allowedRatings) || in_array('pornographic', $allowedRatings)) array_push($allowedRatings, '18+', '21+');
            $ratingPlaceholders = "'" . implode("','", $allowedRatings) . "'";

            $stmt = $pdo->prepare("SELECT manga_id, title, cover_url FROM cp_titles WHERE title LIKE ? AND is_active = 1 AND content_rating IN ($ratingPlaceholders) ORDER BY followers DESC LIMIT 8");
            $stmt->execute(['%' . $query . '%']);
            
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================================
        // 2. LOAD SECTIONS (HTML FRAGMENTS)
        // ==========================================
        case 'load_section':
            header('Content-Type: text/html; charset=utf-8');
            $section = trim($_GET['section'] ?? $request['section'] ?? 'popular');
            $time = $_GET['time'] ?? $request['time'] ?? '1m';

            $timeFilter = "";
            switch ($time) {
                case '1d': $timeFilter = "AND last_updated >= DATE_SUB(NOW(), INTERVAL 1 DAY)"; break;
                case '7d': $timeFilter = "AND last_updated >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
                case '1m': $timeFilter = "AND last_updated >= DATE_SUB(NOW(), INTERVAL 1 MONTH)"; break;
                case '3m': $timeFilter = "AND last_updated >= DATE_SUB(NOW(), INTERVAL 3 MONTH)"; break;
                case '6m': $timeFilter = "AND last_updated >= DATE_SUB(NOW(), INTERVAL 6 MONTH)"; break;
                case '1y': $timeFilter = "AND last_updated >= DATE_SUB(NOW(), INTERVAL 1 YEAR)"; break;
                case 'all': $timeFilter = ""; break;
                default:   $timeFilter = "AND last_updated >= DATE_SUB(NOW(), INTERVAL 1 MONTH)"; break;
            }

            $allowedRatings = isset($_SESSION['content_filters']) ? explode(',', $_SESSION['content_filters']) : ['safe', 'suggestive'];
            if (in_array('safe', $allowedRatings)) array_push($allowedRatings, '10+', '13+', '15+', '16+', 'Semua Umur', 'Remaja');
            if (in_array('suggestive', $allowedRatings)) array_push($allowedRatings, '17+', 'Dewasa');
            if (in_array('erotica', $allowedRatings) || in_array('pornographic', $allowedRatings)) array_push($allowedRatings, '18+', '21+');
            $ratingPlaceholders = "'" . implode("','", $allowedRatings) . "'";
            $baseQuery = "FROM cp_titles WHERE is_active = 1 AND content_rating IN ($ratingPlaceholders) AND en_chapter_count >= 3";

            $timeFilter1Month = " AND last_updated >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            
            if ($section === 'popular') {
                $stmt = $pdo->query("SELECT * $baseQuery $timeFilter ORDER BY followers DESC LIMIT 15");
            } else if ($section === 'follows') {
                // Calculate popular exclusions based on default 1m time
                $stmtPop = $pdo->query("SELECT manga_id $baseQuery $timeFilter1Month ORDER BY followers DESC LIMIT 15");
                $popIds = $stmtPop->fetchAll(PDO::FETCH_COLUMN);
                $excludeIds = empty($popIds) ? "'none'" : "'" . implode("','", $popIds) . "'";
                
                $stmt = $pdo->query("SELECT * $baseQuery $timeFilter AND en_chapter_count BETWEEN 3 AND 40 AND manga_id NOT IN ($excludeIds) ORDER BY followers DESC LIMIT 15");
            } else if ($section === 'latest') {
                $stmtPop = $pdo->query("SELECT manga_id $baseQuery $timeFilter1Month ORDER BY followers DESC LIMIT 15");
                $popIds = $stmtPop->fetchAll(PDO::FETCH_COLUMN);
                $excludeIds1 = empty($popIds) ? "'none'" : "'" . implode("','", $popIds) . "'";
                
                $stmtFoll = $pdo->query("SELECT manga_id $baseQuery AND en_chapter_count BETWEEN 3 AND 40 AND manga_id NOT IN ($excludeIds1) ORDER BY followers DESC LIMIT 15");
                $follIds = $stmtFoll->fetchAll(PDO::FETCH_COLUMN);
                
                $allIds = array_merge($popIds, $follIds);
                $allExcludeIds = empty($allIds) ? "'none'" : "'" . implode("','", $allIds) . "'";

                $tab = $_GET['tab'] ?? $request['tab'] ?? 'hot';
                $type = $_GET['type'] ?? $request['type'] ?? 'all';
                $typeFilter = "";
                if ($type === 'manga') $typeFilter = " AND original_language = 'ja'";
                elseif ($type === 'manhwa') $typeFilter = " AND original_language = 'ko'";
                elseif ($type === 'manhua') $typeFilter = " AND original_language = 'zh'";
                elseif ($type === 'other') $typeFilter = " AND original_language NOT IN ('ja', 'ko', 'zh')";

                if ($tab === 'hot') {
                    $stmt = $pdo->query("SELECT * $baseQuery $typeFilter AND manga_id NOT IN ($allExcludeIds) ORDER BY followers DESC, last_updated DESC LIMIT 15");
                } else {
                    $stmt = $pdo->query("SELECT * $baseQuery $typeFilter AND manga_id NOT IN ($allExcludeIds) ORDER BY last_updated DESC, followers ASC LIMIT 15");
                }
            } else if (trim($section) === 'erotica' || trim($section) === 'explicit') {
                $rating = ($section === 'erotica') ? 'erotica' : 'pornographic';
                $adultBaseQuery = "FROM cp_titles WHERE is_active = 1 AND content_rating = '$rating' AND en_chapter_count >= 3";
                
                $tab = $_GET['tab'] ?? $request['tab'] ?? 'hot';
                if ($tab === 'hot') {
                    $stmt = $pdo->query("SELECT * $adultBaseQuery $timeFilter ORDER BY followers DESC, last_updated DESC LIMIT 15");
                } else {
                    $stmt = $pdo->query("SELECT * $adultBaseQuery $timeFilter ORDER BY last_updated DESC LIMIT 15");
                }
            } else {
                echo "<div class='text-center text-gray-500 py-10 font-bold'>Invalid section.Received: [" . htmlspecialchars($section) . "]</div>";
                exit;
            }

            $mangas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($mangas)) {
                echo "<div class='w-full text-center text-gray-500 py-10 font-bold'>No comics found for this filter.</div>";
                exit;
            }

            $rank = 1;
            foreach ($mangas as $manga) {
                $title = htmlspecialchars($manga['title']);
                $url = "manga.php?id=" . urlencode($manga['manga_id']);
                $rawCover = $manga['cover_url'] ?? 'https://via.placeholder.com/300x420';
                $cover = "image_proxy.php?v=2&w=300&url=" . urlencode($rawCover);
                $updated = timeAgo($manga['last_updated']);
                
                $groupHover = 'group-hover:text-accent';
                $borderColor = 'group-hover:border-accent/50';
                $badgeHtml = "";
                $rankHtml = "";

                if ($section === 'popular') {
                    $genre = htmlspecialchars(explode(', ', $manga['genres'])[0] ?? 'Comic');
                    $badgeHtml = "<span class='text-[10px] font-bold text-accent uppercase bg-black/50 px-1 rounded backdrop-blur'>{$genre}</span>";
                    $rankHtml = "<div class=\"absolute top-0 right-0 bg-black/80 text-white font-black text-xl px-2 py-1 rounded-bl-lg backdrop-blur shadow-lg\">{$rank}</div>";
                } elseif ($section === 'follows') {
                    $groupHover = 'group-hover:text-[#ff4757]';
                    $borderColor = 'group-hover:border-[#ff4757]/50';
                    $followers = number_format($manga['followers']);
                    $badgeHtml = "<span class='text-[10px] font-bold text-yellow-400'>★ {$followers}</span>";
                    $rankHtml = "<div class=\"absolute top-0 right-0 bg-[#ff4757]/90 text-white font-black text-xl px-2 py-1 rounded-bl-lg backdrop-blur shadow-lg\">{$rank}</div>";
                } elseif ($section === 'latest') {
                    $groupHover = 'group-hover:text-[#a29bfe]';
                    $borderColor = 'group-hover:border-[#a29bfe]/50';
                    $lang = strtoupper(htmlspecialchars($manga['original_language'] ?? 'EN'));
                    $badgeHtml = "<span class='text-[10px] font-bold text-gray-300 bg-black/50 px-1 rounded backdrop-blur'>{$lang}</span>";
                } elseif ($section === 'erotica' || $section === 'explicit') {
                    $groupHover = 'group-hover:text-[#ff4757]';
                    $borderColor = 'group-hover:border-[#ff4757]/50';
                    $badgeHtml = "<span class='text-[10px] font-bold text-white bg-[#ff4757]/80 px-1 rounded backdrop-blur'>18+</span>";
                }

                echo "
                <a href=\"{$url}\" class=\"group flex flex-col fade-in flex-none w-[calc(50%-6px)] sm:w-[calc(33.333%-10.6px)] md:w-[calc(25%-12px)] lg:w-[calc(20%-12.8px)] snap-start\">
                    <div class=\"relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border dark:border-gray-800 border-gray-300 {$borderColor} transition-colors\">
                        <img src=\"{$cover}\" class=\"w-full h-full object-contain dark:bg-[#151921] bg-gray-200 group-hover:scale-105 transition-transform duration-300\">
                        <div class=\"absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 flex justify-between items-end h-1/2\">
                            {$badgeHtml}
                            <span class=\"text-[10px] text-gray-300 font-medium\">{$updated}</span>
                        </div>
                        {$rankHtml}
                    </div>
                    <h3 class=\"mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight {$groupHover} transition-colors\">
                        {$title}
                    </h3>
                </a>";
                $rank++;
            }
            break;

        // ==========================================
        // 3. UPDATE USER SETTINGS (JSON)
        // ==========================================
        case 'update_settings':
            header('Content-Type: application/json');
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Not logged in.']);
                exit;
            }

            $filters = $request['filters'] ?? [];
            $validRatings = ['safe', 'suggestive', 'erotica', 'pornographic'];
            $cleanFilters = ['safe']; // Forced minimum

            foreach ($filters as $f) {
                if (in_array($f, $validRatings) && !in_array($f, $cleanFilters)) {
                    $cleanFilters[] = $f;
                }
            }

            $filterString = implode(',', $cleanFilters);
            $stmt = $pdo->prepare("UPDATE cp_users SET content_filters = ? WHERE id = ?");
            $stmt->execute([$filterString, $_SESSION['user_id']]);
            
            $_SESSION['content_filters'] = $filterString;
            echo json_encode(['success' => true]);
            break;

        // ==========================================
        // 4. REDEEM VOUCHER (JSON)
        // ==========================================
        case 'redeem_voucher':
            header('Content-Type: application/json');
            $code = strtoupper(trim($request['code'] ?? ''));

            if (empty($code)) {
                echo json_encode(['success' => false, 'message' => 'Please enter a voucher code.']);
                exit;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM cp_vouchers WHERE voucher_code = ? FOR UPDATE");
            $stmt->execute([$code]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Invalid code.']);
                exit;
            }

            if ($voucher['is_used'] == 1) {
                $usedUserId = $voucher['used_by_user_id'];
                $usrStmt = $pdo->prepare("SELECT * FROM cp_users WHERE id = ?");
                $usrStmt->execute([$usedUserId]);
                $usedUser = $usrStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($usedUser && strpos($usedUser['username'], 'guest_') === 0) {
                    $_SESSION['user_id'] = $usedUser['id'];
                    $_SESSION['username'] = $usedUser['username'];
                    $_SESSION['role'] = $usedUser['role'] ?? 'user';
                    $_SESSION['content_filters'] = $usedUser['content_filters'] ?? 'safe,suggestive';
                    
                    $newToken = bin2hex(random_bytes(32));
                    $pdo->prepare("UPDATE cp_users SET session_token = ? WHERE id = ?")->execute([$newToken, $usedUser['id']]);
                    $_SESSION['session_token'] = $newToken;
                    session_write_close();
                    $pdo->commit();
                    
                    echo json_encode(['success' => true, 'manga_id' => $voucher['manga_id'] ?? null, 'is_vip' => empty($voucher['manga_id']), 'new_expiry' => 'Session restored. You are logged in as a guest!']);
                    exit;
                } else {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Code already redeemed. Please log in with your email/password.']);
                    exit;
                }
            }

            if (!isset($_SESSION['user_id'])) {
                $guestUsername = 'guest_' . bin2hex(random_bytes(4));
                $guestEmail = $guestUsername . '@comixkini.local';
                $guestPassword = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                $newToken = bin2hex(random_bytes(32));
                
                $stmtNew = $pdo->prepare("INSERT INTO cp_users (username, email, password_hash, session_token) VALUES (?, ?, ?, ?)");
                $stmtNew->execute([$guestUsername, $guestEmail, $guestPassword, $newToken]);
                $newUserId = $pdo->lastInsertId();
                
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['username'] = $guestUsername;
                $_SESSION['role'] = 'user';
                $_SESSION['content_filters'] = 'safe,suggestive';
                $_SESSION['session_token'] = $newToken;
            }

            $userId = $_SESSION['user_id'];
            $daysToAdd = (int)$voucher['duration_days'];
            $mangaId = $voucher['manga_id'] ?? null;
            $targetMangaId = $request['manga_id'] ?? null;

            // Handle Universal VIP Codes
            if ($mangaId === 'UNIVERSAL') {
                if (!empty($targetMangaId)) {
                    // Bind the universal code to the requested manga
                    $mangaId = $targetMangaId;
                    $updBind = $pdo->prepare("UPDATE cp_vouchers SET manga_id = ? WHERE id = ?");
                    $updBind->execute([$mangaId, $voucher['id']]);
                } else {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'This is a Universal VIP Code. Please go to the specific manga page you want to unlock and redeem it there.']);
                    exit;
                }
            }

            if (!empty($mangaId)) {
                // ==========================================
                // TITLE-SPECIFIC REDEMPTION
                // ==========================================
                $now = new DateTime();
                $now->modify("+$daysToAdd days");
                $expiresAt = $now->format('Y-m-d H:i:s');

                $stmtTitle = $pdo->prepare("
                    INSERT INTO cp_user_titles (user_id, manga_id, expires_at) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE expires_at = ?
                ");
                $stmtTitle->execute([$userId, $mangaId, $expiresAt, $expiresAt]);

                $updVoucher = $pdo->prepare("UPDATE cp_vouchers SET is_used = 1, used_by_user_id = ?, used_at = NOW() WHERE id = ?");
                $updVoucher->execute([$userId, $voucher['id']]);

                $pdo->commit();
                echo json_encode(['success' => true, 'manga_id' => $mangaId, 'new_expiry' => 'Title Unlocked until ' . $now->format('F j, Y')]);
                exit;
                
            } else {
                // ==========================================
                // STANDARD VIP REDEMPTION
                // ==========================================
                $userStmt = $pdo->prepare("SELECT subscription_ends_at FROM cp_users WHERE id = ? FOR UPDATE");
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                $currentExpiry = $user['subscription_ends_at'];
                $now = new DateTime();

                if ($currentExpiry && new DateTime($currentExpiry) > $now) {
                    $newExpiryObj = new DateTime($currentExpiry);
                    $newExpiryObj->modify("+$daysToAdd days");
                } else {
                    $newExpiryObj = new DateTime();
                    $newExpiryObj->modify("+$daysToAdd days");
                }

                $newExpiryString = $newExpiryObj->format('Y-m-d H:i:s');

                $updUser = $pdo->prepare("UPDATE cp_users SET subscription_ends_at = ? WHERE id = ?");
                $updUser->execute([$newExpiryString, $userId]);

                $updVoucher = $pdo->prepare("UPDATE cp_vouchers SET is_used = 1, used_by_user_id = ?, used_at = NOW() WHERE id = ?");
                $updVoucher->execute([$userId, $voucher['id']]);

                $pdo->commit();
                echo json_encode(['success' => true, 'is_vip' => true, 'new_expiry' => $newExpiryObj->format('F j, Y')]);
                exit;
            }
            break;

        // ==========================================
        // 5. BOOKMARKS & AUTHENTICATION (JSON)
        // ==========================================
        case 'toggle_bookmark':
            header('Content-Type: application/json');
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            $mangaId = $request['manga_id'] ?? null;
            
            $stmt = $pdo->prepare("SELECT 1 FROM cp_bookmarks WHERE user_id = ? AND manga_id = ?");
            $stmt->execute([$_SESSION['user_id'], $mangaId]);
            $exists = $stmt->fetchColumn();

            if ($exists) {
                $pdo->prepare("DELETE FROM cp_bookmarks WHERE user_id = ? AND manga_id = ?")->execute([$_SESSION['user_id'], $mangaId]);
                echo json_encode(['success' => true, 'bookmarked' => false]);
            } else {
                $pdo->prepare("INSERT INTO cp_bookmarks (user_id, manga_id) VALUES (?, ?)")->execute([$_SESSION['user_id'], $mangaId]);
                echo json_encode(['success' => true, 'bookmarked' => true]);
            }
            break;
            
        case 'login':
            header('Content-Type: application/json');
            require_once __DIR__ . '/../src/Services/AuthService.php';
            $authService = new AuthService($pdo);
            echo json_encode($authService->login($request['login_id'] ?? '', $request['password'] ?? ''));
            break;
            
        case 'register':
            header('Content-Type: application/json');
            if (!empty($request['honeypot'])) {
                echo json_encode(['success' => false, 'message' => 'Bot detected.']);
                exit;
            }
            require_once __DIR__ . '/../src/Services/AuthService.php';
            $authService = new AuthService($pdo);
            echo json_encode($authService->register($request['username'] ?? '', $request['email'] ?? '', $request['password'] ?? ''));
            break;

        case 'logout':
            header('Content-Type: application/json');
            session_destroy();
            echo json_encode(['success' => true]);
            break;

        default:
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid route.']);
            break;
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'System Error: ' . $e->getMessage()]);
}
?>