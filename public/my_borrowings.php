<?php
// ============================================================
// PINJAMIN — Riwayat Peminjaman Saya (Peminjam)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

$flash = get_flash();

$uid = current_user()['id'];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Optional filter by status
$status_filter = $_GET['status'] ?? '';
$valid_statuses = ['pending', 'approved', 'partially_returned', 'returned', 'rejected'];
if ($status_filter && !in_array($status_filter, $valid_statuses, true)) {
    $status_filter = '';
}

try {
    $pdo = get_pdo();

    // Build WHERE clause
    $where = "WHERE b.user_id = ?";
    $params = [$uid];
    if ($status_filter) {
        $where .= " AND b.status = ?";
        $params[] = $status_filter;
    }

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings b $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Get borrowings with tool info
    $stmt = $pdo->prepare("
        SELECT b.id, b.quantity, b.borrow_date, b.due_date, b.status, b.approved_at, b.created_at,
               t.code, t.name as tool_name, c.name as category_name
        FROM borrowings b
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
    error_log("My borrowings error: " . $e->getMessage());
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
    <title>Riwayat Peminjaman | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px; background: #3498db; color: white; padding: 20px 0;
            position: fixed; height: 100vh; overflow-y: auto;
        }
        .sidebar .brand { padding: 0 20px 20px; border-bottom: 1px solid #4aa3db; margin-bottom: 20px; }
        .sidebar .brand h2 { font-size: 18px; font-weight: 600; }
        .sidebar .brand small { color: #a8d0e6; font-size: 12px; }
        .sidebar nav a { display: block; padding: 12px 20px; color: #d6eaf8; text-decoration: none; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar nav a:hover, .sidebar nav a.active { background: #2980b9; color: white; border-left-color: #f39c12; }
        .sidebar nav a .icon { margin-right: 10px; }
        .sidebar .user-info { padding: 20px; border-top: 1px solid #4aa3db; margin-top: 20px; font-size: 13px; color: #a8d0e6; }
        .sidebar .user-info strong { color: white; display: block; margin-bottom: 4px; }
        .sidebar .user-info a { color: #f39c12; text-decoration: none; font-size: 12px; }
        .main { flex: 1; margin-left: 260px; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header h1 { color: #2c3e50; font-size: 24px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary { background: #27ae60; color: white; }
        .btn-secondary:hover { background: #219a52; }
        .btn-outline { background: transparent; border: 1px solid #3498db; color: #3498db; }
        .btn-outline:hover { background: #3498db; color: white; }
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
        .pagination a:hover { background: #3498db; color: white; border-color: #3498db; }
        .pagination .current { background: #3498db; color: white; border-color: #3498db; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state h3 { margin-bottom: 8px; color: #666; }
        .filter-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-form select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
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
                <small>Peminjam Panel</small>
            </div>
            <nav>
                <a href="dashboard_peminjam.php"><span class="icon">📊</span> Dashboard</a>
                <a href="borrow.php"><span class="icon">📝</span> Ajukan Pinjam</a>
                <a href="my_borrowings.php" class="active"><span class="icon">📋</span> Riwayat Pinjam</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #5dade2; color: #3498db;">Peminjam</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Riwayat Peminjaman Saya</h1>
                <a href="borrow.php" class="btn btn-primary">Ajukan Pinjam Baru</a>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Daftar Peminjaman (<?= $total ?> total)</h3>
                    <form method="GET" class="filter-form">
                        <select name="status" onchange="this.form.submit()">
                            <option value="">Semua Status</option>
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
                            <h3>Belum ada riwayat peminjaman</h3>
                            <p>Klik "Ajukan Pinjam Baru" untuk memulai</p>
                            <a href="borrow.php" class="btn btn-primary" style="margin-top: 16px;">Ajukan Pinjam Baru</a>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th>Alat</th>
                                    <th>Jumlah</th>
                                    <th>Tanggal Pinjam</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Status</th>
                                    <th>Disetujui</th>
                                    <th>Diajukan</th>
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
                                        <strong><?= e($b['code']) ?> - <?= e($b['tool_name']) ?></strong><br>
                                        <small style="color: #666;"><?= e($b['category_name']) ?></small>
                                    </td>
                                    <td><?= $b['quantity'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($b['borrow_date'])) ?></td>
                                    <td><?= date('d/m/Y', strtotime($b['due_date'])) ?></td>
                                    <td><span class="badge <?= $status_class[$b['status']] ?? '' ?>"><?= $status_label[$b['status']] ?? $b['status'] ?></span></td>
                                    <td><?= $b['approved_at'] ? date('d/m/Y H:i', strtotime($b['approved_at'])) : '-' ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($b['created_at'])) ?></td>
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
                            <a href="?page=<?= $page - 1 ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>">&laquo; Sebelumnya</a>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $i ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>">Selanjutnya &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>