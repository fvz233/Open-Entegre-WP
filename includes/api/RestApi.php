<?php

namespace MultiSync\Api;

use MultiSync\Models\Supplier;
use MultiSync\Models\MarketplaceQuestion;
use MultiSync\Models\SyncJob;
use MultiSync\Models\SyncJobItem;
use MultiSync\Models\SyncChangeHistory;
use MultiSync\Marketplaces\MarketplaceManager;
use MultiSync\Sync\ProductImporter;
use MultiSync\Sync\OrderImporter;
use MultiSync\Sync\StockSync;
use MultiSync\Sync\StockScheduler;
use MultiSync\Sync\OrderScheduler;
use MultiSync\Sync\JobQueue;
use MultiSync\Sync\QuestionSync;
use MultiSync\Sync\ProductPublisher;

if (!defined('ABSPATH')) {
    exit;
}

class RestApi
{

    public function register_routes()
    {
        $namespace = 'multi-sync/v1';

        // Suppliers
        register_rest_route($namespace, '/suppliers', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_suppliers'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/suppliers/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_supplier'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Manual Sync Trigger
        register_rest_route($namespace, '/sync/run', array(
            'methods' => 'POST',
            'callback' => array($this, 'run_manual_sync'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/sync/stock-price', array(
            'methods' => 'POST',
            'callback' => array($this, 'run_stock_price_sync'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/sync/stock-price-preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'preview_stock_price_sync'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Sync Preview (Products)
        register_rest_route($namespace, '/sync/preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'preview_sync'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Sync Preview (Orders)
        register_rest_route($namespace, '/sync/order-preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'preview_order_sync'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/products/publish-preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'preview_product_publish'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/products/publish', array(
            'methods' => 'POST',
            'callback' => array($this, 'publish_products'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/trendyol/category-mappings/(?P<supplier_id>\d+)', array(
            array('methods' => 'GET', 'callback' => array($this, 'get_trendyol_category_mappings'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => 'POST', 'callback' => array($this, 'save_trendyol_category_mapping'), 'permission_callback' => array($this, 'check_permission')),
        ));

        register_rest_route($namespace, '/trendyol/categories/(?P<supplier_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'search_trendyol_categories'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/trendyol/categories/(?P<supplier_id>\d+)/(?P<category_id>\d+)/attributes', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_trendyol_category_attributes'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/marketplaces/category-mappings/(?P<supplier_id>\d+)', array(
            array('methods' => 'GET', 'callback' => array($this, 'get_marketplace_category_mappings'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => 'POST', 'callback' => array($this, 'save_marketplace_category_mapping'), 'permission_callback' => array($this, 'check_permission')),
        ));

        register_rest_route($namespace, '/marketplaces/categories/(?P<supplier_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'search_marketplace_categories'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/marketplaces/categories/(?P<supplier_id>\d+)/(?P<category_id>[^/]+)/attributes', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_marketplace_category_attributes'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/marketplaces/brands/(?P<supplier_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'search_marketplace_brands'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/marketplaces/brand-mappings/(?P<supplier_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_marketplace_brand_mapping'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/debug/marketplace-http', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_marketplace_http_debug'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/debug/marketplace-products-raw', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_marketplace_raw_products'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Sync Settings
        register_rest_route($namespace, '/settings/(?P<supplier_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_sync_settings'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_sync_settings'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Universal queue center
        register_rest_route($namespace, '/jobs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_jobs'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/jobs/(?P<id>\\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_job_detail'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/jobs/(?P<id>\\d+)/approve', array(
            'methods' => 'POST',
            'callback' => array($this, 'approve_job'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/jobs/(?P<id>\\d+)/reject', array(
            'methods' => 'POST',
            'callback' => array($this, 'reject_job'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/jobs/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_job_settings'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/jobs/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_job_settings'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/changes', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_changes'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Marketplace questions
        register_rest_route($namespace, '/questions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_questions'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/questions/refresh', array(
            'methods' => 'POST',
            'callback' => array($this, 'refresh_questions'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route($namespace, '/questions/(?P<id>\\d+)/reply', array(
            'methods' => 'POST',
            'callback' => array($this, 'reply_question'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }

    public function check_permission()
    {
        return current_user_can('manage_options');
    }

    public function get_suppliers()
    {
        $model = new Supplier();
        $suppliers = $model->ensure_predefined_suppliers();
        return rest_ensure_response($suppliers ? $suppliers : array());
    }

    public function update_supplier($request)
    {
        $id = $request->get_param('id');
        $params = $request->get_params();

        // Debug logging
        multi_sync_debug_log("update_supplier called with ID: $id");
        multi_sync_debug_log("update_supplier params: " . json_encode($this->mask_sensitive_params($params)));

        $model = new Supplier();
        $result = $model->update($id, $params);

        multi_sync_debug_log("update_supplier result: " . json_encode($result));

        if ($result === false) {
            global $wpdb;
            $message = 'Tedarikci bilgileri kaydedilemedi.';
            if (isset($wpdb) && !empty($wpdb->last_error)) {
                $message .= ' DB: ' . $wpdb->last_error;
                multi_sync_debug_log("update_supplier db_error: " . $wpdb->last_error);
            }

            return new \WP_Error('multi_sync_supplier_update_failed', $message, array('status' => 500));
        }

        StockScheduler::sync_supplier_schedule((int) $id);
        OrderScheduler::sync_supplier_schedule((int) $id);

        return rest_ensure_response(array(
            'success' => true,
            'updated' => (int) $result,
            'message' => ((int) $result > 0)
                ? 'Tedarikci bilgileri kaydedildi.'
                : 'Degisiklik yok. Kayitlar zaten guncel.',
        ));
    }

    public function run_manual_sync($request)
    {
        multi_sync_debug_log("API HIT: /sync/run");
        $supplier_id = (int) $request->get_param('supplier_id');
        $type = sanitize_key((string) $request->get_param('type'));
        $selected_items = $request->get_param('selected_items');

        multi_sync_debug_log("Params: Supplier: $supplier_id, Type: $type, Items: " . print_r($selected_items, true));

        try {
            if ('product' === $type) {
                multi_sync_debug_log("Run Manual Sync: Instantiating ProductImporter...");
                $importer = new ProductImporter();
                multi_sync_debug_log("Run Manual Sync: Calling run_sync...");
                $report = $importer->run_sync($supplier_id, is_array($selected_items) ? $selected_items : array());
                multi_sync_debug_log("Run Manual Sync: run_sync completed.");
                if (!empty($report['errors'])) {
                    return new \WP_Error('multi_sync_product_import_failed', implode('; ', $report['errors']), array('status' => 409, 'report' => $report));
                }
                return rest_ensure_response(array(
                    'success' => true,
                    'queued' => false,
                    'message' => empty($report['warnings'])
                        ? 'Urun senkronu tamamlandi'
                        : sprintf('Urun senkronu tamamlandi. %d cakisan urun guvenle atlandi.', (int) $report['skipped']),
                    'report' => $report,
                ));
            } elseif ('order' === $type) {
                $result = JobQueue::enqueue_order_import_job(
                    $supplier_id,
                    is_array($selected_items) ? $selected_items : array(),
                    'manual'
                );
                if (is_wp_error($result)) {
                    return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
                }

                return rest_ensure_response(array(
                    'success' => true,
                    'queued' => !empty($result['queued']),
                    'job_id' => isset($result['job_id']) ? (int) $result['job_id'] : 0,
                    'job_status' => isset($result['job_status']) ? (string) $result['job_status'] : 'queued',
                    'requires_approval' => !empty($result['requires_approval']),
                    'message' => 'Siparis import isi kuyruga alindi.',
                ));
            }

            return new \WP_Error('multi_sync_invalid_sync_type', 'Desteklenmeyen sync tipi.', array('status' => 400));
        } catch (\Throwable $e) {
            multi_sync_debug_log("Sync error: " . $e->getMessage());
            return new \WP_Error('multi_sync_sync_failed', $e->getMessage(), array('status' => 500));
        }
    }

    public function run_stock_price_sync($request)
    {
        $parsed = $this->parse_stock_price_request($request);
        if (is_wp_error($parsed)) {
            return new \WP_Error($parsed->get_error_code(), $parsed->get_error_message(), array('status' => 400));
        }

        $result = JobQueue::enqueue_stock_push_job(
            $parsed['supplier_id'],
            $parsed['stock_mode'],
            $parsed['selected_skus'],
            $parsed['runtime_sync'],
            'manual'
        );

        if (is_wp_error($result)) {
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => !empty($result['queued'])
                ? 'Stok/fiyat gonderimi kuyruga alindi.'
                : 'Degisiklik olmadigi icin islem olusturulmadi.',
            'result' => $result,
            'queued' => !empty($result['queued']),
            'reason' => isset($result['reason']) ? $result['reason'] : '',
            'job_id' => isset($result['job_id']) ? (int) $result['job_id'] : 0,
            'job_status' => isset($result['job_status']) ? (string) $result['job_status'] : '',
            'requires_approval' => !empty($result['requires_approval']),
        ));
    }

    public function preview_stock_price_sync($request)
    {
        $parsed = $this->parse_stock_price_request($request);
        if (is_wp_error($parsed)) {
            return new \WP_Error($parsed->get_error_code(), $parsed->get_error_message(), array('status' => 400));
        }

        $preview_result = StockSync::preview_for_supplier(
            $parsed['supplier_id'],
            array(),
            $parsed['selected_skus'],
            $parsed['stock_mode'],
            $parsed['runtime_sync']
        );

        if (is_wp_error($preview_result)) {
            return new \WP_Error($preview_result->get_error_code(), $preview_result->get_error_message(), array('status' => 400));
        }

        $items = array();
        $summary = array();
        $effective_mode = $parsed['stock_mode'];
        $effective_sync_stock = (bool) $parsed['runtime_sync']['sync_stock'];
        $effective_sync_price = (bool) $parsed['runtime_sync']['sync_price'];
        $warning = '';

        if (is_array($preview_result)) {
            $items = isset($preview_result['items']) && is_array($preview_result['items'])
                ? $preview_result['items']
                : array();
            $summary = isset($preview_result['summary']) && is_array($preview_result['summary'])
                ? $preview_result['summary']
                : array();
            if (isset($preview_result['mode']) && is_string($preview_result['mode'])) {
                $effective_mode = $preview_result['mode'];
            }
            if (array_key_exists('sync_stock', $preview_result)) {
                $effective_sync_stock = (bool) $preview_result['sync_stock'];
            }
            if (array_key_exists('sync_price', $preview_result)) {
                $effective_sync_price = (bool) $preview_result['sync_price'];
            }
            if (isset($preview_result['warning']) && is_string($preview_result['warning'])) {
                $warning = $preview_result['warning'];
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'items' => $items,
            'summary' => $summary,
            'mode' => $effective_mode,
            'sync_stock' => $effective_sync_stock,
            'sync_price' => $effective_sync_price,
            'warning' => $warning,
        ));
    }

    public function preview_sync($request)
    {
        multi_sync_debug_log("API HIT: /sync/preview");
        $supplier_id = $request->get_param('supplier_id');

        $supplier = (new Supplier())->get((int) $supplier_id);
        if (!$supplier || !$supplier->active) {
            return new \WP_Error('multi_sync_invalid_supplier', 'Supplier not found or inactive.', array('status' => 400));
        }

        try {
            multi_sync_debug_log("Instantiating ProductImporter...");
            $importer = new ProductImporter();
            multi_sync_debug_log("ProductImporter instantiated. Calling preview($supplier_id)...");
            $preview_data = $importer->preview($supplier_id);
            if (is_wp_error($preview_data)) {
                return new \WP_Error($preview_data->get_error_code(), $preview_data->get_error_message(), array('status' => 502));
            }

            $items = array();
            $stok_kodsuz = array();

            if (is_array($preview_data) && isset($preview_data['items'])) {
                $items = is_array($preview_data['items']) ? $preview_data['items'] : array();
                $stok_kodsuz = isset($preview_data['stok_kodsuz']) && is_array($preview_data['stok_kodsuz'])
                    ? $preview_data['stok_kodsuz']
                    : array();
            } elseif (is_array($preview_data)) {
                // Backward-compatible handling in case preview returns plain item list.
                $items = $preview_data;
            }

            multi_sync_debug_log("Preview Items Count: " . count($items));
            multi_sync_debug_log("Preview Stok Kodsuz Count: " . count($stok_kodsuz));

            return rest_ensure_response(array(
                'success' => true,
                'items' => $items,
                'stok_kodsuz' => $stok_kodsuz,
            ));
        } catch (\Exception $e) {
            multi_sync_debug_log("Preview Error: " . $e->getMessage());
            return new \WP_Error('preview_error', $e->getMessage(), array('status' => 500));
        } catch (\Error $e) {
            multi_sync_debug_log("Preview Fatal Error: " . $e->getMessage());
            return new \WP_Error('preview_fatal', $e->getMessage(), array('status' => 500));
        }
    }

    public function preview_product_publish($request)
    {
        $items = (new ProductPublisher())->preview((int) $request->get_param('supplier_id'));
        if (is_wp_error($items)) {
            return new \WP_Error($items->get_error_code(), $items->get_error_message(), array('status' => 400));
        }
        return rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function publish_products($request)
    {
        $selected = $request->get_param('selected_items');
        $overrides = $request->get_param('product_overrides');
        $result = (new ProductPublisher())->publish(
            (int) $request->get_param('supplier_id'),
            is_array($selected) ? $selected : array(),
            is_array($overrides) ? $overrides : array()
        );
        if (is_wp_error($result)) {
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400, 'details' => $result->get_error_data()));
        }
        return rest_ensure_response(array('success' => true, 'result' => $result));
    }

    public function get_trendyol_category_mappings($request)
    {
        return $this->get_marketplace_category_mappings($request);
    }

    public function get_marketplace_category_mappings($request)
    {
        $supplier_id = (int) $request->get_param('supplier_id');
        $context = $this->marketplace_context($supplier_id);
        if (is_wp_error($context)) {
            return $context;
        }
        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        if (is_wp_error($terms)) {
            return $terms;
        }
        $categories = array_map(function ($term) {
            return array('id' => (int) $term->term_id, 'name' => (string) $term->name);
        }, $terms);
        return rest_ensure_response(array(
            'success' => true,
            'woo_categories' => $categories,
            'woo_brands' => $this->woo_brand_terms(),
            'marketplace_label' => $context['adapter']->get_label(),
            'mappings' => $this->marketplace_category_mappings($supplier_id),
            'brand_mappings' => get_option('multi_sync_brand_mappings_' . $supplier_id, array()),
        ));
    }

    public function search_trendyol_categories($request)
    {
        return $this->search_marketplace_categories($request);
    }

    public function search_marketplace_categories($request)
    {
        $context = $this->marketplace_context((int) $request->get_param('supplier_id'));
        if (is_wp_error($context)) {
            return $context;
        }
        if (!is_callable(array($context['adapter'], 'fetch_product_categories'))) {
            return new \WP_Error('multi_sync_category_mapping_unsupported', $context['adapter']->get_label() . ' kategori eslemesi desteklenmiyor.', array('status' => 400));
        }
        $items = $context['adapter']->fetch_product_categories($context['supplier'], sanitize_text_field((string) $request->get_param('query')));
        return is_wp_error($items) ? $items : rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function get_trendyol_category_attributes($request)
    {
        return $this->get_marketplace_category_attributes($request);
    }

    public function get_marketplace_category_attributes($request)
    {
        $context = $this->marketplace_context((int) $request->get_param('supplier_id'));
        if (is_wp_error($context)) {
            return $context;
        }
        if (!is_callable(array($context['adapter'], 'fetch_category_attributes'))) {
            return new \WP_Error('multi_sync_category_attributes_unsupported', $context['adapter']->get_label() . ' kategori nitelikleri desteklenmiyor.', array('status' => 400));
        }
        $items = $context['adapter']->fetch_category_attributes($context['supplier'], sanitize_text_field((string) $request->get_param('category_id')));
        return is_wp_error($items) ? $items : rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function search_marketplace_brands($request)
    {
        $context = $this->marketplace_context((int) $request->get_param('supplier_id'));
        if (is_wp_error($context)) return $context;
        $query = sanitize_text_field((string) $request->get_param('query'));
        $category_id = sanitize_text_field((string) $request->get_param('category_id'));
        if (is_callable(array($context['adapter'], 'fetch_product_brands'))) {
            $items = $context['adapter']->fetch_product_brands($context['supplier'], $query, $category_id);
            return is_wp_error($items) ? $items : rest_ensure_response(array('success' => true, 'items' => $items));
        }
        return rest_ensure_response(array('success' => true, 'items' => $query === '' ? array() : array(array('id' => $query, 'name' => $query))));
    }

    public function save_marketplace_brand_mapping($request)
    {
        $supplier_id = (int) $request->get_param('supplier_id');
        $context = $this->marketplace_context($supplier_id);
        if (is_wp_error($context)) return $context;
        $woo_brand_key = sanitize_text_field((string) $request->get_param('woo_brand_key'));
        $valid_keys = array_column($this->woo_brand_terms(), 'key');
        if (!in_array($woo_brand_key, $valid_keys, true)) {
            return new \WP_Error('multi_sync_invalid_woo_brand', 'WooCommerce markasi gecersiz.', array('status' => 400));
        }
        $mappings = get_option('multi_sync_brand_mappings_' . $supplier_id, array());
        $brand_id = sanitize_text_field((string) $request->get_param('brand_id'));
        if ($brand_id === '') unset($mappings[$woo_brand_key]);
        else $mappings[$woo_brand_key] = array(
            'brand_id' => $brand_id,
            'brand_name' => sanitize_text_field((string) $request->get_param('brand_name')),
        );
        $option_name = 'multi_sync_brand_mappings_' . $supplier_id;
        update_option($option_name, $mappings, false);
        if (get_option($option_name, null) !== $mappings) {
            return new \WP_Error('multi_sync_mapping_save_failed', 'Marka eslemesi veritabanina kaydedilemedi.', array('status' => 500));
        }
        return rest_ensure_response(array('success' => true, 'brand_mappings' => $mappings));
    }

    public function save_trendyol_category_mapping($request)
    {
        return $this->save_marketplace_category_mapping($request);
    }

    public function save_marketplace_category_mapping($request)
    {
        $supplier_id = (int) $request->get_param('supplier_id');
        $context = $this->marketplace_context($supplier_id);
        if (is_wp_error($context)) {
            return $context;
        }
        $woo_category_id = (int) $request->get_param('woo_category_id');
        if (!$woo_category_id || !term_exists($woo_category_id, 'product_cat')) {
            return new \WP_Error('multi_sync_invalid_woo_category', 'WooCommerce kategorisi gecersiz.', array('status' => 400));
        }
        $mappings = $this->marketplace_category_mappings($supplier_id);
        $marketplace_category_id = sanitize_text_field((string) ($request->get_param('marketplace_category_id') ?: $request->get_param('trendyol_category_id')));
        if ($marketplace_category_id === '' || $marketplace_category_id === '0') {
            unset($mappings[$woo_category_id]);
        } else {
            $attributes = array();
            foreach ((array) $request->get_param('attributes') as $attribute) {
                if (!is_array($attribute) || empty($attribute['attributeId'])) {
                    continue;
                }
                $clean = array('attributeId' => sanitize_text_field((string) $attribute['attributeId']));
                if (!empty($attribute['attributeValueIds']) && is_array($attribute['attributeValueIds'])) {
                    $clean['attributeValueIds'] = array_values(array_filter(array_map('sanitize_text_field', $attribute['attributeValueIds'])));
                } elseif (isset($attribute['attributeValue']) && trim((string) $attribute['attributeValue']) !== '') {
                    $clean['attributeValue'] = sanitize_text_field($attribute['attributeValue']);
                }
                if (count($clean) > 1) {
                    $attributes[] = $clean;
                }
            }
            $definitions = array();
            foreach ((array) $request->get_param('attribute_definitions') as $definition) {
                if (!is_array($definition) || empty($definition['id'])) {
                    continue;
                }
                $values = array();
                foreach ((array) ($definition['values'] ?? array()) as $value) {
                    if (is_array($value) && !empty($value['id'])) {
                        $values[] = array('id' => sanitize_text_field((string) $value['id']), 'name' => sanitize_text_field((string) ($value['name'] ?? '')));
                    }
                }
                $definitions[] = array(
                    'id' => sanitize_text_field((string) $definition['id']),
                    'name' => sanitize_text_field((string) ($definition['name'] ?? '')),
                    'required' => !empty($definition['required']),
                    'slicer' => !empty($definition['slicer']),
                    'varianter' => !empty($definition['varianter']),
                    'allow_custom' => !empty($definition['allow_custom']),
                    'expects_language' => !empty($definition['expects_language']),
                    'values' => $values,
                );
            }
            $commission_raw = trim((string) $request->get_param('commission_rate'));
            if ($commission_raw !== '' && (!is_numeric($commission_raw) || (float) $commission_raw < 0 || (float) $commission_raw >= 100)) {
                return new \WP_Error('multi_sync_invalid_category_commission', 'Komisyon orani 0 ile 100 arasinda olmalidir.', array('status' => 400));
            }
            $mappings[$woo_category_id] = array(
                'category_id' => $marketplace_category_id,
                'category_name' => sanitize_text_field((string) ($request->get_param('marketplace_category_name') ?: $request->get_param('trendyol_category_name'))),
                'attributes' => $attributes,
                'attribute_definitions' => $definitions,
            );
            if ($commission_raw !== '') $mappings[$woo_category_id]['commission_rate'] = (float) $commission_raw;
        }
        $option_name = 'multi_sync_category_mappings_' . $supplier_id;
        update_option($option_name, $mappings, false);
        if (get_option($option_name, null) !== $mappings) {
            return new \WP_Error('multi_sync_mapping_save_failed', 'Kategori eslemesi veritabanina kaydedilemedi.', array('status' => 500));
        }
        return rest_ensure_response(array('success' => true, 'mappings' => $mappings));
    }

    private function marketplace_context($supplier_id)
    {
        $supplier = (new Supplier())->get((int) $supplier_id);
        if (!$supplier || !$supplier->active) {
            return new \WP_Error('multi_sync_invalid_marketplace_supplier', 'Aktif pazar yeri hesabi bulunamadi.', array('status' => 400));
        }
        return array('supplier' => $supplier, 'adapter' => (new MarketplaceManager())->for_supplier($supplier));
    }

    private function marketplace_category_mappings($supplier_id)
    {
        $mappings = get_option('multi_sync_category_mappings_' . (int) $supplier_id, null);
        return is_array($mappings) ? $mappings : get_option('multi_sync_trendyol_category_mappings_' . (int) $supplier_id, array());
    }

    private function woo_brand_terms()
    {
        $result = array();
        foreach (get_object_taxonomies('product') as $taxonomy) {
            $normalized = strtolower((string) $taxonomy);
            if (strpos($normalized, 'brand') === false && strpos($normalized, 'marka') === false) continue;
            $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
            if (is_wp_error($terms)) continue;
            foreach ($terms as $term) {
                $result[] = array('key' => $taxonomy . ':' . (int) $term->term_id, 'taxonomy' => $taxonomy, 'id' => (int) $term->term_id, 'name' => (string) $term->name);
            }
        }
        return $result;
    }

    public function preview_order_sync($request)
    {
        multi_sync_debug_log("API HIT: /sync/order-preview");
        $supplier_id = $request->get_param('supplier_id');

        $supplier = (new Supplier())->get((int) $supplier_id);
        if (!$supplier || !$supplier->active) {
            return new \WP_Error('multi_sync_invalid_supplier', 'Supplier not found or inactive.', array('status' => 400));
        }

        try {
            multi_sync_debug_log("Instantiating OrderImporter...");
            $importer = new OrderImporter();
            multi_sync_debug_log("OrderImporter instantiated. Calling preview($supplier_id)...");
            $items = $importer->preview($supplier_id);
            if (is_wp_error($items)) {
                return new \WP_Error($items->get_error_code(), $items->get_error_message(), array('status' => 502));
            }
            multi_sync_debug_log("Order Preview Items Count: " . count($items));

            return rest_ensure_response(array('success' => true, 'items' => $items));
        } catch (\Exception $e) {
            multi_sync_debug_log("Order Preview Error: " . $e->getMessage());
            return new \WP_Error('order_preview_error', $e->getMessage(), array('status' => 500));
        } catch (\Error $e) {
            multi_sync_debug_log("Order Preview Fatal Error: " . $e->getMessage());
            return new \WP_Error('order_preview_fatal', $e->getMessage(), array('status' => 500));
        }
    }

    public function get_marketplace_http_debug($request)
    {
        $supplier_id = (int) $request->get_param('supplier_id');
        $marketplace_key = sanitize_key((string) $request->get_param('marketplace_key'));
        $limit = max(1, min(100, (int) $request->get_param('limit')));
        if ($limit <= 0) {
            $limit = 20;
        }
        $operation = sanitize_text_field((string) $request->get_param('operation'));
        $status_code = (int) $request->get_param('status_code');

        if ($supplier_id > 0 && $marketplace_key === '') {
            $supplier_model = new Supplier();
            $supplier = $supplier_model->get($supplier_id);
            if ($supplier && !empty($supplier->marketplace_key)) {
                $marketplace_key = sanitize_key($supplier->marketplace_key);
            }
        }

        $entry = \MultiSync\Marketplaces\BaseMarketplace::get_last_http_debug($supplier_id, $marketplace_key);
        $history = \MultiSync\Marketplaces\BaseMarketplace::get_http_debug_history(
            $supplier_id,
            $marketplace_key,
            $limit,
            array(
                'operation' => $operation,
                'status_code' => $status_code,
            )
        );
        if (!$entry) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Debug kaydi bulunamadi. Once manuel onizleme veya senkron calistirin.',
                'history' => is_array($history) ? $history : array(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'entry' => $entry,
            'history' => is_array($history) ? $history : array(),
        ));
    }

    public function get_marketplace_raw_products($request)
    {
        $supplier_id = (int) $request->get_param('supplier_id');
        $page = max(0, (int) $request->get_param('page'));
        $size = (int) $request->get_param('size');
        $size = $size > 0 ? min(100, $size) : 20;

        if ($supplier_id <= 0) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'supplier_id gecersiz.',
            ));
        }

        $supplier_model = new Supplier();
        $supplier = $supplier_model->get($supplier_id);
        if (!$supplier) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Tedarikci bulunamadi.',
            ));
        }

        $manager = new MarketplaceManager();
        $adapter = $manager->for_supplier($supplier);
        if (!$adapter) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Pazar yeri adaptor bulunamadi.',
            ));
        }

        $raw_items = $adapter->fetch_products($supplier, array(
            'page' => $page,
            'size' => $size,
        ));

        if (is_wp_error($raw_items)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $raw_items->get_error_message(),
                'debug' => \MultiSync\Marketplaces\BaseMarketplace::get_last_http_debug($supplier_id, sanitize_key((string) $supplier->marketplace_key)),
            ));
        }

        if (!is_array($raw_items)) {
            $raw_items = array();
        }

        $items = array();
        $count = 0;
        foreach ($raw_items as $raw_item) {
            if ($count >= 30) {
                break;
            }
            $items[] = $this->sanitize_raw_debug_value($raw_item);
            $count++;
        }

        $first_item_keys = array();
        if (!empty($items) && is_array($items[0])) {
            $first_item_keys = array_slice(array_keys($items[0]), 0, 80);
        }

        return rest_ensure_response(array(
            'success' => true,
            'supplier_id' => $supplier_id,
            'marketplace_key' => sanitize_key((string) $supplier->marketplace_key),
            'page' => $page,
            'size' => $size,
            'total_items' => count($raw_items),
            'shown_items' => count($items),
            'first_item_keys' => $first_item_keys,
            'items' => $items,
        ));
    }

    public function get_sync_settings($request)
    {
        $supplier_id = $request->get_param('supplier_id');
        $model = new \MultiSync\Models\SyncSettings();
        $settings = $model->get($supplier_id);

        if (!$settings) {
            // Return defaults if no settings exist
            return rest_ensure_response((object) array(
                'sync_stock' => 0,
                'sync_price' => 0,
                'sync_products' => 0,
                'sync_orders' => 0,
                'stock_automation_mode' => 'scheduled',
                'schedule' => 'manual',
                'interval_minutes' => 5,
            ));
        }

        if (!isset($settings->stock_automation_mode) || !is_string($settings->stock_automation_mode)) {
            $settings->stock_automation_mode = 'scheduled';
        } else {
            $settings->stock_automation_mode = sanitize_key((string) $settings->stock_automation_mode);
            if (!in_array($settings->stock_automation_mode, array('scheduled', 'event_driven'), true)) {
                $settings->stock_automation_mode = 'scheduled';
            }
        }

        // Automatic price/product sync is disabled by design.
        $settings->sync_price = 0;
        $settings->sync_products = 0;

        return rest_ensure_response($settings);
    }

    public function save_sync_settings($request)
    {
        $params = $request->get_params();

        // Debug logging
        multi_sync_debug_log("save_sync_settings called");
        multi_sync_debug_log("save_sync_settings params: " . json_encode($params));

        if (!isset($params['supplier_id'])) {
            return rest_ensure_response(array('success' => false, 'message' => 'supplier_id eksik'));
        }

        $model = new \MultiSync\Models\SyncSettings();
        $result = $model->save($params['supplier_id'], $params);

        multi_sync_debug_log("save_sync_settings result: " . json_encode($result));

        StockScheduler::sync_supplier_schedule((int) $params['supplier_id']);
        OrderScheduler::sync_supplier_schedule((int) $params['supplier_id']);

        return rest_ensure_response(array('success' => true));
    }

    public function get_jobs($request)
    {
        $model = new SyncJob();
        $filters = array(
            'status' => sanitize_key((string) $request->get_param('status')),
            'job_type' => sanitize_key((string) $request->get_param('job_type')),
            'supplier_id' => (int) $request->get_param('supplier_id'),
            'date_from' => sanitize_text_field((string) $request->get_param('date_from')),
            'date_to' => sanitize_text_field((string) $request->get_param('date_to')),
            'page' => max(1, (int) $request->get_param('page')),
            'per_page' => (int) $request->get_param('per_page'),
        );

        $result = $model->list_jobs($filters);

        return rest_ensure_response(array(
            'success' => true,
            'items' => isset($result['items']) ? $result['items'] : array(),
            'pagination' => isset($result['pagination']) ? $result['pagination'] : array(),
        ));
    }

    public function get_job_detail($request)
    {
        $job_id = (int) $request->get_param('id');
        if ($job_id <= 0) {
            return rest_ensure_response(array('success' => false, 'message' => 'job_id gecersiz.'));
        }

        $job_model = new SyncJob();
        $item_model = new SyncJobItem();

        $job = $job_model->get($job_id);
        if (!$job) {
            return rest_ensure_response(array('success' => false, 'message' => 'Job bulunamadi.'));
        }

        $items = $item_model->get_by_job($job_id);

        return rest_ensure_response(array(
            'success' => true,
            'job' => $job,
            'items' => $items,
        ));
    }

    public function approve_job($request)
    {
        $job_id = (int) $request->get_param('id');
        $result = JobQueue::approve_job($job_id, get_current_user_id());
        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'job' => $result,
        ));
    }

    public function reject_job($request)
    {
        $job_id = (int) $request->get_param('id');
        $result = JobQueue::reject_job($job_id, get_current_user_id());
        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'job' => $result,
        ));
    }

    public function get_job_settings($request)
    {
        return rest_ensure_response(array(
            'success' => true,
            'settings' => JobQueue::get_settings(),
        ));
    }

    public function save_job_settings($request)
    {
        $settings = JobQueue::save_settings($request->get_params());

        return rest_ensure_response(array(
            'success' => true,
            'settings' => $settings,
        ));
    }

    public function get_changes($request)
    {
        $model = new SyncChangeHistory();
        $filters = array(
            'job_type' => sanitize_key((string) $request->get_param('job_type')),
            'supplier_id' => (int) $request->get_param('supplier_id'),
            'date_from' => sanitize_text_field((string) $request->get_param('date_from')),
            'date_to' => sanitize_text_field((string) $request->get_param('date_to')),
            'page' => max(1, (int) $request->get_param('page')),
            'per_page' => (int) $request->get_param('per_page'),
        );

        $result = $model->list_changes($filters);

        return rest_ensure_response(array(
            'success' => true,
            'items' => isset($result['items']) ? $result['items'] : array(),
            'pagination' => isset($result['pagination']) ? $result['pagination'] : array(),
        ));
    }

    public function get_questions($request)
    {
        $model = new MarketplaceQuestion();
        $filters = array(
            'supplier_id' => (int) $request->get_param('supplier_id'),
            'marketplace_key' => sanitize_key((string) $request->get_param('marketplace_key')),
            'status' => sanitize_text_field((string) $request->get_param('status')),
            'search' => sanitize_text_field((string) $request->get_param('search')),
            'page' => max(1, (int) $request->get_param('page')),
            'per_page' => (int) $request->get_param('per_page'),
        );

        $result = $model->list_questions($filters);
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
        $auto_refresh_summary = array();

        $should_auto_refresh = empty($items)
            && ((int) $filters['page'] === 1)
            && empty($filters['search'])
            && empty($filters['status']);

        if ($should_auto_refresh) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }

            $service = new QuestionSync();
            $summary = $service->refresh_questions($filters['supplier_id'] > 0 ? (int) $filters['supplier_id'] : 0);
            if (is_array($summary)) {
                $auto_refresh_summary = $summary;
            }
            $result = $model->list_questions($filters);
        }

        return rest_ensure_response(array(
            'success' => true,
            'items' => isset($result['items']) ? $result['items'] : array(),
            'pagination' => isset($result['pagination']) ? $result['pagination'] : array(),
            'auto_refresh' => $auto_refresh_summary,
        ));
    }

    public function refresh_questions($request)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }

        $supplier_id = (int) $request->get_param('supplier_id');

        $service = new QuestionSync();
        $summary = $service->refresh_questions($supplier_id > 0 ? $supplier_id : 0);

        if (is_wp_error($summary)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $summary->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'summary' => is_array($summary) ? $summary : array(),
        ));
    }

