<?php
/**
 * Plugin Name:       OpenAI Product Feed for Woo
 * Plugin URI:        https://automattic.ai
 * Description:       Generate and manage AI-optimized product feeds for WooCommerce.
 * Version:           0.1.0
 * Author:            James LePage
 * Author URI:        https://j.cv
 * License:           GPL-2.0+
 * Text Domain:       openai-product-feed-for-woo
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */

if (!defined("ABSPATH")) {
    exit(); // Exit if accessed directly.
}

define("OAPFW_VERSION", "0.1.0");
define("OAPFW_PLUGIN_FILE", __FILE__);
define("OAPFW_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("OAPFW_PLUGIN_URL", plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, function () {
    // Placeholder for future setup (e.g., options, caps, schedules)
    if (!class_exists("WooCommerce")) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__(
                "OpenAI Product Feed for Woo requires WooCommerce to be installed and active.",
                "openai-product-feed-for-woo",
            ),
        );
    }
});

register_deactivation_hook(__FILE__, function () {
    // Placeholder for cleanup (e.g., unschedule events)
});

add_action("plugins_loaded", function () {
    if (!class_exists("WooCommerce")) {
        add_action("admin_notices", function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__(
                    "OpenAI Product Feed for Woo requires WooCommerce to be installed and active.",
                    "openai-product-feed-for-woo",
                ) .
                "</p></div>";
        });
        return;
    }

    // Bootstrap plugin
    OAPFW_Plugin::instance();
});

final class OAPFW_Plugin
{
    private static $instance = null;
    /** @var OAPFW_Settings */
    private $settings;
    /** @var OAPFW_Feed_Generator */
    private $feed_generator;
    const CRON_HOOK = 'oapfw_push_feed_event';

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action("init", [$this, "register_post_types"]);

        // Load includes lazily to keep bootstrap light
        add_action('init', function () {
            require_once OAPFW_PLUGIN_DIR . 'includes/class-oapfw-settings.php';
            require_once OAPFW_PLUGIN_DIR . 'includes/class-oapfw-feed-generator.php';
            require_once OAPFW_PLUGIN_DIR . 'includes/class-oapfw-product-fields.php';
            $this->settings = new OAPFW_Settings();
            $this->feed_generator = new OAPFW_Feed_Generator($this->settings);
            OAPFW_Product_Fields::init();

            // Schedule on activation and settings changes if delivery is enabled
            add_filter('cron_schedules', [$this, 'every_fifteen_minutes']);
            add_action(self::CRON_HOOK, [$this, 'cron_push_feed']);
            add_action('update_option_' . $this->settings->option_name(), [$this, 'maybe_reschedule'], 10, 3);
            // Debounced delta push on product changes
            add_action('woocommerce_update_product', [$this, 'queue_delta_push'], 10, 1);
            add_action('woocommerce_product_set_stock', [$this, 'queue_delta_push'], 10, 1);
            // WooCommerce settings tab integration
            add_filter('woocommerce_settings_tabs_array', [$this, 'add_wc_settings_tab'], 50);
            add_action('woocommerce_settings_tabs_oapfw', [$this, 'wc_settings_tab_content']);
            add_action('woocommerce_update_options_oapfw', [$this, 'wc_settings_save']);
            // Actions from Woo settings page (Download/Push)
            add_action('admin_post_oapfw_download_feed', [$this, 'handle_download_feed']);
            add_action('admin_post_oapfw_push_now', [$this, 'handle_push_now']);
        }, 0);

        // REST preview (admin-only)
        add_action('rest_api_init', function () {
            register_rest_route('oapfw/v1', '/feed', [
                'methods' => 'GET',
                'permission_callback' => function () { return current_user_can('manage_woocommerce'); },
                'callback' => function (\WP_REST_Request $request) {
                    if (!$this->feed_generator) { return []; }
                    $pid = absint((string) $request->get_param('product_id'));
                    if ($pid) {
                        return rest_ensure_response($this->feed_generator->build_for_product_id($pid));
                    }
                    return rest_ensure_response($this->feed_generator->build_feed());
                },
            ]);
        });

