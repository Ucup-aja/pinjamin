<?php
// ============================================================
// PINJAMIN — Kelola Peminjaman (Admin)
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
                // Admin membuat pengajuan atas nama peminjam
                $user_id = (int)($_POST['user_id'] ?? 0);
                $tool_id = (int)($_POST['tool_id'] ?? 0);
                $quantity = (int)($_POST['quantity'] ?? 0);
                $borrow_date = $_POST['borrow_date'] ?? '';
                $due_date = $_POST['due_date'] ?? '';

                if ($user_id <= 0 || $tool_id <= 0 || $quantity <= 0 || $borrow_date === '' || $due_date === '') {
                    $errors[] = 'Semua field wajib diisi';
                } elseif ($due_date < $borrow_date) {
                    $errors[] = 'Tanggal jatuh tempo tidak boleh lebih awal dari tanggal peminjaman';
                } else {
                    // Validasi user exists & is peminjam
                    $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                    if (!$user) {
                        $errors[] = 'Peminjam tidak ditemukan';
                    } elseif ($user['role'] !== 'peminjam') {
                        $errors[] = 'Hanya role peminjam yang bisa meminjam';
                    } elseif (!$user['is_active']) {
                        $errors[] = 'Akun peminjam tidak aktif';
                    } else {
                        // Validasi tool & stock
                        $stmt = $pdo->prepare("SELECT id, quantity_available FROM tools WHERE id = ?");
                        $stmt->execute([$tool_id]);
                        $tool = $stmt->fetch();
                        if (!$tool) {
                            $errors[] = 'Alat tidak ditemukan';
                        } elseif ($tool['quantity_available'] < $quantity) {
                            $errors[] = "Stok tidak mencukupi. Tersedia: {$tool['quantity_available']}";
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO borrowings (user_id, tool_id, quantity, borrow_date, due_date, status)
                                VALUES (?, ?, ?, ?, ?, 'pending')
                            ");
                            $stmt->execute([$user_id, $tool_id, $quantity, $borrow_date, $due_date]);
                            $new_id = $pdo->lastInsertId();
                            log_activity('ADMIN_BORROW_ADD', "Admin buat pengajuan pinjam ID $new_id untuk user $user_id, tool $tool_id x$quantity");
                            $success = true;
                        }
                    }
                }

            } elseif ($action === 'edit') {
                // Edit hanya jika status pending
                $user_id = (int)($_POST['user_id'] ?? 0);
                $tool_id = (int)($_POST['tool_id'] ?? 0);
                $quantity = (int)($_POST['quantity'] ?? 0);
                $borrow_date = $_POST['borrow_date'] ?? '';
                $due_date = $_POST['due_date'] ?? '';

                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } else {
                    // Cek status current
                    $stmt = $pdo->prepare("SELECT status, tool_id, quantity FROM borrowings WHERE id = ?");
                    $stmt->execute([$id]);
                    $current = $stmt->fetch();
                    if (!$current) {
                        $errors[] = 'Peminjaman tidak ditemukan';
                    } elseif ($current['status'] !== 'pending') {
                        $errors[] = 'Hanya pengajuan pending yang dapat diedit';
                    } else {
                        if ($user_id <= 0 || $tool_id <= 0 || $quantity <= 0 || $borrow_date === '' || $due_date === '') {
                            $errors[] = 'Semua field wajib diisi';
                        } elseif ($due_date < $borrow_date) {
                            $errors[] = 'Tanggal jatuh tempo tidak boleh lebih awal dari tanggal peminjaman';
                        } else {
                            // Validasi user
                            $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
                            $stmt->execute([$user_id]);
                            $user = $stmt->fetch();
                            if (!$user || $user['role'] !== 'peminjam' || !$user['is_active']) {
                                $errors[] = 'Peminjam tidak valid';
                            } else {
                                // Validasi tool & stock (consider current quantity if same tool)
                                $stmt = $pdo->prepare("SELECT id, quantity_available FROM tools WHERE id = ?");
                                $stmt->execute([$tool_id]);
                                $tool = $stmt->fetch();
                                if (!$tool) {
                                    $errors[] = 'Alat tidak ditemukan';
                                } else {
                                    $available = $tool['quantity_available'];
                                    if ($tool_id == $current['tool_id']) {
                                        $available += $current['quantity']; // Add back current reservation
                                    }
                                    if ($available < $quantity) {
                                        $errors[] = "Stok tidak mencukupi. Tersedia: $available";
                                    } else {
                                        $stmt = $pdo->prepare("
                                            UPDATE borrowings
                                            SET user_id = ?, tool_id = ?, quantity = ?, borrow_date = ?, due_date = ?
                                            WHERE id = ?
                                        ");
                                        $stmt->execute([$user_id, $tool_id, $quantity, $borrow_date, $due_date, $id]);
                                        log_activity('ADMIN_BORROW_EDIT', "Admin edit pengajuan pinjam ID $id");
                                        $success = true;
                                    }
                                }
                            }
                        }
                    }
                }

            } elseif ($action === 'delete') {
                // Delete hanya jika pending/rejected dan tidak ada return confirmed
                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } else {
                    $stmt = $pdo->prepare("SELECT status FROM borrowings WHERE id = ?");
                    $stmt->execute([$id]);
                    $current = $stmt->fetch();
                    if (!$current) {
                        $errors[] = 'Peminjaman tidak ditemukan';
                    } elseif (!in_array($current['status'], ['pending', 'rejected'], true)) {
                        $errors[] = 'Hanya pengajuan pending atau rejected yang dapat dihapus';
                    } else {
                        // Cek apakah ada return confirmed
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns WHERE borrowing_id = ? AND status = 'confirmed'");
                        $stmt->execute([$id]);
                        $confirmed_returns = $stmt->fetchColumn();
                        if ($confirmed_returns > 0) {
                            $errors[] = 'Tidak dapat hapus: sudah ada pengembalian yang dikonfirmasi';
                        } else {
                            // Hapus returns pending/rejected terkait dulu (FK constraint)
                            $stmt = $pdo->prepare("DELETE FROM returns WHERE borrowing_id = ?");
                            $stmt->execute([$id]);

                            $stmt = $pdo->prepare("DELETE FROM borrowings WHERE id = ?");
                            $stmt->execute([$id]);
                            log_activity('ADMIN_BORROW_DELETE', "Admin hapus pengajuan pinjam ID $id");
                            $success = true;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Admin borrowings error: " . $e->getMessage());
            $errors[] = 'Terjadi kesalahan database';
        }
    }
}

