<?php

namespace MultiSync\Sync;

use MultiSync\Marketplaces\MarketplaceManager;
use MultiSync\Models\Supplier;
use MultiSync\Models\SyncLog;

if (!defined('ABSPATH')) {
    exit;
}

class StockSync
{

    public static function sync_single_product($product)
    {
        if (!$product)
            return;

        $supplier_model = new Supplier();
        $suppliers = $supplier_model->get_all();

        $product_supplier_id = (int) $product->get_meta('_multi_sync_supplier_id', true);

        foreach ($suppliers as $supplier) {
            if (!$supplier->active)
                continue;

            if ($product_supplier_id > 0 && (int) $supplier->id !== $product_supplier_id) {
                continue;
            }

            $settings = self::get_sync_settings($supplier->id);
            if (!$settings || (!$settings->sync_stock && !$settings->sync_price)) {
                continue;
            }

            self::push_single_product_update($supplier, $product, (bool) $settings->sync_stock, (bool) $settings->sync_price);
        }
    }

    public static function run_for_supplier(
        $supplier_id,
        $product_ids = array(),
        $product_skus = array(),
        $stock_mode = 'marketplace_match',
        $runtime_sync = array(),
        $options = array()
    )
    {
        $supplier_model = new Supplier();
        $supplier = $supplier_model->get($supplier_id);
        if (!$supplier || !$supplier->active) {
            return new \WP_Error('multi_sync_invalid_supplier', 'Satici bulunamadi veya pasif.');
        }

        $settings = self::get_sync_settings($supplier_id);

        $runtime_sync_stock = is_array($runtime_sync) && array_key_exists('sync_stock', $runtime_sync)
            ? (bool) $runtime_sync['sync_stock']
            : null;
        $runtime_sync_price = is_array($runtime_sync) && array_key_exists('sync_price', $runtime_sync)
            ? (bool) $runtime_sync['sync_price']
            : null;

        // If settings row does not exist yet, manual run should still work with sane defaults.
        $sync_stock = $runtime_sync_stock !== null
            ? $runtime_sync_stock
            : ($settings ? (bool) $settings->sync_stock : true);
        $sync_price = $runtime_sync_price !== null
            ? $runtime_sync_price
            : ($settings ? (bool) $settings->sync_price : false);
        $stock_mode = self::sanitize_stock_mode($stock_mode);
        $log_enabled = true;
        if (is_array($options) && array_key_exists('log_enabled', $options)) {
            $log_enabled = (bool) $options['log_enabled'];
        }

        if (!$sync_stock && !$sync_price) {
            return new \WP_Error('multi_sync_sync_disabled', 'Stok ve fiyat senkronu kapali.');
        }

        $manager = new MarketplaceManager();
        $adapter = $manager->for_supplier($supplier);
        if (!$adapter) {
            return new \WP_Error('multi_sync_marketplace_not_found', 'Pazar yeri adaptoru bulunamadi.');
        }

        if ($stock_mode === 'marketplace_match') {
            if (!$sync_stock) {
                return new \WP_Error(
                    'multi_sync_stock_sync_disabled',
                    'Bu modda stok gonderimi icin "Stok Senkronizasyonu" acik olmalidir.'
                );
            }

            return self::run_marketplace_match_mode(
                $supplier_id,
                $supplier,
                $adapter,
                $product_ids,
                $product_skus,
                array(
                    'log_enabled' => $log_enabled,
                )
            );
        }

        if (!empty($product_skus)) {
            $resolved_ids = self::resolve_product_ids_by_skus($product_skus);
            if (!empty($resolved_ids)) {
                $product_ids = array_values(array_unique(array_merge($product_ids, $resolved_ids)));
            } else {
                $product_ids = array();
            }
        }

        $products = self::get_products_for_push($product_ids);
        if (empty($products)) {
            return array(
                'processed' => 0,
                'sent' => 0,
                'batch_request_ids' => array(),
            );
        }

        $items = array();
        foreach ($products as $product) {
            $payload_item = $adapter->build_price_inventory_item_from_product($product, $sync_stock, $sync_price);
            if ($payload_item !== null) {
                $items[] = $payload_item;
            }
        }

        if (empty($items)) {
            return array(
                'processed' => count($products),
                'sent' => 0,
                'batch_request_ids' => array(),
            );
        }

        $log_model = $log_enabled ? new SyncLog() : null;
        $batch_request_ids = array();
        $sent = 0;
        $errors = array();
        $chunks = self::build_push_chunks($items, $supplier);

        foreach ($chunks as $chunk) {
            $response = $adapter->push_price_inventory_updates($supplier, $chunk);
            if (is_wp_error($response)) {
                $errors[] = $response->get_error_message();
                if ($log_model) {
                    $log_model->log($supplier_id, 'stock_push', 'error', $response->get_error_message());
                }
                continue;
            }

            $sent += count($chunk);
            $chunk_batch_ids = self::extract_batch_ids($response);
            if (!empty($chunk_batch_ids)) {
                $batch_request_ids = array_merge($batch_request_ids, $chunk_batch_ids);
            }
        }

        $batch_request_ids = array_values(array_unique($batch_request_ids));

        if ($log_model) {
            $log_model->log(
                $supplier_id,
                'stock_push',
                'success',
                sprintf('Price/stock push completed. Sent: %d, Batches: %d', $sent, count($batch_request_ids))
            );
        }

        return array(
            'processed' => count($products),
            'sent' => $sent,
            'batch_request_ids' => $batch_request_ids,
            'errors' => $errors,
        );
    }

