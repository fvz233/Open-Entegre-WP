<?php

namespace MultiSync\Sync;

use MultiSync\Marketplaces\MarketplaceManager;
use MultiSync\Models\Supplier;
use MultiSync\Models\SyncLog;

if (!defined('ABSPATH')) {
    exit;
}

class OrderImporter
{

    private $supplier_model;
    private $marketplace_manager;
    private $log_model;

    public function __construct()
    {
        $this->supplier_model = new Supplier();
        $this->marketplace_manager = new MarketplaceManager();
        $this->log_model = new SyncLog();
    }

    /**
     * Preview orders from API without importing.
     * 
     * @param int $supplier_id
     * @return array Array of order preview data
     */
    public function preview($supplier_id)
    {
        multi_sync_debug_log("OrderImporter::preview - Starting for supplier_id: $supplier_id");

        $supplier = $this->supplier_model->get($supplier_id);
        if (!$supplier || !$supplier->active) {
            multi_sync_debug_log("OrderImporter::preview - Supplier not found or inactive");
            return array();
        }

        $marketplace = $this->marketplace_manager->for_supplier($supplier);
        if (!$marketplace) {
            multi_sync_debug_log("OrderImporter::preview - Marketplace adapter not found");
            return array();
        }

        $items = $this->fetch_all_orders($marketplace, $supplier);
        if (is_wp_error($items)) {
            multi_sync_debug_log("OrderImporter::preview - API Error: " . $items->get_error_message());
            return $items;
        }

        multi_sync_debug_log("OrderImporter::preview - Extracted " . count($items) . " items from response");

        if (empty($items)) {
            multi_sync_debug_log("OrderImporter::preview - No items extracted.");
            return array();
        }

        $preview_items = array();
        foreach ($items as $item) {
            $order_data = $marketplace->map_order($item);

            $external_id = isset($order_data['external_id']) ? $order_data['external_id'] : (isset($order_data['id']) ? $order_data['id'] : null);
            $already_imported = $external_id ? $this->order_exists($external_id, $supplier_id, $supplier, false) : false;

            $preview_items[] = array(
                'external_id' => $external_id,
                'status' => isset($order_data['status']) ? $order_data['status'] : 'pending',
                'currency' => isset($order_data['currency']) ? $order_data['currency'] : '',
                'total' => isset($order_data['total']) ? $order_data['total'] : '0.00',
                'customer_email' => isset($order_data['billing_email']) ? $order_data['billing_email'] : '',
                'customer_name' => trim((isset($order_data['billing_first_name']) ? $order_data['billing_first_name'] : '') . ' ' . (isset($order_data['billing_last_name']) ? $order_data['billing_last_name'] : '')),
                'line_items_count' => isset($order_data['line_items']) && is_array($order_data['line_items']) ? count($order_data['line_items']) : 0,
                'already_imported' => $already_imported ? true : false,
                'raw_data' => $order_data
            );
        }

        multi_sync_debug_log("OrderImporter::preview - Returning " . count($preview_items) . " preview items");
        return $preview_items;
    }

    /**
     * Run the order sync process.
     * 
     * @param int $supplier_id
     * @param array $selected_items Optional array of external IDs to import
     */
    public function run_sync($supplier_id, $selected_items = array())
    {
        return $this->run_sync_with_report($supplier_id, $selected_items, array(
            'log' => true,
            'strict' => false,
        ));
    }

