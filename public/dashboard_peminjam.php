<?php
// ============================================================
// PINJAMIN — Dashboard Peminjam
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

$user = current_user();
$flash = get_flash();

$uid = $user['id'];

// Fetch dashboard stats
try {
    $pdo = get_pdo();
    
    // Alat yang sedang dipinjam (approved + partially_returned)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM borrowings WHERE user_id = ? AND status IN ('approved','partially_returned')");
    $stmt->execute([$uid]);
    $stat_borrowed = $stmt->fetchColumn();
    
    // Menunggu persetujuan
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$uid]);
    $stat_pending = $stmt->fetchColumn();
    
    // Menunggu konfirmasi pengembalian (status 'pending' di tabel returns)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns WHERE requested_by = ? AND status = 'pending'");
    $stmt->execute([$uid]);
    $stat_return_pending = $stmt->fetchColumn();
    
    // Total denda (confirmed returns)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(fine_amount), 0) FROM returns WHERE requested_by = ? AND status = 'confirmed'");
    $stmt->execute([$uid]);
    $stat_total_fine = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Dashboard peminjam stats error: " . $e->getMessage());
    $stat_borrowed = $stat_pending = $stat_return_pending = $stat_total_fine = 0;
}

$pageTitle = 'Dashboard Peminjam';
$currentPage = 'dashboard_peminjam.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Dashboard Peminjam</h1>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <p class="text-muted mb-6">Lihat alat tersedia, ajukan peminjaman, dan kelola pengembalian.</p>

  <div class="stats-grid mb-6">
    <div class="stat-card">
      <div class="stat-label">Alat Dipinjam</div>
      <div class="stat-value"><?= $stat_borrowed ?></div>
      <div class="stat-meta">disetujui + sebagian kembali</div>
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
      <div class="stat-label">Total Denda</div>
      <div class="stat-value">Rp <?= number_format($stat_total_fine, 0, ',', '.') ?></div>
      <div class="stat-meta">dari pengembalian dikonfirmasi</div>
    </div>
  </div>

  <div class="cards-grid">
    <div class="card">
      <div class="card-header"><h3>Daftar Alat Tersedia</h3></div>
      <div class="card-body">
        <p class="card-text">Lihat alat yang dapat dipinjam beserta stoknya.</p>
        <a href="<?= base_url('borrow.php') ?>" class="btn btn-primary">Ajukan Peminjaman</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Riwayat Peminjaman Saya</h3></div>
      <div class="card-body">
        <p class="card-text">Lihat status peminjaman: menunggu persetujuan, disetujui, dikembalikan sebagian, dikembalikan, atau ditolak.</p>
        <a href="<?= base_url('my_borrowings.php') ?>" class="btn btn-primary">Lihat Riwayat</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Ajukan Pengembalian</h3></div>
      <div class="card-body">
        <p class="card-text">Kembalikan alat parsial atau penuh dari peminjaman yang disetujui. Status pengembalian: menunggu konfirmasi.</p>
        <a href="<?= base_url('return_request.php') ?>" class="btn btn-primary">Ajukan Pengembalian</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Riwayat Pengembalian Saya</h3></div>
      <div class="card-body">
        <p class="card-text">Lihat riwayat pengembalian: menunggu konfirmasi, dikonfirmasi, atau ditolak — beserta detail denda dan tanggal kembali.</p>
        <a href="<?= base_url('my_returns.php') ?>" class="btn btn-primary">Lihat Riwayat</a>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>