    public static function preview_for_supplier(
        $supplier_id,
        $product_ids = array(),
        $product_skus = array(),
        $stock_mode = 'marketplace_match',
        $runtime_sync = array(),
        $options = array()
    )
    {
        $supplier_model = new Supplier();
        $supplier = $supplier_model->get($supplier_id);
        if (!$supplier || !$supplier->active) {
            return new \WP_Error('multi_sync_invalid_supplier', 'Satici bulunamadi veya pasif.');
        }

        $settings = self::get_sync_settings($supplier_id);

        $runtime_sync_stock = is_array($runtime_sync) && array_key_exists('sync_stock', $runtime_sync)
            ? (bool) $runtime_sync['sync_stock']
            : null;
        $runtime_sync_price = is_array($runtime_sync) && array_key_exists('sync_price', $runtime_sync)
            ? (bool) $runtime_sync['sync_price']
            : null;

        $sync_stock = $runtime_sync_stock !== null
            ? $runtime_sync_stock
            : ($settings ? (bool) $settings->sync_stock : true);
        $sync_price = $runtime_sync_price !== null
            ? $runtime_sync_price
            : ($settings ? (bool) $settings->sync_price : false);
        $stock_mode = self::sanitize_stock_mode($stock_mode);

        if (!$sync_stock && !$sync_price) {
            return new \WP_Error('multi_sync_sync_disabled', 'Stok ve fiyat senkronu kapali.');
        }

        $manager = new MarketplaceManager();
        $adapter = $manager->for_supplier($supplier);
        if (!$adapter) {
            return new \WP_Error('multi_sync_marketplace_not_found', 'Pazar yeri adaptoru bulunamadi.');
        }

        $log_warnings = true;
        if (is_array($options) && array_key_exists('log_warnings', $options)) {
            $log_warnings = (bool) $options['log_warnings'];
        }

        if ($stock_mode === 'marketplace_match') {
            if (!$sync_stock) {
                return new \WP_Error(
                    'multi_sync_stock_sync_disabled',
                    'Bu modda stok gonderimi icin "Stok Senkronizasyonu" acik olmalidir.'
                );
            }

            return self::build_marketplace_match_preview(
                $supplier_id,
                $supplier,
                $adapter,
                $product_ids,
                $product_skus,
                $log_warnings
            );
        }

        return self::build_direct_preview(
            $supplier_id,
            $supplier,
            $adapter,
            $product_ids,
            $product_skus,
            $sync_stock,
            $sync_price,
            $log_warnings
        );
    }

