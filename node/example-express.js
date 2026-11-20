// Example: Express login form protected by Aurox Turnstile.
//   npm install express && node example-express.js  →  http://localhost:3000
// Uses Cloudflare's public test keys unless you set TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY.
const express = require('express');
const { turnstile, widgetHtml } = require('./aurox-turnstile');

const SITE_KEY = process.env.TURNSTILE_SITE_KEY || '1x00000000000000000000AA';
const SECRET_KEY = process.env.TURNSTILE_SECRET_KEY || '1x0000000000000000000000000000000AA';

const app = express();
app.use(express.urlencoded({ extended: false }));

app.get('/', (req, res) => {
  res.send(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
  <body style="font:16px system-ui;max-width:420px;margin:40px auto;padding:0 16px">
  <h1>Sign in</h1>
  <form method="post" action="/login">
    <p><input name="email" type="email" placeholder="Email" required style="width:100%;padding:10px"></p>
    <p><input name="password" type="password" placeholder="Password" required style="width:100%;padding:10px"></p>
    ${widgetHtml(SITE_KEY, { action: 'login' })}
    <button style="padding:10px 18px">Sign in</button>
  </form></body>`);
});

app.post('/login', turnstile({ secret: SECRET_KEY, action: 'login' }), (req, res) => {
  res.send('Security check passed — now check the email and password as usual.');
});

app.listen(3000, () => console.log('Aurox Turnstile demo on http://localhost:3000'));
