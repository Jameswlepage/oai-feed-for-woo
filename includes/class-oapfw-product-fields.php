<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Adds extra fields to WooCommerce product editor for feed attributes.
 */
class OAPFW_Product_Fields {
    public static function init() {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_fields']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_fields']);
    }

    public static function render_fields() {
        echo '<div class="options_group">';

        // GTIN / MPN / Brand fallback
        woocommerce_wp_text_input([
            'id' => '_gtin',
            'label' => esc_html__('GTIN', 'openai-product-feed-for-woo'),
            'desc_tip' => true,
            'description' => esc_html__('8–14 digits; no spaces or dashes.', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_mpn',
            'label' => esc_html__('MPN', 'openai-product-feed-for-woo'),
            'desc_tip' => true,
            'description' => esc_html__('Required if GTIN is not provided.', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_brand',
            'label' => esc_html__('Brand (fallback)', 'openai-product-feed-for-woo'),
            'desc_tip' => true,
            'description' => esc_html__('Used if attribute pa_brand is not set.', 'openai-product-feed-for-woo'),
        ]);

        // Flags
        woocommerce_wp_checkbox([
            'id' => '_oapfw_enable_search',
            'label' => esc_html__('Enable search (ChatGPT)', 'openai-product-feed-for-woo'),
            'description' => esc_html__('Overrides global default for this product.', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_checkbox([
            'id' => '_oapfw_enable_checkout',
            'label' => esc_html__('Enable checkout (ChatGPT)', 'openai-product-feed-for-woo'),
            'description' => esc_html__('Requires enable search.', 'openai-product-feed-for-woo'),
        ]);

        // Compliance
        woocommerce_wp_text_input([
            'id' => '_oapfw_age_restriction',
            'label' => esc_html__('Age restriction', 'openai-product-feed-for-woo'),
            'type' => 'number',
            'custom_attributes' => ['min' => '0', 'step' => '1'],
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_warning',
            'label' => esc_html__('Warning text', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_warning_url',
            'label' => esc_html__('Warning URL', 'openai-product-feed-for-woo'),
            'placeholder' => 'https://',
        ]);

        // Media extras
        woocommerce_wp_text_input([
            'id' => '_oapfw_video_link',
            'label' => esc_html__('Product video URL', 'openai-product-feed-for-woo'),
            'placeholder' => 'https://',
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_model_3d_link',
            'label' => esc_html__('3D model URL', 'openai-product-feed-for-woo'),
            'placeholder' => 'https://',
        ]);

        // Q&A
        woocommerce_wp_textarea_input([
            'id' => '_oapfw_q_and_a',
            'label' => esc_html__('Q&A (plain text)', 'openai-product-feed-for-woo'),
            'rows' => 3,
        ]);

        echo '</div>';
    }

    public static function save_fields(WC_Product $product) {
        // Text fields
        $text_keys = ['_gtin','_mpn','_brand','_oapfw_warning','_oapfw_q_and_a'];
        foreach ($text_keys as $key) {
            if (isset($_POST[$key])) {
                $val = sanitize_text_field(wp_unslash($_POST[$key]));
                $product->update_meta_data($key, $val);
            }
        }
        // URLs
        $url_keys = ['_oapfw_warning_url','_oapfw_video_link','_oapfw_model_3d_link'];
        foreach ($url_keys as $key) {
            if (isset($_POST[$key])) {
                $val = esc_url_raw(wp_unslash($_POST[$key]));
                $product->update_meta_data($key, $val);
            }
        }
        // Numbers
        if (isset($_POST['_oapfw_age_restriction'])) {
            $product->update_meta_data('_oapfw_age_restriction', max(0, absint(wp_unslash($_POST['_oapfw_age_restriction']))));
        }
        // Checkboxes (store as 'true'/'')
        $product->update_meta_data('_oapfw_enable_search', isset($_POST['_oapfw_enable_search']) ? 'true' : '');
        $product->update_meta_data('_oapfw_enable_checkout', isset($_POST['_oapfw_enable_checkout']) ? 'true' : '');
    }
}

