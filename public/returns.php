<?php
// ============================================================
// PINJAMIN — Konfirmasi Pengembalian (Petugas)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('petugas');

$errors = [];
$success = false;

$petugas_id = current_user()['id'];

// Handle POST - confirm or reject return
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    } else {
        $action = $_POST['action'] ?? '';
        $return_id = (int)($_POST['return_id'] ?? 0);

        if ($return_id <= 0) {
            $errors[] = 'ID pengembalian tidak valid';
        } else {
            try {
                $pdo = get_pdo();

                if ($action === 'confirm') {
                    // Panggil stored procedure sp_confirm_return (2 params only)
                    $stmt = $pdo->prepare("CALL sp_confirm_return(?, ?)");
                    $stmt->execute([$return_id, $petugas_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor(); // Tutup cursor

                    if ($result && $result['success'] == '1') {
                        $success = true;
                        log_activity('RETURN_CONFIRM', "Konfirmasi pengembalian ID $return_id: {$result['info']}");
                    } else {
                        $errors[] = $result['info'] ?? 'Konfirmasi gagal';
                    }
                } elseif ($action === 'reject') {
                    // Atomic reject - hanya jika status masih pending
                    $stmt = $pdo->prepare("
                        UPDATE returns
                        SET status = 'rejected', processed_by = ?
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$petugas_id, $return_id]);

                    if ($stmt->rowCount() > 0) {
                        $success = true;
                        log_activity('RETURN_REJECT', "Tolak pengembalian ID $return_id");
                    } else {
                        // Cek status saat ini
                        $stmt = $pdo->prepare("SELECT status FROM returns WHERE id = ?");
                        $stmt->execute([$return_id]);
                        $current = $stmt->fetch();
                        $errors[] = $current
                            ? "Pengembalian tidak bisa ditolak (status: {$current['status']})"
                            : 'Pengembalian tidak ditemukan';
                    }
                }
            } catch (PDOException $e) {
                error_log("Return confirmation error: " . $e->getMessage());
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
$valid_statuses = ['pending', 'confirmed', 'rejected'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'pending';
}

try {
    $pdo = get_pdo();

    // Build WHERE clause
    $where = "WHERE r.status = ?";
    $params = [$status_filter];

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns r $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get returns with user, borrowing, and tool info
    $stmt = $pdo->prepare("
        SELECT r.id, r.quantity, r.status, r.created_at, r.return_date, r.processed_by,
               r.fine_amount,
               b.id as borrowing_id, b.borrow_date, b.due_date, b.quantity as borrow_qty,
               u.name as user_name, u.username,
               t.code, t.name as tool_name, c.name as category_name,
               u2.name as processed_by_name
        FROM returns r
        JOIN borrowings b ON b.id = r.borrowing_id
        JOIN users u ON u.id = b.user_id
        JOIN tools t ON t.id = b.tool_id
        JOIN categories c ON c.id = t.category_id
        LEFT JOIN users u2 ON u2.id = r.processed_by
        $where
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $stmt->execute($params);
    $returns = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Returns list error: " . $e->getMessage());
    $returns = [];
    $total = 0;
    $total_pages = 1;
}
?>
<?php
$pageTitle = 'Konfirmasi Pengembalian';
$currentPage = 'returns.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Konfirmasi Pengembalian</h1>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Pengembalian (<?= $total ?> total)</h3>
      <form method="GET" class="filter-form">
        <label class="form-label" for="status-filter">Status</label>
        <select id="status-filter" name="status" class="form-select" onchange="this.form.submit()">
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

    <div class="table-responsive">
      <?php if (empty($returns)): ?>
        <div class="empty-state">
          <h3>Tidak ada data</h3>
          <p>Belum ada pengembalian dengan status ini</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Peminjam</th>
              <th scope="col">Alat</th>
              <th scope="col">Jumlah</th>
              <th scope="col">Tgl Minta</th>
              <th scope="col">Jatuh Tempo</th>
              <th scope="col">Status</th>
              <th scope="col">Tgl Kembali</th>
              <th scope="col">Diproses</th>
              <th scope="col">Denda</th>
              <th scope="col">Aksi</th>
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
              $is_overdue = $r['status'] !== 'confirmed' && date('Y-m-d') > $r['due_date'];
            ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td>
                <strong><?= e($r['user_name']) ?></strong>
                <small>@<?= e($r['username']) ?></small>
              </td>
              <td>
                <strong><?= e($r['code']) ?> - <?= e($r['tool_name']) ?></strong>
                <small><?= e($r['category_name']) ?></small>
              </td>
              <td><?= $r['quantity'] ?> / <?= $r['borrow_qty'] ?></td>
              <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
              <td>
                <?= date('d/m/Y', strtotime($r['due_date'])) ?>
                <?php if ($is_overdue): ?> <span class="badge badge-danger">TERLAMBAT</span><?php endif; ?>
              </td>
              <td><span class="badge <?= $status_class[$r['status']] ?? '' ?>"><?= $status_label[$r['status']] ?? $r['status'] ?></span></td>
              <td><?= $r['return_date'] ? date('d/m/Y', strtotime($r['return_date'])) : '-' ?></td>
              <td><?= $r['processed_by_name'] ? e($r['processed_by_name']) : '-' ?></td>
              <td><?= $r['fine_amount'] > 0 ? '<span class="badge badge-danger">Rp ' . number_format($r['fine_amount'], 0, ',', '.') . '</span>' : '-' ?></td>
              <td>
                <div class="action-btns">
                  <?php if ($r['status'] === 'pending'): ?>
                    <form method="POST" onsubmit="return confirm('Konfirmasi pengembalian ini? Stok akan bertambah dan denda dihitung otomatis.');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="confirm">
                      <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Konfirmasi</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Tolak pengembalian ini? Status jadi ditolak.');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
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