    private static function build_marketplace_match_preview($supplier_id, $supplier, $adapter, $product_ids = array(), $product_skus = array(), $log_warnings = true)
    {
        $sku_map_result = self::fetch_marketplace_products_by_sku($supplier_id, $supplier, $adapter, $log_warnings);
        if (is_wp_error($sku_map_result)) {
            return $sku_map_result;
        }

        $requested_sku_keys = self::collect_requested_sku_keys($product_ids, $product_skus);
        if (!empty($requested_sku_keys)) {
            $sku_map_result = array_intersect_key($sku_map_result, array_flip($requested_sku_keys));
        }

        if (empty($sku_map_result)) {
            return array(
                'mode' => 'marketplace_match',
                'sync_stock' => true,
                'sync_price' => false,
                'items' => array(),
                'summary' => array(
                    'total' => 0,
                    'updatable' => 0,
                    'not_updatable' => 0,
                    'marketplace_products' => 0,
                    'matched_products' => 0,
                ),
            );
        }

        $resolved_ids = self::resolve_product_ids_by_skus(array_keys($sku_map_result));
        $products = self::get_products_for_push($resolved_ids);
        $woo_products_by_sku_key = self::index_products_by_sku($products);

        $marketplace_products = self::group_marketplace_product_aliases($sku_map_result, $woo_products_by_sku_key);
        $items = array();
        $updatable = 0;

        foreach ($marketplace_products as $marketplace_entry) {
            $sku_key = $marketplace_entry['selection_key'];
            $marketplace_product = $marketplace_entry['product'];
            $sku = isset($marketplace_product['sku']) ? trim((string) $marketplace_product['sku']) : '';
            if ($sku === '') {
                $sku = (string) $sku_key;
            }

            $woo_product = $marketplace_entry['woo_product'];
            $can_push = $woo_product !== null;

            if ($can_push) {
                $updatable++;
            }

            $before_stock = self::numeric_or_null(
                isset($marketplace_product['stock_quantity']) ? $marketplace_product['stock_quantity'] : null,
                0
            );
            $before_price = self::numeric_or_null(
                isset($marketplace_product['regular_price']) ? $marketplace_product['regular_price'] : null,
                2
            );
            $before_discount_price = self::numeric_or_null(
                isset($marketplace_product['discounted_price'])
                    ? $marketplace_product['discounted_price']
                    : (isset($marketplace_product['regular_price']) ? $marketplace_product['regular_price'] : null),
                2
            );

            $after_stock = $can_push ? self::get_product_stock_quantity($woo_product) : null;

            $name = '';
            if ($can_push && is_callable(array($woo_product, 'get_name'))) {
                $name = (string) $woo_product->get_name();
            }
            if ($name === '') {
                $name = isset($marketplace_product['name']) ? (string) $marketplace_product['name'] : $sku;
            }

            $items[] = array(
                'sku' => $sku,
                'selection_key' => $sku_key,
                'name' => $name,
                'preview_image' => isset($marketplace_product['preview_image']) ? (string) $marketplace_product['preview_image'] : '',
                'before_stock' => $before_stock,
                'after_stock' => $after_stock,
                'before_price' => $before_price,
                'after_price' => null,
                'before_discount_price' => $before_discount_price,
                'after_discount_price' => null,
                'will_update_stock' => true,
                'will_update_price' => false,
                'will_update_discount_price' => false,
                'can_push' => $can_push,
                'status_text' => $can_push ? 'Guncellenecek' : 'Woo urunu bulunamadi',
            );
        }

        usort($items, array(__CLASS__, 'compare_preview_items'));

        return array(
            'mode' => 'marketplace_match',
            'sync_stock' => true,
            'sync_price' => false,
            'items' => $items,
            'summary' => array(
                'total' => count($items),
                'updatable' => $updatable,
                'not_updatable' => max(0, count($items) - $updatable),
                'marketplace_products' => count($marketplace_products),
                'matched_products' => $updatable,
            ),
        );
    }

    private static function group_marketplace_product_aliases($products_by_alias, $woo_products_by_sku_key = array())
    {
        $grouped = array();

        foreach ($products_by_alias as $alias => $product) {
            if (!is_array($product)) {
                continue;
            }

            $identity = md5(serialize($product));
            if (!isset($grouped[$identity])) {
                $grouped[$identity] = array(
                    'selection_key' => (string) $alias,
                    'product' => $product,
                    'woo_product' => null,
                );
            }

            if (isset($woo_products_by_sku_key[$alias])) {
                $grouped[$identity]['selection_key'] = (string) $alias;
                $grouped[$identity]['woo_product'] = $woo_products_by_sku_key[$alias];
            }
        }

        return array_values($grouped);
    }

