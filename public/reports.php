<?php
// ============================================================
// PINJAMIN — Laporan (Petugas)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('petugas');

$flash = get_flash();

$pdo = get_pdo();

// Filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';
$report_type = $_GET['type'] ?? 'borrowings'; // borrowings, returns, fines
$is_print = isset($_GET['print']) && $_GET['print'] === '1';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

// Pagination (only for web view)
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clauses
$borrow_where = "WHERE DATE(b.borrow_date) BETWEEN ? AND ?";
$borrow_params = [$date_from, $date_to];

$return_where = "WHERE DATE(r.created_at) BETWEEN ? AND ?";
$return_params = [$date_from, $date_to];

if ($status_filter) {
    $valid_borrow_status = ['pending', 'approved', 'partially_returned', 'returned', 'rejected'];
    $valid_return_status = ['pending', 'confirmed', 'rejected'];
    
    if ($report_type === 'borrowings' && in_array($status_filter, $valid_borrow_status, true)) {
        $borrow_where .= " AND b.status = ?";
        $borrow_params[] = $status_filter;
    } elseif ($report_type === 'returns' && in_array($status_filter, $valid_return_status, true)) {
        $return_where .= " AND r.status = ?";
        $return_params[] = $status_filter;
    } elseif ($report_type === 'fines' && in_array($status_filter, $valid_return_status, true)) {
        $return_where .= " AND r.status = ?";
        $return_params[] = $status_filter;
    }
}

// Summary stats
try {
    // Total borrowings in period
    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(quantity), 0) FROM borrowings $borrow_where");
    $stmt->execute($borrow_params);
    [$total_borrowings, $total_borrow_qty] = $stmt->fetch(PDO::FETCH_NUM);

    // Total returns in period (confirmed only for fines)
    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(quantity), 0), COALESCE(SUM(fine_amount), 0) FROM returns $return_where AND r.status = 'confirmed'");
    $stmt->execute($return_params);
    [$total_confirmed_returns, $total_return_qty, $total_fines] = $stmt->fetch(PDO::FETCH_NUM);

    // Pending returns
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns $return_where AND r.status = 'pending'");
    $stmt->execute($return_params);
    $total_pending_returns = $stmt->fetchColumn();

    // Rejected returns
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns $return_where AND r.status = 'rejected'");
    $stmt->execute($return_params);
    $total_rejected_returns = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Reports summary error: " . $e->getMessage());
    $total_borrowings = $total_borrow_qty = $total_confirmed_returns = $total_return_qty = $total_fines = 0;
    $total_pending_returns = $total_rejected_returns = 0;
}

// Detail data based on report type
$data = [];
$total_pages = 1;
$total_rows = 0;

// For print mode, fetch ALL data (no pagination)
$use_pagination = !$is_print;
$current_per_page = $is_print ? 10000 : $per_page; // Large limit for print
$current_offset = $is_print ? 0 : $offset;

