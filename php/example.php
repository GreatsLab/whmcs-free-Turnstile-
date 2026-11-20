<?php
/**
 * Example: a contact form protected by Aurox Turnstile.
 * Run locally with:  php -S localhost:8000 example.php
 *
 * The keys below are Cloudflare's public test keys (always pass).
 * Replace them with your own from https://dash.cloudflare.com/?to=/:account/turnstile
 */
require __DIR__ . '/AuroxTurnstile.php';

$turnstile = new AuroxTurnstile(
    getenv('TURNSTILE_SITE_KEY') ?: '1x00000000000000000000AA',
    getenv('TURNSTILE_SECRET_KEY') ?: '1x0000000000000000000000000000000AA'
);

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $check = $turnstile->verify('contact');
    $notice = $check['success']
        ? '<p class="ok">Thanks! Your message passed the security check.</p>'
        : '<p class="bad">' . htmlspecialchars($check['message']) . '</p>';
    // ...send the email / save the message here when $check['success'] is true.
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contact · Aurox Turnstile demo</title>
  <style>
    body{font:16px/1.5 system-ui,sans-serif;max-width:460px;margin:40px auto;padding:0 16px;color:#111}
    input,textarea{width:100%;padding:10px;border:1px solid #d0d5dd;border-radius:8px;font:inherit;box-sizing:border-box;margin-bottom:12px}
    button{background:linear-gradient(135deg,#1e6bff,#7b5cff);color:#fff;border:0;border-radius:8px;padding:11px 18px;font-weight:600;cursor:pointer}
    .ok{color:#0f7a45}.bad{color:#b3261e}
  </style>
</head>
<body>
  <h1>Contact us</h1>
  <?= $notice ?>
  <form method="post">
    <input name="name" placeholder="Your name" required>
    <input name="email" type="email" placeholder="Email" required>
    <textarea name="message" rows="4" placeholder="Message" required></textarea>
    <?= $turnstile->widget('contact') ?>
    <button type="submit">Send message</button>
  </form>
</body>
</html>
