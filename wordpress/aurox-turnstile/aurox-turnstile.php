<?php
/**
 * Plugin Name:       Aurox Turnstile
 * Plugin URI:        https://github.com/GreatsLab/whmcs-free-Turnstile-
 * Description:       Free Cloudflare Turnstile protection for WordPress login, registration, lost password, comments and WooCommerce. Protected by AuroxHost.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Chimnadindu Great Chibueze (AuroxHost)
 * Author URI:        https://auroxhost.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       aurox-turnstile
 *
 * @package   AuroxTurnstile
 * @copyright 2026 Chimnadindu Great Chibueze / AuroxHost
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Aurox_Turnstile
{
    const VERSION  = '1.0.0';
    const OPTION   = 'aurox_turnstile';
    const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var array<string, array> */
    private $cache = [];

    /** @var array */
    private $opts;

    /** @var bool */
    private $rendered = false;

    public static function instance()
    {
        static $inst = null;
        return $inst ?: ($inst = new self());
    }

    private function __construct()
    {
        $this->opts = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'action_links']);

        if (!$this->ready()) {
            add_action('admin_notices', [$this, 'setup_notice']);
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('login_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_footer', [$this, 'print_assets'], 5);
        add_action('login_footer', [$this, 'print_assets'], 5);

        if ($this->on('login')) {
            add_action('login_form', function () { $this->widget('login'); });
            add_filter('login_form_middle', function ($html) { return $html . $this->widget('login', false); });
            add_filter('authenticate', [$this, 'check_login'], 99, 3);
        }
        if ($this->on('register')) {
            add_action('register_form', function () { $this->widget('register'); });
            add_filter('registration_errors', [$this, 'check_register'], 10, 3);
        }
        if ($this->on('lostpassword')) {
            add_action('lostpassword_form', function () { $this->widget('lostpassword'); });
            add_action('woocommerce_lostpassword_form', function () { $this->widget('lostpassword'); });
            add_action('lostpassword_post', [$this, 'check_lostpassword'], 10, 1);
        }
        if ($this->on('comments')) {
            add_filter('comment_form_submit_field', [$this, 'comment_field'], 10, 1);
            add_filter('preprocess_comment', [$this, 'check_comment'], 1);
        }
        if ($this->on('wc_login')) {
            add_action('woocommerce_login_form', function () { $this->widget('wc_login'); });
            add_filter('woocommerce_process_login_errors', [$this, 'check_wc_login'], 10, 1);
        }
        if ($this->on('wc_register')) {
            add_action('woocommerce_register_form', function () { $this->widget('wc_register'); });
            add_filter('woocommerce_process_registration_errors', [$this, 'check_wc_register'], 10, 1);
        }
        if ($this->on('wc_checkout')) {
            add_action('woocommerce_review_order_before_submit', function () { $this->widget('wc_checkout'); });
            add_action('woocommerce_after_checkout_validation', [$this, 'check_wc_checkout'], 10, 2);
        }
    }

    public static function defaults()
    {
        return [
            'site_key'       => '',
            'secret_key'     => '',
            'theme'          => 'auto',
            'size'           => 'flexible',
            'appearance'     => 'always',
            'login'          => 1,
            'register'       => 1,
            'lostpassword'   => 1,
            'comments'       => 1,
            'wc_login'       => 1,
            'wc_register'    => 1,
            'wc_checkout'    => 1,
            'skip_logged_in' => 1,
            'allowlist'      => '',
        ];
    }

    private function ready()
    {
        return $this->opts['site_key'] !== '' && $this->opts['secret_key'] !== '';
    }

    private function on($key)
    {
        return !empty($this->opts[$key]);
    }

    /* ------------------------------------------------------------------
     * Widget
     * ---------------------------------------------------------------- */

    public function widget($context, $echo = true)
    {
        if ($this->allowlisted()) {
            return '';
        }
        if (in_array($context, ['comment', 'wc_checkout'], true) && is_user_logged_in() && $this->on('skip_logged_in')) {
            return '';
        }
        $this->rendered = true;

        $html = '<div class="aurox-ts-box' . ($this->opts['theme'] === 'dark' ? ' is-dark' : '') . '" data-aurox-context="' . esc_attr($context) . '">'
            . '<div class="aurox-ts-widget"></div>'
            . '<a class="aurox-ts-badge" href="https://auroxhost.com" target="_blank" rel="noopener" title="Bot protection by AuroxHost">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2 3.5 5.2v6.1c0 5.1 3.6 9.6 8.5 10.7 4.9-1.1 8.5-5.6 8.5-10.7V5.2L12 2Z" fill="currentColor"/><path d="m8.2 12.3 2.5 2.5 5.1-5.2" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '<span>' . esc_html__('Protected by', 'aurox-turnstile') . ' <strong>AuroxHost</strong></span></a>'
            . '</div>';

        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above
            return '';
        }
        return $html;
    }

    public function comment_field($submit_field)
    {
        return $this->widget('comment', false) . $submit_field;
    }

    public function register_assets()
    {
        $base = plugin_dir_url(__FILE__) . 'assets/';
        wp_register_style('aurox-turnstile', $base . 'aurox-turnstile.css', [], self::VERSION);
        wp_register_script('aurox-turnstile', $base . 'aurox-turnstile-wp.js', [], self::VERSION, true);
        wp_localize_script('aurox-turnstile', 'AuroxTurnstileConfig', [
            'siteKey'    => $this->opts['site_key'],
            'theme'      => $this->opts['theme'],
            'size'       => $this->opts['size'],
            'appearance' => $this->opts['appearance'],
            'required'   => __('Please complete the security check before continuing.', 'aurox-turnstile'),
            'failed'     => __('The security check could not load. Please refresh the page.', 'aurox-turnstile'),
        ]);
    }

    /** Only load Turnstile on pages that actually show a widget. */
    public function print_assets()
    {
        if (!$this->rendered) {
            return;
        }
        wp_enqueue_style('aurox-turnstile');
        wp_enqueue_script('aurox-turnstile');
        wp_print_styles('aurox-turnstile');
    }

    /* ------------------------------------------------------------------
     * Verification
     * ---------------------------------------------------------------- */

    private function client_ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    private function allowlisted()
    {
        $ip = $this->client_ip();
        if ($ip === '' || trim($this->opts['allowlist']) === '') {
            return false;
        }
        foreach (preg_split('/[\s,]+/', $this->opts['allowlist'], -1, PREG_SPLIT_NO_EMPTY) as $rule) {
            if (self::ip_matches($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    public static function ip_matches($ip, $rule)
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (strpos($rule, '/') === false) {
            return @inet_pton($rule) === $ipBin;
        }
        list($subnet, $bits) = explode('/', $rule, 2);
        $subBin = @inet_pton($subnet);
        if ($subBin === false || strlen($subBin) !== strlen($ipBin) || !ctype_digit($bits) || (int) $bits > strlen($ipBin) * 8) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return ($ipBin[$bytes] & $mask) === ($subBin[$bytes] & $mask);
    }

    /**
     * @return true|string True on success, otherwise a message for the visitor.
     */
    private function verify($context)
    {
        if ($this->allowlisted()) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- Turnstile token is the proof here
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';
        if ($token === '') {
            return __('Please complete the security check before continuing.', 'aurox-turnstile');
        }

        $key = md5($token . $context);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 10,
            'body'    => [
                'secret'   => $this->opts['secret_key'],
                'response' => $token,
                'remoteip' => $this->client_ip(),
            ],
        ]);

        if (is_wp_error($response)) {
            return $this->cache[$key] = __('We could not confirm the security check right now. Please try again.', 'aurox-turnstile');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['success']) && (empty($body['action']) || $body['action'] === $context)) {
            return $this->cache[$key] = true;
        }

        $codes = isset($body['error-codes']) ? (array) $body['error-codes'] : [];
        $message = in_array('timeout-or-duplicate', $codes, true)
            ? __('The security check expired. Please complete it again.', 'aurox-turnstile')
            : __('The security check failed. Please try again.', 'aurox-turnstile');

        return $this->cache[$key] = $message;
    }

    private function error($message)
    {
        return '<strong>' . esc_html__('Error:', 'aurox-turnstile') . '</strong> ' . esc_html($message);
    }

    public function check_login($user, $username, $password)
    {
        // Only the standard wp-login.php form (and wp_login_form()) posts "log".
        // phpcs:ignore WordPress.Security.NonceVerification
        if (!isset($_POST['log']) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $user;
        }
        $ok = $this->verify('login');
        return $ok === true ? $user : new WP_Error('aurox_turnstile', $this->error($ok));
    }

    public function check_register($errors, $login, $email)
    {
        $ok = $this->verify('register');
        if ($ok !== true) {
            $errors->add('aurox_turnstile', $this->error($ok));
        }
        return $errors;
    }

    public function check_lostpassword($errors)
    {
        // Admins sending reset links from the Users screen are not challenged.
        if (is_user_logged_in() && current_user_can('edit_users')) {
            return;
        }
        $ok = $this->verify('lostpassword');
        if ($ok !== true && $errors instanceof WP_Error) {
            $errors->add('aurox_turnstile', $this->error($ok));
        }
    }

    public function check_comment($comment)
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (is_admin() || !isset($_POST['comment_post_ID'])) {
            return $comment;
        }
        if (!empty($comment['comment_type']) && in_array($comment['comment_type'], ['pingback', 'trackback'], true)) {
            return $comment;
        }
        if (is_user_logged_in() && $this->on('skip_logged_in')) {
            return $comment;
        }
        $ok = $this->verify('comment');
        if ($ok !== true) {
            wp_die(esc_html($ok), esc_html__('Security check', 'aurox-turnstile'), ['response' => 403, 'back_link' => true]);
        }
        return $comment;
    }

    public function check_wc_login($validation_error)
    {
        $ok = $this->verify('wc_login');
        if ($ok !== true) {
            $validation_error->add('aurox_turnstile', $ok);
        }
        return $validation_error;
    }

    public function check_wc_register($validation_error)
    {
        $ok = $this->verify('wc_register');
        if ($ok !== true) {
            $validation_error->add('aurox_turnstile', $ok);
        }
        return $validation_error;
    }

    public function check_wc_checkout($data, $errors)
    {
        if (is_user_logged_in() && $this->on('skip_logged_in')) {
            return;
        }
        $ok = $this->verify('wc_checkout');
        if ($ok !== true) {
            $errors->add('aurox_turnstile', $ok);
        }
    }

    /* ------------------------------------------------------------------
     * Admin
     * ---------------------------------------------------------------- */

    public function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=aurox-turnstile')) . '">' . esc_html__('Settings', 'aurox-turnstile') . '</a>');
        return $links;
    }

    public function setup_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'settings_page_aurox-turnstile') {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Aurox Turnstile</strong> is installed but not protecting anything yet. '
            . '<a href="' . esc_url(admin_url('options-general.php?page=aurox-turnstile')) . '">Add your Cloudflare Turnstile keys</a>.</p></div>';
    }

    public function admin_menu()
    {
        add_options_page('Aurox Turnstile', 'Aurox Turnstile', 'manage_options', 'aurox-turnstile', [$this, 'settings_page']);
    }

    public function register_settings()
    {
        register_setting('aurox_turnstile', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    public function sanitize($input)
    {
        $input = (array) $input;
        $out = self::defaults();
        $out['site_key'] = isset($input['site_key']) ? sanitize_text_field($input['site_key']) : '';
        $out['secret_key'] = isset($input['secret_key']) ? sanitize_text_field($input['secret_key']) : '';
        $out['theme'] = isset($input['theme']) && in_array($input['theme'], ['auto', 'light', 'dark'], true) ? $input['theme'] : 'auto';
        $out['size'] = isset($input['size']) && in_array($input['size'], ['flexible', 'normal', 'compact'], true) ? $input['size'] : 'flexible';
        $out['appearance'] = isset($input['appearance']) && in_array($input['appearance'], ['always', 'interaction-only'], true) ? $input['appearance'] : 'always';
        foreach (['login', 'register', 'lostpassword', 'comments', 'wc_login', 'wc_register', 'wc_checkout', 'skip_logged_in'] as $k) {
            $out[$k] = empty($input[$k]) ? 0 : 1;
        }
        $out['allowlist'] = isset($input['allowlist']) ? sanitize_textarea_field($input['allowlist']) : '';
        return $out;
    }

    public function settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $o = $this->opts;
        $name = self::OPTION;
        $forms = [
            'login'        => 'Login (wp-login.php and wp_login_form())',
            'register'     => 'Registration',
            'lostpassword' => 'Lost password (WordPress and WooCommerce)',
            'comments'     => 'Comments',
            'wc_login'     => 'WooCommerce My Account login',
            'wc_register'  => 'WooCommerce My Account registration',
            'wc_checkout'  => 'WooCommerce checkout (classic checkout)',
        ];
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px">
                <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="axwp" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1e6bff"/><stop offset="1" stop-color="#7b5cff"/></linearGradient></defs><path d="M12 2 3.5 5.2v6.1c0 5.1 3.6 9.6 8.5 10.7 4.9-1.1 8.5-5.6 8.5-10.7V5.2L12 2Z" fill="url(#axwp)"/><path d="m8.2 12.3 2.5 2.5 5.1-5.2" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Aurox Turnstile
            </h1>
            <p>Free Cloudflare Turnstile protection. Get your keys from the
                <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Cloudflare dashboard → Turnstile</a>.</p>

            <form method="post" action="options.php">
                <?php settings_fields('aurox_turnstile'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ax-site">Site key</label></th>
                        <td><input id="ax-site" class="regular-text" name="<?php echo esc_attr($name); ?>[site_key]" value="<?php echo esc_attr($o['site_key']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ax-secret">Secret key</label></th>
                        <td><input id="ax-secret" type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr($name); ?>[secret_key]" value="<?php echo esc_attr($o['secret_key']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Protect</th>
                        <td><fieldset>
                            <?php foreach ($forms as $k => $label) : ?>
                                <label style="display:block;margin-bottom:6px"><input type="checkbox" name="<?php echo esc_attr($name . '[' . $k . ']'); ?>" value="1" <?php checked(!empty($o[$k])); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                            <label style="display:block;margin-top:10px"><input type="checkbox" name="<?php echo esc_attr($name); ?>[skip_logged_in]" value="1" <?php checked(!empty($o['skip_logged_in'])); ?>> Don't challenge logged-in users on comments and checkout</label>
                        </fieldset></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ax-theme">Theme</label></th>
                        <td><select id="ax-theme" name="<?php echo esc_attr($name); ?>[theme]">
                            <?php foreach (['auto', 'light', 'dark'] as $t) : ?><option value="<?php echo esc_attr($t); ?>" <?php selected($o['theme'], $t); ?>><?php echo esc_html(ucfirst($t)); ?></option><?php endforeach; ?>
                        </select></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ax-size">Size</label></th>
                        <td><select id="ax-size" name="<?php echo esc_attr($name); ?>[size]">
                            <?php foreach (['flexible', 'normal', 'compact'] as $t) : ?><option value="<?php echo esc_attr($t); ?>" <?php selected($o['size'], $t); ?>><?php echo esc_html(ucfirst($t)); ?></option><?php endforeach; ?>
                        </select></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ax-app">Appearance</label></th>
                        <td><select id="ax-app" name="<?php echo esc_attr($name); ?>[appearance]">
                            <option value="always" <?php selected($o['appearance'], 'always'); ?>>Always show</option>
                            <option value="interaction-only" <?php selected($o['appearance'], 'interaction-only'); ?>>Only when interaction is needed</option>
                        </select></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ax-allow">Allow-listed IPs</label></th>
                        <td><textarea id="ax-allow" class="large-text code" rows="3" name="<?php echo esc_attr($name); ?>[allowlist]"><?php echo esc_textarea($o['allowlist']); ?></textarea>
                            <p class="description">One IP or CIDR range per line. These visitors are never challenged.</p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <p style="color:#6b7280">Aurox Turnstile is free and open source · Built by
                <a href="https://github.com/GreatsLab" target="_blank" rel="noopener">Chimnadindu Great Chibueze</a> ·
                Protected by <a href="https://auroxhost.com" target="_blank" rel="noopener"><strong>AuroxHost</strong></a></p>
        </div>
        <?php
    }
}

add_action('plugins_loaded', ['Aurox_Turnstile', 'instance']);
