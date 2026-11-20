// Offline tests with a fake fetch — no network needed.
const assert = require('assert');
const { verifyTurnstile, widgetHtml } = require('./aurox-turnstile');

const fake = (status, json) => async () => ({ status, json: async () => json });

(async () => {
  let r = await verifyTurnstile('', { secret: 's' });
  assert.strictEqual(r.success, false); assert.deepStrictEqual(r.codes, ['missing-input-response']);

  r = await verifyTurnstile('tok', { secret: 's', fetch: fake(200, { success: true, action: 'login' }), action: 'login' });
  assert.strictEqual(r.success, true);

  r = await verifyTurnstile('tok', { secret: 's', fetch: fake(200, { success: true, action: 'other' }), action: 'login' });
  assert.deepStrictEqual(r.codes, ['action-mismatch']);

  r = await verifyTurnstile('tok', { secret: 's', fetch: fake(200, { success: false, 'error-codes': ['timeout-or-duplicate'] }) });
  assert.match(r.message, /expired/);

  r = await verifyTurnstile('tok', { secret: 's', fetch: async () => { throw new Error('down'); } });
  assert.deepStrictEqual(r.codes, ['network-error']);

  const html = widgetHtml('KEY"<x>', { action: 'log in!' });
  assert.ok(html.includes('Protected by <strong>AuroxHost</strong>'));
  assert.ok(html.includes('data-action="login"'));
  assert.ok(!html.includes('<x>'));

  console.log('All Node tests passed');
})().catch((e) => { console.error(e); process.exit(1); });
