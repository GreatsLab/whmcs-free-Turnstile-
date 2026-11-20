<?php
/**
 * Aurox Turnstile for WHMCS
 *
 * Free Cloudflare Turnstile protection for the WHMCS client area:
 * login, registration, checkout, support tickets, contact form and
 * password reset. No paid plan, no tracking, no puzzles for real people.
 *
 * Built by Chimnadindu Great Chibueze (JustDeGreat), founder of AuroxHost.
 *
 * @package   AuroxTurnstile
 * @author    Chimnadindu Great Chibueze (JustDeGreat) <https://github.com/GreatsLab>
 * @copyright 2026 Chimnadindu Great Chibueze / AuroxHost
 * @license   MIT
 * @link      https://github.com/GreatsLab/whmcs-free-Turnstile-
 * @link      https://auroxhost.com
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Verifier.php';
require_once __DIR__ . '/lib/Guard.php';

use AuroxTurnstile\Guard;
use AuroxTurnstile\Verifier;
use WHMCS\Database\Capsule;

function aurox_turnstile_config()
{
    return [
        'name'        => 'Aurox Turnstile',
        'description' => 'Free Cloudflare Turnstile protection for login, registration, checkout, tickets, contact and password reset. '
            . 'Protected by <a href="https://auroxhost.com" target="_blank" rel="noopener"><strong>AuroxHost</strong></a>.',
        'author'      => '<a href="https://github.com/GreatsLab" target="_blank" rel="noopener">Chimnadindu Great Chibueze (AuroxHost)</a>',
        'language'    => 'english',
        'version'     => Guard::VERSION,
        'fields'      => [
            'site_key' => [
                'FriendlyName' => 'Site Key',
                'Type'         => 'text',
                'Size'         => '60',
                'Description'  => '<br>From <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Cloudflare dashboard → Turnstile</a>. Free on every Cloudflare plan.',
            ],
            'secret_key' => [
                'FriendlyName' => 'Secret Key',
                'Type'         => 'password',
                'Size'         => '60',
                'Description'  => '<br>Keep this private. It never leaves your server.',
            ],
            'protect_login' => [
                'FriendlyName' => 'Protect Login',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Client area login (login page and any login box on your theme)',
            ],
            'protect_register' => [
                'FriendlyName' => 'Protect Registration',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'New account sign-up page',
            ],
            'protect_checkout' => [
                'FriendlyName' => 'Protect Checkout',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Cart checkout (stops fake orders and card testing)',
            ],
            'protect_ticket' => [
                'FriendlyName' => 'Protect New Tickets',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Opening a new support ticket',
            ],
            'protect_contact' => [
                'FriendlyName' => 'Protect Contact Form',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Pre-sales contact form',
            ],
            'protect_pwreset' => [
                'FriendlyName' => 'Protect Password Reset',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Forgotten password request',
            ],
            'skip_logged_in' => [
                'FriendlyName' => 'Trust Logged-in Clients',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Skip the check on checkout, tickets and contact for clients who are already signed in',
            ],
            'theme' => [
                'FriendlyName' => 'Widget Theme',
                'Type'         => 'dropdown',
                'Options'      => 'auto,light,dark',
                'Default'      => 'auto',
                'Description'  => '"auto" follows the visitor\'s light/dark setting',
            ],
            'size' => [
                'FriendlyName' => 'Widget Size',
                'Type'         => 'dropdown',
                'Options'      => 'flexible,normal,compact',
                'Default'      => 'flexible',
                'Description'  => '"flexible" stretches to the width of the form — best on mobile',
            ],
            'appearance' => [
                'FriendlyName' => 'Widget Appearance',
                'Type'         => 'dropdown',
                'Options'      => 'always,interaction-only',
                'Default'      => 'always',
                'Description'  => '"interaction-only" hides the widget unless Cloudflare needs the visitor to click',
            ],
            'language' => [
                'FriendlyName' => 'Widget Language',
                'Type'         => 'text',
                'Size'         => '10',
                'Default'      => 'auto',
                'Description'  => '"auto" or a code such as en, fr, de, es, pt-br',
            ],
            'error_message' => [
                'FriendlyName' => 'Custom Error Message',
                'Type'         => 'text',
                'Size'         => '80',
                'Default'      => '',
                'Description'  => '<br>Optional. Leave empty to use clear built-in messages.',
            ],
            'ip_allowlist' => [
                'FriendlyName' => 'Allow-listed IPs',
                'Type'         => 'textarea',
                'Rows'         => '3',
                'Cols'         => '60',
                'Description'  => 'One IP or CIDR range per line (IPv4 and IPv6). These visitors skip the check — handy for your office or monitoring.',
            ],
            'behind_cloudflare' => [
                'FriendlyName' => 'Site Is Behind Cloudflare Proxy',
                'Type'         => 'yesno',
                'Description'  => 'Read the real visitor IP from the CF-Connecting-IP header. Only tick this if your WHMCS is proxied (orange cloud).',
            ],
            'on_network_error' => [
                'FriendlyName' => 'If Cloudflare Is Unreachable',
                'Type'         => 'dropdown',
                'Options'      => 'Block,Allow',
                'Default'      => 'Block',
                'Description'  => '"Block" is safest. "Allow" keeps logins working if your server temporarily cannot reach Cloudflare.',
            ],
            'enable_log' => [
                'FriendlyName' => 'Keep Activity Log',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Record passes and blocks so you can see who is being stopped',
            ],
            'log_days' => [
                'FriendlyName' => 'Log Retention (days)',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '30',
                'Description'  => 'Older entries are cleared by the daily cron',
            ],
        ],
    ];
}

function aurox_turnstile_activate()
{
    try {
        Guard::createTable();
        return [
            'status'      => 'success',
            'description' => 'Aurox Turnstile is active. Click "Configure", paste your Cloudflare Turnstile keys and save. Protected by AuroxHost.',
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Could not create the log table: ' . $e->getMessage()];
    }
}

function aurox_turnstile_deactivate()
{
    try {
        Guard::dropTable();
    } catch (\Throwable $e) {
        // nothing to clean up
    }
    return ['status' => 'success', 'description' => 'Aurox Turnstile has been deactivated and its log removed.'];
}

function aurox_turnstile_upgrade($vars)
{
    try {
        Guard::createTable();
    } catch (\Throwable $e) {
        // table already present
    }
}

/**
 * Admin page: Addons → Aurox Turnstile.
 */
