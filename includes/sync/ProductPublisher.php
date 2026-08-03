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
            $items[] = array(
                'selection_key' => (string) $product->get_id(),
                'sku' => (string) $product->get_sku(),
                'name' => (string) $product->get_name(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'stock_quantity' => $product->get_stock_quantity(),
                'category_commission_rate' => $mapping['commission_rate'] ?? null,
                'preview_image' => wp_get_attachment_url($product->get_image_id()) ?: '',
                'can_import' => !is_wp_error($payload),
                'preview_warning' => is_wp_error($payload) ? $payload->get_error_message() : '',
                'missing_fields' => is_array($error_data) && isset($error_data['fields']) ? $error_data['fields'] : array(),
                'row_type' => $product->is_type('variation') ? 'variation' : 'simple',
                'variation_parent_key' => $parent ? ((string) $parent->get_sku() ?: (string) $parent->get_id()) : '',
                'variation_parent_name' => $parent ? (string) $parent->get_name() : '',
                'variation_attributes' => $variation_attributes,
                'variation_attribute_options' => array_keys($variation_attributes),
                'variation_attribute_labels' => $variation_attribute_labels,
                'variation_target_options' => $variation_target_options,
            );
        }

        return $items;
    }

    public function publish($supplier_id, $product_ids = array(), $overrides = array())
    {
        $context = $this->context($supplier_id);
        if (is_wp_error($context)) {
            return $context;
        }

        $payloads = array();
        $skipped = array();
        foreach ($this->products($product_ids) as $product) {
            $product_overrides = isset($overrides[$product->get_id()]) && is_array($overrides[$product->get_id()])
                ? $overrides[$product->get_id()]
                : array();
            $payload = $this->build_payload($context['adapter'], $product, $this->product_mapping($product, $context), $product_overrides);
            if (is_wp_error($payload)) {
                $skipped[] = array('id' => $product->get_id(), 'message' => $payload->get_error_message());
                continue;
            }
            $payloads[] = $payload;
        }

        // ponytail: one marketplace batch; add chunked jobs when a store needs more than 1,000 publishable products.
        if (count($payloads) > 1000) {
            return new \WP_Error('multi_sync_product_batch_too_large', 'Tek seferde en fazla 1000 urun gonderilebilir.');
        }

        if (empty($payloads)) {
            return new \WP_Error('multi_sync_no_publishable_products', 'Gonderilebilir urun bulunamadi.', array('skipped' => $skipped));
        }

        $result = $context['adapter']->push_products($context['supplier'], $payloads);
        if (is_wp_error($result)) {
            return $result;
        }

        return array('sent' => count($payloads), 'skipped' => $skipped, 'response' => $result);
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

        $mappings = get_option('multi_sync_category_mappings_' . (int) $supplier_id, null);
        if (!is_array($mappings)) {
            $mappings = get_option('multi_sync_trendyol_category_mappings_' . (int) $supplier_id, array());
        }

        $commission_rates = array();
        if (is_callable(array($adapter, 'fetch_category_commission_rates'))) {
            $fetched_rates = $adapter->fetch_category_commission_rates($supplier);
            if (is_array($fetched_rates)) $commission_rates = $fetched_rates;
        }

        return array(
            'supplier' => $supplier,
            'adapter' => $adapter,
            'mappings' => $mappings,
            'brand_mappings' => get_option('multi_sync_brand_mappings_' . (int) $supplier_id, array()),
            'commission_rates' => $commission_rates,
        );
    }

    private function product_mapping($product, $context)
    {
        $mapping = $this->category_mapping($product, $context['mappings']);
        $category_id = (string) ($mapping['category_id'] ?? '');
        if ($category_id !== '' && !array_key_exists('commission_rate', $mapping) && isset($context['commission_rates'][$category_id])) {
            $mapping['commission_rate'] = (float) $context['commission_rates'][$category_id];
        }
        return array_merge($mapping, $this->brand_mapping($product, $context['brand_mappings']));
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
}
