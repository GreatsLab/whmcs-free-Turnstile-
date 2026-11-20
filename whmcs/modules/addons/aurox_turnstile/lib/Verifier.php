<?php
/**
 * Aurox Turnstile — server-side token verification.
 *
 * Talks to Cloudflare's siteverify endpoint and turns the answer into a
 * simple pass / fail with a human-readable reason.
 *
 * @package   AuroxTurnstile
 * @author    Chimnadindu Great Chibueze (JustDeGreat) <https://github.com/GreatsLab>
 * @copyright 2026 Chimnadindu Great Chibueze / AuroxHost
 * @license   MIT
 * @link      https://auroxhost.com
 */

namespace AuroxTurnstile;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Verifier
{
    const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var array<string, array> Results cached per token so a token is only sent once per request. */
    private static $cache = [];

    /** @var callable|null Optional transport (used by tests): fn(array $payload): ?array */
    public static $transport = null;

    /** @var string */
    private $secret;

    /** @var int */
    private $timeout;

    public function __construct($secret, $timeout = 10)
    {
        $this->secret = trim((string) $secret);
        $this->timeout = (int) $timeout;
    }

    /**
     * Verify a token.
     *
     * @param string      $token          Value of the cf-turnstile-response field.
     * @param string|null $remoteIp       Visitor IP (optional, extra signal for Cloudflare).
     * @param string|null $expectedAction If set, the token must have been issued for this action.
     *
     * @return array{success: bool, network_error: bool, codes: string[], message: string, raw: array}
     */
    public function verify($token, $remoteIp = null, $expectedAction = null)
    {
        $token = is_string($token) ? trim($token) : '';

        if ($this->secret === '') {
            return $this->result(false, ['missing-input-secret']);
        }
        if ($token === '') {
            return $this->result(false, ['missing-input-response']);
        }
        if (strlen($token) > 2048) {
            return $this->result(false, ['invalid-input-response']);
        }

        $cacheKey = hash('sha256', $token . '|' . (string) $expectedAction);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $payload = ['secret' => $this->secret, 'response' => $token];
        if ($remoteIp) {
            $payload['remoteip'] = $remoteIp;
        }

        $raw = $this->post($payload);

        if ($raw === null) {
            $result = $this->result(false, ['network-error'], [], true);
        } elseif (!empty($raw['success'])) {
            if ($expectedAction !== null && !empty($raw['action']) && $raw['action'] !== $expectedAction) {
                $result = $this->result(false, ['action-mismatch'], $raw);
            } else {
                $result = $this->result(true, [], $raw);
            }
        } else {
            $codes = isset($raw['error-codes']) && is_array($raw['error-codes']) ? $raw['error-codes'] : ['unknown-error'];
            $result = $this->result(false, $codes, $raw);
        }

        return self::$cache[$cacheKey] = $result;
    }

    /**
     * Check whether the configured secret key is valid by sending a dummy token.
     * Cloudflare answers "invalid-input-secret" for a bad key and
     * "invalid-input-response" for a good key with a bad token.
     *
     * @return array{ok: bool, message: string}
     */
    public function checkSecret()
    {
        if ($this->secret === '') {
            return ['ok' => false, 'message' => 'No secret key has been entered yet.'];
        }
        $raw = $this->post(['secret' => $this->secret, 'response' => 'aurox-key-check']);
        if ($raw === null) {
            return ['ok' => false, 'message' => 'Could not reach Cloudflare. Check that your server can make outbound HTTPS requests.'];
        }
        $codes = isset($raw['error-codes']) ? (array) $raw['error-codes'] : [];
        if (in_array('invalid-input-secret', $codes, true) || in_array('missing-input-secret', $codes, true)) {
            return ['ok' => false, 'message' => 'Cloudflare rejected the secret key. Copy it again from the Turnstile dashboard.'];
        }
        if (!empty($raw['success'])) {
            return ['ok' => true, 'message' => 'This is a Cloudflare test key — every visitor will pass. Swap in your real keys before going live.'];
        }
        return ['ok' => true, 'message' => 'The secret key is valid and Cloudflare is reachable.'];
    }

    /**
     * Turn Cloudflare error codes into something a visitor can understand.
     */
    public static function describe(array $codes)
    {
        $map = [
            'missing-input-response' => 'Please complete the security check before continuing.',
            'invalid-input-response' => 'The security check was not valid. Please try again.',
            'timeout-or-duplicate'   => 'The security check expired. Please complete it again.',
            'action-mismatch'        => 'The security check did not match this form. Please try again.',
            'network-error'          => 'We could not confirm the security check right now. Please try again in a moment.',
            'missing-input-secret'   => 'The security check is not configured correctly. Please contact support.',
            'invalid-input-secret'   => 'The security check is not configured correctly. Please contact support.',
            'internal-error'         => 'The security check had a hiccup. Please try again.',
        ];
        foreach ($codes as $code) {
            if (isset($map[$code])) {
                return $map[$code];
            }
        }
        return 'The security check failed. Please try again.';
    }

    private function result($success, array $codes, array $raw = [], $networkError = false)
    {
        return [
            'success'       => (bool) $success,
            'network_error' => (bool) $networkError,
            'codes'         => array_values(array_map('strval', $codes)),
            'message'       => $success ? '' : self::describe($codes),
            'raw'           => $raw,
        ];
    }

    /**
     * @return array|null Decoded JSON, or null when Cloudflare could not be reached.
     */
    private function post(array $payload)
    {
        if (is_callable(self::$transport)) {
            return call_user_func(self::$transport, $payload);
        }
        $body = http_build_query($payload, '', '&');

        if (function_exists('curl_init')) {
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'AuroxTurnstile/1.0 (+https://auroxhost.com)',
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: AuroxTurnstile/1.0\r\n",
                'content'       => $body,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ]]);
            $response = @file_get_contents(self::ENDPOINT, false, $context);
            $status = $response === false ? 0 : 200;
        }

        if ($response === false || $response === '' || $status === 0 || $status >= 500) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
