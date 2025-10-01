<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Adds extra fields to WooCommerce product editor for feed attributes.
 */
class OAPFW_Product_Fields {
    public static function init() {
        // Use a dedicated product data tab/panel for clarity
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'add_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'render_panel']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_fields']);
    }

    public static function add_tab($tabs) {
        $tabs['oapfw'] = [
            'label'  => esc_html__('OpenAI Feed', 'openai-product-feed-for-woo'),
            'target' => 'oapfw_product_data',
            'class'  => ['show_if_simple', 'show_if_variable'],
            'priority' => 80,
        ];
        return $tabs;
    }

    public static function render_panel() {
        echo '<div id="oapfw_product_data" class="panel woocommerce_options_panel hidden">';
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

        // Item info
        woocommerce_wp_select([
            'id' => '_oapfw_condition',
            'label' => esc_html__('Condition', 'openai-product-feed-for-woo'),
            'options' => [
                '' => __('— Select —', 'openai-product-feed-for-woo'),
                'new' => 'new',
                'refurbished' => 'refurbished',
                'used' => 'used',
            ],
            'description' => esc_html__('Required if not new.', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_select([
            'id' => '_oapfw_age_group',
            'label' => esc_html__('Age group', 'openai-product-feed-for-woo'),
            'options' => [
                '' => __('— Optional —', 'openai-product-feed-for-woo'),
                'newborn' => 'newborn', 'infant' => 'infant', 'toddler' => 'toddler', 'kids' => 'kids', 'adult' => 'adult',
            ],
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

        // Pricing extras
        woocommerce_wp_text_input([
            'id' => '_oapfw_applicable_taxes_fees',
            'label' => esc_html__('Additional taxes/fees (e.g., 7 USD)', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_unit_pricing_measure',
            'label' => esc_html__('Unit pricing measure (e.g., 16 oz)', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_base_measure',
            'label' => esc_html__('Base measure (e.g., 1 oz)', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_pricing_trend',
            'label' => esc_html__('Pricing trend (short text)', 'openai-product-feed-for-woo'),
        ]);

        // Availability extras
        woocommerce_wp_text_input([
            'id' => '_oapfw_availability_date',
            'label' => esc_html__('Availability date (YYYY-MM-DD)', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_expiration_date',
            'label' => esc_html__('Expiration date (YYYY-MM-DD)', 'openai-product-feed-for-woo'),
        ]);

        // Performance & Geo
        woocommerce_wp_text_input([
            'id' => '_oapfw_popularity_score',
            'label' => esc_html__('Popularity score (0–5)', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_return_rate',
            'label' => esc_html__('Return rate (%)', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_geo_price',
            'label' => esc_html__('Geo price (e.g., 79.99 USD (CA))', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_oapfw_geo_availability',
            'label' => esc_html__('Geo availability (e.g., in_stock (TX), out_of_stock (NY))', 'openai-product-feed-for-woo'),
        ]);

        // Related
        woocommerce_wp_text_input([
            'id' => '_oapfw_related_product_id',
            'label' => esc_html__('Related product IDs (CSV)', 'openai-product-feed-for-woo'),
        ]);
        woocommerce_wp_select([
            'id' => '_oapfw_relationship_type',
            'label' => esc_html__('Relationship type', 'openai-product-feed-for-woo'),
            'options' => [
                '' => __('— Optional —', 'openai-product-feed-for-woo'),
                'part_of_set' => 'part_of_set',
                'required_part' => 'required_part',
                'often_bought_with' => 'often_bought_with',
                'substitute' => 'substitute',
                'different_brand' => 'different_brand',
                'accessory' => 'accessory',
            ],
        ]);

        echo '</div>';
        // Helpful actions/preview
        $rest = rest_url('oapfw/v1/feed');
        echo '<p style="margin: 8px 0;">' . esc_html__('Preview this product in the feed (admin-only):', 'openai-product-feed-for-woo') . ' ';
        echo '<a href="' . esc_url(add_query_arg(['product_id' => get_the_ID()], $rest)) . '" target="_blank">' . esc_html__('Open preview', 'openai-product-feed-for-woo') . '</a></p>';
        echo '</div>';
    }

    public static function save_fields(WC_Product $product) {
        // Text fields
        $text_keys = ['_gtin','_mpn','_brand','_oapfw_warning','_oapfw_q_and_a','_oapfw_condition','_oapfw_age_group','_oapfw_applicable_taxes_fees','_oapfw_unit_pricing_measure','_oapfw_base_measure','_oapfw_pricing_trend','_oapfw_availability_date','_oapfw_expiration_date','_oapfw_geo_price','_oapfw_geo_availability','_oapfw_related_product_id','_oapfw_relationship_type'];
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
