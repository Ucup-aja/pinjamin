<?php
// ============================================================
// PINJAMIN — Persetujuan Peminjaman (Petugas)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('petugas');

$flash = get_flash();
$errors = [];
$success = false;

$petugas_id = current_user()['id'];

// Handle POST - approve or reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    } else {
        $action = $_POST['action'] ?? '';
        $borrowing_id = (int)($_POST['borrowing_id'] ?? 0);

        if ($borrowing_id <= 0) {
            $errors[] = 'ID peminjaman tidak valid';
        } else {
            try {
                $pdo = get_pdo();

                if ($action === 'approve') {
                    // Panggil stored procedure
                    $stmt = $pdo->prepare("CALL sp_approve_borrowing(?, ?)");
                    $stmt->execute([$borrowing_id, $petugas_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor(); // Tutup cursor

                    if ($result && $result['success'] == '1') {
                        $success = true;
                        log_activity('BORROW_APPROVE', "Setujui peminjaman ID $borrowing_id: {$result['info']}");
                    } else {
                        $errors[] = $result['info'] ?? 'Persetujuan gagal';
                    }
                } elseif ($action === 'reject') {
                    // Atomic reject - hanya jika status masih pending
                    $stmt = $pdo->prepare("
                        UPDATE borrowings
                        SET status = 'rejected'
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$borrowing_id]);

                    if ($stmt->rowCount() > 0) {
                        $success = true;
                        log_activity('BORROW_REJECT', "Tolak peminjaman ID $borrowing_id");
                    } else {
                        // Cek status saat ini
                        $stmt = $pdo->prepare("SELECT status FROM borrowings WHERE id = ?");
                        $stmt->execute([$borrowing_id]);
                        $current = $stmt->fetch();
                        $errors[] = $current
                            ? "Peminjaman tidak bisa ditolak (status: {$current['status']})"
                            : 'Peminjaman tidak ditemukan';
                    }
                }
            } catch (PDOException $e) {
                error_log("Approval error: " . $e->getMessage());
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
$valid_statuses = ['pending', 'approved', 'partially_returned', 'returned', 'rejected'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'pending';
}

try {
    $pdo = get_pdo();

    // Build WHERE clause
    $where = "WHERE b.status = ?";
    $params = [$status_filter];

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings b $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get borrowings with user and tool info
    $stmt = $pdo->prepare("
        SELECT b.id, b.quantity, b.borrow_date, b.due_date, b.status, b.approved_at, b.created_at,
               u.name as user_name, u.username,
               t.code, t.name as tool_name, c.name as category_name
        FROM borrowings b
        JOIN users u ON u.id = b.user_id
        JOIN tools t ON t.id = b.tool_id
        JOIN categories c ON c.id = t.category_id
        $where
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $stmt->execute($params);
    $borrowings = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Approvals list error: " . $e->getMessage());
    $borrowings = [];
    $total = 0;
    $total_pages = 1;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persetujuan Peminjaman | Pinjamin</title>
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
        .pagination a:hover { background: #27ae60; color: white; border-color: #27ae60; }
        .pagination .current { background: #27ae60; color: white; border-color: #27ae60; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state h3 { margin-bottom: 8px; color: #666; }
        .filter-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-form select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .action-btns { display: flex; gap: 6px; }
        .icon-btn { padding: 6px 10px; border-radius: 4px; text-decoration: none; font-size: 13px; transition: all 0.2s; }
        .icon-btn.approve { background: #e8f5e9; color: #27ae60; }
        .icon-btn.approve:hover { background: #27ae60; color: white; }
        .icon-btn.reject { background: #fdeaea; color: #e74c3c; }
        .icon-btn.reject:hover { background: #e74c3c; color: white; }
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
                <a href="approvals.php" class="active"><span class="icon">✅</span> Persetujuan Pinjam</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #2ecc71; color: #27ae60;">Petugas</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Persetujuan Peminjaman</h1>
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
                        <select name="status" onchange="this.form.submit()">
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
                            <option value="partially_returned" <?= $status_filter === 'partially_returned' ? 'selected' : '' ?>>Sebagian Kembali</option>
                            <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Sudah Kembali</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                        <?php if ($page > 1): ?>
                            <input type="hidden" name="page" value="<?= $page ?>">
                        <?php endif; ?>
                    </form>
                </div>
                <div class="table-responsive">
                    <?php if (empty($borrowings)): ?>
                        <div class="empty-state">
                            <h3>Tidak ada data</h3>
                            <p>Belum ada peminjaman dengan status ini</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th>Peminjam</th>
                                    <th>Alat</th>
                                    <th>Jumlah</th>
                                    <th>Tgl Pinjam</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Status</th>
                                    <th>Disetujui</th>
                                    <th style="width: 160px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($borrowings as $b): 
                                    $status_class = [
                                        'pending' => 'badge-pending',
                                        'approved' => 'badge-approved',
                                        'partially_returned' => 'badge-partial',
                                        'returned' => 'badge-returned',
                                        'rejected' => 'badge-rejected',
                                    ];
                                    $status_label = [
                                        'pending' => 'Menunggu',
                                        'approved' => 'Disetujui',
                                        'partially_returned' => 'Sebagian Kembali',
                                        'returned' => 'Sudah Kembali',
                                        'rejected' => 'Ditolak',
                                    ];
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
                                    <td>
                                        <div class="action-btns">
                                            <?php if ($b['status'] === 'pending'): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Setujui peminjaman ini? Stok akan berkurang otomatis.');">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="borrowing_id" value="<?= $b['id'] ?>">
                                                    <button type="submit" class="icon-btn approve">Setujui</button>
                                                </form>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Tolak peminjaman ini? Stok tidak akan berubah.');">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="borrowing_id" value="<?= $b['id'] ?>">
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