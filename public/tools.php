<?php
// ============================================================
// PINJAMIN — CRUD Alat (Admin)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$errors = [];
$success = false;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            $pdo = get_pdo();

            if ($action === 'add') {
                $category_id = (int)($_POST['category_id'] ?? 0);
                $code = trim($_POST['code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $quantity_total = (int)($_POST['quantity_total'] ?? 0);

                if ($category_id <= 0) {
                    $errors[] = 'Kategori wajib dipilih';
                } elseif ($code === '' || $name === '') {
                    $errors[] = 'Kode dan nama alat wajib diisi';
                } elseif ($quantity_total < 0) {
                    $errors[] = 'Jumlah total tidak boleh negatif';
                } else {
                    // Cek kode unik
                    $stmt = $pdo->prepare("SELECT id FROM tools WHERE code = ?");
                    $stmt->execute([$code]);
                    if ($stmt->fetch()) {
                        $errors[] = 'Kode alat sudah digunakan';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO tools (category_id, code, name, description, quantity_total, quantity_available) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$category_id, $code, $name, $description, $quantity_total, $quantity_total]);
                        log_activity('TOOL_ADD', "Tambah alat: $code - $name (total: $quantity_total)");
                        $success = true;
                    }
                }
            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                $category_id = (int)($_POST['category_id'] ?? 0);
                $code = trim($_POST['code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $quantity_total = (int)($_POST['quantity_total'] ?? 0);

                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } elseif ($category_id <= 0) {
                    $errors[] = 'Kategori wajib dipilih';
                } elseif ($code === '' || $name === '') {
                    $errors[] = 'Kode dan nama alat wajib diisi';
                } elseif ($quantity_total < 0) {
                    $errors[] = 'Jumlah total tidak boleh negatif';
                } else {
                    // Cek alat exists dan lock row
                    $stmt = $pdo->prepare("SELECT id, quantity_total, quantity_available FROM tools WHERE id = ? FOR UPDATE");
                    $stmt->execute([$id]);
                    $tool = $stmt->fetch();

                    if (!$tool) {
                        $errors[] = 'Alat tidak ditemukan';
                    } else {
                        // Cek kode unik (kecuali dirinya sendiri)
                        $stmt = $pdo->prepare("SELECT id FROM tools WHERE code = ? AND id != ?");
                        $stmt->execute([$code, $id]);
                        if ($stmt->fetch()) {
                            $errors[] = 'Kode alat sudah digunakan';
                        } else {
                            $old_total = (int)$tool['quantity_total'];
                            $available = (int)$tool['quantity_available'];
                            $borrowed = $old_total - $available;

                            // Validasi: total baru tidak boleh kurang dari yang dipinjam
                            if ($quantity_total < $borrowed) {
                                $errors[] = "Total tidak boleh kurang dari yang sedang dipinjam ($borrowed unit)";
                            } else {
                                // Hitung available baru = total baru - borrowed
                                $new_available = $quantity_total - $borrowed;

                                $stmt = $pdo->prepare("UPDATE tools SET category_id = ?, code = ?, name = ?, description = ?, quantity_total = ?, quantity_available = ? WHERE id = ?");
                                $stmt->execute([$category_id, $code, $name, $description, $quantity_total, $new_available, $id]);
                                log_activity('TOOL_EDIT', "Edit alat ID $id: $code - $name (total: $old_total -> $quantity_total, available: $available -> $new_available)");
                                $success = true;
                            }
                        }
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);

                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } else {
                    // Cek apakah alat direferensikan transaksi
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE tool_id = ?");
                    $stmt->execute([$id]);
                    $borrow_count = $stmt->fetchColumn();

                    if ($borrow_count > 0) {
                        $errors[] = "Alat tidak dapat dihapus karena memiliki $borrow_count riwayat peminjaman";
                    } else {
                        $stmt = $pdo->prepare("SELECT code, name FROM tools WHERE id = ?");
                        $stmt->execute([$id]);
                        $tool = $stmt->fetch();
                        $code = $tool['code'] ?? 'unknown';
                        $name = $tool['name'] ?? 'unknown';

                        $stmt = $pdo->prepare("DELETE FROM tools WHERE id = ?");
                        $stmt->execute([$id]);
                        log_activity('TOOL_DELETE', "Hapus alat: $code - $name (ID: $id)");
                        $success = true;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Tool CRUD error: " . $e->getMessage());
            $errors[] = 'Terjadi kesalahan database';
        }
    }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

try {
    $pdo = get_pdo();

    // Total count
    $stmt = $pdo->query("SELECT COUNT(*) FROM tools");
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get tools with category name
    $stmt = $pdo->prepare("
        SELECT t.id, t.category_id, t.code, t.name, t.description, t.quantity_total, t.quantity_available, t.created_at, c.name as category_name
        FROM tools t
        JOIN categories c ON c.id = t.category_id
        ORDER BY t.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $per_page, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $tools = $stmt->fetchAll();

    // Get categories for dropdown
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Tool list error: " . $e->getMessage());
    $tools = [];
    $categories = [];
    $total = 0;
    $total_pages = 1;
}

// AJAX handler for edit modal data
if (isset($_GET['edit']) && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT id, category_id, code, name, description, quantity_total FROM tools WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $tool = $stmt->fetch();
        if ($tool) {
            echo json_encode(['success' => true, 'tool' => $tool]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Alat tidak ditemukan']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Get tool for edit modal (non-AJAX fallback)
$edit_tool = null;
if (isset($_GET['edit']) && !isset($_GET['ajax'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, category_id, code, name, description, quantity_total FROM tools WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $edit_tool = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Tool edit fetch error: " . $e->getMessage());
    }
}
?>
<?php
$pageTitle = 'Kelola Alat';
$currentPage = basename($_SERVER['SCRIPT_NAME']);

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Kelola Alat</h1>
    <div class="page-actions">
      <button type="button" class="btn btn-primary" onclick="openModal('add')">+ Tambah Alat</button>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Alat (<?= $total ?> total)</h3>
    </div>

    <?php if (empty($tools)): ?>
      <div class="empty-state">
        <h3>Belum ada alat</h3>
        <p>Klik "Tambah Alat" untuk menambah data pertama</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Kode</th>
              <th scope="col">Nama</th>
              <th scope="col">Kategori</th>
              <th scope="col">Stok Total</th>
              <th scope="col">Tersedia</th>
              <th scope="col">Dipinjam</th>
              <th scope="col">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tools as $tool):
              $borrowed = $tool['quantity_total'] - $tool['quantity_available'];
              $stock_class = $tool['quantity_available'] == 0 ? 'badge-danger' : ($tool['quantity_available'] < 3 ? 'badge-pending' : 'badge-success');
            ?>
            <tr>
              <td><?= $tool['id'] ?></td>
              <td><code><?= e($tool['code']) ?></code></td>
              <td><?= e($tool['name']) ?></td>
              <td><?= e($tool['category_name']) ?></td>
              <td><?= $tool['quantity_total'] ?></td>
              <td><span class="badge <?= $stock_class ?>"><?= $tool['quantity_available'] ?></span></td>
              <td><?= $borrowed ?></td>
              <td>
                <div class="action-btns">
                  <a href="tools.php?edit=<?= $tool['id'] ?>" class="btn btn-icon btn-secondary icon-btn" aria-label="Edit <?= e($tool['name']) ?>" onclick="event.preventDefault(); openModal('edit', <?= $tool['id'] ?>)">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>
                  </a>
                  <button type="button" class="btn btn-icon btn-danger icon-btn" aria-label="Hapus <?= e($tool['name']) ?>" onclick="confirmDelete(<?= $tool['id'] ?>, '<?= e($tool['code']) ?> - <?= e($tool['name']) ?>', <?= $borrowed ?>)">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($total_pages > 1): ?>
    <div class="card-body">
      <nav class="pagination" aria-label="Navigasi halaman">
        <?php if ($page > 1): ?>
          <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary btn-sm" aria-label="Halaman sebelumnya">&laquo; Sebelumnya</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        for ($i = $start; $i <= $end; $i++): ?>
          <?php if ($i == $page): ?>
            <span class="btn btn-primary btn-sm" aria-current="page"><?= $i ?></span>
          <?php else: ?>
            <a href="?page=<?= $i ?>" class="btn btn-secondary btn-sm"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
          <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary btn-sm" aria-label="Halaman berikutnya">Selanjutnya &raquo;</a>
        <?php endif; ?>
      </nav>
    </div>
    <?php endif; ?>
  </div>
</main>

<!-- Modal Add/Edit -->
<div class="modal" id="toolModal" role="dialog" aria-modal="true" aria-labelledby="toolModalTitle">
  <div class="modal-backdrop" onclick="closeModal()"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title" id="toolModalTitle">Tambah Alat</h2>
      <button type="button" class="modal-close" aria-label="Tutup" onclick="closeModal()">&times;</button>
    </div>
    <form method="POST" id="toolForm" novalidate>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" id="toolFormAction" value="add">
        <input type="hidden" name="id" id="toolFormId">

        <div class="form-group">
          <label class="form-label" for="toolCategory">Kategori <span class="required">*</span></label>
          <select class="form-select" id="toolCategory" name="category_id" required>
            <option value="">-- Pilih Kategori --</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="toolCode">Kode Alat <span class="required">*</span></label>
            <input class="form-input" type="text" id="toolCode" name="code" required autocomplete="off" maxlength="30">
          </div>
          <div class="form-group">
            <label class="form-label" for="toolName">Nama Alat <span class="required">*</span></label>
            <input class="form-input" type="text" id="toolName" name="name" required autocomplete="off" maxlength="150">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="toolDescription">Deskripsi</label>
          <textarea class="form-textarea" id="toolDescription" name="description" placeholder="Deskripsi alat (opsional)"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label" for="toolQuantityTotal">Jumlah Total (Inventaris) <span class="required">*</span></label>
          <input class="form-input" type="number" id="toolQuantityTotal" name="quantity_total" required min="0" value="0">
          <small class="form-hint">Stok tersedia akan disesuaikan otomatis saat edit. Saat tambah baru, tersedia = total.</small>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal()">Batal</button>
        <button type="submit" class="btn btn-primary" id="toolSubmitBtn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Form Delete (hidden) -->
<form method="POST" id="toolDeleteForm" hidden>
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="toolDeleteId">
</form>

<script>
  const modal = document.getElementById('toolModal');
  const form = document.getElementById('toolForm');
  const title = document.getElementById('toolModalTitle');
  const actionInput = document.getElementById('toolFormAction');
  const idInput = document.getElementById('toolFormId');
  const submitBtn = document.getElementById('toolSubmitBtn');

  function openModal(mode, id = null) {
    form.reset();
    if (mode === 'add') {
      title.textContent = 'Tambah Alat';
      actionInput.value = 'add';
      idInput.value = '';
      submitBtn.textContent = 'Tambah';
    } else if (mode === 'edit' && id) {
      title.textContent = 'Edit Alat';
      actionInput.value = 'edit';
      idInput.value = id;
      submitBtn.textContent = 'Update';
      // Fetch tool data
      fetch('tools.php?edit=' + id + '&ajax=1')
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            document.getElementById('toolCategory').value = data.tool.category_id;
            document.getElementById('toolCode').value = data.tool.code;
            document.getElementById('toolName').value = data.tool.name;
            document.getElementById('toolDescription').value = data.tool.description;
            document.getElementById('toolQuantityTotal').value = data.tool.quantity_total;
          }
        });
    }
    modal.classList.add('show');
  }

  function closeModal() {
    modal.classList.remove('show');
  }

  modal.addEventListener('click', (e) => {
    if (e.target === modal || e.target.classList.contains('modal-backdrop')) closeModal();
  });

  // Delete confirmation
  function confirmDelete(id, name, borrowCount) {
    let msg = `Hapus alat "${name}" (ID: ${id})?`;
    if (borrowCount > 0) {
      msg += `\n\nPERINGATAN: Alat ini memiliki ${borrowCount} riwayat peminjaman dan TIDAK DAPAT DIHAPUS.`;
      alert(msg);
      return;
    }
    msg += '\n\nTindakan ini tidak dapat dibatalkan.';
    if (confirm(msg)) {
      document.getElementById('toolDeleteId').value = id;
      document.getElementById('toolDeleteForm').submit();
    }
  }

  // Close modal on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });

  // Uppercase code input
  document.getElementById('toolCode').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
  });
</script>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
