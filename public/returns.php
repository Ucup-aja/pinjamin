<?php
// ============================================================
// PINJAMIN — Konfirmasi Pengembalian (Petugas)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('petugas');

$flash = get_flash();
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
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Pengembalian | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px; background: #27ae60; color: white; padding: 20px 0;
            position: fixed; height: 100vh; overflow-y: auto;
        }
        .sidebar .brand { padding: 0 20px 20px; border-bottom: 1px solid #3dc475; margin-bottom: 20px; }
        .sidebar .brand h2 { font-size: 18px; font-weight: 600; }
        .sidebar .brand small { color: #a3d9b3; font-size: 12px; }
        .sidebar nav a { display: block; padding: 12px 20px; color: #d5f5e3; text-decoration: none; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar nav a:hover, .sidebar nav a.active { background: #219a52; color: white; border-left-color: #f39c12; }
        .sidebar nav a .icon { margin-right: 10px; }
        .sidebar .user-info { padding: 20px; border-top: 1px solid #3dc475; margin-top: 20px; font-size: 13px; color: #a3d9b3; }
        .sidebar .user-info strong { color: white; display: block; margin-bottom: 4px; }
        .sidebar .user-info a { color: #f39c12; text-decoration: none; font-size: 12px; }
        .main { flex: 1; margin-left: 260px; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header h1 { color: #2c3e50; font-size: 24px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #27ae60; color: white; }
        .btn-primary:hover { background: #219a52; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-outline { background: transparent; border: 1px solid #27ae60; color: #27ae60; }
        .btn-outline:hover { background: #27ae60; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .card-header { padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .card-header h3 { color: #2c3e50; font-size: 16px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; color: #666; font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover td { background: #fafafa; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-confirmed { background: #d5f5e3; color: #27ae60; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 6px; text-decoration: none; color: #333; border: 1px solid #ddd; }
        .pagination a:hover { background: #27ae60; color: white; border-color: #27ae60; }
        .pagination .current { background: #27ae60; color: white; border-color: #27ae60; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state h3 { margin-bottom: 8px; color: #666; }
        .filter-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-form select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .action-btns { display: flex; gap: 6px; flex-wrap: wrap; }
        .icon-btn { padding: 6px 10px; border-radius: 4px; text-decoration: none; font-size: 13px; transition: all 0.2s; border: none; cursor: pointer; }
        .icon-btn.confirm { background: #e8f5e9; color: #27ae60; }
        .icon-btn.confirm:hover { background: #27ae60; color: white; }
        .icon-btn.reject { background: #fdeaea; color: #e74c3c; }
        .icon-btn.reject:hover { background: #e74c3c; color: white; }
        .fine { color: #e74c3c; font-weight: 600; }
        .overdue { color: #e74c3c; font-weight: 600; font-size: 12px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 8px; padding: 24px; width: 90%; max-width: 500px; max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-header h3 { color: #2c3e50; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; font-size: 14px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
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
                <small>Petugas Panel</small>
            </div>
            <nav>
                <a href="dashboard_petugas.php"><span class="icon">📊</span> Dashboard</a>
                <a href="approvals.php"><span class="icon">✅</span> Persetujuan Pinjam</a>
                <a href="returns.php" class="active"><span class="icon">🔄</span> Konfirmasi Kembali</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #2ecc71; color: #27ae60;">Petugas</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Konfirmasi Pengembalian</h1>
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
                        <select name="status" onchange="this.form.submit()">
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
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
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th>Peminjam</th>
                                    <th>Alat</th>
                                    <th>Jumlah</th>
                                    <th>Tgl Minta</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Status</th>
                                    <th>Tgl Kembali</th>
                                    <th>Diproses</th>
                                    <th>Denda</th>
                                    <th style="width: 180px;">Aksi</th>
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
                                        'pending' => 'Pending',
                                        'confirmed' => 'Dikonfirmasi',
                                        'rejected' => 'Ditolak',
                                    ];
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
                                    <td>
                                        <?= date('d/m/Y', strtotime($r['due_date'])) ?>
                                        <?php if ($is_overdue): ?><span class="overdue"> TERLAMBAT</span><?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= $status_class[$r['status']] ?? '' ?>"><?= $status_label[$r['status']] ?? $r['status'] ?></span></td>
                                    <td><?= $r['return_date'] ? date('d/m/Y', strtotime($r['return_date'])) : '-' ?></td>
                                    <td><?= $r['processed_by_name'] ? e($r['processed_by_name']) : '-' ?></td>
                                    <td><?= $r['fine_amount'] > 0 ? '<span class="fine">Rp ' . number_format($r['fine_amount'], 0, ',', '.') . '</span>' : '-' ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <?php if ($r['status'] === 'pending'): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Konfirmasi pengembalian ini? Stok akan bertambah dan denda dihitung otomatis.');">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="confirm">
                                                    <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="icon-btn confirm">Konfirmasi</button>
                                                </form>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Tolak pengembalian ini? Status jadi rejected.');">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="icon-btn reject">Tolak</button>
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
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($status_filter) ?>">&laquo; Sebelumnya</a>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($status_filter) ?>">Selanjutnya &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>