try {
    if ($report_type === 'borrowings') {
        // Count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings b JOIN users u ON u.id = b.user_id JOIN tools t ON t.id = b.tool_id $borrow_where");
        $stmt->execute($borrow_params);
        $total_rows = $stmt->fetchColumn();
        $total_pages = ceil($total_rows / $per_page);

        // Data
        $stmt = $pdo->prepare("
            SELECT b.id, b.quantity, b.borrow_date, b.due_date, b.status, b.approved_at, b.created_at,
                   u.name as user_name, u.username,
                   t.code, t.name as tool_name, c.name as category_name,
                   ap.name as approved_by_name
            FROM borrowings b
            JOIN users u ON u.id = b.user_id
            JOIN tools t ON t.id = b.tool_id
            JOIN categories c ON c.id = t.category_id
            LEFT JOIN users ap ON ap.id = b.approved_by
            $borrow_where
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $borrow_params[] = $current_per_page;
        $borrow_params[] = $current_offset;
        $stmt->execute($borrow_params);
        $data = $stmt->fetchAll();

    } elseif ($report_type === 'returns') {
        // Count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns r JOIN borrowings b ON b.id = r.borrowing_id JOIN users u ON u.id = b.user_id JOIN tools t ON t.id = b.tool_id $return_where");
        $stmt->execute($return_params);
        $total_rows = $stmt->fetchColumn();
        $total_pages = ceil($total_rows / $per_page);

        // Data
        $stmt = $pdo->prepare("
            SELECT r.id, r.quantity, r.status, r.created_at, r.return_date, r.fine_amount,
                   b.borrow_date, b.due_date, b.quantity as borrow_qty,
                   u.name as user_name, u.username,
                   t.code, t.name as tool_name, c.name as category_name,
                   proc.name as processed_by_name
            FROM returns r
            JOIN borrowings b ON b.id = r.borrowing_id
            JOIN users u ON u.id = b.user_id
            JOIN tools t ON t.id = b.tool_id
            JOIN categories c ON c.id = t.category_id
            LEFT JOIN users proc ON proc.id = r.processed_by
            $return_where
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $return_params[] = $current_per_page;
        $return_params[] = $current_offset;
        $stmt->execute($return_params);
        $data = $stmt->fetchAll();

    } elseif ($report_type === 'fines') {
        // Count - only confirmed returns with fines
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns r JOIN borrowings b ON b.id = r.borrowing_id JOIN users u ON u.id = b.user_id JOIN tools t ON t.id = b.tool_id $return_where AND r.status = 'confirmed' AND r.fine_amount > 0");
        $stmt->execute($return_params);
        $total_rows = $stmt->fetchColumn();
        $total_pages = ceil($total_rows / $per_page);

        // Data
        $stmt = $pdo->prepare("
            SELECT r.id, r.quantity, r.status, r.created_at, r.return_date, r.fine_amount,
                   b.borrow_date, b.due_date,
                   u.name as user_name, u.username,
                   t.code, t.name as tool_name,
                   proc.name as processed_by_name
            FROM returns r
            JOIN borrowings b ON b.id = r.borrowing_id
            JOIN users u ON u.id = b.user_id
            JOIN tools t ON t.id = b.tool_id
            LEFT JOIN users proc ON proc.id = r.processed_by
            $return_where
            AND r.status = 'confirmed' AND r.fine_amount > 0
            ORDER BY r.fine_amount DESC
            LIMIT ? OFFSET ?
        ");
        $return_params[] = $current_per_page;
        $return_params[] = $current_offset;
        $stmt->execute($return_params);
        $data = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log("Reports data error: " . $e->getMessage());
    $data = [];
    $total_pages = 1;
}

// Status label maps
$borrow_status_label = [
    'pending' => 'Pending', 'approved' => 'Disetujui', 'partially_returned' => 'Sebagian Kembali',
    'returned' => 'Dikembalikan', 'rejected' => 'Ditolak'
];
$borrow_status_class = [
    'pending' => 'badge-pending', 'approved' => 'badge-approved', 'partially_returned' => 'badge-partial',
    'returned' => 'badge-returned', 'rejected' => 'badge-rejected'
];
$return_status_label = ['pending' => 'Pending', 'confirmed' => 'Dikonfirmasi', 'rejected' => 'Ditolak'];
$return_status_class = ['pending' => 'badge-pending', 'confirmed' => 'badge-confirmed', 'rejected' => 'badge-rejected'];

$report_titles = [
    'borrowings' => 'Laporan Peminjaman',
    'returns' => 'Laporan Pengembalian',
    'fines' => 'Rekapitulasi Denda'
];

// Print mode: render print layout and exit
if ($is_print) {
    $print_title = $report_titles[$report_type];
    $period_label = date('d/m/Y', strtotime($date_from)) . ' - ' . date('d/m/Y', strtotime($date_to));
    $status_label_text = '';
    if ($status_filter) {
        if ($report_type === 'borrowings') {
            $status_label_text = $borrow_status_label[$status_filter] ?? $status_filter;
        } else {
            $status_label_text = $return_status_label[$status_filter] ?? $status_filter;
        }
    }
    $now = date('d/m/Y H:i');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($print_title) ?> - Cetak</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #222; }
        .print-container { max-width: 210mm; margin: 0 auto; padding: 15mm 10mm; }
        
        /* Header */
        .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #2c3e50; padding-bottom: 10px; }
        .print-header h1 { font-size: 20px; font-weight: 700; color: #2c3e50; margin-bottom: 4px; }
        .print-header .subtitle { font-size: 13px; color: #555; margin-bottom: 2px; }
        .print-header .meta { font-size: 11px; color: #777; }
        .print-header .meta span { margin: 0 10px; }
        
        /* Summary */
        .print-summary { display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 11px; flex-wrap: wrap; gap: 8px; }
        .print-summary .item { background: #f5f5f5; padding: 6px 12px; border-radius: 4px; }
        .print-summary .label { color: #666; font-weight: 500; }
        .print-summary .value { color: #2c3e50; font-weight: 700; margin-left: 6px; }
        
        /* Table */
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th, td { padding: 5px 6px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; color: #333; font-weight: 600; text-transform: uppercase; font-size: 9px; }
        tr:nth-child(even) td { background: #fafafa; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 8px; font-size: 9px; font-weight: 600; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-partial { background: #d6eaf8; color: #3498db; }
        .badge-returned { background: #d5f5e3; color: #27ae60; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-confirmed { background: #d5f5e3; color: #27ae60; }
        .fine { color: #e74c3c; font-weight: 600; }
        
        /* Footer */
        .print-footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 10px; color: #777; display: flex; justify-content: space-between; }
        
        /* Page break control */
        @media print {
            body { margin: 0; padding: 0; }
            .print-container { padding: 10mm; }
            thead { display: table-header-group; }
            tbody { display: table-row-group; }
            tr { page-break-inside: avoid; }
            @page { margin: 10mm; }
        }
        
        @page { size: A4; margin: 15mm 10mm; }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="print-header">
            <h1>PINJAMIN</h1>
            <div class="subtitle"><?= e($print_title) ?></div>
            <div class="meta">
                <span>Periode: <?= e($period_label) ?></span>
                <?php if ($status_label_text): ?><span>Status: <?= e($status_label_text) ?></span><?php endif; ?>
                <span>Dicetak: <?= e($now) ?></span>
            </div>
        </div>
        
        <div class="print-summary">
            <?php if ($report_type === 'borrowings'): ?>
                <div class="item"><span class="label">Total Transaksi:</span><span class="value"><?= $total_rows ?></span></div>
                <div class="item"><span class="label">Total Unit:</span><span class="value"><?= array_sum(array_column($data, 'quantity')) ?></span></div>
            <?php elseif ($report_type === 'returns'): ?>
                <div class="item"><span class="label">Total Transaksi:</span><span class="value"><?= $total_rows ?></span></div>
                <div class="item"><span class="label">Dikonfirmasi:</span><span class="value"><?= count(array_filter($data, fn($r) => $r['status'] === 'confirmed')) ?></span></div>
                <div class="item"><span class="label">Pending:</span><span class="value"><?= count(array_filter($data, fn($r) => $r['status'] === 'pending')) ?></span></div>
                <div class="item"><span class="label">Ditolak:</span><span class="value"><?= count(array_filter($data, fn($r) => $r['status'] === 'rejected')) ?></span></div>
                <div class="item"><span class="label">Total Unit Dikembalikan:</span><span class="value"><?= array_sum(array_column($data, 'quantity')) ?></span></div>
            <?php else: ?>
                <div class="item"><span class="label">Total Transaksi:</span><span class="value"><?= $total_rows ?></span></div>
                <div class="item"><span class="label">Total Denda:</span><span class="value">Rp <?= number_format(array_sum(array_column($data, 'fine_amount')), 0, ',', '.') ?></span></div>
            <?php endif; ?>
        </div>
        
        <table>
            <thead>
                <tr>
                    <?php if ($report_type === 'borrowings'): ?>
                        <th style="width: 5%;">No</th>
                        <th style="width: 12%;">Tgl Pinjam</th>
                        <th style="width: 18%;">Peminjam</th>
                        <th style="width: 25%;">Alat</th>
                        <th style="width: 6%;" class="text-center">Qty</th>
                        <th style="width: 12%;">Status</th>
                    <?php elseif ($report_type === 'returns'): ?>
                        <th style="width: 5%;">No</th>
                        <th style="width: 12%;">Tgl Kembali</th>
                        <th style="width: 18%;">Peminjam</th>
                        <th style="width: 25%;">Alat</th>
                        <th style="width: 6%;" class="text-center">Qty</th>
                        <th style="width: 12%;">Status</th>
                    <?php else: ?>
                        <th style="width: 5%;">No</th>
                        <th style="width: 12%;">Tgl Kembali</th>
                        <th style="width: 18%;">Peminjam</th>
                        <th style="width: 25%;">Alat</th>
                        <th style="width: 6%;" class="text-center">Qty</th>
                        <th style="width: 12%;" class="text-right">Denda</th>
                        <th style="width: 8%;" class="text-center">Hari</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                    <tr>
                        <td colspan="<?= $report_type === 'fines' ? 7 : 6 ?>" style="text-align: center; padding: 20px; color: #999;">Tidak ada data</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data as $i => $row): ?>
                        <tr>
                            <?php if ($report_type === 'borrowings'): ?>
                                <td class="text-center"><?= $i + 1 ?></td>
                                <td><?= date('d/m/Y', strtotime($row['borrow_date'])) ?></td>
                                <td>
                                    <strong><?= e($row['user_name']) ?></strong><br>
                                    <small style="color: #666;">@<?= e($row['username']) ?></small>
                                </td>
                                <td>
                                    <strong><?= e($row['code']) ?> - <?= e($row['tool_name']) ?></strong><br>
                                    <small style="color: #666;"><?= e($row['category_name']) ?></small>
                                </td>
                                <td class="text-center"><?= $row['quantity'] ?></td>
                                <td class="text-center"><span class="badge <?= $borrow_status_class[$row['status']] ?? '' ?>"><?= $borrow_status_label[$row['status']] ?? $row['status'] ?></span></td>
                            <?php elseif ($report_type === 'returns'): ?>
                                <td class="text-center"><?= $i + 1 ?></td>
                                <td><?= $row['return_date'] ? date('d/m/Y', strtotime($row['return_date'])) : '-' ?></td>
                                <td>
                                    <strong><?= e($row['user_name']) ?></strong><br>
                                    <small style="color: #666;">@<?= e($row['username']) ?></small>
                                </td>
                                <td>
                                    <strong><?= e($row['code']) ?> - <?= e($row['tool_name']) ?></strong><br>
                                    <small style="color: #666;"><?= e($row['category_name']) ?></small>
                                </td>
                                <td class="text-center"><?= $row['quantity'] ?> / <?= $row['borrow_qty'] ?></td>
                                <td class="text-center"><span class="badge <?= $return_status_class[$row['status']] ?? '' ?>"><?= $return_status_label[$row['status']] ?? $row['status'] ?></span></td>
                            <?php else: ?>
                                <td class="text-center"><?= $i + 1 ?></td>
                                <td><?= $row['return_date'] ? date('d/m/Y', strtotime($row['return_date'])) : '-' ?></td>
                                <td>
                                    <strong><?= e($row['user_name']) ?></strong><br>
                                    <small style="color: #666;">@<?= e($row['username']) ?></small>
                                </td>
                                <td>
                                    <strong><?= e($row['code']) ?> - <?= e($row['tool_name']) ?></strong>
                                </td>
                                <td class="text-center"><?= $row['quantity'] ?></td>
                                <td class="text-right fine">Rp <?= number_format($row['fine_amount'], 0, ',', '.') ?></td>
                                <td class="text-center">
                                    <?php 
                                    if ($row['return_date'] && $row['due_date']) {
                                        $late = (int)max(0, (strtotime($row['return_date']) - strtotime($row['due_date'])) / 86400);
                                        echo $late > 0 ? $late : '-';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="print-footer">
            <span>PINJAMIN - Laporan otomatis</span>
            <span>Halaman dicetak pada <?= $now ?></span>
        </div>
    </div>
    <script>window.onload = () => window.print();</script>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan | Pinjamin</title>
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
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 16px; }
        .header h1 { color: #2c3e50; font-size: 24px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #27ae60; color: white; }
        .btn-primary:hover { background: #219a52; }
        .btn-secondary { background: #3498db; color: white; }
        .btn-secondary:hover { background: #2980b9; }
        .btn-outline { background: transparent; border: 1px solid #27ae60; color: #27ae60; }
        .btn-outline:hover { background: #27ae60; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-print { background: #8e44ad; color: white; }
        .btn-print:hover { background: #7d3c98; }
        @media print { .btn-print { display: none; } .sidebar { display: none; } .main { margin-left: 0; padding: 0; } .header { display: none; } .filter-form { display: none; } .card { box-shadow: none; border: 1px solid #ddd; } }
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
        .badge-confirmed { background: #d5f5e3; color: #27ae60; }
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
        .filter-form select, .filter-form input[type="date"] { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .stats-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card .label { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .value { color: #2c3e50; font-size: 24px; font-weight: 700; margin-top: 4px; }
        .tab-nav { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-nav a { padding: 10px 16px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 14px; transition: all 0.2s; }
        .tab-nav a.active { background: #27ae60; color: white; }
        .tab-nav a:not(.active) { background: #e8f5e9; color: #27ae60; }
        .tab-nav a:not(.active):hover { background: #d4edda; }
        .fine { color: #e74c3c; font-weight: 600; }
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
                <a href="returns.php"><span class="icon">🔄</span> Konfirmasi Kembali</a>
                <a href="reports.php" class="active"><span class="icon">📊</span> Laporan</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #2ecc71; color: #27ae60;">Petugas</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Laporan</h1>
                <div style="display: flex; gap: 10px;">
                    <a href="?type=<?= e($report_type) ?>&date_from=<?= e($date_from) ?>&date_to=<?= e($date_to) ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>&print=1" target="_blank" class="btn btn-print">🖨 Cetak Laporan Lengkap</a>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <a href="?type=borrowings<?= $date_from ? '&date_from=' . urlencode($date_from) : '' ?><?= $date_to ? '&date_to=' . urlencode($date_to) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>" class="<?= $report_type === 'borrowings' ? 'active' : '' ?>">📋 Peminjaman</a>
                <a href="?type=returns<?= $date_from ? '&date_from=' . urlencode($date_from) : '' ?><?= $date_to ? '&date_to=' . urlencode($date_to) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>" class="<?= $report_type === 'returns' ? 'active' : '' ?>">🔄 Pengembalian</a>
                <a href="?type=fines<?= $date_from ? '&date_from=' . urlencode($date_from) : '' ?><?= $date_to ? '&date_to=' . urlencode($date_to) : '' ?>" class="<?= $report_type === 'fines' ? 'active' : '' ?>">💰 Denda</a>
            </div>

            <!-- Filter Form -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <h3>Filter</h3>
                </div>
                <div style="padding: 20px;">
                    <form method="GET" class="filter-form">
                        <input type="hidden" name="type" value="<?= e($report_type) ?>">
                        <label>Dari:</label>
                        <input type="date" name="date_from" value="<?= e($date_from) ?>">
                        <label>Sampai:</label>
                        <input type="date" name="date_to" value="<?= e($date_to) ?>">
                        
                        <?php if ($report_type === 'borrowings'): ?>
                            <label>Status:</label>
                            <select name="status">
                                <option value="">Semua</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
                                <option value="partially_returned" <?= $status_filter === 'partially_returned' ? 'selected' : '' ?>>Sebagian Kembali</option>
                                <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Dikembalikan</option>
                                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
                            </select>
                        <?php elseif ($report_type === 'returns'): ?>
                            <label>Status:</label>
                            <select name="status">
                                <option value="">Semua</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Dikonfirmasi</option>
                                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="status" value="confirmed">
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-primary">Terapkan</button>
                        <a href="reports.php?type=<?= e($report_type) ?>" class="btn btn-outline">Reset</a>
                    </form>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="stats-summary">
                <?php if ($report_type === 'borrowings'): ?>
                    <div class="stat-card">
                        <div class="label">Total Peminjaman</div>
                        <div class="value"><?= $total_borrowings ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Total Unit Dipinjam</div>
                        <div class="value"><?= $total_borrow_qty ?></div>
                    </div>
                <?php elseif ($report_type === 'returns'): ?>
                    <div class="stat-card">
                        <div class="label">Dikonfirmasi</div>
                        <div class="value"><?= $total_confirmed_returns ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Pending</div>
                        <div class="value"><?= $total_pending_returns ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Ditolak</div>
                        <div class="value"><?= $total_rejected_returns ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Unit Dikembalikan</div>
                        <div class="value"><?= $total_return_qty ?></div>
                    </div>
                <?php else: ?>
                    <div class="stat-card">
                        <div class="label">Total Denda</div>
                        <div class="value">Rp <?= number_format($total_fines, 0, ',', '.') ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Transaksi Berdenda</div>
                        <div class="value"><?= $total_rows ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Data Table -->
            <div class="card">
                <div class="card-header">
                    <h3><?= $report_titles[$report_type] ?> (<?= $total_rows ?> total)</h3>
                </div>
                <div class="table-responsive">
                    <?php if (empty($data)): ?>
                        <div class="empty-state">
                            <h3>Tidak ada data</h3>
                            <p>Coba ubah filter tanggal atau status</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <?php if ($report_type === 'borrowings'): ?>
                                        <th style="width: 60px;">ID</th>
                                        <th>Peminjam</th>
                                        <th>Alat</th>
                                        <th>Qty</th>
                                        <th>Tgl Pinjam</th>
                                        <th>Jatuh Tempo</th>
                                        <th>Status</th>
                                        <th>Disetujui</th>
                                        <th>Oleh</th>
                                        <th>Dibuat</th>
                                    <?php elseif ($report_type === 'returns'): ?>
                                        <th style="width: 60px;">ID</th>
                                        <th>Peminjam</th>
                                        <th>Alat</th>
                                        <th>Qty</th>
                                        <th>Tgl Minta</th>
                                        <th>Status</th>
                                        <th>Tgl Kembali</th>
                                        <th>Dikonfirmasi Oleh</th>
                                        <th>Denda</th>
                                    <?php else: ?>
                                        <th style="width: 60px;">ID</th>
                                        <th>Peminjam</th>
                                        <th>Alat</th>
                                        <th>Qty</th>
                                        <th>Tgl Pinjam</th>
                                        <th>Jatuh Tempo</th>
                                        <th>Tgl Kembali</th>
                                        <th>Dikonfirmasi Oleh</th>
                                        <th>Denda</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data as $row): ?>
                                <tr>
                                    <?php if ($report_type === 'borrowings'): ?>
                                        <td><?= $row['id'] ?></td>
                                        <td>
                                            <strong><?= e($row['user_name']) ?></strong><br>
                                            <small style="color: #666;">@<?= e($row['username']) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= e($row['code']) ?> - <?= e($row['tool_name']) ?></strong><br>
                                            <small style="color: #666;"><?= e($row['category_name']) ?></small>
                                        </td>
                                        <td><?= $row['quantity'] ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['borrow_date'])) ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['due_date'])) ?></td>
                                        <td><span class="badge <?= $borrow_status_class[$row['status']] ?? '' ?>"><?= $borrow_status_label[$row['status']] ?? $row['status'] ?></span></td>
                                        <td><?= $row['approved_at'] ? date('d/m/Y H:i', strtotime($row['approved_at'])) : '-' ?></td>
                                        <td><?= $row['approved_by_name'] ? e($row['approved_by_name']) : '-' ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                    <?php elseif ($report_type === 'returns'): ?>
                                        <td><?= $row['id'] ?></td>
                                        <td>
                                            <strong><?= e($row['user_name']) ?></strong><br>
                                            <small style="color: #666;">@<?= e($row['username']) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= e($row['code']) ?> - <?= e($row['tool_name']) ?></strong><br>
                                            <small style="color: #666;"><?= e($row['category_name']) ?></small>
                                        </td>
                                        <td><?= $row['quantity'] ?> / <?= $row['borrow_qty'] ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                        <td><span class="badge <?= $return_status_class[$row['status']] ?? '' ?>"><?= $return_status_label[$row['status']] ?? $row['status'] ?></span></td>
                                        <td><?= $row['return_date'] ? date('d/m/Y', strtotime($row['return_date'])) : '-' ?></td>
                                        <td><?= $row['processed_by_name'] ? e($row['processed_by_name']) : '-' ?></td>
                                        <td><?= $row['fine_amount'] > 0 ? '<span class="fine">Rp ' . number_format($row['fine_amount'], 0, ',', '.') . '</span>' : '-' ?></td>
                                    <?php else: ?>
                                        <td><?= $row['id'] ?></td>
                                        <td>
                                            <strong><?= e($row['user_name']) ?></strong><br>
                                            <small style="color: #666;">@<?= e($row['username']) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= e($row['code']) ?> - <?= e($row['tool_name']) ?></strong>
                                        </td>
                                        <td><?= $row['quantity'] ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['borrow_date'])) ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['due_date'])) ?></td>
                                        <td><?= $row['return_date'] ? date('d/m/Y', strtotime($row['return_date'])) : '-' ?></td>
                                        <td><?= $row['processed_by_name'] ? e($row['processed_by_name']) : '-' ?></td>
                                        <td><span class="fine">Rp <?= number_format($row['fine_amount'], 0, ',', '.') ?></span></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1 && !$is_print): ?>
                <div style="padding: 20px;">
                    <div class="pagination">
                        <?php 
                        $base_params = "type=" . urlencode($report_type) . "&date_from=" . urlencode($date_from) . "&date_to=" . urlencode($date_to);
                        if ($status_filter) $base_params .= "&status=" . urlencode($status_filter);
                        ?>
                        <?php if ($page > 1): ?>
                            <a href="?<?= $base_params ?>&page=<?= $page - 1 ?>">&laquo; Sebelumnya</a>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?<?= $base_params ?>&page=<?= $i ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?= $base_params ?>&page=<?= $page + 1 ?>">Selanjutnya &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>