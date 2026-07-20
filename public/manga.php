<?php
// public/manga.php
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
require_once __DIR__ . '/../src/ApiHelper.php';
require_once __DIR__ . '/../src/Services/MangaService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/ChapterFeedService.php';

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

$mangaId = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$mangaId) die("Error: No Manga ID provided.");

// 1. Initialize Services
$authService = new AuthService($pdo);
$mangaService = new MangaService($pdo);
$feedService = new ChapterFeedService();

// 2. Auth & Concurrency Checks
$userId = $_SESSION['user_id'] ?? null;
$isVIP = $authService->isVip($userId);

$userOwnsThisTitle = false;
if (!$isVIP && $userId) {
    $stmtCheck = $pdo->prepare("SELECT expires_at FROM cp_user_titles WHERE user_id = ? AND manga_id = ? AND expires_at > NOW()");
    $stmtCheck->execute([$userId, $mangaId]);
    $ownExpiry = $stmtCheck->fetchColumn();
    if ($ownExpiry) {
        $userOwnsThisTitle = true;
        $ownExpiryText = date('M j, Y', strtotime($ownExpiry));
    }
}

if ($userId && !$authService->verifySessionConcurrency($userId, $_SESSION['session_token'] ?? '')) {
    session_destroy();
    echo "<script>alert('Your account was logged into from another device.'); window.location.href='index.php';</script>";
    exit;
}

// 3. Fetch Core Data
$manga = $mangaService->getMangaById($mangaId);
if (!$manga) die("Error: Comic not found in database.");

$isBookmarked = $mangaService->checkBookmarkStatus($userId, $mangaId);
$altTitlesString = $mangaService->parseAltTitles($manga['alt_titles']);

// 4. Fetch the Chapter Feed via the new Service
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;

$allowedRatings = isset($_SESSION['content_filters']) ? explode(',', $_SESSION['content_filters']) : ['safe', 'suggestive'];
$feedData = $feedService->getFeed($manga, $page, $limit, $allowedRatings);

// --- START OF NEW STRICT NATIVE-ONLY FILTER ---
$rawChapters = $feedData['chapters'];
$uniqueChapters = [];

foreach ($rawChapters as $chap) {
    $c = $chap['attributes'];
    $chapNum = isset($c['chapter']) && $c['chapter'] !== null ? (string)$c['chapter'] : 'Oneshot';

    // IF IT IS AN EXTERNAL LINK, THROW IT IN THE TRASH IMMEDIATELY.
    if (!empty($c['externalUrl'])) {
        continue; 
    }

    // Only keep one version of each chapter number
    if (!isset($uniqueChapters[$chapNum])) {
        $uniqueChapters[$chapNum] = $chap;
    }
}

$chapters = array_values($uniqueChapters);

// FIX 1: Restore the global API total so pagination knows how many pages exist!
$totalChapters = $feedData['totalChapters']; 
// --- END OF NEW STRICT NATIVE-ONLY FILTER ---

// FIX 2: Define the offset so the "Showing X to Y" text works perfectly
$offset = ($page - 1) * $limit; 

$firstChapterUrl = $feedData['firstChapterUrl'];
$startTarget = $feedData['startTarget'];
$totalPages = ceil($totalChapters / $limit);

$readHistory = $userId ? $mangaService->getUserReadingHistory($userId, $mangaId) : null;

$mainButtonText = "START READING";
$mainButtonUrl = $firstChapterUrl;

if ($readHistory && !empty($readHistory['chapter_id'])) {
    $mainButtonText = "RESUME (Ch. " . htmlspecialchars($readHistory['chapter_num']) . ")";
    $mainButtonUrl = "read.php?manga_id=" . urlencode($mangaId) . "&chapter_id=" . urlencode($readHistory['chapter_id']);
}

