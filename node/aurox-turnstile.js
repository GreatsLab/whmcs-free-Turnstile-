/*!
 * Aurox Turnstile for Node.js v1.0.0 — Protected by AuroxHost
 * Cloudflare Turnstile verification + Express middleware. Zero dependencies (Node 18+).
 * (c) 2026 Chimnadindu Great Chibueze (JustDeGreat) · https://auroxhost.com · MIT
 */
'use strict';

const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
const API = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

const MESSAGES = {
  'missing-input-response': 'Please complete the security check before continuing.',
  'invalid-input-response': 'The security check was not valid. Please try again.',
  'timeout-or-duplicate': 'The security check expired. Please complete it again.',
  'action-mismatch': 'The security check did not match this form. Please try again.',
  'network-error': 'We could not confirm the security check right now. Please try again in a moment.',
  'missing-input-secret': 'The security check is not configured correctly.',
  'invalid-input-secret': 'The security check is not configured correctly.'
};

function describe(codes) {
  for (const c of codes) if (MESSAGES[c]) return MESSAGES[c];
  return 'The security check failed. Please try again.';
}

function result(success, codes = [], raw = {}) {
  return { success, codes, message: success ? '' : describe(codes), raw };
}

/**
 * Verify a Turnstile token.
 * @param {string} token  value of the "cf-turnstile-response" field
 * @param {{secret: string, remoteip?: string, action?: string, timeout?: number, fetch?: Function}} opts
 */
async function verifyTurnstile(token, opts = {}) {
  const secret = (opts.secret || process.env.TURNSTILE_SECRET_KEY || '').trim();
  token = typeof token === 'string' ? token.trim() : '';
  if (!secret) return result(false, ['missing-input-secret']);
  if (!token) return result(false, ['missing-input-response']);
  if (token.length > 2048) return result(false, ['invalid-input-response']);

  const body = new URLSearchParams({ secret, response: token });
  if (opts.remoteip) body.set('remoteip', opts.remoteip);

  const doFetch = opts.fetch || globalThis.fetch;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), opts.timeout || 10000);
  let raw;
  try {
    const res = await doFetch(ENDPOINT, { method: 'POST', body, signal: controller.signal });
    if (res.status >= 500) return result(false, ['network-error']);
    raw = await res.json();
  } catch (e) {
    return result(false, ['network-error']);
  } finally {
    clearTimeout(timer);
  }

  if (raw && raw.success) {
    if (opts.action && raw.action && raw.action !== opts.action) return result(false, ['action-mismatch'], raw);
    return result(true, [], raw);
  }
  return result(false, (raw && raw['error-codes']) || ['unknown-error'], raw || {});
}

/**
 * Express / Connect middleware. Needs a body parser (express.urlencoded / express.json) before it.
 *   app.post('/login', turnstile({ secret, action: 'login' }), handler)
 */
function turnstile(options = {}) {
  return async function auroxTurnstile(req, res, next) {
    const token = (req.body && req.body['cf-turnstile-response']) || req.get?.('cf-turnstile-response') || '';
    const ip = req.get?.('cf-connecting-ip') && options.behindCloudflare ? req.get('cf-connecting-ip') : req.ip;
    const check = await verifyTurnstile(token, { ...options, remoteip: ip });
    req.turnstile = check;
    if (check.success) return next();
    if (typeof options.onFail === 'function') return options.onFail(req, res, check);
    res.status(403);
    if (req.accepts?.('html') && !req.xhr) return res.send(`<p>${check.message}</p><p><a href="javascript:history.back()">Go back</a></p>`);
    return res.json({ ok: false, error: check.message, codes: check.codes });
  };
}

/** Widget HTML with the "Protected by AuroxHost" badge. Put it inside your form. */
function widgetHtml(siteKey, { action = '', theme = 'auto', size = 'flexible', appearance = 'always', badge = true, script = true } = {}) {
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  action = String(action).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 32);
  return (script ? `<script src="${API}" async defer></script>` : '') +
    `<div class="aurox-ts-box${theme === 'dark' ? ' is-dark' : ''}">` +
    `<div class="cf-turnstile" data-sitekey="${esc(siteKey)}"${action ? ` data-action="${esc(action)}"` : ''} data-theme="${esc(theme)}" data-size="${esc(size)}" data-appearance="${esc(appearance)}"></div>` +
    (badge ? BADGE : '') + '</div>';
}

const BADGE =
  '<a class="aurox-ts-badge" href="https://auroxhost.com" target="_blank" rel="noopener" title="Bot protection by AuroxHost" ' +
  'style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:3px 10px 3px 8px;border-radius:999px;border:1px solid rgba(30,107,255,.18);background:rgba(30,107,255,.06);color:#1e6bff;font:500 11.5px/1.6 system-ui,sans-serif;text-decoration:none">' +
  '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3.5 5.2v6.1c0 5.1 3.6 9.6 8.5 10.7 4.9-1.1 8.5-5.6 8.5-10.7V5.2L12 2Z" fill="currentColor"/><path d="m8.2 12.3 2.5 2.5 5.1-5.2" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
  '<span>Protected by <strong>AuroxHost</strong></span></a>';

module.exports = { verifyTurnstile, turnstile, widgetHtml, describe, BADGE };
