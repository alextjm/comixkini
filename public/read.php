<?php
// public/read.php
// 1. Define a private folder for ComixKini sessions
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
require_once __DIR__ . '/../src/ApiHelper.php';
require_once __DIR__ . '/../src/Services/MangaService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/ChapterFeedService.php';
require_once __DIR__ . '/../src/Services/ReaderService.php';

$mangaId = $_GET['manga_id'] ?? null;
$requestedChapterId = $_GET['chapter_id'] ?? null;

if (!$mangaId || !$requestedChapterId) die("Error: Missing routing parameters.");

// 1. Initialize Services
$authService = new AuthService($pdo);
$mangaService = new MangaService($pdo);
$feedService = new ChapterFeedService();
$readerService = new ReaderService($pdo);

// 2. Concurrency & VIP Checks
$userId = $_SESSION['user_id'] ?? null;
$isVIP = $authService->isVip($userId);

$userOwnsThisTitle = false;
if (!$isVIP && $userId) {
    $stmtCheck = $pdo->prepare("SELECT 1 FROM cp_user_titles WHERE user_id = ? AND manga_id = ? AND expires_at > NOW()");
    $stmtCheck->execute([$userId, $mangaId]);
    $userOwnsThisTitle = (bool)$stmtCheck->fetchColumn();
}

$hasHdAccess = $isVIP || $userOwnsThisTitle;

if ($userId && !$authService->verifySessionConcurrency($userId, $_SESSION['session_token'] ?? '')) {
    session_destroy();
    echo "<script>alert('Your account was logged into from another device. You have been disconnected.'); window.location.href = 'index.php';</script>";
    exit;
}

// 3. Fetch Master Metadata
$manga = $mangaService->getMangaById($mangaId);
$mangaTitle = $manga['title'] ?? 'Unknown Manga';

// 4. Fetch the Chapter Feed for the Dropdown & Navigation
// We fetch a high limit (500) to populate the navigation array
$allowedRatings = isset($_SESSION['content_filters']) ? explode(',', $_SESSION['content_filters']) : ['safe', 'suggestive'];
$feedData = $feedService->getFeed($manga, 1, 5000, $allowedRatings);
$chapters = $feedData['chapters'];

// 5. Establish Context (Where are we?)
$context = $readerService->getChapterContext($chapters, $requestedChapterId);
$chapterNum = $context['chapterNum'];
$prevChapterId = $context['prevChapterId'];
$nextChapterId = $context['nextChapterId'];

// 6. Paywall & Logging
$isLocked = $readerService->isChapterLocked($chapterNum, $isVIP, $_SESSION['user_id'] ?? null, $mangaId);
if (!$isLocked) {
    $readerService->logActivity($userId, $mangaId, $requestedChapterId, $chapterNum);
}

// 7. Fetch Images
$pages = [];
$serverError = false;

