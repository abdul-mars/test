<?php
/** @var string $content */
use QRoute\Core\View;
$e = [View::class, 'e'];
$title      = $title      ?? 'QRoute';
$description = $description ?? 'Dynamic QR codes you can re-point any time, with rules that send each scan to the right place.';
$currentUser = $currentUser ?? null;
$activeNav  = $activeNav  ?? '';
$flash      = $flash      ?? null;
$bodyClass  = $bodyClass  ?? '';
$nonce      = View::nonce();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($title) ?></title>
<meta name="description" content="<?= $e($description) ?>">
<meta name="color-scheme" content="light dark">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="<?= $e($description) ?>">
<meta property="og:type" content="website">
<link rel="stylesheet" href="/assets/app.css">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<script src="/assets/theme-init.js" nonce="<?= $e($nonce) ?>"></script>
</head>
<body class="<?= $e($bodyClass) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
  <div class="wrap">
    <a class="brand" href="<?= $currentUser ? '/app' : '/' ?>">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="1" y="1" width="9" height="9" rx="2" stroke="currentColor" stroke-width="2"/>
        <rect x="14" y="1" width="9" height="9" rx="2" stroke="currentColor" stroke-width="2"/>
        <rect x="1" y="14" width="9" height="9" rx="2" stroke="currentColor" stroke-width="2"/>
        <path d="M14 18h5m0 0-2.5-2.5M19 18l-2.5 2.5" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      QRoute
    </a>

    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Toggle navigation">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>

    <nav class="nav" id="site-nav">
      <?php if ($currentUser !== null): ?>
        <a href="/app" class="<?= $activeNav === 'links' ? 'active' : '' ?>">My codes</a>
        <a href="/app/analytics" class="<?= $activeNav === 'analytics' ? 'active' : '' ?>">Analytics</a>
        <a href="/app/settings" class="<?= $activeNav === 'settings' ? 'active' : '' ?>">Settings</a>
        <?php if ($currentUser->isAdmin()): ?>
          <a href="/admin" class="<?= $activeNav === 'admin' ? 'active' : '' ?>">Admin</a>
        <?php endif; ?>
        <button class="btn btn-ghost btn-sm" type="button" data-theme-toggle aria-label="Switch colour theme">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36-.7.7M6.34 17.66l-.7.7m12.72 0-.7-.7M6.34 6.34l-.7-.7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/>
          </svg>
        </button>
        <form method="post" action="/logout" style="margin:0">
          <input type="hidden" name="_csrf" value="<?= $e($csrfToken ?? '') ?>">
          <button class="btn btn-secondary btn-sm" type="submit">Sign out</button>
        </form>
      <?php else: ?>
        <a href="/pricing" class="<?= $activeNav === 'pricing' ? 'active' : '' ?>">Pricing</a>
        <a href="/docs" class="<?= $activeNav === 'docs' ? 'active' : '' ?>">API</a>
        <button class="btn btn-ghost btn-sm" type="button" data-theme-toggle aria-label="Switch colour theme">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36-.7.7M6.34 17.66l-.7.7m12.72 0-.7-.7M6.34 6.34l-.7-.7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/>
          </svg>
        </button>
        <a href="/login">Sign in</a>
        <a href="/register" class="btn btn-sm">Start free</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main id="main">
  <?php if ($flash !== null && ($flash['message'] ?? '') !== ''): ?>
    <div class="wrap" style="padding-top:1.1rem">
      <div class="alert alert-<?= $e($flash['type'] ?? 'info') ?>" role="status" data-autodismiss>
        <?= $e($flash['message']) ?>
      </div>
    </div>
  <?php endif; ?>
  <?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap">
    <div>&copy; <?= date('Y') ?> QRoute — dynamic QR codes that never need reprinting.</div>
    <div class="row">
      <a href="/pricing">Pricing</a>
      <a href="/docs">API</a>
      <a href="/legal/privacy">Privacy</a>
      <a href="/legal/terms">Terms</a>
    </div>
  </div>
</footer>

<script src="/assets/app.js" nonce="<?= $e($nonce) ?>" defer></script>
</body>
</html>
