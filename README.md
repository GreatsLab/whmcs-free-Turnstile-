<div align="center">

# Aurox Turnstile

### Free Cloudflare Turnstile protection for WHMCS, WordPress and any website

**Stop bots, fake sign-ups, card-testing orders and spam tickets without making real people solve puzzles.**

🛡️ Protected by **[AuroxHost](https://auroxhost.com)** · Built by **[Chimnadindu Great Chibueze](https://github.com/GreatsLab)**

![Version](https://img.shields.io/badge/version-1.0.0-1e6bff)
![WHMCS](https://img.shields.io/badge/WHMCS-8.x-7b5cff)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4)
![License](https://img.shields.io/badge/license-MIT-0f9d58)

</div>

---

## Why I built this

I run [AuroxHost](https://auroxhost.com), and like every hosting company we get hammered by bots:
fake accounts, stolen cards being tested on checkout, and spam through the contact form and support
tickets. reCAPTCHA annoys real customers, and most of the WHMCS captcha addons I found were paid,
abandoned, or needed me to edit theme files every time WHMCS updated.

Cloudflare Turnstile is free, private, and most visitors never see a challenge. So I wrote the
integration I wanted for my own billing system, and I'm sharing it so other hosts and site owners
can use it too.

## What's in the box

| Folder | For | What it protects |
|---|---|---|
| [`whmcs/`](whmcs/) | **WHMCS 8.x** addon module (7.10 should work too) | Login, registration, checkout, new tickets, contact form, password reset |
| [`wordpress/`](wordpress/) | **WordPress 5.8+** plugin | Login, registration, lost password, comments, WooCommerce login / register / checkout |
| [`php/`](php/) | **Any PHP site** (Blesta, HostBill, Laravel, custom panels) | Whatever form you put it on — one file, no Composer |
| [`node/`](node/) | **Node.js 18+** / Express | Verification function + middleware, zero dependencies |
| [`html/`](html/) | **Anything else** | Copy-paste widget snippet with the badge |

Every version shows a small **"Protected by AuroxHost"** badge under the widget.

## Highlights (WHMCS)

- **No template edits.** The widget is injected automatically, so it works with Six, Twenty-One, Lagom and custom themes, and survives WHMCS updates.
- **Checked on the server**, before WHMCS acts on the form. The widget on its own blocks nothing — the server check is what stops bots.
- **Keeps form data on failure** for registration, checkout and tickets (it uses WHMCS's own validation hooks, so the error appears like any other form error).
- **Per-form switches** — turn protection on or off for each form.
- **Trusts logged-in clients** on checkout, tickets and contact (optional), so your existing customers aren't slowed down.
- **IP allow-list** with CIDR support for IPv4 and IPv6 (your office, uptime monitors).
- **Action binding** — a token solved on the contact form can't be replayed against the login form.
- **Activity log and dashboard** — see passes and blocks per form for the last 24 hours and 7 days, auto-cleaned by the daily cron.
- **Secret key checker** — one click tells you whether Cloudflare accepts your key.
- **Fail-safe choice** — decide whether logins should still work if your server can't reach Cloudflare.
- **Light, dark, auto, flexible width**, and "only show when needed" mode.

---

## 1. Get your free Turnstile keys

1. Log in to the [Cloudflare dashboard](https://dash.cloudflare.com/?to=/:account/turnstile) → **Turnstile** → **Add widget**.
2. Give it a name, add your domain(s) (for example `app.auroxhost.com`), and choose **Managed**.
3. Copy the **Site Key** and **Secret Key**.

You don't need to move your DNS to Cloudflare. Turnstile works on any site.

## 2. Install on WHMCS

1. Download this repository (**Code → Download ZIP**) and unzip it.
2. Upload the folder `whmcs/modules/addons/aurox_turnstile` to `modules/addons/` in your WHMCS root, so you end up with:
   ```
   /your-whmcs/modules/addons/aurox_turnstile/aurox_turnstile.php
   ```
3. In the WHMCS admin go to **System Settings → Addon Modules**, find **Aurox Turnstile** and click **Activate**.
4. Click **Configure**, paste your **Site Key** and **Secret Key**, tick the forms you want to protect, and give your admin role access.
5. Save. Open your client area in a private window and you'll see the widget on the login page.

The dashboard lives at **Addons → Aurox Turnstile**.

> **Tip:** WHMCS has its own built-in captcha (**Settings → Security → Captcha**). Switch that off for the same forms so visitors don't get two challenges.

### WHMCS settings

| Setting | What it does |
|---|---|
| Site Key / Secret Key | Your Turnstile keys |
| Protect Login / Registration / Checkout / New Tickets / Contact / Password Reset | Turn each form on or off |
| Trust Logged-in Clients | Skip the check on checkout, tickets and contact for signed-in clients |
| Widget Theme | `auto`, `light` or `dark` |
| Widget Size | `flexible` (fills the form, best on mobile), `normal` or `compact` |
| Widget Appearance | `always`, or `interaction-only` to hide it unless a click is needed |
| Widget Language | `auto` or a language code (`en`, `fr`, `pt-br` …) |
| Custom Error Message | Replace the built-in message shown when a check fails |
| Allow-listed IPs | IPs / CIDR ranges that are never challenged |
| Site Is Behind Cloudflare Proxy | Use `CF-Connecting-IP` for the visitor IP (only if WHMCS is proxied) |
| If Cloudflare Is Unreachable | `Block` (safest) or `Allow` |
| Keep Activity Log / Log Retention | Store passes and blocks, and for how many days |

### Custom theme placement

The widget goes just above the form's submit button. If you want it somewhere specific, add this
anywhere inside the form in your template and the widget will be placed there instead:

```html
<div data-aurox-turnstile></div>
```

### Locked out?

Remove or rename the `modules/addons/aurox_turnstile` folder (or deactivate the addon). Protection
stops immediately. You can also add your own IP to the allow-list.

## 3. Install on WordPress

1. Zip the `wordpress/aurox-turnstile` folder (or upload the folder by FTP to `wp-content/plugins/`).
2. **Plugins → Add New → Upload Plugin**, then **Activate**.
3. Go to **Settings → Aurox Turnstile**, paste your keys, pick the forms, save.

WooCommerce support covers the **classic** checkout shortcode. The block-based checkout uses a different API and isn't covered yet.

## 4. Any PHP website

```php
require 'AuroxTurnstile.php';
$turnstile = new AuroxTurnstile('YOUR_SITE_KEY', 'YOUR_SECRET_KEY');

// Inside your <form>, above the submit button:
echo $turnstile->widget('contact');

// When the form is submitted:
$check = $turnstile->verify('contact');
if (!$check['success']) {
    exit($check['message']);
}
```

Try it locally: `cd php && php -S localhost:8000 example.php`

## 5. Node.js / Express

```js
const { turnstile, widgetHtml } = require('./aurox-turnstile');

app.use(express.urlencoded({ extended: false }));
app.get('/login', (req, res) => res.send(`<form method="post">… ${widgetHtml(SITE_KEY, { action: 'login' })} <button>Sign in</button></form>`));
app.post('/login', turnstile({ secret: process.env.TURNSTILE_SECRET_KEY, action: 'login' }), handler);
```

Run the demo: `cd node && npm install express && node example-express.js`. Run tests: `npm test`.

## 6. Anything else

Copy [`html/turnstile-snippet.html`](html/turnstile-snippet.html) into your form, then check the token
on your server. [`docs/server-side.md`](docs/server-side.md) has examples for PHP, Node, Python and curl,
plus Cloudflare's test keys.

---

## How it works

```
 Browser                         Your server                       Cloudflare
 ───────                         ───────────                       ──────────
 Turnstile widget runs  ──────►  form + cf-turnstile-response
                                 POST secret + token + IP  ──────► siteverify
                                 ◄──────────────────────────────── success / error-codes
                                 accept the form, or show an error
```

Tokens last five minutes and work once. If a visitor waits too long, the widget refreshes itself.

## Security notes

- Your secret key only ever lives on your server.
- Tokens are bound to the form they were issued for (`action`), so they can't be reused elsewhere.
- The WHMCS module only looks at front-end entry points; the admin area, API, gateway callbacks and 2FA step are left alone.
- If something inside the module throws an error, it is logged to the WHMCS Activity Log and the client area keeps working.

Found a security issue? Please message me privately (links below) instead of opening a public issue.

## Credits

**Aurox Turnstile** is designed, written and maintained by
**Chimnadindu Great Chibueze ([JustDeGreat](https://x.com/JustDeGreat))** — founder & CEO of
**[AuroxHost](https://auroxhost.com)**, network and cloud engineer, Blue Team.

- 🌐 [auroxhost.com](https://auroxhost.com)
- 💻 [github.com/GreatsLab](https://github.com/GreatsLab)
- 💼 [linkedin.com/in/chimnadindu](https://www.linkedin.com/in/chimnadindu) · [AuroxHost on LinkedIn](https://www.linkedin.com/company/auroxhost)
- 🐦 [x.com/JustDeGreat](https://x.com/JustDeGreat)
- 📣 [AuroxHost WhatsApp channel](https://whatsapp.com/channel/0029VbE0FCREKyZAE4SW1u3H)

If this saved you from a wave of spam, a ⭐ on the repo means a lot.

## License

MIT © 2026 Chimnadindu Great Chibueze / AuroxHost. You can use it on commercial sites for free.
Please keep the copyright notice and the "Protected by AuroxHost" credit.

Cloudflare and Turnstile are trademarks of Cloudflare, Inc. This is an independent open-source
integration and is not made or endorsed by Cloudflare. WHMCS and WordPress are trademarks of their
respective owners.
