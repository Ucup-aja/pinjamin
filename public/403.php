<?php
// ============================================================
// PINJAMIN — 403 Forbidden Page
// ============================================================
if (!function_exists('base_url')) {
    require_once __DIR__ . '/../includes/bootstrap.php';
}
http_response_code(403);
$pageTitle = 'Akses Ditolak | Pinjamin';
include __DIR__ . '/../includes/partials/head_public.php';
?>
<div class="error-page">
  <div class="error-card">
    <h1 class="error-code">403</h1>
    <h2 style="margin: 0 0 var(--space-2); color: var(--color-text);">Akses Ditolak</h2>
    <p style="margin: 0 0 var(--space-6); color: var(--color-text-muted);">
      Anda tidak memiliki izin untuk mengakses halaman ini.
    </p>
    <a href="<?= base_url('login.php') ?>" class="btn btn-primary">Kembali ke Login</a>
  </div>
</div>
<script src="<?= asset('js/theme.js') ?>"></script>
</body>
</html>