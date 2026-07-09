<?php
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

// 4. FORCE CLOUDFLARE/BROWSER TO NEVER CACHE THIS HTML
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$pdo = require_once __DIR__ . '/../config/database.php';

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

// --- FETCH CONTINUE READING HISTORY ---
$readingHistory = [];
if (isset($_SESSION['user_id'])) {
    $stmtHistory = $pdo->prepare("
        SELECT h.manga_id, h.chapter_id, h.chapter_num, h.read_at, t.title, t.cover_url 
        FROM cp_reading_history h
        JOIN cp_titles t ON h.manga_id = t.manga_id
        WHERE h.user_id = ? AND t.is_active = 1
        ORDER BY h.read_at DESC
        LIMIT 5
    ");
    $stmtHistory->execute([$_SESSION['user_id']]);
    $readingHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
}
// --------------------------------------

$allowedRatings = ['safe', 'suggestive'];
if (isset($_SESSION['content_filters'])) {
    $allowedRatings = explode(',', $_SESSION['content_filters']);
}
if (in_array('safe', $allowedRatings)) array_push($allowedRatings, '10+', '13+', '15+', '16+', 'Semua Umur', 'Remaja');
if (in_array('suggestive', $allowedRatings)) array_push($allowedRatings, '17+', 'Dewasa');
if (in_array('erotica', $allowedRatings) || in_array('pornographic', $allowedRatings)) array_push($allowedRatings, '18+', '21+');
$ratingPlaceholders = "'" . implode("','", $allowedRatings) . "'";

$baseQuery = "FROM cp_titles WHERE is_active = 1 AND content_rating IN ($ratingPlaceholders) AND en_chapter_count >= 3";
$timeFilter1Month = " AND last_updated >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";

$stmtRecentPopular = $pdo->query("SELECT * $baseQuery $timeFilter1Month ORDER BY followers DESC LIMIT 15");
$recentPopular = $stmtRecentPopular->fetchAll(PDO::FETCH_ASSOC);

// Prevent overlap
$popularIds = array_column($recentPopular, 'manga_id');
$excludeIds = empty($popularIds) ? "'none'" : "'" . implode("','", $popularIds) . "'";

$stmtMostFollows = $pdo->query("SELECT * $baseQuery AND en_chapter_count BETWEEN 3 AND 40 AND manga_id NOT IN ($excludeIds) ORDER BY followers DESC LIMIT 15");
$mostFollows = $stmtMostFollows->fetchAll(PDO::FETCH_ASSOC);

// Prevent overlap
$mostFollowsIds = array_column($mostFollows, 'manga_id');
$allExcludeIds = empty(array_merge($popularIds, $mostFollowsIds)) ? "'none'" : "'" . implode("','", array_merge($popularIds, $mostFollowsIds)) . "'";

// Use followers ASC as secondary sort so it picks different items than the top popular since last_updated is identical for all right now
$stmtLatest = $pdo->query("SELECT * $baseQuery AND manga_id NOT IN ($allExcludeIds) ORDER BY last_updated DESC, followers ASC LIMIT 15");
$latestUpdates = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);

$stmtSidebar = $pdo->query("SELECT * $baseQuery AND followers > 1000 ORDER BY RAND() LIMIT 8");
$sidebarMangas = $stmtSidebar->fetchAll(PDO::FETCH_ASSOC);

$showErotica = in_array('erotica', $allowedRatings);
$showExplicit = in_array('pornographic', $allowedRatings);

