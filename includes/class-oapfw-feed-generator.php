<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Feed generator for OpenAI Product Feed (spec-aligned, minimal subset).
 *
 * Keeps logic light: build on demand, serialize to chosen format.
 */
class OAPFW_Feed_Generator {
    /** @var OAPFW_Settings */
    private $settings;

    public function __construct(OAPFW_Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Build the feed as an array of associative arrays (rows).
     */
    public function build_feed(): array {
        if (!class_exists('WC_Product')) { return []; }

        $args = [
            'status' => ['publish'],
            'limit'  => -1,
            'type'   => ['simple', 'variable', 'variation'],
            'return' => 'objects',
        ];
        $products = wc_get_products($args);
        $rows = [];
        foreach ($products as $product) {
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $vid) {
                    $rows[] = $this->map_product(wc_get_product($vid), $product);
                }
            } elseif ($product->is_type('variation')) {
                $rows[] = $this->map_product($product, wc_get_product($product->get_parent_id()));
            } else {
                $rows[] = $this->map_product($product, null);
            }
        }
        // Drop null/empty values for cleanliness
        $rows = array_map(function ($r) { return array_filter($r, fn($v) => $v !== null && $v !== ''); }, $rows);
        return apply_filters('oapfw_feed_rows', $rows);
    }

    private function map_product(WC_Product $p, ?WC_Product $parent = null): array {
        $currency = get_woocommerce_currency();
        $sku = $p->get_sku() ?: 'wc-' . $p->get_id();
        $image_id = $p->get_image_id() ?: ($parent ? $parent->get_image_id() : 0);
        $main_img = $image_id ? wp_get_attachment_url($image_id) : '';
        $gallery_ids = $p->get_gallery_image_ids();
        if (empty($gallery_ids) && $parent) { $gallery_ids = $parent->get_gallery_image_ids(); }
        $gallery_urls = array_filter(array_map('wp_get_attachment_url', $gallery_ids));

        $regular = $p->get_regular_price();
        $sale    = $p->get_sale_price();
        $sale_from = $p->get_date_on_sale_from();
        $sale_to   = $p->get_date_on_sale_to();

        $stock_status = $p->get_stock_status(); // instock | outofstock | onbackorder
        $availability = ($stock_status === 'instock') ? 'in_stock' : (($stock_status === 'outofstock') ? 'out_of_stock' : 'preorder');

        $brand = $p->get_attribute('pa_brand');
        if (!$brand && $parent) { $brand = $parent->get_attribute('pa_brand'); }

        $gtin = get_post_meta($p->get_id(), '_gtin', true);
        $mpn  = get_post_meta($p->get_id(), '_mpn', true);
        $brand_meta = get_post_meta($p->get_id(), '_brand', true);

        $settings = [
            'enable_search_default' => $this->settings->get('enable_search_default', 'true'),
            'enable_checkout_default' => $this->settings->get('enable_checkout_default', 'false'),
            'seller_name' => $this->settings->get('seller_name', ''),
            'seller_url' => $this->settings->get('seller_url', ''),
            'privacy_url' => $this->settings->get('privacy_url', ''),
            'tos_url' => $this->settings->get('tos_url', ''),
            'returns_url' => $this->settings->get('returns_url', ''),
            'return_window' => (int) $this->settings->get('return_window', 0),
        ];

        $row = [
            // OpenAI flags
            'enable_search'   => $settings['enable_search_default'],
            'enable_checkout' => $settings['enable_checkout_default'],

            // Basic Product Data
            'id'          => $sku,
            'gtin'        => $gtin ?: null,
            'mpn'         => $gtin ? null : ($mpn ?: null),
            'title'       => wp_strip_all_tags($p->get_name()),
            'description' => $this->truncate_plain(($p->get_description() ?: $p->get_short_description()), 5000),
            'link'        => get_permalink($p->get_id()),

            // Item Information
            'product_category' => $this->category_path($p),
            'brand'            => ($brand ?: $brand_meta) ?: null,
            'material'         => $p->get_attribute('pa_material') ?: null,
            'weight'           => $p->get_weight() ? $p->get_weight() . ' ' . get_option('woocommerce_weight_unit') : null,
            'length'           => $p->get_length() ? $p->get_length() . ' ' . get_option('woocommerce_dimension_unit') : null,
            'width'            => $p->get_width() ? $p->get_width() . ' ' . get_option('woocommerce_dimension_unit') : null,
            'height'           => $p->get_height() ? $p->get_height() . ' ' . get_option('woocommerce_dimension_unit') : null,

            // Media
            'image_link'            => $main_img,
            'additional_image_link' => $gallery_urls,

            // Media extras
            'video_link'       => esc_url_raw((string) get_post_meta($p->get_id(), '_oapfw_video_link', true)) ?: null,
            'model_3d_link'    => esc_url_raw((string) get_post_meta($p->get_id(), '_oapfw_model_3d_link', true)) ?: null,

            // Price & Promotions
            'price'  => $regular ? sprintf('%s %s', $regular, $currency) : null,
            'sale_price' => $sale ? sprintf('%s %s', $sale, $currency) : null,
            'sale_price_effective_date' => ($sale && $sale_from && $sale_to)
                ? $sale_from->date_i18n('Y-m-d') . ' / ' . $sale_to->date_i18n('Y-m-d')
                : null,

            // Availability & Inventory
            'availability'        => $availability,
            'inventory_quantity'  => $p->get_stock_quantity() ?? 0,

            // Variants
            'item_group_id'    => $parent ? ($parent->get_sku() ?: 'wc-' . $parent->get_id()) : null,
            'item_group_title' => $parent ? wp_strip_all_tags($parent->get_name()) : null,
            'color'            => $p->get_attribute('pa_color') ?: null,
            'size'             => $p->get_attribute('pa_size') ?: null,
            'size_system'      => $p->get_attribute('pa_size_system') ?: null,
            'gender'           => $p->get_attribute('pa_gender') ?: null,

            // Merchant Info & Returns
            'seller_name'           => $settings['seller_name'] ?: null,
            'seller_url'            => $settings['seller_url'] ?: null,
            'seller_privacy_policy' => $settings['privacy_url'] ?: null,
            'seller_tos'            => $settings['tos_url'] ?: null,
            'return_policy'         => $settings['returns_url'] ?: null,
            'return_window'         => $settings['return_window'] ?: null,

            // Compliance
            'warning'               => ($w = get_post_meta($p->get_id(), '_oapfw_warning', true)) ? wp_strip_all_tags($w) : null,
            'warning_url'           => esc_url_raw((string) get_post_meta($p->get_id(), '_oapfw_warning_url', true)) ?: null,
            'age_restriction'       => ($ar = absint((string) get_post_meta($p->get_id(), '_oapfw_age_restriction', true))) ? $ar : null,

            // Q&A
            'q_and_a'               => ($qa = get_post_meta($p->get_id(), '_oapfw_q_and_a', true)) ? wp_strip_all_tags($qa) : null,
        ];

        // Per-product flag overrides
        $override_search = get_post_meta($p->get_id(), '_oapfw_enable_search', true);
        $override_checkout = get_post_meta($p->get_id(), '_oapfw_enable_checkout', true);
        if ($override_search !== '') { $row['enable_search'] = $this->bool_string($override_search); }
        if ($override_checkout !== '') { $row['enable_checkout'] = $this->bool_string($override_checkout); }

        $row = $this->validate_row($row);
        return apply_filters('oapfw_map_product', $row, $p, $parent, $settings);
    }

    private function category_path(WC_Product $p): ?string {
        $terms = get_the_terms($p->get_id(), 'product_cat');
        if (!$terms || is_wp_error($terms)) { return null; }
        // Shallow approach: choose deepest by parent chain length
        $term = array_reduce($terms, function ($carry, $item) {
            $depth = 0; $tmp = $item; while ($tmp && $tmp->parent) { $tmp = get_term($tmp->parent, 'product_cat'); $depth++; if (is_wp_error($tmp)) break; }
            $carry_depth = 0; $tmp2 = $carry; while ($tmp2 && $tmp2->parent) { $tmp2 = get_term($tmp2->parent, 'product_cat'); $carry_depth++; if (is_wp_error($tmp2)) break; }
            return ($carry === null || $depth >= $carry_depth) ? $item : $carry;
        }, null);
        if (!$term) { return null; }
        $path = [$term->name];
        while ($term->parent) {
            $term = get_term($term->parent, 'product_cat');
            if (is_wp_error($term)) { break; }
            array_unshift($path, $term->name);
        }
        return implode(' > ', $path);
    }

    private function truncate_plain(string $html, int $max): string {
        $txt = wp_strip_all_tags($html);
        if (mb_strlen($txt) > $max) {
            $txt = mb_substr($txt, 0, $max);
        }
        return $txt;
    }

    private function validate_row(array $row): array {
        // enforce strings 'true'|'false' and dependency: checkout requires search
        $row['enable_search'] = strtolower((string) ($row['enable_search'] ?? 'true')) === 'true' ? 'true' : 'false';
        $row['enable_checkout'] = strtolower((string) ($row['enable_checkout'] ?? 'false')) === 'true' ? 'true' : 'false';
        if ($row['enable_checkout'] === 'true' && $row['enable_search'] !== 'true') {
            $row['enable_checkout'] = 'false';
        }
        // title/description limits
        if (!empty($row['title'])) { $row['title'] = mb_substr($row['title'], 0, 150); }
        if (!empty($row['description'])) { $row['description'] = mb_substr($row['description'], 0, 5000); }
        // mpn required if gtin missing
        if (empty($row['gtin']) && empty($row['mpn'])) { $row['mpn'] = 'N/A'; }
        return $row;
    }

    private function bool_string($v): string {
        $v = strtolower((string) $v);
        return ($v === 'true' || $v === '1' || $v === 'yes') ? 'true' : 'false';
    }

    /**
     * Serialize feed rows to the desired format (json,csv,xml,tsv).
     * Returns string payload and sets $content_type by reference.
     */
    public function serialize(array $rows, string $format, ?string &$content_type = null): string {
        $format = strtolower($format);
        switch ($format) {
            case 'csv':
                $content_type = 'text/csv';
                return $this->to_csv($rows);
            case 'xml':
                $content_type = 'application/xml';
                return $this->to_xml($rows);
            case 'tsv':
                $content_type = 'text/tab-separated-values';
                return $this->to_tsv($rows);
            default:
                $content_type = 'application/json';
                return wp_json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    private function to_csv(array $rows): string {
        if (!$rows) { return ''; }
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, array_keys($rows[0]));
        foreach ($rows as $r) { fputcsv($fh, $this->stringify_values($r)); }
        rewind($fh); return stream_get_contents($fh);
    }

    private function to_tsv(array $rows): string {
        if (!$rows) { return ''; }
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, implode("\t", array_keys($rows[0])) . "\n");
        foreach ($rows as $r) { fwrite($fh, implode("\t", $this->stringify_values($r)) . "\n"); }
        rewind($fh); return stream_get_contents($fh);
    }

    private function to_xml(array $rows): string {
        $xml = new SimpleXMLElement('<products/>');
        foreach ($rows as $r) {
            $item = $xml->addChild('product');
            foreach ($r as $k => $v) {
                if (is_array($v)) { $v = implode(',', $v); }
                $item->addChild($k, htmlspecialchars((string) $v));
            }
        }
        return $xml->asXML();
    }

    private function stringify_values(array $r): array {
        foreach ($r as $k => $v) {
            if (is_array($v)) { $r[$k] = implode(',', $v); }
        }
        return array_values($r);
    }
}
