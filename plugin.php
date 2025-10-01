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
        add_action("admin_menu", [$this, "register_admin_menu"]);

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
        }, 0);

        // REST preview (admin-only)
        add_action('rest_api_init', function () {
            register_rest_route('oapfw/v1', '/feed', [
                'methods' => 'GET',
                'permission_callback' => function () { return current_user_can('manage_woocommerce'); },
                'callback' => function () {
                    return rest_ensure_response($this->feed_generator ? $this->feed_generator->build_feed() : []);
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
        $tab = 'export';
        $tabs = [
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
        echo '<h2>' . esc_html__('OpenAI Product Feed', 'openai-product-feed-for-woo') . '</h2>';
        echo '<p class="description">' . esc_html__('Provide a structured product feed so ChatGPT can index your products with up-to-date price and availability. Choose a delivery format and (optionally) enable scheduled push to your allow-listed HTTPS endpoint.', 'openai-product-feed-for-woo') . '</p>';

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
        printf('<input type="text" class="small-text" name="oapfw_settings[enable_search_default]" value="%s" placeholder="true|false">', $get('enable_search_default','true'));
        echo '<p class="description">' . esc_html__('Controls whether products can appear in ChatGPT search results. Use lower-case “true” or “false”.', 'openai-product-feed-for-woo') . '</p>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('enable_checkout (default)', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="text" class="small-text" name="oapfw_settings[enable_checkout_default]" value="%s" placeholder="true|false">', $get('enable_checkout_default','false'));
        echo '<p class="description">' . esc_html__('Allows direct purchase inside ChatGPT. Requires enable_search=true and Instant Checkout approval.', 'openai-product-feed-for-woo') . '</p>';
        echo '</td></tr>';

        echo '</table>';

        // Merchant Info & Policies
        echo '<h3>' . esc_html__('Merchant Info & Policies', 'openai-product-feed-for-woo') . '</h3>';
        echo '<p class="description">' . esc_html__('These fields populate seller attribution and policy links in the feed. privacy/tos are required if checkout is enabled.', 'openai-product-feed-for-woo') . '</p>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Seller Name', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="text" class="regular-text" name="oapfw_settings[seller_name]" value="%s">', $get('seller_name',''));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Seller URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[seller_url]" value="%s" placeholder="https://example.com/store">', $get('seller_url',''));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Privacy Policy URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[privacy_url]" value="%s" placeholder="https://example.com/privacy">', $get('privacy_url',''));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Terms of Service URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[tos_url]" value="%s" placeholder="https://example.com/terms">', $get('tos_url',''));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Return Policy URL', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="url" class="regular-text" name="oapfw_settings[returns_url]" value="%s" placeholder="https://example.com/returns">', $get('returns_url',''));
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Return Window (days)', 'openai-product-feed-for-woo') . '</th><td>';
        printf('<input type="number" class="small-text" min="0" step="1" name="oapfw_settings[return_window]" value="%s">', esc_attr((int)$this->settings->get('return_window',0)));
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

    public function maybe_reschedule($old_value, $value, $option) {
        $enabled = isset($value['delivery_enabled']) && $value['delivery_enabled'] === 'true';
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($enabled && !$ts) {
            wp_schedule_event(time() + 60, 'every_fifteen_minutes', self::CRON_HOOK);
        } elseif (!$enabled && $ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
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
