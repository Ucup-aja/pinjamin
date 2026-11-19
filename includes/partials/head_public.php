<?php
// ============================================================
// PINJAMIN — Shared HTML Head (Public / Non-Auth Pages)
// No layout wrapper, no sidebar. Includes theme init & CSS.
// ============================================================
$pageTitle = $pageTitle ?? 'Pinjamin';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light dark">
  <title><?= e($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
  <script>
    (function () {
      var KEY = 'pinjamin-theme', html = document.documentElement, theme = 'light';
      try {
        var saved = localStorage.getItem(KEY);
        if (saved === 'dark' || saved === 'light') theme = saved;
        else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) theme = 'dark';
      } catch (_) {
        try { if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) theme = 'dark'; } catch (e) {}
      }
      html.dataset.theme = theme;
    })();
  </script>
</head>
<body>