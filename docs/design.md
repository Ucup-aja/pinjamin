# Pinjamin — Design System & UI Redesign Specification

**Project:** Pinjamin (Aplikasi Peminjaman Alat Sekolah)
**Concept:** Institutional Minimalism
**Target Branch:** `feat/ui-day2`
**Baseline Tag:** `ukk-day1-stable`
**Date:** 2026-11-19
**Language:** English (UI copy in Indonesian)

---

## 1. Design Goals

| Goal | Description |
|------|-------------|
| **Professional Authority** | Visual language signals institutional trust: calm, structured, purposeful. Not a consumer SaaS dashboard. |
| **Functional Clarity** | Every pixel serves a task: scanning tables, completing forms, confirming actions. Decoration is eliminated. |
| **Cross-role Consistency** | Admin, Petugas, Peminjam share a single component system, color logic, layout grid. Only navigation and priority actions differ. |
| **Accessibility by Default** | WCAG AA contrast, keyboard focus visible, semantic HTML, reduced motion respected, print-safe dark mode. |
| **Maintainable Implementation** | No build step, no new dependencies. Single CSS file, single JS module, PHP partials for shared chrome. Migration is incremental per page. |
| **Zero Regression Risk** | All POST endpoints, CSRF, redirects, stored procedures, auth guards, database schema, and business rules remain untouched. |

---

## 2. Design Principles

| Principle | Application |
|-----------|-------------|
| **Clarity over decoration** | No gradients, glassmorphism, glows, hero illustrations, or decorative shadows. Color = meaning, not mood. |
| **Consistency over novelty** | One button system, one form layout, one table style, one sidebar pattern. Variations only where role workflow demands it. |
| **Function over visual effects** | Transitions only on interactive elements (hover, focus, drawer slide). No page-load animations, no parallax, no stagger. |
| **Accessible by default** | Focus rings use `--color-focus` (3:1 minimum). Status never color-only — always paired with text label. Touch targets ≥ 44px. |
| **Responsive without compromise** | Mobile drawer replaces sidebar. Tables gain controlled horizontal scroll or card layout. No feature hidden on small screens. |
| **Institutional color restraint** | Primary blue is an accent (buttons, active states, focus). Backgrounds stay neutral. Role colors only on sidebar header + role badge. |

---

## 3. Visual Identity

| Attribute | Value |
|-----------|-------|
| **Name** | Institutional Minimalism |
| **Tone** | Professional, calm, structured, trustworthy, efficient |
| **Metaphor** | Government/corporate admin panel — not a startup dashboard |
| **Iconography** | Inline SVG (Feather/Lucide style) from the beginning. No emoji in new navigation or action components. Existing emoji may be migrated gradually. |
| **Border radius** | 6–8px (cards, inputs, buttons). Not pill (999px) except badges. |
| **Shadow** | Single elevation: `0 1px 3px rgba(0,0,0,0.08)` for cards; `0 4px 12px rgba(0,0,0,0.12)` for modals/drawer. No layered shadows. |
| **Transition** | 120–180ms `ease-out` on: hover, focus, drawer, modal, tooltip. Never on layout shifts. |

---

## 4. Color Tokens (CSS Custom Properties)

### Light Theme (Default)

```css
:root {
  /* Base */
  --color-bg: #F8FAFC;           /* Page background */
  --color-surface: #FFFFFF;      /* Card, modal, table row */
  --color-surface-hover: #F1F5F9;
  --color-border: #CBD5E1;       /* Subtle divider (non-interactive) */
  --color-border-strong: #64748B;/* Input border, table header divider — meets 3:1 */

  /* Text */
  --color-text: #0F172A;         /* Primary text (16:1 on bg) */
  --color-text-muted: #475569;   /* Secondary text (7.5:1 on bg) */
  --color-text-on-primary: #FFFFFF;

  /* Primary (accent blue) */
  --color-primary: #1D4ED8;
  --color-primary-hover: #1E40AF;
  --color-primary-active: #1E3A8A;
  --color-primary-focus: #93C5FD; /* Focus ring — 3:1 on white, 4.5:1 on primary */
  --color-primary-subtle: #DBEAFE; /* Badge bg, selected row */

  /* Semantic */
  --color-success: #16A34A;
  --color-success-bg: #DCFCE7;
  --color-success-text: #166534;
  --color-warning: #F59E0B;
  --color-warning-bg: #FEF3C7;
  --color-warning-text: #92400E;
  --color-danger: #DC2626;
  --color-danger-bg: #FEE2E2;
  --color-danger-text: #991B1B;

  /* Role accents (sidebar brand + role badge only) */
  --color-role-admin: #E74C3C;
  --color-role-petugas: #27AE60;
  --color-role-peminjam: #3498DB;

  /* Focus */
  --color-focus: #1D4ED8;        /* Visible focus ring color */
  --color-focus-ring: 0 0 0 3px rgba(29, 78, 216, 0.25);

  /* Overlay */
  --color-overlay: rgba(15, 23, 42, 0.45); /* Modal/drawer backdrop */
}
```

### Dark Theme (`[data-theme="dark"]`)

```css
[data-theme="dark"] {
  --color-bg: #0B1220;
  --color-surface: #111B2E;
  --color-surface-hover: #1E2D45;
  --color-border: #334155;
  --color-border-strong: #64748B; /* Same hex — 3.6:1 on surface */

  --color-text: #F1F5F9;
  --color-text-muted: #CBD5E1;
  --color-text-on-primary: #0B1220; /* Dark text on light primary */

  --color-primary: #60A5FA;
  --color-primary-hover: #93C5FD;
  --color-primary-active: #3B82F6;
  --color-primary-focus: #1D4ED8;  /* Focus ring — dark blue on dark */
  --color-primary-subtle: #1E3A5F;

  --color-success: #22C55E;
  --color-success-bg: #14532D;
  --color-success-text: #BBF7D0;
  --color-warning: #FBBF24;
  --color-warning-bg: #78350F;
  --color-warning-text: #FDE68A;
  --color-danger: #EF4444;
  --color-danger-bg: #7F1D1D;
  --color-danger-text: #FECACA;

  --color-role-admin: #EF4444;
  --color-role-petugas: #22C55E;
  --color-role-peminjam: #60A5FA;

  --color-focus: #60A5FA;
  --color-focus-ring: 0 0 0 3px rgba(96, 165, 250, 0.35);

  --color-overlay: rgba(0, 0, 0, 0.6);
}
```

