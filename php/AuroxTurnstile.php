<?php
/**
 * Aurox Turnstile — drop-in Cloudflare Turnstile for any PHP website.
 *
 * One file, no Composer, no framework. Works with plain PHP, Laravel,
 * CodeIgniter, Blesta, HostBill, custom panels — anything that runs PHP 7.2+.
 *
 *   require 'AuroxTurnstile.php';
 *   $ts = new AuroxTurnstile('SITE_KEY', 'SECRET_KEY');
 *
 *   // In your form:
 *   echo $ts->widget('contact');
 *
 *   // When the form is posted:
 *   $check = $ts->verify('contact');
 *   if (!$check['success']) { echo $check['message']; exit; }
 *
 * @author    Chimnadindu Great Chibueze (JustDeGreat) <https://github.com/GreatsLab>
 * @copyright 2026 Chimnadindu Great Chibueze / AuroxHost
 * @license   MIT
 * @link      https://auroxhost.com
 */

class AuroxTurnstile
{
    const VERSION  = '1.0.0';
    const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const API      = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private $siteKey;
    private $secretKey;
    private $options;
    private static $scriptPrinted = false;
    private static $cache = [];

    /**
     * @param array $options theme (auto|light|dark), size (flexible|normal|compact),
     *                       appearance (always|interaction-only), language, badge (bool), timeout (int)
     */
    public function __construct($siteKey, $secretKey, array $options = [])
    {
        $this->siteKey = trim((string) $siteKey);
        $this->secretKey = trim((string) $secretKey);
        $this->options = array_merge([
            'theme'      => 'auto',
            'size'       => 'flexible',
            'appearance' => 'always',
            'language'   => 'auto',
            'badge'      => true,
            'timeout'    => 10,
        ], $options);
    }

    /**
     * HTML for the widget. Put it inside your <form>, just above the submit button.
     *
     * @param string $action Optional label (a-z, 0-9, _ and -, up to 32 chars) that is checked again on verify().
     */
    public function widget($action = '')
    {
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $action = preg_replace('/[^A-Za-z0-9_-]/', '', substr((string) $action, 0, 32));

        $html = '';
        if (!self::$scriptPrinted) {
            self::$scriptPrinted = true;
            $html .= '<script src="' . self::API . '" async defer></script>' . "\n" . self::styles();
        }

        $html .= '<div class="aurox-ts-box' . ($this->options['theme'] === 'dark' ? ' is-dark' : '') . '">'
            . '<div class="cf-turnstile"'
            . ' data-sitekey="' . $e($this->siteKey) . '"'
            . ($action !== '' ? ' data-action="' . $e($action) . '"' : '')
            . ' data-theme="' . $e($this->options['theme']) . '"'
            . ' data-size="' . $e($this->options['size']) . '"'
            . ' data-appearance="' . $e($this->options['appearance']) . '"'
            . ' data-language="' . $e($this->options['language']) . '"></div>';

        if ($this->options['badge']) {
            $html .= self::badge();
        }
        return $html . '</div>';
    }

    /**
     * Check the token that came with the form.
     *
     * @return array{success: bool, message: string, codes: string[], raw: array}
     */
    public function verify($expectedAction = null, $token = null, $remoteIp = null)
    {
        if ($token === null) {
            $token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
        }
        $token = is_string($token) ? trim($token) : '';
        if ($remoteIp === null) {
            $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        }

        if ($this->secretKey === '') {
            return $this->result(false, ['missing-input-secret']);
        }
        if ($token === '') {
            return $this->result(false, ['missing-input-response']);
        }
        if (strlen($token) > 2048) {
            return $this->result(false, ['invalid-input-response']);
        }

        $key = hash('sha256', $token . '|' . (string) $expectedAction);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $payload = ['secret' => $this->secretKey, 'response' => $token];
        if ($remoteIp) {
            $payload['remoteip'] = $remoteIp;
        }
        $raw = $this->post($payload);

        if ($raw === null) {
            $result = $this->result(false, ['network-error']);
        } elseif (!empty($raw['success'])) {
            $result = ($expectedAction !== null && $expectedAction !== '' && !empty($raw['action']) && $raw['action'] !== $expectedAction)
                ? $this->result(false, ['action-mismatch'], $raw)
                : $this->result(true, [], $raw);
        } else {
            $result = $this->result(false, isset($raw['error-codes']) ? (array) $raw['error-codes'] : ['unknown-error'], $raw);
        }
        return self::$cache[$key] = $result;
    }

