<?php
/**
 * Aurox Turnstile — settings, allow-list, logging and the "should we check
 * this request?" logic shared by the hooks and the admin page.
 *
 * @package   AuroxTurnstile
 * @author    Chimnadindu Great Chibueze (JustDeGreat) <https://github.com/GreatsLab>
 * @copyright 2026 Chimnadindu Great Chibueze / AuroxHost
 * @license   MIT
 * @link      https://auroxhost.com
 */

namespace AuroxTurnstile;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Guard
{
    const MODULE    = 'aurox_turnstile';
    const LOG_TABLE = 'mod_aurox_turnstile_log';
    const VERSION   = '1.0.0';

    /** Forms this module knows how to protect => settings key. */
    const CONTEXTS = [
        'login'    => 'protect_login',
        'register' => 'protect_register',
        'checkout' => 'protect_checkout',
        'ticket'   => 'protect_ticket',
        'contact'  => 'protect_contact',
        'pwreset'  => 'protect_pwreset',
    ];

    /** @var array|null */
    private static $settings = null;

    /**
     * All addon settings as setting => value.
     */
    public static function settings()
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $settings = [];
        try {
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->pluck('value', 'setting');
            $settings = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array) $rows;
        } catch (\Throwable $e) {
            $settings = [];
        }
        return self::$settings = $settings;
    }

    public static function get($key, $default = '')
    {
        $s = self::settings();
        return array_key_exists($key, $s) && $s[$key] !== null ? $s[$key] : $default;
    }

    public static function on($key)
    {
        $v = self::get($key, '');
        return $v === 'on' || $v === '1' || $v === 1 || $v === true || $v === 'yes';
    }

    public static function siteKey()
    {
        return trim((string) self::get('site_key'));
    }

    /**
     * The secret key. Some WHMCS versions store "password" fields encrypted,
     * so decrypt when the stored value does not look like a Turnstile key.
     */
    public static function secretKey()
    {
        $value = trim((string) self::get('secret_key'));
        if ($value === '' || self::looksLikeKey($value)) {
            return $value;
        }
        if (function_exists('decrypt')) {
            $decrypted = trim((string) decrypt($value));
            if (self::looksLikeKey($decrypted)) {
                return $decrypted;
            }
        }
        return $value;
    }

    private static function looksLikeKey($value)
    {
        return (bool) preg_match('/^[0-9]x[A-Za-z0-9_\-]{8,}$/', $value);
    }

    /**
     * Is the module ready to protect anything at all?
     */
    public static function ready()
    {
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    public static function enabled($context)
    {
        if (!self::ready() || !isset(self::CONTEXTS[$context])) {
            return false;
        }
        return self::on(self::CONTEXTS[$context]);
    }

    /**
     * Contexts that should show a widget for the current visitor.
     *
     * @return string[]
     */
    public static function activeContexts($loggedIn)
    {
        if (!self::ready() || self::ipAllowed(self::clientIp())) {
            return [];
        }
        $out = [];
        foreach (array_keys(self::CONTEXTS) as $context) {
            if (!self::enabled($context)) {
                continue;
            }
            if ($loggedIn && self::skipForLoggedIn($context)) {
                continue;
            }
            $out[] = $context;
        }
        return $out;
    }

    /**
     * Logged-in clients have already proven themselves, so by default they
     * are not asked again on checkout, tickets or the contact form.
     */
    public static function skipForLoggedIn($context)
    {
        return in_array($context, ['checkout', 'ticket', 'contact'], true) && self::on('skip_logged_in');
    }

    public static function isLoggedIn()
    {
        try {
            if (class_exists('\WHMCS\Authentication\CurrentUser')) {
                $current = new \WHMCS\Authentication\CurrentUser();
                if ($current->user() || $current->client()) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // fall through to the session check
        }
        return !empty($_SESSION['uid']);
    }

    public static function clientIp()
    {
        if (self::on('behind_cloudflare') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    /**
     * Allow-list check. Accepts single IPs and CIDR ranges (IPv4 and IPv6),
     * one per line or separated by commas.
     */
    public static function ipAllowed($ip)
    {
        $list = (string) self::get('ip_allowlist');
        if ($ip === '' || trim($list) === '') {
            return false;
        }
        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (self::ipMatches($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    public static function ipMatches($ip, $rule)
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (strpos($rule, '/') === false) {
            $ruleBin = @inet_pton($rule);
            return $ruleBin !== false && $ruleBin === $ipBin;
        }
        list($subnet, $bits) = explode('/', $rule, 2);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin) || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask));
    }

    /**
     * Run the full check for a form context.
     *
     * @return array{passed: bool, message: string}
     */
    public static function check($context)
    {
        if (!self::enabled($context)) {
            return ['passed' => true, 'message' => ''];
        }
        $ip = self::clientIp();
        if (self::ipAllowed($ip)) {
            return ['passed' => true, 'message' => ''];
        }
        if (self::skipForLoggedIn($context) && self::isLoggedIn()) {
            return ['passed' => true, 'message' => ''];
        }

        $token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
        $verifier = new Verifier(self::secretKey());
        $result = $verifier->verify($token, $ip, $context);

        if (!$result['success'] && $result['network_error'] && self::get('on_network_error', 'Block') === 'Allow') {
            self::log($context, $ip, true, ['network-error-allowed']);
            return ['passed' => true, 'message' => ''];
        }

        self::log($context, $ip, $result['success'], $result['codes']);

        $message = $result['message'];
        $custom = trim((string) self::get('error_message'));
        if (!$result['success'] && $custom !== '' && !$result['network_error']) {
            $message = $custom;
        }

        return ['passed' => $result['success'], 'message' => $message];
    }

    public static function log($context, $ip, $success, array $codes)
    {
        if (!self::on('enable_log')) {
            return;
        }
        try {
            Capsule::table(self::LOG_TABLE)->insert([
                'context'     => substr((string) $context, 0, 32),
                'ip'          => substr((string) $ip, 0, 45),
                'success'     => $success ? 1 : 0,
                'error_codes' => substr(implode(',', $codes), 0, 255),
                'user_agent'  => substr(isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '', 0, 255),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a login or checkout.
        }
    }

    public static function createTable()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::LOG_TABLE)) {
            return;
        }
        $schema->create(self::LOG_TABLE, function ($table) {
            $table->increments('id');
            $table->string('context', 32);
            $table->string('ip', 45);
            $table->boolean('success')->default(0);
            $table->string('error_codes', 255)->default('');
            $table->string('user_agent', 255)->default('');
            $table->dateTime('created_at');
            $table->index('created_at');
            $table->index('context');
        });
    }

    public static function dropTable()
    {
        Capsule::schema()->dropIfExists(self::LOG_TABLE);
    }

    public static function pruneLog($days)
    {
        $days = max(1, (int) $days);
        try {
            Capsule::table(self::LOG_TABLE)
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
                ->delete();
        } catch (\Throwable $e) {
            // table may not exist yet
        }
    }

    /**
     * Messages are carried across the redirect after a failed check.
     */
    public static function flash($context = null, $message = null)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if ($message !== null) {
            $_SESSION['aurox_turnstile_error'] = ['context' => (string) $context, 'message' => (string) $message];
            return null;
        }
        $flash = isset($_SESSION['aurox_turnstile_error']) && is_array($_SESSION['aurox_turnstile_error'])
            ? $_SESSION['aurox_turnstile_error'] : null;
        unset($_SESSION['aurox_turnstile_error']);
        return $flash;
    }
}
