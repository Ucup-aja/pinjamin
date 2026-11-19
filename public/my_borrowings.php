<?php
// ============================================================
// PINJAMIN — Riwayat Peminjaman Saya (Peminjam)
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
$valid_statuses = ['pending', 'approved', 'partially_returned', 'returned', 'rejected'];
if ($status_filter && !in_array($status_filter, $valid_statuses, true)) {
    $status_filter = '';
}

try {
    $pdo = get_pdo();

    // Build WHERE clause
    $where = "WHERE b.user_id = ?";
    $params = [$uid];
    if ($status_filter) {
        $where .= " AND b.status = ?";
        $params[] = $status_filter;
    }

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings b $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get borrowings with tool info
    $stmt = $pdo->prepare("
        SELECT b.id, b.quantity, b.borrow_date, b.due_date, b.status, b.approved_at, b.created_at,
               t.code, t.name as tool_name, c.name as category_name
        FROM borrowings b
        JOIN tools t ON t.id = b.tool_id
        JOIN categories c ON c.id = t.category_id
        $where
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $stmt->execute($params);
    $borrowings = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("My borrowings error: " . $e->getMessage());
    $borrowings = [];
    $total = 0;
    $total_pages = 1;
}
$pageTitle = 'Riwayat Peminjaman';
$currentPage = 'my_borrowings.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Riwayat Peminjaman Saya</h1>
    <div class="page-actions">
      <a href="<?= base_url('borrow.php') ?>" class="btn btn-primary">Ajukan Pinjam Baru</a>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Peminjaman (<?= $total ?> total)</h3>
      <form method="GET" class="filter-form">
        <label class="sr-only" for="status">Filter status</label>
        <select class="form-select" id="status" name="status" onchange="this.form.submit()">
          <option value="">Semua Status</option>
          <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
          <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
          <option value="partially_returned" <?= $status_filter === 'partially_returned' ? 'selected' : '' ?>>Dikembalikan Sebagian</option>
          <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Dikembalikan</option>
          <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
        </select>
        <?php if ($page > 1): ?>
          <input type="hidden" name="page" value="<?= $page ?>">
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($borrowings)): ?>
      <div class="empty-state">
        <h3>Belum ada riwayat peminjaman</h3>
        <p>Klik "Ajukan Pinjam Baru" untuk memulai</p>
        <a href="<?= base_url('borrow.php') ?>" class="btn btn-primary">Ajukan Pinjam Baru</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Alat</th>
              <th scope="col">Jumlah</th>
              <th scope="col">Tanggal Pinjam</th>
              <th scope="col">Jatuh Tempo</th>
              <th scope="col">Status</th>
              <th scope="col">Disetujui</th>
              <th scope="col">Diajukan</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($borrowings as $b):
              $status_class = [
                'pending' => 'badge-pending',
                'approved' => 'badge-approved',
                'partially_returned' => 'badge-partial',
                'returned' => 'badge-returned',
                'rejected' => 'badge-rejected',
              ];
              $status_label = [
                'pending' => 'Menunggu Persetujuan',
                'approved' => 'Disetujui',
                'partially_returned' => 'Dikembalikan Sebagian',
                'returned' => 'Dikembalikan',
                'rejected' => 'Ditolak',
              ];
            ?>
            <tr>
              <td><?= $b['id'] ?></td>
              <td>
                <strong><?= e($b['code']) ?> - <?= e($b['tool_name']) ?></strong>
                <small><?= e($b['category_name']) ?></small>
              </td>
              <td><?= $b['quantity'] ?></td>
              <td><?= date('d/m/Y', strtotime($b['borrow_date'])) ?></td>
              <td><?= date('d/m/Y', strtotime($b['due_date'])) ?></td>
              <td><span class="badge <?= $status_class[$b['status']] ?? '' ?>"><?= $status_label[$b['status']] ?? $b['status'] ?></span></td>
              <td><?= $b['approved_at'] ? date('d/m/Y H:i', strtotime($b['approved_at'])) : '-' ?></td>
              <td><?= date('d/m/Y H:i', strtotime($b['created_at'])) ?></td>
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