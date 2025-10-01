<?php
if (!defined('ABSPATH')) { exit; }

class OAPFW_Validator {
    /**
     * Validate a single feed row and return list of issues (strings).
     */
    public static function validate_row(array $r): array {
        $issues = [];
        $req = function($k,$msg) use (&$issues,$r){ if (empty($r[$k]) && $r[$k] !== '0') { $issues[] = $msg; }};
        $req('id','Missing id');
        $req('title','Missing title');
        $req('description','Missing description');
        $req('link','Missing link');
        $req('image_link','Missing image_link');
        $req('price','Missing price');
        $req('availability','Missing availability');

        if (!empty($r['gtin']) && !preg_match('/^\d{8,14}$/', (string)$r['gtin'])) {
            $issues[] = 'gtin invalid (must be 8–14 digits)';
        }
        if (empty($r['gtin']) && empty($r['mpn'])) {
            $issues[] = 'mpn required if gtin missing';
        }
        if (!empty($r['sale_price']) && !empty($r['price'])) {
            $sp = floatval(self::num($r['sale_price']));
            $pr = floatval(self::num($r['price']));
            if ($sp > $pr) { $issues[] = 'sale_price must be <= price'; }
        }
        if (!empty($r['sale_price_effective_date']) && strpos($r['sale_price_effective_date'], '/') !== false) {
            [$start,$end] = array_map('trim', explode('/', $r['sale_price_effective_date']));
            if ($start && $end && $start > $end) { $issues[] = 'sale window start must precede end'; }
        }
        if (!empty($r['enable_checkout']) && $r['enable_checkout'] === 'true' && ($r['enable_search'] ?? '') !== 'true') {
            $issues[] = 'enable_checkout requires enable_search=true';
        }
        if (!empty($r['availability']) && !in_array($r['availability'], ['in_stock','out_of_stock','preorder'], true)) {
            $issues[] = 'availability must be in_stock|out_of_stock|preorder';
        }
        if (!empty($r['availability']) && $r['availability']==='preorder' && empty($r['availability_date'])) {
            $issues[] = 'availability_date required for preorder';
        }
        return $issues;
    }

    private static function num($str) {
        // Extract leading number from "12.34 USD"
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', (string)$str, $m)) return $m[1];
        return 0;
    }
}

