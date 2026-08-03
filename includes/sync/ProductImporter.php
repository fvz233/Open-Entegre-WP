<?php

namespace MultiSync\Sync;

use MultiSync\Marketplaces\MarketplaceManager;
use MultiSync\Models\Supplier;
use MultiSync\Models\SyncLog;

if (!defined('ABSPATH')) {
    exit;
}

function resolve_commission_rate($product_rates, $marketplace_key, $default_rate)
{
    return is_array($product_rates) && array_key_exists($marketplace_key, $product_rates)
        ? (float) $product_rates[$marketplace_key]
        : (float) $default_rate;
}

function resolve_inherited_commission_rate($variation_rates, $parent_rates, $marketplace_key, $default_rate)
{
    if (is_array($variation_rates) && array_key_exists($marketplace_key, $variation_rates)) {
        return (float) $variation_rates[$marketplace_key];
    }
    return resolve_commission_rate($parent_rates, $marketplace_key, $default_rate);
}

function group_variation_products($products, $variation_choices = array())
{
    $candidates = array();
    foreach ($products as $index => $product) {
        $parent_key = isset($product['parent_key']) ? trim((string) $product['parent_key']) : '';
        if ($parent_key !== '') {
            $candidates[$parent_key][$index] = $product;
        }
    }

    $groups = array();
    foreach ($candidates as $parent_key => $children) {
        if (count($children) < 2) {
            continue;
        }
        $values = array();
        foreach ($children as $child) {
            foreach ((array) ($child['variation_attributes'] ?? array()) as $name => $value) {
                if (trim((string) $name) !== '' && trim((string) $value) !== '') {
                    $values[(string) $name][(string) $value] = true;
                }
            }
        }
        $varying = array_filter($values, static function ($set) {
            return count($set) > 1;
        });
        if (empty($varying)) {
            continue;
        }
        $choice = isset($variation_choices[$parent_key]) ? (string) $variation_choices[$parent_key] : '';
        if ($choice !== '' && isset($varying[$choice])) {
            $varying = array($choice => $varying[$choice]);
        }
        foreach ($children as $index => $child) {
            $child['variation_attributes'] = array_intersect_key((array) $child['variation_attributes'], $varying);
            $children[$index] = $child;
        }
        $groups[$parent_key] = $children;
    }
    return $groups;
}

class ProductImporter
{

    private $supplier_model;
    private $marketplace_manager;
    private $log_model;
    private $last_error;

    public function __construct()
    {
        if (function_exists('multi_sync_debug_log'))
            multi_sync_debug_log("ProductImporter::__construct start");
        $this->supplier_model = new Supplier();
        $this->marketplace_manager = new MarketplaceManager();
        $this->log_model = new SyncLog();
        if (function_exists('multi_sync_debug_log'))
            multi_sync_debug_log("ProductImporter::__construct end");
    }

