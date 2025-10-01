<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Minimal settings manager for OpenAI Product Feed for Woo.
 *
 * - Registers a single option array `oapfw_settings`.
 * - Renders basic fields required for feed metadata and defaults.
 */
class OAPFW_Settings {
    const OPT = 'oapfw_settings';

    public function __construct() {
        add_action('admin_init', [$this, 'register']);
    }

    public function option_name(): string { return self::OPT; }

    public function get(string $key, $default = '') {
        $opt = get_option(self::OPT, []);
        return isset($opt[$key]) ? $opt[$key] : $default;
    }

    public function register() {
        register_setting(self::OPT, self::OPT, [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);

        add_settings_section('oapfw_main', esc_html__('Feed Settings', 'openai-product-feed-for-woo'), function () {
            echo '<p>' . esc_html__('Configure feed format and merchant metadata. These values populate required fields in the feed. No external network calls are made by default.', 'openai-product-feed-for-woo') . '</p>';
        }, self::OPT);

        $fields = [
            'format' => [
                'label' => esc_html__('Feed Format', 'openai-product-feed-for-woo'),
                'render' => function () {
                    $val = $this->get('format', 'json');
                    echo '<select name="' . esc_attr(self::OPT) . '[format]">';
                    foreach (['json','csv','xml','tsv'] as $fmt) {
                        printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($fmt), selected($val, $fmt, false));
                    }
                    echo '</select>';
                },
            ],
            'pull_endpoint_enabled' => [
                'label' => esc_html__('Enable Pull Endpoint (REST)', 'openai-product-feed-for-woo'),
                'render' => function () {
                    $val = $this->get('pull_endpoint_enabled', 'false');
                    echo '<label><input type="checkbox" name="' . esc_attr(self::OPT) . '[pull_endpoint_enabled]" value="true" ' . checked($val, 'true', false) . ' /> ' . esc_html__('Expose read-only endpoint under wc/v3 for OpenAI to pull', 'openai-product-feed-for-woo') . '</label>';
                },
            ],
            'pull_access_token' => [
                'label' => esc_html__('Pull Access Token', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('pull_access_token'); },
            ],
            'delivery_enabled' => [
                'label' => esc_html__('Enable External Delivery (cron)', 'openai-product-feed-for-woo'),
                'render' => function () {
                    $val = $this->get('delivery_enabled', 'false');
                    echo '<label><input type="checkbox" name="' . esc_attr(self::OPT) . '[delivery_enabled]" value="true" ' . checked($val, 'true', false) . ' /> ' . esc_html__('Push feed to endpoint every 15 minutes', 'openai-product-feed-for-woo') . '</label>';
                },
            ],
            'endpoint_url' => [
                'label' => esc_html__('Endpoint URL (HTTPS)', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('endpoint_url'); },
            ],
            'auth_token' => [
                'label' => esc_html__('Authorization Bearer Token', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('auth_token'); },
            ],
            'enable_search_default' => [
                'label' => esc_html__('Default enable_search', 'openai-product-feed-for-woo'),
                'render' => function () {
                    $val = $this->get('enable_search_default', 'true');
                    echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPT) . '[enable_search_default]" value="' . esc_attr($val) . '" placeholder="true|false" />';
                },
            ],
            'enable_checkout_default' => [
                'label' => esc_html__('Default enable_checkout', 'openai-product-feed-for-woo'),
                'render' => function () {
                    $val = $this->get('enable_checkout_default', 'false');
                    echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPT) . '[enable_checkout_default]" value="' . esc_attr($val) . '" placeholder="true|false" />';
                },
            ],
            'seller_name' => [
                'label' => esc_html__('Seller Name', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('seller_name'); },
            ],
            'seller_url' => [
                'label' => esc_html__('Seller URL', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('seller_url'); },
            ],
            'privacy_url' => [
                'label' => esc_html__('Privacy Policy URL', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('privacy_url'); },
            ],
            'tos_url' => [
                'label' => esc_html__('Terms of Service URL', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('tos_url'); },
            ],
            'returns_url' => [
                'label' => esc_html__('Return Policy URL', 'openai-product-feed-for-woo'),
                'render' => function () { $this->text('returns_url'); },
            ],
            'return_window' => [
                'label' => esc_html__('Return Window (days)', 'openai-product-feed-for-woo'),
                'render' => function () { $this->number('return_window'); },
            ],
        ];

        foreach ($fields as $key => $cfg) {
            add_settings_field($key, $cfg['label'], $cfg['render'], self::OPT, 'oapfw_main');
        }
    }

    public function sanitize($input) {
        $out = [];
        $out['format'] = in_array(($input['format'] ?? 'json'), ['json','csv','xml','tsv'], true) ? $input['format'] : 'json';
        $out['pull_endpoint_enabled'] = $this->bool_string($input['pull_endpoint_enabled'] ?? 'false');
        $out['pull_access_token'] = sanitize_text_field($input['pull_access_token'] ?? '');
        $out['delivery_enabled'] = $this->bool_string($input['delivery_enabled'] ?? 'false');
        $out['endpoint_url'] = esc_url_raw($input['endpoint_url'] ?? '');
        $out['auth_token'] = sanitize_text_field($input['auth_token'] ?? '');
        // For checkbox-style inputs, missing means false
        $out['enable_search_default'] = $this->bool_string($input['enable_search_default'] ?? 'false');
        $out['enable_checkout_default'] = $this->bool_string($input['enable_checkout_default'] ?? 'false');
        $out['seller_name'] = sanitize_text_field($input['seller_name'] ?? '');
        $out['seller_url'] = esc_url_raw($input['seller_url'] ?? '');
        $out['privacy_url'] = esc_url_raw($input['privacy_url'] ?? '');
        $out['tos_url'] = esc_url_raw($input['tos_url'] ?? '');
        $out['returns_url'] = esc_url_raw($input['returns_url'] ?? '');
        $out['return_window'] = isset($input['return_window']) ? max(0, absint($input['return_window'])) : 0;
        return $out;
    }

    private function text(string $key) {
        $val = $this->get($key, '');
        printf('<input type="text" class="regular-text" name="%s[%s]" value="%s" />', esc_attr(self::OPT), esc_attr($key), esc_attr($val));
    }

    private function number(string $key) {
        $val = (int) $this->get($key, 0);
        printf('<input type="number" class="small-text" min="0" step="1" name="%s[%s]" value="%d" />', esc_attr(self::OPT), esc_attr($key), $val);
    }

    private function bool_string($v): string {
        $v = strtolower((string) $v);
        return ($v === 'true' || $v === '1' || $v === 'yes') ? 'true' : 'false';
    }
}
