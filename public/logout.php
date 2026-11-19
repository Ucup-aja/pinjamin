<?php
// ============================================================
// PINJAMIN — Logout
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';

// Log aktivitas sebelum destroy session
if (is_logged_in()) {
    $user = current_user();
    log_activity('LOGOUT', "User {$user['username']} logout");
}

// Hapus semua data session
$_SESSION = [];

// Hapus session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect ke login
redirect('login.php', 'Anda telah logout', 'info');