if ($showErotica) {
    $stmtErotica = $pdo->query("SELECT * FROM cp_titles WHERE is_active = 1 AND content_rating = 'erotica' AND en_chapter_count >= 3 $timeFilter1Month ORDER BY followers DESC, last_updated DESC LIMIT 15");
    $eroticaMangas = $stmtErotica->fetchAll(PDO::FETCH_ASSOC);
}
if ($showExplicit) {
    $stmtExplicit = $pdo->query("SELECT * FROM cp_titles WHERE is_active = 1 AND content_rating = 'pornographic' AND en_chapter_count >= 3 $timeFilter1Month ORDER BY followers DESC, last_updated DESC LIMIT 15");
    $explicitMangas = $stmtExplicit->fetchAll(PDO::FETCH_ASSOC);
}
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
    <title>ComixKini - Home</title>
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
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2d3748; border-radius: 4px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .fade-in { animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="dark:bg-background bg-gray-100 dark:text-gray-300 text-gray-800 font-sans antialiased relative transition-colors duration-300">

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
                <button id="mobileSearchToggle" class="md:hidden p-1.5 sm:p-2 text-gray-400 hover:text-accent transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </button>

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
                            <?php if (strpos($_SESSION['username'], 'guest_') === 0): ?>
                                <button onclick="openAuthModal('register')" class="w-full text-left px-4 py-3 sm:py-2 text-sm text-blue-500 hover:bg-blue-500/10 font-bold border-b dark:border-gray-700 border-gray-200">Claim Account</button>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <a href="admin.php" class="block px-4 py-3 sm:py-2 text-sm text-accent font-bold dark:hover:bg-[#262c38] hover:bg-gray-100 border-b dark:border-gray-700 border-gray-200 flex justify-between items-center">
                                    Admin Panel <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                </a>
                            <?php endif; ?>
                            <button onclick="logoutUser()" class="w-full text-left px-4 py-3 sm:py-2 text-sm text-red-500 hover:bg-red-500/10 font-bold">Logout</button>
                        </div>
                    </div>
                <?php else: ?>
                    <button onclick="openAuthModal()" class="bg-accent hover:bg-cyan-400 text-black text-[11px] sm:text-sm font-bold py-1.5 px-3 sm:px-4 rounded transition-colors block shadow-md">LOGIN</button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <?php if (isset($_SESSION['user_id']) && strpos($_SESSION['username'], 'guest_') === 0): ?>
    <div class="bg-blue-600 text-white text-sm font-bold py-3 px-4 text-center shadow-lg relative z-40">
        You are browsing as a Guest. 
        <button onclick="openAuthModal('register')" class="underline hover:text-blue-200 ml-2 transition-colors">Claim your account</button> to save your progress permanently!
    </div>
    <?php endif; ?>

    <div id="mobileSearchTray" class="hidden md:hidden bg-white dark:bg-surface border-b border-gray-200 dark:border-gray-800 z-[60] p-3 shadow-lg relative transition-colors duration-300">
        <div class="relative w-full max-w-md mx-auto">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <input type="text" id="mobileSearchInput" autocomplete="off" placeholder="Search comic..." class="bg-gray-100 dark:bg-[#1e232d] border border-gray-300 dark:border-gray-700 text-sm rounded py-2.5 pl-10 pr-4 w-full focus:outline-none focus:border-accent dark:text-gray-200 text-black transition-colors">
        </div>
        <div id="mobileSearchResults" class="absolute top-full left-0 right-0 mt-1 mx-3 bg-white dark:bg-[#1e232d] border border-gray-200 dark:border-gray-700 rounded-md shadow-2xl z-50 hidden">
            <div id="mobileSearchResultsList" class="flex flex-col max-h-[300px] overflow-y-auto"></div>
        </div>
    </div>

    <main class="max-w-[1500px] mx-auto px-4 py-6 flex flex-col xl:flex-row gap-6">
        
        <div class="w-full xl:w-[70%] flex flex-col gap-8">
            <?php if (!empty($readingHistory)): ?>
            <section class="animate-fade-in">
                <div class="flex justify-between items-center mb-4 border-b dark:border-gray-800 border-gray-300 pb-2 relative z-[50]">
                    <h2 class="text-xl font-bold dark:text-white text-black tracking-wide border-l-4 border-green-500 pl-2">Continue Reading</h2>
                </div>
                
                <div class="flex overflow-x-auto no-scrollbar scroll-smooth gap-3 sm:gap-4 transition-opacity duration-300 pb-2 snap-x snap-mandatory">
                    <?php foreach ($readingHistory as $hist): ?>
                    <a href="read.php?manga_id=<?= urlencode($hist['manga_id']) ?>&chapter_id=<?= urlencode($hist['chapter_id']) ?>" class="group flex flex-col flex-none w-[calc(50%-6px)] sm:w-[calc(33.333%-10.6px)] md:w-[calc(25%-12px)] lg:w-[calc(20%-12.8px)] snap-start">
                        <div class="relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border dark:border-gray-800 border-gray-300 group-hover:border-green-500/50 transition-colors shadow-lg">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($hist['cover_url'] ?? 'https://via.placeholder.com/300x420') ?>" class="w-full h-full object-contain dark:bg-[#151921] bg-gray-200 group-hover:scale-105 transition-transform duration-300 opacity-90 group-hover:opacity-100">
                            
                            <div class="absolute inset-0 bg-black/40 group-hover:bg-transparent transition-colors flex items-center justify-center">
                                <div class="w-12 h-12 rounded-full bg-black/60 backdrop-blur border-2 border-white flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <svg class="w-6 h-6 text-white ml-1" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4l12 6-12 6z"></path></svg>
                                </div>
                            </div>

                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 pt-12 flex justify-between items-end">
                                <span class="text-[11px] font-black text-white bg-green-600 px-2 py-0.5 rounded shadow-lg">Ch. <?= htmlspecialchars($hist['chapter_num']) ?></span>
                                <span class="text-[10px] text-gray-300 font-medium"><?= timeAgo($hist['read_at']) ?></span>
                            </div>
                        </div>
                        <h3 class="mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-green-500 transition-colors">
                            <?= htmlspecialchars($hist['title']) ?>
                        </h3>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            
            <section>
                <div class="flex justify-between items-center mb-4 border-b dark:border-gray-800 border-gray-300 pb-2 relative z-[40]">
                    <h2 class="text-xl font-bold dark:text-white text-black tracking-wide border-l-4 border-accent pl-2">Most Recent Popular</h2>
                    
                    <div class="flex items-center gap-2">
                        <div class="flex gap-1 hidden sm:flex">
                            <button onclick="slideCarousel('popularGrid', -1)" class="dark:bg-[#1a1f29] bg-gray-200 border dark:border-gray-700 border-gray-300 dark:text-gray-400 text-gray-600 hover:text-accent dark:hover:text-accent p-1.5 rounded transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            <button onclick="slideCarousel('popularGrid', 1)" class="dark:bg-[#1a1f29] bg-gray-200 border dark:border-gray-700 border-gray-300 dark:text-gray-400 text-gray-600 hover:text-accent dark:hover:text-accent p-1.5 rounded transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                        
                        <div class="relative filter-dropdown">
                            <button onclick="toggleFilter('popularDropdown')" class="flex items-center gap-2 text-xs font-bold dark:text-gray-300 text-gray-600 dark:bg-[#1a1f29] bg-gray-200 hover:text-accent dark:hover:text-accent px-3 py-1.5 rounded transition-colors border dark:border-gray-700 border-gray-300">
                                <span id="popularLabel">1 month</span>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div id="popularDropdown" class="absolute right-0 mt-2 w-32 dark:bg-[#151921] bg-white border dark:border-gray-700 border-gray-200 rounded shadow-2xl hidden flex flex-col overflow-hidden">
                                <button onclick="fetchSection('popular', '1d', '1 day')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">1 day</button>
                                <button onclick="fetchSection('popular', '7d', '7 days')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">7 days</button>
                                <button onclick="fetchSection('popular', '1m', '1 month')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors dark:bg-[#1a1f29] bg-gray-100">1 month</button>
                                <button onclick="fetchSection('popular', '3m', '3 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">3 months</button>
                                <button onclick="fetchSection('popular', '6m', '6 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">6 months</button>
                                <button onclick="fetchSection('popular', '1y', '1 year')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">1 year</button>
                                <button onclick="fetchSection('popular', 'all', 'All time')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">All time</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="popularGrid" class="flex overflow-x-auto no-scrollbar scroll-smooth gap-3 sm:gap-4 transition-opacity duration-300 min-h-[200px] snap-x snap-mandatory pb-4">
                    <?php $rank = 1; foreach ($recentPopular as $manga): ?>
                    <a href="manga.php?id=<?= urlencode($manga['manga_id']) ?>" class="group flex flex-col fade-in flex-none w-[calc(50%-6px)] sm:w-[calc(33.333%-10.6px)] md:w-[calc(25%-12px)] lg:w-[calc(20%-12.8px)] snap-start">
                        <div class="relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border dark:border-gray-800 border-gray-300 group-hover:border-accent/50 transition-colors">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($manga['cover_url'] ?? 'https://via.placeholder.com/300x420') ?>" class="w-full h-full object-contain dark:bg-[#151921] bg-gray-200 group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 flex justify-between items-end h-1/2">
                                <span class="text-[10px] font-bold text-accent uppercase bg-black/50 px-1 rounded backdrop-blur"><?= htmlspecialchars(explode(', ', $manga['genres'])[0] ?? 'Comic') ?></span>
                                <span class="text-[10px] text-gray-300 font-medium"><?= timeAgo($manga['last_updated']) ?></span>
                            </div>
                            <div class="absolute top-0 right-0 bg-black/80 text-white font-black text-xl px-2 py-1 rounded-bl-lg backdrop-blur shadow-lg"><?= $rank++ ?></div>
                        </div>
                        <h3 class="mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-accent transition-colors">
                            <?= htmlspecialchars($manga['title']) ?>
                        </h3>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section>
                <div class="flex justify-between items-center mb-4 border-b dark:border-gray-800 border-gray-300 pb-2 relative z-[35]">
                    <h2 class="text-xl font-bold dark:text-white text-black tracking-wide border-l-4 border-[#ff4757] pl-2">Most Follows New Comics</h2>
                    
                    <div class="flex items-center gap-2">
                        <div class="flex gap-1 hidden sm:flex">
                            <button onclick="slideCarousel('followsGrid', -1)" class="dark:bg-[#1a1f29] bg-gray-200 border dark:border-gray-700 border-gray-300 dark:text-gray-400 text-gray-600 hover:text-[#ff4757] dark:hover:text-[#ff4757] p-1.5 rounded transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            <button onclick="slideCarousel('followsGrid', 1)" class="dark:bg-[#1a1f29] bg-gray-200 border dark:border-gray-700 border-gray-300 dark:text-gray-400 text-gray-600 hover:text-[#ff4757] dark:hover:text-[#ff4757] p-1.5 rounded transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                        
                        <div class="relative filter-dropdown">
                            <button onclick="toggleFilter('followsDropdown')" class="flex items-center gap-2 text-xs font-bold dark:text-gray-300 text-gray-600 dark:bg-[#1a1f29] bg-gray-200 hover:text-[#ff4757] dark:hover:text-[#ff4757] px-3 py-1.5 rounded transition-colors border dark:border-gray-700 border-gray-300">
                                <span id="followsLabel">1 month</span>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div id="followsDropdown" class="absolute right-0 mt-2 w-32 dark:bg-[#151921] bg-white border dark:border-gray-700 border-gray-200 rounded shadow-2xl hidden flex flex-col overflow-hidden">
                                <button onclick="fetchSection('follows', '1d', '1 day')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">1 day</button>
                                <button onclick="fetchSection('follows', '7d', '7 days')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">7 days</button>
                                <button onclick="fetchSection('follows', '1m', '1 month')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors dark:bg-[#1a1f29] bg-gray-100">1 month</button>
                                <button onclick="fetchSection('follows', '3m', '3 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">3 months</button>
                                <button onclick="fetchSection('follows', '6m', '6 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">6 months</button>
                                <button onclick="fetchSection('follows', '1y', '1 year')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">1 year</button>
                                <button onclick="fetchSection('follows', 'all', 'All time')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">All time</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="followsGrid" class="flex overflow-x-auto no-scrollbar scroll-smooth gap-3 sm:gap-4 transition-opacity duration-300 min-h-[200px] snap-x snap-mandatory pb-4">
                    <?php $rank = 1; foreach ($mostFollows as $manga): ?>
                    <a href="manga.php?id=<?= urlencode($manga['manga_id']) ?>" class="group flex flex-col fade-in flex-none w-[calc(50%-6px)] sm:w-[calc(33.333%-10.6px)] md:w-[calc(25%-12px)] lg:w-[calc(20%-12.8px)] snap-start">
                        <div class="relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border dark:border-gray-800 border-gray-300 group-hover:border-[#ff4757]/50 transition-colors">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($manga['cover_url'] ?? 'https://via.placeholder.com/300x420') ?>" class="w-full h-full object-contain dark:bg-[#151921] bg-gray-200 group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 flex justify-between items-end h-1/2">
                                <span class="text-[10px] font-bold text-yellow-400">★ <?= number_format($manga['followers']) ?></span>
                                <span class="text-[10px] text-gray-300 font-medium"><?= timeAgo($manga['last_updated']) ?></span>
                            </div>
                            <div class="absolute top-0 right-0 bg-[#ff4757]/90 text-white font-black text-xl px-2 py-1 rounded-bl-lg backdrop-blur shadow-lg"><?= $rank++ ?></div>
                        </div>
                        <h3 class="mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-[#ff4757] transition-colors">
                            <?= htmlspecialchars($manga['title']) ?>
                        </h3>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section>
                <div class="flex justify-between items-center mb-4 border-b dark:border-gray-800 border-gray-300 pb-2 relative z-[30]">
                    <h2 class="text-xl font-bold dark:text-white text-black tracking-wide border-l-4 border-[#a29bfe] pl-2">Latest Updates</h2>
                    
                    <div class="flex items-center gap-1.5 ml-auto">
                        <div class="flex gap-1">
                            <button onclick="fetchLatest('hot')" id="btnLatestHot" class="bg-accent text-black text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.281.436-.506.914-.769 1.37-.253.437-.552.883-.923 1.258-.35.353-.787.646-1.343.822a1 1 0 00-.51 1.545c.42.502.822 1.056 1.135 1.69.308.625.54 1.345.54 2.19 0 .42-.053.815-.145 1.185-.05.2-.105.4-.165.594a1 1 0 001.272 1.27c.18-.052.355-.109.525-.173.342-.13.67-.294.978-.501.62-.416 1.144-.99 1.488-1.745.344-.755.485-1.616.326-2.52a1 1 0 00-.234-.546c-.16-.214-.356-.407-.577-.576-.23-.177-.487-.33-.761-.462-.257-.123-.53-.223-.815-.306-.27-.078-.553-.133-.836-.164a1 1 0 01-.735-1.636c.162-.178.347-.335.548-.466.216-.142.45-.262.698-.356a1 1 0 00.56-.99zM10 18a8 8 0 100-16 8 8 0 000 16z" clip-rule="evenodd"></path></svg>
                                HOT
                            </button>
                            <button onclick="fetchLatest('new')" id="btnLatestNew" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287-.947c.886.54 2.041.062 2.287-.947 1.372.836 2.942-.734 2.106-2.106a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"></path></svg>
                                NEW
                            </button>
                        </div>

                        <div class="flex gap-1">
                            <button onclick="slideCarouselFull('latestGrid', -1)" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white p-1.5 rounded-sm transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            <button onclick="slideCarouselFull('latestGrid', 1)" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white p-1.5 rounded-sm transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                        
                        <div class="relative filter-dropdown">
                            <button onclick="toggleFilter('latestDropdown')" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white p-1.5 rounded-sm transition-colors flex items-center justify-center">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg>
                            </button>
                            <div id="latestDropdown" class="absolute right-0 mt-2 w-32 dark:bg-[#151921] bg-white border dark:border-gray-700 border-gray-200 rounded shadow-2xl hidden flex flex-col overflow-hidden z-50">
                                <button onclick="fetchLatestType('all')" id="btnType-all" class="text-left px-4 py-2 text-sm dark:bg-[#1a1f29] bg-gray-100 dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors border-b dark:border-gray-700 border-gray-200">All</button>
                                <button onclick="fetchLatestType('manga')" id="btnType-manga" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">Manga</button>
                                <button onclick="fetchLatestType('manhwa')" id="btnType-manhwa" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">Manhwa</button>
                                <button onclick="fetchLatestType('manhua')" id="btnType-manhua" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors">Manhua</button>
                                <button onclick="fetchLatestType('other')" id="btnType-other" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-accent hover:text-black font-medium transition-colors border-t dark:border-gray-700 border-gray-200">Other</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="latestGrid" class="flex overflow-x-auto no-scrollbar scroll-smooth gap-3 sm:gap-4 transition-opacity duration-300 min-h-[200px] snap-x snap-mandatory pb-4">
                    <?php foreach ($latestUpdates as $manga): ?>
                    <a href="manga.php?id=<?= urlencode($manga['manga_id']) ?>" class="group flex flex-col fade-in flex-none w-[calc(50%-6px)] sm:w-[calc(33.333%-10.6px)] md:w-[calc(25%-12px)] lg:w-[calc(20%-12.8px)] snap-start">
                        <div class="relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border dark:border-gray-800 border-gray-300 group-hover:border-[#a29bfe]/50 transition-colors">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($manga['cover_url'] ?? 'https://via.placeholder.com/300x420') ?>" class="w-full h-full object-contain dark:bg-[#151921] bg-gray-200 group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 flex justify-between items-end h-1/2">
                                <span class="text-[10px] font-bold text-gray-300 bg-black/50 px-1 rounded backdrop-blur"><?= htmlspecialchars(strtoupper($manga['original_language'] ?? 'EN')) ?></span>
                                <span class="text-[10px] text-gray-300 font-medium"><?= timeAgo($manga['last_updated']) ?></span>
                            </div>
                        </div>
                        <h3 class="mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-[#a29bfe] transition-colors">
                            <?= htmlspecialchars($manga['title']) ?>
                        </h3>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($showErotica): ?>
            <section>
                <div class="flex justify-between items-center mb-4 border-b dark:border-gray-800 border-gray-300 pb-2 relative z-[25]">
                    <h2 class="text-xl font-bold text-[#ff4757] tracking-wide border-l-4 border-[#ff4757] pl-2">Latest Mature (18+)</h2>
                    
                    <div class="flex items-center gap-1.5 ml-auto">
                        <div class="flex gap-1">
                            <button onclick="fetchAdult('erotica', 'hot')" id="btnEroticaHot" class="bg-[#ff4757] text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.281.436-.506.914-.769 1.37-.253.437-.552.883-.923 1.258-.35.353-.787.646-1.343.822a1 1 0 00-.51 1.545c.42.502.822 1.056 1.135 1.69.308.625.54 1.345.54 2.19 0 .42-.053.815-.145 1.185-.05.2-.105.4-.165.594a1 1 0 001.272 1.27c.18-.052.355-.109.525-.173.342-.13.67-.294.978-.501.62-.416 1.144-.99 1.488-1.745.344-.755.485-1.616.326-2.52a1 1 0 00-.234-.546c-.16-.214-.356-.407-.577-.576-.23-.177-.487-.33-.761-.462-.257-.123-.53-.223-.815-.306-.27-.078-.553-.133-.836-.164a1 1 0 01-.735-1.636c.162-.178.347-.335.548-.466.216-.142.45-.262.698-.356a1 1 0 00.56-.99zM10 18a8 8 0 100-16 8 8 0 000 16z" clip-rule="evenodd"></path></svg>
                                HOT
                            </button>
                            <button onclick="fetchAdult('erotica', 'new')" id="btnEroticaNew" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287-.947c.886.54 2.041.062 2.287-.947 1.372.836 2.942-.734 2.106-2.106a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"></path></svg>
                                NEW
                            </button>
                        </div>
                        
                        <div class="flex gap-1">
                            <button onclick="slideCarouselFull('eroticaGrid', -1)" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white p-1.5 rounded-sm transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            <button onclick="slideCarouselFull('eroticaGrid', 1)" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white p-1.5 rounded-sm transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                        
                        <div class="relative filter-dropdown">
                            <button onclick="toggleFilter('eroticaDropdown')" class="flex items-center gap-2 text-xs font-bold dark:text-gray-300 text-gray-600 dark:bg-[#1a1f29] bg-gray-200 hover:text-[#ff4757] dark:hover:text-[#ff4757] px-3 py-1.5 rounded transition-colors border dark:border-gray-700 border-gray-300">
                                <span id="eroticaLabel">1 month</span>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div id="eroticaDropdown" class="absolute right-0 mt-2 w-32 dark:bg-[#151921] bg-white border dark:border-gray-700 border-gray-200 rounded shadow-2xl hidden flex flex-col overflow-hidden">
                                <button onclick="fetchAdultTime('erotica', '1d', '1 day')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">1 day</button>
                                <button onclick="fetchAdultTime('erotica', '7d', '7 days')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">7 days</button>
                                <button onclick="fetchAdultTime('erotica', '1m', '1 month')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors dark:bg-[#1a1f29] bg-gray-100">1 month</button>
                                <button onclick="fetchAdultTime('erotica', '3m', '3 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">3 months</button>
                                <button onclick="fetchAdultTime('erotica', '6m', '6 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">6 months</button>
                                <button onclick="fetchAdultTime('erotica', '1y', '1 year')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">1 year</button>
                                <button onclick="fetchAdultTime('erotica', 'all', 'All time')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">All time</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="eroticaGrid" class="flex overflow-x-auto no-scrollbar scroll-smooth gap-3 sm:gap-4 transition-opacity duration-300 min-h-[200px] snap-x snap-mandatory pb-4">
                    <?php foreach ($eroticaMangas as $manga): ?>
                    <a href="manga.php?id=<?= urlencode($manga['manga_id']) ?>" class="group flex flex-col fade-in flex-none w-[calc(50%-6px)] sm:w-[calc(33.333%-10.6px)] md:w-[calc(25%-12px)] lg:w-[calc(20%-12.8px)] snap-start">
                        <div class="relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border border-gray-800 group-hover:border-[#ff4757]/50 transition-colors">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($manga['cover_url'] ?? 'https://via.placeholder.com/300x420') ?>" class="w-full h-full object-contain dark:bg-[#151921] bg-gray-200 group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 flex justify-between items-end h-1/2">
                                <span class="text-[10px] font-bold text-white bg-[#ff4757]/80 px-1 rounded backdrop-blur">18+</span>
                                <span class="text-[10px] text-gray-300 font-medium"><?= timeAgo($manga['last_updated']) ?></span>
                            </div>
                        </div>
                        <h3 class="mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-[#ff4757] transition-colors">
                            <?= htmlspecialchars($manga['title']) ?>
                        </h3>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($showExplicit): ?>
            <section>
                <div class="flex justify-between items-center mb-4 border-b dark:border-gray-800 border-gray-300 pb-2 relative z-[20]">
                    <h2 class="text-xl font-bold text-[#ff4757] tracking-wide border-l-4 border-[#ff4757] pl-2">Latest Explicit (18+)</h2>
                    
                    <div class="flex items-center gap-1.5 ml-auto">
                        <div class="flex gap-1">
                            <button onclick="fetchAdult('explicit', 'hot')" id="btnExplicitHot" class="bg-[#ff4757] text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.281.436-.506.914-.769 1.37-.253.437-.552.883-.923 1.258-.35.353-.787.646-1.343.822a1 1 0 00-.51 1.545c.42.502.822 1.056 1.135 1.69.308.625.54 1.345.54 2.19 0 .42-.053.815-.145 1.185-.05.2-.105.4-.165.594a1 1 0 001.272 1.27c.18-.052.355-.109.525-.173.342-.13.67-.294.978-.501.62-.416 1.144-.99 1.488-1.745.344-.755.485-1.616.326-2.52a1 1 0 00-.234-.546c-.16-.214-.356-.407-.577-.576-.23-.177-.487-.33-.761-.462-.257-.123-.53-.223-.815-.306-.27-.078-.553-.133-.836-.164a1 1 0 01-.735-1.636c.162-.178.347-.335.548-.466.216-.142.45-.262.698-.356a1 1 0 00.56-.99zM10 18a8 8 0 100-16 8 8 0 000 16z" clip-rule="evenodd"></path></svg>
                                HOT
                            </button>
                            <button onclick="fetchAdult('explicit', 'new')" id="btnExplicitNew" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287-.947c.886.54 2.041.062 2.287-.947 1.372.836 2.942-.734 2.106-2.106a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"></path></svg>
                                NEW
                            </button>
                        </div>
                        
                        <div class="flex gap-1">
                            <button onclick="slideCarouselFull('explicitGrid', -1)" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white p-1.5 rounded-sm transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            <button onclick="slideCarouselFull('explicitGrid', 1)" class="dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white p-1.5 rounded-sm transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                        
                        <div class="relative filter-dropdown">
                            <button onclick="toggleFilter('explicitDropdown')" class="flex items-center gap-2 text-xs font-bold dark:text-gray-300 text-gray-600 dark:bg-[#1a1f29] bg-gray-200 hover:text-[#ff4757] dark:hover:text-[#ff4757] px-3 py-1.5 rounded transition-colors border dark:border-gray-700 border-gray-300">
                                <span id="explicitLabel">1 month</span>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div id="explicitDropdown" class="absolute right-0 mt-2 w-32 dark:bg-[#151921] bg-white border dark:border-gray-700 border-gray-200 rounded shadow-2xl hidden flex flex-col overflow-hidden">
                                <button onclick="fetchAdultTime('explicit', '1d', '1 day')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">1 day</button>
                                <button onclick="fetchAdultTime('explicit', '7d', '7 days')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">7 days</button>
                                <button onclick="fetchAdultTime('explicit', '1m', '1 month')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors dark:bg-[#1a1f29] bg-gray-100">1 month</button>
                                <button onclick="fetchAdultTime('explicit', '3m', '3 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">3 months</button>
                                <button onclick="fetchAdultTime('explicit', '6m', '6 months')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">6 months</button>
                                <button onclick="fetchAdultTime('explicit', '1y', '1 year')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">1 year</button>
                                <button onclick="fetchAdultTime('explicit', 'all', 'All time')" class="text-left px-4 py-2 text-sm dark:text-gray-300 text-gray-700 hover:bg-[#ff4757] hover:text-white font-medium transition-colors">All time</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="explicitGrid" class="flex overflow-x-auto no-scrollbar scroll-smooth gap-3 sm:gap-4 transition-opacity duration-300 min-h-[200px] snap-x snap-mandatory pb-4">
                    <?php foreach ($explicitMangas as $manga): ?>
                    <a href="manga.php?id=<?= urlencode($manga['manga_id']) ?>" class="group flex flex-col fade-in flex-none w-[calc(50%-6px)] sm:w-[calc(33.333%-10.6px)] md:w-[calc(25%-12px)] lg:w-[calc(20%-12.8px)] snap-start">
                        <div class="relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border border-gray-800 group-hover:border-[#ff4757]/50 transition-colors">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($manga['cover_url'] ?? 'https://via.placeholder.com/300x420') ?>" class="w-full h-full object-contain dark:bg-[#151921] bg-gray-200 group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 flex justify-between items-end h-1/2">
                                <span class="text-[10px] font-bold text-white bg-[#ff4757]/80 px-1 rounded backdrop-blur">18+</span>
                                <span class="text-[10px] text-gray-300 font-medium"><?= timeAgo($manga['last_updated']) ?></span>
                            </div>
                        </div>
                        <h3 class="mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-[#ff4757] transition-colors">
                            <?= htmlspecialchars($manga['title']) ?>
                        </h3>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

        </div>

        <aside class="w-full xl:w-[30%]">
            <div class="dark:bg-surface bg-white rounded-md border dark:border-gray-800 border-gray-300 pb-2 sticky top-20 shadow-sm transition-colors">
                <div class="p-4 border-b dark:border-gray-800 border-gray-200 flex items-center gap-2">
                    <h2 class="dark:text-white text-black font-bold tracking-wide">Discovery</h2>
                </div>
                <div class="flex flex-col">
                    <?php foreach ($sidebarMangas as $manga): ?>
                    <a href="manga.php?id=<?= urlencode($manga['manga_id']) ?>" class="flex gap-3 p-3 dark:hover:bg-card hover:bg-gray-50 transition-colors border-b dark:border-gray-800/50 border-gray-100 last:border-0 group">
                        <div class="w-14 h-20 flex-shrink-0 rounded bg-gray-800 overflow-hidden border border-gray-700">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($manga['cover_url'] ?? 'https://via.placeholder.com/80x110') ?>" class="w-full h-full object-cover">
                        </div>
                        <div class="flex flex-col justify-center flex-grow overflow-hidden">
                            <span class="text-[9px] uppercase tracking-widest text-accent font-bold mb-0.5"><?= htmlspecialchars(explode(', ', $manga['genres'])[0] ?? 'Comic') ?></span>
                            <h3 class="text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-accent transition-colors">
                                <?= htmlspecialchars($manga['title']) ?>
                            </h3>
                            <div class="flex gap-4 mt-2">
                                <span class="text-[10px] font-bold text-yellow-500">★ <?= number_format($manga['followers']) ?></span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>
    </main>

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
                        <label>Website (Leave Blank)</label>
                        <input type="text" id="regHoneypot" autocomplete="off" tabindex="-1">
                        <input type="hidden" id="regTime" value="<?= time() ?>">
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
        // --- Theme & Nav ---
        const html = document.documentElement; const btnLight = document.getElementById('themeLight'); const btnDark = document.getElementById('themeDark');
        function updateThemeButtons() {
            if (html.classList.contains('dark')) { btnDark.classList.add('bg-accent', 'text-black'); btnDark.classList.remove('text-gray-400'); btnLight.classList.remove('bg-accent', 'text-black'); btnLight.classList.add('dark:text-gray-400', 'text-gray-600'); }
            else { btnLight.classList.add('bg-accent', 'text-black'); btnLight.classList.remove('text-gray-400', 'text-gray-600'); btnDark.classList.remove('bg-accent', 'text-black'); btnDark.classList.add('text-gray-600'); }
        }
        if(btnLight && btnDark) { updateThemeButtons(); btnLight.addEventListener('click', () => { html.classList.remove('dark'); localStorage.setItem('theme', 'light'); updateThemeButtons(); }); btnDark.addEventListener('click', () => { html.classList.add('dark'); localStorage.setItem('theme', 'dark'); updateThemeButtons(); }); }

        // --- Mobile Search Toggle (FIXED) ---
        const mobileBtn = document.getElementById('mobileSearchToggle'); 
        const mobileTray = document.getElementById('mobileSearchTray'); 
        const mobileInput = document.getElementById('mobileSearchInput');
        
        if (mobileBtn && mobileTray && mobileInput) {
            mobileBtn.addEventListener('click', (e) => { 
                e.stopPropagation(); 
                mobileTray.classList.toggle('hidden'); 
                if (!mobileTray.classList.contains('hidden')) {
                    mobileInput.focus(); 
                }
            });
        }

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
        setupSearch('mobileSearchInput', 'mobileSearchResults', 'mobileSearchResultsList');

        // --- Custom Carousel Scrollers ---
        function slideCarousel(gridId, direction) {
            const grid = document.getElementById(gridId);
            const item = grid.firstElementChild;
            if (!item) return;
            const gap = parseFloat(window.getComputedStyle(grid).gap) || 0;
            const scrollAmount = (item.offsetWidth + gap) * 2;
            grid.scrollBy({ left: scrollAmount * direction, behavior: 'smooth' });
        }
        function slideCarouselFull(gridId, direction) {
            const grid = document.getElementById(gridId);
            const scrollAmount = grid.clientWidth;
            grid.scrollBy({ left: scrollAmount * direction, behavior: 'smooth' });
        }

        function toggleFilter(dropdownId) {
            document.querySelectorAll('.filter-dropdown > div').forEach(el => { if(el.id !== dropdownId) el.classList.add('hidden'); });
            document.getElementById(dropdownId).classList.toggle('hidden');
        }

        // --- Standard Sections ---
        function fetchSection(sectionName, timeParam, labelText) {
            document.getElementById(sectionName + 'Label').innerText = labelText;
            document.getElementById(sectionName + 'Dropdown').classList.add('hidden');
            const grid = document.getElementById(sectionName + 'Grid');
            grid.style.opacity = '0.3';
            fetch(`api.php?action=load_section&section=${sectionName}&time=${timeParam}`).then(response => response.text()).then(html => {
                grid.innerHTML = html; grid.style.opacity = '1'; grid.scrollTo({left: 0});
            }).catch(err => { grid.style.opacity = '1'; });
        }

        // --- Latest Updates Sections ---
        let currentLatestTab = 'hot';
        let currentLatestType = 'all';
        function fetchLatest(tab) {
            currentLatestTab = tab;
            const btnHot = document.getElementById('btnLatestHot');
            const btnNew = document.getElementById('btnLatestNew');
            if(tab === 'hot') {
                btnHot.className = "bg-accent text-black text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
                btnNew.className = "dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
            } else {
                btnNew.className = "bg-accent text-black text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
                btnHot.className = "dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
            }
            updateLatestGrid();
        }
        function fetchLatestType(type) {
            currentLatestType = type;
            ['all','manga','manhwa','manhua','other'].forEach(t => {
                const btn = document.getElementById('btnType-' + t);
                if(t === type) { btn.classList.add('dark:bg-[#1a1f29]', 'bg-gray-100'); }
                else { btn.classList.remove('dark:bg-[#1a1f29]', 'bg-gray-100'); }
            });
            document.getElementById('latestDropdown').classList.add('hidden');
            updateLatestGrid();
        }
        function updateLatestGrid() {
            const grid = document.getElementById('latestGrid');
            grid.style.opacity = '0.3';
            fetch('api.php?action=load_section&section=latest&tab=${currentLatestTab}&type=${currentLatestType}').then(res => res.text()).then(html => {
                grid.innerHTML = html; grid.style.opacity = '1'; grid.scrollTo({left: 0});
            });
        }

        // --- Adult Sections Logic ---
        const adultState = {
            erotica: { tab: 'hot', time: '1m' },
            explicit: { tab: 'hot', time: '1m' }
        };

        function fetchAdult(section, tab) {
            adultState[section].tab = tab;
            let sectionPrefix = section.charAt(0).toUpperCase() + section.slice(1);
            const btnHot = document.getElementById('btn' + sectionPrefix + 'Hot');
            const btnNew = document.getElementById('btn' + sectionPrefix + 'New');

            if(tab === 'hot') {
                btnHot.className = "bg-[#ff4757] text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
                btnNew.className = "dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
            } else {
                btnNew.className = "bg-[#ff4757] text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
                btnHot.className = "dark:bg-[#2d333b] bg-gray-200 dark:text-gray-400 text-gray-600 hover:text-black dark:hover:text-white text-xs font-bold px-3 py-1.5 rounded-sm flex items-center gap-1 transition-colors";
            }
            updateAdultGrid(section);
        }

        function fetchAdultTime(section, timeParam, labelText) {
            adultState[section].time = timeParam;
            document.getElementById(section + 'Label').innerText = labelText;
            document.getElementById(section + 'Dropdown').classList.add('hidden');
            updateAdultGrid(section);
        }

        function updateAdultGrid(section) {
            const grid = document.getElementById(section + 'Grid');
            grid.style.opacity = '0.3';
            fetch(`api.php?action=load_section&section=${section}&tab=${adultState[section].tab}&time=${adultState[section].time}`)
            .then(res => res.text()).then(html => {
                grid.innerHTML = html; grid.style.opacity = '1'; grid.scrollTo({left: 0});
            });
        }

        // --- Auth Logic (FIXED with safety checks) ---
        const authModal = document.getElementById('authModal'); const loginForm = document.getElementById('loginForm'); const registerForm = document.getElementById('registerForm'); const tabLogin = document.getElementById('tabLogin'); const tabRegister = document.getElementById('tabRegister'); const authError = document.getElementById('authError');
        function openAuthModal(tab = 'login') { authModal.classList.remove('hidden'); switchAuthTab(tab); } 
        function closeAuthModal() { authModal.classList.add('hidden'); authError.classList.add('hidden'); }
        function switchAuthTab(tab) {
            authError.classList.add('hidden');
            if (tab === 'login') { loginForm.classList.remove('hidden'); registerForm.classList.add('hidden'); tabLogin.classList.add('text-accent', 'border-b-2', 'border-accent'); tabLogin.classList.remove('text-gray-500'); tabRegister.classList.remove('text-accent', 'border-b-2', 'border-accent'); tabRegister.classList.add('text-gray-500'); }
            else { loginForm.classList.add('hidden'); registerForm.classList.remove('hidden'); tabRegister.classList.add('text-accent', 'border-b-2', 'border-accent'); tabRegister.classList.remove('text-gray-500'); tabLogin.classList.remove('text-accent', 'border-b-2', 'border-accent'); tabLogin.classList.add('text-gray-500'); }
        }
        function handleAuth(event, action) {
            event.preventDefault(); authError.classList.add('hidden'); let payload = { action: action };
            if (action === 'login') {
                payload.login_id = document.getElementById('loginId').value;
                payload.password = document.getElementById('loginPassword').value;
            } else {
                payload.username = document.getElementById('regUsername').value;
                payload.email = document.getElementById('regEmail').value;
                payload.password = document.getElementById('regPassword').value;

                // Safely grab the Anti-Bot Traps
                const honeypot = document.getElementById('regHoneypot');
                const loadTime = document.getElementById('regTime');
                if (honeypot) payload.honeypot = honeypot.value;
                if (loadTime) payload.load_time = loadTime.value;
            }

            fetch('auth_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(res => res.json()).then(data => {
                if (data.success) { window.location.href = window.location.pathname + '?v=' + new Date().getTime(); } 
                else { authError.innerText = data.message; authError.classList.remove('hidden'); }
            }).catch(err => {
                authError.innerText = "Connection error. Try again."; authError.classList.remove('hidden');
            });
        }
        function logoutUser() { fetch('auth_api.php', { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:'logout'}) }).then(() => window.location.href = window.location.pathname + '?v=' + new Date().getTime()); }

        // --- Global Clickaway Logic (FIXED for mobile tray) ---
        document.addEventListener('click', (e) => {
            const searchResults = document.getElementById('searchResults'); const mobileSearchResults = document.getElementById('mobileSearchResults'); const mobileTray = document.getElementById('mobileSearchTray');
            if (!e.target.closest('#searchInput') && !e.target.closest('#searchResults')) { if(searchResults) searchResults.classList.add('hidden'); }
            
            // The fix: including #mobileSearchTray below so typing doesn't close it
            if (!e.target.closest('#mobileSearchTray') && !e.target.closest('#mobileSearchToggle')) { 
                if(mobileSearchResults) mobileSearchResults.classList.add('hidden'); 
                if(mobileTray) mobileTray.classList.add('hidden'); 
            }
            
            if (!e.target.closest('.filter-dropdown')) { document.querySelectorAll('.filter-dropdown > div').forEach(el => el.classList.add('hidden')); }
        });
    </script>
</body>
</html>
