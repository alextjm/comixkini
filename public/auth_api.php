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

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'register') {
    // --- 1. THE HONEYPOT TRAP ---
    if (!empty($input['honeypot'])) {
        echo json_encode(['success' => true]);
        exit;
    }

    // --- 2. THE TIME TRAP (Autofill Safe) ---
    if (isset($input['load_time'])) {
        $timeTaken = time() - intval($input['load_time']);
        if ($timeTaken < 2) {
            echo json_encode(['success' => false, 'message' => 'Request blocked: Suspicious automated activity.']);
            exit;
        }
    }

    // --- 3. IP RATE LIMITER ---
    $userIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown_ip';
    $cacheDir = __DIR__ . '/../cache/';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    
    $rateLimitFile = $cacheDir . 'rate_' . md5($userIp) . '.json';
    $rateData = file_exists($rateLimitFile) ? json_decode(file_get_contents($rateLimitFile), true) : ['count' => 0, 'first_attempt' => time()];

    if (time() - $rateData['first_attempt'] > 3600) {
        $rateData = ['count' => 0, 'first_attempt' => time()];
    }
    if ($rateData['count'] >= 3) {
        echo json_encode(['success' => false, 'message' => 'Too many accounts created from this IP. Please try again in an hour.']);
        exit;
    }

    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (!$username || !$email || strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'All fields required. Password must be 6+ characters.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO cp_users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $hash]);
        
        $rateData['count']++;
        file_put_contents($rateLimitFile, json_encode($rateData));
        
        // Generate Concurrency Token
        $newToken = bin2hex(random_bytes(32));
        $userId = $pdo->lastInsertId();
        $updateToken = $pdo->prepare("UPDATE cp_users SET session_token = ? WHERE id = ?");
        $updateToken->execute([$newToken, $userId]);

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'user';
        $_SESSION['content_filters'] = 'safe,suggestive';
        $_SESSION['session_token'] = $newToken;
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { 
            echo json_encode(['success' => false, 'message' => 'Username or Email is already taken.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
        }
    }
    exit;
}

if ($action === 'login') {
    $emailOrUser = trim($input['login_id'] ?? '');
    $password = $input['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM cp_users WHERE email = ? OR username = ? LIMIT 1");
    $stmt->execute([$emailOrUser, $emailOrUser]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        
        // Concurrency Token Generation
        $newToken = bin2hex(random_bytes(32));
        $updateToken = $pdo->prepare("UPDATE cp_users SET session_token = ? WHERE id = ?");
        $updateToken->execute([$newToken, $user['id']]);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['content_filters'] = $user['content_filters'] ?? 'safe,suggestive';
        $_SESSION['session_token'] = $newToken;
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
?>
