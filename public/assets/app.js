/* QRoute — progressive enhancement only.
   Every page works with JavaScript disabled; this file adds conveniences. */
(function () {
  'use strict';

  /* ---------------------------------------------------- mobile nav */
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ------------------------------------------------------ toast */
  var toastEl = null;
  function toast(message) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = message;
    toastEl.classList.add('show');
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
  }

  /* ------------------------------------------------ copy buttons */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    var text = btn.getAttribute('data-copy');
    var done = function () { toast('Copied to clipboard'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
    } else {
      fallbackCopy(text, done);
    }
  });

  function fallbackCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (err) { toast('Press Ctrl+C to copy'); }
    document.body.removeChild(ta);
  }

  /* ------------------------------------------- confirm on delete */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      e.preventDefault();
    }
  });

  /* ------------------------------------- live QR + slug preview */
  var slugInput = document.querySelector('[data-slug-preview]');
  if (slugInput) {
    var out = document.getElementById(slugInput.getAttribute('data-slug-preview'));
    var sync = function () {
      if (out) out.textContent = slugInput.value || slugInput.getAttribute('placeholder') || '';
    };
    slugInput.addEventListener('input', sync);
    sync();
  }

  /* ---------------------------- QR studio: live colour preview */
  var studio = document.getElementById('qr-studio');
  if (studio) {
    var stage = studio.querySelector('[data-qr-stage]');
    var timer = null;
    var refresh = function () {
      if (!stage) return;
      var params = new URLSearchParams();
      studio.querySelectorAll('[data-qr-param]').forEach(function (el) {
        params.set(el.getAttribute('data-qr-param'), el.value);
      });
      var url = stage.getAttribute('data-qr-src') + '?' + params.toString();
      fetch(url, { headers: { 'Accept': 'image/svg+xml' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
        .then(function (svg) { stage.innerHTML = svg; })
        .catch(function () { /* keep the last good render */ });
    };
    studio.addEventListener('input', function (e) {
      if (!e.target.matches('[data-qr-param]')) return;
      clearTimeout(timer);
      timer = setTimeout(refresh, 180);
    });
  }

  /* ------------------------------- rule builder: add / remove rows */
  var builder = document.getElementById('rule-conditions');
  if (builder) {
    builder.addEventListener('change', function (e) {
      var group = e.target.closest('[data-condition]');
      if (!group) return;
      // Reflect "any" state so the summary line stays accurate.
      var checked = group.querySelectorAll('input:checked').length;
      var summary = group.querySelector('[data-condition-summary]');
      if (summary) {
        summary.textContent = checked === 0 ? 'Any' : checked + ' selected';
      }
    });
  }

  /* --------------------------- theme toggle (persisted per device) */
  var themeBtn = document.querySelector('[data-theme-toggle]');
  if (themeBtn) {
    var apply = function (theme) {
      if (theme === 'light' || theme === 'dark') {
        document.documentElement.setAttribute('data-theme', theme);
      } else {
        document.documentElement.removeAttribute('data-theme');
      }
    };
    themeBtn.addEventListener('click', function () {
      var current = document.documentElement.getAttribute('data-theme');
      var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      var next = current ? (current === 'dark' ? 'light' : 'dark') : (prefersDark ? 'light' : 'dark');
      apply(next);
      try { localStorage.setItem('qroute-theme', next); } catch (err) { /* private mode */ }
    });
  }

  /* ---------------------------- auto-dismiss flash after a while */
  document.querySelectorAll('.alert[data-autodismiss]').forEach(function (el) {
    setTimeout(function () { el.style.display = 'none'; }, 6000);
  });
})();