function aurox_turnstile_output($vars)
{
    $link = $vars['modulelink'];
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };

    $notice = '';
    if (isset($_GET['action']) && $_GET['action'] === 'checkkey') {
        $check = (new Verifier(Guard::secretKey()))->checkSecret();
        $notice = '<div class="alert alert-' . ($check['ok'] ? 'success' : 'danger') . '">' . $e($check['message']) . '</div>';
    }
    if (isset($_GET['action']) && $_GET['action'] === 'clearlog' && isset($_GET['token']) && function_exists('check_token')) {
        check_token('WHMCS.admin.default');
        try {
            Capsule::table(Guard::LOG_TABLE)->truncate();
            $notice = '<div class="alert alert-success">Activity log cleared.</div>';
        } catch (\Throwable $ex) {
            $notice = '<div class="alert alert-danger">Could not clear the log.</div>';
        }
    }

    // Numbers for the last 24 hours and 7 days.
    $stats = ['day_pass' => 0, 'day_block' => 0, 'week_pass' => 0, 'week_block' => 0];
    $byContext = [];
    $recent = [];
    try {
        $day = date('Y-m-d H:i:s', time() - 86400);
        $week = date('Y-m-d H:i:s', time() - 7 * 86400);
        $stats['day_pass'] = Capsule::table(Guard::LOG_TABLE)->where('created_at', '>=', $day)->where('success', 1)->count();
        $stats['day_block'] = Capsule::table(Guard::LOG_TABLE)->where('created_at', '>=', $day)->where('success', 0)->count();
        $stats['week_pass'] = Capsule::table(Guard::LOG_TABLE)->where('created_at', '>=', $week)->where('success', 1)->count();
        $stats['week_block'] = Capsule::table(Guard::LOG_TABLE)->where('created_at', '>=', $week)->where('success', 0)->count();
        foreach (array_keys(Guard::CONTEXTS) as $ctx) {
            $byContext[$ctx] = Capsule::table(Guard::LOG_TABLE)->where('created_at', '>=', $week)->where('context', $ctx)->where('success', 0)->count();
        }
        $recent = Capsule::table(Guard::LOG_TABLE)->orderBy('id', 'desc')->limit(50)->get();
    } catch (\Throwable $ex) {
        // table missing; show zeros
    }

    $ready = Guard::ready();
    $labels = [
        'login' => 'Login', 'register' => 'Registration', 'checkout' => 'Checkout',
        'ticket' => 'New ticket', 'contact' => 'Contact form', 'pwreset' => 'Password reset',
    ];
    $isTestKey = strpos(Guard::siteKey(), '1x0000') === 0 || strpos(Guard::siteKey(), '2x0000') === 0 || strpos(Guard::siteKey(), '3x0000') === 0;
    $token = function_exists('generate_token') ? generate_token('link') : '';

    echo <<<CSS