### Print Override (Forces Light)

```css
@media print {
  :root, [data-theme="dark"] {
    --color-bg: #FFFFFF;
    --color-surface: #FFFFFF;
    --color-text: #0F172A;
    --color-text-muted: #475569;
    --color-border: #CBD5E1;
    --color-border-strong: #475569;
    --color-primary: #1D4ED8;
    --color-primary-subtle: #DBEAFE;
    --color-overlay: transparent;
  }
  .sidebar, .topbar, .theme-switcher, .btn-print, .action-btns,
  .pagination, .filter-form, .modal, .drawer-backdrop { display: none !important; }
  .main { margin-left: 0 !important; padding: 0 !important; max-width: none !important; }
  .card { box-shadow: none !important; border: 1px solid var(--color-border) !important; }
  .table-responsive { overflow: visible !important; }
  body { min-height: auto !important; }
}
```

**reports.php print view** — the existing implementation in `public/reports.php` (lines 200–399) is preserved. It uses its own embedded print stylesheet optimized for A4. The shared `app.css` print overrides only hide global chrome (sidebar, topbar, theme switcher). Do not redesign `reports.php` print during UI-2.

### Contrast Verification (Approximate WCAG AA)

| Pair | Light Ratio | Dark Ratio | Status |
|------|-------------|------------|--------|
| Text on BG | 16.1:1 | 14.2:1 | ✅ AAA |
| Muted on BG | 7.5:1 | 11.5:1 | ✅ AA |
| Primary on White | 6.7:1 | — | ✅ AA |
| White on Primary (Dark) | — | 7.4:1 | ✅ AA |
| Border-strong on Surface | 4.8:1 | 3.6:1 | ✅ AA (UI) |
| Success text on BG | 6.2:1 | 7.1:1 | ✅ AA |
| Danger text on BG | 5.9:1 | 6.8:1 | ✅ AA |
| Focus ring on Primary | 3.1:1 | 3.5:1 | ✅ AA (non-text) |

