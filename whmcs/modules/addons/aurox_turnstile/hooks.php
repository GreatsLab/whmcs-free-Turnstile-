<?php
/**
 * Aurox Turnstile for WHMCS — hooks.
 *
 * Two jobs:
 *  1. Put the Turnstile widget on the protected forms (theme-independent,
 *     injected from the footer so it works with Six, Twenty-One, Lagom and
 *     custom themes without editing a single .tpl file).
 *  2. Check the token on the server before WHMCS acts on the form.
 *
 * @package   AuroxTurnstile
 * @author    Chimnadindu Great Chibueze (JustDeGreat) <https://github.com/GreatsLab>
 * @copyright 2026 Chimnadindu Great Chibueze / AuroxHost
 * @license   MIT
 * @link      https://auroxhost.com
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Verifier.php';
require_once __DIR__ . '/lib/Guard.php';

use AuroxTurnstile\Guard;

/* -------------------------------------------------------------------------
 * Request helpers
 * ---------------------------------------------------------------------- */

function aurox_turnstile_script()
{
    return isset($_SERVER['SCRIPT_NAME']) ? strtolower(basename((string) $_SERVER['SCRIPT_NAME'])) : '';
}

/**
 * The WHMCS route for this request, e.g. "/login" or "/password/reset".
 * Works with and without friendly URLs.
 */
function aurox_turnstile_route()
{
    if (!empty($_GET['rp']) && is_string($_GET['rp'])) {
        return '/' . trim($_GET['rp'], '/');
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $base = isset($_SERVER['SCRIPT_NAME']) ? rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/') : '';
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = preg_replace('#^/index\.php#', '', $path);
    return '/' . trim((string) $path, '/');
}

/**
 * Which protected form (if any) is being submitted right now and must be
 * checked before WHMCS runs? Forms with a native WHMCS validation hook
 * (registration, checkout, tickets) are handled by those hooks instead, so
 * the visitor keeps everything they typed when the check fails.
 */
function aurox_turnstile_early_context()
{
    if (defined('ADMINAREA') || (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST')) {
        return null;
    }

    $script = aurox_turnstile_script();
    // Only ever look at front-end entry points in the WHMCS root.
    if (!in_array($script, ['index.php', 'dologin.php', 'login.php', 'clientarea.php', 'pwreset.php', 'contact.php'], true)) {
        return null;
    }

    $route = strtolower(aurox_turnstile_route());

    // Login: WHMCS 8 posts to /login, WHMCS 7 to dologin.php.
    if (isset($_POST['username'], $_POST['password'])
        && ($script === 'dologin.php' || $route === '/login' || $route === '/login/validate')) {
        return 'login';
    }

    // Password reset request (first step only — the email address).
    if (isset($_POST['email']) && !isset($_POST['newpw'], $_POST['confirmpw'])
        && (preg_match('#^/password/reset(/(begin|send|email))?$#', $route) || $script === 'pwreset.php')) {
        return 'pwreset';
    }

    // Contact form.
    if ($script === 'contact.php' && isset($_POST['action']) && $_POST['action'] === 'send') {
        return 'contact';
    }

    return null;
}

function aurox_turnstile_redirect_back()
{
    $target = '';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $ref = parse_url((string) $_SERVER['HTTP_REFERER']);
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
        $refHost = isset($ref['host']) ? strtolower($ref['host']) . (isset($ref['port']) ? ':' . $ref['port'] : '') : '';
        if ($refHost !== '' && ($refHost === $host || strtolower($ref['host']) === preg_replace('/:\d+$/', '', $host))) {
            $target = (string) $_SERVER['HTTP_REFERER'];
        }
    }
    if ($target === '') {
        $target = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    }
    $target = str_replace(["\r", "\n"], '', $target);
    header('Location: ' . $target, true, 303);
    exit;
}

/* -------------------------------------------------------------------------
 * 1) Early check for login, password reset and contact form
 *    (runs as soon as WHMCS loads hooks, before the form is processed)
 * ---------------------------------------------------------------------- */

(function () {
    try {
        $context = aurox_turnstile_early_context();
        if ($context === null) {
            return;
        }
        $result = Guard::check($context);
        if (!$result['passed']) {
            Guard::flash($context, $result['message']);
            aurox_turnstile_redirect_back();
        }
    } catch (\Throwable $e) {
        // Never take the client area down because of this module.
        if (function_exists('logActivity')) {
            logActivity('Aurox Turnstile error: ' . $e->getMessage());
        }
    }
})();

/* -------------------------------------------------------------------------
 * 2) Native validation hooks — the visitor keeps their form data
 * ---------------------------------------------------------------------- */

// Registration (register.php only — not profile edits, not the admin area, not the API).
add_hook('ClientDetailsValidation', 1, function ($vars) {
    if (defined('ADMINAREA') || aurox_turnstile_script() !== 'register.php') {
        return [];
    }
    $result = Guard::check('register');
    return $result['passed'] ? [] : [$result['message']];
});

// Checkout.
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (defined('ADMINAREA')) {
        return [];
    }
    $result = Guard::check('checkout');
    return $result['passed'] ? [] : [$result['message']];
});

