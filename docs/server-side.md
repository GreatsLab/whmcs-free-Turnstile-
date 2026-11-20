# Checking the token on your server

The widget gives the browser a one-time token in a hidden field called
`cf-turnstile-response`. Your server has to send that token to Cloudflare and
only accept the form if Cloudflare says `"success": true`. Skip this step and
bots can simply leave the field out.

- Tokens are valid for **5 minutes** and can be used **once**.
- Keep your **secret key** on the server. Never put it in HTML or JavaScript.

## PHP

Use [`php/AuroxTurnstile.php`](../php/AuroxTurnstile.php):

```php
require 'AuroxTurnstile.php';
$ts = new AuroxTurnstile('SITE_KEY', 'SECRET_KEY');
$check = $ts->verify();
if (!$check['success']) {
    http_response_code(403);
    exit($check['message']);
}
```

## Node.js / Express

Use [`node/aurox-turnstile.js`](../node/aurox-turnstile.js):

```js
const { turnstile } = require('./aurox-turnstile');
app.post('/contact', express.urlencoded({ extended: false }), turnstile({ secret: process.env.TURNSTILE_SECRET_KEY }), handler);
```

## Python (Flask)

```python
import requests
from flask import request, abort

def turnstile_ok():
    r = requests.post(
        "https://challenges.cloudflare.com/turnstile/v0/siteverify",
        data={
            "secret": TURNSTILE_SECRET_KEY,
            "response": request.form.get("cf-turnstile-response", ""),
            "remoteip": request.remote_addr,
        },
        timeout=10,
    )
    return r.ok and r.json().get("success") is True

@app.post("/contact")
def contact():
    if not turnstile_ok():
        abort(403, "Please complete the security check.")
    ...
```

## Anything else (curl)

```bash
curl -s https://challenges.cloudflare.com/turnstile/v0/siteverify \
  --data-urlencode "secret=$TURNSTILE_SECRET_KEY" \
  --data-urlencode "response=$TOKEN_FROM_FORM"
```

## Test keys

Cloudflare publishes keys you can use while building:

| Purpose | Site key | Secret key |
|---|---|---|
| Always passes | `1x00000000000000000000AA` | `1x0000000000000000000000000000000AA` |
| Always blocks | `2x00000000000000000000AB` | `2x0000000000000000000000000000000AA` |
| Forces an interactive challenge | `3x00000000000000000000FF` | `1x0000000000000000000000000000000AA` |
| Token already used (secret) | — | `3x0000000000000000000000000000000AA` |

Swap in your real keys before going live.
