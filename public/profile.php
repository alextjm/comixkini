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

// Security: Kick out guests
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$userId = $_SESSION['user_id'];

// Fetch user's saved comics by joining the titles table with the bookmarks table
$stmt = $pdo->prepare("
    SELECT t.*, b.created_at as saved_at 
    FROM cp_titles t 
    JOIN cp_bookmarks b ON t.manga_id = b.manga_id 
    WHERE b.user_id = ? 
    ORDER BY b.created_at DESC
");
$stmt->execute([$userId]);
$savedComics = $stmt->fetchAll(PDO::FETCH_ASSOC);

function timeAgo($datetime) {
    if (!$datetime) return 'Unknown';
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $time);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Library - ComixPass</title>
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

    <main class="max-w-[1500px] mx-auto px-4 py-8">
        <div class="mb-8">
	    <h1 class="text-3xl font-black dark:text-white text-black tracking-wide border-l-4 border-accent pl-3">My Library</h1>
<div class="mb-10 bg-[#1a1f29] border border-gray-800 rounded-lg p-5 inline-block">
            <h2 class="text-sm font-bold text-white mb-3">Content Filter Settings</h2>
            <?php
                $currentFilters = isset($_SESSION['content_filters']) ? explode(',', $_SESSION['content_filters']) : ['safe', 'suggestive'];
            ?>
            <div class="flex flex-wrap gap-4 text-sm font-bold">
                <label class="flex items-center gap-2 text-gray-500 cursor-not-allowed">
                    <input type="checkbox" checked disabled class="accent-accent w-4 h-4"> Safe (Required)
                </label>
                <label class="flex items-center gap-2 text-gray-300 cursor-pointer">
                    <input type="checkbox" value="suggestive" class="filter-cb accent-accent w-4 h-4" <?= in_array('suggestive', $currentFilters) ? 'checked' : '' ?>> Suggestive
                </label>
                <label class="flex items-center gap-2 text-[#ff4757] cursor-pointer">
                    <input type="checkbox" value="erotica" class="filter-cb accent-[#ff4757] w-4 h-4" <?= in_array('erotica', $currentFilters) ? 'checked' : '' ?>> Erotica (18+)
                </label>
                <label class="flex items-center gap-2 text-red-600 cursor-pointer">
                    <input type="checkbox" value="pornographic" class="filter-cb accent-red-600 w-4 h-4" <?= in_array('pornographic', $currentFilters) ? 'checked' : '' ?>> Explicit (18+)
                </label>
            </div>
            <button onclick="saveContentSettings()" class="mt-4 bg-gray-800 hover:bg-gray-700 text-white text-xs font-bold px-4 py-2 rounded transition-colors" id="saveFilterBtn">Save Preferences</button>
        </div>

        <script>
            function saveContentSettings() {
                const btn = document.getElementById('saveFilterBtn');
                const checkboxes = document.querySelectorAll('.filter-cb');
                let selected = [];
                checkboxes.forEach(cb => { if(cb.checked) selected.push(cb.value); });

                btn.innerText = 'Saving...';
                fetch('api.php?action=update_settings', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ filters: selected })
                }).then(res => res.json()).then(data => {
                    if(data.success) {
                        btn.innerText = 'Saved! Reloading...';
                        btn.classList.add('text-accent');
                        setTimeout(() => window.location.href = 'index.php', 1000);
                    }
                });
            }
        </script>
            <p class="text-sm dark:text-gray-400 text-gray-600 mt-2 ml-4">You have <?= count($savedComics) ?> saved comics.</p>
        </div>

        <?php if (empty($savedComics)): ?>
            <div class="text-center py-20 dark:bg-surface bg-white border dark:border-gray-800 border-gray-300 rounded-lg">
                <svg class="w-16 h-16 mx-auto text-gray-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                <h2 class="text-xl font-bold dark:text-gray-300 text-gray-700">Your library is empty</h2>
                <p class="text-sm dark:text-gray-500 text-gray-500 mt-2 mb-6">Go explore the homepage and save some comics to read later!</p>
                <a href="index.php" class="bg-accent text-black font-bold px-6 py-2 rounded hover:bg-cyan-400 transition-colors">Discover Comics</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <?php foreach ($savedComics as $manga): ?>
                <div class="group flex flex-col relative" id="card-<?= $manga['manga_id'] ?>">
                    <a href="manga.php?id=<?= urlencode($manga['manga_id']) ?>" class="relative aspect-[1/1.4] w-full rounded-md overflow-hidden dark:bg-gray-800 bg-gray-200 border dark:border-gray-800 border-gray-300 group-hover:border-[#ff4757]/50 transition-colors">
                        <img src="<?= htmlspecialchars($manga['cover_url'] ?? 'https://via.placeholder.com/300x420') ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-2 flex justify-between items-end h-1/2">
                            <span class="text-[10px] text-gray-300 font-medium">Saved <?= timeAgo($manga['saved_at']) ?></span>
                        </div>
                    </a>
                    <button onclick="removeBookmark('<?= $manga['manga_id'] ?>')" class="absolute top-2 right-2 bg-black/80 hover:bg-red-500 text-white p-1.5 rounded transition-colors shadow-lg opacity-0 group-hover:opacity-100 z-10" title="Remove from Library">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                    </button>
                    <h3 class="mt-2 text-sm font-semibold dark:text-gray-200 text-gray-800 line-clamp-2 leading-tight group-hover:text-[#ff4757] transition-colors">
                        <?= htmlspecialchars($manga['title']) ?>
                    </h3>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <script>
        // --- 1. Unique Profile Logic ---
        function removeBookmark(mangaId) {
            if(!confirm("Remove this comic from your library?")) return;
            fetch('bookmark_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ manga_id: mangaId })
            }).then(res => res.json()).then(data => {
                if (data.success && !data.bookmarked) {
                    document.getElementById('card-' + mangaId).style.display = 'none';
                }
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