> Final verification via [axe-core](https://github.com/dequelabs/axe-core) or [color-contrast-checker](https://webaim.org/resources/contrastchecker/) in UI-2.

---

## 5. Typography

| Role | Size | Weight | Line Height | Token |
|------|------|--------|-------------|-------|
| Body / Base | 14px | 400 | 1.5 | `--fs-body: 0.875rem` |
| Small / Caption | 12px | 400 | 1.4 | `--fs-sm: 0.75rem` |
| Form Label | 13px | 500 | 1.4 | `--fs-label: 0.8125rem` |
| Input / Select / Button | 14px | 400/500 | 1.5 | `--fs-input: 0.875rem` |
| Page Title (h1) | 24px | 600 | 1.3 | `--fs-h1: 1.5rem` |
| Section Title (h2) | 18px | 600 | 1.3 | `--fs-h2: 1.125rem` |
| Card Title (h3) | 16px | 600 | 1.4 | `--fs-h3: 1rem` |
| Table Header | 12px | 600 | 1.4 | `--fs-th: 0.75rem` |
| Badge | 11px | 600 | 1 | `--fs-badge: 0.6875rem` |

**Font Stack:** `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji"`

**No Google Fonts. No `@font-face`.** CSS variable mapping:

```css
:root {
  --font-sans: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;

  --fs-xs: 0.6875rem;   /* 11px - badge */
  --fs-sm: 0.75rem;     /* 12px - caption, th */
  --fs-label: 0.8125rem;/* 13px - form label */
  --fs-body: 0.875rem;  /* 14px - base */
  --fs-input: 0.875rem; /* 14px - inputs, buttons */
  --fs-h3: 1rem;        /* 16px - card title */
  --fs-h2: 1.125rem;    /* 18px - section */
  --fs-h1: 1.5rem;      /* 24px - page title */

  --lh-tight: 1.3;
  --lh-normal: 1.5;
  --lh-relaxed: 1.6;
}
```

---

## 6. Spacing & Sizing Scale

| Token | Value | Use |
|-------|-------|-----|
| `--space-1` | 4px | Icon gaps, badge padding |
| `--space-2` | 8px | Input padding Y, button gap, card inner gap |
| `--space-3` | 12px | Form group gap, table cell padding X |
| `--space-4` | 16px | Card padding, section gap, modal padding |
| `--space-5` | 20px | Header height component |
| `--space-6` | 24px | Page section gap, card header |
| `--space-8` | 32px | Major layout gap, page header bottom |

**Border Radius:**
```css
--radius-sm: 4px;   /* Badge, icon button */
--radius-md: 6px;   /* Input, button, card, table cell */
--radius-lg: 8px;   /* Modal, drawer, card (optional) */
--radius-full: 999px; /* Pill badge only */
```

**Content Width:** `--max-content: 1280px` (centered within `.main`)

**Sidebar Width:** `--sidebar-w: 248px` (desktop)

**Header Height:** `--header-h: 56px`

**Touch Target:** `--touch-target: 44px` (min-height for buttons, inputs, clickable rows)

**Z-Index Scale:**
```css
--z-dropdown: 100;
--z-drawer-backdrop: 200;
--z-drawer: 210;
--z-modal-backdrop: 300;
--z-modal: 310;
--z-toast: 400;
--z-tooltip: 500;
```

---

## 7. Layout & Navigation

### 7.1 Global Layout (Authenticated Pages)

```
<html>
  <body>
    <aside class="sidebar" role="navigation" aria-label="Main navigation">
      <div class="sidebar-brand">...</div>
      <nav class="sidebar-nav">...</nav>
      <div class="sidebar-user">...</div>
    </aside>

    <div class="drawer-backdrop" hidden></div>

    <header class="topbar" role="banner">
      <button class="drawer-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">☰</button>
      <div class="topbar-title"><h1>Page Title</h1></div>
      <div class="topbar-actions">
        <button class="theme-switcher" aria-label="Toggle theme">🌙</button>
        <span class="user-avatar" aria-hidden="true">U</span>
      </div>
    </header>

    <main class="main" role="main">
      <div class="page-header">
        <h1>Page Title</h1>
        <div class="page-actions">...</div>
      </div>
      <div class="page-content">...</div>
    </main>

    <footer class="page-footer" role="contentinfo">...</footer>
  </body>
</html>
```

### 7.2 Responsive Behavior

| Breakpoint | Sidebar | Topbar | Main | Table |
|------------|---------|--------|------|-------|
| ≥ 1024px (Desktop) | Fixed left, 248px | Full width, page title + actions | Margin-left: 248px, max-width 1280px | Full table, sticky header optional |
| 768–1023px (Tablet) | Drawer (overlay) | Full width, hamburger + title | Full width, padding 16px | Horizontal scroll in `.table-responsive` |
| < 768px (Mobile) | Drawer (overlay) | Full width, hamburger + title | Full width, padding 12px | Card layout alternative OR controlled scroll |

**Drawer Implementation:**
- CSS-only slide-in via `transform: translateX(-100%)` → `translateX(0)` on `[data-drawer-open]`
- Backdrop click / Escape key closes
- Focus trap inside drawer when open (JS)
- `aria-expanded` on toggle button synced

### 7.3 Navigation Per Role

| Role | Sidebar Items (Active Highlight) |
|------|----------------------------------|
| **Admin** | Dashboard, Pengguna, Kategori, Alat, Kelola Peminjaman, Kelola Pengembalian, Log Aktivitas |
| **Petugas** | Dashboard, Persetujuan Pinjam, Konfirmasi Kembali, Laporan |
| **Peminjam** | Dashboard, Ajukan Pinjam, Riwayat Pinjam, Ajukan Kembali, Riwayat Kembali |

**Active State:** `aria-current="page"` on current nav link. Match via `basename($_SERVER['SCRIPT_NAME'])`.

### 7.4 Breadcrumb

Only on pages ≥ 3 levels deep (e.g., Admin → Kelola Peminjaman → Edit Modal). Optional — omit if redundant with sidebar + page title.

---

## 8. Component Specifications

All components defined as CSS utility classes + minimal HTML patterns. No JS framework.

### 8.1 Sidebar & Mobile Drawer

```html
<aside class="sidebar" id="sidebar" role="navigation" aria-label="Navigasi utama">
  <div class="sidebar-brand">
    <h2>Pinjamin</h2>
    <small>Admin Panel</small>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard_admin.php" class="nav-link" aria-current="page">
      <svg class="nav-icon" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg> Dashboard
    </a>
    <!-- ... -->
  </nav>
  <div class="sidebar-user">
    <strong>Nama User</strong>
    <span class="badge badge-role-admin">Admin</span>
    <a href="logout.php" class="nav-link logout-link">Logout</a>
  </div>
</aside>
<div class="drawer-backdrop" id="drawerBackdrop" hidden></div>
```

**CSS Hooks:**
- `.sidebar` — fixed, full height, `--sidebar-w`, bg `var(--color-surface)`, border-right `var(--color-border)`
- `.nav-link` — block, padding `var(--space-3) var(--space-4)`, color `var(--color-text-muted)`, hover `var(--color-surface-hover)`, active: bg `var(--color-primary-subtle)`, color `var(--color-primary)`, left border `3px solid var(--color-primary)`
- `.sidebar-brand` — padding `var(--space-4)`, border-bottom
- `.sidebar-user` — padding `var(--space-4)`, border-top, small text
- `.drawer-backdrop` — fixed inset-0, bg `var(--color-overlay)`, `opacity: 0` → `1`, pointer-events none/auto
- `[data-drawer-open] .sidebar` — `transform: translateX(0)`
- `[data-drawer-open] .drawer-backdrop` — visible

### 8.2 Topbar

```html
<header class="topbar" role="banner">
  <button class="btn-icon drawer-toggle" aria-label="Buka menu" aria-expanded="false" aria-controls="sidebar">
    <svg class="icon-menu" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
  </button>
  <div class="topbar-title"><h1 class="page-title">Kelola Pengguna</h1></div>
  <div class="topbar-actions">
    <button class="btn-icon theme-switcher" id="themeToggle" aria-label="Ganti ke mode gelap" aria-pressed="false">
      <svg class="icon-sun" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
      <svg class="icon-moon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
    </button>
    <div class="user-menu">…</div>
  </div>
</header>
```

- Height: `--header-h` (56px)
- Background: `var(--color-surface)`, border-bottom `var(--color-border)`
- Page title: `--fs-h2`, `var(--color-text)`
- Theme switcher: icon-only button, toggles `[data-theme]` on `<html>`, saves to localStorage, `aria-pressed` sync

### 8.3 Page Header

```html
<div class="page-header">
  <h1 class="page-title">Kelola Pengguna</h1>
  <div class="page-actions">
    <a href="users.php?export=1" class="btn btn-secondary">Ekspor</a>
    <button class="btn btn-primary" onclick="openAddModal()">+ Tambah Pengguna</button>
  </div>
</div>
```

- Margin-bottom: `var(--space-6)`
- Flex wrap on small screens
- Page title: `--fs-h1`

### 8.4 Buttons

| Variant | Class | Background | Text | Border | Use |
|---------|-------|------------|------|--------|-----|
| Primary | `.btn-primary` | `var(--color-primary)` | `var(--color-text-on-primary)` | none | Main CTA (Save, Submit, Approve) |
| Secondary | `.btn-secondary` | `var(--color-surface)` | `var(--color-text)` | `1px solid var(--color-border-strong)` | Alternative (Cancel, Back, Export) |
| Destructive | `.btn-danger` | `var(--color-danger)` | white | none | Delete, Reject, Remove |
| Ghost | `.btn-ghost` | transparent | `var(--color-text-muted)` | none | Low emphasis (Reset filter) |
| Outline | `.btn-outline` | transparent | `var(--color-primary)` | `1px solid var(--color-primary)` | Secondary CTA (View details) |

**States:**
- Hover: darken bg 8–10% (primary/danger) / `var(--color-surface-hover)` (secondary)
- Active: darken further 5%
- Focus: `outline: none; box-shadow: var(--color-focus-ring);`
- Disabled: `opacity: 0.5; cursor: not-allowed;`

**Sizes:**
- Default: `padding: 10px 18px; min-height: 44px;`
- Small (`.btn-sm`): `padding: 6px 12px; min-height: 36px;`
- Icon-only (`.btn-icon`): `padding: 10px; width: 44px; height: 44px;`

### 8.5 Form Controls

```html
<div class="form-group">
  <label for="username" class="form-label">Username <span class="required" aria-hidden="true">*</span></label>
  <input type="text" id="username" name="username" class="form-input" required autocomplete="username" aria-describedby="username-hint">
  <span id="username-hint" class="form-hint">Minimal 3 karakter</span>
</div>
```

- `.form-label` — `--fs-label`, `var(--color-text)`, `margin-bottom: var(--space-1)`, `display: block`
- `.form-input`, `.form-select`, `.form-textarea` — `--fs-input`, `padding: var(--space-2) var(--space-3)`, border `1px solid var(--color-border-strong)`, radius `var(--radius-md)`, bg `var(--color-surface)`, color `var(--color-text)`
- Focus: `border-color: var(--color-primary); box-shadow: var(--color-focus-ring);`
- Error: `border-color: var(--color-danger);` + `.form-error` message below
- Disabled: `bg: var(--color-surface-hover); cursor: not-allowed;`
- `.form-row` — grid `1fr 1fr`, gap `var(--space-4)`, stacks on `< 600px`
- `.form-hint` — `--fs-sm`, `var(--color-text-muted)`
- `.required` — `var(--color-danger)`

### 8.6 Search & Filter Bar

```html
<form class="filter-form" method="GET">
  <input type="search" name="search" placeholder="Cari..." class="form-input" style="min-width: 240px;" value="<?= e($_GET['search'] ?? '') ?>">
  <select name="status" class="form-select" style="width: auto;">
    <option value="">Semua Status</option>
    <option value="pending" <?= $sf==='pending'?'selected':'' ?>>Pending</option>
    …
  </select>
  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  <a href="?" class="btn btn-ghost btn-sm" hidden>Reset</a>
</form>
```

- Flex wrap, gap `var(--space-2)`
- Reset button visible only when any filter active

### 8.7 Data Table

```html
<div class="table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th scope="col" style="width: 60px;">ID</th>
        <th scope="col">Peminjam</th>
        <th scope="col">Alat</th>
        <th scope="col" style="width: 100px;">Status</th>
        <th scope="col" style="width: 140px;">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>123</td>
        <td><strong>Budi Santoso</strong><br><small>@budi</small></td>
        <td><strong>PRJ-001 - Proyektor</strong><br><small>Elektronik</small></td>
        <td><span class="badge badge-pending">Pending</span></td>
        <td>
          <div class="action-btns">
            <button class="btn-icon icon-btn-edit" aria-label="Edit peminjaman 123">✎</button>
            <button class="btn-icon icon-btn-delete" aria-label="Hapus peminjaman 123">🗑</button>
          </div>
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

**Styling:**
- `.table-responsive` — `overflow-x: auto; -webkit-overflow-scrolling: touch;`
- `.data-table` — `width: 100%; border-collapse: collapse; font-size: var(--fs-body);`
- `th` — `padding: var(--space-3) var(--space-4); text-align: left; bg: var(--color-surface-hover); color: var(--color-text-muted); font-size: var(--fs-th); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border-strong);`
- `td` — `padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); vertical-align: middle;`
- `tr:hover td` — `background: var(--color-surface-hover);`
- `.action-btns` — `display: flex; gap: var(--space-1);`
- `.icon-btn` — `.btn-icon` with semantic icon, `aria-label` required

**Responsive Table Strategies (two patterns, chosen per page):**

1. **Dense admin/petugas tables** — controlled horizontal scroll:
   - Wrap in `.table-responsive` (`overflow-x: auto`).
   - Keep all columns and headers. Action buttons and pagination stay visible.
   - Min-width on table to preserve column legibility; page layout never overflows.
   - Use this for `users.php`, `categories.php`, `tools.php`, `approvals.php`, `returns.php`, `admin_borrowings.php`, `admin_returns.php`, `reports.php`, `activity_log.php`.

2. **Simple mobile-oriented records** — optional card/list via `data-label`:
   - Only where a card truly improves scanning (e.g., peminjam history, return request cards).
   - CSS-only conversion using `data-label`, no JS.
   ```css
   @media (max-width: 640px) {
     .table-cards thead { display: none; }
     .table-cards tbody, .table-cards tr, .table-cards td { display: block; width: 100%; }
     .table-cards tr { border: 1px solid var(--color-border); border-radius: var(--radius-md); margin-bottom: var(--space-3); background: var(--color-surface); }
     .table-cards td { padding: var(--space-3); border: none; border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between; gap: var(--space-3); }
     .table-cards td:last-child { border-bottom: none; }
     .table-cards td::before { content: attr(data-label); font-weight: 600; color: var(--color-text-muted); font-size: var(--fs-sm); }
   }
   ```

Do not force every table into card layout.

### 8.8 Pagination

```html
<nav class="pagination" aria-label="Navigasi halaman">
  <a href="?page=1" class="btn btn-secondary btn-sm" aria-label="Halaman pertama">«</a>
  <a href="?page=4" class="btn btn-secondary btn-sm">4</a>
  <span class="btn btn-primary btn-sm" aria-current="page">5</span>
  <a href="?page=6" class="btn btn-secondary btn-sm">6</a>
  <a href="?page=10" class="btn btn-secondary btn-sm" aria-label="Halaman terakhir">»</a>
