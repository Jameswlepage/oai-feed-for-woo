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
        add_action('plugins_loaded', function () {
            require_once OAPFW_PLUGIN_DIR . 'includes/class-oapfw-settings.php';
            require_once OAPFW_PLUGIN_DIR . 'includes/class-oapfw-feed-generator.php';
            $this->settings = new OAPFW_Settings();
            $this->feed_generator = new OAPFW_Feed_Generator($this->settings);
        });

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
        } else {
            echo '<form method="post" action="options.php">';
            if ($this->settings) {
                settings_fields($this->settings->option_name());
                do_settings_sections($this->settings->option_name());
            }
            submit_button(__('Save Settings', 'openai-product-feed-for-woo'));
            echo '</form>';
        }

        echo '</div>';
    }
}
