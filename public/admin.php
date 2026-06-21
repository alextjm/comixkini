<?php
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

// STRICT SECURITY: Kick out anyone who isn't an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. You do not have permission to view this page.");
}

$successMessage = '';
$errorMessage = '';

// Handle Code Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $amount = max(1, min(500, (int)$_POST['amount'])); // Max 500 at a time
    $generated = 0;
    
    $mangaId = null;
    $days = 365;

    // Determine type of code being generated
    if ($_POST['action'] === 'generate_vip') {
        $days = (int)$_POST['days'];
    } elseif ($_POST['action'] === 'generate_title') {
        $mangaId = trim($_POST['manga_id']);
        $days = 365; // Force 1 year access for titles
        
        if (empty($mangaId)) {
            $errorMessage = "Error: Manga ID is required to generate Title Codes.";
        }
    }

    if (empty($errorMessage)) {
        for ($i = 0; $i < $amount; $i++) {
            // Generate a random 16-character hex string
            $rawCode = strtoupper(substr(bin2hex(random_bytes(8)), 0, 16));
            // Format it like XXXX-XXXX-XXXX-XXXX
            $formattedCode = substr($rawCode, 0, 4) . '-' . substr($rawCode, 4, 4) . '-' . substr($rawCode, 8, 4) . '-' . substr($rawCode, 12, 4);
            
            try {
                // Ensure your cp_vouchers table has the manga_id column added
                $stmt = $pdo->prepare("INSERT INTO cp_vouchers (voucher_code, duration_days, manga_id) VALUES (?, ?, ?)");
                $stmt->execute([$formattedCode, $days, $mangaId]);
                $generated++;
            } catch (PDOException $e) {
                // Ignore duplicate collisions, just skip
                continue;
            }
        }
        $type = $mangaId ? "Title Codes ($mangaId)" : "VIP Codes";
        $successMessage = "Successfully generated $generated $type for $days days!";
    }
}

// Fetch Stats
$totalUsers = $pdo->query("SELECT COUNT(*) FROM cp_users")->fetchColumn();
$activeVIPs = $pdo->query("SELECT COUNT(*) FROM cp_users WHERE subscription_ends_at > NOW()")->fetchColumn();

// Fetch Latest Unused Codes for Shopee
$stmtUnused = $pdo->query("SELECT * FROM cp_vouchers WHERE is_used = 0 ORDER BY created_at DESC LIMIT 50");
$unusedCodes = $stmtUnused->fetchAll(PDO::FETCH_ASSOC);

// Fetch Recently Used Codes
$stmtUsed = $pdo->query("
    SELECT v.*, u.username 
    FROM cp_vouchers v 
    JOIN cp_users u ON v.used_by_user_id = u.id 
    WHERE v.is_used = 1 
    ORDER BY v.used_at DESC 
    LIMIT 10
");
$usedCodes = $stmtUsed->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ComixKini Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { colors: { background: '#0d1015', surface: '#151921', card: '#1a1f29', accent: '#26c6da' } } } }
    </script>