    /**
     * Run order sync and return a structured report for queue/history usage.
     *
     * @param int $supplier_id
     * @param array $selected_items
     * @param array $options Supported keys: log(bool), strict(bool)
     * @return array|\WP_Error
     */
    public function run_sync_with_report($supplier_id, $selected_items = array(), $options = array())
    {
        $log_enabled = !isset($options['log']) || (bool) $options['log'];
        $strict = !empty($options['strict']);

        $report = array(
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'changes' => array(),
            'errors' => array(),
        );

        $supplier = $this->supplier_model->get($supplier_id);
        if (!$supplier || !$supplier->active) {
            $message = 'Supplier not found or inactive.';
            if ($strict) {
                return new \WP_Error('multi_sync_invalid_supplier', $message);
            }
            return $report;
        }

        $marketplace = $this->marketplace_manager->for_supplier($supplier);
        if (!$marketplace) {
            $message = 'Marketplace adapter not found.';
            if ($log_enabled) {
                $this->log_model->log($supplier_id, 'order_import', 'error', $message);
            }
            if ($strict) {
                return new \WP_Error('multi_sync_marketplace_not_found', $message);
            }
            $report['errors'][] = $message;
            return $report;
        }

        if ($log_enabled) {
            $this->log_model->log($supplier_id, 'order_import', 'info', 'Starting Order Import via marketplace adapter');
        }

        $items = $this->fetch_all_orders($marketplace, $supplier);
        if (is_wp_error($items)) {
            $message = 'API Error: ' . $items->get_error_message();
            if ($log_enabled) {
                $this->log_model->log($supplier_id, 'order_import', 'error', $message);
            }
            if ($strict) {
                return new \WP_Error('multi_sync_order_fetch_error', $message);
            }
            $report['errors'][] = $message;
            return $report;
        }

        if (empty($items)) {
            if ($log_enabled) {
                $this->log_model->log($supplier_id, 'order_import', 'warning', 'Empty response from API.');
            }
            return $report;
        }

        foreach ($items as $item) {
            $order_data = $marketplace->map_order($item);

            $external_id = isset($order_data['external_id']) ? $order_data['external_id'] : (isset($order_data['id']) ? $order_data['id'] : null);

            // Filter by selected items if provided
            if (!empty($selected_items) && !in_array($external_id, $selected_items)) {
                continue;
            }

            if ($external_id) {
                // Add supplier identifiers for later display/styling
                $order_data['_created_via'] = isset($supplier->name) ? $supplier->name : 'MultiSync';
                $order_data['_multi_sync_supplier_id'] = $supplier_id;
                multi_sync_debug_log("OrderImporter::run_sync - Setting created_via to: " . $order_data['_created_via']);

                $existing_id = $this->order_exists($external_id, $supplier_id, $supplier, true);
                if ($existing_id) {
                    $before_order = wc_get_order($existing_id);
                    $before_status = $this->extract_order_status_for_report($before_order);
                    $before_meta = $this->extract_order_meta_for_report($before_order);

                    if ($this->update_order($existing_id, $order_data)) {
                        $report['updated']++;
                        $after_order = wc_get_order($existing_id);
                        $report['changes'][] = array(
                            'external_id' => (string) $external_id,
                            'order_id' => (int) $existing_id,
                            'status' => 'updated',
                            'before_status' => $before_status,
                            'after_status' => $this->extract_order_status_for_report($after_order),
                            'before_meta' => $before_meta,
                            'after_meta' => $this->extract_order_meta_for_report($after_order),
                            'message' => 'Order updated.',
                        );

                        do_action('multi_sync_order_imported', (int) $supplier_id, (int) $existing_id, $order_data);
                    } else {
                        $error_message = "Order update failed for external_id {$external_id} (order_id: {$existing_id})";
                        if ($log_enabled) {
                            $this->log_model->log(
                                $supplier_id,
                                'order_import',
                                'error',
                                $error_message
                            );
                        }
                        $report['failed']++;
                        $report['errors'][] = $error_message;
                        $report['changes'][] = array(
                            'external_id' => (string) $external_id,
                            'order_id' => (int) $existing_id,
                            'status' => 'failed',
                            'before_status' => $before_status,
                            'after_status' => $before_status,
                            'before_meta' => $before_meta,
                            'after_meta' => $before_meta,
                            'message' => $error_message,
                        );
                    }
                    continue;
                }

                $created_id = $this->create_order($order_data);
                if ($created_id) {
                    $report['created']++;
                    $created_order = wc_get_order($created_id);
                    $report['changes'][] = array(
                        'external_id' => (string) $external_id,
                        'order_id' => (int) $created_id,
                        'status' => 'created',
                        'before_status' => '',
                        'after_status' => $this->extract_order_status_for_report($created_order),
                        'before_meta' => array(),
                        'after_meta' => $this->extract_order_meta_for_report($created_order),
                        'message' => 'Order created.',
                    );

                    do_action('multi_sync_order_imported', (int) $supplier_id, (int) $created_id, $order_data);
                } else {
                    $error_message = "Order create failed for external_id {$external_id}";
                    if ($log_enabled) {
                        $this->log_model->log($supplier_id, 'order_import', 'error', $error_message);
                    }
                    $report['failed']++;
                    $report['errors'][] = $error_message;
                    $report['changes'][] = array(
                        'external_id' => (string) $external_id,
                        'order_id' => 0,
                        'status' => 'failed',
                        'before_status' => '',
                        'after_status' => '',
                        'before_meta' => array(),
                        'after_meta' => array(),
                        'message' => $error_message,
                    );
                }
            }
        }

        if ($log_enabled) {
            $this->log_model->log(
                $supplier_id,
                'order_import',
                'success',
                sprintf(
                    'Processed orders: Created %d, Updated %d, Failed %d.',
                    (int) $report['created'],
                    (int) $report['updated'],
                    (int) $report['failed']
                )
            );
        }

        return $report;
    }

