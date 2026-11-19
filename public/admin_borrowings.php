<?php
// ============================================================
// PINJAMIN — Kelola Peminjaman (Admin)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$flash = get_flash();
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
    'pending' => 'Pending', 'approved' => 'Disetujui', 'partially_returned' => 'Sebagian Kembali',
    'returned' => 'Dikembalikan', 'rejected' => 'Ditolak'
];
$status_class = [
    'pending' => 'badge-pending', 'approved' => 'badge-approved', 'partially_returned' => 'badge-partial',
    'returned' => 'badge-returned', 'rejected' => 'badge-rejected'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Peminjaman | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px; background: #2c3e50; color: white; padding: 20px 0;
            position: fixed; height: 100vh; overflow-y: auto;
        }
        .sidebar .brand { padding: 0 20px 20px; border-bottom: 1px solid #3d566e; margin-bottom: 20px; }
        .sidebar .brand h2 { font-size: 18px; font-weight: 600; }
        .sidebar .brand small { color: #a8bcc8; font-size: 12px; }
        .sidebar nav a { display: block; padding: 12px 20px; color: #d5e5f0; text-decoration: none; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar nav a:hover, .sidebar nav a.active { background: #243746; color: white; border-left-color: #f39c12; }
        .sidebar nav a .icon { margin-right: 10px; }
        .sidebar .user-info { padding: 20px; border-top: 1px solid #3d566e; margin-top: 20px; font-size: 13px; color: #a8bcc8; }
        .sidebar .user-info strong { color: white; display: block; margin-bottom: 4px; }
        .sidebar .user-info a { color: #f39c12; text-decoration: none; font-size: 12px; }
        .main { flex: 1; margin-left: 260px; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 16px; }
        .header h1 { color: #2c3e50; font-size: 24px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #2c3e50; color: white; }
        .btn-primary:hover { background: #1a252f; }
        .btn-secondary { background: #3498db; color: white; }
        .btn-secondary:hover { background: #2980b9; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-outline { background: transparent; border: 1px solid #2c3e50; color: #2c3e50; }
        .btn-outline:hover { background: #2c3e50; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .card-header { padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .card-header h3 { color: #2c3e50; font-size: 16px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #f8f9fa; color: #666; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover td { background: #fafafa; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-partial { background: #d6eaf8; color: #3498db; }
        .badge-returned { background: #d5f5e3; color: #27ae60; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 6px; text-decoration: none; color: #333; border: 1px solid #ddd; }
        .pagination a:hover { background: #2c3e50; color: white; border-color: #2c3e50; }
        .pagination .current { background: #2c3e50; color: white; border-color: #2c3e50; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state h3 { margin-bottom: 8px; color: #666; }
        .filter-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-form select, .filter-form input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .action-btns { display: flex; gap: 6px; flex-wrap: wrap; }
        .icon-btn { padding: 6px 10px; border-radius: 4px; text-decoration: none; font-size: 13px; transition: all 0.2s; border: none; cursor: pointer; }
        .icon-btn.edit { background: #e8f0fe; color: #3498db; }
        .icon-btn.edit:hover { background: #3498db; color: white; }
        .icon-btn.delete { background: #fdeaea; color: #e74c3c; }
        .icon-btn.delete:hover { background: #e74c3c; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 8px; padding: 24px; width: 90%; max-width: 600px; max-height: 85vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-header h3 { color: #2c3e50; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; }
        .form-group { margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; font-size: 14px; }
        input[type="text"], input[type="number"], input[type="date"], select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #2c3e50; box-shadow: 0 0 0 3px rgba(44,62,80,0.1); }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .stock-info { font-size: 12px; color: #666; margin-top: 4px; }
        .stock-info.low { color: #e74c3c; font-weight: 600; }
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
                <a href="users.php"><span class="icon">👥</span> Kelola Pengguna</a>
                <a href="categories.php"><span class="icon">📁</span> Kelola Kategori</a>
                <a href="tools.php"><span class="icon">🔧</span> Kelola Alat</a>
                <a href="admin_borrowings.php" class="active"><span class="icon">📋</span> Kelola Peminjaman</a>
                <a href="admin_returns.php"><span class="icon">🔄</span> Kelola Pengembalian</a>
                <a href="activity_log.php"><span class="icon">📊</span> Log Aktivitas</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #e74c3c; color: white;">Admin</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Kelola Peminjaman</h1>
                <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Tambah Pengajuan</button>
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
                    <h3>Daftar Peminjaman (<?= $total ?> total)</h3>
                    <form method="GET" class="filter-form">
                        <input type="text" name="search" placeholder="Cari nama, username, kode, nama alat..." value="<?= e($search) ?>" style="min-width: 200px;">
                        <select name="status">
                            <option value="">Semua Status</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
                            <option value="partially_returned" <?= $status_filter === 'partially_returned' ? 'selected' : '' ?>>Sebagian Kembali</option>
                            <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Dikembalikan</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                        <?php if ($search || $status_filter): ?>
                            <a href="admin_borrowings.php" class="btn btn-outline btn-sm">Reset</a>
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
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th>Peminjam</th>
                                    <th>Alat</th>
                                    <th>Qty</th>
                                    <th>Tgl Pinjam</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Status</th>
                                    <th>Disetujui</th>
                                    <th>Oleh</th>
                                    <th>Kembali</th>
                                    <th style="width: 160px;">Aksi</th>
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
                                        <strong><?= e($b['user_name']) ?></strong><br>
                                        <small style="color: #666;">@<?= e($b['username']) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= e($b['code']) ?> - <?= e($b['tool_name']) ?></strong><br>
                                        <small style="color: #666;"><?= e($b['category_name']) ?></small>
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
                                                <button type="button" class="icon-btn edit" onclick="openEditModal(
                                                    <?= $b['id'] ?>,
                                                    <?= $b['user_id'] ?>,
                                                    <?= $b['tool_id'] ?>,
                                                    <?= $b['quantity'] ?>,
                                                    '<?= e($b['borrow_date']) ?>',
                                                    '<?= e($b['due_date']) ?>'
                                                )">Edit</button>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus pengajuan ini? Data terkait akan ikut terhapus.');">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                                    <button type="submit" class="icon-btn delete">Hapus</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #999; font-size: 13px;">-</span>
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
    </div>

    <!-- Add Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Tambah Pengajuan Peminjaman (Admin)</h3>
                <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST" id="addForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label for="add_user_id">Peminjam *</label>
                        <select name="user_id" id="add_user_id" required>
                            <option value="">-- Pilih Peminjam --</option>
                            <?php foreach ($peminjam_list as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (@<?= e($p['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="add_tool_id">Alat *</label>
                        <select name="tool_id" id="add_tool_id" required onchange="updateStockInfo('add')">
                            <option value="">-- Pilih Alat --</option>
                            <?php foreach ($tools_list as $t): ?>
                                <option value="<?= $t['id'] ?>" data-stock="<?= $t['quantity_available'] ?>"><?= e($t['code']) ?> - <?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="add_quantity">Jumlah *</label>
                        <input type="number" name="quantity" id="add_quantity" required min="1" value="1">
                        <div id="add_stock_info" class="stock-info"></div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="add_borrow_date">Tanggal Peminjaman *</label>
                        <input type="date" name="borrow_date" id="add_borrow_date" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label for="add_due_date">Tanggal Jatuh Tempo *</label>
                        <input type="date" name="due_date" id="add_due_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                </div>
                <div class="modal-actions">
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
                <h3>Edit Pengajuan Peminjaman</h3>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_user_id">Peminjam *</label>
                        <select name="user_id" id="edit_user_id" required>
                            <option value="">-- Pilih Peminjam --</option>
                            <?php foreach ($peminjam_list as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (@<?= e($p['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_tool_id">Alat *</label>
                        <select name="tool_id" id="edit_tool_id" required onchange="updateStockInfo('edit')">
                            <option value="">-- Pilih Alat --</option>
                            <?php foreach ($tools_list as $t): ?>
                                <option value="<?= $t['id'] ?>" data-stock="<?= $t['quantity_available'] ?>"><?= e($t['code']) ?> - <?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_quantity">Jumlah *</label>
                        <input type="number" name="quantity" id="edit_quantity" required min="1" value="1">
                        <div id="edit_stock_info" class="stock-info"></div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_borrow_date">Tanggal Peminjaman *</label>
                        <input type="date" name="borrow_date" id="edit_borrow_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_due_date">Tanggal Jatuh Tempo *</label>
                        <input type="date" name="due_date" id="edit_due_date" required>
                    </div>
                </div>
                <div class="modal-actions">
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
            document.getElementById('addModal').classList.add('active');
        }

        function openEditModal(id, user_id, tool_id, quantity, borrow_date, due_date) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_user_id').value = user_id;
            document.getElementById('edit_tool_id').value = tool_id;
            document.getElementById('edit_quantity').value = quantity;
            document.getElementById('edit_borrow_date').value = borrow_date;
            document.getElementById('edit_due_date').value = due_date;
            updateStockInfo('edit');
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
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
                document.querySelectorAll('.modal.active').forEach(m => closeModal(m.id));
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
</body>
</html>