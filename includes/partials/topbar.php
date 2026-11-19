<?php
// ============================================================
// PINJAMIN — Topbar (wraps in .content)
// Requires: $pageTitle, current_user()
// ============================================================
$pageTitle = $pageTitle ?? 'Pinjamin';
$user = current_user();
?>
<div class="content">
<header class="topbar" role="banner">
  <button class="btn-icon drawer-toggle" aria-label="Buka menu" aria-expanded="false" aria-controls="sidebar" id="drawerToggle">
    <svg class="icon-menu" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <line x1="3" y1="6" x2="21" y2="6"></line>
      <line x1="3" y1="12" x2="21" y2="12"></line>
      <line x1="3" y1="18" x2="21" y2="18"></line>
    </svg>
  </button>
  <div class="topbar-title">
    <h1 class="page-title"><?= e($pageTitle) ?></h1>
  </div>
  <div class="topbar-actions">
    <?php include __DIR__ . '/theme_toggle.php'; ?>
    <div class="user-avatar" aria-hidden="true">
      <?= e(mb_substr($user['name'] ?? 'U', 0, 1)) ?>
    </div>
  </div>
</header>
<script>
  (function () {
    var toggle = document.getElementById('drawerToggle');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('drawerBackdrop');
    if (!toggle || !sidebar || !backdrop) return;
    function closeDrawer() {
      document.documentElement.removeAttribute('data-drawer-open');
      toggle.setAttribute('aria-expanded', 'false');
    }
    function openDrawer() {
      document.documentElement.setAttribute('data-drawer-open', '');
      toggle.setAttribute('aria-expanded', 'true');
      setTimeout(function () {
        var firstLink = sidebar.querySelector('.nav-link');
        if (firstLink) firstLink.focus();
      }, 0);
    }
    toggle.addEventListener('click', function () {
      var isOpen = document.documentElement.hasAttribute('data-drawer-open');
      isOpen ? closeDrawer() : openDrawer();
    });
    backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeDrawer();
    });
  })();
</script>