    /**
     * Check if an order with the given external ID already exists.
     * @return int|false Order ID if exists, false otherwise
     */
    private function order_exists($external_id, $supplier_id, $supplier, $backfill_legacy)
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_multi_sync_external_id' 
            AND meta_value = %s
        ", $external_id));

        $hpos_table = $wpdb->prefix . 'wc_orders_meta';
        if ($wpdb->get_var("SHOW TABLES LIKE '$hpos_table'") === $hpos_table) {
            $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare("
                SELECT order_id FROM {$hpos_table} 
                WHERE meta_key = '_multi_sync_external_id' 
                AND meta_value = %s
            ", $external_id)));
        }

        $legacy = array();
        foreach (array_unique(array_map('intval', $ids)) as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            $owner = (int) $order->get_meta('_multi_sync_supplier_id', true);
            if ($owner === (int) $supplier_id) {
                return $order_id;
            }
            if ($owner === 0 && is_object($supplier) && (string) $order->get_created_via() === (string) $supplier->name) {
                $legacy[] = $order_id;
            }
        }
        $same_origin_suppliers = array_filter($this->supplier_model->get_all(), static function ($candidate) use ($supplier) {
            return is_object($candidate) && isset($candidate->name) && (string) $candidate->name === (string) $supplier->name;
        });
        if (count($legacy) === 1 && count($same_origin_suppliers) === 1) {
            if ($backfill_legacy) {
                $order = wc_get_order($legacy[0]);
                $order->update_meta_data('_multi_sync_supplier_id', (int) $supplier_id);
                $order->save();
            }
            return $legacy[0];
        }
        return false;
    }

