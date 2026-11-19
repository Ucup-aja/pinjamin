<?php
// ============================================================
// PINJAMIN — CRUD Kategori (Admin)
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
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');

                if ($name === '') {
                    $errors[] = 'Nama kategori wajib diisi';
                } else {
                    // Cek nama unik
                    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
                    $stmt->execute([$name]);
                    if ($stmt->fetch()) {
                        $errors[] = 'Nama kategori sudah digunakan';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                        $stmt->execute([$name, $description]);
                        log_activity('CATEGORY_ADD', "Tambah kategori: $name");
                        $success = true;
                    }
                }
            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');

                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } elseif ($name === '') {
                    $errors[] = 'Nama kategori wajib diisi';
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
                    $stmt->execute([$id]);
                    if (!$stmt->fetch()) {
                        $errors[] = 'Kategori tidak ditemukan';
                    } else {
                        $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
                        $stmt->execute([$name, $id]);
                        if ($stmt->fetch()) {
                            $errors[] = 'Nama kategori sudah digunakan';
                        } else {
                            $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
                            $stmt->execute([$name, $description, $id]);
                            log_activity('CATEGORY_EDIT', "Edit kategori ID $id: $name");
                            $success = true;
                        }
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);

                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } else {
                    // Cek apakah kategori digunakan oleh alat
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tools WHERE category_id = ?");
                    $stmt->execute([$id]);
                    $tool_count = $stmt->fetchColumn();

                    if ($tool_count > 0) {
                        $errors[] = "Kategori tidak dapat dihapus karena masih digunakan oleh $tool_count alat";
                    } else {
                        $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                        $stmt->execute([$id]);
                        $cat = $stmt->fetch();
                        $name = $cat['name'] ?? 'unknown';

                        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                        $stmt->execute([$id]);
                        log_activity('CATEGORY_DELETE', "Hapus kategori: $name (ID: $id)");
                        $success = true;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Category CRUD error: " . $e->getMessage());
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
    $stmt = $pdo->query("SELECT COUNT(*) FROM categories");
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get categories with tool count
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.description, COUNT(t.id) as tool_count
        FROM categories c
        LEFT JOIN tools t ON t.category_id = c.id
        GROUP BY c.id, c.name, c.description
        ORDER BY c.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $per_page, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $categories = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Category list error: " . $e->getMessage());
    $categories = [];
    $total = 0;
    $total_pages = 1;
}

// AJAX handler for edit modal data
if (isset($_GET['edit']) && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT id, name, description FROM categories WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $category = $stmt->fetch();
        if ($category) {
            echo json_encode(['success' => true, 'category' => $category]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Get category for edit modal (non-AJAX fallback)
$edit_category = null;
if (isset($_GET['edit']) && !isset($_GET['ajax'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, description FROM categories WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $edit_category = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Category edit fetch error: " . $e->getMessage());
    }
}
?>
<?php
$pageTitle = 'Kelola Kategori';
$currentPage = basename($_SERVER['SCRIPT_NAME']);

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Kelola Kategori</h1>
    <div class="page-actions">
      <button type="button" class="btn btn-primary" onclick="openModal('add')">+ Tambah Kategori</button>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Kategori (<?= $total ?> total)</h3>
    </div>

    <?php if (empty($categories)): ?>
      <div class="empty-state">
        <h3>Belum ada kategori</h3>
        <p>Klik "Tambah Kategori" untuk menambah data pertama</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Nama</th>
              <th scope="col">Deskripsi</th>
              <th scope="col">Jumlah Alat</th>
              <th scope="col">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $cat): ?>
            <tr>
              <td><?= $cat['id'] ?></td>
              <td><?= e($cat['name']) ?></td>
              <td><?= e($cat['description'] ?: '-') ?></td>
              <td>
                <span class="badge <?= $cat['tool_count'] > 0 ? 'badge-info' : 'badge-success' ?>">
                  <?= $cat['tool_count'] ?> alat
                </span>
              </td>
              <td>
                <div class="action-btns">
                  <a href="categories.php?edit=<?= $cat['id'] ?>" class="btn btn-icon btn-secondary icon-btn" aria-label="Edit <?= e($cat['name']) ?>" onclick="event.preventDefault(); openModal('edit', <?= $cat['id'] ?>)">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>
                  </a>
                  <button type="button" class="btn btn-icon btn-danger icon-btn" aria-label="Hapus <?= e($cat['name']) ?>" onclick="confirmDelete(<?= $cat['id'] ?>, '<?= e($cat['name']) ?>', <?= $cat['tool_count'] ?>)">
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
<div class="modal" id="categoryModal" role="dialog" aria-modal="true" aria-labelledby="catModalTitle">
  <div class="modal-backdrop" onclick="closeModal()"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title" id="catModalTitle">Tambah Kategori</h2>
      <button type="button" class="modal-close" aria-label="Tutup" onclick="closeModal()">&times;</button>
    </div>
    <form method="POST" id="catForm" novalidate>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" id="catFormAction" value="add">
        <input type="hidden" name="id" id="catFormId">

        <div class="form-group">
          <label class="form-label" for="catName">Nama Kategori <span class="required">*</span></label>
          <input class="form-input" type="text" id="catName" name="name" required autocomplete="off" maxlength="100">
        </div>

        <div class="form-group">
          <label class="form-label" for="catDescription">Deskripsi</label>
          <textarea class="form-textarea" id="catDescription" name="description" placeholder="Deskripsi kategori (opsional)"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal()">Batal</button>
        <button type="submit" class="btn btn-primary" id="catSubmitBtn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Form Delete (hidden) -->
<form method="POST" id="catDeleteForm" hidden>
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="catDeleteId">
</form>

<script>
  const modal = document.getElementById('categoryModal');
  const form = document.getElementById('catForm');
  const title = document.getElementById('catModalTitle');
  const actionInput = document.getElementById('catFormAction');
  const idInput = document.getElementById('catFormId');
  const submitBtn = document.getElementById('catSubmitBtn');

  function openModal(mode, id = null) {
    form.reset();
    if (mode === 'add') {
      title.textContent = 'Tambah Kategori';
      actionInput.value = 'add';
      idInput.value = '';
      submitBtn.textContent = 'Tambah';
    } else if (mode === 'edit' && id) {
      title.textContent = 'Edit Kategori';
      actionInput.value = 'edit';
      idInput.value = id;
      submitBtn.textContent = 'Update';
      // Fetch category data
      fetch('categories.php?edit=' + id + '&ajax=1')
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            document.getElementById('catName').value = data.category.name;
            document.getElementById('catDescription').value = data.category.description;
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
  function confirmDelete(id, name, toolCount) {
    let msg = `Hapus kategori "${name}" (ID: ${id})?`;
    if (toolCount > 0) {
      msg += `\n\nPERINGATAN: Kategori ini digunakan oleh ${toolCount} alat dan TIDAK DAPAT DIHAPUS.`;
      alert(msg);
      return;
    }
    msg += '\n\nTindakan ini tidak dapat dibatalkan.';
    if (confirm(msg)) {
      document.getElementById('catDeleteId').value = id;
      document.getElementById('catDeleteForm').submit();
    }
  }

  // Close modal on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });
</script>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
