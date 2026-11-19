<?php
// ============================================================
// PINJAMIN — Login Page
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';

// Jika sudah login, redirect ke dashboard sesuai role
if (is_logged_in()) {
    $role = current_user()['role'];
    $dashboards = [
        'admin'     => 'dashboard_admin.php',
        'petugas'   => 'dashboard_petugas.php',
        'peminjam'  => 'dashboard_peminjam.php',
    ];
    redirect($dashboards[$role] ?? 'login.php');
}

$flash = get_flash();
$errors = [];

// Proses form login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi CSRF
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validasi input
    if ($username === '') {
        $errors[] = 'Username wajib diisi';
    }
    if ($password === '') {
        $errors[] = 'Password wajib diisi';
    }

    if (empty($errors)) {
        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT id, name, username, password, role, is_active FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (!$user['is_active']) {
                    $errors[] = 'Akun tidak aktif. Hubungi administrator.';
                } else {
                    // Login berhasil - regenerasi session ID
                    session_regenerate_id(true);

                    // Set session data
                    $_SESSION['user_id']       = $user['id'];
                    $_SESSION['user_name']     = $user['name'];
                    $_SESSION['user_username'] = $user['username'];
                    $_SESSION['user_role']     = $user['role'];

                    // Log aktivitas
                    log_activity('LOGIN', "User {$user['username']} login sebagai {$user['role']}");

                    // Redirect ke dashboard sesuai role
                    $dashboards = [
                        'admin'     => 'dashboard_admin.php',
                        'petugas'   => 'dashboard_petugas.php',
                        'peminjam'  => 'dashboard_peminjam.php',
                    ];
                    redirect($dashboards[$user['role']] ?? 'login.php', 'Login berhasil', 'success');
                }
            } else {
                $errors[] = 'Username atau password salah';
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = 'Terjadi kesalahan sistem. Silakan coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 400px;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 {
            color: #333;
            font-size: 28px;
            font-weight: 600;
        }
        .logo p {
            color: #666;
            margin-top: 4px;
        }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn:hover { background: #5568d3; }
        .btn:disabled { background: #a0a8e8; cursor: not-allowed; }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error { background: #fee; color: #c00; border: 1px solid #fcc; }
        .alert-success { background: #efe; color: #060; border: 1px solid #cfc; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .demo-accounts {
            margin-top: 30px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 13px;
        }
        .demo-accounts h4 { margin-bottom: 10px; color: #333; }
        .demo-accounts table { width: 100%; border-collapse: collapse; }
        .demo-accounts th, .demo-accounts td { padding: 6px 8px; text-align: left; }
        .demo-accounts th { color: #667eea; font-weight: 600; }
        .demo-accounts code { background: #eef; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <h1>Pinjamin</h1>
            <p>Aplikasi Peminjaman Alat</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" autocomplete="username" required autofocus
                       value="<?= e($_POST['username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn">Masuk</button>
        </form>

        <div class="demo-accounts">
            <h4>Akun Demo (password: <code>password123</code>)</h4>
            <table>
                <tr><th>Role</th><th>Username</th></tr>
                <tr><td>Admin</td><td><code>admin</code></td></tr>
                <tr><td>Petugas</td><td><code>petugas1</code> / <code>petugas2</code></td></tr>
                <tr><td>Peminjam</td><td><code>peminjam_a</code> / <code>peminjam_b</code></td></tr>
            </table>
        </div>
    </div>
</body>
</html>