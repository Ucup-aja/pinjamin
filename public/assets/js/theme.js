/**
 * PINJAMIN — Theme Switcher
 * Minimal, no dependencies, inline-safe
 */
(function () {
  'use strict';

  var KEY = 'pinjamin-theme';
  var html = document.documentElement;
  var toggle = document.getElementById('themeToggle');

  function apply(theme) {
    html.dataset.theme = theme;
    try {
      localStorage.setItem(KEY, theme);
    } catch (_) {
      // localStorage unavailable (private browsing, etc.)
    }
    if (toggle) {
      toggle.setAttribute('aria-pressed', theme === 'dark');
      toggle.setAttribute('aria-label', theme === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
    }
  }

  // Determine initial theme: localStorage > prefers-color-scheme > light
  var theme = 'light';
  try {
    var saved = localStorage.getItem(KEY);
    if (saved === 'dark' || saved === 'light') {
      theme = saved;
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      theme = 'dark';
    }
  } catch (_) {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      theme = 'dark';
    }
  }
  apply(theme);

  // Listen for system preference changes (only if user hasn't set a preference)
  try {
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        try {
          if (!localStorage.getItem(KEY)) {
            apply(e.matches ? 'dark' : 'light');
          }
        } catch (_) {
          apply(e.matches ? 'dark' : 'light');
        }
      });
    }
  } catch (_) {}

  // Toggle handler
  if (toggle) {
    toggle.addEventListener('click', function () {
      var next = html.dataset.theme === 'dark' ? 'light' : 'dark';
      apply(next);
    });
  }
})();