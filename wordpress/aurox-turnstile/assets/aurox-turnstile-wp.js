/*!
 * Aurox Turnstile for WordPress v1.0.0 — Protected by AuroxHost
 * (c) 2026 Chimnadindu Great Chibueze (JustDeGreat) · https://auroxhost.com · MIT
 */
(function () {
  'use strict';
  var cfg = window.AuroxTurnstileConfig || {};
  var ready = false;

  function boxes() {
    return Array.prototype.slice.call(document.querySelectorAll('.aurox-ts-box:not([data-widget-id])'));
  }

  function render(box) {
    var err = box.querySelector('.aurox-ts-error');
    var target = box.querySelector('.aurox-ts-widget');
    if (!target || box.getAttribute('data-widget-id')) return;
    try {
      var id = window.turnstile.render(target, {
        sitekey: cfg.siteKey,
        action: box.getAttribute('data-aurox-context') || undefined,
        theme: cfg.theme || 'auto',
        size: cfg.size || 'flexible',
        appearance: cfg.appearance || 'always',
        callback: function () { if (err || (err = box.querySelector('.aurox-ts-error'))) err.remove(); },
        'expired-callback': function () { try { window.turnstile.reset(id); } catch (e) {} }
      });
      box.setAttribute('data-widget-id', id);
    } catch (e) {}
  }

  function renderAll() { if (ready) boxes().forEach(render); }

  // Stop empty submissions with a friendly message (server still checks).
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.querySelector) return;
    var box = form.querySelector('.aurox-ts-box');
    if (!box || !ready) return;
    var field = form.querySelector('input[name="cf-turnstile-response"]');
    if (!field || !field.value) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var msg = box.querySelector('.aurox-ts-error');
      if (!msg) {
        msg = document.createElement('div');
        msg.className = 'aurox-ts-error';
        msg.setAttribute('role', 'alert');
        box.insertBefore(msg, box.firstChild);
      }
      msg.textContent = cfg.required;
      if (box.scrollIntoView) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, true);

  window.__auroxTurnstileOnload = function () { ready = true; renderAll(); };

  var s = document.createElement('script');
  s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__auroxTurnstileOnload';
  s.async = true;
  s.defer = true;
  document.head.appendChild(s);

  // WooCommerce re-draws the checkout with AJAX: render again, and reset after a failed order.
  if (window.jQuery) {
    window.jQuery(document.body).on('updated_checkout', renderAll);
    window.jQuery(document.body).on('checkout_error', function () {
      document.querySelectorAll('.aurox-ts-box[data-widget-id]').forEach(function (b) {
        try { window.turnstile.reset(b.getAttribute('data-widget-id')); } catch (e) {}
      });
    });
  }
})();
