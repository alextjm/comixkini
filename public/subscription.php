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

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Fetch user data to check subscription
$stmt = $pdo->prepare("SELECT username, email, subscription_ends_at FROM cp_users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$isActive = false;
// Fetch user's individual purchased titles
$stmtTitles = $pdo->prepare("
    SELECT t.manga_id, t.title, t.cover_url, ut.expires_at 
    FROM cp_user_titles ut
    JOIN cp_titles t ON ut.manga_id = t.manga_id
    WHERE ut.user_id = ? AND ut.expires_at > NOW()
    ORDER BY ut.expires_at ASC
");
$stmtTitles->execute([$_SESSION['user_id']]);
$ownedTitles = $stmtTitles->fetchAll(PDO::FETCH_ASSOC);
$daysRemaining = 0;
$expiryText = "Free Tier";

if ($user['subscription_ends_at']) {
    $expiryDate = new DateTime($user['subscription_ends_at']);
    $now = new DateTime();
    
    if ($expiryDate > $now) {
        $isActive = true;
        $daysRemaining = $now->diff($expiryDate)->days;
        $expiryText = $expiryDate->format('F j, Y');
    } else {
        $expiryText = "Expired on " . $expiryDate->format('M j, Y');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My ComixPass - Subscription</title>
    <meta name="referrer" content="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) { document.documentElement.classList.add('dark'); } 
        else { document.documentElement.classList.remove('dark'); }
        tailwind.config = { darkMode: 'class', theme: { extend: { colors: { background: '#0d1015', surface: '#151921', card: '#1a1f29', accent: '#26c6da' } } } }
    </script>
    <style>
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2d3748; border-radius: 4px; }
    </style>
</head>
<body class="dark:bg-background bg-gray-100 dark:text-gray-300 text-gray-800 font-sans antialiased pb-10 transition-colors duration-300">

    <nav class="dark:bg-surface bg-white border-b dark:border-gray-800 border-gray-200 sticky top-0 z-50 transition-colors duration-300">
        <div class="max-w-[1500px] mx-auto px-4 h-16 flex items-center justify-between gap-2 sm:gap-4">
            
            <a href="index.php" class="flex items-center space-x-2 flex-shrink-0 group">
                <svg class="w-7 h-7 sm:w-8 sm:h-8 text-accent group-hover:scale-110 transition-transform" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" viewBox="0 0 24 24">
                    <path d="M21 11.5C21 16.75 16.97 21 12 21c-1.66 0-3.21-.42-4.55-1.16L3 21l1.5-4.2C3.55 15.35 3 13.5 3 11.5 3 6.25 7.03 2 12 2s9 4.25 9 9.5z M12 6 l1.5 4 h4 l-3.2 2.5 l1.2 4 l-3.5 -2.6 l-3.5 2.6 l1.2 -4 l-3.2 -2.5 h4 z"/>
                </svg>
                <span class="text-lg sm:text-xl font-bold tracking-wider dark:text-white text-black">COMIXPASS</span>
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
                            <a href="subscription.php" class="block px-4 py-3 sm:py-2 text-sm dark:text-gray-300 text-gray-700 dark:hover:bg-[#262c38] hover:bg-gray-100 hover:text-accent border-b dark:border-gray-700 border-gray-200">ComixPass Status</a>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <a href="admin.php" class="block px-4 py-3 sm:py-2 text-sm text-accent font-bold dark:hover:bg-[#262c38] hover:bg-gray-100 border-b dark:border-gray-700 border-gray-200 flex justify-between items-center">
                                    Admin Panel
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                </a>
                            <?php endif; ?>
                            <button onclick="logoutUser()" class="w-full text-left px-4 py-3 sm:py-2 text-sm text-red-500 hover:bg-red-500/10 font-bold">Logout</button>
                        </div>
		    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="mobileSearchTray" class="hidden md:hidden w-full dark:bg-surface bg-white border-b dark:border-gray-800 border-gray-200 p-3 shadow-lg absolute left-0 top-16 z-60">
            <div class="relative w-full flex items-center dark:bg-[#1e232d] bg-gray-100 rounded border dark:border-gray-700 border-gray-300 focus-within:border-gray-500 transition-colors">
                <div class="pl-3 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
                <input type="text" id="mobileSearchInput" autocomplete="off" placeholder="Search comic..." class="bg-transparent text-sm py-2.5 pl-3 w-full focus:outline-none dark:text-gray-200 text-black">
            </div>
            <div id="mobileSearchResults" class="mt-2 w-full dark:bg-[#1e232d] bg-white border dark:border-gray-700 border-gray-200 rounded-md shadow-2xl hidden">
                <div id="mobileSearchResultsList" class="flex flex-col max-h-[300px] overflow-y-auto"></div>
            </div>
        </div>
    </nav>

    <main class="max-w-3xl mx-auto px-4 py-12">
        <h1 class="text-3xl font-black dark:text-white text-black tracking-wide mb-8">Subscription Status</h1>

        <div class="dark:bg-surface bg-white border <?= $isActive ? 'dark:border-accent border-accent shadow-accent/20' : 'dark:border-gray-700 border-gray-300' ?> border-2 rounded-xl p-8 mb-8 shadow-2xl relative overflow-hidden transition-colors">
            <?php if ($isActive): ?>
                <div class="absolute top-0 right-0 bg-accent text-black font-black text-xs px-4 py-1 rounded-bl-lg uppercase tracking-widest">Active VIP</div>
            <?php endif; ?>
            
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                <div>
                    <p class="text-sm dark:text-gray-400 text-gray-500 font-bold mb-1">Account</p>
                    <p class="text-xl dark:text-white text-black font-bold"><?= htmlspecialchars($user['username']) ?></p>
                    <p class="text-xs dark:text-gray-500 text-gray-500"><?= htmlspecialchars($user['email']) ?></p>
                </div>
                <div class="md:text-right text-center">
                    <p class="text-sm dark:text-gray-400 text-gray-500 font-bold mb-1">Valid Until</p>
                    <p class="<?= $isActive ? 'text-accent' : 'dark:text-white text-black' ?> text-2xl font-black"><?= $expiryText ?></p>
                    <?php if ($isActive): ?>
                        <p class="text-xs dark:text-gray-400 text-gray-600 mt-1"><?= $daysRemaining ?> days remaining</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dark:bg-[#1a1f29] bg-gray-50 border dark:border-gray-800 border-gray-200 rounded-xl p-8 transition-colors">
            <h2 class="text-xl font-bold dark:text-white text-black mb-2">Redeem ComixPass Code</h2>
            <p class="text-sm dark:text-gray-400 text-gray-600 mb-6">Purchased a subscription code from our Shopee store? Enter it below to unlock premium access. Time is automatically added to your current balance.</p>
            
            <div id="voucherMsg" class="hidden mb-4 p-3 rounded text-sm font-bold text-center"></div>

            <div class="flex flex-col sm:flex-row gap-3">
                <input type="text" id="voucherCode" placeholder="XXXX-XXXX-XXXX-XXXX" class="flex-grow dark:bg-surface bg-white border dark:border-gray-700 border-gray-300 rounded-lg p-4 dark:text-white text-black font-mono uppercase tracking-widest focus:border-accent focus:outline-none placeholder-gray-500 transition-colors">
                <button onclick="redeemVoucher()" class="bg-accent hover:bg-cyan-400 text-black font-black px-8 py-4 rounded-lg shadow-md transition-colors whitespace-nowrap">REDEEM</button>
            </div>
            
            <div class="mt-6 pt-6 border-t dark:border-gray-800 border-gray-200">
                <p class="text-xs text-gray-500 text-center">Need a code? <a href="https://shopee.com.my/" target="_blank" class="text-accent hover:underline font-bold">Visit our Official Shopee Store</a>.</p>
            </div>
        </div>
        <?php if (!empty($ownedTitles)): ?>
        <div class="mt-8">
            <h2 class="text-xl font-bold dark:text-white text-black mb-4 border-l-4 border-accent pl-2">My Purchased Titles</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php foreach ($ownedTitles as $title): ?>
                    <?php 
                        $expDate = new DateTime($title['expires_at']); 
                        $daysLeft = (new DateTime())->diff($expDate)->days;
                    ?>
                    <a href="manga.php?id=<?= urlencode($title['manga_id']) ?>" class="flex gap-4 dark:bg-[#1a1f29] bg-gray-50 border dark:border-gray-800 border-gray-200 rounded-xl p-4 hover:border-accent dark:hover:border-accent transition-colors group">
                        <div class="w-16 h-24 flex-shrink-0 rounded bg-gray-800 overflow-hidden shadow-md">
                            <img src="image_proxy.php?v=2&w=300&url=<?= urlencode($title['cover_url'] ?? 'https://via.placeholder.com/80x110') ?>" class="w-full h-full object-cover">
                        </div>
                        <div class="flex flex-col justify-center">
                            <h3 class="font-bold dark:text-gray-200 text-gray-800 line-clamp-2 group-hover:text-accent transition-colors">
                                <?= htmlspecialchars($title['title']) ?>
                            </h3>
                            <p class="text-xs dark:text-gray-400 text-gray-500 mt-2">Access valid until:</p>
                            <p class="text-sm font-black dark:text-white text-black"><?= $expDate->format('M j, Y') ?></p>
                            <p class="text-[10px] text-[#ff4757] font-bold mt-1"><?= $daysLeft ?> days left</p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
    <script>
        // --- 1. Unique Subscription Logic ---
        function redeemVoucher() {
            const codeInput = document.getElementById('voucherCode');
            const code = codeInput.value.trim();
            const msgBox = document.getElementById('voucherMsg');

            if (!code) return;

            msgBox.className = 'mb-4 p-3 rounded text-sm font-bold text-center dark:bg-gray-800 bg-gray-200 dark:text-gray-300 text-gray-700';
            msgBox.innerText = 'Validating code...';
            msgBox.classList.remove('hidden');

            fetch('api.php?action=redeem_voucher', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code })
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    msgBox.className = 'mb-4 p-3 rounded text-sm font-bold text-center bg-green-900/50 border border-green-500 text-green-500';
                    msgBox.innerText = `Success! Your ComixPass is now valid until ${data.new_expiry}. Reloading...`;
                    codeInput.value = '';
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    msgBox.className = 'mb-4 p-3 rounded text-sm font-bold text-center bg-red-900/50 border border-red-500 text-red-500';
                    msgBox.innerText = data.message;
                }
            }).catch(err => {
                msgBox.className = 'mb-4 p-3 rounded text-sm font-bold text-center bg-red-900/50 border border-red-500 text-red-500';
                msgBox.innerText = 'Server connection error. Please try again.';
            });
        }

        // --- 2. Nav Scripts (Theme) ---
        const html = document.documentElement;
        const btnLight = document.getElementById('themeLight');
        const btnDark = document.getElementById('themeDark');

        function updateThemeButtons() {
            if (html.classList.contains('dark')) {
                btnDark.classList.add('bg-accent', 'text-black'); btnDark.classList.remove('text-gray-400');
                btnLight.classList.remove('bg-accent', 'text-black'); btnLight.classList.add('dark:text-gray-400', 'text-gray-600');
            } else {
                btnLight.classList.add('bg-accent', 'text-black'); btnLight.classList.remove('text-gray-400', 'text-gray-600');
                btnDark.classList.remove('bg-accent', 'text-black'); btnDark.classList.add('text-gray-600');
            }
        }
        if(btnLight && btnDark) {
            updateThemeButtons();
            btnLight.addEventListener('click', () => { html.classList.remove('dark'); localStorage.setItem('theme', 'light'); updateThemeButtons(); });
            btnDark.addEventListener('click', () => { html.classList.add('dark'); localStorage.setItem('theme', 'dark'); updateThemeButtons(); });
        }

        // --- 3. Mobile Search Toggle (FIXED with stopPropagation) ---
        const mobileBtn = document.getElementById('mobileSearchToggle');
        const mobileTray = document.getElementById('mobileSearchTray');
        const mobileInput = document.getElementById('mobileSearchInput');
        if (mobileBtn && mobileTray && mobileInput) {
            mobileBtn.addEventListener('click', (e) => { 
                e.stopPropagation(); 
                mobileTray.classList.toggle('hidden'); 
                if (!mobileTray.classList.contains('hidden')) mobileInput.focus(); 
            });
        }

        // --- 4. AJAX Search Bar Logic ---
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

        function logoutUser() { fetch('auth_api.php?action=logout').then(() => window.location.reload()); }

        // --- 5. Global Clickaway (FIXED for mobile tray) ---
        document.addEventListener('click', (e) => {
            const searchResults = document.getElementById('searchResults'); const mobileSearchResults = document.getElementById('mobileSearchResults'); const mobileTray = document.getElementById('mobileSearchTray');
            if (!e.target.closest('#searchInput') && !e.target.closest('#searchResults')) { if(searchResults) searchResults.classList.add('hidden'); }
            
            if (!e.target.closest('#mobileSearchTray') && !e.target.closest('#mobileSearchToggle')) { 
                if(mobileSearchResults) mobileSearchResults.classList.add('hidden'); 
                if(mobileTray) mobileTray.classList.add('hidden'); 
            }
        });
    </script>
</body>
</html>
