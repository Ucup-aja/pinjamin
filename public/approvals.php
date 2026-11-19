<?php
// ============================================================
// PINJAMIN — Persetujuan Peminjaman (Petugas)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('petugas');

$errors = [];
$success = false;

$petugas_id = current_user()['id'];

// Handle POST - approve or reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    } else {
        $action = $_POST['action'] ?? '';
        $borrowing_id = (int)($_POST['borrowing_id'] ?? 0);

        if ($borrowing_id <= 0) {
            $errors[] = 'ID peminjaman tidak valid';
        } else {
            try {
                $pdo = get_pdo();

                if ($action === 'approve') {
                    // Panggil stored procedure
                    $stmt = $pdo->prepare("CALL sp_approve_borrowing(?, ?)");
                    $stmt->execute([$borrowing_id, $petugas_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor(); // Tutup cursor

                    if ($result && $result['success'] == '1') {
                        $success = true;
                        log_activity('BORROW_APPROVE', "Setujui peminjaman ID $borrowing_id: {$result['info']}");
                    } else {
                        $errors[] = $result['info'] ?? 'Persetujuan gagal';
                    }
                } elseif ($action === 'reject') {
                    // Atomic reject - hanya jika status masih pending
                    $stmt = $pdo->prepare("
                        UPDATE borrowings
                        SET status = 'rejected'
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$borrowing_id]);

                    if ($stmt->rowCount() > 0) {
                        $success = true;
                        log_activity('BORROW_REJECT', "Tolak peminjaman ID $borrowing_id");
                    } else {
                        // Cek status saat ini
                        $stmt = $pdo->prepare("SELECT status FROM borrowings WHERE id = ?");
                        $stmt->execute([$borrowing_id]);
                        $current = $stmt->fetch();
                        $errors[] = $current
                            ? "Peminjaman tidak bisa ditolak (status: {$current['status']})"
                            : 'Peminjaman tidak ditemukan';
                    }
                }
            } catch (PDOException $e) {
                error_log("Approval error: " . $e->getMessage());
                $errors[] = 'Terjadi kesalahan database';
            }
        }
    }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Optional filter
$status_filter = $_GET['status'] ?? 'pending';
$valid_statuses = ['pending', 'approved', 'partially_returned', 'returned', 'rejected'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'pending';
}

try {
    $pdo = get_pdo();

    // Build WHERE clause
    $where = "WHERE b.status = ?";
    $params = [$status_filter];

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings b $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get borrowings with user and tool info
    $stmt = $pdo->prepare("
        SELECT b.id, b.quantity, b.borrow_date, b.due_date, b.status, b.approved_at, b.created_at,
               u.name as user_name, u.username,
               t.code, t.name as tool_name, c.name as category_name
        FROM borrowings b
        JOIN users u ON u.id = b.user_id
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
    error_log("Approvals list error: " . $e->getMessage());
    $borrowings = [];
    $total = 0;
    $total_pages = 1;
}
?>
<?php
$pageTitle = 'Persetujuan Peminjaman';
$currentPage = 'approvals.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Persetujuan Peminjaman</h1>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Peminjaman (<?= $total ?> total)</h3>
      <form method="GET" class="filter-form">
        <label class="form-label" for="status-filter">Status</label>
        <select id="status-filter" name="status" class="form-select" onchange="this.form.submit()">
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

    <div class="table-responsive">
      <?php if (empty($borrowings)): ?>
        <div class="empty-state">
          <h3>Tidak ada data</h3>
          <p>Belum ada peminjaman dengan status ini</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Peminjam</th>
              <th scope="col">Alat</th>
              <th scope="col">Jumlah</th>
              <th scope="col">Tgl Pinjam</th>
              <th scope="col">Jatuh Tempo</th>
              <th scope="col">Status</th>
              <th scope="col">Disetujui</th>
              <th scope="col">Aksi</th>
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
                <strong><?= e($b['user_name']) ?></strong>
                <small>@<?= e($b['username']) ?></small>
              </td>
              <td>
                <strong><?= e($b['code']) ?> - <?= e($b['tool_name']) ?></strong>
                <small><?= e($b['category_name']) ?></small>
              </td>
              <td><?= $b['quantity'] ?></td>
              <td><?= date('d/m/Y', strtotime($b['borrow_date'])) ?></td>
              <td><?= date('d/m/Y', strtotime($b['due_date'])) ?></td>
              <td><span class="badge <?= $status_class[$b['status']] ?? '' ?>"><?= $status_label[$b['status']] ?? $b['status'] ?></span></td>
              <td><?= $b['approved_at'] ? date('d/m/Y H:i', strtotime($b['approved_at'])) : '-' ?></td>
              <td>
                <div class="action-btns">
                  <?php if ($b['status'] === 'pending'): ?>
                    <form method="POST" onsubmit="return confirm('Setujui peminjaman ini? Stok akan berkurang otomatis.');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="borrowing_id" value="<?= $b['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Setujui</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Tolak peminjaman ini? Stok tidak akan berubah.');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="borrowing_id" value="<?= $b['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Tolak</button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
      <div class="card-body">
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($status_filter) ?>" aria-label="Sebelumnya">&laquo;</a>
          <?php endif; ?>
          <?php
          $start = max(1, $page - 2);
          $end = min($total_pages, $page + 2);
          for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i == $page): ?>
              <span class="current" aria-current="page"><?= $i ?></span>
            <?php else: ?>
              <a href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($status_filter) ?>" aria-label="Selanjutnya">&raquo;</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
