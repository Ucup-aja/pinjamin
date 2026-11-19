<?php
// ============================================================
// PINJAMIN — Kelola Pengembalian (Admin)
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

$status_label = ['pending' => 'Pending', 'confirmed' => 'Dikonfirmasi', 'rejected' => 'Ditolak'];
$status_class = ['pending' => 'badge-pending', 'confirmed' => 'badge-confirmed', 'rejected' => 'badge-rejected'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengembalian | Pinjamin</title>
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
        .badge-confirmed { background: #d5f5e3; color: #27ae60; }
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
        @media (max-width: 600px) { .form-row { grid-template: 1fr; } }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; font-size: 14px; }
        input[type="text"], input[type="number"], input[type="date"], select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #2c3e50; box-shadow: 0 0 0 3px rgba(44,62,80,0.1); }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .stock-info { font-size: 12px; color: #666; margin-top: 4px; }
        .fine { color: #e74c3c; font-weight: 600; }
        .overdue { color: #e74c3c; font-weight: 600; font-size: 12px; }
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
                <a href="admin_borrowings.php"><span class="icon">📋</span> Kelola Peminjaman</a>
                <a href="admin_returns.php" class="active"><span class="icon">🔄</span> Kelola Pengembalian</a>
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
                <h1>Kelola Pengembalian</h1>
                <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Ajukan Pengembalian</button>
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
                    <h3>Daftar Pengembalian (<?= $total ?> total)</h3>
                    <form method="GET" class="filter-form">
                        <input type="text" name="search" placeholder="Cari nama, username, kode, nama alat..." value="<?= e($search) ?>" style="min-width: 200px;">
                        <select name="status">
                            <option value="">Semua Status</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Dikonfirmasi</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                        <?php if ($search || $status_filter): ?>
                            <a href="admin_returns.php" class="btn btn-outline btn-sm">Reset</a>
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
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th>Peminjam</th>
                                    <th>Alat</th>
                                    <th>Qty</th>
                                    <th>Tgl Minta</th>
                                    <th>Status</th>
                                    <th>Tgl Kembali</th>
                                    <th>Dikonfirmasi Oleh</th>
                                    <th>Denda</th>
                                    <th style="width: 120px;">Aksi</th>
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
                                        <strong><?= e($r['user_name']) ?></strong><br>
                                        <small style="color: #666;">@<?= e($r['username']) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= e($r['code']) ?> - <?= e($r['tool_name']) ?></strong><br>
                                        <small style="color: #666;"><?= e($r['category_name']) ?></small>
                                    </td>
                                    <td><?= $r['quantity'] ?> / <?= $r['borrow_qty'] ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                                    <td><span class="badge <?= $status_class[$r['status']] ?? '' ?>"><?= $status_label[$r['status']] ?? $r['status'] ?></span></td>
                                    <td>
                                        <?= $r['return_date'] ? date('d/m/Y', strtotime($r['return_date'])) : '-' ?>
                                        <?php if ($is_overdue): ?><span class="overdue"> TERLAMBAT</span><?php endif; ?>
                                    </td>
                                    <td><?= $r['processed_by_name'] ? e($r['processed_by_name']) : '-' ?></td>
                                    <td><?= $r['fine_amount'] > 0 ? '<span class="fine">Rp ' . number_format($r['fine_amount'], 0, ',', '.') . '</span>' : '-' ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <?php if ($can_delete): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Batalkan/hapus pengajuan ini?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="icon-btn delete">Batal</button>
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

            <div style="margin-top: 20px; padding: 16px; background: #f8f9fa; border-radius: 6px; font-size: 13px; color: #666;">
                <strong>Catatan:</strong>
                <ul style="margin: 8px 0 0 20px; padding: 0;">
                    <li>Admin dapat membuat pengajuan pengembalian atas nama peminjam (validasi trigger: <code>requested_by</code> harus sama dengan <code>borrowings.user_id</code>).</li>
                    <li>Hanya pengajuan <strong>pending</strong> atau <strong>rejected</strong> yang dapat dibatalkan/dihapus.</li>
                    <li>Pengembalian <strong>confirmed</strong> tidak dapat diubah/hapus (menggunakan <code>sp_confirm_return</code> via Petugas).</li>
                    <li>Konfirmasi pengembalian tetap dilakukan Petugas melalui halaman <a href="returns.php">Konfirmasi Kembali</a>.</li>
                </ul>
            </div>
        </main>
    </div>

    <!-- Add Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Ajukan Pengembalian (Admin)</h3>
                <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST" id="addForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label for="add_borrowing_id">Peminjaman *</label>
                    <select name="borrowing_id" id="add_borrowing_id" required onchange="updateReturnForm()">
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
                        <label for="add_requested_by">Peminjam (Otomatis)</label>
                        <input type="text" id="add_requested_by_name" readonly style="background: #f8f9fa;">
                        <input type="hidden" name="requested_by" id="add_requested_by">
                    </div>
                    <div class="form-group">
                        <label for="add_tool_info">Alat (Otomatis)</label>
                        <input type="text" id="add_tool_info" readonly style="background: #f8f9fa;">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="add_quantity">Jumlah Kembali *</label>
                        <input type="number" name="quantity" id="add_quantity" required min="1" value="1">
                        <div id="add_remaining_info" class="stock-info"></div>
                    </div>
                </div>
                <div class="modal-actions">
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
            document.getElementById('addModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
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
                document.querySelectorAll('.modal.active').forEach(m => closeModal(m.id));
            }
        });
    </script>
</body>
</html>