// Filter & pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$valid_statuses = ['pending', 'approved', 'partially_returned', 'returned', 'rejected'];

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.username LIKE ? OR t.code LIKE ? OR t.name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($status_filter && in_array($status_filter, $valid_statuses, true)) {
    $where .= " AND b.status = ?";
    $params[] = $status_filter;
}

// Count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM borrowings b
    JOIN users u ON u.id = b.user_id
    JOIN tools t ON t.id = b.tool_id
    $where
");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Data
$stmt = $pdo->prepare("
    SELECT b.id, b.quantity, b.borrow_date, b.due_date, b.status, b.approved_at, b.created_at,
           u.id as user_id, u.name as user_name, u.username,
           t.id as tool_id, t.code, t.name as tool_name, c.name as category_name,
           ap.name as approved_by_name,
           COALESCE(SUM(CASE WHEN r.status = 'confirmed' THEN r.quantity ELSE 0 END), 0) as returned_qty
    FROM borrowings b
    JOIN users u ON u.id = b.user_id
    JOIN tools t ON t.id = b.tool_id
    JOIN categories c ON c.id = t.category_id
    LEFT JOIN users ap ON ap.id = b.approved_by
    LEFT JOIN returns r ON r.borrowing_id = b.id
    $where
    GROUP BY b.id
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $per_page;
$params[] = $offset;
$stmt->execute($params);
$borrowings = $stmt->fetchAll();

// Get users (peminjam) and tools for add/edit modal
$stmt = $pdo->query("SELECT id, name, username FROM users WHERE role = 'peminjam' AND is_active = 1 ORDER BY name");
$peminjam_list = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, code, name, quantity_available FROM tools WHERE quantity_available > 0 ORDER BY code");
$tools_list = $stmt->fetchAll();

$status_label = [
    'pending' => 'Menunggu Persetujuan', 'approved' => 'Disetujui', 'partially_returned' => 'Dikembalikan Sebagian',
    'returned' => 'Dikembalikan', 'rejected' => 'Ditolak'
];
$status_class = [
    'pending' => 'badge-pending', 'approved' => 'badge-approved', 'partially_returned' => 'badge-partial',
    'returned' => 'badge-returned', 'rejected' => 'badge-rejected'
];

$pageTitle = 'Kelola Peminjaman';
$currentPage = 'admin_borrowings.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Kelola Peminjaman</h1>
    <div class="page-actions">
      <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Tambah Pengajuan</button>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Peminjaman (<?= $total ?> total)</h3>
      <form method="GET" class="filter-form">
        <input type="text" class="form-input" name="search" placeholder="Cari nama, username, kode, nama alat..." value="<?= e($search) ?>" aria-label="Cari peminjaman">
        <select class="form-select" name="status" aria-label="Filter status">
          <option value="">Semua Status</option>
          <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
          <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
          <option value="partially_returned" <?= $status_filter === 'partially_returned' ? 'selected' : '' ?>>Dikembalikan Sebagian</option>
          <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Dikembalikan</option>
          <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($search || $status_filter): ?>
          <a href="<?= base_url('admin_borrowings.php') ?>" class="btn btn-outline btn-sm">Reset</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="table-responsive">
      <?php if (empty($borrowings)): ?>
        <div class="empty-state">
          <h3>Tidak ada data</h3>
          <p>Coba ubah filter atau tambah pengajuan baru</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Peminjam</th>
              <th scope="col">Alat</th>
              <th scope="col">Qty</th>
              <th scope="col">Tgl Pinjam</th>
              <th scope="col">Jatuh Tempo</th>
              <th scope="col">Status</th>
              <th scope="col">Disetujui</th>
              <th scope="col">Oleh</th>
              <th scope="col">Kembali</th>
              <th scope="col">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($borrowings as $b):
              $can_edit = $b['status'] === 'pending';
              $can_delete = in_array($b['status'], ['pending', 'rejected']) && $b['returned_qty'] == 0;
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
              <td><?= $b['approved_by_name'] ? e($b['approved_by_name']) : '-' ?></td>
              <td><?= $b['returned_qty'] > 0 ? $b['returned_qty'] . ' / ' . $b['quantity'] : '-' ?></td>
              <td>
                <div class="action-btns">
                  <?php if ($can_edit): ?>
                    <button type="button" class="btn btn-outline btn-sm" onclick="openEditModal(
                      <?= $b['id'] ?>,
                      <?= $b['user_id'] ?>,
                      <?= $b['tool_id'] ?>,
                      <?= $b['quantity'] ?>,
                      '<?= e($b['borrow_date']) ?>',
                      '<?= e($b['due_date']) ?>'
                    )">Edit</button>
                  <?php endif; ?>
                  <?php if ($can_delete): ?>
                    <form method="POST" onsubmit="return confirm('Hapus pengajuan ini? Data terkait akan ikut terhapus.');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $b['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                    </form>
                  <?php elseif (!$can_edit): ?>
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
</main>

