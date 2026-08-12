<?php

namespace MultiSync\Sync;

use MultiSync\Marketplaces\MarketplaceManager;
use MultiSync\Models\Supplier;

if (!defined('ABSPATH')) {
    exit;
}

class ProductPublisher
{
    public function preview($supplier_id)
    {
        $context = $this->context($supplier_id);
        if (is_wp_error($context)) {
            return $context;
        }

        $catalog = $this->fetch_catalog_products($context);
        $catalog_products = is_array($catalog) ? $catalog : array();
        $catalog_error = is_wp_error($catalog) ? $catalog->get_error_message() : '';

        $items = array();
        foreach ($this->products() as $product) {
            $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;
            $variation_attributes = $product->is_type('variation') ? (array) $product->get_attributes() : array();
            $variation_attribute_labels = array();
            foreach (array_keys($variation_attributes) as $attribute_name) {
                $variation_attribute_labels[$attribute_name] = function_exists('wc_attribute_label')
                    ? wc_attribute_label($attribute_name, $parent)
                    : $attribute_name;
            }
            $mapping = $this->product_mapping($product, $context);
            $variation_target_options = array();
            foreach ((array) ($mapping['attribute_definitions'] ?? array()) as $definition) {
                if (is_array($definition) && !empty($definition['id']) && (!empty($definition['slicer']) || !empty($definition['varianter']))) {
                    $variation_target_options[] = array('id' => (string) $definition['id'], 'name' => (string) ($definition['name'] ?? $definition['id']));
                }
            }
            $payload = $this->build_payload($context['adapter'], $product, $mapping);
            $error_data = is_wp_error($payload) ? $payload->get_error_data() : array();
            $upload_action = 'upload';
            if ($this->is_published($product, $context, $catalog_products)) {
                $upload_action = 'update';
                $catalog_item = $this->catalog_item_for_product($product, $catalog_products);
                if ($catalog_item !== null && is_callable(array($context['adapter'], 'build_price_inventory_item_from_product'))) {
                    $price_item = $context['adapter']->build_price_inventory_item_from_product($product, true, true, $mapping['commission_rate'] ?? null);
                    if (is_array($price_item) && $this->product_unchanged($price_item, $catalog_item)) {
                        $upload_action = 'unchanged';
                    }
                }
            }
            $catalog_data = $upload_action !== 'upload' ? $catalog_item : null;
            $catalog_comparison = null;
            if ($catalog_data !== null && is_array($catalog_data)) {
                $catalog_comparison = $this->comparison_before_after($product, $catalog_data, $price_item ?? null);
            }
            $items[] = array(
                'selection_key' => (string) $product->get_id(),
                'sku' => (string) $product->get_sku(),
                'name' => is_callable(array($context['adapter'], 'product_export_name')) ? $context['adapter']->product_export_name($product, $parent) : (string) $product->get_name(),
                'category_names' => $this->product_category_names($product),
                'upload_action' => $upload_action,
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'stock_quantity' => $product->get_stock_quantity(),
                'category_commission_rate' => $mapping['commission_rate'] ?? null,
                'preview_image' => wp_get_attachment_url($product->get_image_id()) ?: '',
                'can_import' => !is_wp_error($payload),
                'preview_warning' => is_wp_error($payload) ? $payload->get_error_message() : '',
                'missing_fields' => is_array($error_data) && isset($error_data['fields']) ? $error_data['fields'] : array(),
                'catalog_comparison' => $catalog_comparison,
                'row_type' => $product->is_type('variation') ? 'variation' : 'simple',
                'variation_parent_key' => $parent ? ((string) $parent->get_sku() ?: (string) $parent->get_id()) : '',
                'variation_parent_name' => $parent ? (string) $parent->get_name() : '',
                'variation_attributes' => $variation_attributes,
                'variation_attribute_options' => array_keys($variation_attributes),
                'variation_attribute_labels' => $variation_attribute_labels,
                'variation_target_options' => $variation_target_options,
                'attribute_fields' => $this->attribute_fields($mapping),
            );
        }

        return array(
            'items' => $items,
            'catalog_products' => count($catalog_products),
            'catalog_error' => $catalog_error,
        );
    }

