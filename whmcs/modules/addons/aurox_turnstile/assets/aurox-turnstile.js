/*!
 * Aurox Turnstile for WHMCS v1.0.0
 * Free Cloudflare Turnstile protection — Protected by AuroxHost
 * (c) 2026 Chimnadindu Great Chibueze (JustDeGreat) · https://auroxhost.com
 * MIT License · https://github.com/GreatsLab/whmcs-free-Turnstile-
 */
(function () {
  'use strict';

  var cfg = window.AuroxTurnstileConfig;
  if (!cfg || window.__auroxTurnstileLoaded) return;
  window.__auroxTurnstileLoaded = true;

  var API = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__auroxTurnstileOnload';
  var text = cfg.text || {};
  var contexts = cfg.contexts || [];
  var pending = [];      // containers waiting for the Turnstile API
  var apiState = 'idle'; // idle | loading | ready | failed

  function has(ctx) { return contexts.indexOf(ctx) !== -1; }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function isPost(form) { return (form.getAttribute('method') || 'get').toLowerCase() === 'post'; }
  function path() { return (location.pathname + location.search).toLowerCase(); }

  /* ---------------------------------------------------------------------
   * Find the forms to protect. Each rule mirrors the server-side check, so
   * a form is only ever checked on the server if it got a widget here.
   * ------------------------------------------------------------------- */
  var rules = {
    login: function () {
      return $$('form').filter(function (f) {
        return isPost(f) && $('input[name="username"]', f) && $('input[name="password"]', f);
      });
    },
    register: function () {
      if (cfg.page !== 'register') return [];
      return $$('form').filter(function (f) {
        return isPost(f) && $('input[name="firstname"]', f) && $('input[name="email"]', f);
      });
    },
    checkout: function () {
      if (cfg.page !== 'cart') return [];
      var f = $('#frmCheckout') || ($('#btnCompleteOrder') && $('#btnCompleteOrder').form);
      if (!f) {
        f = $$('form').filter(function (x) {
          return isPost(x) && /a=checkout/i.test(x.getAttribute('action') || '');
        })[0];
      }
      return f ? [f] : [];
    },
    ticket: function () {
      if (cfg.page !== 'submitticket') return [];
      return $$('form').filter(function (f) {
        return isPost(f) && $('input[name="subject"]', f) && $('textarea[name="message"]', f);
      });
    },
    contact: function () {
      if (cfg.page !== 'contact') return [];
      return $$('form').filter(function (f) {
        return isPost(f) && $('textarea[name="message"]', f);
      });
    },
    pwreset: function () {
      var onResetPage = /password\/reset|pwreset/.test(path()) || /password-reset|pwreset/.test(cfg.template || '');
      return $$('form').filter(function (f) {
        if (!isPost(f) || !$('input[name="email"]', f) || $('input[name="username"]', f) || $('input[name="password"]', f)) return false;
        var action = (f.getAttribute('action') || '').toLowerCase();
        return onResetPage || /password\/reset|pwreset/.test(action);
      });
    }
  };

  /* ---------------------------------------------------------------------
   * UI
   * ------------------------------------------------------------------- */
  var SHIELD = '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
    '<path d="M12 2 3.5 5.2v6.1c0 5.1 3.6 9.6 8.5 10.7 4.9-1.1 8.5-5.6 8.5-10.7V5.2L12 2Z" fill="currentColor"/>' +
    '<path d="m8.2 12.3 2.5 2.5 5.1-5.2" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function buildBox(ctx) {
    var box = document.createElement('div');
    box.className = 'aurox-ts-box' + (cfg.theme === 'dark' ? ' is-dark' : '');
    box.setAttribute('data-aurox-context', ctx);
    box.innerHTML =
      '<div class="aurox-ts-error" role="alert" hidden></div>' +
      '<div class="aurox-ts-widget"></div>' +
      '<a class="aurox-ts-badge" href="https://auroxhost.com" target="_blank" rel="noopener" title="Bot protection by AuroxHost">' +
      SHIELD + '<span>' + escapeHtml(text.badge || 'Protected by') + ' <strong>' + escapeHtml(text.brand || 'AuroxHost') + '</strong></span></a>';
    return box;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function showError(box, msg) {
    var el = $('.aurox-ts-error', box);
    if (!el) return;
    if (msg) { el.textContent = msg; el.hidden = false; } else { el.textContent = ''; el.hidden = true; }
  }

  /** Put the box just above the submit button's row. */
  function mount(form, ctx) {
    if (form.getAttribute('data-aurox-ts')) return null;
    form.setAttribute('data-aurox-ts', ctx);

    var box = buildBox(ctx);
    var manual = $('[data-aurox-turnstile]', form);
    if (manual) {
      manual.appendChild(box);
      return box;
    }

    var buttons = $$('button[type="submit"], input[type="submit"], button:not([type])', form)
      .filter(function (b) { return b.offsetParent !== null || b.getClientRects().length; });
    var btn = buttons[buttons.length - 1] || $$('button[type="submit"], input[type="submit"]', form).pop();

    if (!btn) {
      form.appendChild(box);
      return box;
    }

    var anchor = btn;
    while (anchor.parentElement && anchor.parentElement !== form &&
           !$('input:not([type="hidden"]):not([type="submit"]), textarea, select', anchor.parentElement)) {
      anchor = anchor.parentElement;
    }
    anchor.parentElement.insertBefore(box, anchor);
    return box;
  }

  function guardSubmit(form, box) {
    form.addEventListener('submit', function (e) {
      var field = $('input[name="cf-turnstile-response"]', form);
      if (apiState === 'failed') return; // let the server decide and explain
      if (!field || !field.value) {
        e.preventDefault();
        e.stopImmediatePropagation();
        showError(box, text.required);
        if (box.scrollIntoView) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Re-enable buttons that themes disable on click.
        $$('button[disabled], input[type="submit"][disabled]', form).forEach(function (b) {
          b.disabled = false;
          b.classList.remove('disabled');
        });
      }
    }, true);
  }

  /* ---------------------------------------------------------------------
   * Turnstile
   * ------------------------------------------------------------------- */
  function render(box) {
    var target = $('.aurox-ts-widget', box);
    var ctx = box.getAttribute('data-aurox-context');
    try {
      var id = window.turnstile.render(target, {
        sitekey: cfg.siteKey,
        action: ctx,
        theme: cfg.theme || 'auto',
        size: cfg.size || 'flexible',
        appearance: cfg.appearance || 'always',
        language: cfg.language || 'auto',
        callback: function () { showError(box, ''); },
        'expired-callback': function () {
          showError(box, text.expired);
          try { window.turnstile.reset(id); } catch (e) {}
        },
        'error-callback': function () { showError(box, text.failed); }
      });
      box.setAttribute('data-widget-id', id);
    } catch (e) {
      showError(box, text.failed);
    }
  }

  window.__auroxTurnstileOnload = function () {
    apiState = 'ready';
    pending.splice(0).forEach(render);
  };

  function loadApi() {
    if (apiState !== 'idle') return;
    if (window.turnstile && window.turnstile.render) { window.__auroxTurnstileOnload(); return; }
    apiState = 'loading';
    var s = document.createElement('script');
    s.src = API;
    s.async = true;
    s.defer = true;
    s.onerror = function () {
      apiState = 'failed';
      pending.splice(0).forEach(function (b) { showError(b, text.failed); });
    };
    document.head.appendChild(s);
  }

  function queue(box) {
    if (apiState === 'ready') render(box);
    else { pending.push(box); loadApi(); }
  }

  /* ---------------------------------------------------------------------
   * Boot
   * ------------------------------------------------------------------- */
  var flashShown = false;

  function scan() {
    contexts.forEach(function (ctx) {
      if (!rules[ctx]) return;
      rules[ctx]().forEach(function (form) {
        var box = mount(form, ctx);
        if (!box) return;
        guardSubmit(form, box);
        if (!flashShown && cfg.error && cfg.error.context === ctx) {
          showError(box, cfg.error.message);
          flashShown = true;
        }
        queue(box);
      });
    });

    // A failed check came back but the form for it is gone: show it at the top.
    if (!flashShown && cfg.error && cfg.error.message) {
      var main = $('#main-body .container, #main-body, main, .main-content, body');
      if (main) {
        var note = document.createElement('div');
        note.className = 'aurox-ts-flash';
        note.setAttribute('role', 'alert');
        note.textContent = cfg.error.message;
        main.insertBefore(note, main.firstChild);
        flashShown = true;
      }
    }
  }

  function boot() {
    scan();
    // Catch forms that themes add later (login modals, dynamic checkout steps).
    if ('MutationObserver' in window && contexts.length) {
      var timer = null;
      new MutationObserver(function () {
        clearTimeout(timer);
        timer = setTimeout(scan, 150);
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
