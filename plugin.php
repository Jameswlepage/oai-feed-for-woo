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
        echo '<div class="wrap">';
        echo "<h1>" .
            esc_html__(
                "OpenAI Product Feed for Woo",
                "openai-product-feed-for-woo",
            ) .
            "</h1>";
        echo "<p>" .
            esc_html__(
                "Welcome! Build your AI-optimized product feeds here.",
                "openai-product-feed-for-woo",
            ) .
            "</p>";
        echo "</div>";
    }
}
