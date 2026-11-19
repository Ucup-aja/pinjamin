<?php
// ============================================================
// PINJAMIN — Log Aktivitas (Admin)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

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
    'LOGIN' => 'badge-confirmed',
    'LOGOUT' => 'badge-rejected',
    'USER_ADD' => 'badge-approved',
    'USER_EDIT' => 'badge-partial',
    'USER_DELETE' => 'badge-rejected',
    'CATEGORY_ADD' => 'badge-approved',
    'CATEGORY_EDIT' => 'badge-partial',
    'CATEGORY_DELETE' => 'badge-rejected',
    'TOOL_ADD' => 'badge-approved',
    'TOOL_EDIT' => 'badge-partial',
    'TOOL_DELETE' => 'badge-rejected',
    'BORROW_REQUEST' => 'badge-pending',
    'BORROW_APPROVE' => 'badge-approved',
    'BORROW_REJECT' => 'badge-rejected',
    'RETURN_REQUEST' => 'badge-pending',
    'RETURN_CONFIRM' => 'badge-confirmed',
    'RETURN_REJECT' => 'badge-rejected',
    'ADMIN_BORROW_ADD' => 'badge-approved',
    'ADMIN_BORROW_EDIT' => 'badge-partial',
    'ADMIN_BORROW_DELETE' => 'badge-rejected',
    'ADMIN_RETURN_ADD' => 'badge-approved',
    'ADMIN_RETURN_DELETE' => 'badge-rejected',
];

$action_labels = [
    'LOGIN' => 'Masuk',
    'LOGOUT' => 'Keluar',
    'USER_ADD' => 'Tambah Pengguna',
    'USER_EDIT' => 'Ubah Pengguna',
    'USER_DELETE' => 'Hapus Pengguna',
    'CATEGORY_ADD' => 'Tambah Kategori',
    'CATEGORY_EDIT' => 'Ubah Kategori',
    'CATEGORY_DELETE' => 'Hapus Kategori',
    'TOOL_ADD' => 'Tambah Alat',
    'TOOL_EDIT' => 'Ubah Alat',
    'TOOL_DELETE' => 'Hapus Alat',
    'BORROW_REQUEST' => 'Ajukan Peminjaman',
    'BORROW_APPROVE' => 'Setujui Peminjaman',
    'BORROW_REJECT' => 'Tolak Peminjaman',
    'RETURN_REQUEST' => 'Ajukan Pengembalian',
    'RETURN_CONFIRM' => 'Konfirmasi Pengembalian',
    'RETURN_REJECT' => 'Tolak Pengembalian',
    'ADMIN_BORROW_ADD' => 'Tambah Peminjaman (Admin)',
    'ADMIN_BORROW_EDIT' => 'Ubah Peminjaman (Admin)',
    'ADMIN_BORROW_DELETE' => 'Hapus Peminjaman (Admin)',
    'ADMIN_RETURN_ADD' => 'Tambah Pengembalian (Admin)',
    'ADMIN_RETURN_DELETE' => 'Hapus Pengembalian (Admin)',
];

$pageTitle = 'Log Aktivitas';
$currentPage = 'activity_log.php';

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Log Aktivitas</h1>
    <div class="page-actions">
      <span class="text-muted">Total: <?= $total_entries ?> entri</span>
      <a href="?page=1" class="btn btn-secondary btn-sm">&#8635; Refresh</a>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card mb-6">
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
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">Waktu</th>
              <th scope="col">Pengguna</th>
              <th scope="col">Aksi</th>
              <th scope="col">Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parsed_entries as $entry):
              $badge_class = $action_badges[$entry['action']] ?? 'badge-pending';
              $role_class = 'badge-role-' . strtolower($entry['role']);
            ?>
            <tr>
              <td><?= e($entry['timestamp']) ?></td>
              <td>
                <strong><?= e($entry['username']) ?></strong>
                <small>ID: <?= e($entry['user_id']) ?></small>
                <span class="badge <?= $role_class ?>"><?= e($entry['role']) ?></span>
              </td>
              <td><span class="badge <?= $badge_class ?>"><?= e($action_labels[$entry['action']] ?? $entry['action']) ?></span></td>
              <td><span class="text-muted"><?= e($entry['details']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
      <div class="card-body">
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

  <div class="card">
    <div class="card-header">
      <h3>Catatan</h3>
    </div>
    <div class="card-body text-muted">
      <ul>
        <li>Log disimpan di file <code>logs/activity.log</code> (di luar DocumentRoot).</li>
        <li>Hanya Admin yang dapat melihat halaman ini.</li>
        <li>Password, token, dan kredensial <strong>tidak</strong> dicatat dalam log.</li>
        <li>Ditampilkan maksimal <?= $max_lines ?> entri terbaru untuk performa.</li>
      </ul>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