</head>
<body class="bg-background text-gray-300 font-sans antialiased pb-20">

    <nav class="bg-surface border-b border-gray-800 sticky top-0 z-50">
        <div class="max-w-[1500px] mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <a href="admin.php" class="flex items-center space-x-2 flex-shrink-0 group">
                <svg class="w-7 h-7 sm:w-8 sm:h-8 text-accent group-hover:scale-110 transition-transform" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" viewBox="0 0 24 24">
                    <path d="M21 11.5C21 16.75 16.97 21 12 21c-1.66 0-3.21-.42-4.55-1.16L3 21l1.5-4.2C3.55 15.35 3 13.5 3 11.5 3 6.25 7.03 2 12 2s9 4.25 9 9.5z M12 6 l1.5 4 h4 l-3.2 2.5 l1.2 4 l-3.5 -2.6 l-3.5 2.6 l1.2 -4 l-3.2 -2.5 h4 z"/>
                </svg>
                <span class="text-lg sm:text-xl font-bold tracking-wider text-white">COMIXKINI <span class="text-accent text-sm sm:text-base">ADMIN</span></span>
            </a>
            </div>
            
            <div class="flex items-center gap-4">
                <a href="index.php" class="text-sm font-bold text-gray-400 hover:text-white transition-colors hidden sm:block">Back to Site</a>
                
                <div class="relative group cursor-pointer block">
                    <div class="flex items-center gap-2 bg-[#1a1f29] border border-gray-700 rounded-full sm:pl-3 p-0.5 sm:pr-1 sm:py-1 hover:border-accent transition-colors">
                        <span class="hidden sm:block text-xs font-bold text-gray-200"><?= htmlspecialchars($_SESSION['username']) ?></span>
                        <div class="w-8 h-8 sm:w-7 sm:h-7 bg-accent rounded-full flex items-center justify-center text-black font-black text-xs sm:text-[10px]">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </div>
                    </div>
                    <div class="absolute right-0 mt-2 w-48 bg-[#1a1f29] border border-gray-700 rounded shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 overflow-hidden">
                        <a href="index.php" class="block sm:hidden px-4 py-3 text-sm text-gray-300 hover:bg-[#262c38] hover:text-white">Back to Site</a>
                        <a href="profile.php" class="block px-4 py-3 sm:py-2 text-sm text-gray-300 hover:bg-[#262c38] hover:text-accent">My Library</a>
                        <a href="subscription.php" class="block px-4 py-3 sm:py-2 text-sm text-gray-300 hover:bg-[#262c38] hover:text-accent border-b border-gray-700">ComixKini Status</a>
                        <button onclick="fetch('auth_api.php?action=logout').then(()=>window.location='index.php')" class="w-full text-left px-4 py-3 sm:py-2 text-sm text-red-500 hover:bg-red-500/10 font-bold">Logout</button>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-4 py-8">
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-surface border border-gray-800 rounded-lg p-6 shadow-xl">
                <p class="text-sm text-gray-500 font-bold mb-1">Total Registered Users</p>
                <p class="text-3xl text-white font-black"><?= number_format($totalUsers) ?></p>
            </div>
            <div class="bg-surface border border-gray-800 rounded-lg p-6 shadow-xl">
                <p class="text-sm text-gray-500 font-bold mb-1">Active VIP Subscribers</p>
                <p class="text-3xl text-accent font-black"><?= number_format($activeVIPs) ?></p>
            </div>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="bg-green-900/50 border border-green-500 text-green-400 px-4 py-3 rounded mb-6 font-bold">
                <?= $successMessage ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errorMessage)): ?>
            <div class="bg-red-900/50 border border-red-500 text-red-400 px-4 py-3 rounded mb-6 font-bold">
                <?= $errorMessage ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <div class="lg:col-span-1 flex flex-col gap-6">
                
                <div class="bg-surface border border-gray-800 rounded-lg p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-white border-b border-gray-800 pb-2 mb-4">1. Generate VIP Codes</h2>
                    <form method="POST" class="flex flex-col gap-4">
                        <input type="hidden" name="action" value="generate_vip">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">VIP Duration</label>
                            <select name="days" class="w-full bg-[#1a1f29] border border-gray-700 rounded p-2.5 text-white text-sm focus:border-accent focus:outline-none">
                                <option value="30">1 Month (30 Days)</option>
                                <option value="90">3 Months (90 Days)</option>
                                <option value="180">6 Months (180 Days)</option>
                                <option value="365">1 Year (365 Days)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Quantity</label>
                            <input type="number" name="amount" value="10" min="1" max="500" class="w-full bg-[#1a1f29] border border-gray-700 rounded p-2.5 text-white text-sm focus:border-accent focus:outline-none">
                        </div>
                        <button type="submit" class="w-full bg-gray-700 hover:bg-gray-600 text-white font-black py-3 rounded mt-2 transition-colors">GENERATE VIP CODES</button>
                    </form>
                </div>

                <div class="bg-surface border border-accent/30 rounded-lg p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-accent border-b border-gray-800 pb-2 mb-4">2. Generate Title Codes</h2>
                    <p class="text-xs text-gray-400 mb-4">Grants 1-Year access strictly to a specific Manga ID.</p>
                    <form method="POST" class="flex flex-col gap-4">
                        <input type="hidden" name="action" value="generate_title">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Target Manga ID (Required)</label>
                            <input type="text" name="manga_id" required placeholder="e.g. 32d76d19-8a05-4db0-9fc2..." class="w-full bg-[#1a1f29] border border-gray-700 rounded p-2.5 text-white text-sm focus:border-accent focus:outline-none font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Quantity</label>
                            <input type="number" name="amount" value="10" min="1" max="500" class="w-full bg-[#1a1f29] border border-gray-700 rounded p-2.5 text-white text-sm focus:border-accent focus:outline-none">
                        </div>
                        <button type="submit" class="w-full bg-accent hover:bg-cyan-400 text-black font-black py-3 rounded mt-2 transition-colors">GENERATE TITLE CODES</button>
                    </form>
                </div>

            </div>

            <div class="lg:col-span-2 flex flex-col gap-8">
                
                <div class="bg-surface border border-gray-800 rounded-lg p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-white border-b border-gray-800 pb-2 mb-4 flex justify-between">
                        <span>Available Codes (Latest 50)</span>
                        <span class="text-accent text-sm">Ready for Shopee</span>
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="text-gray-500 border-b border-gray-800">
                                    <th class="pb-2">Code</th>
                                    <th class="pb-2">Type / Target</th>
                                    <th class="pb-2">Duration</th>
                                    <th class="pb-2">Created</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-300">
                                <?php foreach ($unusedCodes as $code): ?>
                                <tr class="border-b border-gray-800/50 hover:bg-[#1a1f29] transition-colors">
                                    <td class="py-2 font-mono text-white select-all"><?= htmlspecialchars($code['voucher_code']) ?></td>
                                    
                                    <td class="py-2">
                                        <?php if(empty($code['manga_id'])): ?>
                                            <span class="text-gray-400 font-bold text-xs bg-gray-800 px-2 py-1 rounded">VIP SUB</span>
                                        <?php else: ?>
                                            <span class="text-accent font-bold text-xs bg-accent/20 px-2 py-1 rounded" title="<?= htmlspecialchars($code['manga_id']) ?>">TITLE (Hover to view ID)</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-2"><?= $code['duration_days'] ?> Days</td>
                                    <td class="py-2 text-xs text-gray-500"><?= date('M j, Y - H:i', strtotime($code['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($unusedCodes)): ?>
                                <tr><td colspan="4" class="py-4 text-center text-gray-500">No available codes. Generate some!</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-surface border border-gray-800 rounded-lg p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-white border-b border-gray-800 pb-2 mb-4">Recently Redeemed</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="text-gray-500 border-b border-gray-800">
                                    <th class="pb-2">User</th>
                                    <th class="pb-2">Code Used</th>
                                    <th class="pb-2">Type</th>
                                    <th class="pb-2">Redeemed At</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-300">
                                <?php foreach ($usedCodes as $code): ?>
                                <tr class="border-b border-gray-800/50">
                                    <td class="py-2 font-bold text-accent"><?= htmlspecialchars($code['username']) ?></td>
                                    <td class="py-2 font-mono text-gray-500 line-through"><?= htmlspecialchars($code['voucher_code']) ?></td>
                                    
                                    <td class="py-2 text-xs font-bold text-gray-500">
                                        <?= empty($code['manga_id']) ? 'VIP' : 'TITLE' ?>
                                    </td>

                                    <td class="py-2 text-xs text-gray-500"><?= date('M j, Y - H:i', strtotime($code['used_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>
</body>
</html>