</nav>
```

- Centered, gap `var(--space-1)`
- Current page: `.btn-primary` with `aria-current="page"`
- First/prev/next/last with `aria-label`

### 8.9 Status Badge

```html
<span class="badge badge-pending">Pending</span>
<span class="badge badge-success">Disetujui</span>
<span class="badge badge-danger">Ditolak</span>
<span class="badge badge-warning">Menunggu</span>
```

**Variants:**
| Class | Background | Text | Use |
|-------|------------|------|-----|
| `.badge-pending` | `var(--color-warning-bg)` | `var(--color-warning-text)` | Pending, Menunggu |
| `.badge-success` / `.badge-approved` / `.badge-confirmed` / `.badge-returned` | `var(--color-success-bg)` | `var(--color-success-text)` | Approved, Confirmed, Returned |
| `.badge-danger` / `.badge-rejected` | `var(--color-danger-bg)` | `var(--color-danger-text)` | Rejected, Ditolak |
| `.badge-info` / `.badge-partial` | `var(--color-primary-subtle)` | `var(--color-primary)` | Sebagian Kembali, Info |
| `.badge-role-admin` | `var(--color-role-admin)` | white | Role badge only |
| `.badge-role-petugas` | `var(--color-role-petugas)` | white | Role badge only |
| `.badge-role-peminjam` | `var(--color-role-peminjam)` | white | Role badge only |

- Padding: `var(--space-1) var(--space-2)`
- Radius: `var(--radius-full)`
- Font: `--fs-badge`, weight 600

### 8.10 Flash Message & Validation Error

```html
<!-- Flash (session) -->
<div class="alert alert-success" role="alert">Operasi berhasil</div>
<div class="alert alert-error" role="alert">
  <ul><li>Username sudah digunakan</li></ul>