// 5. Fetch Recommendations
$recommendations = $mangaService->getRecommendations($mangaId, $allowedRatings);
$type = ($manga['original_language'] === 'ja') ? 'Manga' : (($manga['original_language'] === 'ko') ? 'Manhwa' : 'Comic');
$genres = $manga['genres'] ? htmlspecialchars($manga['genres']) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="manifest" href="/manifest.json">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="ComixKini">
<link rel="apple-touch-icon" href="/icons/icon-192x192.png">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js');
  });
}
</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($manga['title']) ?> - ComixKini</title>
    <meta name="referrer" content="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) { document.documentElement.classList.add('dark'); } 
        else { document.documentElement.classList.remove('dark'); }
        tailwind.config = { darkMode: 'class', theme: { extend: { colors: { background: '#0d1015', surface: '#151921', card: '#1a1f29', accent: '#26c6da' } } } }
    </script>
    <style>
        .line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .line-clamp-4 { display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2d3748; border-radius: 4px; }
        .fade-in { animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="dark:bg-background bg-gray-100 dark:text-gray-300 text-gray-800 font-sans antialiased relative pb-10 transition-colors duration-300">
    
    <nav class="dark:bg-surface bg-white border-b dark:border-gray-800 border-gray-200 sticky top-0 z-50 transition-colors duration-300">
        <div class="max-w-[1500px] mx-auto px-4 h-16 flex items-center justify-between gap-2 sm:gap-4">
            <a href="index.php" class="flex items-center space-x-2 flex-shrink-0 group">
                <svg class="w-7 h-7 sm:w-8 sm:h-8 text-accent group-hover:scale-110 transition-transform" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" viewBox="0 0 24 24">
                    <path d="M21 11.5C21 16.75 16.97 21 12 21c-1.66 0-3.21-.42-4.55-1.16L3 21l1.5-4.2C3.55 15.35 3 13.5 3 11.5 3 6.25 7.03 2 12 2s9 4.25 9 9.5z M12 6 l1.5 4 h4 l-3.2 2.5 l1.2 4 l-3.5 -2.6 l-3.5 2.6 l1.2 -4 l-3.2 -2.5 h4 z"/>
                </svg>
                <span class="text-lg sm:text-xl font-bold tracking-wider dark:text-white text-black">COMIXKINI</span>
            </a>

            <div class="flex-grow max-w-2xl relative hidden md:flex items-center">
                <div class="relative w-full flex items-center dark:bg-[#1e232d] bg-gray-100 rounded border dark:border-gray-700 border-gray-300 focus-within:border-gray-500 transition-colors">
                    <div class="pl-3 flex items-center pointer-events-none">
                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input type="text" id="searchInput" autocomplete="off" placeholder="Search comic..." class="bg-transparent text-sm py-2.5 pl-3 pr-10 w-full focus:outline-none dark:text-gray-200 text-black">
                </div>
                <div id="searchResults" class="absolute top-full left-0 mt-2 w-full dark:bg-[#1e232d] bg-white border dark:border-gray-700 border-gray-200 rounded-md shadow-2xl z-50 hidden">
                    <div id="searchResultsList" class="flex flex-col max-h-[400px] overflow-y-auto"></div>
                </div>
            </div>

            <div class="flex items-center space-x-2 sm:space-x-4 flex-shrink-0">
                <div class="flex dark:bg-gray-800 bg-gray-200 rounded overflow-hidden border dark:border-gray-700 border-gray-300">
                    <button id="themeLight" class="px-2.5 sm:px-3 py-1.5 transition-colors focus:outline-none">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zM8 7V5h4v2H8zm0 6v-2h4v2H8z"/></svg>
                    </button>
                    <button id="themeDark" class="px-2.5 sm:px-3 py-1.5 transition-colors focus:outline-none">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
                    </button>
                </div>
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="relative group cursor-pointer block">
                        <div class="flex items-center gap-2 dark:bg-[#1a1f29] bg-gray-100 border dark:border-gray-700 border-gray-300 rounded-full sm:pl-3 p-0.5 sm:pr-1 sm:py-1 hover:border-accent transition-colors">
                            <span class="hidden sm:block text-xs font-bold dark:text-gray-200 text-gray-800"><?= htmlspecialchars($_SESSION['username']) ?></span>
                            <div class="w-8 h-8 sm:w-7 sm:h-7 bg-accent rounded-full flex items-center justify-center text-black font-black text-xs sm:text-[10px]">
                                <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                            </div>
                        </div>
                        <div class="absolute right-0 mt-2 w-48 dark:bg-[#1a1f29] bg-white border dark:border-gray-700 border-gray-200 rounded shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 overflow-hidden">
                            <a href="profile.php" class="block px-4 py-3 sm:py-2 text-sm dark:text-gray-300 text-gray-700 dark:hover:bg-[#262c38] hover:bg-gray-100 hover:text-accent">My Library</a>
                            <a href="subscription.php" class="block px-4 py-3 sm:py-2 text-sm dark:text-gray-300 text-gray-700 dark:hover:bg-[#262c38] hover:bg-gray-100 hover:text-accent border-b dark:border-gray-700 border-gray-200">ComixKini Status</a>
                            <button onclick="logoutUser()" class="w-full text-left px-4 py-3 sm:py-2 text-sm text-red-500 hover:bg-red-500/10 font-bold">Logout</button>
                        </div>
                    </div>
                <?php else: ?>
                    <button onclick="openAuthModal()" class="bg-accent hover:bg-cyan-400 text-black text-[11px] sm:text-sm font-bold py-1.5 px-3 sm:px-4 rounded transition-colors block shadow-md">LOGIN</button>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    
    <div class="max-w-[1500px] mx-auto px-4 py-8 flex flex-col md:flex-row gap-6">
        <div class="w-full md:w-[260px] flex-shrink-0">
            <div class="rounded-md overflow-hidden shadow-2xl border dark:border-gray-800 border-gray-300">
                <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($manga['cover_url']) ?>" alt="Cover" class="w-full h-auto object-contain dark:bg-[#151921] bg-gray-200">
            </div>
        </div>
        <div class="flex-grow flex flex-col min-w-0">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-[10px] dark:bg-gray-800 bg-gray-200 dark:text-gray-400 text-gray-600 px-2 py-1 rounded font-bold tracking-wider">#<?= substr($manga['manga_id'], 0, 4) ?></span>
                <span class="text-[10px] dark:bg-gray-800 bg-gray-200 dark:text-gray-400 text-gray-600 px-2 py-1 rounded font-bold tracking-wider uppercase">[ <?= htmlspecialchars($manga['publish_year'] ?? 'N/A') ?> ]</span>
                <?php if($manga['content_rating'] === 'erotica' || $manga['content_rating'] === 'pornographic'): ?>
                    <span class="text-[10px] bg-[#ff4757]/20 text-[#ff4757] border border-[#ff4757]/50 px-2 py-1 rounded font-bold tracking-wider uppercase">18+ MATURE</span>
                <?php endif; ?>
            </div>
            <h1 class="text-3xl font-black dark:text-white text-black leading-tight mb-2"><?= htmlspecialchars($manga['title']) ?></h1>
            <?php if ($userOwnsThisTitle): ?>
                <div class="inline-block bg-green-900/30 border border-green-500/50 text-green-400 text-xs font-bold px-3 py-1.5 rounded mb-4">
                    ✓ Purchased Title - Access valid until <?= $ownExpiryText ?>
                </div>
            <?php elseif (!$isVIP): ?>
                <div class="inline-block dark:bg-[#1a1f29] bg-gray-200 border dark:border-gray-700 border-gray-300 p-2.5 rounded mb-4 flex-col gap-2">
                    <div class="flex gap-2 items-center">
                        <input type="text" id="specificVoucherInput" placeholder="Enter Unlock Code" class="dark:bg-[#151921] bg-white border dark:border-gray-600 border-gray-300 text-xs rounded py-1.5 px-3 focus:outline-none dark:text-white text-black w-48 uppercase font-mono">
                        <button onclick="redeemSpecificVoucher()" class="bg-accent hover:bg-cyan-400 text-black text-xs font-bold px-4 py-1.5 rounded transition-colors shadow-sm">Redeem</button>
                    </div>
                    <span id="specificVoucherMessage" class="mt-2 text-xs font-bold block hidden"></span>
                </div>
            <?php endif; ?>
            <p class="text-[11px] text-gray-500 leading-relaxed mb-4 line-clamp-4 hover:line-clamp-none transition-all cursor-pointer"><?= htmlspecialchars($altTitlesString) ?></p>

            <div class="flex flex-wrap gap-2 mb-6">
                <a href="<?= htmlspecialchars($mainButtonUrl) ?>" target="<?= $startTarget ?>" class="bg-accent hover:bg-cyan-400 text-black font-black text-sm px-6 py-2.5 rounded flex items-center shadow-md transition-colors">
                    <?= $mainButtonText ?> <svg class="w-4 h-4 ml-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
                </a>
                
                <button onclick="toggleBookmark('<?= $mangaId ?>')" id="bookmarkBtn" class="<?= $isBookmarked ? 'bg-[#ff4757] text-white border-[#ff4757]' : 'dark:bg-surface bg-white text-black dark:text-white border-gray-300 dark:border-gray-700 hover:border-[#ff4757] dark:hover:border-[#ff4757]' ?> border px-4 py-2.5 rounded shadow-sm transition-colors flex items-center gap-2 font-bold text-sm">
                    <svg class="w-5 h-5" fill="<?= $isBookmarked ? 'currentColor' : 'none' ?>" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                    <span id="bookmarkText"><?= $isBookmarked ? 'SAVED' : 'SAVE' ?></span>
                </button>
            </div>

            <p class="text-sm dark:text-gray-400 text-gray-700 leading-relaxed line-clamp-4">
                <?= !empty($manga['description']) ? htmlspecialchars($manga['description']) : 'No description available.' ?>
            </p>
        </div>
        <div class="w-full md:w-[350px] flex-shrink-0 dark:bg-surface bg-white rounded-md border dark:border-gray-800 border-gray-300 p-5">
            <div class="flex justify-between items-center border-b dark:border-gray-700 border-gray-200 pb-4 mb-4">
                <div class="flex text-2xl text-gray-300">
                    <span class="text-yellow-400">★</span><span class="text-yellow-400">★</span><span class="text-yellow-400">★</span><span class="text-yellow-400">★</span><span class="text-gray-600">★</span>
                </div>
                <div class="dark:bg-gray-700 bg-gray-200 dark:text-white text-black font-bold text-lg px-3 py-1 rounded">9.1</div>
            </div>
            <div class="text-xs dark:text-gray-400 text-gray-600 space-y-2.5 leading-relaxed">
                <p><span class="dark:text-gray-500 text-gray-500">Followed:</span> <span class="dark:text-gray-200 text-gray-800"><?= number_format($manga['followers']) ?> users</span></p>
                <p><span class="dark:text-gray-500 text-gray-500">Type:</span> <span class="dark:text-gray-200 text-gray-800"><?= htmlspecialchars($type) ?></span></p>
                <p><span class="dark:text-gray-500 text-gray-500">Demographics:</span> <span class="dark:text-gray-200 text-gray-800"><?= htmlspecialchars(ucfirst($manga['demographic'] ?? 'Unknown')) ?></span></p>
                <p><span class="dark:text-gray-500 text-gray-500">Authors:</span> <span class="text-accent"><?= htmlspecialchars($manga['author'] ?? 'Unknown') ?></span></p>
                <p><span class="dark:text-gray-500 text-gray-500">Artists:</span> <span class="text-accent"><?= htmlspecialchars($manga['artist'] ?? 'Unknown') ?></span></p>
                <p><span class="dark:text-gray-500 text-gray-500">Genres:</span> <span class="dark:text-gray-200 text-gray-800"><?= $genres ?></span></p>
                <p><span class="dark:text-gray-500 text-gray-500">Original lang:</span> <span class="dark:text-gray-200 text-gray-800"><?= htmlspecialchars($manga['original_language'] ?? 'N/A') ?></span></p>
            </div>
        </div>
    </div>

    <div class="max-w-[1500px] mx-auto px-4 flex flex-col xl:flex-row gap-6 mt-4">
        <div class="w-full xl:w-[70%]">
            <div class="flex text-[11px] font-bold text-gray-500 uppercase tracking-widest border-b dark:border-gray-800 border-gray-300 pb-2 mb-2 px-2 mt-8">
                <div class="w-2/3">📖 Chapter</div>
                <div class="w-1/3 text-right">⏰ Updated</div>
            </div>
            <div class="flex flex-col">
                <?php if (empty($chapters)): ?>
                    <div class="p-6 text-center text-gray-500 text-sm">No chapters found for this page.</div>
                <?php else: ?>
                    <?php foreach ($chapters as $chap): ?>
                        <?php 
                            $c = $chap['attributes'];
                            $chapNum = isset($c['chapter']) && $c['chapter'] !== null ? $c['chapter'] : 'Oneshot';
                            $titleText = !empty($c['title']) ? ' - ' . htmlspecialchars($c['title']) : '';
                            
                            $isFreeChapter = ($chapNum === 'Oneshot' || floatval($chapNum) < 2);
                            $isLocked = !$isVIP && !$isFreeChapter && !$userOwnsThisTitle;

                            if ($isLocked) {
                                $chapUrl = isset($_SESSION['user_id']) ? 'subscription.php' : 'javascript:openAuthModal()';
                                $target = '_self';
                                $statusIcon = '<svg class="w-4 h-4 ml-2 inline text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path></svg>';
                                $rowClass = 'opacity-70 dark:hover:bg-[#1a1f29] hover:bg-gray-100 cursor-pointer';
                            } else {
                                $rowClass = 'dark:hover:bg-[#1e232d] hover:bg-gray-50';
                                
                                // Simplified: Everything here is guaranteed native because of our filter above!
                                $chapUrl = "read.php?manga_id={$mangaId}&chapter_id={$chap['id']}";
                                $target = '_self';
                                $statusIcon = '';
                            }
                        ?>
                        <a href="<?= htmlspecialchars($chapUrl) ?>" target="<?= $target ?>" class="flex items-center justify-between p-3 border-b dark:border-gray-800/50 border-gray-200 transition-colors group <?= $rowClass ?>">
                            <div class="w-2/3 flex items-center pr-4">
                                <span class="text-sm font-bold dark:text-gray-200 text-gray-800 group-hover:text-accent transition-colors">Ch. <?= htmlspecialchars($chapNum) ?> <?= $statusIcon ?></span>
                                <span class="text-sm text-gray-500 ml-1 truncate"><?= $titleText ?></span>
                            </div>
                            <div class="w-1/3 text-right flex items-center justify-end gap-4">
                                 <?php if ($isLocked): ?>
                                    <span class="text-[10px] font-bold text-yellow-500 uppercase tracking-widest border border-yellow-500/50 px-2 py-0.5 rounded">VIP Only</span>
                                <?php else: ?>
                                    <span class="text-xs text-gray-500 flex items-center gap-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> <?= timeAgo($c['updatedAt']) ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($totalChapters > 0): ?>
            <div class="mt-8 flex flex-col items-center">
                <?php 
                    $startItem = $offset + 1;
                    $endItem = min($offset + $limit, $totalChapters);
                ?>
                <span class="text-xs text-gray-500 mb-4">Showing <?= $startItem ?> to <?= $endItem ?> of <?= $totalChapters ?> items</span>
                
                <div class="flex space-x-1 text-sm font-bold">
                    <?php if ($page > 1): ?>
                        <a href="?id=<?= urlencode($mangaId) ?>&page=1" class="px-3 py-1.5 dark:text-gray-400 text-gray-600 dark:hover:bg-[#1e232d] hover:bg-gray-200 dark:hover:text-white hover:text-black rounded transition-colors">&laquo; First</a>
                        <a href="?id=<?= urlencode($mangaId) ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 dark:text-gray-400 text-gray-600 dark:hover:bg-[#1e232d] hover:bg-gray-200 dark:hover:text-white hover:text-black rounded transition-colors">&lsaquo; Prev</a>
                    <?php endif; ?>

                    <?php 
                    $maxPagesToShow = 5;
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    if ($startPage === 1) { $endPage = min($totalPages, $maxPagesToShow); }
                    if ($endPage === $totalPages) { $startPage = max(1, $totalPages - $maxPagesToShow + 1); }

                    for ($p = $startPage; $p <= $endPage; $p++): 
                    ?>
                        <?php if ($p == $page): ?>
                            <span class="px-3 py-1.5 dark:bg-[#4a5568] bg-gray-300 dark:text-white text-black rounded cursor-default"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?id=<?= urlencode($mangaId) ?>&page=<?= $p ?>" class="px-3 py-1.5 dark:text-gray-400 text-gray-600 dark:hover:bg-[#1e232d] hover:bg-gray-200 dark:hover:text-white hover:text-black rounded transition-colors"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?id=<?= urlencode($mangaId) ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 dark:text-gray-400 text-gray-600 dark:hover:bg-[#1e232d] hover:bg-gray-200 dark:hover:text-white hover:text-black rounded transition-colors">Next &rsaquo;</a>
                        <a href="?id=<?= urlencode($mangaId) ?>&page=<?= $totalPages ?>" class="px-3 py-1.5 dark:text-gray-400 text-gray-600 dark:hover:bg-[#1e232d] hover:bg-gray-200 dark:hover:text-white hover:text-black rounded transition-colors">Last &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <aside class="w-full xl:w-[30%]">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="dark:text-white text-black font-bold tracking-wide">Recommendations</h2>
            </div>
            <div class="flex flex-col space-y-3">
                <?php foreach ($recommendations as $rec): ?>
                <a href="manga.php?id=<?= urlencode($rec['manga_id']) ?>" class="flex gap-3 dark:hover:bg-[#1e232d] hover:bg-gray-50 p-2 rounded transition-colors group">
                    <div class="w-16 h-24 flex-shrink-0 rounded bg-gray-800 overflow-hidden shadow-md border dark:border-gray-700 border-gray-300">
                        <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($rec['cover_url'] ?? 'https://via.placeholder.com/80x110') ?>" class="w-full h-full object-contain dark:bg-[#151921] bg-gray-200">
                    </div>
                    <div class="flex flex-col justify-center flex-grow">
                        <span class="text-[9px] uppercase tracking-widest text-accent font-bold mb-1"><?= htmlspecialchars(explode(', ', $rec['genres'])[0] ?? 'Comic') ?></span>
                        <h3 class="text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-accent transition-colors">
                            <?= htmlspecialchars($rec['title']) ?>
                        </h3>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>

    <div id="authModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center">
        <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" onclick="closeAuthModal()"></div>
        <div class="relative w-full max-w-md dark:bg-surface bg-white border dark:border-gray-700 border-gray-300 rounded-lg shadow-2xl overflow-hidden animate-fade-in">
            <button onclick="closeAuthModal()" class="absolute top-4 right-4 dark:text-gray-400 text-gray-500 hover:text-black dark:hover:text-white z-10 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            <div class="flex border-b dark:border-gray-800 border-gray-200">
                <button id="tabLogin" onclick="switchAuthTab('login')" class="flex-1 py-4 text-sm font-bold text-accent border-b-2 border-accent transition-colors">LOGIN</button>
                <button id="tabRegister" onclick="switchAuthTab('register')" class="flex-1 py-4 text-sm font-bold text-gray-500 hover:text-gray-800 dark:hover:text-gray-300 transition-colors">REGISTER</button>
            </div>
            <div class="p-6">
                <div id="authError" class="hidden mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded text-red-500 text-xs text-center font-bold"></div>
                <form id="loginForm" onsubmit="handleAuth(event, 'login')" class="flex flex-col gap-4">
                    <div>
                        <label class="block text-xs font-bold dark:text-gray-400 text-gray-600 mb-1">Email or Username</label>
                        <input type="text" id="loginId" required class="w-full dark:bg-[#1a1f29] bg-gray-50 border dark:border-gray-700 border-gray-300 rounded p-2.5 dark:text-white text-black text-sm focus:border-accent focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold dark:text-gray-400 text-gray-600 mb-1">Password</label>
                        <input type="password" id="loginPassword" required class="w-full dark:bg-[#1a1f29] bg-gray-50 border dark:border-gray-700 border-gray-300 rounded p-2.5 dark:text-white text-black text-sm focus:border-accent focus:outline-none">
                    </div>
                    <button type="submit" class="w-full bg-accent hover:bg-cyan-400 text-black font-black py-3 rounded mt-2 shadow-md transition-colors">ENTER COMIXKINI</button>
                </form>
                <form id="registerForm" onsubmit="handleAuth(event, 'register')" class="hidden flex flex-col gap-4">
                    <div style="display: none; position: absolute; left: -9999px;" aria-hidden="true">
                        <label>Website (Leave Blank)</label><input type="text" id="regHoneypot" autocomplete="off" tabindex="-1"><input type="hidden" id="regTime" value="<?= time() ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold dark:text-gray-400 text-gray-600 mb-1">Username</label>
                        <input type="text" id="regUsername" required class="w-full dark:bg-[#1a1f29] bg-gray-50 border dark:border-gray-700 border-gray-300 rounded p-2.5 dark:text-white text-black text-sm focus:border-accent focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold dark:text-gray-400 text-gray-600 mb-1">Email Address</label>
                        <input type="email" id="regEmail" required class="w-full dark:bg-[#1a1f29] bg-gray-50 border dark:border-gray-700 border-gray-300 rounded p-2.5 dark:text-white text-black text-sm focus:border-accent focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold dark:text-gray-400 text-gray-600 mb-1">Password</label>
                        <input type="password" id="regPassword" required minlength="6" class="w-full dark:bg-[#1a1f29] bg-gray-50 border dark:border-gray-700 border-gray-300 rounded p-2.5 dark:text-white text-black text-sm focus:border-accent focus:outline-none">
                    </div>
                    <button type="submit" class="w-full dark:bg-white bg-gray-800 hover:bg-black dark:hover:bg-gray-200 dark:text-black text-white font-black py-3 rounded mt-2 shadow-md transition-colors">CREATE ACCOUNT</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const html = document.documentElement; const btnLight = document.getElementById('themeLight'); const btnDark = document.getElementById('themeDark');
        function updateThemeButtons() {
            if (html.classList.contains('dark')) { btnDark.classList.add('bg-accent', 'text-black'); btnDark.classList.remove('text-gray-400'); btnLight.classList.remove('bg-accent', 'text-black'); btnLight.classList.add('dark:text-gray-400', 'text-gray-600'); }
            else { btnLight.classList.add('bg-accent', 'text-black'); btnLight.classList.remove('text-gray-400', 'text-gray-600'); btnDark.classList.remove('bg-accent', 'text-black'); btnDark.classList.add('text-gray-600'); }
        }
        if(btnLight && btnDark) { updateThemeButtons(); btnLight.addEventListener('click', () => { html.classList.remove('dark'); localStorage.setItem('theme', 'light'); updateThemeButtons(); }); btnDark.addEventListener('click', () => { html.classList.add('dark'); localStorage.setItem('theme', 'dark'); updateThemeButtons(); }); }

        function setupSearch(inputId, resultsId, listId) {
            const inputEl = document.getElementById(inputId); const resultsEl = document.getElementById(resultsId); const listEl = document.getElementById(listId);
            if(!inputEl || !resultsEl || !listEl) return;
            let debounceTimer;
            inputEl.addEventListener('input', function (e) {
                const query = e.target.value.trim(); clearTimeout(debounceTimer);
                if (query.length < 2) { resultsEl.classList.add('hidden'); return; }
                debounceTimer = setTimeout(() => {
                    fetch(`api.php?action=search&q=${encodeURIComponent(query)}`).then(res => res.json()).then(data => {
                        listEl.innerHTML = '';
                        if (data.length === 0) { listEl.innerHTML = '<div class="p-4 text-sm text-gray-500 text-center">No comics found.</div>'; }
                        else {
                            data.forEach(manga => {
                                const cover = manga.cover_url ? manga.cover_url : 'https://via.placeholder.com/40x56';
                                listEl.innerHTML += `<a href="manga.php?id=${manga.manga_id}" class="flex items-center p-3 hover:bg-gray-100 dark:hover:bg-[#262c38] transition-colors border-b border-gray-200 dark:border-gray-800/50 cursor-pointer group"><img src="${cover}" class="w-10 h-14 object-cover rounded shadow border border-gray-300 dark:border-gray-700"><div class="flex flex-col flex-grow ml-4"><span class="text-[14px] font-bold dark:text-gray-200 text-gray-800 line-clamp-1 group-hover:text-accent">${manga.title}</span></div></a>`;
                            });
                        }
                        resultsEl.classList.remove('hidden');
                    });
                }, 300);
            });
        }
        setupSearch('searchInput', 'searchResults', 'searchResultsList');

        const authModal = document.getElementById('authModal'); const loginForm = document.getElementById('loginForm'); const registerForm = document.getElementById('registerForm'); const tabLogin = document.getElementById('tabLogin'); const tabRegister = document.getElementById('tabRegister'); const authError = document.getElementById('authError');
        function openAuthModal() { authModal.classList.remove('hidden'); } function closeAuthModal() { authModal.classList.add('hidden'); authError.classList.add('hidden'); }
        function switchAuthTab(tab) {
            authError.classList.add('hidden');
            if (tab === 'login') { loginForm.classList.remove('hidden'); registerForm.classList.add('hidden'); tabLogin.classList.add('text-accent', 'border-b-2', 'border-accent'); tabLogin.classList.remove('text-gray-500'); tabRegister.classList.remove('text-accent', 'border-b-2', 'border-accent'); tabRegister.classList.add('text-gray-500'); }
            else { loginForm.classList.add('hidden'); registerForm.classList.remove('hidden'); tabRegister.classList.add('text-accent', 'border-b-2', 'border-accent'); tabRegister.classList.remove('text-gray-500'); tabLogin.classList.remove('text-accent', 'border-b-2', 'border-accent'); tabLogin.classList.add('text-gray-500'); }
        }
        function handleAuth(event, action) {
            event.preventDefault(); authError.classList.add('hidden'); let payload = { action: action };
            if (action === 'login') { payload.login_id = document.getElementById('loginId').value; payload.password = document.getElementById('loginPassword').value; } 
            else {
                payload.username = document.getElementById('regUsername').value; payload.email = document.getElementById('regEmail').value; payload.password = document.getElementById('regPassword').value;
                const honeypot = document.getElementById('regHoneypot'); const loadTime = document.getElementById('regTime');
                if (honeypot) payload.honeypot = honeypot.value; if (loadTime) payload.load_time = loadTime.value;
            }
            fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(res => res.json()).then(data => { if (data.success) { window.location.reload(); } else { authError.innerText = data.message; authError.classList.remove('hidden'); } })
            .catch(err => { authError.innerText = "Connection error. Try again."; authError.classList.remove('hidden'); });
        }
        function logoutUser() { fetch('api.php?action=logout').then(() => window.location.reload()); }

        function redeemSpecificVoucher() {
            const code = document.getElementById('specificVoucherInput').value.trim();
            const msgEl = document.getElementById('specificVoucherMessage');
            if (!code) return;
            msgEl.innerText = "Redeeming...";
            msgEl.className = "mt-2 text-xs font-bold text-blue-500 block";
            
            fetch('api.php?action=redeem_voucher', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code, manga_id: '<?= $mangaId ?>' })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    msgEl.innerText = "Success! " + (data.new_expiry || "Title Unlocked!");
                    msgEl.className = "mt-2 text-xs font-bold text-green-500 block";
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    msgEl.innerText = data.message || "Failed to redeem.";
                    msgEl.className = "mt-2 text-xs font-bold text-red-500 block";
                }
            })
            .catch(err => {
                msgEl.innerText = "Connection error.";
                msgEl.className = "mt-2 text-xs font-bold text-red-500 block";
            });
        }

        function toggleBookmark(mangaId) {
            const isGuest = document.querySelector('button[onclick="openAuthModal()"]');
            if (isGuest) { openAuthModal(); return; }
            fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'toggle_bookmark', manga_id: mangaId }) })
            .then(res => res.json()).then(data => {
                if (data.success) {
                    const btn = document.getElementById('bookmarkBtn'); const text = document.getElementById('bookmarkText'); const svg = btn.querySelector('svg');
                    if (data.bookmarked) { btn.className = 'bg-[#ff4757] text-white border-[#ff4757] border px-4 py-2.5 rounded shadow-sm transition-colors flex items-center gap-2 font-bold text-sm'; text.innerText = 'SAVED'; svg.setAttribute('fill', 'currentColor'); } 
                    else { btn.className = 'dark:bg-surface bg-white text-black dark:text-white border-gray-300 dark:border-gray-700 hover:border-[#ff4757] dark:hover:border-[#ff4757] border px-4 py-2.5 rounded shadow-sm transition-colors flex items-center gap-2 font-bold text-sm'; text.innerText = 'SAVE'; svg.setAttribute('fill', 'none'); }
                }
            });
        }
        document.addEventListener('click', (e) => { if (!e.target.closest('#searchInput') && !e.target.closest('#searchResults')) { const searchResults = document.getElementById('searchResults'); if(searchResults) searchResults.classList.add('hidden'); } });
    </script>
</body>
</html>