    public function run_sync($supplier_id, $selected_skus = array(), $variation_choices = array())
    {
        multi_sync_debug_log("ProductImporter::run_sync start for Supplier $supplier_id with " . count($selected_skus) . " selected SKUs.");
        $supplier = $this->supplier_model->get($supplier_id);
        $report = $this->empty_report();
        if (!$supplier || !$supplier->active) {
            $report['errors'][] = 'Supplier not found or inactive.';
            return $report;
        }

        $this->log_model->log($supplier_id, 'product_import', 'info', 'Starting Import...');

        $stok_kodsuz = array();
        $products = $this->fetch_and_map_products($supplier_id, false, $stok_kodsuz);

        if (empty($products)) {
            if (is_wp_error($this->last_error)) {
                $report['errors'][] = $this->last_error->get_error_message();
            }
            $this->log_model->log($supplier_id, 'product_import', 'warning', 'No products found to import.');
            return $report;
        }

        $grouped_indexes = array();
        foreach (group_variation_products($products, $variation_choices) as $parent_key => $children) {
            foreach (array_keys($children) as $index) {
                $grouped_indexes[$index] = true;
            }
            $has_selected_child = empty($selected_skus);
            foreach ($children as $child) {
                $has_selected_child = $has_selected_child || $this->is_selected($child, $selected_skus);
            }
            if (!$has_selected_child) {
                continue;
            }
            $result = $this->save_variable_group($parent_key, $children, $supplier_id, $selected_skus);
            $this->merge_report($report, $result);
        }

        foreach ($products as $index => $product_data) {
            if (isset($grouped_indexes[$index])) {
                continue;
            }
            if (!$this->is_selected($product_data, $selected_skus)) {
                continue;
            }
            $this->merge_report($report, $this->save_product($product_data, $supplier_id));
        }

        $this->log_model->log($supplier_id, 'product_import', empty($report['errors']) && empty($report['warnings']) ? 'success' : 'warning', sprintf(
            'Products: created %d, updated %d, migrated %d, skipped %d, warnings %d, errors %d.',
            $report['created'], $report['updated'], $report['migrated'], $report['skipped'], count($report['warnings']), count($report['errors'])
        ));
        return $report;
    }

    public function preview($supplier_id)
    {
        multi_sync_debug_log("ProductImporter::preview called for Supplier $supplier_id");
        $stok_kodsuz = array();
        $items = $this->fetch_and_map_products($supplier_id, true, $stok_kodsuz);
        if (is_wp_error($this->last_error)) {
            return $this->last_error;
        }

        $groups = group_variation_products($items);
        foreach ($groups as $parent_key => $children) {
            $attribute_options = array();
            foreach ($children as $child) {
                $attribute_options = array_values(array_unique(array_merge($attribute_options, array_keys((array) $child['variation_attributes']))));
            }
            foreach (array_keys($children) as $index) {
                $items[$index]['row_type'] = 'variation';
                $items[$index]['variation_parent_key'] = $parent_key;
                $items[$index]['variation_attributes'] = $children[$index]['variation_attributes'];
                $items[$index]['variation_attribute_options'] = $attribute_options;
            }
        }
        $blocked_parents = array();
        foreach ($items as $index => $item) {
            $match = $this->find_product($item, $supplier_id);
            if (is_wp_error($match)) {
                $items[$index]['preview_warning'] = $match->get_error_message();
                $items[$index]['can_import'] = false;
                if (!empty($item['variation_parent_key'])) {
                    $blocked_parents[$item['variation_parent_key']] = $match->get_error_message();
                }
            } else {
                $items[$index]['import_action'] = $match ? 'update' : 'create';
                if ($match && isset($item['row_type']) && $item['row_type'] === 'variation') {
                    $existing = wc_get_product($match);
                    if ($existing && !$existing->is_type('variation')) {
                        $items[$index]['preview_warning'] = 'Existing Woo product will keep its ID and migrate to a variation.';
                    }
                }
            }
        }
        foreach ($items as $index => $item) {
            $parent_key = $item['variation_parent_key'] ?? '';
            if ($parent_key !== '' && isset($blocked_parents[$parent_key])) {
                $items[$index]['preview_warning'] = 'Variation group skipped: ' . $blocked_parents[$parent_key];
                $items[$index]['can_import'] = false;
            }
        }
        return array(
            'items' => array_values($items),
            'stok_kodsuz' => array_values($stok_kodsuz),
        );
    }

