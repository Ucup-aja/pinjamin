<?php
// ============================================================
// PINJAMIN — CRUD Pengguna (Admin)
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
                $username = trim($_POST['username'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'peminjam';
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if ($username === '' || $name === '' || $password === '') {
                    $errors[] = 'Semua field wajib diisi';
                } elseif (strlen($password) < 6) {
                    $errors[] = 'Password minimal 6 karakter';
                } else {
                    // Cek username unik
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $errors[] = 'Username sudah digunakan';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, name, password, role, is_active) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $name, $hash, $role, $is_active]);
                        log_activity('USER_ADD', "Tambah user: $username ($role)");
                        $success = true;
                    }
                }
            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                $username = trim($_POST['username'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $role = $_POST['role'] ?? 'peminjam';
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $password = $_POST['password'] ?? '';

                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } elseif ($username === '' || $name === '') {
                    $errors[] = 'Username dan nama wajib diisi';
                } else {
                    // Cek user exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    if (!$stmt->fetch()) {
                        $errors[] = 'User tidak ditemukan';
                    } else {
                        // Cek username unik (kecuali dirinya sendiri)
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                        $stmt->execute([$username, $id]);
                        if ($stmt->fetch()) {
                            $errors[] = 'Username sudah digunakan';
                        } else {
                            // Mencegah admin menonaktifkan akun sendiri
                            $current_user = current_user();
                            if ($current_user['id'] == $id && $is_active == 0) {
                                $errors[] = 'Anda tidak dapat menonaktifkan akun sendiri';
                            } else {
                                if ($password !== '') {
                                    if (strlen($password) < 6) {
                                        $errors[] = 'Password minimal 6 karakter';
                                    } else {
                                        $hash = password_hash($password, PASSWORD_DEFAULT);
                                        $stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, password = ?, role = ?, is_active = ? WHERE id = ?");
                                        $stmt->execute([$username, $name, $hash, $role, $is_active, $id]);
                                        log_activity('USER_EDIT', "Edit user ID $id: username=$username, role=$role, password_changed=yes");
                                    }
                                } else {
                                    $stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, role = ?, is_active = ? WHERE id = ?");
                                    $stmt->execute([$username, $name, $role, $is_active, $id]);
                                    log_activity('USER_EDIT', "Edit user ID $id: username=$username, role=$role, password_changed=no");
                                }
                                if (empty($errors)) {
                                    $success = true;
                                }
                            }
                        }
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $current_user = current_user();

                if ($id <= 0) {
                    $errors[] = 'ID tidak valid';
                } elseif ($current_user['id'] == $id) {
                    $errors[] = 'Anda tidak dapat menghapus akun sendiri';
                } else {
                    // Cek apakah user punya transaksi (borrowings atau returns)
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ?");
                    $stmt->execute([$id]);
                    $borrow_count = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE approved_by = ?");
                    $stmt->execute([$id]);
                    $approve_count = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns WHERE requested_by = ?");
                    $stmt->execute([$id]);
                    $return_req_count = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns WHERE processed_by = ?");
                    $stmt->execute([$id]);
                    $return_proc_count = $stmt->fetchColumn();

                    if ($borrow_count > 0 || $approve_count > 0 || $return_req_count > 0 || $return_proc_count > 0) {
                        $errors[] = 'User memiliki riwayat transaksi, tidak dapat dihapus. Gunakan nonaktifkan.';
                    } else {
                        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                        $stmt->execute([$id]);
                        $user = $stmt->fetch();
                        $username = $user['username'] ?? 'unknown';

                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$id]);
                        log_activity('USER_DELETE', "Hapus user: $username (ID: $id)");
                        $success = true;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("User CRUD error: " . $e->getMessage());
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
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get users
    $stmt = $pdo->prepare("SELECT id, name, username, role, is_active, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $per_page, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("User list error: " . $e->getMessage());
    $users = [];
    $total = 0;
    $total_pages = 1;
}

// AJAX handler for edit modal data
if (isset($_GET['edit']) && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT id, name, username, role, is_active FROM users WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $user = $stmt->fetch();
        if ($user) {
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Get user for edit modal (non-AJAX fallback)
$edit_user = null;
if (isset($_GET['edit']) && !isset($_GET['ajax'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, username, role, is_active FROM users WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $edit_user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("User edit fetch error: " . $e->getMessage());
    }
}
?>
<?php
$pageTitle = 'Kelola Pengguna';
$currentPage = basename($_SERVER['SCRIPT_NAME']);

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Kelola Pengguna</h1>
    <div class="page-actions">
      <button type="button" class="btn btn-primary" onclick="openModal('add')">+ Tambah Pengguna</button>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Daftar Pengguna (<?= $total ?> total)</h3>
    </div>

    <?php if (empty($users)): ?>
      <div class="empty-state">
        <h3>Belum ada pengguna</h3>
        <p>Klik "Tambah Pengguna" untuk menambah data pertama</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Nama</th>
              <th scope="col">Username</th>
              <th scope="col">Role</th>
              <th scope="col">Status</th>
              <th scope="col">Dibuat</th>
              <th scope="col">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
              <td><?= $user['id'] ?></td>
              <td><?= e($user['name']) ?></td>
              <td><?= e($user['username']) ?></td>
              <td><span class="badge badge-role-<?= e($user['role']) ?>"><?= ucfirst($user['role']) ?></span></td>
              <td><span class="badge <?= $user['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
              <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
              <td>
                <div class="action-btns">
                  <a href="users.php?edit=<?= $user['id'] ?>" class="btn btn-icon btn-secondary icon-btn" aria-label="Edit <?= e($user['name']) ?>" onclick="event.preventDefault(); openModal('edit', <?= $user['id'] ?>)">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>
                  </a>
                  <?php if ($user['id'] != current_user()['id']): ?>
                  <button type="button" class="btn btn-icon btn-danger icon-btn" aria-label="Hapus <?= e($user['name']) ?>" onclick="confirmDelete(<?= $user['id'] ?>, '<?= e($user['username']) ?>')">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                  </button>
                  <?php endif; ?>
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
<div class="modal" id="userModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal-backdrop" onclick="closeModal()"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title" id="modalTitle">Tambah Pengguna</h2>
      <button type="button" class="modal-close" aria-label="Tutup" onclick="closeModal()">&times;</button>
    </div>
    <form method="POST" id="userForm" novalidate>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="formId">

        <div class="form-group">
          <label class="form-label" for="username">Username <span class="required">*</span></label>
          <input class="form-input" type="text" id="username" name="username" required autocomplete="username" maxlength="50">
        </div>

        <div class="form-group">
          <label class="form-label" for="name">Nama Lengkap <span class="required">*</span></label>
          <input class="form-input" type="text" id="name" name="name" required autocomplete="name" maxlength="100">
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password <?= isset($edit_user) ? '(kosongkan jika tidak diubah)' : '<span class="required">*</span>' ?></label>
          <input class="form-input" type="password" id="password" name="password" <?= isset($edit_user) ? '' : 'required' ?> autocomplete="new-password" minlength="6">
        </div>

        <div class="form-group">
          <label class="form-label" for="role">Role <span class="required">*</span></label>
          <select class="form-select" id="role" name="role" required>
            <option value="admin">Admin</option>
            <option value="petugas">Petugas</option>
            <option value="peminjam">Peminjam</option>
          </select>
        </div>

        <div class="form-group">
          <div class="checkbox-group">
            <input type="checkbox" id="is_active" name="is_active" value="1">
            <label class="form-label" for="is_active">Aktif</label>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal()">Batal</button>
        <button type="submit" class="btn btn-primary" id="submitBtn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Form Delete (hidden) -->
<form method="POST" id="deleteForm" hidden>
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<script>
  const modal = document.getElementById('userModal');
  const form = document.getElementById('userForm');
  const title = document.getElementById('modalTitle');
  const actionInput = document.getElementById('formAction');
  const idInput = document.getElementById('formId');
  const passwordInput = document.getElementById('password');
  const submitBtn = document.getElementById('submitBtn');

  function openModal(mode, id = null) {
    form.reset();
    if (mode === 'add') {
      title.textContent = 'Tambah Pengguna';
      actionInput.value = 'add';
      idInput.value = '';
      passwordInput.required = true;
      passwordInput.placeholder = 'Minimal 6 karakter';
      submitBtn.textContent = 'Tambah';
    } else if (mode === 'edit' && id) {
      title.textContent = 'Edit Pengguna';
      actionInput.value = 'edit';
      idInput.value = id;
      passwordInput.required = false;
      passwordInput.placeholder = 'Kosongkan jika tidak diubah';
      submitBtn.textContent = 'Update';
      // Fetch user data
      fetch('users.php?edit=' + id + '&ajax=1')
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            document.getElementById('username').value = data.user.username;
            document.getElementById('name').value = data.user.name;
            document.getElementById('role').value = data.user.role;
            document.getElementById('is_active').checked = data.user.is_active == 1;
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

  // Handle form submit
  form.addEventListener('submit', (e) => {
    // Let it submit normally
  });

  // Delete confirmation
  function confirmDelete(id, username) {
    if (confirm(`Hapus user "${username}" (ID: ${id})?\n\nCatatan: User dengan riwayat transaksi tidak bisa dihapus.`)) {
      document.getElementById('deleteId').value = id;
      document.getElementById('deleteForm').submit();
    }
  }

  // Close modal on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });
</script>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