</div>
<div class="alert alert-warning" role="alert">Peringatan: stok rendah</div>
```

**Variants:**
| Class | Border-left | Background | Text | Icon |
|-------|-------------|------------|------|------|
| `.alert-success` | `4px solid var(--color-success)` | `var(--color-success-bg)` | `var(--color-success-text)` | ✓ |
| `.alert-error` | `4px solid var(--color-danger)` | `var(--color-danger-bg)` | `var(--color-danger-text)` | ✕ |
| `.alert-warning` | `4px solid var(--color-warning)` | `var(--color-warning-bg)` | `var(--color-warning-text)` | ⚠ |

- Padding: `var(--space-3) var(--space-4)`
- Radius: `var(--radius-md)`
- Margin-bottom: `var(--space-4)`
- `role="alert"` for screen readers

**Inline Field Error:**
```html
<input class="form-input" aria-invalid="true" aria-describedby="username-error">
<span id="username-error" class="form-error" style="color: var(--color-danger); font-size: var(--fs-sm); margin-top: var(--space-1);">Username sudah digunakan</span>
```

### 8.11 Empty State

```html
<div class="empty-state">
  <svg class="empty-icon" aria-hidden="true">…</svg>
  <h3>Belum ada data</h3>
  <p>Klik "Tambah" untuk memulai</p>
  <a href="users.php?add=1" class="btn btn-primary" style="margin-top: var(--space-4);">Tambah Pengguna</a>
</div>
```

- Centered, padding `var(--space-8)`
- Icon: 64px, `var(--color-text-muted)`
- Title: `--fs-h3`, `var(--color-text)`
- Description: `--fs-body`, `var(--color-text-muted)`

### 8.12 Confirmation Dialog (Modal)

```html
<div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" hidden>
  <div class="modal-backdrop"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="confirmTitle" class="modal-title">Konfirmasi Hapus</h2>
      <button class="btn-icon modal-close" aria-label="Tutup">
        <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </button>
    </div>
    <div class="modal-body">
      <p>Hapus pengguna "Budi" (ID: 12)?</p>
      <p class="modal-warning">Pengguna dengan riwayat transaksi tidak dapat dihapus.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-modal-dismiss>Batal</button>
      <button class="btn btn-danger" data-modal-confirm>Hapus</button>
    </div>
  </div>
</div>
```

**Behavior:**
- Open: remove `hidden`, add `.modal-open` on body (prevent scroll), focus first focusable
- Close: on backdrop click, Escape, close button, dismiss button
- Focus trap inside modal
- `data-modal-confirm` button submits hidden form or triggers callback

**Styling:**
- `.modal` — fixed inset-0, z-index `--z-modal`
- `.modal-backdrop` — absolute inset-0, bg `var(--color-overlay)`
- `.modal-content` — max-width 480px (form), 600px (complex), centered, bg `var(--color-surface)`, radius `--radius-lg`, shadow `0 12px 32px var(--color-overlay)`
- `.modal-header` — flex between, padding `var(--space-4)`, border-bottom
- `.modal-body` — padding `var(--space-4)`
- `.modal-footer` — flex end, gap `var(--space-2)`, padding `var(--space-4)`, border-top

### 8.13 Summary Card (Stat Card)

```html
<div class="stat-card">
  <div class="stat-label">Total Pengguna</div>
  <div class="stat-value">142</div>
  <div class="stat-meta">+12 dari bulan lalu</div>
</div>
```

- Grid: `repeat(auto-fit, minmax(180px, 1fr))`, gap `var(--space-4)`
- Card: padding `var(--space-5)`, bg `var(--color-surface)`, radius `--radius-md`, border `1px solid var(--color-border)`
- `.stat-label` — `--fs-sm`, uppercase, `var(--color-text-muted)`
- `.stat-value` — `--fs-h1` (24px), weight 700, `var(--color-text)`
- `.stat-meta` — `--fs-xs`, `var(--color-text-muted)`

### 8.14 Theme Switcher

```html
<button class="btn-icon theme-switcher" id="themeToggle" aria-label="Ganti ke mode gelap" aria-pressed="false">
  <svg class="icon-sun" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
  <svg class="icon-moon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