    private function fetch_all_orders($marketplace, $supplier)
    {
        $all = array();
        $seen = array();
        for ($page = 0; $page < 80; $page++) {
            $items = $marketplace->fetch_orders($supplier, array('page' => $page, 'size' => 200));
            if (is_wp_error($items)) {
                return $items;
            }
            if (!is_array($items) || empty($items)) {
                break;
            }
            $cursor = sha1(wp_json_encode($items));
            if (isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
            $all = array_merge($all, $items);
        }
        return $all;
    }

    /**
     * Create a WooCommerce order from mapped data.
     */
    private function create_order($data)
    {
        if (!function_exists('wc_create_order')) {
            return false;
        }

        $args = array();
        if (isset($data['_created_via'])) {
            $args['created_via'] = $data['_created_via'];
        }
        $order = wc_create_order($args);

        if (is_wp_error($order)) {
            if (function_exists('multi_sync_debug_log')) {
                multi_sync_debug_log("OrderImporter: Failed to create order - " . $order->get_error_message());
            }
            return false;
        }

        // Store external ID for duplicate prevention
        if (isset($data['external_id'])) {
            $order->update_meta_data('_multi_sync_external_id', $data['external_id']);
        }

        // Store supplier id for color lookup (more reliable than string matching)
        if (isset($data['_multi_sync_supplier_id'])) {
            $order->update_meta_data('_multi_sync_supplier_id', (int) $data['_multi_sync_supplier_id']);
        }

        // Set origin/created via
        if (isset($data['_created_via'])) {
            $order->set_created_via($data['_created_via']);
        }

        // Set billing address
        $billing_fields = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone');
        $billing_address = array();
        foreach ($billing_fields as $field) {
            $key = 'billing_' . $field;
            if (isset($data[$key])) {
                $billing_address[$field] = $data[$key];
            }
        }
        if (!empty($billing_address)) {
            $order->set_address($billing_address, 'billing');
        }

        // Set shipping address
        $shipping_fields = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country');
        $shipping_address = array();
        foreach ($shipping_fields as $field) {
            $key = 'shipping_' . $field;
            if (isset($data[$key])) {
                $shipping_address[$field] = $data[$key];
            }
        }
        if (!empty($shipping_address)) {
            $order->set_address($shipping_address, 'shipping');
        }

        // Add line items
        if (isset($data['line_items']) && is_array($data['line_items'])) {
            foreach ($data['line_items'] as $line_item) {
                $this->add_line_item($order, $line_item, isset($data['_multi_sync_supplier_id']) ? (int) $data['_multi_sync_supplier_id'] : 0);
            }
        }

        // Set order status
        if (isset($data['status'])) {
            $supplier_id = isset($data['_multi_sync_supplier_id']) ? $data['_multi_sync_supplier_id'] : null;
            $status = $this->map_order_status($data['status'], $supplier_id);
            $order->set_status($status);
        }

        // Set payment method
        if (isset($data['payment_method'])) {
            $order->set_payment_method($data['payment_method']);
        }
        if (isset($data['payment_method_title'])) {
            $order->set_payment_method_title($data['payment_method_title']);
        }

        // Add customer note
        if (isset($data['customer_note'])) {
            $order->set_customer_note($data['customer_note']);
        }

        // Set order date (date_created) from API if provided
        if (isset($data['order_date']) && !empty($data['order_date'])) {
            $order_date = $data['order_date'];
            // Handle timestamp (milliseconds or seconds)
            if (is_numeric($order_date)) {
                // If it's in milliseconds (13+ digits), convert to seconds
                if (strlen((string) $order_date) >= 13) {
                    $order_date = (int) ($order_date / 1000);
                }
                $order->set_date_created($order_date);
            } else {
                // Handle string date format
                $timestamp = strtotime($order_date);
                if ($timestamp) {
                    $order->set_date_created($timestamp);
                }
            }
        }

        // Set date paid if provided
        if (isset($data['date_paid']) && !empty($data['date_paid'])) {
            $date_paid = $data['date_paid'];
            if (is_numeric($date_paid)) {
                if (strlen((string) $date_paid) >= 13) {
                    $date_paid = (int) ($date_paid / 1000);
                }
                $order->set_date_paid($date_paid);
            } else {
                $timestamp = strtotime($date_paid);
                if ($timestamp) {
                    $order->set_date_paid($timestamp);
                }
            }
        }

        // Calculate totals and save
        $order->calculate_totals();
        $order->save();

        // Ensure stock is reduced for imported paid/active statuses.
        $this->maybe_reduce_stock_for_imported_order($order);

        if (function_exists('multi_sync_debug_log')) {
            multi_sync_debug_log("OrderImporter: Created order #" . $order->get_id() . " (External ID: " . (isset($data['external_id']) ? $data['external_id'] : 'N/A') . ")");
        }

        return $order->get_id();
    }

    /**
     * Add a line item to the order.
     */
    private function add_line_item($order, $item_data, $supplier_id = 0)
    {
        $item_data = (array) $item_data;

        $sku = isset($item_data['sku']) ? trim((string) $item_data['sku']) : '';
        $mapped_id = isset($item_data['product_id']) ? intval($item_data['product_id']) : 0;
        $quantity = isset($item_data['quantity']) ? intval($item_data['quantity']) : 1;
        $price = isset($item_data['price']) ? floatval($item_data['price']) : 0;
        $name = isset($item_data['name']) ? $item_data['name'] : '';

        $product_id = 0;

        $product_id = $this->resolve_product_id_by_external_identifiers($item_data, $supplier_id);

        // External identifiers are authoritative; Woo SKU is the compatibility fallback.
        if (!empty($sku)) {
            $product_id = $product_id ?: $this->resolve_product_id_by_sku($sku);
        }

        // 2. Fallback to mapped ID if valid
        if (!$product_id && $mapped_id > 0) {
            $product_id = $mapped_id;
        }

        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $item = new \WC_Order_Item_Product();
                $item->set_product($product);
                $item->set_quantity($quantity);

                // Use provided price if available, otherwise use product price
                if ($price > 0) {
                    $item->set_subtotal($price * $quantity);
                    $item->set_total($price * $quantity);
                } else {
                    $product_price = floatval($product->get_price());
                    if ($product_price > 0) {
                        $item->set_subtotal($product_price * $quantity);
                        $item->set_total($product_price * $quantity);
                    }
                }

                $order->add_item($item);
                return true;
            }
        }

