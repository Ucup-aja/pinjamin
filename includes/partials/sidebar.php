<?php
// ============================================================
// PINJAMIN — Sidebar (Role-Aware)
// Requires: current_user(), base_url(), $currentPage
// ============================================================
$user = current_user();
$role = $user['role'] ?? '';
$isAdmin = $role === 'admin';
$isPetugas = $role === 'petugas';
$isPeminjam = $role === 'peminjam';

$navItems = [];
if ($isAdmin) {
    $navItems = [
        ['href' => 'dashboard_admin.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => 'users.php', 'label' => 'Pengguna', 'icon' => 'users'],
        ['href' => 'categories.php', 'label' => 'Kategori', 'icon' => 'folder'],
        ['href' => 'tools.php', 'label' => 'Alat', 'icon' => 'tool'],
        ['href' => 'admin_borrowings.php', 'label' => 'Kelola Peminjaman', 'icon' => 'list'],
        ['href' => 'admin_returns.php', 'label' => 'Kelola Pengembalian', 'icon' => 'return'],
        ['href' => 'activity_log.php', 'label' => 'Log Aktivitas', 'icon' => 'log'],
    ];
} elseif ($isPetugas) {
    $navItems = [
        ['href' => 'dashboard_petugas.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => 'approvals.php', 'label' => 'Persetujuan Pinjam', 'icon' => 'approve'],
        ['href' => 'returns.php', 'label' => 'Konfirmasi Kembali', 'icon' => 'return'],
        ['href' => 'reports.php', 'label' => 'Laporan', 'icon' => 'report'],
    ];
} elseif ($isPeminjam) {
    $navItems = [
        ['href' => 'dashboard_peminjam.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => 'borrow.php', 'label' => 'Ajukan Pinjam', 'icon' => 'borrow'],
        ['href' => 'my_borrowings.php', 'label' => 'Riwayat Pinjam', 'icon' => 'history'],
        ['href' => 'return_request.php', 'label' => 'Ajukan Kembali', 'icon' => 'return'],
        ['href' => 'my_returns.php', 'label' => 'Riwayat Kembali', 'icon' => 'history'],
    ];
}

function sidebar_icon($name) {
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"></path>',
        'tool' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>',
        'list' => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'return' => '<polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>',
        'log' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'approve' => '<polyline points="20 6 9 17 4 12"></polyline>',
        'borrow' => '<path d="M21 15V5a2 2 0 0 0-2-2H9a2 2 0 0 0 0 4h5.34"></path><path d="M3 3v18"></path><path d="M3 7h18"></path><path d="M3 11h18"></path>',
        'history' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        'report' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
    ];
    $path = $icons[$name] ?? $icons['dashboard'];
    return '<svg class="nav-icon" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $path . '</svg>';
}

function sidebar_role_badge_class($role) {
    return match ($role) {
        'admin' => 'badge-role-admin',
        'petugas' => 'badge-role-petugas',
        'peminjam' => 'badge-role-peminjam',
        default => '',
    };
}

function sidebar_role_label($role) {
    return match ($role) {
        'admin' => 'Admin',
        'petugas' => 'Petugas',
        'peminjam' => 'Peminjam',
        default => ucfirst($role),
    };
}
?>
<aside class="sidebar" id="sidebar" role="navigation" aria-label="Navigasi utama">
  <div class="sidebar-brand">
    <h2>Pinjamin</h2>
    <small><?= $isAdmin ? 'Admin Panel' : ($isPetugas ? 'Petugas Panel' : 'Peminjam Panel') ?></small>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($navItems as $item): ?>
      <?php $active = ($currentPage === $item['href']); ?>
      <a href="<?= base_url($item['href']) ?>" class="nav-link" <?= $active ? 'aria-current="page"' : '' ?>>
        <?= sidebar_icon($item['icon']) ?>
        <?= e($item['label']) ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-user">
    <strong><?= e($user['name']) ?></strong>
    <span class="badge <?= sidebar_role_badge_class($role) ?>"><?= sidebar_role_label($role) ?></span>
    <a href="<?= base_url('logout.php') ?>" class="nav-link logout-link">
      <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
      Logout
    </a>
  </div>
</aside>
<div class="drawer-backdrop" id="drawerBackdrop" hidden></div>