</button>
```

**CSS:**
```css
.theme-switcher .icon-sun { display: none; }
.theme-switcher .icon-moon { display: block; }
[data-theme="dark"] .theme-switcher .icon-sun { display: block; }
[data-theme="dark"] .theme-switcher .icon-moon { display: none; }
[data-theme="dark"] .theme-switcher { aria-label: "Ganti ke mode terang"; }
```

**JS (theme.js):**
```js
(function() {
  const KEY = 'pinjamin-theme';
  const html = document.documentElement;
  const toggle = document.getElementById('themeToggle');
  function apply(theme) {
    html.dataset.theme = theme;
    try { localStorage.setItem(KEY, theme); } catch (_) {}
    if (toggle) {
      toggle.setAttribute('aria-pressed', theme === 'dark');
      toggle.setAttribute('aria-label', theme === 'dark' ? 'Ganti ke mode terang' : 'Ganti ke mode gelap');
    }
  }
  // Init: localStorage > prefers-color-scheme > light
  let theme = 'light';
  try {
    const saved = localStorage.getItem(KEY);
    if (saved) theme = saved;
    else if (window.matchMedia('(prefers-color-scheme: dark)').matches) theme = 'dark';
  } catch (_) {
    if (window.matchMedia('(prefers-color-scheme: dark)').matches) theme = 'dark';
  }
  apply(theme);

  // Listen for system change (only if no user preference)
  try {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
      if (!localStorage.getItem(KEY)) apply(e.matches ? 'dark' : 'light');
    });
  } catch (_) {}

  toggle?.addEventListener('click', () => {
    const next = html.dataset.theme === 'dark' ? 'light' : 'dark';
    apply(next);
  });
})();
```
- Inline in `<head>` before stylesheet (small synchronous script)
- No `visibility: hidden` — JavaScript failure must not make app invisible
- Safe localStorage access with try/catch
- `color-scheme` meta tag in HTML for native form control styling

---

## 9. Responsive Behavior Details

| Component | Desktop (≥1024) | Tablet (768–1023) | Mobile (<768) |
|-----------|-----------------|-------------------|---------------|
| Sidebar | Fixed, 248px | Drawer overlay | Drawer overlay |
| Topbar | Full, page title + actions | Full, hamburger + title | Full, hamburger + title |
| Page Padding | 32px | 24px | 16px |
| Table | Full, sticky header optional | `.table-responsive` scroll | Card layout via CSS (data-label) |
| Form Row | 2-col grid | 2-col grid | 1-col stack |
| Modal | Max 480–600px, centered | Max 90vw, centered | Full width, bottom sheet style optional |
| Pagination | Full numbers | Compact (prev/next + current) | Prev/Next only |
| Touch Target | 44px (hover states) | 44px | 48px minimum |

**Breakpoint Tokens:**
```css
--bp-sm: 640px;
--bp-md: 768px;
--bp-lg: 1024px;
--bp-xl: 1280px;
```

---

## 10. Accessibility Requirements

| Requirement | Implementation |
|-------------|----------------|
| **Contrast** | All text ≥ 4.5:1 (AA), UI borders ≥ 3:1. Verified in UI-2. |
| **Focus Visible** | `:focus-visible { outline: none; box-shadow: var(--color-focus-ring); }` on all interactive elements. |
| **Keyboard Navigation** | Tab order logical. Drawer: focus trap, Escape closes. Modal: focus trap, Escape closes. |
| **ARIA** | `role="alert"` on flash. `aria-current="page"` on active nav. `aria-label` on icon buttons. `aria-expanded` on drawer toggle. `aria-modal="true"` on modal. |
| **Color Not Sole Indicator** | Every badge has text. Status columns show text + badge. Error fields have text message + border. |
| **Reduced Motion** | `@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }` |
| **Print** | Dark mode forced light. Chrome/UI hidden. Tables not paginated. |
| **Language** | `<html lang="id">`. All UI copy Indonesian. |
| **Touch Target** | Min 44×44px (48×48 mobile). |
| **Form Labels** | Every input has `<label for="id">` or `aria-label`. Required marked visually + `aria-required`. |

---

## 11. Role-Specific UX Patterns

### 11.1 Admin
**Priorities:** CRUD (Users, Categories, Tools), Transaction Oversight (All Borrowings, All Returns), Audit Log.
- Tables: Search + Status filter + Pagination (15/page). Dense but scannable.
- Actions: Add/Edit/Delete modals. Delete guarded by referential integrity checks.
- Dashboard: 6 stat cards (Users, Categories, Tools, Pending Borrow, Pending Return, Active Borrow).
- Navigation: Full admin sidebar (7 items).

### 11.2 Petugas
**Priorities:** Approval Queue (Borrowings), Return Confirmation Queue, Reports (Print).
- Approvals: Default filter `status=pending`. Primary actions: **Setujui** (green) / **Tolak** (red) per row. Confirmation dialog via inline form `onsubmit="return confirm(...)"`.
- Returns: Default filter `status=pending`. Actions: **Konfirmasi** / **Tolak**. Denda shown on confirmed.
- Reports: Tabbed (Peminjaman / Pengembalian / Denda). Date range + Status filter. **Cetak Laporan Lengkap** button opens print view (no pagination, all rows).
- Dashboard: 5 stat cards focused on today's workload.

### 11.3 Peminjam
**Priorities:** Tool Catalog (Availability), Submit Borrow Request, Borrowing History, Submit Return Request, Return History.
- Borrow Form: Tool picker as radio cards showing code, name, category, stock. Quantity + date pickers. Validation: due ≥ borrow + 1 day, qty ≤ available.
- Return Request: List active borrowings (approved/partial) as cards with quantity breakdown. Per-card inline form for partial/full return.
- History Tables: Read-only, status badges, pagination (10/page).
- Dashboard: 4 stat cards (Borrowed, Pending Approval, Pending Return, Total Fines).

---

## 12. Anti-AI-Slop Constraints (Enforced)

| Forbidden | Rationale |
|-----------|-----------|
| Decorative gradients | Only solid semantic backgrounds |
| Glassmorphism / backdrop-blur | Reduces legibility, printing issues |
| Glow / neon effects | Unprofessional, distracts |
| Large shadows on all components | Single elevation scale only |
| Border radius > 10px | Cards 6–8px, badges pill only |
| Excess stat cards | Only where decision-support needed |
| Charts without real need | Tables preferred for admin data |
| Hero sections on dashboards | Page title + actions only |
| Emoji as nav icons | Use inline SVG (future) or text |
| Gratuitous animation | Only hover/focus/drawer/modal |
| Hyperbolic copy | Factual, Indonesian, concise |
| Primary color on large surfaces | Accent only |
| Inconsistent layouts per page | Single layout system |
| Abstract wrapper components | Direct semantic HTML |
| New dependencies for UI | Zero build, zero npm, zero CDN |

---

## 13. Page Inventory & Migration Plan

### 13.1 Page Registry (20 PHP files in `public/`)

| File | Role | Purpose | Layout Type | Current Issues |
|------|------|---------|-------------|----------------|
| `login.php` | Public | Auth entry | Centered card | Gradient bg, inline styles |
| `index.php` | Public | Redirect → login | — | Empty |
| `logout.php` | Auth | Destroy session | — | No UI |
| `403.php` | Error | Forbidden | Centered card | Inline styles, different palette |
| `dashboard_admin.php` | Admin | Overview + nav cards | Card grid | Role-color header, inline styles |
| `dashboard_petugas.php` | Petugas | Overview + action cards | Card grid | Role-color header, inline styles |
| `dashboard_peminjam.php` | Peminjam | Overview + action cards | Card grid | Role-color header, inline styles |
| `users.php` | Admin | CRUD Users | Sidebar + Table + Modal | Duplicated CSS, emoji icons |
| `categories.php` | Admin | CRUD Categories | Sidebar + Table + Modal | Duplicated CSS |
| `tools.php` | Admin | CRUD Tools + Stock | Sidebar + Table + Modal | Duplicated CSS, stock badges |
| `admin_borrowings.php` | Admin | Manage All Borrowings | Sidebar + Table + Filter + Modals | Complex modal, search, duplicated CSS |
| `admin_returns.php` | Admin | Manage All Returns | Sidebar + Table + Filter + Modals | Similar to above |
| `approvals.php` | Petugas | Approve/Reject Borrowings | Sidebar + Table + Filter | Inline confirm forms, duplicated CSS |
| `returns.php` | Petugas | Confirm/Reject Returns | Sidebar + Table + Filter | Inline confirm forms, duplicated CSS |
| `reports.php` | Petugas | Reports + Print | Sidebar + Tabs + Filter + Table + Print view | Dual style blocks (web + print), duplicated CSS |
| `borrow.php` | Peminjam | Submit Borrow Request | Sidebar + Form (radio cards) | Duplicated CSS |
| `my_borrowings.php` | Peminjam | Borrowing History | Sidebar + Table + Filter | Duplicated CSS |
| `return_request.php` | Peminjam | Submit Return Request | Sidebar + Card list + Inline forms | Duplicated CSS |
| `my_returns.php` | Peminjam | Return History | Sidebar + Table + Filter | Duplicated CSS |
| `activity_log.php` | Admin | Audit Log (file-based) | Sidebar + Table + Pagination | Duplicated CSS |

### 13.2 Shared Assets to Create (UI-2)

| Path | Purpose |
|------|---------|
| `public/assets/css/app.css` | All design tokens + component styles + layout + print |
| `public/assets/js/theme.js` | Theme init (inline in head) + toggle handler |
| `includes/partials/head.php` | `<!DOCTYPE>`, `<head>`, tokens, CSS link, theme init script |
| `includes/partials/sidebar.php` | Role-aware sidebar + drawer markup |
| `includes/partials/topbar.php` | Topbar with drawer toggle, page title slot, theme switcher |
| `includes/partials/flash.php` | Flash + validation error rendering |
| `includes/partials/footer.php` | `</main></div></body></html>` + global JS link |
| `includes/partials/modal.php` | Reusable modal structure (optional) |

### 13.3 Migration Sequence (UI-2 → UI-5)

| Phase | Files | Scope |
|-------|-------|-------|
| **UI-2** | Create assets + partials. Refactor `login.php` + `403.php` as test pages (no sidebar). Verify theme, tokens, print. | Foundation |
| **UI-3** | `dashboard_admin.php`, `dashboard_petugas.php`, `dashboard_peminjam.php`, `login.php` | Auth + Role Dashboards |
| **UI-4a** | `users.php`, `categories.php`, `tools.php` | Admin CRUD (table + modal pattern) |
| **UI-4b** | `admin_borrowings.php`, `admin_returns.php` | Admin Transaction Mgmt (search + filter + complex modals) |
| **UI-4c** | `approvals.php`, `returns.php` | Petugas Action Queues (inline confirm forms) |
| **UI-4d** | `reports.php` | Petugas Reports (tabs + print view) |
| **UI-4e** | `borrow.php`, `my_borrowings.php`, `return_request.php`, `my_returns.php` | Peminjam Flow |
| **UI-4f** | `activity_log.php` | Admin Log |
| **UI-5** | Full regression: PHP lint, HTML validation, theme, responsive, keyboard, print, forms, role guards, stock/transaction integrity. Remove dead inline CSS/JS. | Polish & Verify |

> Each phase: implement → verify → report → user review → next phase.

---

## 14. Files to Change (Summary)

| Category | Files | Change Type |
|----------|-------|-------------|
| **New Assets** | `public/assets/css/app.css`, `public/assets/js/theme.js` | Create |
| **New Partials** | `includes/partials/*.php` (6 files) | Create |
| **Auth/Error Pages** | `login.php`, `403.php`, `logout.php` (redirect only), `index.php` | Replace inline styles → partials |
| **Dashboards** | `dashboard_admin.php`, `dashboard_petugas.php`, `dashboard_peminjam.php` | Replace layout → partials; keep PHP logic |
| **Admin CRUD** | `users.php`, `categories.php`, `tools.php` | Replace layout → partials; modals keep IDs |
| **Admin Transactions** | `admin_borrowings.php`, `admin_returns.php` | Replace layout → partials; search/filter forms keep names |
| **Petugas Queues** | `approvals.php`, `returns.php` | Replace layout → partials; inline confirm forms keep `action`, `borrowing_id`, `return_id` |
| **Reports** | `reports.php` | Replace layout → partials; print view keeps own styles (enhanced) |
| **Peminjam Flow** | `borrow.php`, `my_borrowings.php`, `return_request.php`, `my_returns.php` | Replace layout → partials; radio cards → shared component |
| **Log** | `activity_log.php` | Replace layout → partials |

**Backend Files UNCHANGED:**
- `includes/bootstrap.php` (helpers, CSRF, auth, PDO)
- `config/config.php` (credentials)
- `database/*.sql` (schema, procedures, triggers, functions)
- No new PHP classes, no composer, no autoloader changes.

---

## 15. Regression Checklist (UI-5)

| Area | Checks |
|------|--------|
| **PHP Syntax** | `php -l` on all `public/*.php` + `includes/*.php` |
| **HTML Validity** | No unclosed tags, unique IDs, proper nesting, `scope="col"` on `<th>` |
| **Asset Paths** | `asset('css/app.css')` resolves; `asset('js/theme.js')` loads; no 404 |
| **Light/Dark Mode** | Toggle persists in localStorage; no FOUC; system preference respected when no pref; print forces light |
| **Responsive** | 1920px, 1366px, 1024px, 768px, 640px, 375px — no horizontal overflow on layout; drawer opens/closes; tables scroll or card; touch targets ≥ 44px |
| **Keyboard** | Tab through entire page; focus visible on all interactive; drawer trap; modal trap; Escape closes; `aria-expanded` sync |
| **Forms** | All POST endpoints work; CSRF validated; required fields block submit; validation errors show; flash shows; redirects correct |
| **Role Guards** | Admin pages → 403 for Petugas/Peminjam; Petugas pages → 403 for Admin/Peminjam; Peminjam pages → 403 for Admin/Petugas; Logout clears session |
| **Print** | `reports.php?print=1` → A4, no chrome, all rows, headers repeat, dark mode → light |
| **Transaction Integrity** | Create borrowing → stock unchanged; Approve → stock –qty; Return request → stock unchanged; Confirm return → stock +qty + fine calc; Reject → no stock change; Delete borrowing (pending) → no stock change; Delete user with history → blocked |
| **Consistency Audit** | Every page: same header height, sidebar width, button sizes, badge palette, table header style, pagination style, modal size, form spacing |
| **Dead Code Removal** | All inline `<style>` blocks removed; all inline `<script>` (except theme init) moved to `app.js` or partials; no duplicate CSS rules |

---

## 16. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| **FOUC on theme load** | Low | Minor flicker | Synchronous inline init in `<head>` sets `data-theme` before paint; no `visibility: hidden`; safe localStorage fallback |
| **Sidebar active state mismatch** | Medium | Wrong highlight | Compute active from `basename($_SERVER['SCRIPT_NAME'])` in `sidebar.php`; pass `$currentPage` variable |
| **Modal ID collisions** | Low | Broken modals | Namespace modal IDs: `modal-user`, `modal-category`, `modal-tool`, `modal-borrow`, `modal-return`, `modal-confirm` |
| **Drawer focus trap failure** | Low | Accessibility regression | Test with NVDA/VoiceOver; implement lightweight trap (focus first/last, wrap) |
| **Print view broken in dark mode** | Medium | Unusable reports | `@media print` forces light tokens; hide chrome via `display: none !important` |
| **Inline form `onsubmit` confirm removed** | Low | Lost confirmation | Keep `onsubmit="return confirm(...)"` on inline forms; modal confirm uses separate pattern |
| **Radio card tool picker (borrow.php) style regression** | Medium | UX degradation | Extract as shared component `.tool-picker` with same JS selection logic |
| **Asset path break on subdirectory deploy** | Low | 404 CSS/JS | `asset()` helper uses `base_url()`; verify `config.php` `base_url` correct |
| **CSRF token mismatch after modal AJAX** | Low | Form fails | Modal fetches fresh token via AJAX or reuses page token (current: page token reused — safe) |
| **PHP error display in production** | Low | Info leak | `bootstrap.php` already disables `display_errors` on non-localhost; unchanged |

---

## 17. Implementation Notes for Developers

1. **No Build Step.** Edit `app.css` and `theme.js` directly. Browser caches — use `?v=2` query or rename file on deploy.
2. **Partials Are Pure Presentation.** They receive data via global `$config`, `current_user()`, `base_url()`, `asset()`, `get_flash()`, `csrf_token()`. No logic.
3. **Preserve All `name` Attributes.** Forms submit to same endpoints. `action`, `csrf_token`, `id`, `status`, `page`, `search`, `date_from`, `date_to`, `type`, `borrowing_id`, `return_id`, `tool_id`, `user_id`, `quantity`, `borrow_date`, `due_date`, `category_id`, `code`, `name`, `description`, `username`, `password`, `role`, `is_active`.
4. **JavaScript Minimal.** Only: theme toggle, drawer toggle + focus trap, modal open/close + focus trap, tool picker radio highlight, borrow/return date min sync, confirm dialog delegation. No frameworks.
5. **Print Styles in `app.css`.** `@media print` block at end. Reports print view keeps its own `<style>` but inherits tokens.
6. **Testing Checklist per Phase.** Run through all 3 roles. Verify every POST. Check network tab for 404 assets. Test print from browser.

---

## 18. Approval Gate

**UI-1 Deliverable:** This document (`docs/design.md`).

**Next Step:** User review → approve → proceed to **UI-2: Shared Visual Foundation**.

**Do Not Implement** until explicit approval.

---

*End of Design Specification*