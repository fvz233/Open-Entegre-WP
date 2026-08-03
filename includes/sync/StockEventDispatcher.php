<?php

namespace MultiSync\Sync;

use MultiSync\Models\Supplier;
use MultiSync\Models\SyncLog;
use MultiSync\Models\SyncSettings;

if (!defined('ABSPATH')) {
    exit;
}

class StockEventDispatcher
{
    private const DEBOUNCE_SECONDS = 30;
    private static $initialized = false;
    private static $supplier_cache = array(
        'time' => 0,
        'ids' => array(),
    );

    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        add_action('woocommerce_new_order', array(__CLASS__, 'on_woocommerce_new_order'), 10, 1);
        add_action('woocommerce_product_set_stock', array(__CLASS__, 'on_product_set_stock'), 10, 1);
        add_action('woocommerce_variation_set_stock', array(__CLASS__, 'on_product_set_stock'), 10, 1);
        add_action('woocommerce_product_set_stock_status', array(__CLASS__, 'on_product_set_stock_status'), 10, 3);
        add_action('woocommerce_variation_set_stock_status', array(__CLASS__, 'on_product_set_stock_status'), 10, 3);
        add_action('multi_sync_order_imported', array(__CLASS__, 'on_order_imported'), 10, 3);
    }

    public static function on_woocommerce_new_order($order_id)
    {
        $skus = self::extract_skus_from_order_id($order_id);
        $supplier_id = self::extract_supplier_id_from_order_id($order_id);
        $preferred_suppliers = $supplier_id > 0 ? array($supplier_id) : array();
        self::enqueue_for_skus($skus, $preferred_suppliers);
    }

    public static function on_product_set_stock($product)
    {
        $sku = self::extract_sku_from_product($product);
        if ($sku === '') {
            return;
        }

        self::enqueue_for_skus(array($sku));
    }

    public static function on_product_set_stock_status($product_id, $stock_status = '', $product = null)
    {
        $sku = self::extract_sku_from_product($product);
        if ($sku === '' && $product_id) {
            $resolved_product = function_exists('wc_get_product') ? wc_get_product((int) $product_id) : null;
            $sku = self::extract_sku_from_product($resolved_product);
        }

        if ($sku === '') {
            return;
        }

        self::enqueue_for_skus(array($sku));
    }

    public static function on_order_imported($supplier_id, $order_id, $order_data = array())
    {
        $skus = self::extract_skus_from_order_data($order_data);
        if (empty($skus)) {
            $skus = self::extract_skus_from_order_id($order_id);
        }

        $preferred_suppliers = array();
        if ((int) $supplier_id > 0) {
            $preferred_suppliers[] = (int) $supplier_id;
        }

        self::enqueue_for_skus($skus, $preferred_suppliers);
    }

    private static function enqueue_for_skus($skus, $preferred_supplier_ids = array())
    {
        $normalized_skus = self::normalize_skus($skus);
        if (empty($normalized_skus)) {
            return;
        }

        $event_supplier_ids = self::get_event_driven_supplier_ids();
        if (empty($event_supplier_ids)) {
            return;
        }

        $preferred_supplier_ids = self::normalize_supplier_ids($preferred_supplier_ids);
        if (!empty($preferred_supplier_ids)) {
            $event_supplier_ids = array_values(array_intersect($event_supplier_ids, $preferred_supplier_ids));
            if (empty($event_supplier_ids)) {
                return;
            }
        }

        $supplier_sku_map = array();

        foreach ($normalized_skus as $sku) {
            $target_supplier_ids = !empty($preferred_supplier_ids)
                ? $event_supplier_ids
                : self::resolve_supplier_ids_for_sku($sku, $event_supplier_ids);
            if (empty($target_supplier_ids)) {
                continue;
            }

            foreach ($target_supplier_ids as $supplier_id) {
                if (!isset($supplier_sku_map[$supplier_id])) {
                    $supplier_sku_map[$supplier_id] = array();
                }
                $supplier_sku_map[$supplier_id][] = $sku;
            }
        }

        if (empty($supplier_sku_map)) {
            return;
        }

        foreach ($supplier_sku_map as $supplier_id => $supplier_skus) {
            $deduped_skus = self::apply_debounce((int) $supplier_id, $supplier_skus);
            if (empty($deduped_skus)) {
                continue;
            }

            $result = JobQueue::enqueue_stock_push_job_for_skus((int) $supplier_id, $deduped_skus, 'event');
            if (is_wp_error($result)) {
                self::log_event_error((int) $supplier_id, $result->get_error_message());
            }
        }
    }

    private static function get_event_driven_supplier_ids()
    {
        $now = time();
        if (
            isset(self::$supplier_cache['time'], self::$supplier_cache['ids']) &&
            is_array(self::$supplier_cache['ids']) &&
            ($now - (int) self::$supplier_cache['time']) < self::DEBOUNCE_SECONDS
        ) {
            return self::$supplier_cache['ids'];
        }

        $supplier_model = new Supplier();
        $settings_model = new SyncSettings();
        $suppliers = $supplier_model->get_all();

        $ids = array();
        if (is_array($suppliers)) {
            foreach ($suppliers as $supplier) {
                if (!$supplier || empty($supplier->active) || !isset($supplier->id)) {
                    continue;
                }

                $settings = $settings_model->get((int) $supplier->id);
                if (!$settings || empty($settings->sync_stock)) {
                    continue;
                }

                $mode = sanitize_key((string) (isset($settings->stock_automation_mode) ? $settings->stock_automation_mode : 'scheduled'));
                if ($mode !== 'event_driven') {
                    continue;
                }

                $ids[] = (int) $supplier->id;
            }
        }

        $ids = array_values(array_unique($ids));
        self::$supplier_cache = array(
            'time' => $now,
            'ids' => $ids,
        );

        return $ids;
    }

    private static function resolve_supplier_ids_for_sku($sku, $event_supplier_ids)
    {
        $sku = trim((string) $sku);
        if ($sku === '' || !is_array($event_supplier_ids) || empty($event_supplier_ids)) {
            return array();
        }

        $product_id = self::resolve_product_id_by_sku($sku);
        if ($product_id > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product && is_callable(array($product, 'get_meta'))) {
                $product_supplier_id = (int) $product->get_meta('_multi_sync_supplier_id', true);
                if ($product_supplier_id > 0) {
                    if (in_array($product_supplier_id, $event_supplier_ids, true)) {
                        return array($product_supplier_id);
                    }
                    return array();
                }
            }
        }

        return $event_supplier_ids;
    }

    private static function resolve_product_id_by_sku($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return 0;
        }

        if (function_exists('wc_get_product_id_by_sku')) {
            $product_id = (int) wc_get_product_id_by_sku($sku);
            if ($product_id > 0) {
                return $product_id;
            }
        }

        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }

        $meta_table = $wpdb->postmeta;
        $posts_table = $wpdb->posts;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id
             FROM {$meta_table} pm
             INNER JOIN {$posts_table} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
               AND LOWER(pm.meta_value) = LOWER(%s)
               AND p.post_type IN ('product', 'product_variation')
             LIMIT 1",
            $sku
        ));
    }

    private static function apply_debounce($supplier_id, $skus)
    {
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return array();
        }

        $normalized = self::normalize_skus($skus);
        if (empty($normalized)) {
            return array();
        }

        $allowed = array();
        foreach ($normalized as $sku) {
            $transient_key = self::get_debounce_key($supplier_id, $sku);
            if (get_transient($transient_key) !== false) {
                continue;
            }

            set_transient($transient_key, 1, self::DEBOUNCE_SECONDS);
            $allowed[] = $sku;
        }

        return $allowed;
    }

    private static function get_debounce_key($supplier_id, $sku)
    {
        $normalized_sku = function_exists('mb_strtolower')
            ? mb_strtolower((string) $sku, 'UTF-8')
            : strtolower((string) $sku);
        return 'multi_sync_event_stock_push_' . (int) $supplier_id . '_' . md5($normalized_sku);
    }

    private static function extract_sku_from_product($product)
    {
        if (!$product || !is_object($product) || !is_callable(array($product, 'get_sku'))) {
            return '';
        }

        return trim((string) $product->get_sku());
    }

    private static function extract_skus_from_order_id($order_id)
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return array();
        }

        $order = wc_get_order($order_id);
        if (!$order || !is_callable(array($order, 'get_items'))) {
            return array();
        }

        $skus = array();
        foreach ($order->get_items() as $item) {
            if (!$item) {
                continue;
            }

            $product = is_callable(array($item, 'get_product')) ? $item->get_product() : null;
            $sku = self::extract_sku_from_product($product);

            if ($sku === '' && is_callable(array($item, 'get_product_id'))) {
                $product_id = (int) $item->get_product_id();
                if ($product_id > 0 && function_exists('wc_get_product')) {
                    $sku = self::extract_sku_from_product(wc_get_product($product_id));
                }
            }

            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        return self::normalize_skus($skus);
    }

    private static function extract_supplier_id_from_order_id($order_id)
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return 0;
        }

        $order = wc_get_order($order_id);
        if (!$order || !is_callable(array($order, 'get_meta'))) {
            return 0;
        }

        return (int) $order->get_meta('_multi_sync_supplier_id', true);
    }

    private static function extract_skus_from_order_data($order_data)
    {
        if (!is_array($order_data) || !isset($order_data['line_items']) || !is_array($order_data['line_items'])) {
            return array();
        }

        $skus = array();
        foreach ($order_data['line_items'] as $line_item) {
            if (!is_array($line_item)) {
                continue;
            }

            if (!isset($line_item['sku'])) {
                continue;
            }

            $sku = trim((string) $line_item['sku']);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        return self::normalize_skus($skus);
    }

    private static function normalize_skus($skus)
    {
        $normalized = array();
        $seen = array();

        if (!is_array($skus)) {
            return array();
        }

        foreach ($skus as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $sku = trim((string) $value);
            if ($sku === '') {
                continue;
            }

            $key = function_exists('mb_strtolower') ? mb_strtolower($sku, 'UTF-8') : strtolower($sku);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $sku;
        }

        return $normalized;
    }

    private static function normalize_supplier_ids($supplier_ids)
    {
        $normalized = array();
        if (!is_array($supplier_ids)) {
            return $normalized;
        }

        foreach ($supplier_ids as $supplier_id) {
            $value = (int) $supplier_id;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function log_event_error($supplier_id, $message)
    {
        $supplier_id = (int) $supplier_id;
        $text = '[event] Stok push kuyruga eklenemedi: ' . (string) $message;

        if (function_exists('multi_sync_debug_log')) {
            multi_sync_debug_log($text . ' (supplier_id=' . $supplier_id . ')');
        }

        $log_model = new SyncLog();
        $log_model->log($supplier_id, 'stock_push', 'error', $text);
    }
}
