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
$pageTitle = 'Masuk | Pinjamin';
include __DIR__ . '/../includes/partials/head_public.php';
?>
<div class="auth-page">
  <div class="auth-theme">
    <?php include __DIR__ . '/../includes/partials/theme_toggle.php'; ?>
  </div>

  <div class="auth-card">
    <div class="auth-brand">
      <h1>Pinjamin</h1>
      <p class="brand-sub">Sistem Peminjaman Alat Sekolah</p>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>" role="alert">
        <?= e($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error" role="alert">
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
        <label for="username" class="form-label">Username</label>
        <input type="text" id="username" name="username" class="form-input"
               autocomplete="username" required autofocus
               value="<?= e($_POST['username'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="password" class="form-label">Password</label>
        <div class="password-field">
          <input type="password" id="password" name="password" class="form-input"
                 autocomplete="current-password" required>
          <button type="button" class="password-toggle" id="passwordToggle" aria-label="Tampilkan password" aria-pressed="false">
            <svg class="icon-eye" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg class="icon-eye-off" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>
            </svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;">Masuk</button>
    </form>

    <p class="auth-footer">Gunakan akun yang diberikan administrator sekolah.</p>
  </div>
</div>

<script src="<?= asset('js/theme.js') ?>"></script>
<script>
  (function () {
    var toggle = document.getElementById('passwordToggle');
    var input = document.getElementById('password');
    if (!toggle || !input) return;
    toggle.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
      toggle.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
      toggle.querySelector('.icon-eye').style.display = show ? 'none' : 'block';
      toggle.querySelector('.icon-eye-off').style.display = show ? 'block' : 'none';
    });
  })();
</script>
</body>
</html>