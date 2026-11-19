<?php
// ============================================================
// PINJAMIN — CRUD Pengguna (Admin)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$flash = get_flash();
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
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengguna | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px; background: #2c3e50; color: white; padding: 20px 0;
            position: fixed; height: 100vh; overflow-y: auto;
        }
        .sidebar .brand { padding: 0 20px 20px; border-bottom: 1px solid #3d5166; margin-bottom: 20px; }
        .sidebar .brand h2 { font-size: 18px; font-weight: 600; }
        .sidebar .brand small { color: #8aa1b8; font-size: 12px; }
        .sidebar nav a { display: block; padding: 12px 20px; color: #bdc3c7; text-decoration: none; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar nav a:hover, .sidebar nav a.active { background: #34495e; color: white; border-left-color: #e74c3c; }
        .sidebar nav a .icon { margin-right: 10px; }
        .sidebar .user-info { padding: 20px; border-top: 1px solid #3d5166; margin-top: 20px; font-size: 13px; color: #8aa1b8; }
        .sidebar .user-info strong { color: white; display: block; margin-bottom: 4px; }
        .sidebar .user-info a { color: #3498db; text-decoration: none; font-size: 12px; }
        .main { flex: 1; margin-left: 260px; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header h1 { color: #2c3e50; font-size: 24px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #e74c3c; color: white; }
        .btn-primary:hover { background: #c0392b; }
        .btn-secondary { background: #3498db; color: white; }
        .btn-secondary:hover { background: #2980b9; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-outline { background: transparent; border: 1px solid #e74c3c; color: #e74c3c; }
        .btn-outline:hover { background: #e74c3c; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .card-header { padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { color: #2c3e50; font-size: 16px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; color: #666; font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover td { background: #fafafa; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-admin { background: #fadbd8; color: #e74c3c; }
        .badge-petugas { background: #d5f5e3; color: #27ae60; }
        .badge-peminjam { background: #d6eaf8; color: #3498db; }
        .badge-active { background: #d5f5e3; color: #27ae60; }
        .badge-inactive { background: #fadbd8; color: #e74c3c; }
        .action-btns { display: flex; gap: 6px; }
        .icon-btn { padding: 6px 10px; border-radius: 4px; text-decoration: none; font-size: 13px; transition: all 0.2s; }
        .icon-btn.edit { background: #e8f0fe; color: #3498db; }
        .icon-btn.edit:hover { background: #3498db; color: white; }
        .icon-btn.delete { background: #fdeaea; color: #e74c3c; }
        .icon-btn.delete:hover { background: #e74c3c; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 8px; padding: 24px; width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title { font-size: 18px; font-weight: 600; color: #2c3e50; }
        .modal-close { background: none; border: none; font-size: 24px; color: #999; cursor: pointer; line-height: 1; }
        .modal-close:hover { color: #333; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; font-size: 14px; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #e74c3c; box-shadow: 0 0 0 3px rgba(231,76,60,0.1); }
        .checkbox-group { display: flex; align-items: center; gap: 8px; }
        .checkbox-group input[type="checkbox"] { width: auto; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 6px; text-decoration: none; color: #333; border: 1px solid #ddd; }
        .pagination a:hover { background: #e74c3c; color: white; border-color: #e74c3c; }
        .pagination .current { background: #e74c3c; color: white; border-color: #e74c3c; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state h3 { margin-bottom: 8px; color: #666; }
        @media (max-width: 768px) {
            .sidebar { position: static; width: 100%; }
            .main { margin-left: 0; }
            .layout { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">
                <h2>Pinjamin</h2>
                <small>Admin Panel</small>
            </div>
            <nav>
                <a href="dashboard_admin.php" class=""><span class="icon">📊</span> Dashboard</a>
                <a href="users.php" class="active"><span class="icon">👥</span> Pengguna</a>
                <a href="categories.php"><span class="icon">📁</span> Kategori</a>
                <a href="tools.php"><span class="icon">🔧</span> Alat</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge badge-admin"><?= e(current_user()['role']) ?></span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Kelola Pengguna</h1>
                <button class="btn btn-primary" onclick="openModal('add')">+ Tambah Pengguna</button>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">Operasi berhasil</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Daftar Pengguna (<?= $total ?> total)</h3>
                </div>
                <div class="table-responsive">
                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <h3>Belum ada pengguna</h3>
                            <p>Klik "Tambah Pengguna" untuk menambah data pertama</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 50px;">ID</th>
                                    <th>Nama</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Dibuat</th>
                                    <th style="width: 120px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= $user['id'] ?></td>
                                    <td><?= e($user['name']) ?></td>
                                    <td><?= e($user['username']) ?></td>
                                    <td><span class="badge badge-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span></td>
                                    <td><span class="badge badge-<?= $user['is_active'] ? 'active' : 'inactive' ?>"><?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                                    <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <a href="users.php?edit=<?= $user['id'] ?>" class="icon-btn edit" onclick="event.preventDefault(); openModal('edit', <?= $user['id'] ?>)">Edit</a>
                                            <?php if ($user['id'] != current_user()['id']): ?>
                                            <button type="button" class="icon-btn delete" onclick="confirmDelete(<?= $user['id'] ?>, '<?= e($user['username']) ?>')">Hapus</button>
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
                <div style="padding: 20px;">
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>">&laquo; Sebelumnya</a>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $i ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?>">Selanjutnya &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal Add/Edit -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Tambah Pengguna</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="userForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">

                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required autocomplete="username" maxlength="50">
                </div>

                <div class="form-group">
                    <label for="name">Nama Lengkap *</label>
                    <input type="text" id="name" name="name" required autocomplete="name" maxlength="100">
                </div>

                <div class="form-group">
                    <label for="password">Password <?= isset($edit_user) ? '(kosongkan jika tidak diubah)' : '*' ?></label>
                    <input type="password" id="password" name="password" <?= isset($edit_user) ? '' : 'required' ?> autocomplete="new-password" minlength="6">
                </div>

                <div class="form-group">
                    <label for="role">Role *</label>
                    <select id="role" name="role" required>
                        <option value="admin">Admin</option>
                        <option value="petugas">Petugas</option>
                        <option value="peminjam">Peminjam</option>
                    </select>
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="is_active" name="is_active" value="1">
                        <label for="is_active">Aktif</label>
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
    <form method="POST" id="deleteForm" style="display: none;">
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
            if (e.target === modal) closeModal();
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
</body>
</html>