    private static function build_direct_preview(
        $supplier_id,
        $supplier,
        $adapter,
        $product_ids = array(),
        $product_skus = array(),
        $sync_stock = true,
        $sync_price = false,
        $log_warnings = true
    )
    {
        $marketplace_key = '';
        if (is_object($supplier) && !empty($supplier->marketplace_key)) {
            $marketplace_key = sanitize_key((string) $supplier->marketplace_key);
        }
        $supports_discount_price = $marketplace_key !== 'pttavm';

        $marketplace_sku_map = self::fetch_marketplace_products_by_sku($supplier_id, $supplier, $adapter, $log_warnings);
        if (is_wp_error($marketplace_sku_map)) {
            return $marketplace_sku_map;
        }
        if (!is_array($marketplace_sku_map)) {
            $marketplace_sku_map = array();
        }

        $requested_sku_keys = self::collect_requested_sku_keys($product_ids, $product_skus);
        if (!empty($requested_sku_keys)) {
            $marketplace_sku_map = array_intersect_key($marketplace_sku_map, array_flip($requested_sku_keys));
        }

        $resolved_ids = self::resolve_product_ids_by_skus(array_keys($marketplace_sku_map));
        $products = self::get_products_for_push($resolved_ids);
        $woo_products_by_sku_key = self::index_products_by_sku($products);

        $marketplace_products = self::group_marketplace_product_aliases($marketplace_sku_map, $woo_products_by_sku_key);
        $items = array();
        $updatable = 0;

        foreach ($marketplace_products as $marketplace_entry) {
            $sku_key = $marketplace_entry['selection_key'];
            $marketplace_product = $marketplace_entry['product'];
            $product = $marketplace_entry['woo_product'];
            $can_push = $product !== null;
            $sku = isset($marketplace_product['sku']) ? trim((string) $marketplace_product['sku']) : '';
            if ($sku === '') {
                $sku = (string) $sku_key;
            }
            if ($can_push) {
                $updatable++;
            }

            $before_stock = self::numeric_or_null(isset($marketplace_product['stock_quantity']) ? $marketplace_product['stock_quantity'] : null, 0);
            $before_price = self::numeric_or_null(isset($marketplace_product['regular_price']) ? $marketplace_product['regular_price'] : null, 2);
            $before_discount_price = self::numeric_or_null(
                isset($marketplace_product['discounted_price'])
                    ? $marketplace_product['discounted_price']
                    : (isset($marketplace_product['sale_price'])
                        ? $marketplace_product['sale_price']
                        : (isset($marketplace_product['regular_price']) ? $marketplace_product['regular_price'] : null)),
                2
            );

            $after_stock = ($can_push && $sync_stock) ? self::get_product_stock_quantity($product) : null;
            $after_price = ($can_push && $sync_price) ? self::get_product_regular_price($product) : null;
            $after_discount_price = ($can_push && $sync_price && $supports_discount_price)
                ? self::get_product_discount_price($product)
                : null;
            if ($can_push && $sync_price && is_callable(array($adapter, 'build_price_inventory_item_from_product'))) {
                $price_item = $adapter->build_price_inventory_item_from_product($product, false, true);
                if (is_array($price_item)) {
                    $list_price = self::numeric_or_null(isset($price_item['listPrice']) ? $price_item['listPrice'] : (isset($price_item['price']) ? $price_item['price'] : null), 2);
                    if ($list_price !== null) {
                        $after_price = $list_price;
                    }
                    $sale_price = self::numeric_or_null(isset($price_item['salePrice']) ? $price_item['salePrice'] : (isset($price_item['salesPrice']) ? $price_item['salesPrice'] : (isset($price_item['sale_price']) ? $price_item['sale_price'] : null)), 2);
                    $after_discount_price = $sale_price !== null && $after_price !== null && $sale_price < $after_price
                        ? $sale_price
                        : null;
                }
            }
            $will_update_discount_price = $sync_price
                && $supports_discount_price
                && ($before_discount_price !== null || $after_discount_price !== null);

            $name = '';
            if ($can_push && is_callable(array($product, 'get_name'))) {
                $name = (string) $product->get_name();
            }
            if ($name === '') {
                $name = isset($marketplace_product['name']) ? (string) $marketplace_product['name'] : $sku;
            }

            $items[] = array(
                'sku' => $sku,
                'selection_key' => $sku_key,
                'name' => $name,
                'preview_image' => isset($marketplace_product['preview_image']) ? (string) $marketplace_product['preview_image'] : '',
                'before_stock' => $before_stock,
                'after_stock' => $after_stock,
                'before_price' => $before_price,
                'after_price' => $after_price,
                'before_discount_price' => $before_discount_price,
                'after_discount_price' => $after_discount_price,
                'will_update_stock' => (bool) $sync_stock,
                'will_update_price' => (bool) $sync_price,
                'will_update_discount_price' => $will_update_discount_price,
                'can_push' => $can_push,
                'status_text' => $can_push ? 'Hazir' : 'Woo urunu bulunamadi',
            );
        }

        usort($items, array(__CLASS__, 'compare_preview_items'));

        $result = array(
            'mode' => 'direct',
            'sync_stock' => (bool) $sync_stock,
            'sync_price' => (bool) $sync_price,
            'items' => $items,
            'summary' => array(
                'total' => count($items),
                'updatable' => $updatable,
                'not_updatable' => max(0, count($items) - $updatable),
                'marketplace_missing' => 0,
            ),
        );

        return $result;
    }