    public function reply_question($request)
    {
        $id = (int) $request->get_param('id');
        $answer_text = trim((string) $request->get_param('answer_text'));

        if ($id <= 0) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Gecersiz soru id.',
            ));
        }

        $service = new QuestionSync();
        $item = $service->reply_to_question($id, $answer_text, get_current_user_id());
        if (is_wp_error($item)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $item->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Yanit basariyla gonderildi.',
            'item' => $item,
        ));
    }

    private function normalize_manual_stock_price_runtime_sync($runtime_sync)
    {
        $normalized = array(
            'sync_stock' => true,
            'sync_price' => false,
        );

        if (is_array($runtime_sync)) {
            if (array_key_exists('sync_stock', $runtime_sync)) {
                $normalized['sync_stock'] = (bool) $runtime_sync['sync_stock'];
            }
            if (array_key_exists('sync_price', $runtime_sync)) {
                $normalized['sync_price'] = (bool) $runtime_sync['sync_price'];
            }
        }

        // Manual endpoint should not be blocked by automation flags being both false.
        if (!$normalized['sync_stock'] && !$normalized['sync_price']) {
            $normalized['sync_stock'] = true;
        }

        return $normalized;
    }

    private function parse_stock_price_request($request)
    {
        $supplier_id = (int) $request->get_param('supplier_id');
        if (!$supplier_id) {
            return new \WP_Error('multi_sync_missing_supplier_id', 'supplier_id eksik');
        }

        $stock_mode = sanitize_key((string) $request->get_param('stock_mode'));
        if ($stock_mode === '') {
            $stock_mode = 'marketplace_match';
        }

        $selected_skus = array();
        $selected_items = $request->get_param('selected_items');
        if (is_array($selected_items)) {
            foreach ($selected_items as $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $sku = trim((string) sanitize_text_field((string) $value));
                if ($sku !== '') {
                    $selected_skus[] = $sku;
                }
            }
        }

        $runtime_sync = array();
        $request_sync_stock = $request->get_param('sync_stock');
        $request_sync_price = $request->get_param('sync_price');

        if ($request_sync_stock !== null) {
            $runtime_sync['sync_stock'] = rest_sanitize_boolean($request_sync_stock);
        }
        if ($request_sync_price !== null) {
            $runtime_sync['sync_price'] = rest_sanitize_boolean($request_sync_price);
        }

        return array(
            'supplier_id' => $supplier_id,
            'stock_mode' => $stock_mode,
            'selected_skus' => $selected_skus,
            'runtime_sync' => $this->normalize_manual_stock_price_runtime_sync($runtime_sync),
        );
    }

    private function mask_sensitive_params($params)
    {
        if (!is_array($params)) {
            return $params;
        }

        $masked = $params;
        $sensitive_keys = array('api_key', 'api_secret', 'amazon_refresh_token', 'ptt_rest_api_key', 'ptt_access_token');
        foreach ($sensitive_keys as $key) {
            if (isset($masked[$key])) {
                $masked[$key] = $this->mask_sensitive_value($masked[$key]);
            }
        }

        return $masked;
    }

    private function mask_sensitive_value($value)
    {
        $value = (string) $value;
        $len = strlen($value);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 3) . str_repeat('*', max(3, $len - 5)) . substr($value, -2);
    }

    private function sanitize_raw_debug_value($value, $depth = 0)
    {
        if ($depth > 4) {
            return '[max_depth]';
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $sanitized = array();
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 80) {
                    $sanitized['__truncated__'] = 'key_limit';
                    break;
                }
                $sanitized[(string) $key] = $this->sanitize_raw_debug_value($item, $depth + 1);
                $count++;
            }
            return $sanitized;
        }

        if (is_string($value)) {
            $len = strlen($value);
            if ($len > 1200) {
                return substr($value, 0, 1200) . '...<truncated:' . $len . '>';
            }
            return $value;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }
}
