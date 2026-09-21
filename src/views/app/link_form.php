<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
$errors = $errors ?? [];
$values = $values ?? [];
$canCustomSlug = $canCustomSlug ?? false;
?>
<div class="wrap wrap-mid" style="padding-block:1.6rem">
  <p class="small"><a href="/app">&larr; Back to your codes</a></p>
  <h1 style="font-size:1.5rem">New dynamic code</h1>
  <p class="muted">
    Pick where it should point today. You can change this whenever you like — the
    printed code never changes.
  </p>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
      <div>
        <strong>Please fix the following:</strong>
        <ul><?php foreach ($errors as $err): ?><li><?= $e($err) ?></li><?php endforeach; ?></ul>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="/app/links/new" class="card">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">

    <div class="field">
      <label for="default_url">Destination URL</label>
      <input type="text" id="default_url" name="default_url" inputmode="url"
             placeholder="example.com/menu" required autofocus
             value="<?= $e($values['default_url'] ?? '') ?>">
      <p class="hint">Where a scan goes when no rule matches. We will add https:// if you leave it off.</p>
    </div>

    <div class="field">
      <label for="title">Name <span class="muted" style="font-weight:400">(optional)</span></label>
      <input type="text" id="title" name="title" maxlength="120"
             placeholder="Table tent — spring menu"
             value="<?= $e($values['title'] ?? '') ?>">
      <p class="hint">Only you see this. It makes a long list of codes navigable.</p>
    </div>

    <?php if ($canCustomSlug): ?>
      <div class="field">
        <label for="slug">Custom short link <span class="muted" style="font-weight:400">(optional)</span></label>
        <div class="prefix-input">
          <span class="prefix"><?= $e(preg_replace('#^https?://#', '', $baseUrl)) ?>/</span>
          <input type="text" id="slug" name="slug" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9_\-]*[A-Za-z0-9]"
                 placeholder="spring-menu" value="<?= $e($values['slug'] ?? '') ?>">
        </div>
        <p class="hint">Leave blank for a short random one. This cannot be changed later — it is what gets printed.</p>
      </div>
    <?php endif; ?>

    <div class="row" style="margin-top:1.3rem">
      <button class="btn" type="submit">Create code</button>
      <a class="btn btn-ghost" href="/app">Cancel</a>
    </div>
  </form>
</div>