    private function fetch_and_map_products($supplier_id, $collect_stok_kodsuz, &$stok_kodsuz)
    {
        $this->last_error = null;
        multi_sync_debug_log("Fetching products for supplier $supplier_id");
        $supplier = $this->supplier_model->get($supplier_id);
        if (!$supplier || !$supplier->active) {
            return array();
        }

        $marketplace = $this->marketplace_manager->for_supplier($supplier);
        if (!$marketplace) {
            $this->log_model->log($supplier_id, 'product_import', 'error', 'Marketplace adapter not found.');
            $this->last_error = new \WP_Error('multi_sync_marketplace_not_found', 'Marketplace adapter not found.');
            return array();
        }

        $marketplace_key = '';
        if ($supplier && isset($supplier->marketplace_key)) {
            $marketplace_key = sanitize_key((string) $supplier->marketplace_key);
        }

        $max_pages = 80;
        $page_size = $marketplace_key === 'ciceksepeti' ? 60 : 300;
        $mapped_products = array();
        $mapped_products_by_sku = array();
        $skipped_missing_stock_code = 0;
        $total_raw_items = 0;

        for ($page = 0; $page < $max_pages; $page++) {
            $items = $marketplace->fetch_products($supplier, array('page' => $page, 'size' => $page_size));
            if (is_wp_error($items)) {
                if ($this->should_tolerate_ciceksepeti_rate_limit(
                    $marketplace_key,
                    $page,
                    count($mapped_products_by_sku),
                    $items
                )) {
                    $warning_message = 'Ciceksepeti rate limit nedeniyle tum sayfalar cekilemedi; mevcut verilerle devam edildi.';
                    multi_sync_debug_log($warning_message . ' Page: ' . $page);
                    $this->log_model->log($supplier_id, 'product_import', 'warning', $warning_message);
                    break;
                }

                multi_sync_debug_log("Product fetch error: " . $items->get_error_message());
                $this->log_model->log($supplier_id, 'product_import', 'error', 'Product fetch error: ' . $items->get_error_message());
                $this->last_error = $items;
                return array();
            }

            if (!is_array($items) || empty($items)) {
                break;
            }

            $total_raw_items += count($items);
            $count_before = count($mapped_products_by_sku);

            foreach ($items as $item) {
                try {
                    $product_data = $marketplace->map_product($item);
                    if (is_wp_error($product_data)) {
                        $skipped_missing_stock_code++;
                        if ($collect_stok_kodsuz && $this->is_missing_identifier_error($product_data)) {
                            $this->collect_stok_kodsuz_item($stok_kodsuz, $item, array(), $product_data->get_error_message());
                        }
                        multi_sync_debug_log("Product mapping skipped: " . $product_data->get_error_message());
                        continue;
                    }

                    if (empty($product_data['sku'])) {
                        if ($collect_stok_kodsuz) {
                            $this->collect_stok_kodsuz_item($stok_kodsuz, $item, $product_data, 'Stock kodu veya SKU bulunamadi.');
                        }
                        continue;
                    }

                    $identity = !empty($product_data['external_barcode'])
                        ? $product_data['external_barcode']
                        : (!empty($product_data['external_product_id']) ? $product_data['external_product_id'] : $product_data['sku']);
                    $sku_key = $this->normalize_sku_key($identity);
                    if ($sku_key === '' || isset($mapped_products_by_sku[$sku_key])) {
                        continue;
                    }

                    $mapped_products_by_sku[$sku_key] = $this->enrich_product_data($product_data);
                } catch (\Throwable $e) {
                    multi_sync_debug_log("Mapping Error: " . $e->getMessage());
                }
            }

            $count_after = count($mapped_products_by_sku);
            if ($count_after === $count_before && $page > 0) {
                // Guards against APIs that keep returning the first page.
                break;
            }
        }

        $mapped_products = array_values($mapped_products_by_sku);
        multi_sync_debug_log("Found " . $total_raw_items . " items across pages. Mapped unique SKU count: " . count($mapped_products));

        if ($skipped_missing_stock_code > 0) {
            $this->log_model->log(
                $supplier_id,
                'product_import',
                'warning',
                sprintf('%d urun stockCode olmadigi icin atlandi.', $skipped_missing_stock_code)
            );
        }

        return $mapped_products;
    }

