<?php
// ============================================================
// PINJAMIN — Kelola Pengembalian (Admin)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$errors = [];
$success = false;

$pdo = get_pdo();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        try {
            if ($action === 'add') {
                // Admin membuat pengajuan pengembalian atas nama peminjam
                $borrowing_id = (int)($_POST['borrowing_id'] ?? 0);
                $requested_by = (int)($_POST['requested_by'] ?? 0);
                $quantity = (int)($_POST['quantity'] ?? 0);

                if ($borrowing_id <= 0 || $requested_by <= 0 || $quantity <= 0) {
                    $errors[] = 'Semua field wajib diisi';
                } else {
                    // Validasi borrowing exists dan status valid
                    $stmt = $pdo->prepare("
                        SELECT b.id, b.user_id, b.tool_id, b.quantity, b.status,
                               COALESCE(SUM(CASE WHEN r.status = 'confirmed' THEN r.quantity ELSE 0 END), 0) as returned_qty
                        FROM borrowings b
                        LEFT JOIN returns r ON r.borrowing_id = b.id
                        WHERE b.id = ?
                        GROUP BY b.id
                    ");
                    $stmt->execute([$borrowing_id]);
                    $borrowing = $stmt->fetch();

                    if (!$borrowing) {
                        $errors[] = 'Peminjaman tidak ditemukan';
                    } elseif (!in_array($borrowing['status'], ['approved', 'partially_returned'], true)) {
                        $errors[] = 'Peminjaman tidak dalam status yang bisa dikembalikan (status: ' . $borrowing['status'] . ')';
                    } elseif ($borrowing['user_id'] != $requested_by) {
                        $errors[] = 'Peminjam tidak sesuai dengan peminjaman ini';
                    } else {
                        $max_returnable = $borrowing['quantity'] - $borrowing['returned_qty'];
                        if ($quantity > $max_returnable) {
                            $errors[] = "Jumlah melebihi yang dapat dikembalikan. Maksimum: $max_returnable";
                        } else {
                            // Insert return request (trigger will validate requested_by)
                            $stmt = $pdo->prepare("
                                INSERT INTO returns (borrowing_id, requested_by, quantity, status)
                                VALUES (?, ?, ?, 'pending')
                            ");
                            $stmt->execute([$borrowing_id, $requested_by, $quantity]);
                            $new_id = $pdo->lastInsertId();
                            log_activity('ADMIN_RETURN_ADD', "Admin buat pengajuan kembali ID $new_id untuk borrowing $borrowing_id, user $requested_by x$quantity");
                            $success = true;
                        }
                    }
                }

            } elseif ($action === 'delete') {
                // Delete hanya jika pending atau rejected
                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } else {
                    $stmt = $pdo->prepare("SELECT status FROM returns WHERE id = ?");
                    $stmt->execute([$id]);
                    $current = $stmt->fetch();
                    if (!$current) {
                        $errors[] = 'Pengembalian tidak ditemukan';
                    } elseif (!in_array($current['status'], ['pending', 'rejected'], true)) {
                        $errors[] = 'Hanya pengajuan pending atau rejected yang dapat dibatalkan/dihapus';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM returns WHERE id = ?");
                        $stmt->execute([$id]);
                        log_activity('ADMIN_RETURN_DELETE', "Admin hapus/batal pengajuan kembali ID $id");
                        $success = true;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Admin returns error: " . $e->getMessage());
            // Check for trigger error
            if (strpos($e->getMessage(), 'requested_by harus sama') !== false) {
                $errors[] = 'Peminjam tidak sesuai dengan peminjaman ini (validasi trigger)';
            } else {
                $errors[] = 'Terjadi kesalahan database';
            }
        }
    }
}

// Filter & pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$valid_statuses = ['pending', 'confirmed', 'rejected'];

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.username LIKE ? OR t.code LIKE ? OR t.name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($status_filter && in_array($status_filter, $valid_statuses, true)) {
    $where .= " AND r.status = ?";
    $params[] = $status_filter;
}

// Count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM returns r
    JOIN borrowings b ON b.id = r.borrowing_id
    JOIN users u ON u.id = b.user_id
    JOIN tools t ON t.id = b.tool_id
    $where
");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Data
$stmt = $pdo->prepare("
    SELECT r.id, r.quantity, r.status, r.created_at, r.return_date, r.fine_amount, r.processed_by,
           b.id as borrowing_id, b.borrow_date, b.due_date, b.quantity as borrow_qty, b.status as borrow_status,
           u.id as user_id, u.name as user_name, u.username,
           t.id as tool_id, t.code, t.name as tool_name, c.name as category_name,
           proc.name as processed_by_name
    FROM returns r
    JOIN borrowings b ON b.id = r.borrowing_id
    JOIN users u ON u.id = b.user_id
    JOIN tools t ON t.id = b.tool_id
    JOIN categories c ON c.id = t.category_id
    LEFT JOIN users proc ON proc.id = r.processed_by
    $where
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $per_page;
$params[] = $offset;
$stmt->execute($params);
$returns = $stmt->fetchAll();

// Get borrowings (approved/partially_returned) for add modal
$stmt = $pdo->query("
    SELECT b.id, b.user_id, b.tool_id, b.quantity, b.status,
           u.name as user_name, u.username,
           t.code, t.name as tool_name,
           COALESCE(SUM(CASE WHEN r.status = 'confirmed' THEN r.quantity ELSE 0 END), 0) as returned_qty
    FROM borrowings b
    JOIN users u ON u.id = b.user_id
    JOIN tools t ON t.id = b.tool_id
    LEFT JOIN returns r ON r.borrowing_id = b.id
    WHERE b.status IN ('approved', 'partially_returned')
    GROUP BY b.id
    ORDER BY b.due_date ASC
");
$borrowings_list = $stmt->fetchAll();

// Compute remaining for each
foreach ($borrowings_list as &$b) {
    $b['remaining'] = $b['quantity'] - $b['returned_qty'];
}
unset($b);

$status_label = ['pending' => 'Menunggu Konfirmasi', 'confirmed' => 'Dikonfirmasi', 'rejected' => 'Ditolak'];
$status_class = ['pending' => 'badge-pending', 'confirmed' => 'badge-confirmed', 'rejected' => 'badge-rejected'];

$pageTitle = 'Kelola Pengembalian';
$currentPage = 'admin_returns.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Kelola Pengembalian</h1>
    <div class="page-actions">
      <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Ajukan Pengembalian</button>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card mb-6">
    <div class="card-header">
      <h3>Daftar Pengembalian (<?= $total ?> total)</h3>
      <form method="GET" class="filter-form">
        <input type="text" class="form-input" name="search" placeholder="Cari nama, username, kode, nama alat..." value="<?= e($search) ?>" aria-label="Cari pengembalian">
        <select class="form-select" name="status" aria-label="Filter status">
          <option value="">Semua Status</option>
          <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Menunggu Konfirmasi</option>
          <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Dikonfirmasi</option>
          <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($search || $status_filter): ?>
          <a href="<?= base_url('admin_returns.php') ?>" class="btn btn-outline btn-sm">Reset</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="table-responsive">
      <?php if (empty($returns)): ?>
        <div class="empty-state">
          <h3>Tidak ada data</h3>
          <p>Coba ubah filter atau tambah pengajuan pengembalian</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Peminjam</th>
              <th scope="col">Alat</th>
              <th scope="col">Qty</th>
              <th scope="col">Tgl Minta</th>
              <th scope="col">Status</th>
              <th scope="col">Tgl Kembali</th>
              <th scope="col">Dikonfirmasi Oleh</th>
              <th scope="col">Denda</th>
              <th scope="col">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($returns as $r):
              $can_delete = in_array($r['status'], ['pending', 'rejected']);
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
              <td><span class="badge <?= $status_class[$r['status']] ?? '' ?>"><?= $status_label[$r['status']] ?? $r['status'] ?></span></td>
              <td>
                <?= $r['return_date'] ? date('d/m/Y', strtotime($r['return_date'])) : '-' ?>
                <?php if ($is_overdue): ?><span class="badge badge-danger">TERLAMBAT</span><?php endif; ?>
              </td>
              <td><?= $r['processed_by_name'] ? e($r['processed_by_name']) : '-' ?></td>
              <td><?= $r['fine_amount'] > 0 ? '<span class="badge badge-danger">Rp ' . number_format($r['fine_amount'], 0, ',', '.') . '</span>' : '-' ?></td>
              <td>
                <div class="action-btns">
                  <?php if ($can_delete): ?>
                    <form method="POST" onsubmit="return confirm('Hapus pengajuan pengembalian ini?');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $r['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Batal</button>
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
          <?php
          $base_params = "";
          if ($search) $base_params .= "search=" . urlencode($search) . "&";
          if ($status_filter) $base_params .= "status=" . urlencode($status_filter) . "&";
          ?>
          <?php if ($page > 1): ?>
            <a href="?<?= $base_params ?>page=<?= $page - 1 ?>">&laquo; Sebelumnya</a>
          <?php endif; ?>
          <?php
          $start = max(1, $page - 2);
          $end = min($total_pages, $page + 2);
          for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i == $page): ?>
              <span class="current"><?= $i ?></span>
            <?php else: ?>
              <a href="?<?= $base_params ?>page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <a href="?<?= $base_params ?>page=<?= $page + 1 ?>">Selanjutnya &raquo;</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Catatan</h3>
    </div>
    <div class="card-body text-muted">
      <ul>
        <li>Admin dapat membuat pengajuan pengembalian atas nama peminjam (validasi trigger: <code>requested_by</code> harus sama dengan <code>borrowings.user_id</code>).</li>
        <li>Hanya pengajuan <strong>pending</strong> atau <strong>rejected</strong> yang dapat dibatalkan/dihapus.</li>
        <li>Pengembalian <strong>confirmed</strong> tidak dapat diubah/hapus (menggunakan <code>sp_confirm_return</code> via Petugas).</li>
        <li>Konfirmasi pengembalian tetap dilakukan Petugas melalui halaman <a href="<?= base_url('returns.php') ?>">Konfirmasi Kembali</a>.</li>
      </ul>
    </div>
  </div>
</main>

<!-- Add Modal -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title">Ajukan Pengembalian (Admin)</h2>
      <button type="button" class="modal-close" onclick="closeModal('addModal')" aria-label="Tutup">&times;</button>
    </div>
    <form method="POST" id="addForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
          <label class="form-label" for="add_borrowing_id">Peminjaman <span class="required">*</span></label>
          <select class="form-select" name="borrowing_id" id="add_borrowing_id" required onchange="updateReturnForm()">
            <option value="">-- Pilih Peminjaman --</option>
            <?php foreach ($borrowings_list as $b): ?>
              <option value="<?= $b['id'] ?>"
                data-user-id="<?= $b['user_id'] ?>"
                data-user-name="<?= e($b['user_name']) ?> (@<?= e($b['username']) ?>)"
                data-tool="<?= e($b['code']) ?> - <?= e($b['tool_name']) ?>"
                data-qty="<?= $b['quantity'] ?>"
                data-returned="<?= $b['returned_qty'] ?>"
                data-remaining="<?= $b['remaining'] ?>"
                data-status="<?= $b['status'] ?>">
                <?= e($b['code']) ?> - <?= e($b['tool_name']) ?> (<?= e($b['user_name']) ?>) - Sisa: <?= $b['remaining'] ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="add_requested_by_name">Peminjam (Otomatis)</label>
            <input class="form-input" type="text" id="add_requested_by_name" readonly>
            <input type="hidden" name="requested_by" id="add_requested_by">
          </div>
          <div class="form-group">
            <label class="form-label" for="add_tool_info">Alat (Otomatis)</label>
            <input class="form-input" type="text" id="add_tool_info" readonly>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="add_quantity">Jumlah Kembali <span class="required">*</span></label>
            <input class="form-input" type="number" name="quantity" id="add_quantity" required min="1" value="1">
            <div id="add_remaining_info" class="form-hint"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Batal</button>
        <button type="submit" class="btn btn-primary">Ajukan</button>
      </div>
    </form>
  </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('addForm').reset();
        document.getElementById('add_requested_by_name').value = '';
        document.getElementById('add_requested_by').value = '';
        document.getElementById('add_tool_info').value = '';
        document.getElementById('add_quantity').value = 1;
        document.getElementById('add_remaining_info').textContent = '';
        document.getElementById('addModal').classList.add('show');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    function updateReturnForm() {
        const select = document.getElementById('add_borrowing_id');
        const option = select.options[select.selectedIndex];
        const userId = option.dataset.userId;
        const userName = option.dataset.userName;
        const tool = option.dataset.tool;
        const remaining = parseInt(option.dataset.remaining);

        document.getElementById('add_requested_by').value = userId || '';
        document.getElementById('add_requested_by_name').value = userName || '';
        document.getElementById('add_tool_info').value = tool || '';

        const qtyInput = document.getElementById('add_quantity');
        const infoDiv = document.getElementById('add_remaining_info');

        if (remaining > 0) {
            qtyInput.max = remaining;
            qtyInput.value = 1;
            infoDiv.textContent = `Maksimum yang bisa dikembalikan: ${remaining}`;
            infoDiv.classList.toggle('low', remaining < 3);
        } else {
            qtyInput.max = 1;
            qtyInput.value = 1;
            infoDiv.textContent = 'Semua unit sudah dikembalikan';
            infoDiv.classList.add('low');
        }
    }

    // Close modal on outside click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal(modal.id);
        });
    });

    // Close modal on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(m => closeModal(m.id));
        }
    });
</script>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