        // Public pull endpoint under Woo namespace (optional, token-protected)
        add_action('rest_api_init', function () {
            register_rest_route('wc/v3', '/openai-feed', [
                'methods' => 'GET',
                'permission_callback' => function (\WP_REST_Request $request) {
                    if (!$this->settings || $this->settings->get('pull_endpoint_enabled','false') !== 'true') { return false; }
                    $token = $this->settings->get('pull_access_token','');
                    if ($token === '') { return false; }
                    // Accept Authorization: Bearer <token> or ?token=...
                    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? trim((string) $_SERVER['HTTP_AUTHORIZATION']) : '';
                    if (!$auth && function_exists('apache_request_headers')) {
                        $headers = apache_request_headers();
                        if (isset($headers['Authorization'])) { $auth = $headers['Authorization']; }
                    }
                    $bearer = '';
                    if ($auth && stripos($auth, 'Bearer ') === 0) { $bearer = substr($auth, 7); }
                    $q = $request->get_param('token');
                    return hash_equals($token, $bearer ?: (string) $q);
                },
                'callback' => function (\WP_REST_Request $request) {
                    if (!$this->feed_generator) { return new \WP_Error('oapfw_no_generator', __('Feed generator unavailable', 'openai-product-feed-for-woo'), ['status'=>500]); }
                    $format = strtolower((string) $request->get_param('format') ?: $this->settings->get('format','json'));
                    $rows = $this->feed_generator->build_feed();
                    $payload = $this->feed_generator->serialize($rows, $format, $content_type);
                    return new \WP_REST_Response($payload, 200, ['Content-Type' => $content_type]);
                },
            ]);
        });
    }

    public function register_post_types()
    {
        // Reserved for future custom post types or taxonomies.
    }

    public function register_admin_menu()
    {
        add_menu_page(
            __("AI Product Feeds", "openai-product-feed-for-woo"),
            __("AI Feeds", "openai-product-feed-for-woo"),
            "manage_woocommerce",
            "oapfw",
            [$this, "render_admin_page"],
            "dashicons-rss",
        );
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'openai-product-feed-for-woo'));
        }

        // Download action (nonce-protected)
        if (isset($_GET['oapfw_action']) && $_GET['oapfw_action'] === 'download_feed') {
            check_admin_referer('oapfw_download_feed');
            $format = $this->settings ? $this->settings->get('format', 'json') : 'json';
            $filename = 'openai-feed-' . date('Ymd-His');
            $rows = $this->feed_generator ? $this->feed_generator->build_feed() : [];
            if ($this->feed_generator) {
                $payload = $this->feed_generator->serialize($rows, $format, $content_type);
            } else {
                $payload = wp_json_encode([]);
                $content_type = 'application/json';
            }
            nocache_headers();
            header('Content-Type: ' . $content_type);
            header('Content-Disposition: attachment; filename=' . $filename . '.' . $format);
            echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
        // Manual push action
        if (isset($_GET['oapfw_action']) && $_GET['oapfw_action'] === 'push_now') {
            check_admin_referer('oapfw_push_now');
            $this->push_to_endpoint();
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success"><p>' . esc_html__('Feed push triggered. Check debug log for status.', 'openai-product-feed-for-woo') . '</p></div>';
            });
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('OpenAI Product Feed for Woo', 'openai-product-feed-for-woo') . '</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        $tabs = [
            'settings' => __('Settings', 'openai-product-feed-for-woo'),
            'export'   => __('Export', 'openai-product-feed-for-woo'),
        ];
        foreach ($tabs as $key => $label) {
            $class = $tab === $key ? ' nav-tab nav-tab-active' : ' nav-tab';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=oapfw&tab=' . $key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'export') {
            echo '<p>' . esc_html__('Download a current snapshot of your product feed in the configured format. This plugin does not push to external endpoints unless explicitly enabled and requested.', 'openai-product-feed-for-woo') . '</p>';
            $dl_url = wp_nonce_url(admin_url('admin.php?page=oapfw&oapfw_action=download_feed'), 'oapfw_download_feed');
            echo '<a href="' . esc_url($dl_url) . '" class="button button-primary">' . esc_html__('Download Feed', 'openai-product-feed-for-woo') . '</a> ';
            if ($this->settings) {
                $format = esc_html($this->settings->get('format', 'json'));
                echo '<span style="margin-left:8px;">' . sprintf(esc_html__('Format: %s', 'openai-product-feed-for-woo'), $format) . '</span>';
            }
            echo '<p style="margin-top:1em;">';
            echo '<code>' . esc_html(rest_url('oapfw/v1/feed')) . '</code> ' . esc_html__('(admin-only preview)', 'openai-product-feed-for-woo');
            echo '</p>';

            // Push now button if delivery enabled
            if ($this->settings && $this->settings->get('delivery_enabled', 'false') === 'true') {
                $push_url = wp_nonce_url(admin_url('admin.php?page=oapfw&oapfw_action=push_now'), 'oapfw_push_now');
                echo '<p><a href="' . esc_url($push_url) . '" class="button">' . esc_html__('Push Now', 'openai-product-feed-for-woo') . '</a></p>';
            }
        } else {
            // Settings tab with subtabs (anchors)
            echo '<div class="subsubsub" style="margin: 8px 0;">';
            $subs = [
                'format'   => __('Format', 'openai-product-feed-for-woo'),
                'delivery' => __('Delivery', 'openai-product-feed-for-woo'),
                'defaults' => __('Defaults', 'openai-product-feed-for-woo'),
                'merchant' => __('Merchant', 'openai-product-feed-for-woo'),
            ];
            $i = 0; foreach ($subs as $id => $label) { $i++; echo '<a href="#' . esc_attr($id) . '">' . esc_html($label) . '</a>' . ($i < count($subs) ? ' | ' : ''); }
            echo '</div>';

            echo '<form method="post" action="options.php">';
            if ($this->settings) { settings_fields($this->settings->option_name()); }

            echo '<a id="format"></a>';
            echo '<h2>' . esc_html__('OpenAI Product Feed', 'openai-product-feed-for-woo') . '</h2>';
            echo '<p class="description">' . esc_html__('Provide a structured product feed so ChatGPT can index your products with up-to-date price and availability.', 'openai-product-feed-for-woo') . '</p>';

            echo '<h3>' . esc_html__('Feed Format', 'openai-product-feed-for-woo') . '</h3>';
            echo '<table class="form-table">';
            echo '<tr><th>' . esc_html__('Format', 'openai-product-feed-for-woo') . '</th><td><select name="oapfw_settings[format]">';
            foreach (['json','csv','xml','tsv'] as $fmt) {
                printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($fmt), selected($this->settings->get('format','json'), $fmt, false));
            }
            echo '</select><p class="description">' . esc_html__('Supported: JSON, CSV, XML, TSV. JSON is recommended unless your integration specifies otherwise.', 'openai-product-feed-for-woo') . '</p></td></tr>';
            echo '</table>';

            echo '<a id="delivery"></a>';
            echo '<h3>' . esc_html__('Scheduled Delivery', 'openai-product-feed-for-woo') . '</h3>';
            echo '<table class="form-table">';
            echo '<tr><th>' . esc_html__('Enable schedule', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<label><input type="checkbox" name="oapfw_settings[delivery_enabled]" value="true" %s/> %s</label>', checked($this->settings->get('delivery_enabled','false'), 'true', false), esc_html__('Push feed every ≤ 15 minutes', 'openai-product-feed-for-woo'));
            echo '<p class="description">' . esc_html__('Posts the feed to your HTTPS endpoint on a 15-minute cadence and after product changes (debounced).', 'openai-product-feed-for-woo') . '</p>';
            echo '</td></tr>';

            echo '<tr><th>' . esc_html__('Endpoint URL (HTTPS)', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<input type="url" class="regular-text" name="oapfw_settings[endpoint_url]" value="%s" placeholder="https://example.com/path">', esc_attr($this->settings->get('endpoint_url','')));
            echo '<p class="description">' . esc_html__('Use the allow-listed HTTPS endpoint provided by OpenAI.', 'openai-product-feed-for-woo') . '</p>';
            echo '</td></tr>';

            echo '<tr><th>' . esc_html__('Authorization Bearer Token', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<input type="text" class="regular-text" name="oapfw_settings[auth_token]" value="%s" placeholder="sk_live_...">', esc_attr($this->settings->get('auth_token','')));
            echo '<p class="description">' . esc_html__('Sent as “Authorization: Bearer <token>”.', 'openai-product-feed-for-woo') . '</p>';
            echo '</td></tr>';
            echo '</table>';

            echo '<a id="defaults"></a>';
            echo '<h3>' . esc_html__('Defaults & Flags', 'openai-product-feed-for-woo') . '</h3>';
            echo '<table class="form-table">';
            $search_val = $this->settings->get('enable_search_default','');
            if ($search_val === '') { $search_val = 'true'; }
            echo '<tr><th>' . esc_html__('enable_search (default)', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<label><input type="checkbox" name="oapfw_settings[enable_search_default]" value="true" %s/> %s</label>', checked($search_val, 'true', false), esc_html__('Allow products in ChatGPT search', 'openai-product-feed-for-woo'));
            echo '<p class="description">' . esc_html__('Defaults to enabled. Uncheck to hide by default (can override per product).', 'openai-product-feed-for-woo') . '</p>';
            echo '</td></tr>';

            $checkout_val = $this->settings->get('enable_checkout_default','');
            if ($checkout_val === '') { $checkout_val = 'false'; }
            echo '<tr><th>' . esc_html__('enable_checkout (default)', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<label><input type="checkbox" name="oapfw_settings[enable_checkout_default]" value="true" %s/> %s</label>', checked($checkout_val, 'true', false), esc_html__('Allow ChatGPT Instant Checkout (if approved)', 'openai-product-feed-for-woo'));
            echo '<p class="description">' . esc_html__('Requires enable_search=true and OpenAI Instant Checkout approval.', 'openai-product-feed-for-woo') . '</p>';
            echo '</td></tr>';
            echo '</table>';

            echo '<a id="merchant"></a>';
            echo '<h3>' . esc_html__('Merchant Info & Policies', 'openai-product-feed-for-woo') . '</h3>';
            echo '<table class="form-table">';
            $default_shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
            $default_shop_url = $default_shop_url ?: home_url('/');
            $default_privacy = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '';
            $seller_name = $this->settings->get('seller_name',''); if ($seller_name === '') { $seller_name = get_bloginfo('name'); }
            $seller_url  = $this->settings->get('seller_url',''); if ($seller_url === '') { $seller_url = $default_shop_url; }
            $privacy_url = $this->settings->get('privacy_url',''); if ($privacy_url === '') { $privacy_url = $default_privacy; }
            $tos = $this->settings->get('tos_url',''); if ($tos === '' && function_exists('wc_terms_and_conditions_page_id')) { $pid = wc_terms_and_conditions_page_id(); if ($pid) { $tos = get_permalink($pid); } }

            echo '<tr><th>' . esc_html__('Seller Name', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<input type="text" class="regular-text" name="oapfw_settings[seller_name]" value="%s">', esc_attr($seller_name));
            echo '</td></tr>';

            echo '<tr><th>' . esc_html__('Seller URL', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<input type="url" class="regular-text" name="oapfw_settings[seller_url]" value="%s" placeholder="https://example.com/store">', esc_attr($seller_url));
            echo '</td></tr>';

            echo '<tr><th>' . esc_html__('Privacy Policy URL', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<input type="url" class="regular-text" name="oapfw_settings[privacy_url]" value="%s" placeholder="https://example.com/privacy">', esc_attr($privacy_url));
            echo '</td></tr>';

            echo '<tr><th>' . esc_html__('Terms of Service URL', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<input type="url" class="regular-text" name="oapfw_settings[tos_url]" value="%s" placeholder="https://example.com/terms">', esc_attr($tos));
            echo '</td></tr>';

            echo '<tr><th>' . esc_html__('Return Policy URL', 'openai-product-feed-for-woo') . '</th><td>';
            printf('<input type="url" class="regular-text" name="oapfw_settings[returns_url]" value="%s" placeholder="https://example.com/returns">', esc_attr($this->settings->get('returns_url','')));
            echo '</td></tr>';

            echo '<tr><th>' . esc_html__('Return Window (days)', 'openai-product-feed-for-woo') . '</th><td>';
            $return_window = (int) $this->settings->get('return_window',0); if ($return_window === 0) { $return_window = 30; }
            printf('<input type="number" class="small-text" min="0" step="1" name="oapfw_settings[return_window]" value="%s">', esc_attr($return_window));
            echo '</td></tr>';
            echo '</table>';

            echo '<p class="description">' . sprintf(
                esc_html__('See the Product Feed Spec for field requirements and examples: %s', 'openai-product-feed-for-woo'),
                '<a href="https://developers.openai.com/commerce/specs/feed/" target="_blank" rel="noopener">developers.openai.com/commerce/specs/feed/</a>'
            ) . '</p>';

            submit_button(__('Save Settings', 'openai-product-feed-for-woo'));
            echo '</form>';
        }

        echo '</div>';
    }

    public function every_fifteen_minutes($schedules) {
        $schedules['every_fifteen_minutes'] = [
            'interval' => 15 * 60,
            'display'  => __('Every 15 Minutes', 'openai-product-feed-for-woo'),
        ];
        return $schedules;
    }

    // WooCommerce Settings Tab integration
    public function add_wc_settings_tab($tabs) {
        $tabs['oapfw'] = __('OpenAI Feed', 'openai-product-feed-for-woo');
        return $tabs;
    }

    public function wc_settings_tab_content() {
        if (!$this->settings) { return; }
        $get = function($k,$d=''){ return esc_attr($this->settings->get($k,$d)); };
        $section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'settings';
        // Sub-tabs (sections)
        echo '<ul class="subsubsub">';
        $sections = [
            'settings' => __('Settings', 'openai-product-feed-for-woo'),
            'export'   => __('Export', 'openai-product-feed-for-woo'),
        ];
        $i=0; foreach ($sections as $id=>$label){ $i++; $cls = $section===$id?'class="current"':''; echo '<li><a '.$cls.' href="'.esc_url(add_query_arg(['page'=>'wc-settings','tab'=>'oapfw','section'=>$id], admin_url('admin.php'))).'">'.esc_html($label).'</a>'.($i<count($sections)?' | ':'').'</li>'; };
        echo '</ul><br class="clear" />';

        echo '<h2>' . esc_html__('OpenAI Product Feed', 'openai-product-feed-for-woo') . '</h2>';
        echo '<p class="description">' . esc_html__('Provide a structured product feed so ChatGPT can index your products with up-to-date price and availability.', 'openai-product-feed-for-woo') . '</p>';

        if ($section === 'export') {
            // Export section only
            echo '<p>' . esc_html__('Download the current feed or push it now to your configured endpoint.', 'openai-product-feed-for-woo') . '</p>';
            echo '<p><code>' . esc_html(rest_url('oapfw/v1/feed')) . '</code> ' . esc_html__('(admin-only preview)', 'openai-product-feed-for-woo') . '</p>';
            echo '<table class="form-table"><tr><th>' . esc_html__('Actions', 'openai-product-feed-for-woo') . '</th><td>';
            echo '<form style="display:inline-block;margin-right:8px;" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="oapfw_download_feed" />';
            wp_nonce_field('oapfw_download_feed');
            echo '<button type="submit" class="button button-primary">' . esc_html__('Download Feed', 'openai-product-feed-for-woo') . '</button>';
            echo '</form>';
            if ($this->settings && $this->settings->get('delivery_enabled','false') === 'true') {
                echo '<form style="display:inline-block;" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="oapfw_push_now" />';
                wp_nonce_field('oapfw_push_now');
                echo '<button type="submit" class="button">' . esc_html__('Push Now', 'openai-product-feed-for-woo') . '</button>';
                echo '</form>';
            }
            // Show pull endpoint details if enabled
            if ($this->settings->get('pull_endpoint_enabled','false') === 'true') {
                $pull = rest_url('wc/v3/openai-feed');
                echo '<p style="margin-top:8px;">' . esc_html__('Pull endpoint:', 'openai-product-feed-for-woo') . ' <code>' . esc_html($pull) . '</code></p>';
            }
            echo '</td></tr></table>';
            return;
        }

        // Feed format
        echo '<h3>' . esc_html__('Feed Format', 'openai-product-feed-for-woo') . '</h3>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Format', 'openai-product-feed-for-woo') . '</th><td><select name="oapfw_settings[format]">';
        foreach (['json','csv','xml','tsv'] as $fmt) {
            printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($fmt), selected($this->settings->get('format','json'), $fmt, false));
        }
        echo '</select><p class="description">' . esc_html__('Supported: JSON, CSV, XML, TSV. JSON is recommended unless your integration specifies otherwise.', 'openai-product-feed-for-woo') . '</p></td></tr>';

        // Delivery
        echo '<tr><th>' . esc_html__('Scheduled Delivery', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<label><input type="checkbox" name="oapfw_settings[delivery_enabled]" value="true" %s/> %s</label>', checked($this->settings->get('delivery_enabled','false'), 'true', false), esc_html__('Enable push every ≤ 15 minutes', 'openai-product-feed-for-woo'));
        echo '<p class="description">' . esc_html__('When enabled, this site will POST your feed to the configured HTTPS endpoint on a 15-minute cadence and after product changes (debounced).', 'openai-product-feed-for-woo') . '</p>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Endpoint URL (HTTPS)', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[endpoint_url]" value="%s" placeholder="https://example.com/path">', $get('endpoint_url',''));
        echo '<p class="description">' . esc_html__('Use the allow-listed HTTPS endpoint provided by OpenAI. All transfers occur over encrypted HTTPS.', 'openai-product-feed-for-woo') . '</p>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Authorization Bearer Token', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="text" class="regular-text" name="oapfw_settings[auth_token]" value="%s" placeholder="sk_live_...">', $get('auth_token',''));
        echo '<p class="description">' . esc_html__('Sent as “Authorization: Bearer <token>” when delivering the feed.', 'openai-product-feed-for-woo') . '</p>';
        echo '</td></tr>';

        echo '</table>';

        // Defaults & Flags
        echo '<h3>' . esc_html__('Defaults & Flags', 'openai-product-feed-for-woo') . '</h3>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('enable_search (default)', 'openai-product-feed-for-woo') . '</th><td>';
        $search_val_wc = $this->settings->get('enable_search_default',''); if ($search_val_wc === '') { $search_val_wc = 'true'; }
        printf('<label><input type="checkbox" name="oapfw_settings[enable_search_default]" value="true" %s/> %s</label>', checked($search_val_wc, 'true', false), esc_html__('Allow products in ChatGPT search', 'openai-product-feed-for-woo'));
        echo '<p class="description">' . esc_html__('Defaults to enabled. Uncheck to hide by default (can override per product).', 'openai-product-feed-for-woo') . '</p>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('enable_checkout (default)', 'openai-product-feed-for-woo') . '</th><td>';
        $checkout_val_wc = $this->settings->get('enable_checkout_default',''); if ($checkout_val_wc === '') { $checkout_val_wc = 'false'; }
        printf('<label><input type="checkbox" name="oapfw_settings[enable_checkout_default]" value="true" %s/> %s</label>', checked($checkout_val_wc, 'true', false), esc_html__('Allow ChatGPT Instant Checkout (if approved)', 'openai-product-feed-for-woo'));
        echo '<p class="description">' . esc_html__('Requires enable_search=true and OpenAI Instant Checkout approval.', 'openai-product-feed-for-woo') . '</p>';
        echo '</td></tr>';

        echo '</table>';

        // Merchant Info & Policies
        echo '<h3>' . esc_html__('Merchant Info & Policies', 'openai-product-feed-for-woo') . '</h3>';
        echo '<p class="description">' . esc_html__('These fields populate seller attribution and policy links in the feed. privacy/tos are required if checkout is enabled.', 'openai-product-feed-for-woo') . '</p>';
        echo '<table class="form-table">';
        $default_shop_url_wc = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
        $default_shop_url_wc = $default_shop_url_wc ?: home_url('/');
        $default_privacy_wc = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '';
        $seller_name_wc = $this->settings->get('seller_name',''); if ($seller_name_wc === '') { $seller_name_wc = get_bloginfo('name'); }
        $seller_url_wc  = $this->settings->get('seller_url',''); if ($seller_url_wc === '') { $seller_url_wc = $default_shop_url_wc; }
        $privacy_url_wc = $this->settings->get('privacy_url',''); if ($privacy_url_wc === '') { $privacy_url_wc = $default_privacy_wc; }
        $tos_wc = $this->settings->get('tos_url',''); if ($tos_wc === '' && function_exists('wc_terms_and_conditions_page_id')) { $pid = wc_terms_and_conditions_page_id(); if ($pid) { $tos_wc = get_permalink($pid); } }

        echo '<tr><th>' . esc_html__('Seller Name', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="text" class="regular-text" name="oapfw_settings[seller_name]" value="%s">', esc_attr($seller_name_wc));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Seller URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[seller_url]" value="%s" placeholder="https://example.com/store">', esc_attr($seller_url_wc));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Privacy Policy URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[privacy_url]" value="%s" placeholder="https://example.com/privacy">', esc_attr($privacy_url_wc));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Terms of Service URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[tos_url]" value="%s" placeholder="https://example.com/terms">', esc_attr($tos_wc));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Return Policy URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[returns_url]" value="%s" placeholder="https://example.com/returns">', $get('returns_url',''));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Return Window (days)', 'openai-product-feed-for-woo') . '</th><td>';
        $return_window_wc = (int)$this->settings->get('return_window',0); if ($return_window_wc === 0) { $return_window_wc = 30; }
        printf('<input type="number" class="small-text" min="0" step="1" name="oapfw_settings[return_window]" value="%s">', esc_attr($return_window_wc));
        echo '<p class="description">' . esc_html__('Days allowed for return. Positive integer.', 'openai-product-feed-for-woo') . '</p></td></tr>';

        echo '</table>';

        // Reference note
        echo '<p class="description">' . sprintf(
            /* translators: %s: docs url */
            esc_html__('See the Product Feed Spec for field requirements and examples: %s', 'openai-product-feed-for-woo'),
            '<a href="https://developers.openai.com/commerce/specs/feed/" target="_blank" rel="noopener">developers.openai.com/commerce/specs/feed/</a>'
        ) . '</p>';
    }

    public function wc_settings_save() {
        if (!$this->settings) { return; }
        $posted = isset($_POST['oapfw_settings']) && is_array($_POST['oapfw_settings']) ? wp_unslash($_POST['oapfw_settings']) : [];
        $sanitized = $this->settings->sanitize($posted);
        update_option($this->settings->option_name(), $sanitized);
    }

    // Admin-post handlers for wc-settings actions
    public function handle_download_feed() {
        if (!current_user_can('manage_woocommerce')) { wp_die(__('Permission denied.', 'openai-product-feed-for-woo')); }
        check_admin_referer('oapfw_download_feed');
        $format = $this->settings ? $this->settings->get('format', 'json') : 'json';
        $filename = 'openai-feed-' . date('Ymd-His');
        $rows = $this->feed_generator ? $this->feed_generator->build_feed() : [];
        if ($this->feed_generator) {
            $payload = $this->feed_generator->serialize($rows, $format, $content_type);
        } else {
            $payload = wp_json_encode([]);
            $content_type = 'application/json';
        }
        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename=' . $filename . '.' . $format);
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function handle_push_now() {
        if (!current_user_can('manage_woocommerce')) { wp_die(__('Permission denied.', 'openai-product-feed-for-woo')); }
        check_admin_referer('oapfw_push_now');
        $this->push_to_endpoint();
        wp_safe_redirect(add_query_arg(['page'=>'wc-settings','tab'=>'oapfw','oapfw_message'=>'pushed'], admin_url('admin.php')));
        exit;
    }

    public function maybe_reschedule($old_value, $value, $option) {
        $enabled = isset($value['delivery_enabled']) && $value['delivery_enabled'] === 'true';
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($enabled && !$ts) {
            wp_schedule_event(time() + 60, 'every_fifteen_minutes', self::CRON_HOOK);
        } elseif (!$enabled && $ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    public function maybe_admin_notice() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') { return; }
        if (isset($_GET['oapfw_message']) && $_GET['oapfw_message'] === 'pushed') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Feed push triggered. Check debug log for status.', 'openai-product-feed-for-woo') . '</p></div>';
        }
    }

    public function queue_delta_push($product_id_or_obj) {
        if (!$this->settings || $this->settings->get('delivery_enabled', 'false') !== 'true') { return; }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 120, self::CRON_HOOK);
        }
    }

    public function cron_push_feed() {
        if (!$this->settings || $this->settings->get('delivery_enabled', 'false') !== 'true') { return; }
        $this->push_to_endpoint();
    }

    private function push_to_endpoint() {
        if (!$this->feed_generator) { return; }
        $rows = $this->feed_generator->build_feed();
        $format = $this->settings->get('format', 'json');
        $endpoint = trim((string) $this->settings->get('endpoint_url', ''));
        if (empty($endpoint)) { return; }
        $payload = $this->feed_generator->serialize($rows, $format, $content_type);
        $headers = ['Content-Type' => $content_type];
        $token = $this->settings->get('auth_token', '');
        if (!empty($token)) { $headers['Authorization'] = 'Bearer ' . $token; }
        $resp = wp_remote_post($endpoint, [
            'headers' => $headers,
            'timeout' => 30,
            'body'    => $payload,
        ]);
        if (is_wp_error($resp)) {
            error_log('[OAPFW] Feed push failed: ' . $resp->get_error_message());
        } else {
            error_log('[OAPFW] Feed push HTTP ' . wp_remote_retrieve_response_code($resp));
        }
    }
}