    private function collect_stok_kodsuz_item(&$stok_kodsuz, $raw_item, $mapped_item = array(), $reason = '')
    {
        if (!is_array($stok_kodsuz)) {
            $stok_kodsuz = array();
        }

        $entry = $this->build_stok_kodsuz_entry($raw_item, $mapped_item, $reason);
        if (empty($entry) || !is_array($entry) || empty($entry['__key'])) {
            return;
        }

        $key = $entry['__key'];
        unset($entry['__key']);

        if (!isset($stok_kodsuz[$key])) {
            $stok_kodsuz[$key] = $entry;
        }
    }

    private function build_stok_kodsuz_entry($raw_item, $mapped_item = array(), $reason = '')
    {
        $sources = array();
        if (is_array($mapped_item) && !empty($mapped_item)) {
            $sources[] = $mapped_item;
        }
        $sources[] = $this->to_array_recursive($raw_item);

        $name = $this->find_first_non_empty_value($sources, array(
            'name',
            'title',
            'item-name',
            'item_name',
            'product_name',
            'productname',
            'urun_adi',
            'urunadi',
        ));
        $merchant_sku = $this->find_first_non_empty_value($sources, array(
            'merchantsku',
            'merchant_sku',
            'stockcode',
            'stock_code',
            'seller_sku',
            'sellersku',
            'barcode',
            'barkod',
            'model_code',
            'modelcode',
            'code',
        ));

        if ($name === '' && $merchant_sku === '') {
            return array();
        }

        $preview_image = '';
        if (is_array($mapped_item) && isset($mapped_item['preview_image']) && is_string($mapped_item['preview_image'])) {
            $preview_image = trim($mapped_item['preview_image']);
        }

        $unique_key = $merchant_sku !== ''
            ? 'merchant:' . $this->normalize_sku_key($merchant_sku)
            : 'name:' . $this->normalize_sku_key($name);

        if ($unique_key === '' || $unique_key === 'merchant:' || $unique_key === 'name:') {
            $unique_key = 'row:' . md5(wp_json_encode($sources));
        }

        return array(
            '__key' => $unique_key,
            'name' => $name,
            'merchant_sku' => $merchant_sku,
            'preview_image' => $preview_image,
            'reason' => (string) $reason,
        );
    }

    private function is_missing_identifier_error($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $code = $this->normalize_lookup_key($error->get_error_code());
        $message = $this->normalize_lookup_key($error->get_error_message());
        $haystack = trim($code . ' ' . $message);

        if ($haystack === '') {
            return false;
        }

        if (strpos($haystack, 'stockcode') !== false || strpos($haystack, 'stock_code') !== false) {
            return true;
        }

        $has_missing = strpos($haystack, 'missing') !== false
            || strpos($haystack, 'not_found') !== false
            || strpos($haystack, 'bulunamadi') !== false
            || strpos($haystack, 'eksik') !== false;

        $mentions_identifier = strpos($haystack, 'sku') !== false
            || strpos($haystack, 'stock') !== false
            || strpos($haystack, 'stok') !== false
            || strpos($haystack, 'merchant') !== false
            || strpos($haystack, 'barkod') !== false
            || strpos($haystack, 'barcode') !== false;

        return $has_missing && $mentions_identifier;
    }

