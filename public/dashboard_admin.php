<?php
// ============================================================
// PINJAMIN — Dashboard Admin (UI-2 Pilot)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$user = current_user();

// Fetch dashboard stats
try {
    $pdo = get_pdo();
    
    $stat_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stat_categories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $stat_tools = $pdo->query("SELECT COUNT(*) FROM tools")->fetchColumn();
    $stat_pending = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status = 'pending'")->fetchColumn();
    $stat_return_pending = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'pending'")->fetchColumn();
    $stat_active_borrowings = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('approved','partially_returned')")->fetchColumn();
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stat_users = $stat_categories = $stat_tools = $stat_pending = $stat_return_pending = $stat_active_borrowings = 0;
}

$pageTitle = 'Dashboard Admin';
$currentPage = 'dashboard_admin.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Dashboard Admin</h1>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <p class="welcome text-muted mb-6">Kelola pengguna, kategori, alat, dan pantau transaksi peminjaman.</p>

  <div class="stats-grid mb-6">
    <div class="stat-card">
      <div class="stat-label">Total Pengguna</div>
      <div class="stat-value"><?= $stat_users ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Kategori</div>
      <div class="stat-value"><?= $stat_categories ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Alat</div>
      <div class="stat-value"><?= $stat_tools ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Menunggu Persetujuan</div>
      <div class="stat-value"><?= $stat_pending ?></div>
      <div class="stat-meta">peminjaman</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Menunggu Konfirmasi</div>
      <div class="stat-value"><?= $stat_return_pending ?></div>
      <div class="stat-meta">pengembalian</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Peminjaman Aktif</div>
      <div class="stat-value"><?= $stat_active_borrowings ?></div>
      <div class="stat-meta">disetujui + sebagian kembali</div>
    </div>
  </div>

  <div class="cards-grid">
    <div class="card">
      <div class="card-header"><h3>Kelola Pengguna</h3></div>
      <div class="card-body">
        <p class="card-text">Tambah dan kelola akun serta hak akses pengguna.</p>
        <a href="<?= base_url('users.php') ?>" class="btn btn-primary">Kelola Pengguna</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Kelola Kategori</h3></div>
      <div class="card-body">
        <p class="card-text">Atur kategori untuk memudahkan pengelompokan alat.</p>
        <a href="<?= base_url('categories.php') ?>" class="btn btn-primary">Kelola Kategori</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Kelola Alat</h3></div>
      <div class="card-body">
        <p class="card-text">Kelola informasi alat, jumlah inventaris, dan ketersediaan stok.</p>
        <a href="<?= base_url('tools.php') ?>" class="btn btn-primary">Kelola Alat</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Kelola Peminjaman</h3></div>
      <div class="card-body">
        <p class="card-text">Pantau dan kelola seluruh transaksi peminjaman alat.</p>
        <a href="<?= base_url('admin_borrowings.php') ?>" class="btn btn-primary">Kelola Peminjaman</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Kelola Pengembalian</h3></div>
      <div class="card-body">
        <p class="card-text">Pantau dan kelola pengajuan serta riwayat pengembalian alat.</p>
        <a href="<?= base_url('admin_returns.php') ?>" class="btn btn-primary">Kelola Pengembalian</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Log Aktivitas</h3></div>
      <div class="card-body">
        <p class="card-text">Lihat riwayat aktivitas pengguna dan perubahan data dalam sistem.</p>
        <a href="<?= base_url('activity_log.php') ?>" class="btn btn-primary">Lihat Log</a>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>