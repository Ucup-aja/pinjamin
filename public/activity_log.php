<?php
// ============================================================
// PINJAMIN — Log Aktivitas (Admin)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$flash = get_flash();

// Configuration
$log_file = __DIR__ . '/../logs/activity.log';
$max_lines = 500; // Limit lines read for performance
$lines_per_page = 50;

// Page
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $lines_per_page;

// Read log file (newest first)
$entries = [];
$total_entries = 0;

if (file_exists($log_file) && is_readable($log_file)) {
    // Read file into array (reverse for newest first)
    $all_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $all_lines = array_reverse($all_lines);
    $total_entries = count($all_lines);
    
    // Paginate
    $entries = array_slice($all_lines, $offset, $lines_per_page);
}

$total_pages = ceil($total_entries / $lines_per_page);

// Parse log entries for display
$parsed_entries = [];
foreach ($entries as $line) {
    // Format: [2026-11-18 10:30:45] User: username (ID: 1, Role: admin) | Action: USER_ADD | Details
    if (preg_match('/^\[(.*?)\]\s+User:\s+(.*?)\s+\(ID:\s+(.*?),\s+Role:\s+(.*?)\)\s+\|\s+Action:\s+(.*?)\s+\|\s+(.*)$/', $line, $matches)) {
        $parsed_entries[] = [
            'timestamp' => $matches[1],
            'username' => $matches[2],
            'user_id' => $matches[3],
            'role' => $matches[4],
            'action' => $matches[5],
            'details' => $matches[6]
        ];
    } else {
        // Fallback for malformed lines
        $parsed_entries[] = [
            'timestamp' => '-',
            'username' => '-',
            'user_id' => '-',
            'role' => '-',
            'action' => '-',
            'details' => e($line)
        ];
    }
}

// Action type badges
$action_badges = [
    'LOGIN' => 'badge-login',
    'LOGOUT' => 'badge-logout',
    'USER_ADD' => 'badge-add',
    'USER_EDIT' => 'badge-edit',
    'USER_DELETE' => 'badge-delete',
    'CATEGORY_ADD' => 'badge-add',
    'CATEGORY_EDIT' => 'badge-edit',
    'CATEGORY_DELETE' => 'badge-delete',
    'TOOL_ADD' => 'badge-add',
    'TOOL_EDIT' => 'badge-edit',
    'TOOL_DELETE' => 'badge-delete',
    'BORROW_REQUEST' => 'badge-request',
    'BORROW_APPROVE' => 'badge-approve',
    'BORROW_REJECT' => 'badge-reject',
    'RETURN_REQUEST' => 'badge-request',
    'RETURN_CONFIRM' => 'badge-confirm',
    'RETURN_REJECT' => 'badge-reject',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Aktivitas | Pinjamin</title>
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
        .badge-login { background: #d5f5e3; color: #27ae60; }
        .badge-logout { background: #f8d7da; color: #721c24; }
        .badge-add { background: #d4edda; color: #155724; }
        .badge-edit { background: #d6eaf8; color: #3498db; }
        .badge-delete { background: #f8d7da; color: #721c24; }
        .badge-request { background: #fff3cd; color: #856404; }
        .badge-approve { background: #d4edda; color: #155724; }
        .badge-reject { background: #f8d7da; color: #721c24; }
        .badge-confirm { background: #d5f5e3; color: #27ae60; }
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
        .log-detail { font-family: monospace; font-size: 12px; color: #666; white-space: pre-wrap; word-break: break-word; max-width: 400px; }
        .role-badge { display: inline-block; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .role-admin { background: #f8d7da; color: #721c24; }
        .role-petugas { background: #d5f5e3; color: #27ae60; }
        .role-peminjam { background: #d6eaf8; color: #3498db; }
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
                <a href="admin_returns.php"><span class="icon">🔄</span> Kelola Pengembalian</a>
                <a href="activity_log.php" class="active"><span class="icon">📊</span> Log Aktivitas</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #e74c3c; color: white;">Admin</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Log Aktivitas</h1>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span style="color: #666; font-size: 14px;">Total: <?= $total_entries ?> entri</span>
                    <a href="?page=1" class="btn btn-secondary btn-sm">⟳ Refresh</a>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Riwayat Aktivitas Sistem (<?= $total_entries ?> total, menampilkan <?= count($parsed_entries) ?>)</h3>
                </div>
                <div class="table-responsive">
                    <?php if (empty($parsed_entries)): ?>
                        <div class="empty-state">
                            <h3>Belum ada log aktivitas</h3>
                            <p>Log akan tercatat saat pengguna melakukan aksi di sistem</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 160px;">Waktu</th>
                                    <th style="width: 140px;">Pengguna</th>
                                    <th style="width: 120px;">Aksi</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parsed_entries as $entry): 
                                    $badge_class = $action_badges[$entry['action']] ?? 'badge-request';
                                    $role_class = 'role-' . strtolower($entry['role']);
                                ?>
                                <tr>
                                    <td><?= e($entry['timestamp']) ?></td>
                                    <td>
                                        <strong><?= e($entry['username']) ?></strong><br>
                                        <small style="color: #666;">ID: <?= e($entry['user_id']) ?></small>
                                        <span class="role-badge <?= $role_class ?>"><?= e($entry['role']) ?></span>
                                    </td>
                                    <td><span class="badge <?= $badge_class ?>"><?= e($entry['action']) ?></span></td>
                                    <td><span class="log-detail"><?= e($entry['details']) ?></span></td>
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

            <div style="margin-top: 20px; padding: 16px; background: #f8f9fa; border-radius: 6px; font-size: 13px; color: #666;">
                <strong>Catatan:</strong>
                <ul style="margin: 8px 0 0 20px; padding: 0;">
                    <li>Log disimpan di file <code>logs/activity.log</code> (di luar DocumentRoot).</li>
                    <li>Hanya Admin yang dapat melihat halaman ini.</li>
                    <li>Password, token, dan kredensial <strong>tidak</strong> dicatat dalam log.</li>
                    <li>Ditampilkan maksimal <?= $max_lines ?> entri terbaru untuk performa.</li>
                </ul>
            </div>
        </main>
    </div>
</body>
</html>