    private static function collect_requested_sku_keys($product_ids = array(), $product_skus = array())
    {
        $requested_sku_keys = array();

        if (!empty($product_skus)) {
            foreach ($product_skus as $sku) {
                $normalized = self::normalize_sku_key($sku);
                if ($normalized !== '') {
                    $requested_sku_keys[] = $normalized;
                }
            }
        }

        if (!empty($product_ids)) {
            $requested_sku_keys = array_merge(
                $requested_sku_keys,
                self::collect_sku_keys_from_product_ids($product_ids)
            );
        }

        return array_values(array_unique($requested_sku_keys));
    }

    private static function index_products_by_sku($products = array())
    {
        $indexed = array();
        if (!is_array($products)) {
            return $indexed;
        }

        foreach ($products as $product) {
            if (!$product || !is_callable(array($product, 'get_sku'))) {
                continue;
            }

            $sku_key = self::normalize_sku_key($product->get_sku());
            if ($sku_key === '' || isset($indexed[$sku_key])) {
                continue;
            }

            $indexed[$sku_key] = $product;
        }

        return $indexed;
    }

    private static function get_product_stock_quantity($product)
    {
        if (!$product || !is_callable(array($product, 'get_stock_quantity'))) {
            return null;
        }

        $stock = $product->get_stock_quantity();
        if ($stock === null || $stock === '') {
            $stock = 0;
        }

        return max(0, (int) $stock);
    }

    private static function get_product_regular_price($product)
    {
        if (!$product || !is_callable(array($product, 'get_regular_price'))) {
            return null;
        }

        $price = $product->get_regular_price();
        if (!is_numeric($price)) {
            return null;
        }

        return round((float) $price, 2);
    }

    private static function get_product_discount_price($product)
    {
        if (!$product) {
            return null;
        }

        $sale_price = null;
        if (is_callable(array($product, 'get_sale_price'))) {
            $raw_sale_price = $product->get_sale_price();
            if (is_numeric($raw_sale_price)) {
                $sale_price = round((float) $raw_sale_price, 2);
            }
        }

        if ($sale_price !== null) {
            return $sale_price;
        }

        return null;
    }

    private static function numeric_or_null($value, $precision = 2)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if ($precision === 0) {
            return (int) round($number);
        }