// New support ticket from the client area.
add_hook('TicketOpenValidation', 1, function ($vars) {
    if (defined('ADMINAREA') || !empty($vars['isAdmin']) || aurox_turnstile_script() !== 'submitticket.php') {
        return [];
    }
    $result = Guard::check('ticket');
    return $result['passed'] ? [] : [$result['message']];
});

/* -------------------------------------------------------------------------
 * 3) Widget injection
 * ---------------------------------------------------------------------- */

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        $contexts = Guard::activeContexts(Guard::isLoggedIn());
        $flash = Guard::flash();
        if (!$contexts && !$flash) {
            return '';
        }

        $webRoot = isset($vars['WEB_ROOT']) ? rtrim((string) $vars['WEB_ROOT'], '/') : '';
        $assets = $webRoot . '/modules/addons/aurox_turnstile/assets';
        $v = rawurlencode(Guard::VERSION);

        $config = [
            'siteKey'    => Guard::siteKey(),
            'contexts'   => $contexts,
            'page'       => isset($vars['filename']) ? (string) $vars['filename'] : '',
            'template'   => isset($vars['templatefile']) ? (string) $vars['templatefile'] : '',
            'theme'      => Guard::get('theme', 'auto'),
            'size'       => Guard::get('size', 'flexible'),
            'appearance' => Guard::get('appearance', 'always'),
            'language'   => Guard::get('language', 'auto') ?: 'auto',
            'error'      => $flash,
            'text'       => [
                'required' => 'Please complete the security check before continuing.',
                'expired'  => 'The security check expired. Please complete it again.',
                'failed'   => 'The security check could not load. Please refresh the page or disable any script blocker.',
                'badge'    => 'Protected by',
                'brand'    => 'AuroxHost',
            ],
        ];

        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return "\n<!-- Aurox Turnstile v" . Guard::VERSION . " · Protected by AuroxHost · https://auroxhost.com -->\n"
            . '<link rel="stylesheet" href="' . htmlspecialchars($assets . '/aurox-turnstile.css?v=' . $v, ENT_QUOTES) . '">' . "\n"
            . '<script>window.AuroxTurnstileConfig=' . $json . ';</script>' . "\n"
            . '<script src="' . htmlspecialchars($assets . '/aurox-turnstile.js?v=' . $v, ENT_QUOTES) . '" defer></script>' . "\n";
    } catch (\Throwable $e) {
        return '';
    }
});

/* -------------------------------------------------------------------------
 * 4) Housekeeping
 * ---------------------------------------------------------------------- */

add_hook('DailyCronJob', 1, function ($vars) {
    Guard::pruneLog((int) Guard::get('log_days', 30) ?: 30);
});
