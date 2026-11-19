<?php
// ============================================================
// PINJAMIN — Riwayat Pengembalian Saya (Peminjam)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

$uid = current_user()['id'];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Optional filter by status
$status_filter = $_GET['status'] ?? '';
$valid_statuses = ['pending', 'confirmed', 'rejected'];
if ($status_filter && !in_array($status_filter, $valid_statuses, true)) {
    $status_filter = '';
}

try {
    $pdo = get_pdo();

    // Build WHERE clause
    $where = "WHERE r.requested_by = ?";
    $params = [$uid];
    if ($status_filter) {
        $where .= " AND r.status = ?";
        $params[] = $status_filter;
    }

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns r $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get returns with borrowing and tool info
    $stmt = $pdo->prepare("
        SELECT r.id, r.quantity, r.status, r.created_at, r.return_date, r.processed_by,
               r.fine_amount,
               b.borrow_date, b.due_date,
               t.code, t.name as tool_name, c.name as category_name,
               u.name as processed_by_name
        FROM returns r
        JOIN borrowings b ON b.id = r.borrowing_id
        JOIN tools t ON t.id = b.tool_id
        JOIN categories c ON c.id = t.category_id
        LEFT JOIN users u ON u.id = r.processed_by
        $where
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $stmt->execute($params);
    $returns = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("My returns error: " . $e->getMessage());
    $returns = [];
    $total = 0;
    $total_pages = 1;
}
$pageTitle = 'Riwayat Pengembalian';
$currentPage = 'my_returns.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Riwayat Pengembalian Saya</h1>
    <div class="page-actions">
      <a href="<?= base_url('return_request.php') ?>" class="btn btn-primary">Ajukan Kembali</a>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Pengembalian (<?= $total ?> total)</h3>
      <form method="GET" class="filter-form">
        <label class="sr-only" for="status">Filter status</label>
        <select class="form-select" id="status" name="status" onchange="this.form.submit()">
          <option value="">Semua Status</option>
          <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Menunggu Konfirmasi</option>
          <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Dikonfirmasi</option>
          <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
        </select>
        <?php if ($page > 1): ?>
          <input type="hidden" name="page" value="<?= $page ?>">
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($returns)): ?>
      <div class="empty-state">
        <h3>Belum ada riwayat pengembalian</h3>
        <p>Klik "Ajukan Kembali" untuk memulai</p>
        <a href="<?= base_url('return_request.php') ?>" class="btn btn-primary">Ajukan Kembali</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Alat</th>
              <th scope="col">Jumlah</th>
              <th scope="col">Tgl Minta</th>
              <th scope="col">Status</th>
              <th scope="col">Tgl Kembali</th>
              <th scope="col">Diproses Oleh</th>
              <th scope="col">Denda</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($returns as $r):
              $status_class = [
                'pending' => 'badge-pending',
                'confirmed' => 'badge-confirmed',
                'rejected' => 'badge-rejected',
              ];
              $status_label = [
                'pending' => 'Menunggu Konfirmasi',
                'confirmed' => 'Dikonfirmasi',
                'rejected' => 'Ditolak',
              ];
            ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td>
                <strong><?= e($r['code']) ?> - <?= e($r['tool_name']) ?></strong>
                <small><?= e($r['category_name']) ?></small>
              </td>
              <td><?= $r['quantity'] ?></td>
              <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
              <td><span class="badge <?= $status_class[$r['status']] ?? '' ?>"><?= $status_label[$r['status']] ?? $r['status'] ?></span></td>
              <td><?= $r['return_date'] ? date('d/m/Y', strtotime($r['return_date'])) : '-' ?></td>
              <td><?= $r['processed_by_name'] ? e($r['processed_by_name']) : '-' ?></td>
              <td><?= $r['fine_amount'] > 0 ? '<span class="badge badge-danger">Rp ' . number_format($r['fine_amount'], 0, ',', '.') . '</span>' : '-' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($total_pages > 1): ?>
      <div class="card-body">
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>">&laquo; Sebelumnya</a>
          <?php endif; ?>
          <?php
          $start = max(1, $page - 2);
          $end = min($total_pages, $page + 2);
          for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i == $page): ?>
              <span class="current" aria-current="page"><?= $i ?></span>
            <?php else: ?>
              <a href="?page=<?= $i ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>">Selanjutnya &raquo;</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>