    public static function badge()
    {
        return '<a class="aurox-ts-badge" href="https://auroxhost.com" target="_blank" rel="noopener" title="Bot protection by AuroxHost">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2 3.5 5.2v6.1c0 5.1 3.6 9.6 8.5 10.7 4.9-1.1 8.5-5.6 8.5-10.7V5.2L12 2Z" fill="currentColor"/><path d="m8.2 12.3 2.5 2.5 5.1-5.2" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '<span>Protected by <strong>AuroxHost</strong></span></a>';
    }

    public static function styles()
    {
        return '<style>'
            . '.aurox-ts-box{margin:14px 0 16px}'
            . '.aurox-ts-badge{display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:3px 10px 3px 8px;border-radius:999px;'
            . 'border:1px solid rgba(30,107,255,.18);background:rgba(30,107,255,.06);color:#1e6bff;font:500 11.5px/1.6 system-ui,sans-serif;text-decoration:none}'
            . '.aurox-ts-badge:hover{background:rgba(30,107,255,.12)}'
            . '.aurox-ts-badge strong{font-weight:700;background:linear-gradient(135deg,#1e6bff,#7b5cff);-webkit-background-clip:text;background-clip:text;color:transparent}'
            . '.aurox-ts-box.is-dark .aurox-ts-badge{border-color:rgba(122,162,255,.3);background:rgba(122,162,255,.1);color:#a9c1ff}'
            . '.aurox-ts-box.is-dark .aurox-ts-badge strong{background:linear-gradient(135deg,#7aa2ff,#b3a1ff);-webkit-background-clip:text;background-clip:text}'
            . '</style>' . "\n";
    }

    public static function describe(array $codes)
    {
        $map = [
            'missing-input-response' => 'Please complete the security check before continuing.',
            'invalid-input-response' => 'The security check was not valid. Please try again.',
            'timeout-or-duplicate'   => 'The security check expired. Please complete it again.',
            'action-mismatch'        => 'The security check did not match this form. Please try again.',
            'network-error'          => 'We could not confirm the security check right now. Please try again in a moment.',
            'missing-input-secret'   => 'The security check is not configured correctly.',
            'invalid-input-secret'   => 'The security check is not configured correctly.',
        ];
        foreach ($codes as $c) {
            if (isset($map[$c])) {
                return $map[$c];
            }
        }
        return 'The security check failed. Please try again.';
    }

    private function result($success, array $codes, array $raw = [])
    {
        return [
            'success' => (bool) $success,
            'message' => $success ? '' : self::describe($codes),
            'codes'   => array_values($codes),
            'raw'     => $raw,
        ];
    }

    private function post(array $payload)
    {
        $body = http_build_query($payload, '', '&');
        if (function_exists('curl_init')) {
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => (int) $this->options['timeout'],
                CURLOPT_USERAGENT      => 'AuroxTurnstile/' . self::VERSION . ' (+https://auroxhost.com)',
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $response = @file_get_contents(self::ENDPOINT, false, stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $body,
                'timeout'       => (int) $this->options['timeout'],
                'ignore_errors' => true,
            ]]));
            $status = $response === false ? 0 : 200;
        }
        if ($response === false || $response === '' || $status === 0 || $status >= 500) {
            return null;
        }
        $json = json_decode($response, true);
        return is_array($json) ? $json : null;
    }
}
