<?php
// ============================================================
// PINJAMIN — Dashboard Petugas
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('petugas');

$user = current_user();
$flash = get_flash();

// Fetch dashboard stats
try {
    $pdo = get_pdo();
    
    $stat_pending = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status = 'pending'")->fetchColumn();
    $stat_return_pending = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'pending'")->fetchColumn();
    $stat_approved_today = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('approved','partially_returned','returned') AND DATE(approved_at) = CURDATE()")->fetchColumn();
    $stat_confirmed_today = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'confirmed' AND DATE(return_date) = CURDATE()")->fetchColumn();
    $stat_rejected_today = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'rejected' AND DATE(return_date) = CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    error_log("Dashboard petugas stats error: " . $e->getMessage());
    $stat_pending = $stat_return_pending = $stat_approved_today = $stat_confirmed_today = $stat_rejected_today = 0;
}

$pageTitle = 'Dashboard Petugas';
$currentPage = 'dashboard_petugas.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Dashboard Petugas</h1>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <p class="text-muted mb-6">Tinjau pengajuan, konfirmasi pengembalian, dan lihat laporan.</p>

  <div class="stats-grid mb-6">
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
      <div class="stat-label">Disetujui Hari Ini</div>
      <div class="stat-value"><?= $stat_approved_today ?></div>
      <div class="stat-meta">peminjaman</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Dikonfirmasi Hari Ini</div>
      <div class="stat-value"><?= $stat_confirmed_today ?></div>
      <div class="stat-meta">pengembalian</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Ditolak Hari Ini</div>
      <div class="stat-value"><?= $stat_rejected_today ?></div>
      <div class="stat-meta">pengembalian</div>
    </div>
  </div>

  <div class="cards-grid">
    <div class="card">
      <div class="card-header"><h3>Setujui Peminjaman</h3></div>
      <div class="card-body">
        <p class="card-text">Tinjau pengajuan peminjaman alat dan tentukan persetujuannya.</p>
        <a href="<?= base_url('approvals.php') ?>" class="btn btn-primary">Lihat Pengajuan</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Konfirmasi Pengembalian</h3></div>
      <div class="card-body">
        <p class="card-text">Periksa pengembalian alat, konfirmasi penerimaan, dan pantau denda keterlambatan.</p>
        <a href="<?= base_url('returns.php') ?>" class="btn btn-primary">Lihat Pengembalian</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Cetak Laporan</h3></div>
      <div class="card-body">
        <p class="card-text">Lihat dan cetak laporan peminjaman, pengembalian, serta rekap denda.</p>
        <a href="<?= base_url('reports.php') ?>" class="btn btn-secondary">Lihat Laporan</a>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>