<!-- Add Modal -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title">Tambah Pengajuan Peminjaman (Admin)</h2>
      <button type="button" class="modal-close" onclick="closeModal('addModal')" aria-label="Tutup">&times;</button>
    </div>
    <form method="POST" id="addForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="add_user_id">Peminjam <span class="required">*</span></label>
            <select class="form-select" name="user_id" id="add_user_id" required>
              <option value="">-- Pilih Peminjam --</option>
              <?php foreach ($peminjam_list as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (@<?= e($p['username']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="add_tool_id">Alat <span class="required">*</span></label>
            <select class="form-select" name="tool_id" id="add_tool_id" required onchange="updateStockInfo('add')">
              <option value="">-- Pilih Alat --</option>
              <?php foreach ($tools_list as $t): ?>
                <option value="<?= $t['id'] ?>" data-stock="<?= $t['quantity_available'] ?>"><?= e($t['code']) ?> - <?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="add_quantity">Jumlah <span class="required">*</span></label>
            <input class="form-input" type="number" name="quantity" id="add_quantity" required min="1" value="1">
            <div id="add_stock_info" class="form-hint"></div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="add_borrow_date">Tanggal Peminjaman <span class="required">*</span></label>
            <input class="form-input" type="date" name="borrow_date" id="add_borrow_date" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="add_due_date">Tanggal Jatuh Tempo <span class="required">*</span></label>
            <input class="form-input" type="date" name="due_date" id="add_due_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Batal</button>
        <button type="submit" class="btn btn-primary">Tambah</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title">Edit Pengajuan Peminjaman</h2>
      <button type="button" class="modal-close" onclick="closeModal('editModal')" aria-label="Tutup">&times;</button>
    </div>
    <form method="POST" id="editForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="edit_user_id">Peminjam <span class="required">*</span></label>
            <select class="form-select" name="user_id" id="edit_user_id" required>
              <option value="">-- Pilih Peminjam --</option>
              <?php foreach ($peminjam_list as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (@<?= e($p['username']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_tool_id">Alat <span class="required">*</span></label>
            <select class="form-select" name="tool_id" id="edit_tool_id" required onchange="updateStockInfo('edit')">
              <option value="">-- Pilih Alat --</option>
              <?php foreach ($tools_list as $t): ?>
                <option value="<?= $t['id'] ?>" data-stock="<?= $t['quantity_available'] ?>"><?= e($t['code']) ?> - <?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="edit_quantity">Jumlah <span class="required">*</span></label>
            <input class="form-input" type="number" name="quantity" id="edit_quantity" required min="1" value="1">
            <div id="edit_stock_info" class="form-hint"></div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="edit_borrow_date">Tanggal Peminjaman <span class="required">*</span></label>
            <input class="form-input" type="date" name="borrow_date" id="edit_borrow_date" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_due_date">Tanggal Jatuh Tempo <span class="required">*</span></label>
            <input class="form-input" type="date" name="due_date" id="edit_due_date" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('addForm').reset();
        document.getElementById('add_borrow_date').value = '<?= date('Y-m-d') ?>';
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        document.getElementById('add_due_date').min = tomorrow.toISOString().split('T')[0];
        document.getElementById('add_due_date').value = tomorrow.toISOString().split('T')[0];
        document.getElementById('add_stock_info').textContent = '';
        document.getElementById('addModal').classList.add('show');
    }

    function openEditModal(id, user_id, tool_id, quantity, borrow_date, due_date) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_user_id').value = user_id;
        document.getElementById('edit_tool_id').value = tool_id;
        document.getElementById('edit_quantity').value = quantity;
        document.getElementById('edit_borrow_date').value = borrow_date;
        document.getElementById('edit_due_date').value = due_date;
        updateStockInfo('edit');
        document.getElementById('editModal').classList.add('show');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    function updateStockInfo(prefix) {
        const select = document.getElementById(prefix + '_tool_id');
        const qtyInput = document.getElementById(prefix + '_quantity');
        const infoDiv = document.getElementById(prefix + '_stock_info');
        const option = select.options[select.selectedIndex];
        const stock = option ? parseInt(option.dataset.stock) : 0;

        if (stock > 0) {
            const currentQty = parseInt(qtyInput.value) || 0;
            const available = stock + (prefix === 'edit' ? (<?= json_encode(array_column($borrowings, 'quantity', 'id')) ?>[document.getElementById('edit_id').value] || 0) : 0);
            infoDiv.textContent = `Stok tersedia: ${available} (stok sistem: ${stock})`;
            infoDiv.classList.toggle('low', available < 3);
            qtyInput.max = available;
        } else {
            infoDiv.textContent = '';
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

    // Auto-set due_date min based on borrow_date
    ['add', 'edit'].forEach(prefix => {
        const borrowInput = document.getElementById(prefix + '_borrow_date');
        const dueInput = document.getElementById(prefix + '_due_date');
        if (borrowInput && dueInput) {
            borrowInput.addEventListener('change', () => {
                const nextDay = new Date(borrowInput.value);
                nextDay.setDate(nextDay.getDate() + 1);
                const minDue = nextDay.toISOString().split('T')[0];
                dueInput.min = minDue;
                if (dueInput.value && dueInput.value < minDue) {
                    dueInput.value = minDue;
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