    private function find_first_non_empty_value($sources, $candidate_keys)
    {
        if (!is_array($sources) || !is_array($candidate_keys)) {
            return '';
        }

        foreach ($candidate_keys as $candidate_key) {
            $normalized_candidate = $this->normalize_lookup_key($candidate_key);
            if ($normalized_candidate === '') {
                continue;
            }

            foreach ($sources as $source) {
                $value = $this->find_value_by_key_recursive($source, $normalized_candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function find_value_by_key_recursive($source, $normalized_candidate_key)
    {
        if (is_object($source)) {
            $source = get_object_vars($source);
        }

        if (!is_array($source)) {
            return '';
        }

        foreach ($source as $key => $value) {
            if ($this->normalize_lookup_key($key) === $normalized_candidate_key) {
                if (is_scalar($value)) {
                    $string_value = trim((string) $value);
                    if ($string_value !== '') {
                        return $string_value;
                    }
                }
            }

            if (is_array($value) || is_object($value)) {
                $nested = $this->find_value_by_key_recursive($value, $normalized_candidate_key);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function to_array_recursive($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return array();
        }

        $result = array();
        foreach ($value as $key => $item) {
            if (is_array($item) || is_object($item)) {
                $result[$key] = $this->to_array_recursive($item);
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }

    private function normalize_lookup_key($value)
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(array('-', '.', ' '), '_', $normalized);
        if (function_exists('mb_strtolower')) {
            $normalized = mb_strtolower($normalized, 'UTF-8');
        } else {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }

    private function should_tolerate_ciceksepeti_rate_limit($marketplace_key, $page, $mapped_count, $error)
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

    private function normalize_sku_key($sku)
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

    private function enrich_product_data($product_data)
    {
        $image_url = '';
        if (isset($product_data['images'])) {
            $imgs = $product_data['images'];
            $raw = is_array($imgs) ? (count($imgs) > 0 ? $imgs[0] : '') : $imgs;

            if (is_string($raw)) {
                $image_url = $raw;
            }
        }

        $product_data['preview_image'] = $image_url;
        $product_data['selection_key'] = !empty($product_data['external_barcode'])
            ? (string) $product_data['external_barcode']
            : (!empty($product_data['external_product_id']) ? (string) $product_data['external_product_id'] : (string) $product_data['sku']);
        return $product_data;
    }

    private function save_product($data, $supplier_id)
    {
        $supplier = $this->supplier_model->get($supplier_id);
        $report = $this->empty_report();
        $match = $this->find_product($data, $supplier_id);
        if (is_wp_error($match)) {
            $report['errors'][] = $match->get_error_message();
            return $report;
        }

        $created = !$match;
        $product = $match ? wc_get_product($match) : new \WC_Product_Simple();
        if (!$product) {
            $report['errors'][] = 'Product could not be loaded for ' . (string) $data['sku'];
            return $report;
        }
        if ($product->is_type('variable')) {
            $report['skipped']++;
            return $report;
        }
        if ($created) {
            $sku = $this->choose_new_woo_sku($data);
            if ($sku === '') {
                $report['errors'][] = 'No unique Woo SKU for ' . (string) $data['sku'];
                return $report;
            }
            $product->set_sku($sku);
        }

        $this->apply_product_data($product, $data, $supplier, null);
        $product->save();
        if ($created) {
            $this->apply_images($product, $data);
            $product->save();
        }
        $report[$created ? 'created' : 'updated']++;
        return $report;
    }

    private function save_variable_group($parent_key, $children, $supplier_id, $selected_skus)
    {
        $report = $this->empty_report();
        $supplier = $this->supplier_model->get($supplier_id);
        $resolved = array();
        $reserved_skus = array();
        $seller_sku_counts = array_count_values(array_filter(array_map(static function ($child) {
            return isset($child['external_sku']) ? (string) $child['external_sku'] : '';
        }, $children)));
        foreach ($children as $index => &$child) {
            $match = $this->find_product($child, $supplier_id);
            if (is_wp_error($match)) {
                $report['errors'][] = $match->get_error_message();
                return $report;
            }
            if ($match && in_array((int) $match, $resolved, true)) {
                $report['errors'][] = 'Variation identifiers resolve to the same Woo product for parent ' . $parent_key;
                return $report;
            }
            if ($match) {
                $existing = wc_get_product($match);
                if (!$existing || $existing->is_type('variable')) {
                    $report['errors'][] = 'Variation conflict for parent ' . $parent_key;
                    return $report;
                }
                if ($existing->is_type('variation') && $existing->get_parent_id() > 0) {
                    $stored_parent = (string) $existing->get_meta('_multi_sync_parent_key', true);
                    if ($stored_parent === '') {
                        $current_parent = wc_get_product($existing->get_parent_id());
                        $stored_parent = $current_parent ? (string) $current_parent->get_meta('_multi_sync_parent_key', true) : '';
                        if ($stored_parent === '' && $current_parent && (string) $current_parent->get_sku() === (string) $parent_key) {
                            $stored_parent = (string) $parent_key;
                        }
                    }
                    if ($stored_parent !== (string) $parent_key) {
                        $report['errors'][] = 'Variation already belongs to another marketplace parent.';
                        return $report;
                    }
                }
                $resolved[] = (int) $match;
            } else {
                $sku_data = $child;
                if (!empty($child['external_sku']) && ($seller_sku_counts[(string) $child['external_sku']] ?? 0) > 1) {
                    $sku_data['sku'] = !empty($child['external_barcode']) ? $child['external_barcode'] : ($child['external_product_id'] ?? '');
                }
                $child['sku'] = $this->choose_new_woo_sku($sku_data, $reserved_skus);
                if ($child['sku'] === '') {
                    $report['errors'][] = 'No unique Woo SKU for variation in parent ' . $parent_key;
                    return $report;
                }
                $reserved_skus[] = $this->normalize_sku_key($child['sku']);
            }
            $children[$index]['_resolved_id'] = (int) $match;
        }
        unset($child);

        $parent_id = $this->find_variable_parent($parent_key, $supplier_id, $supplier->marketplace_key);
        if (is_wp_error($parent_id)) {
            $report['errors'][] = $parent_id->get_error_message();
            return $report;
        }
        $parent = $parent_id ? wc_get_product($parent_id) : new \WC_Product_Variable();
        if (!$parent || ($parent_id && !$parent->is_type('variable'))) {
            $report['errors'][] = 'Variable parent conflict for ' . $parent_key;
            return $report;
        }

        $first = reset($children);
        $parent->set_name(isset($first['name']) ? (string) $first['name'] : (string) $parent_key);
        $parent->update_meta_data('_multi_sync_supplier_id', (int) $supplier_id);
        $parent->update_meta_data('_multi_sync_marketplace_key', sanitize_key((string) $supplier->marketplace_key));
        $parent->update_meta_data('_multi_sync_parent_key', (string) $parent_key);
        $parent->set_attributes($this->build_parent_attributes($children));
        $parent->save();
        if (!$parent_id) {
            $this->apply_images($parent, $first);
            $parent->save();
        }

        foreach ($children as $child) {
            if (!$this->is_selected($child, $selected_skus)) {
                continue;
            }
            $existing_id = (int) $child['_resolved_id'];
            $migrated = false;
            if ($existing_id) {
                $old = wc_get_product($existing_id);
                if (!$old->is_type('variation')) {
                    $updated = wp_update_post(array('ID' => $existing_id, 'post_type' => 'product_variation', 'post_parent' => $parent->get_id()), true);
                    if (is_wp_error($updated)) {
                        $report['errors'][] = $updated->get_error_message();
                        continue;
                    }
                    clean_post_cache($existing_id);
                    $migrated = true;
                }
                $variation = new \WC_Product_Variation($existing_id);
            } else {
                $variation = new \WC_Product_Variation();
                $variation->set_sku($child['sku']);
            }
            $variation->set_parent_id($parent->get_id());
            $attributes = array();
            foreach ((array) $child['variation_attributes'] as $name => $value) {
                $attributes[sanitize_title((string) $name)] = (string) $value;
            }
            $variation->set_attributes($attributes);
            $this->apply_product_data($variation, $child, $supplier, $parent);
            $variation->save();
            if (!$existing_id) {
                $this->apply_images($variation, $child);
                $variation->save();
            }
            $report[$migrated ? 'migrated' : ($existing_id ? 'updated' : 'created')]++;
        }
        \WC_Product_Variable::sync($parent->get_id());
        wc_delete_product_transients($parent->get_id());
        return $report;
    }

    private function apply_product_data($product, $data, $supplier, $parent = null)
    {
        if (isset($data['name']) && !$product->is_type('variation')) {
            $product->set_name((string) $data['name']);
        }
        $marketplace_key = sanitize_key((string) $supplier->marketplace_key);
        $rate = resolve_inherited_commission_rate(
            $product->get_meta('_multi_sync_commission_rates', true),
            $parent ? $parent->get_meta('_multi_sync_commission_rates', true) : array(),
            $marketplace_key,
            $supplier ? $supplier->commission_rate : 0
        );
        $factor = 1 - ($rate / 100);
        if (isset($data['regular_price']) && is_numeric($data['regular_price'])) {
            $regular = round(max(0, (float) $data['regular_price'] * $factor), 2);
            $product->set_regular_price($regular);
            $sale = isset($data['sale_price']) && is_numeric($data['sale_price'])
                ? round(max(0, (float) $data['sale_price'] * $factor), 2)
                : '';
            $product->set_sale_price($sale !== '' && $sale < $regular ? $sale : '');
        }
        if (isset($data['stock_quantity'])) {
            $product->set_stock_quantity(max(0, (int) $data['stock_quantity']));
            $product->set_manage_stock(true);
        }
        $product->update_meta_data('_multi_sync_supplier_id', (int) $supplier->id);
        $product->update_meta_data('_multi_sync_marketplace_key', $marketplace_key);
        $meta = array(
            '_multi_sync_external_sku' => 'external_sku',
            '_multi_sync_external_barcode' => 'external_barcode',
            '_multi_sync_external_product_id' => 'external_product_id',
            '_multi_sync_parent_key' => 'parent_key',
        );
        foreach ($meta as $meta_key => $data_key) {
            if (isset($data[$data_key]) && $data[$data_key] !== '') {
                $product->update_meta_data($meta_key, sanitize_text_field((string) $data[$data_key]));
            }
        }
        if ($marketplace_key === 'pazarama' && !empty($data['code'])) {
            $product->update_meta_data('_multi_sync_pazarama_code', sanitize_text_field($data['code']));
        }
    }

    private function build_parent_attributes($children)
    {
        $options = array();
        foreach ($children as $child) {
            foreach ((array) $child['variation_attributes'] as $name => $value) {
                $options[(string) $name][(string) $value] = true;
            }
        }
        $attributes = array();
        $position = 0;
        foreach ($options as $name => $values) {
            $attribute = new \WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name($name);
            $attribute->set_options(array_keys($values));
            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes[] = $attribute;
        }
        return $attributes;
    }

    private function find_product($data, $supplier_id)
    {
        $ids = array();
        $identifiers = array(
            '_multi_sync_external_barcode' => $data['external_barcode'] ?? '',
            '_multi_sync_external_product_id' => $data['external_product_id'] ?? '',
            '_multi_sync_external_sku' => $data['external_sku'] ?? '',
        );
        $ambiguous_identifier = false;
        foreach ($identifiers as $meta_key => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $matches = get_posts(array(
                'post_type' => array('product', 'product_variation'), 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 3,
                'meta_query' => array(
                    array('key' => '_multi_sync_supplier_id', 'value' => (int) $supplier_id),
                    array('key' => $meta_key, 'value' => (string) $value),
                ),
            ));
            $matches = array_values(array_unique(array_map('intval', $matches)));
            if (count($matches) === 1) {
                $ids[] = $matches[0];
            } elseif (count($matches) > 1) {
                $ambiguous_identifier = true;
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 1) {
            return new \WP_Error('multi_sync_product_identifier_conflict', 'Marketplace identifiers match multiple Woo products.');
        }
        if (count($ids) === 1) {
            return $ids[0];
        }
        if ($ambiguous_identifier) {
            return new \WP_Error('multi_sync_product_identifier_conflict', 'A marketplace identifier matches multiple Woo products.');
        }
        $sku_ids = array();
        foreach (array('sku', 'external_sku', 'external_barcode', 'external_product_id') as $key) {
            if (empty($data[$key])) {
                continue;
            }
            $candidate_id = (int) wc_get_product_id_by_sku((string) $data[$key]);
            if ($candidate_id) {
                $sku_ids[] = $candidate_id;
            }
        }
        $sku_ids = array_values(array_unique($sku_ids));
        if (count($sku_ids) > 1) {
            return new \WP_Error('multi_sync_product_identifier_conflict', 'Marketplace identifiers match different Woo SKUs.');
        }
        if (empty($sku_ids)) {
            return 0;
        }
        return $sku_ids[0];
    }

    private function find_variable_parent($parent_key, $supplier_id, $marketplace_key)
    {
        $ids = get_posts(array(
            'post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 3,
            'meta_query' => array(
                array('key' => '_multi_sync_supplier_id', 'value' => (int) $supplier_id),
                array('key' => '_multi_sync_marketplace_key', 'value' => sanitize_key((string) $marketplace_key)),
                array('key' => '_multi_sync_parent_key', 'value' => (string) $parent_key),
            ),
        ));
        if (count($ids) > 1) {
            return new \WP_Error('multi_sync_parent_conflict', 'Multiple variable parents match ' . $parent_key);
        }
        if (!empty($ids)) {
            return (int) $ids[0];
        }
        return (int) wc_get_product_id_by_sku((string) $parent_key);
    }

    private function choose_new_woo_sku($data, $reserved_skus = array())
    {
        foreach (array('sku', 'external_barcode', 'external_product_id') as $key) {
            $candidate = isset($data[$key]) ? trim((string) $data[$key]) : '';
            if ($candidate !== '' && !in_array($this->normalize_sku_key($candidate), $reserved_skus, true) && !wc_get_product_id_by_sku($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function apply_images($product, $data)
    {
        $urls = isset($data['images']) ? array_unique(array_filter((array) $data['images'], 'is_string')) : array();
        $ids = array();
        foreach ($urls as $url) {
            $attachment_id = $this->get_or_sideload_image($url, $product->get_id());
            if ($attachment_id) {
                $ids[] = $attachment_id;
            }
        }
        if (!empty($ids)) {
            $product->set_image_id($ids[0]);
            $product->set_gallery_image_ids(array_slice($ids, 1));
        }
    }

    private function is_selected($data, $selected_skus)
    {
        if (empty($selected_skus)) {
            return true;
        }
        foreach (array('sku', 'external_sku', 'external_barcode', 'external_product_id') as $key) {
            if (!empty($data[$key]) && in_array((string) $data[$key], $selected_skus, true)) {
                return true;
            }
        }
        return false;
    }

    private function empty_report()
    {
        return array('created' => 0, 'updated' => 0, 'migrated' => 0, 'skipped' => 0, 'warnings' => array(), 'errors' => array());
    }

    private function merge_report(&$report, $part)
    {
        foreach (array('created', 'updated', 'migrated', 'skipped') as $key) {
            $report[$key] += isset($part[$key]) ? (int) $part[$key] : 0;
        }
        if (!empty($part['errors'])) {
            $report['errors'] = array_merge($report['errors'], (array) $part['errors']);
        }
        if (!empty($part['warnings'])) {
            $report['warnings'] = array_merge($report['warnings'], (array) $part['warnings']);
        }
    }

    private function get_or_sideload_image($url, $product_id)
    {
        // 1. Check if image already exists in media library by URL source
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1",
            $url
        ));

        if ($attachment_id) {
            return $attachment_id;
        }

        // 2. Download
        if (!function_exists('media_sideload_image')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = array(
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp
        );

        if (empty($file_array['name'])) {
            $file_array['name'] = md5($url) . '.jpg';
        }

        $path_parts = pathinfo($file_array['name']);
        if (!isset($path_parts['extension'])) {
            $file_array['name'] .= '.jpg';
        }

        $id = media_handle_sideload($file_array, $product_id);

        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }

        update_post_meta($id, '_source_url', $url);
        return $id;
    }
}
