<?php
// ============================================================
// PINJAMIN — Dashboard Admin
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$user = current_user();
$flash = get_flash();

// Fetch dashboard stats
try {
    $pdo = get_pdo();
    
    $stat_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stat_categories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $stat_tools = $pdo->query("SELECT COUNT(*) FROM tools")->fetchColumn();
    $stat_pending = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status = 'pending'")->fetchColumn();
    $stat_return_pending = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'pending'")->fetchColumn();
    $stat_active_borrowings = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('approved','partially_returned')")->fetchColumn();
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stat_users = $stat_categories = $stat_tools = $stat_pending = $stat_return_pending = $stat_active_borrowings = 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header {
            background: #2c3e50; color: white; padding: 16px 24px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 20px; font-weight: 600; }
        .user-info { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .badge { background: #e74c3c; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }
        .welcome { margin-bottom: 30px; }
        .welcome h2 { color: #2c3e50; margin-bottom: 8px; }
        .welcome p { color: #666; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .card {
            background: white; border-radius: 8px; padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #e74c3c;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .card h3 { color: #2c3e50; margin-bottom: 12px; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .card p { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 16px; }
        .btn {
            display: inline-block; padding: 10px 18px; background: #e74c3c; color: white;
            text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            border: none; cursor: pointer; transition: background 0.2s;
        }
        .btn:hover { background: #c0392b; }
        .btn-secondary { background: #3498db; }
        .btn-secondary:hover { background: #2980b9; }
        .btn-outline { background: transparent; border: 1px solid #e74c3c; color: #e74c3c; }
        .btn-outline:hover { background: #e74c3c; color: white; }
        .nav-links { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .flash { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }
        .flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .flash-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .flash-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-right: 4px; background: #e8f0fe; color: #3498db; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card .label { color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .value { color: #2c3e50; font-size: 28px; font-weight: 700; margin-top: 4px; }
    </style>
</head>
<body>
    <header class="header">
        <h1>Pinjamin</h1>
        <div class="user-info">
            <span>Halo, <strong><?= e($user['name']) ?></strong></span>
            <span class="badge">Admin</span>
            <a href="<?= base_url('logout.php') ?>" class="btn btn-outline" style="padding: 8px 14px; font-size: 13px;">Logout</a>
        </div>
    </header>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash flash-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="welcome">
            <h2>Dashboard Admin</h2>
            <p>Kelola pengguna, kategori, alat, dan pantau seluruh transaksi peminjaman.</p>
        </div>

        <div class="stats">
            <div class="stat-card">
                <div class="label">Total Pengguna</div>
                <div class="value"><?= $stat_users ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Kategori</div>
                <div class="value"><?= $stat_categories ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Alat</div>
                <div class="value"><?= $stat_tools ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Peminjaman Pending</div>
                <div class="value"><?= $stat_pending ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(butuh persetujuan petugas)</div>
            </div>
            <div class="stat-card">
                <div class="label">Pengembalian Pending</div>
                <div class="value"><?= $stat_return_pending ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(butuh konfirmasi petugas)</div>
            </div>
            <div class="stat-card">
                <div class="label">Peminjaman Aktif</div>
                <div class="value"><?= $stat_active_borrowings ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(approved + sebagian kembali)</div>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h3>👥 Kelola Pengguna</h3>
                <p>Tambah, edit, nonaktifkan, atau hapus akun pengguna (admin, petugas, peminjam).</p>
                <div class="nav-links">
                    <a href="users.php" class="btn">Kelola Pengguna</a>
                </div>
            </div>

            <div class="card">
                <h3>📁 Kelola Kategori</h3>
                <p>Kelola kategori alat: tambah, edit, atau hapus kategori.</p>
                <div class="nav-links">
                    <a href="categories.php" class="btn">Kelola Kategori</a>
                </div>
            </div>

            <div class="card">
                <h3>🔧 Kelola Alat</h3>
                <p>Kelola inventaris alat: tambah, edit, hapus, dan atur stok.</p>
                <div class="nav-links">
                    <a href="tools.php" class="btn">Kelola Alat</a>
                </div>
            </div>

            <div class="card">
                <h3>📋 Kelola Peminjaman</h3>
                <p>Status borrowing: <span class="badge">pending</span> → <span class="badge">approved</span> → <span class="badge">partially_returned</span> → <span class="badge">returned</span> / <span class="badge">rejected</span>.</p>
                <div class="nav-links">
                    <a href="admin_borrowings.php" class="btn">Kelola Peminjaman</a>
                </div>
            </div>

            <div class="card">
                <h3>🔄 Kelola Pengembalian</h3>
                <p>Status return: <span class="badge">pending</span> → <span class="badge">confirmed</span> / <span class="badge">rejected</span> (denda via fn_calculate_fine).</p>
                <div class="nav-links">
                    <a href="admin_returns.php" class="btn">Kelola Pengembalian</a>
                </div>
            </div>

            <div class="card">
                <h3>📊 Log Aktivitas</h3>
                <p>Pantau aktivitas sistem: login, perubahan data, persetujuan, konfirmasi.</p>
                <div class="nav-links">
                    <a href="activity_log.php" class="btn">Lihat Log</a>
                </div>
            </div>
        </div>
    </div>

    </body>
</html>