        return round($number, (int) $precision);
    }

    private static function compare_preview_items($left, $right)
    {
        $left_sku = '';
        if (is_array($left) && isset($left['sku'])) {
            $left_sku = self::normalize_sku_key($left['sku']);
        }

        $right_sku = '';
        if (is_array($right) && isset($right['sku'])) {
            $right_sku = self::normalize_sku_key($right['sku']);
        }

        return strcmp($left_sku, $right_sku);
    }

    private static function run_marketplace_match_mode($supplier_id, $supplier, $adapter, $product_ids = array(), $product_skus = array(), $options = array())
    {
        $log_enabled = true;
        if (is_array($options) && array_key_exists('log_enabled', $options)) {
            $log_enabled = (bool) $options['log_enabled'];
        }

        $marketplace_key = '';
        if (is_object($supplier) && !empty($supplier->marketplace_key)) {
            $marketplace_key = sanitize_key((string) $supplier->marketplace_key);
        }

        $sku_map_result = self::fetch_marketplace_products_by_sku($supplier_id, $supplier, $adapter, $log_enabled);
        if (is_wp_error($sku_map_result)) {
            return $sku_map_result;
        }

        if (empty($sku_map_result)) {
            return array(
                'processed' => 0,
                'sent' => 0,
                'batch_request_ids' => array(),
                'mode' => 'marketplace_match',
                'marketplace_products' => 0,
                'matched_products' => 0,
            );
        }

        $requested_sku_keys = array();
        if (!empty($product_skus)) {
            foreach ($product_skus as $sku) {
                $normalized = self::normalize_sku_key($sku);
                if ($normalized !== '') {
                    $requested_sku_keys[] = $normalized;
                }
            }
            $requested_sku_keys = array_values(array_unique($requested_sku_keys));
        }

        if (!empty($product_ids)) {
            $requested_sku_keys = array_values(array_unique(array_merge(
                $requested_sku_keys,
                self::collect_sku_keys_from_product_ids($product_ids)
            )));
        }

        if (!empty($requested_sku_keys)) {
            $sku_map_result = array_intersect_key($sku_map_result, array_flip($requested_sku_keys));
        }

        if (empty($sku_map_result)) {
            return array(
                'processed' => 0,
                'sent' => 0,
                'batch_request_ids' => array(),
                'mode' => 'marketplace_match',
                'marketplace_products' => 0,
                'matched_products' => 0,
            );
        }

        $resolved_ids = self::resolve_product_ids_by_skus(array_keys($sku_map_result));
        $products = self::get_products_for_push($resolved_ids);
        if (empty($products)) {
            return array(
                'processed' => 0,
                'sent' => 0,
                'batch_request_ids' => array(),
                'mode' => 'marketplace_match',
                'marketplace_products' => count($sku_map_result),
                'matched_products' => 0,
            );
        }

        $items = array();
        foreach ($products as $product) {
            if (!$product || !is_callable(array($product, 'get_sku'))) {
                continue;
            }

            $sku = trim((string) $product->get_sku());
            if ($sku === '') {
                continue;
            }

            $sku_key = self::normalize_sku_key($sku);
            if (!isset($sku_map_result[$sku_key])) {
                continue;
            }

            $payload_item = $adapter->build_price_inventory_item_from_product($product, true, false);
            if ($payload_item === null || !is_array($payload_item)) {
                continue;
            }

            $items[] = $payload_item;
        }

        if (empty($items)) {
            return array(
                'processed' => count($products),
                'sent' => 0,
                'batch_request_ids' => array(),
                'mode' => 'marketplace_match',
                'marketplace_products' => count($sku_map_result),
                'matched_products' => count($products),
            );
        }

        $log_model = $log_enabled ? new SyncLog() : null;
        $batch_request_ids = array();
        $sent = 0;
        $errors = array();
        $chunks = self::build_push_chunks($items, $supplier);

        foreach ($chunks as $chunk) {
            $response = $adapter->push_price_inventory_updates($supplier, $chunk);
            if (is_wp_error($response)) {
                $errors[] = $response->get_error_message();
                if ($log_model) {
                    $log_model->log($supplier_id, 'stock_push', 'error', $response->get_error_message());
                }
                continue;
            }

            $sent += count($chunk);
            $chunk_batch_ids = self::extract_batch_ids($response);
            if (!empty($chunk_batch_ids)) {
                $batch_request_ids = array_merge($batch_request_ids, $chunk_batch_ids);
            }
        }

        $batch_request_ids = array_values(array_unique($batch_request_ids));

        if ($log_model) {
            $log_model->log(
                $supplier_id,
                'stock_push',
                'success',
                sprintf(
                    'Marketplace-match stock push completed. Sent: %d, Marketplace products: %d, Matched: %d, Batches: %d',
                    $sent,
                    count($sku_map_result),
                    count($products),
                    count($batch_request_ids)
                )
            );
        }

        return array(
            'processed' => count($products),
            'sent' => $sent,
            'batch_request_ids' => $batch_request_ids,
            'mode' => 'marketplace_match',
            'marketplace_products' => count($sku_map_result),
            'matched_products' => count($products),
            'errors' => $errors,
        );
    }

    public static function fetch_marketplace_products_by_sku($supplier_id, $supplier, $adapter, $log_warnings = true)
    {
        $marketplace_key = '';
        if (is_object($supplier) && !empty($supplier->marketplace_key)) {
            $marketplace_key = sanitize_key((string) $supplier->marketplace_key);
        }

        $max_pages = 40;
        $page_size = $marketplace_key === 'ciceksepeti' ? 60 : 300;
        $mapped_products_by_sku = array();
        $skipped_missing_sku = 0;

        for ($page = 0; $page < $max_pages; $page++) {
            $raw_items = $adapter->fetch_products($supplier, array(
                'page' => $page,
                'size' => $page_size,
            ));

            if (is_wp_error($raw_items)) {
                if (self::should_tolerate_rate_limit(
                    $marketplace_key,
                    $page,
                    count($mapped_products_by_sku),
                    $raw_items
                )) {
                    $warning_message = 'Ciceksepeti rate limit nedeniyle tum sayfalar cekilemedi; mevcut urunlerle eslestirmeye devam edildi.';
                    if ($log_warnings) {
                        $log_model = new SyncLog();
                        $log_model->log($supplier_id, 'stock_push', 'warning', $warning_message);
                    }
                    multi_sync_debug_log($warning_message . ' Page: ' . $page);
                    break;
                }

                return $raw_items;
            }

            if (!is_array($raw_items) || empty($raw_items)) {
                break;
            }

            $count_before = count($mapped_products_by_sku);

            foreach ($raw_items as $raw_item) {
                $mapped_item = $adapter->map_product($raw_item);
                if (is_wp_error($mapped_item)) {
                    $skipped_missing_sku++;
                    continue;
                }

                if (!is_array($mapped_item)) {
                    continue;
                }

                $sku = isset($mapped_item['sku']) ? trim((string) $mapped_item['sku']) : '';
                if ($sku === '') {
                    $skipped_missing_sku++;
                    continue;
                }

                $aliases = array($sku);
                foreach (array('external_sku', 'external_barcode', 'external_product_id') as $identifier_key) {
                    if (!empty($mapped_item[$identifier_key])) {
                        $aliases[] = (string) $mapped_item[$identifier_key];
                    }
                }
                foreach (array_unique($aliases) as $alias) {
                    $sku_key = self::normalize_sku_key($alias);
                    if ($sku_key !== '' && !isset($mapped_products_by_sku[$sku_key])) {
                        $mapped_products_by_sku[$sku_key] = $mapped_item;
                    }
                }
            }

            $count_after = count($mapped_products_by_sku);
            if ($count_after === $count_before && $page > 0) {
                break;
            }
        }

        if ($skipped_missing_sku > 0 && $log_warnings) {
            $log_model = new SyncLog();
            $log_model->log(
                $supplier_id,
                'stock_push',
                'warning',
                sprintf('%d pazar yeri urunu SKU eksik oldugu icin stok eslestirmesine dahil edilmedi.', $skipped_missing_sku)
            );
        }

        return $mapped_products_by_sku;
    }

    private static function should_tolerate_rate_limit($marketplace_key, $page, $mapped_count, $error)
    {
        if ($marketplace_key !== 'ciceksepeti' || $page <= 0 || $mapped_count <= 0 || !is_wp_error($error)) {
            return false;
        }

        $error_data = $error->get_error_data();
        $status_code = is_array($error_data) && isset($error_data['code']) ? (int) $error_data['code'] : 0;
        if (!in_array($status_code, array(400, 429), true)) {
            return false;
        }

        $message = (string) $error->get_error_message();
        if (is_array($error_data) && isset($error_data['body']) && is_string($error_data['body'])) {
            $message .= ' ' . $error_data['body'];
        }

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($message, 'UTF-8')
            : strtolower($message);

        return strpos($normalized, 'limit') !== false
            || strpos($normalized, 'dakika') !== false
            || strpos($normalized, 'too many') !== false;
    }

    private static function collect_sku_keys_from_product_ids($product_ids = array())
    {
        if (!is_array($product_ids) || empty($product_ids)) {
            return array();
        }

        $keys = array();
        foreach ($product_ids as $pid) {
            $product = wc_get_product((int) $pid);
            if (!$product || !is_callable(array($product, 'get_sku'))) {
                continue;
            }

            $sku_key = self::normalize_sku_key($product->get_sku());
            if ($sku_key !== '') {
                $keys[] = $sku_key;
            }
        }

        return array_values(array_unique($keys));
    }

    public static function normalize_sku_key($sku)
    {
        $normalized = trim((string) $sku);
        if ($normalized === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($normalized, 'UTF-8');
        }

        return strtolower($normalized);
    }

    private static function sanitize_stock_mode($stock_mode)
    {
        $mode = sanitize_key((string) $stock_mode);
        $allowed = array('marketplace_match', 'direct');

        if (!in_array($mode, $allowed, true)) {
            return 'marketplace_match';
        }

        return $mode;
    }

    private static function resolve_product_ids_by_skus($skus = array())
    {
        if (!is_array($skus) || empty($skus)) {
            return array();
        }

        $ids = array();
        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }

            $product_id = 0;
            if (function_exists('wc_get_product_id_by_sku')) {
                $product_id = (int) wc_get_product_id_by_sku($sku);
            }

            if (!$product_id) {
                global $wpdb;
                $meta_table = $wpdb->postmeta;
                $posts_table = $wpdb->posts;
                $product_id = (int) $wpdb->get_var($wpdb->prepare(
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

            if ($product_id > 0) {
                $ids[] = $product_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function push_single_product_update($supplier, $product, $sync_stock, $sync_price)
    {
        $manager = new MarketplaceManager();
        $adapter = $manager->for_supplier($supplier);
        if (!$adapter) {
            return;
        }

        $item = $adapter->build_price_inventory_item_from_product($product, $sync_stock, $sync_price);
        if (!$item) {
            return;
        }

        $response = $adapter->push_price_inventory_updates($supplier, array($item));
        $log_model = new SyncLog();

        if (is_wp_error($response)) {
            $log_model->log($supplier->id, 'stock_push', 'error', $response->get_error_message());
            return;
        }

        $log_model->log(
            $supplier->id,
            'stock_push',
            'success',
            'Price/stock updated for SKU: ' . $product->get_sku()
        );
    }

    private static function build_push_chunks($items, $supplier)
    {
        if (!is_array($items) || empty($items)) {
            return array();
        }

        $marketplace_key = '';
        if (is_object($supplier) && !empty($supplier->marketplace_key)) {
            $marketplace_key = sanitize_key((string) $supplier->marketplace_key);
        }

        // Ciceksepeti stock/price endpoint should be called as a single request.
        if ($marketplace_key === 'ciceksepeti') {
            return array(array_values($items));
        }

        return array_chunk($items, 100);
    }

    private static function get_sync_settings($supplier_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}multi_sync_settings WHERE supplier_id=%d",
            $supplier_id
        ));
    }

    private static function get_products_for_push($product_ids = array())
    {
        $products = array();

        if (!empty($product_ids)) {
            foreach ($product_ids as $pid) {
                $product = wc_get_product((int) $pid);
                if ($product && $product->get_sku()) {
                    $products[] = $product;
                }
            }
            return $products;
        }

        $query = new \WC_Product_Query(array(
            'status' => array('publish'),
            'limit' => -1,
            'return' => 'ids',
        ));

        $ids = $query->get_products();
        foreach ($ids as $pid) {
            $product = wc_get_product((int) $pid);
            if ($product && $product->get_sku()) {
                $products[] = $product;
            }
        }

        return $products;
    }

    private static function extract_batch_ids($response)
    {
        if (!is_array($response)) {
            return array();
        }

        $ids = array();

        $direct_keys = array(
            'batchRequestId',
            'taskId',
            'id',
            'requestId',
            'integrationOperationId',
            'IntegrationOperationId',
            'batchId',
            'BatchId',
        );
        foreach ($direct_keys as $key) {
            if (!isset($response[$key])) {
                continue;
            }

            $value = $response[$key];
            if (is_scalar($value) && (string) $value !== '') {
                $ids[] = (string) $value;
            }
        }

        $array_keys = array('batchRequestIds', 'taskIds', 'ids');
        foreach ($array_keys as $key) {
            if (!isset($response[$key]) || !is_array($response[$key])) {
                continue;
            }

            foreach ($response[$key] as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $ids[] = (string) $value;
                }
            }
        }

        if (isset($response['data']) && is_array($response['data'])) {
            $ids = array_merge($ids, self::extract_batch_ids($response['data']));
        }

        if (isset($response['results']) && is_array($response['results'])) {
            foreach ($response['results'] as $result_item) {
                if (is_array($result_item)) {
                    $ids = array_merge($ids, self::extract_batch_ids($result_item));
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
