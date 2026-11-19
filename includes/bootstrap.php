<?php
// ============================================================
// PINJAMIN — Bootstrap Aplikasi
// Inisialisasi konfigurasi, database, session, dan helper
// ============================================================

// Mencegah akses langsung
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung dilarang');
}

// Load konfigurasi
$config = require __DIR__ . '/../config/config.php';

// Konfigurasi session
session_name($config['app']['session_name']);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', (string)$config['app']['session_lifetime']);
session_set_cookie_params([
    'lifetime' => $config['app']['session_lifetime'],
    'path'     => $config['app']['base_url'],
    'httponly' => true,
    'secure'   => isset($_SERVER['HTTPS']),
    'samesite' => 'Lax',
]);
session_start();

// Regenerasi ID session secara periodik untuk keamanan
if (!isset($_SESSION['_last_regeneration']) || time() - $_SESSION['_last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['_last_regeneration'] = time();
}

// Koneksi database (lazy - hanya saat dibutuhkan)
$pdo = null;
function get_pdo(): PDO
{
    global $pdo, $config;
    if ($pdo === null) {
        $dsn = "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']};charset={$config['db']['charset']}";
        $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], $config['db']['options']);
    }
    return $pdo;
}

// Helper: Escape HTML output
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Helper: Redirect dengan flash message
function redirect(string $path, string $message = '', string $type = 'info'): never
{
    global $config;
    if ($message !== '') {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    $url = rtrim($config['app']['base_url'], '/') . '/' . ltrim($path, '/');
    header("Location: $url", true, 302);
    exit;
}

// Helper: Ambil dan hapus flash message
function get_flash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Helper: Generate CSRF token
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Helper: Validasi CSRF token
function csrf_validate(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Helper: Cek apakah user sudah login
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

// Helper: Ambil data user dari session
function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'       => $_SESSION['user_id'],
        'name'     => $_SESSION['user_name'],
        'username' => $_SESSION['user_username'],
        'role'     => $_SESSION['user_role'],
    ];
}

// Helper: Require login (redirect ke login jika belum)
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php', 'Silakan login terlebih dahulu', 'warning');
    }
}

// Helper: Require role tertentu
function require_role(string|array $roles): void
{
    require_login();
    $user_role = current_user()['role'];
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($user_role, $allowed, true)) {
        http_response_code(403);
        include __DIR__ . '/../public/403.php';
        exit;
    }
}

// Helper: Base URL
function base_url(string $path = ''): string
{
    global $config;
    return rtrim($config['app']['base_url'], '/') . '/' . ltrim($path, '/');
}

// Helper: URL asset
function asset(string $path): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Error handling - production mode
if (!isset($_SERVER['SERVER_NAME']) || !in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'], true)) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}

// Log aktivitas (sederhana, ke file)
function log_activity(string $action, string $details = ''): void
{
    $user = current_user();
    $log = sprintf(
        "[%s] User: %s (ID: %s, Role: %s) | Action: %s | %s\n",
        date('Y-m-d H:i:s'),
        $user['username'] ?? 'guest',
        $user['id'] ?? '-',
        $user['role'] ?? '-',
        $action,
        $details
    );
    $log_file = __DIR__ . '/../logs/activity.log';
    @file_put_contents($log_file, $log, FILE_APPEND | LOCK_EX);
}