if (!$isLocked) {
    // Determine if it is a standard MangaDex UUID
    $isExternal = !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestedChapterId);
    $providerString = 'mangadex'; // Default

    if ($isExternal) {
        // Pass the entire comma-separated string to the ReaderService so it can Waterfall
        $providerString = $manga['consumet_id'] ?? 'weebcentral|' . $requestedChapterId;
    }
    
    // Pass the provider string AND the chapterNum
    $fetchResult = $readerService->fetchPages($providerString, $requestedChapterId, $chapterNum, $hasHdAccess);

    if ($fetchResult['success']) {
        $pages = $fetchResult['pages'];
    } else {
        $serverError = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ch. <?= htmlspecialchars($chapterNum) ?> - <?= htmlspecialchars($mangaTitle) ?></title>
    <meta name="referrer" content="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #0d1015; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #0d1015; }
        ::-webkit-scrollbar-thumb { background: #2d3748; border-radius: 4px; }
    </style>
</head>
<body class="text-gray-300 font-sans antialiased min-h-screen flex flex-col relative">

    <nav class="bg-[#151921] border-b border-gray-800 sticky top-0 z-50 shadow-2xl">
        <div class="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
            
            <div class="flex items-center flex-shrink-0 min-w-0">
                <a href="manga.php?id=<?= urlencode($mangaId) ?>" class="flex items-center group mr-3" title="Back to Manga Details">
                    <svg class="w-7 h-7 sm:w-8 sm:h-8 text-accent group-hover:-translate-x-1 transition-transform" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" viewBox="0 0 24 24">
                        <path d="M21 11.5C21 16.75 16.97 21 12 21c-1.66 0-3.21-.42-4.55-1.16L3 21l1.5-4.2C3.55 15.35 3 13.5 3 11.5 3 6.25 7.03 2 12 2s9 4.25 9 9.5z M12 6 l1.5 4 h4 l-3.2 2.5 l1.2 4 l-3.5 -2.6 l-3.5 2.6 l1.2 -4 l-3.2 -2.5 h4 z"/>
                    </svg>
                </a>
                <div class="flex flex-col truncate">
                    <span class="text-sm font-bold text-white truncate"><?= htmlspecialchars($mangaTitle) ?></span>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest font-black">Ch. <?= htmlspecialchars($chapterNum) ?></span>
                </div>
            </div>

            <div class="flex-grow max-w-xs hidden sm:block">
                <?php if (!empty($chapters)): ?>
                    <select class="bg-[#1a1f29] border border-gray-700 text-sm rounded px-3 py-1.5 w-full text-gray-200 focus:outline-none focus:border-[#26c6da]" onchange="window.location.href=this.value">
                        <?php foreach ($chapters as $chap): ?>
                            <?php 
                                $cNum = $chap['attributes']['chapter'] ?? 'Oneshot';
                                $cUrl = "read.php?manga_id=" . urlencode($mangaId) . "&chapter_id=" . urlencode($chap['id']);
                                $selected = ($chap['id'] === $requestedChapterId) ? 'selected' : '';
                                
                                $dropIsFree = ($cNum === 'Oneshot' || floatval($cNum) < 2);
                                $dropLabel = (!$isVIP && !$dropIsFree && !$userOwnsThisTitle) ? "Chapter $cNum (VIP)" : "Chapter $cNum";
                            ?>
                            <option value="<?= $cUrl ?>" <?= $selected ?>><?= htmlspecialchars($dropLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="flex items-center space-x-2 flex-shrink-0">
                <?php if (!$hasHdAccess): ?>
                    <a href="subscription.php" class="hidden sm:flex mr-4 bg-gradient-to-r from-yellow-500 to-yellow-600 text-black text-xs font-black px-3 py-1.5 rounded items-center shadow-lg hover:scale-105 transition-transform">
                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                        GET HD
                    </a>
                <?php endif; ?>

                <?php if ($prevChapterId): ?>
                    <a href="read.php?manga_id=<?= urlencode($mangaId) ?>&chapter_id=<?= urlencode($prevChapterId) ?>" class="bg-[#1a1f29] hover:bg-gray-700 border border-gray-700 text-white px-3 py-1.5 rounded transition-colors text-sm font-bold">&lsaquo; Prev</a>
                <?php else: ?>
                    <span class="bg-gray-900 border border-gray-800 text-gray-600 px-3 py-1.5 rounded text-sm font-bold cursor-not-allowed">&lsaquo; Prev</span>
                <?php endif; ?>

                <?php if ($nextChapterId): ?>
                    <a href="read.php?manga_id=<?= urlencode($mangaId) ?>&chapter_id=<?= urlencode($nextChapterId) ?>" class="bg-[#26c6da] hover:bg-cyan-400 text-black px-3 py-1.5 rounded transition-colors text-sm font-bold">Next &rsaquo;</a>
                <?php else: ?>
                    <span class="bg-gray-900 border border-gray-800 text-gray-600 px-3 py-1.5 rounded text-sm font-bold cursor-not-allowed">Next &rsaquo;</span>
                <?php endif; ?>
            </div>

        </div>
    </nav>

    <main class="flex-grow w-full mx-auto flex flex-col items-center justify-center bg-[#0a0c10]">
        
        <?php if ($isLocked): ?>
            
            <div class="w-full max-w-2xl px-4 py-20 text-center">
                <div class="bg-[#151921] border border-gray-800 rounded-2xl p-10 shadow-2xl relative overflow-hidden">
                    <div class="absolute top-0 inset-x-0 h-1 bg-gradient-to-r from-yellow-400 to-yellow-600"></div>
                    <svg class="w-20 h-20 mx-auto text-yellow-500 mb-6" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path></svg>
                    
                    <h2 class="text-3xl font-black text-white mb-4">ComixKini VIP Required</h2>
                    <p class="text-gray-400 mb-8 leading-relaxed">Chapters 2 and above are reserved for our VIP subscribers. Upgrade your account today to unlock unlimited reading and crystal clear HD artwork!</p>
                    
                    <div class="flex flex-col sm:flex-row justify-center gap-4">
                        <a href="subscription.php" class="bg-gradient-to-r from-yellow-500 to-yellow-600 hover:scale-105 transition-transform text-black font-black px-8 py-4 rounded-lg shadow-lg">
                            UPGRADE TO VIP
                        </a>
                        <a href="manga.php?id=<?= urlencode($mangaId) ?>" class="bg-[#1a1f29] hover:bg-gray-800 border border-gray-700 text-white font-bold px-8 py-4 rounded-lg transition-colors">
                            Back to Chapter List
                        </a>
                    </div>
                </div>
            </div>

        <?php elseif ($serverError): ?>
            <div class="text-center p-6 bg-red-900/20 border border-red-900 rounded-md my-20 max-w-[800px] mx-auto">
                <p class="text-red-500 font-black uppercase tracking-widest mb-2">Image Network Error</p>
                <p class="text-sm text-gray-400">The server failed to return image URLs. Please refresh the page.</p>
            </div>
            
        <?php else: ?>

            <div class="w-full max-w-[800px] mx-auto py-6">
                
                <?php if (!$hasHdAccess): ?>
                    <div class="w-full h-[90px] bg-[#1a1f29] border border-gray-800 mb-6 flex flex-col items-center justify-center text-gray-600 text-sm font-bold rounded">
                        <span>Advertisement Space</span>
                        <a href="subscription.php" class="text-accent text-xs font-normal mt-1 hover:underline">Remove ads with ComixKini</a>
                    </div>
                <?php endif; ?>

                <?php foreach ($pages as $index => $rawUrl): ?>
                    <?php 
                        // Is it a hostile site that requires proxying natively?
                        $isHostile = (strpos($rawUrl, 'comick') !== false || strpos($rawUrl, 'mangapill') !== false);
                        
                        // Is it a MangaDex At-Home Node?
                        $isMangaDexNode = (strpos($rawUrl, 'mangadex') !== false);
                        
                        // Default load strategy
                        $imgSrc = $isMangaDexNode ? $rawUrl : "image_proxy.php?url=" . urlencode($rawUrl);
                    ?>
                    <div class="w-full flex justify-center bg-[#151921] relative leading-none">
                        <img 
                            src="<?= htmlspecialchars($imgSrc) ?>" 
                            referrerpolicy="no-referrer"
                            loading="lazy" 
                            class="w-full h-auto block m-0 p-0 align-bottom"
                            alt="Page <?= $index + 1 ?>"
                            <?php if ($isMangaDexNode): ?>
                            onerror="if(!this.dataset.retried){ 
                                this.dataset.retried = true; 
                                let match = this.src.match(/\/(data|data-saver)\/.*/);
                                if(match){
                                    let fallbackOrigin = 'https://uploads.mangadex.org' + match[0];
                                    this.src = 'image_proxy.php?url=' + encodeURIComponent(fallbackOrigin);
                                }
                            }"
                            <?php endif; ?>
                        >
                    </div>
                <?php endforeach; ?>

                <?php if (!$hasHdAccess): ?>
                    <div class="w-full h-[90px] bg-[#1a1f29] border border-gray-800 mt-6 flex items-center justify-center text-gray-600 text-sm font-bold rounded">
                        Advertisement Space
                    </div>
                <?php endif; ?>

            </div>

            <div class="w-full py-16 flex flex-col items-center mt-8 border-t border-gray-800/50">
                <?php if (!$hasHdAccess): ?>
                    <p class="text-gray-500 text-xs mb-4 uppercase tracking-widest font-bold">Reading in Standard Mode</p>
                <?php else: ?>
                    <p class="text-[#26c6da] text-xs mb-4 uppercase tracking-widest font-bold">★ Reading in Ultra-HD Premium</p>
                <?php endif; ?>

                <?php if ($nextChapterId): ?>
                    <?php 
                        $nextChapIndex = $currentIndex + 1;
                        $nextChapNum = $chapters[$nextChapIndex]['attributes']['chapter'] ?? 'Oneshot';
                        $nextIsLocked = (!$isVIP && !$userOwnsThisTitle && !($nextChapNum === 'Oneshot' || floatval($nextChapNum) < 2));
                    ?>
                    
                    <?php if ($nextIsLocked): ?>
                        <a href="subscription.php" class="bg-gradient-to-r from-yellow-500 to-yellow-600 hover:scale-105 text-black font-black px-10 py-4 rounded shadow-lg transition-transform uppercase tracking-widest flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path></svg>
                            Unlock Next Chapter
                        </a>
                    <?php else: ?>
                        <a href="read.php?manga_id=<?= $mangaId ?>&chapter_id=<?= $nextChapterId ?>" class="bg-[#26c6da] hover:bg-cyan-400 text-black font-black px-10 py-4 rounded shadow-lg transition-colors uppercase tracking-widest">
                            Read Next Chapter
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-gray-500 font-bold text-sm uppercase tracking-widest border border-gray-800 px-10 py-4 rounded">End of Available Chapters</span>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </main>
    <script>
        document.addEventListener('keydown', function(event) {
            // Define the URLs dynamically using PHP. If the ID is null, leave it empty.
            const prevUrl = "<?= $prevChapterId ? 'read.php?manga_id=' . urlencode($mangaId) . '&chapter_id=' . urlencode($prevChapterId) : '' ?>";
            const nextUrl = "<?= $nextChapterId ? 'read.php?manga_id=' . urlencode($mangaId) . '&chapter_id=' . urlencode($nextChapterId) : '' ?>";

            // If Left Arrow is pressed and there is a previous chapter
            if (event.key === 'ArrowLeft' && prevUrl !== '') {
                window.location.href = prevUrl;
            }
            
            // If Right Arrow is pressed and there is a next chapter
            if (event.key === 'ArrowRight' && nextUrl !== '') {
                window.location.href = nextUrl;
            }
        });
    </script>
</body>
</html>
