<?php
// src/Services/AuthService.php

class AuthService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function login($identifier, $password) {
        // Authenticate via Email or Username
        $stmt = $this->pdo->prepare("SELECT id, username, password_hash, role, content_filters FROM cp_users WHERE email = ? OR username = ?");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Generate a new session token for concurrency protection
            $sessionToken = bin2hex(random_bytes(32));
            
            $updateToken = $this->pdo->prepare("UPDATE cp_users SET session_token = ? WHERE id = ?");
            $updateToken->execute([$sessionToken, $user['id']]);

            // Set basic session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'] ?? 'user';
	    $_SESSION['session_token'] = $sessionToken;
	    $_SESSION['content_filters'] = $user['content_filters'] ?? 'safe,suggestive';

            return ['success' => true, 'message' => 'Login successful'];
        }

        return ['success' => false, 'message' => 'Invalid credentials.'];
    }

    public function register($username, $email, $password) {
        // Check if user exists
        $stmt = $this->pdo->prepare("SELECT id FROM cp_users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Username or Email already in use.'];
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $ins = $this->pdo->prepare("INSERT INTO cp_users (username, email, password_hash) VALUES (?, ?, ?)");
        
        if ($ins->execute([$username, $email, $hashedPassword])) {
            // Auto-login after registration
            return $this->login($username, $password);
        }
        
        return ['success' => false, 'message' => 'Registration failed.'];
    }

    public function isVip($userId) {
        if (!$userId) return false;
        $stmtUser = $this->pdo->prepare("SELECT subscription_ends_at FROM cp_users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $subEnd = $stmtUser->fetchColumn();
        
        return ($subEnd && new DateTime($subEnd) > new DateTime());
    }

    public function verifySessionConcurrency($userId, $currentSessionToken) {
        if (!$userId) return false;
        $stmtToken = $this->pdo->prepare("SELECT session_token FROM cp_users WHERE id = ?");
        $stmtToken->execute([$userId]);
        $dbToken = $stmtToken->fetchColumn();

        return (!empty($currentSessionToken) && $dbToken === $currentSessionToken);
    }
}
?>