<style>
.aurox-ts{--ax:#1e6bff;--ax2:#7b5cff;font-family:inherit}
.aurox-ts .ax-hero{background:linear-gradient(135deg,#0b1530 0%,#14245a 55%,#2a1f6e 100%);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.aurox-ts .ax-hero h2{margin:0 0 4px;font-size:22px;font-weight:700;color:#fff}
.aurox-ts .ax-hero p{margin:0;opacity:.8}
.aurox-ts .ax-hero .ax-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.aurox-ts .ax-btn{display:inline-block;padding:8px 14px;border-radius:8px;background:rgba(255,255,255,.12);color:#fff!important;text-decoration:none!important;border:1px solid rgba(255,255,255,.2);font-weight:600}
.aurox-ts .ax-btn:hover{background:rgba(255,255,255,.22)}
.aurox-ts .ax-btn.primary{background:linear-gradient(135deg,var(--ax),var(--ax2));border-color:transparent}
.aurox-ts .ax-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:18px}
.aurox-ts .ax-card{background:#fff;border:1px solid #e5e9f2;border-radius:12px;padding:16px}
.aurox-ts .ax-card .n{font-size:28px;font-weight:800;line-height:1.1}
.aurox-ts .ax-card .l{color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
.aurox-ts .ok{color:#0f9d58}.aurox-ts .bad{color:#d93025}
.aurox-ts .ax-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600}
.aurox-ts .ax-pill.on{background:#e6f6ee;color:#0f7a45}.aurox-ts .ax-pill.off{background:#f1f3f5;color:#6b7280}
.aurox-ts .ax-pill.pass{background:#e6f6ee;color:#0f7a45}.aurox-ts .ax-pill.block{background:#fdecea;color:#b3261e}
.aurox-ts table.ax-table{width:100%;background:#fff;border:1px solid #e5e9f2;border-radius:12px;overflow:hidden;border-collapse:separate;border-spacing:0}
.aurox-ts .ax-table th{background:#f7f9fc;font-size:12px;text-transform:uppercase;color:#6b7280;padding:10px 12px;text-align:left}
.aurox-ts .ax-table td{padding:9px 12px;border-top:1px solid #eef1f6;font-size:13px;vertical-align:top}
.aurox-ts .ax-foot{margin-top:22px;text-align:center;color:#6b7280;font-size:12px}
.aurox-ts .ax-foot a{color:var(--ax);font-weight:600}
.aurox-ts h3{font-size:16px;font-weight:700;margin:18px 0 10px}
</style>
CSS;

    echo '<div class="aurox-ts">';
    echo '<div class="ax-hero">'
        . aurox_turnstile_shield_svg(44)
        . '<div><h2>Aurox Turnstile</h2><p>Free Cloudflare Turnstile protection for your client area · v' . $e(Guard::VERSION) . '</p></div>'
        . '<div class="ax-actions">'
        . '<a class="ax-btn primary" href="configaddonmods.php#aurox_turnstile">Settings</a>'
        . '<a class="ax-btn" href="' . $e($link) . '&action=checkkey">Check secret key</a>'
        . '<a class="ax-btn" href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Cloudflare dashboard</a>'
        . '</div></div>';

    echo $notice;

    if (!$ready) {
        echo '<div class="alert alert-warning"><strong>Almost there.</strong> Add your Turnstile Site Key and Secret Key under '
            . '<a href="configaddonmods.php#aurox_turnstile">Setup → Addon Modules → Aurox Turnstile → Configure</a>. '
            . 'Nothing is protected until both keys are saved.</div>';
    } elseif ($isTestKey) {
        echo '<div class="alert alert-info">You are using a Cloudflare <strong>test key</strong>. That is perfect for trying things out, but swap in your real keys before going live.</div>';
    }

    echo '<div class="ax-grid">'
        . '<div class="ax-card"><div class="l">Passed · 24h</div><div class="n ok">' . (int) $stats['day_pass'] . '</div></div>'
        . '<div class="ax-card"><div class="l">Blocked · 24h</div><div class="n bad">' . (int) $stats['day_block'] . '</div></div>'
        . '<div class="ax-card"><div class="l">Passed · 7 days</div><div class="n ok">' . (int) $stats['week_pass'] . '</div></div>'
        . '<div class="ax-card"><div class="l">Blocked · 7 days</div><div class="n bad">' . (int) $stats['week_block'] . '</div></div>'
        . '</div>';

    echo '<h3>Protected forms</h3><div class="ax-grid">';
    foreach ($labels as $ctx => $label) {
        $on = Guard::enabled($ctx);
        echo '<div class="ax-card"><div style="display:flex;justify-content:space-between;align-items:center">'
            . '<strong>' . $e($label) . '</strong><span class="ax-pill ' . ($on ? 'on' : 'off') . '">' . ($on ? 'On' : 'Off') . '</span></div>'
            . '<div class="l" style="margin-top:8px">Blocked this week: ' . (int) (isset($byContext[$ctx]) ? $byContext[$ctx] : 0) . '</div></div>';
    }
    echo '</div>';

    echo '<h3 style="display:flex;justify-content:space-between;align-items:center">Recent activity'
        . ($token !== '' ? ' <a class="btn btn-default btn-sm" href="' . $e($link) . '&action=clearlog' . $e($token) . '">Clear log</a>' : '')
        . '</h3>';
    if (!Guard::on('enable_log')) {
        echo '<p class="text-muted">The activity log is switched off in settings.</p>';
    } elseif (count($recent) === 0) {
        echo '<p class="text-muted">No activity yet. Once someone submits a protected form it will show up here.</p>';
    } else {
        echo '<table class="ax-table"><thead><tr><th>When</th><th>Form</th><th>Result</th><th>IP</th><th>Reason</th></tr></thead><tbody>';
        foreach ($recent as $row) {
            $row = (array) $row;
            $pass = (int) $row['success'] === 1;
            echo '<tr><td>' . $e($row['created_at']) . '</td>'
                . '<td>' . $e(isset($labels[$row['context']]) ? $labels[$row['context']] : $row['context']) . '</td>'
                . '<td><span class="ax-pill ' . ($pass ? 'pass' : 'block') . '">' . ($pass ? 'Passed' : 'Blocked') . '</span></td>'
                . '<td><code>' . $e($row['ip']) . '</code></td>'
                . '<td>' . $e($row['error_codes']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<div class="ax-foot">Aurox Turnstile is free and open source · Built by '
        . '<a href="https://github.com/GreatsLab" target="_blank" rel="noopener">Chimnadindu Great Chibueze</a> · '
        . 'Protected by <a href="https://auroxhost.com" target="_blank" rel="noopener">AuroxHost</a> · '
        . '<a href="https://github.com/GreatsLab/whmcs-free-Turnstile-" target="_blank" rel="noopener">GitHub</a></div>';
    echo '</div>';
}

function aurox_turnstile_shield_svg($size = 18)
{
    $size = (int) $size;
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<defs><linearGradient id="axg' . $size . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1e6bff"/><stop offset="1" stop-color="#7b5cff"/></linearGradient></defs>'
        . '<path d="M12 2 3.5 5.2v6.1c0 5.1 3.6 9.6 8.5 10.7 4.9-1.1 8.5-5.6 8.5-10.7V5.2L12 2Z" fill="url(#axg' . $size . ')"/>'
        . '<path d="m8.2 12.3 2.5 2.5 5.1-5.2" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}