        if (function_exists('multi_sync_debug_log') && !empty($sku)) {
            multi_sync_debug_log("OrderImporter: Could not match product by SKU '{$sku}', adding generic line item.");
        }

        // If product not found, create a generic line item
        if (!empty($name) || !empty($sku)) {
            $item = new \WC_Order_Item_Product();
            $item->set_name($name ?: ('SKU: ' . $sku));
            $item->set_quantity($quantity);
            $item->set_subtotal($price * $quantity);
            $item->set_total($price * $quantity);
            $order->add_item($item);
            return true;
        }

        return false;
    }

    /**
     * Resolve product ID by SKU with normalization and case-insensitive DB fallback.
     */
    private function resolve_product_id_by_sku($sku)
    {
        if (!function_exists('wc_get_product_id_by_sku')) {
            return 0;
        }

        $sku = trim((string) $sku);
        if ($sku === '') {
            return 0;
        }

        $product_id = (int) wc_get_product_id_by_sku($sku);
        if ($product_id > 0) {
            return $product_id;
        }

        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
             AND LOWER(pm.meta_value) = LOWER(%s)
             AND p.post_type IN ('product', 'product_variation')
             AND p.post_status IN ('publish', 'private')
             LIMIT 1",
            $sku
        );

        return (int) $wpdb->get_var($sql);
    }

    private function resolve_product_id_by_external_identifiers($item_data, $supplier_id)
    {
        if ($supplier_id <= 0) {
            return 0;
        }
        $identifiers = array(
            '_multi_sync_external_barcode' => $item_data['external_barcode'] ?? '',
            '_multi_sync_external_sku' => $item_data['external_sku'] ?? ($item_data['sku'] ?? ''),
            '_multi_sync_external_product_id' => $item_data['external_product_id'] ?? '',
        );
        foreach ($identifiers as $meta_key => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $ids = get_posts(array(
                'post_type' => array('product', 'product_variation'), 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 2,
                'meta_query' => array(
                    array('key' => '_multi_sync_supplier_id', 'value' => (int) $supplier_id),
                    array('key' => $meta_key, 'value' => (string) $value),
                ),
            ));
            if (count($ids) === 1) {
                return (int) $ids[0];
            }
        }
        return 0;
    }

    /**
     * Trigger WooCommerce stock reduction after imported order save if status requires it.
     */
    private function maybe_reduce_stock_for_imported_order($order)
    {
        if (!$order || !is_callable(array($order, 'get_status'))) {
            return;
        }

        $reducible_statuses = array('processing', 'completed', 'on-hold');
        if (!in_array($order->get_status(), $reducible_statuses, true)) {
            return;
        }

        if (function_exists('wc_maybe_reduce_stock_levels')) {
            wc_maybe_reduce_stock_levels($order->get_id());
            return;
        }

        if (function_exists('wc_reduce_stock_levels')) {
            wc_reduce_stock_levels($order->get_id());
        }
    }

    /**
     * Update an existing WooCommerce order.
     */
    private function update_order($order_id, $data)
    {
        if (!function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Set order status
        if (isset($data['status'])) {
            $supplier_id = isset($data['_multi_sync_supplier_id']) ? $data['_multi_sync_supplier_id'] : null;
            $status = $this->map_order_status($data['status'], $supplier_id);
            if ($order->get_status() !== $status) {
                $order->set_status($status, "Updated via Order Sync. Old Status: " . $order->get_status());
            }
        }

        // Add customer note
        if (isset($data['customer_note'])) {
            $order->set_customer_note($data['customer_note']);
        }

        // Keep external ID meta in sync for duplicate detection.
        if (isset($data['external_id'])) {
            $order->update_meta_data('_multi_sync_external_id', (string) $data['external_id']);
        }

        // Update origin/created via if set.
        if (isset($data['_created_via'])) {
            if (method_exists($order, 'set_created_via')) {
                $order->set_created_via($data['_created_via']);
            }
            $order->update_meta_data('_created_via', $data['_created_via']);
        }

        // Update supplier id meta
        if (isset($data['_multi_sync_supplier_id'])) {
            $order->update_meta_data('_multi_sync_supplier_id', (int) $data['_multi_sync_supplier_id']);
        }

        $order->save();
        $this->maybe_reduce_stock_for_imported_order($order);

        if (function_exists('multi_sync_debug_log')) {
            multi_sync_debug_log("OrderImporter: Updated order #$order_id");
        }

        return true;
    }

    private function extract_order_status_for_report($order)
    {
        if (!$order || !is_callable(array($order, 'get_status'))) {
            return '';
        }

        return (string) $order->get_status();
    }

    private function extract_order_meta_for_report($order)
    {
        if (!$order) {
            return array();
        }

        $meta = array(
            'external_id' => '',
            'created_via' => '',
            'supplier_id' => 0,
            'customer_note' => '',
        );

        if (is_callable(array($order, 'get_meta'))) {
            $meta['external_id'] = (string) $order->get_meta('_multi_sync_external_id', true);
            $meta['created_via'] = (string) $order->get_meta('_created_via', true);
            $meta['supplier_id'] = (int) $order->get_meta('_multi_sync_supplier_id', true);
        }

        if (is_callable(array($order, 'get_created_via'))) {
            $created_via = (string) $order->get_created_via();
            if ($created_via !== '') {
                $meta['created_via'] = $created_via;
            }
        }

        if (is_callable(array($order, 'get_customer_note'))) {
            $meta['customer_note'] = (string) $order->get_customer_note();
        }

        return $meta;
    }

    /**
     * Map external order status to WooCommerce status.
     * 
     * @param string $external_status
     * @param int|null $supplier_id
     */
    private function map_order_status($external_status, $supplier_id = null)
    {
        $external_status = strtolower(trim($external_status));

        // 1. Try custom mappings from DB if supplier_id is provided
        if ($supplier_id) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'multi_sync_field_mappings';
            // Check if table exists to avoid errors on old installs
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                // Fetch all order_status mappings for this supplier
                // We fetch all and filter in PHP to avoid case sensitivity issues in SQL depending on collation
                $custom_mappings = $wpdb->get_results($wpdb->prepare(
                    "SELECT api_field, wc_field FROM $table_name WHERE supplier_id = %d AND type = 'order_status'",
                    $supplier_id
                ));

                if ($custom_mappings) {
                    foreach ($custom_mappings as $mapping) {
                        if (strtolower(trim($mapping->api_field)) === $external_status) {
                            return $mapping->wc_field;
                        }
                    }
                }
            }
        }

        // 2. Check Custom Statuses (Registered via Settings)
        $custom_statuses = get_option('multi_sync_custom_statuses', array());
        if (!empty($custom_statuses) && is_array($custom_statuses)) {
            foreach ($custom_statuses as $status) {
                $slug = isset($status['slug']) ? $status['slug'] : '';
                $label = isset($status['label']) ? $status['label'] : '';

                // Check against slug (e.g. wc-delivered)
                if ($slug && $slug === $external_status) {
                    return $slug;
                }

                // Check against label (e.g. Delivered)
                if ($label && strtolower($label) === $external_status) {
                    return $slug;
                }

                // Check against slug without wc- prefix
                $slug_no_prefix = str_replace('wc-', '', $slug);
                if ($slug_no_prefix && $slug_no_prefix === $external_status) {
                    return $slug;
                }
            }
        }

        // 3. Fallback to default hardcoded map
        $status_map = array(

            'pending' => 'pending',
            'created' => 'pending',
            'processing' => 'processing',
            'picking' => 'processing',
            'on-hold' => 'on-hold',
            'on_hold' => 'on-hold',
            'completed' => 'completed',
            'complete' => 'completed',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'unsupplied' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
            'shipped' => 'completed',
            'delivered' => 'completed',
            'unpacked' => 'processing',
            'paid' => 'processing',
            'new' => 'pending',
            'open' => 'processing',
            'closed' => 'completed',
        );

        return isset($status_map[$external_status]) ? $status_map[$external_status] : 'pending';
    }
}
