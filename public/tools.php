<?php
// ============================================================
// PINJAMIN — CRUD Alat (Admin)
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
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Alat | Pinjamin</title>
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
        .badge-available { background: #d5f5e3; color: #27ae60; }
        .badge-low { background: #fff3cd; color: #856004; }
        .badge-empty { background: #fadbd8; color: #e74c3c; }
        .stock-info { display: flex; flex-direction: column; gap: 2px; }
        .stock-label { font-size: 11px; color: #999; }
        .stock-value { font-weight: 600; }
        .action-btns { display: flex; gap: 6px; }
        .icon-btn { padding: 6px 10px; border-radius: 4px; text-decoration: none; font-size: 13px; transition: all 0.2s; }
        .icon-btn.edit { background: #e8f0fe; color: #3498db; }
        .icon-btn.edit:hover { background: #3498db; color: white; }
        .icon-btn.delete { background: #fdeaea; color: #e74c3c; }
        .icon-btn.delete:hover { background: #e74c3c; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 8px; padding: 24px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title { font-size: 18px; font-weight: 600; color: #2c3e50; }
        .modal-close { background: none; border: none; font-size: 24px; color: #999; cursor: pointer; line-height: 1; }
        .modal-close:hover { color: #333; }
        .form-group { margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; font-size: 14px; }
        input[type="text"], input[type="number"], select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        textarea { min-height: 80px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #e74c3c; box-shadow: 0 0 0 3px rgba(231,76,60,0.1); }
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
                <a href="dashboard_admin.php"><span class="icon">📊</span> Dashboard</a>
                <a href="users.php"><span class="icon">👥</span> Pengguna</a>
                <a href="categories.php"><span class="icon">📁</span> Kategori</a>
                <a href="tools.php" class="active"><span class="icon">🔧</span> Alat</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #fadbd8; color: #e74c3c;">Admin</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Kelola Alat</h1>
                <button class="btn btn-primary" onclick="openModal('add')">+ Tambah Alat</button>
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
                    <h3>Daftar Alat (<?= $total ?> total)</h3>
                </div>
                <div class="table-responsive">
                    <?php if (empty($tools)): ?>
                        <div class="empty-state">
                            <h3>Belum ada alat</h3>
                            <p>Klik "Tambah Alat" untuk menambah data pertama</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 50px;">ID</th>
                                    <th>Kode</th>
                                    <th>Nama</th>
                                    <th>Kategori</th>
                                    <th>Stok Total</th>
                                    <th>Tersedia</th>
                                    <th>Dipinjam</th>
                                    <th style="width: 120px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tools as $tool): 
                                    $borrowed = $tool['quantity_total'] - $tool['quantity_available'];
                                    $stock_class = $tool['quantity_available'] == 0 ? 'badge-empty' : ($tool['quantity_available'] < 3 ? 'badge-low' : 'badge-available');
                                ?>
                                <tr>
                                    <td><?= $tool['id'] ?></td>
                                    <td><code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;"><?= e($tool['code']) ?></code></td>
                                    <td><?= e($tool['name']) ?></td>
                                    <td><?= e($tool['category_name']) ?></td>
                                    <td><?= $tool['quantity_total'] ?></td>
                                    <td><span class="badge <?= $stock_class ?>"><?= $tool['quantity_available'] ?></span></td>
                                    <td><?= $borrowed ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <a href="tools.php?edit=<?= $tool['id'] ?>" class="icon-btn edit" onclick="event.preventDefault(); openModal('edit', <?= $tool['id'] ?>)">Edit</a>
                                            <button type="button" class="icon-btn delete" onclick="confirmDelete(<?= $tool['id'] ?>, '<?= e($tool['code']) ?> - <?= e($tool['name']) ?>', <?= $borrowed ?>)">Hapus</button>
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
    <div class="modal" id="toolModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="toolModalTitle">Tambah Alat</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="toolForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" id="toolFormAction" value="add">
                <input type="hidden" name="id" id="toolFormId">

                <div class="form-group">
                    <label for="toolCategory">Kategori *</label>
                    <select id="toolCategory" name="category_id" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="toolCode">Kode Alat *</label>
                        <input type="text" id="toolCode" name="code" required autocomplete="off" maxlength="30" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label for="toolName">Nama Alat *</label>
                        <input type="text" id="toolName" name="name" required autocomplete="off" maxlength="150">
                    </div>
                </div>

                <div class="form-group">
                    <label for="toolDescription">Deskripsi</label>
                    <textarea id="toolDescription" name="description" placeholder="Deskripsi alat (opsional)"></textarea>
                </div>

                <div class="form-group">
                    <label for="toolQuantityTotal">Jumlah Total (Inventaris) *</label>
                    <input type="number" id="toolQuantityTotal" name="quantity_total" required min="0" value="0">
                    <small style="color: #666; font-size: 12px;">Stok tersedia akan disesuaikan otomatis saat edit. Saat tambah baru, tersedia = total.</small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Batal</button>
                    <button type="submit" class="btn btn-primary" id="toolSubmitBtn">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Form Delete (hidden) -->
    <form method="POST" id="toolDeleteForm" style="display: none;">
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
            if (e.target === modal) closeModal();
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
</body>
</html>