    private function product_category_names($product)
    {
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $product = $parent;
            }
        }
        $names = array();
        foreach ((array) $product->get_category_ids() as $category_id) {
            $term = get_term((int) $category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $names[] = (string) $term->name;
            }
        }
        return array_values(array_unique($names));
    }

    public function publish($supplier_id, $product_ids = array(), $overrides = array())
    {
        $context = $this->context($supplier_id);
        if (is_wp_error($context)) {
            return $context;
        }

        $adapter = $context['adapter'];
        $supplier = $context['supplier'];
        $marketplace_key = (string) $context['marketplace_key'];
        if ($marketplace_key === 'n11') {
            $product_ids = $this->expand_variation_product_ids($product_ids, $overrides);
        }
        $supports_update = is_callable(array($adapter, 'build_price_inventory_item_from_product'))
            && is_callable(array($adapter, 'push_price_inventory_updates'));

        $catalog = $this->fetch_catalog_products($context);
        $catalog_products = is_array($catalog) ? $catalog : array();

        $create_payloads = array();
        $product_update_payloads = array();
        $update_payloads = array();
        $create_ids = array();
        $update_ids = array();
        $skipped = array();
        $unchanged = 0;
        foreach ($this->products($product_ids) as $product) {
            $product_overrides = isset($overrides[$product->get_id()]) && is_array($overrides[$product->get_id()])
                ? $overrides[$product->get_id()]
                : array();
            $mapping = $this->product_mapping($product, $context);

            if ($marketplace_key === 'n11' && $this->is_published($product, $context, $catalog_products)
                && is_callable(array($adapter, 'build_product_update_item')) && is_callable(array($adapter, 'push_product_updates'))) {
                $payload = $this->build_payload($adapter, $product, $mapping, $product_overrides);
                if (is_wp_error($payload)) {
                    $skipped[] = array('id' => $product->get_id(), 'message' => $payload->get_error_message());
                    continue;
                }
                $product_update_payloads[] = $adapter->build_product_update_item($payload);
                $update_ids[] = (int) $product->get_id();
                continue;
            }

            if ($supports_update && !$product_overrides && $this->is_published($product, $context, $catalog_products)) {
                $item = $adapter->build_price_inventory_item_from_product($product, true, true, isset($mapping['commission_rate']) ? $mapping['commission_rate'] : null);
                if (is_wp_error($item)) {
                    $skipped[] = array('id' => $product->get_id(), 'message' => $item->get_error_message());
                    continue;
                }
                if ($item) {
                    $catalog_item = $this->catalog_item_for_product($product, $catalog_products);
                    if ($catalog_item !== null && $this->product_unchanged($item, $catalog_item)) {
                        $unchanged++;
                        continue;
                    }
                    $update_payloads[] = $item;
                    $update_ids[] = (int) $product->get_id();
                    continue;
                }
            }

            $payload = $this->build_payload($adapter, $product, $mapping, $product_overrides);
            if (is_wp_error($payload)) {
                $skipped[] = array('id' => $product->get_id(), 'message' => $payload->get_error_message());
                continue;
            }
            $create_payloads[] = $payload;
            $create_ids[] = (int) $product->get_id();
        }

        $total = count($create_payloads) + count($product_update_payloads) + count($update_payloads);
        // ponytail: one marketplace batch per action; add chunked jobs when a store needs more than 1,000 publishable products.
        if ($total > 1000) {
            return new \WP_Error('multi_sync_product_batch_too_large', 'Tek seferde en fazla 1000 urun gonderilebilir.');
        }

        if ($total === 0) {
            return new \WP_Error('multi_sync_no_publishable_products', 'Gonderilebilir urun bulunamadi.', array('skipped' => $skipped));
        }

        $uploaded = 0;
        $updated = 0;
        $response = null;

        if (!empty($create_payloads)) {
            $result = $adapter->push_products($supplier, $create_payloads);
            if (is_wp_error($result)) {
                return $result;
            }
            $uploaded = count($create_payloads);
            $response = $result;
        }

        if (!empty($update_payloads)) {
            $result = $adapter->push_price_inventory_updates($supplier, $update_payloads);
            if (is_wp_error($result)) {
                return $result;
            }
            $updated = count($update_payloads);
            if ($response === null) {
                $response = $result;
            }
            $this->mark_published($update_ids, $marketplace_key);
        }

        if (!empty($product_update_payloads)) {
            $result = $adapter->push_product_updates($supplier, $product_update_payloads);
            if (is_wp_error($result)) {
                return $result;
            }
            $task_id = trim((string) ($result['id'] ?? ''));
            if ($task_id !== '' && $this->confirm_batch_status($adapter, $supplier, $task_id, 'n11') === 'failed') {
                return new \WP_Error('multi_sync_n11_batch_failed', 'n11 urun guncelleme kuyrugu islemi reddetti (batch ' . $task_id . ').');
            }
            $updated += count($product_update_payloads);
            $response = $response === null ? $result : $response;
            $this->mark_published($update_ids, $marketplace_key);
        }

        $batch_status = '';
        if (in_array($marketplace_key, array('ciceksepeti', 'n11'), true) && !empty($create_payloads)
            && is_callable(array($adapter, 'get_batch_request_result')) && is_array($response)) {
            $batch_id = trim((string) ($response['batchId'] ?? $response['batchRequestId'] ?? $response['id'] ?? ''));
            if ($batch_id !== '') {
                $batch_status = $this->confirm_batch_status($adapter, $supplier, $batch_id, $marketplace_key);
                if ($batch_status === 'completed') {
                    $this->mark_published($create_ids, $marketplace_key);
                } elseif ($batch_status === 'failed') {
                    $this->unmark_published($create_ids, $marketplace_key);
                    if (is_callable(array($adapter, 'clear_request_cache'))) {
                        $adapter->clear_request_cache($supplier);
                    }
                    return new \WP_Error(
                        'multi_sync_' . $marketplace_key . '_batch_failed',
                        $adapter->get_label() . ' islem kuyrugu urunleri reddetti (batch ' . $batch_id . ').',
                        array('batch_id' => $batch_id)
                    );
                } elseif ($batch_status === 'pending' && $marketplace_key === 'ciceksepeti') {
                    self::schedule_batch_poll((int) $supplier_id, $batch_id, $create_ids);
                }
            }
        }

        return array(
            'sent' => $uploaded + $updated,
            'uploaded' => $uploaded,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'response' => $response,
            'batch_status' => $batch_status,
        );
    }

    private function confirm_batch_status($adapter, $supplier, $batch_id, $marketplace_key = '')
    {
        $data = $adapter->get_batch_request_result($supplier, $batch_id);
        if (is_wp_error($data)) {
            return 'pending';
        }
        return $marketplace_key === 'n11' ? self::n11_batch_verdict($data) : self::ciceksepeti_batch_verdict($data);
    }

    public static function n11_batch_verdict($data)
    {
        $status = strtoupper((string) ($data['status'] ?? ''));
        $json = strtoupper((string) json_encode($data['skus']['content'] ?? $data));
        if ($status === 'REJECT' || strpos($json, '"STATUS":"FAIL"') !== false) return 'failed';
        if ($status === 'PROCESSED' && strpos($json, '"STATUS":"SUCCESS"') !== false) return 'completed';
        return 'pending';
    }

    public static function ciceksepeti_batch_verdict($data)
    {
        $json = strtolower((string) json_encode($data));
        if (strpos($json, 'failed') !== false || strpos($json, '"success":false') !== false
            || strpos($json, '"severity":"error"') !== false || strpos($json, 'missing_info') !== false
            || strpos($json, 'rejected') !== false) {
            return 'failed';
        }
        if (strpos($json, 'processing') !== false || strpos($json, 'pending') !== false || strpos($json, 'in_progress') !== false) {
            return 'pending';
        }
        if (strpos($json, 'completed') !== false || strpos($json, 'success') !== false || strpos($json, 'succeeded') !== false) {
            return 'completed';
        }
        return 'pending';
    }

    public static function schedule_batch_poll($supplier_id, $batch_id, $product_ids = array())
    {
        $args = array((int) $supplier_id, (string) $batch_id, array_map('absint', (array) $product_ids));
        if (!wp_next_scheduled('multi_sync_ciceksepeti_batch_poll', $args)) {
            wp_schedule_single_event(time() + 70, 'multi_sync_ciceksepeti_batch_poll', $args);
        }
    }

    public static function ciceksepeti_batch_poll_cron($supplier_id, $batch_id, $product_ids = array())
    {
        $batch_id = (string) $batch_id;
        if ($batch_id === '' || !function_exists('get_option')) {
            return;
        }

        // ponytail: 60 attempts x ~70s = ~70 min window; batches taking longer stop here and stay unmarked for a manual re-check/re-send.
        $attempt_key = 'multi_sync_cs_batch_poll_' . $batch_id;
        $attempts = (int) get_option($attempt_key, 0) + 1;
        if ($attempts > 60) {
            delete_option($attempt_key);
            self::store_batch_status($batch_id, 'timeout', 'Ciceksepeti batch ' . $batch_id . ' hala Pending; islemi tekrar deneyin.');
            return;
        }
        update_option($attempt_key, $attempts, false);

        $supplier = (new Supplier())->get((int) $supplier_id);
        if (!$supplier) {
            return;
        }
        $adapter = (new MarketplaceManager())->for_supplier($supplier);
        if (!$adapter || !is_callable(array($adapter, 'get_batch_request_result'))) {
            return;
        }

        $data = $adapter->get_batch_request_result($supplier, $batch_id);
        if (is_wp_error($data)) {
            self::store_batch_status($batch_id, 'error', 'Ciceksepeti batch ' . $batch_id . ' durumu okunamadi: ' . $data->get_error_message());
            self::schedule_batch_poll((int) $supplier_id, $batch_id, $product_ids);
            return;
        }

        $verdict = self::ciceksepeti_batch_verdict($data);
        if ($verdict === 'completed') {
            delete_option($attempt_key);
            $publisher = new self();
            $publisher->mark_published(array_map('absint', (array) $product_ids), 'ciceksepeti');
            self::store_batch_status($batch_id, 'completed', 'Ciceksepeti batch ' . $batch_id . ' tamamlandi.');
            if (class_exists('\MultiSync\Models\SyncLog')) {
                (new \MultiSync\Models\SyncLog())->log((int) $supplier_id, 'ciceksepeti_batch', 'success', 'Ciceksepeti batch tamamlandi: ' . $batch_id);
            }
            return;
        }

        if ($verdict === 'failed') {
            delete_option($attempt_key);
            $publisher = new self();
            $publisher->unmark_published(array_map('absint', (array) $product_ids), 'ciceksepeti');
            if (is_callable(array($adapter, 'clear_request_cache'))) {
                $adapter->clear_request_cache($supplier);
            }
            $message = 'Ciceksepeti batch ' . $batch_id . ' hata ile bitti: ' . self::ciceksepeti_error_summary($data);
            self::store_batch_status($batch_id, 'failed', $message);
            if (class_exists('\MultiSync\Models\SyncLog')) {
                (new \MultiSync\Models\SyncLog())->log((int) $supplier_id, 'ciceksepeti_batch', 'error', $message);
            }
            return;
        }

        self::store_batch_status($batch_id, 'pending', 'Ciceksepeti batch ' . $batch_id . ' hala isleniyor (kontrol #' . $attempts . ').');
        self::schedule_batch_poll((int) $supplier_id, $batch_id, $product_ids);
    }

    private static function store_batch_status($batch_id, $status, $message)
    {
        set_transient(
            'multi_sync_cs_batch_status_' . $batch_id,
            array(
                'status' => (string) $status,
                'message' => (string) $message,
                'checked_at' => current_time('mysql'),
            ),
            WEEK_IN_SECONDS
        );
    }

    public static function ciceksepeti_error_summary($data)
    {
        if (is_wp_error($data)) {
            return $data->get_error_message();
        }
        $json = json_encode($data);
        if (!is_string($json) || strlen($json) > 2000) {
            $json = substr((string) $json, 0, 2000);
        }
        return $json;
    }

    private function is_published($product, $context, $catalog_products = array())
    {
        $key = (string) ($context['marketplace_key'] ?? '');
        if ($key !== '' && $this->has_published_meta($product, $key, $context)) {
            return true;
        }
        if ($product && $product->is_type('variation')) {
            $parent = is_callable(array($product, 'get_parent_id')) ? wc_get_product($product->get_parent_id()) : null;
            if ($parent && $key !== '' && $this->has_published_meta($parent, $key, $context)) {
                return true;
            }
            if ($parent && $this->catalog_has_product($parent, $catalog_products)) {
                return true;
            }
        }
        return $this->catalog_has_product($product, $catalog_products);
    }

    private function fetch_catalog_products($context)
    {
        if (!is_callable(array($context['adapter'], 'fetch_products'))) {
            return array();
        }
        return StockSync::fetch_marketplace_products_by_sku((int) ($context['supplier_id'] ?? 0), $context['supplier'], $context['adapter'], false);
    }

    private function catalog_has_product($product, $catalog_products)
    {
        if (empty($catalog_products) || !$product || !is_callable(array($product, 'get_meta'))) {
            return false;
        }
        return $this->catalog_item_for_product($product, $catalog_products) !== null;
    }

    private function catalog_item_for_product($product, $catalog_products)
    {
        if (empty($catalog_products) || !$product || !is_callable(array($product, 'get_meta'))) {
            return null;
        }
        foreach ($this->product_aliases($product) as $alias) {
            $normalized = StockSync::normalize_sku_key($alias);
            if ($normalized !== '' && isset($catalog_products[$normalized])) {
                return $catalog_products[$normalized];
            }
        }
        return null;
    }

    private function product_aliases($product)
    {
        $aliases = array();
        if (is_callable(array($product, 'get_sku'))) {
            $aliases[] = (string) $product->get_sku();
        }
        foreach (array('_multi_sync_external_sku', '_multi_sync_external_barcode', '_multi_sync_external_product_id') as $meta_key) {
            $value = trim((string) $product->get_meta($meta_key, true));
            if ($value !== '') {
                $aliases[] = $value;
            }
        }
        return $aliases;
    }

    private function product_unchanged($item, $catalog_item)
    {
        if (!is_array($item) || !is_array($catalog_item)) {
            return false;
        }

        $item_regular = isset($item['listPrice']) ? $item['listPrice'] : (isset($item['price']) ? $item['price'] : null);
        $item_sale = isset($item['salePrice']) ? $item['salePrice'] : null;
        $catalog_regular = isset($catalog_item['regular_price']) ? $catalog_item['regular_price'] : null;
        if ($item_regular !== null && $catalog_regular !== null && !$this->numbers_equal($item_regular, $catalog_regular)) {
            return false;
        }

        $catalog_sale = isset($catalog_item['sale_price']) && trim((string) $catalog_item['sale_price']) !== ''
            ? $catalog_item['sale_price']
            : null;
        $has_sale = $item_sale !== null && $item_regular !== null && (float) $item_sale < (float) $item_regular;
        if ($has_sale && ($catalog_sale === null || !$this->numbers_equal($item_sale, $catalog_sale))) {
            return false;
        }
        if (!$has_sale && $catalog_sale !== null) {
            return false;
        }

        $item_stock = isset($item['quantity']) ? $item['quantity'] : (isset($item['stock']) ? $item['stock'] : (isset($item['availableStock']) ? $item['availableStock'] : null));
        $catalog_stock = isset($catalog_item['stock_quantity']) ? $catalog_item['stock_quantity'] : null;
        if ($item_stock !== null && $catalog_stock !== null && (int) $item_stock !== (int) $catalog_stock) {
            return false;
        }

        return true;
    }

    private function numbers_equal($left, $right)
    {
        return abs((float) $left - (float) $right) < 0.005;
    }

    private function comparison_before_after($product, $catalog_item, $price_item = null)
    {
        if (!$product || !is_callable(array($product, 'get_regular_price'))) {
            return null;
        }
        $stock = $product->get_stock_quantity();
        if ($stock === null || $stock === '') $stock = 0;

        $regular = null;
        $sale = null;
        if (is_array($price_item)) {
            $item_regular = isset($price_item['listPrice']) && is_numeric($price_item['listPrice']) ? (float) $price_item['listPrice'] : null;
            $item_sale = isset($price_item['salePrice']) && is_numeric($price_item['salePrice']) ? (float) $price_item['salePrice'] : null;
            if ($item_regular === null && isset($price_item['price']) && is_numeric($price_item['price'])) {
                $item_regular = (float) $price_item['price'];
            }
            if ($item_regular !== null) {
                $regular = $item_regular;
                if ($item_sale !== null && $item_sale < $item_regular) {
                    $sale = $item_sale;
                }
            }
        }
        if ($regular === null) {
            $regular = is_numeric($product->get_regular_price()) ? (float) $product->get_regular_price() : 0.0;
            $sale_raw = is_callable(array($product, 'get_sale_price')) && is_numeric($product->get_sale_price()) ? (float) $product->get_sale_price() : 0.0;
            $sale = $sale_raw > 0 && $sale_raw < $regular ? $sale_raw : 0.0;
        }

        $catalog_regular = isset($catalog_item['regular_price']) && is_numeric($catalog_item['regular_price']) ? (float) $catalog_item['regular_price'] : null;
        $catalog_sale = isset($catalog_item['sale_price']) && is_numeric($catalog_item['sale_price']) && (float) $catalog_item['sale_price'] > 0 ? (float) $catalog_item['sale_price'] : null;
        $catalog_stock = isset($catalog_item['stock_quantity']) ? (int) $catalog_item['stock_quantity'] : null;

        $price_changed = $catalog_regular === null || !$this->numbers_equal($regular, $catalog_regular)
            || ($sale > 0 && ($catalog_sale === null || !$this->numbers_equal($sale, $catalog_sale)))
            || ($sale <= 0 && $catalog_sale !== null);
        $stock_changed = $catalog_stock === null || (int) $stock !== $catalog_stock;

        return array(
            'price_before' => $catalog_regular !== null ? number_format($catalog_regular, 2, '.', '') : '-',
            'price_after' => number_format($regular, 2, '.', ''),
            'sale_price_before' => $catalog_sale !== null ? number_format($catalog_sale, 2, '.', '') : '-',
            'sale_price_after' => $sale > 0 ? number_format($sale, 2, '.', '') : '-',
            'stock_before' => $catalog_stock !== null ? (string) $catalog_stock : '-',
            'stock_after' => (string) (int) $stock,
            'price_changed' => $price_changed,
            'stock_changed' => $stock_changed,
        );
    }

    private function has_published_meta($product, $key, $context)
    {
        if (!$product || !is_callable(array($product, 'get_meta'))) {
            return false;
        }
        $flag = trim((string) $product->get_meta('_multi_sync_published_' . $key, true));
        if ($flag !== '') {
            if ($key === 'ciceksepeti' && strpos($flag, 'confirmed:') !== 0) {
                return false;
            }
            return true;
        }
        $marketplace = function_exists('sanitize_key') ? sanitize_key((string) $product->get_meta('_multi_sync_marketplace_key', true)) : strtolower(trim((string) $product->get_meta('_multi_sync_marketplace_key', true)));
        if ($marketplace === '' || $marketplace !== (function_exists('sanitize_key') ? sanitize_key($key) : strtolower($key))) {
            return false;
        }
        $supplier_id = (int) ($context['supplier_id'] ?? 0);
        if ($supplier_id > 0 && (int) $product->get_meta('_multi_sync_supplier_id', true) !== $supplier_id) {
            return false;
        }
        $external = trim((string) ($product->get_meta('_multi_sync_external_barcode', true) ?: $product->get_meta('_multi_sync_external_sku', true)));
        return $external !== '';
    }

    private function mark_published($product_ids, $marketplace_key)
    {
        $key = (string) $marketplace_key;
        if ($key === '' || !function_exists('current_time')) {
            return;
        }
        $stamp = current_time('mysql');
        foreach (array_map('absint', (array) $product_ids) as $product_id) {
            if ($product_id <= 0) {
                continue;
            }
            $product = wc_get_product($product_id);
            if ($product && is_callable(array($product, 'update_meta_data')) && is_callable(array($product, 'save'))) {
                $product->update_meta_data('_multi_sync_published_' . $key, ($key === 'ciceksepeti' ? 'confirmed:' : '') . $stamp);
                $product->save();
            }
        }
    }

    private function unmark_published($product_ids, $marketplace_key)
    {
        $key = (string) $marketplace_key;
        if ($key === '') {
            return;
        }
        foreach (array_map('absint', (array) $product_ids) as $product_id) {
            if ($product_id <= 0) {
                continue;
            }
            $product = wc_get_product($product_id);
            if ($product && is_callable(array($product, 'delete_meta_data')) && is_callable(array($product, 'save'))) {
                $product->delete_meta_data('_multi_sync_published_' . $key);
                $product->save();
            }
        }
    }

    private function context($supplier_id)
    {
        $supplier = (new Supplier())->get((int) $supplier_id);
        if (!$supplier || !$supplier->active) {
            return new \WP_Error('multi_sync_invalid_marketplace_supplier', 'Aktif pazar yeri hesabi bulunamadi.');
        }

        $adapter = (new MarketplaceManager())->for_supplier($supplier);
        if (!is_callable(array($adapter, 'build_product_item_from_product')) || !is_callable(array($adapter, 'push_products'))) {
            return new \WP_Error('multi_sync_product_publish_unsupported', $adapter->get_label() . ' urun gonderimi henuz desteklenmiyor.');
        }

        $mapping_suffix = is_callable(array($adapter, 'mapping_option_suffix')) ? $adapter->mapping_option_suffix($supplier) : '';
        $mappings = get_option('multi_sync_category_mappings_' . (int) $supplier_id . $mapping_suffix, null);
        if (!is_array($mappings) && $mapping_suffix === '') {
            $mappings = get_option('multi_sync_trendyol_category_mappings_' . (int) $supplier_id, array());
        }
        if (!is_array($mappings)) $mappings = array();

        $commission_rates = array();
        if (is_callable(array($adapter, 'fetch_category_commission_rates'))) {
            $fetched_rates = $adapter->fetch_category_commission_rates($supplier);
            if (is_array($fetched_rates)) $commission_rates = $fetched_rates;
        }

        return array(
            'supplier' => $supplier,
            'adapter' => $adapter,
            'supplier_id' => (int) $supplier_id,
            'marketplace_key' => is_callable(array($adapter, 'get_key')) ? $adapter->get_key() : '',
            'mappings' => $mappings,
            'brand_mappings' => get_option('multi_sync_brand_mappings_' . (int) $supplier_id . $mapping_suffix, array()),
            'commission_rates' => $commission_rates,
        );
    }

    private function product_mapping($product, $context)
    {
        $mapping = $this->category_mapping($product, $context['mappings']);
        if ($context['marketplace_key'] === 'n11') {
            $mapping['shipment_template'] = trim((string) ($context['supplier']->n11_shipment_template ?? ''));
        }
        $category_id = (string) ($mapping['category_id'] ?? '');
        if ($category_id !== '' && !array_key_exists('commission_rate', $mapping) && isset($context['commission_rates'][$category_id])) {
            $mapping['commission_rate'] = (float) $context['commission_rates'][$category_id];
        }
        $mapping = array_merge($mapping, $this->brand_mapping($product, $context['brand_mappings']));
        if ($product->is_type('variation') && empty($mapping['brand_id']) && empty($mapping['brand_name'])) {
            $parent = wc_get_product($product->get_parent_id());
            $brand = $parent ? $this->woo_product_brand($parent) : '';
            if ($brand !== '') $mapping['brand_name'] = $brand;
        }
        return $mapping;
    }

    private function attribute_fields($mapping)
    {
        $matched = array();
        foreach ((array) ($mapping['attributes'] ?? array()) as $attribute) {
            $id = (string) ($attribute['attributeId'] ?? '');
            if ($id !== '') $matched[$id] = (string) (($attribute['attributeValueIds'][0] ?? null) ?: ($attribute['attributeValue'] ?? ''));
        }
        $fields = array();
        foreach ((array) ($mapping['attribute_definitions'] ?? array()) as $definition) {
            $id = (string) ($definition['id'] ?? '');
            if ($id === '') continue;
            $value = $matched[$id] ?? '';
            $label = $value;
            foreach ((array) ($definition['values'] ?? array()) as $option) {
                if ((string) ($option['id'] ?? '') === $value) $label = (string) ($option['name'] ?? $value);
            }
            $fields[] = array(
                'key' => 'attribute_' . $id,
                'label' => (string) ($definition['name'] ?? $id),
                'type' => !empty($definition['values']) ? 'select' : 'text',
                'options' => array_values((array) ($definition['values'] ?? array())),
                'matched_value' => $value,
                'matched_label' => $label,
            );
        }
        return $fields;
    }

    private function woo_product_brand($product)
    {
        foreach (array_keys((array) $product->get_attributes()) as $name) {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $product) : $name;
            $normalized = mb_strtolower((string) $label, 'UTF-8');
            if (strpos($normalized, 'marka') === false && strpos($normalized, 'brand') === false) continue;
            $value = trim((string) $product->get_attribute($name));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function build_payload($adapter, $product, $mapping, $overrides = array())
    {
        if (empty($mapping['category_id'])) {
            return new \WP_Error('multi_sync_category_mapping_required', 'WooCommerce kategorisini pazar yeri kategorisiyle esleyin.');
        }
        return $adapter->build_product_item_from_product($product, $mapping, $overrides);
    }

    private function brand_mapping($product, $mappings)
    {
        if (!function_exists('wp_get_object_terms')) return array();
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $product = $parent;
        }
        foreach (get_object_taxonomies('product') as $taxonomy) {
            $normalized = strtolower((string) $taxonomy);
            if (strpos($normalized, 'brand') === false && strpos($normalized, 'marka') === false) continue;
            foreach ((array) wp_get_object_terms($product->get_id(), $taxonomy) as $term) {
                $key = $taxonomy . ':' . (int) $term->term_id;
                if (isset($mappings[$key]) && is_array($mappings[$key])) return $mappings[$key];
            }
        }
        return array();
    }

    private function category_mapping($product, $mappings)
    {
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $product = $parent;
            }
        }
        $best = array();
        $best_depth = -1;
        foreach ((array) $product->get_category_ids() as $category_id) {
            foreach (array_merge(array((int) $category_id), get_ancestors((int) $category_id, 'product_cat')) as $candidate_id) {
                if (isset($mappings[$candidate_id]) && is_array($mappings[$candidate_id])) {
                    $depth = count(get_ancestors((int) $candidate_id, 'product_cat'));
                    if ($depth > $best_depth) {
                        $best = $mappings[$candidate_id];
                        $best_depth = $depth;
                    }
                }
            }
        }
        return $best;
    }

    private function products($product_ids = array())
    {
        $ids = array_values(array_filter(array_map('absint', (array) $product_ids)));
        if ($ids) {
            return array_values(array_filter(array_map('wc_get_product', $ids), function ($product) {
                return $product && ($product->is_type('simple') || $product->is_type('variation'));
            }));
        }

        $products = array();
        foreach ((new \WC_Product_Query(array('status' => 'publish', 'type' => array('simple', 'variable'), 'limit' => 1001, 'return' => 'objects')))->get_products() as $product) {
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $products[] = $variation;
                    }
                }
            } else {
                $products[] = $product;
            }
        }
        return $products;
    }

    private function expand_variation_product_ids($product_ids, &$overrides = array())
    {
        $expanded = array();
        $family_overrides = array();
        foreach (array_filter(array_map('absint', (array) $product_ids)) as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->is_type('variation')) {
                if (!empty($overrides[$product_id])) {
                    $family_overrides[$product->get_parent_id()] = array_intersect_key($overrides[$product_id], array_flip(array('variation_attribute', 'variation_target_attribute_id')));
                }
                $product = wc_get_product($product->get_parent_id());
            }
            if ($product && $product->is_type('variable')) {
                $expanded = array_merge($expanded, $product->get_children());
                foreach ($product->get_children() as $variation_id) {
                    $overrides[$variation_id] = ($overrides[$variation_id] ?? array()) + ($family_overrides[$product->get_id()] ?? array());
                }
            } else {
                $expanded[] = $product_id;
            }
        }
        return array_values(array_unique(array_map('absint', $expanded)));
    }
}
