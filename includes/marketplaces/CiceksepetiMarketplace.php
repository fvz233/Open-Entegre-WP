<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class CiceksepetiMarketplace extends BaseMarketplace
{
    const API_BASE = 'https://apis.ciceksepeti.com/api/v1';
    const REQUEST_DELAY_SECONDS = 5;
    const SAME_REQUEST_COOLDOWN_SECONDS = 600;

    public function get_key()
    {
        return 'ciceksepeti';
    }

    public function get_label()
    {
        return 'Ciceksepeti';
    }

    public function validate_credentials($supplier)
    {
        $api_key = $this->get_api_key($supplier);
        if ($api_key === '') {
            return new \WP_Error(
                'multi_sync_missing_credentials',
                'Eksik yetki bilgisi: API Key zorunludur.'
            );
        }

        return true;
    }

    protected function build_default_headers($supplier)
    {
        return array(
            'x-api-key' => $this->get_api_key($supplier),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );
    }

    protected function request_json($method, $url, $supplier, $body = null)
    {
        $supplier_id = (int) $this->get_supplier_row_id($supplier);
        $request_signature = $this->build_request_signature($method, $url, $body);
        $signature_hash = md5($request_signature);

        // ponytail: batch-status polllari canli yanit ister; cache'lenen "pending" cevabi pollu kilitler ve debug kaydi birakmaz.
        $skip_same_request_cache = (strtoupper((string) $method) === 'GET' && strpos((string) $url, '/batch-status/') !== false);

        $prefix = 'multi_sync_cs_req_' . $supplier_id . '_';
        $last_any_key = $prefix . 'last_any';
        $last_same_key = $prefix . 'same_' . $signature_hash;
        $cached_response_key = $prefix . 'resp_' . $signature_hash;

        $now = time();
        $last_any = (int) get_transient($last_any_key);
        if ($last_any > 0) {
            $wait_seconds = self::REQUEST_DELAY_SECONDS - ($now - $last_any);
            if ($wait_seconds > 0) {
                sleep($wait_seconds);
            }
        }

        $now = time();
        $last_same = (int) get_transient($last_same_key);
        if (!$skip_same_request_cache && $last_same > 0) {
            $elapsed = $now - $last_same;
            if ($elapsed < self::SAME_REQUEST_COOLDOWN_SECONDS) {
                $cached_response = get_transient($cached_response_key);
                if (is_array($cached_response) || $cached_response instanceof \WP_Error) {
                    if (function_exists('multi_sync_debug_log')) {
                        multi_sync_debug_log(
                            'Ciceksepeti request throttle: same request reused from cache. Supplier: ' . $supplier_id
                        );
                    }
                    return $cached_response;
                }

                return new \WP_Error(
                    'multi_sync_ciceksepeti_same_request_throttled',
                    sprintf(
                        'Ciceksepeti ayni request body ile 10 dakikada bir cagriya izin veriyor. Kalan: %d saniye',
                        max(1, self::SAME_REQUEST_COOLDOWN_SECONDS - $elapsed)
                    )
                );
            }
        }

        $response = parent::request_json($method, $url, $supplier, $body);

        $request_time = time();
        set_transient($last_any_key, $request_time, self::SAME_REQUEST_COOLDOWN_SECONDS);
        if (!$skip_same_request_cache) {
            set_transient($last_same_key, $request_time, self::SAME_REQUEST_COOLDOWN_SECONDS);

            if (is_array($response) || $response instanceof \WP_Error) {
                set_transient($cached_response_key, $response, self::SAME_REQUEST_COOLDOWN_SECONDS);
            }
        }

        return $response;
    }

    public function clear_request_cache($supplier)
    {
        $supplier_id = (int) $this->get_supplier_row_id($supplier);
        if ($supplier_id <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_multi_sync_cs_req_' . $supplier_id . '_%'
        ));
    }

    public function fetch_products($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        // Ciceksepeti product listing uses 1-based pagination.
        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        if (isset($params['Page'])) {
            $page = max(0, ((int) $params['Page']) - 1);
        }

        // Ciceksepeti product endpoint page size limit is 60.
        $page_size = isset($params['size']) ? max(1, min(60, (int) $params['size'])) : 60;
        if (isset($params['pageSize'])) {
            $page_size = max(1, min(60, (int) $params['pageSize']));
        }
        if (isset($params['PageSize'])) {
            $page_size = max(1, min(60, (int) $params['PageSize']));
        }

        $query_args = array(
            'Page' => $page + 1,
            'PageSize' => $page_size,
        );

        $optional_keys = array(
            'ProductStatus' => array('ProductStatus', 'productStatus', 'statusId', 'status'),
            'SortMethod' => array('SortMethod', 'sortMethod'),
            'StockCode' => array('StockCode', 'stockCode', 'merchantSKU', 'merchantSku'),
            'variantName' => array('variantName', 'VariantName'),
        );
        foreach ($optional_keys as $query_key => $source_keys) {
            $value = $this->read_param($params, $source_keys, '');
            if ($value !== '') {
                $query_args[$query_key] = $value;
            }
        }

        $cache_key = $this->build_products_cache_key($supplier, $query_args);
        $cached_items = get_transient($cache_key);
        if (is_array($cached_items)) {
            return $cached_items;
        }

        $url = self::API_BASE . '/Products?' . http_build_query($query_args);
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        $items = $this->extract_list_flexible(
            $response['data'],
            array('data', 'items', 'products', 'content', 'result', 'value')
        );

        if (is_array($items)) {
            // API limitation: same request can be called once per ~10 minutes.
            set_transient($cache_key, $items, 10 * MINUTE_IN_SECONDS);
        }

        return $items;
    }

    public function fetch_orders($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        $page_size = isset($params['size']) ? max(1, min(100, (int) $params['size'])) : 100;
        if (isset($params['pageSize'])) {
            $page_size = max(1, min(100, (int) $params['pageSize']));
        }
        if (isset($params['PageSize'])) {
            $page_size = max(1, min(100, (int) $params['PageSize']));
        }

        $now_utc = current_time('timestamp', true);
        $default_begin = gmdate('Y-m-d\TH:i:s\Z', $now_utc - (14 * DAY_IN_SECONDS));
        $default_end = gmdate('Y-m-d\TH:i:s\Z', $now_utc);

        $start_date = $this->read_param($params, array('startDate', 'StartDate', 'beginDate', 'BeginDate'), $default_begin);
        $end_date = $this->read_param($params, array('endDate', 'EndDate'), $default_end);

        $payload = array(
            'startDate' => $start_date,
            'endDate' => $end_date,
            'page' => $page,
            'pageSize' => $page_size,
        );

        $optional_keys = array(
            'orderNo' => array('orderNo', 'OrderNo', 'orderNumber'),
            'orderItemNo' => array('orderItemNo', 'OrderItemNo'),
            'statusId' => array('statusId', 'StatusId', 'status'),
            'isOrderStatusActive' => array('isOrderStatusActive', 'IsOrderStatusActive'),
        );
        foreach ($optional_keys as $query_key => $source_keys) {
            $value = $this->read_param($params, $source_keys, '');
            if ($value !== '') {
                $payload[$query_key] = $value;
            }
        }

        $url = self::API_BASE . '/Order/GetOrders';
        $response = $this->request_json('POST', $url, $supplier, $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        $items = $this->extract_list_flexible(
            $response['data'],
            array('supplierOrderListWithBranch', 'supplierOrderList', 'data', 'items', 'orders', 'content', 'result', 'value')
        );

        return $this->merge_orders_by_order_id($items);
    }

    public function map_product($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $sku = trim((string) $this->first_not_empty($item, array(
            'merchantSKU',
            'merchantSku',
            'stockCode',
            'StockCode',
            'supplierSKU',
            'supplierSku',
            'sku',
            'SKU',
        ), ''));

        if ($sku === '') {
            $title = (string) $this->first_not_empty($item, array('name', 'productName', 'title', 'productCode', 'code'), 'unknown');
            return new \WP_Error(
                'multi_sync_ciceksepeti_missing_sku',
                sprintf('Ciceksepeti urununde SKU zorunlu. Urun: %s', $title)
            );
        }

        $images = $this->collect_image_urls($item);

        $regular = $this->money_to_float($this->first_not_empty($item, array('listPrice', 'price')), null);
        $sale = $this->money_to_float($this->first_not_empty($item, array('salesPrice', 'salePrice', 'currentPrice')), null);
        return array(
            'sku' => $sku,
            'name' => (string) $this->first_not_empty($item, array('name', 'productName', 'title', 'displayName'), $sku),
            'regular_price' => $regular !== null ? $regular : $sale,
            'sale_price' => $sale !== null && $regular !== null && $sale < $regular ? $sale : '',
            'stock_quantity' => $this->to_int(
                $this->first_not_empty($item, array('stockQuantity', 'StockQuantity', 'quantity', 'stock', 'quantityAvailable')),
                0
            ),
            'images' => $images,
            'preview_image' => !empty($images) ? $images[0] : '',
            'external_sku' => $sku,
            'external_barcode' => (string) $this->first_not_empty($item, array('barcode', 'barkod'), ''),
            'external_product_id' => (string) $this->first_not_empty($item, array('productCode', 'code', 'id'), ''),
            'parent_key' => '',
            'variation_attributes' => array(),
        );
    }

    public function map_order($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $shipping = $this->extract_address(
            $item,
            array('shippingAddress', 'shipmentAddress', 'deliveryAddress', 'ShippingAddress', 'ShipmentAddress')
        );
        $billing = $this->extract_address(
            $item,
            array('billingAddress', 'invoiceAddress', 'BillingAddress', 'InvoiceAddress')
        );

        // Flat order rows from Ciceksepeti GetOrders response.
        if (empty($shipping)) {
            $shipping = array(
                'fullName' => $this->first_not_empty($item, array('receiverName'), ''),
                'phone' => $this->first_not_empty($item, array('receiverPhone'), ''),
                'address' => $this->first_not_empty($item, array('receiverAddress'), ''),
                'city' => $this->first_not_empty($item, array('receiverCity'), ''),
                'district' => $this->first_not_empty($item, array('receiverDistrict', 'receiverRegion'), ''),
                'postalCode' => $this->first_not_empty($item, array('receiverPostalCode', 'receiverZipCode'), ''),
                'countryCode' => 'TR',
                'email' => $this->first_not_empty($item, array('invoiceEmail', 'customerEmail', 'email'), ''),
            );
        }
        if (empty($billing)) {
            $billing = array(
                'fullName' => $this->first_not_empty($item, array('senderName', 'receiverName'), ''),
                'phone' => $this->first_not_empty($item, array('senderPhone', 'receiverPhone'), ''),
                'address' => $this->first_not_empty($item, array('senderAddress', 'receiverAddress'), ''),
                'city' => $this->first_not_empty($item, array('senderCity', 'receiverCity'), ''),
                'district' => $this->first_not_empty($item, array('senderRegion', 'receiverDistrict', 'receiverRegion'), ''),
                'postalCode' => $this->first_not_empty($item, array('senderPostalCode', 'receiverPostalCode', 'receiverZipCode'), ''),
                'countryCode' => 'TR',
                'email' => $this->first_not_empty($item, array('invoiceEmail', 'customerEmail', 'email'), ''),
            );
        }

        $shipping_name = $this->extract_name_parts($shipping);
        $billing_name = $this->extract_name_parts($billing);

        $raw_lines = $this->extract_order_lines($item);
        $line_items = array();
        $fallback_total = 0.0;

        foreach ($raw_lines as $line) {
            $line = is_array($line) ? $line : (array) $line;
            $product = isset($line['product']) && is_array($line['product']) ? $line['product'] : array();

            $quantity = (int) $this->to_int($this->first_not_empty($line, array('quantity', 'qty', 'count')), 1);
            if ($quantity <= 0) {
                $quantity = 1;
            }

            $unit_price = $this->money_to_float(
                $this->first_not_empty($line, array('price', 'salePrice', 'listPrice', 'unitPrice')),
                null
            );
            $line_total = $this->money_to_float(
                $this->first_not_empty($line, array('totalPrice', 'lineAmount', 'amount')),
                null
            );
            if ($unit_price === null && $line_total !== null && $quantity > 0) {
                $unit_price = $line_total / $quantity;
            }
            $unit_price = $unit_price !== null ? (float) $unit_price : 0.0;
            $fallback_total += ($unit_price * $quantity);

            $line_items[] = array(
                'sku' => (string) $this->first_not_empty($line, array(
                    'merchantSKU',
                    'merchantSku',
                    'supplierSKU',
                    'supplierSku',
                    'stockCode',
                    'sku',
                    'code',
                    'productCode',
                ), $this->first_not_empty($product, array('merchantSKU', 'supplierSKU', 'stockCode', 'sku'), '')),
                'name' => (string) $this->first_not_empty($line, array(
                    'productName',
                    'name',
                    'title',
                ), $this->first_not_empty($product, array('name', 'title', 'productName'), '')),
                'quantity' => $quantity,
                'price' => $unit_price,
            );
        }

        $total = $this->money_to_float(
            $this->first_not_empty($item, array('mergedTotalPrice', 'totalPrice', 'orderTotal', 'totalAmount', 'amount', 'invoiceAmount', 'itemPrice')),
            null
        );
        if ($total === null) {
            $total = $fallback_total;
        }

        $email = (string) $this->first_not_empty(
            $item,
            array('customerEmail', 'invoiceEmail', 'email'),
            $this->first_not_empty($billing, array('email', 'customerEmail'), $this->first_not_empty($shipping, array('email', 'customerEmail'), ''))
        );

        $status = $this->normalize_order_status($item, $raw_lines);

        return array(
            'external_id' => (string) $this->first_not_empty($item, array('OrderId', 'orderId', 'id', 'orderNo', 'orderNumber'), ''),
            'status' => $status,
            'currency' => (string) $this->first_not_empty($item, array('currency', 'currencyCode'), 'TRY'),
            'total' => (float) $total,
            'order_date' => $this->extract_order_date($item),
            'billing_first_name' => $billing_name[0],
            'billing_last_name' => $billing_name[1],
            'billing_phone' => (string) $this->first_not_empty($billing, array('phone', 'phoneNumber', 'gsm', 'mobilePhone'), ''),
            'billing_email' => $email,
            'billing_address_1' => (string) $this->first_not_empty($billing, array('address', 'fullAddress', 'addressDetail', 'addressLine', 'displayAddress', 'displayAddressText'), ''),
            'billing_city' => (string) $this->first_not_empty($billing, array('city', 'cityName', 'district'), ''),
            'billing_postcode' => (string) $this->first_not_empty($billing, array('postalCode', 'postCode', 'zipCode'), ''),
            'billing_country' => (string) $this->first_not_empty($billing, array('countryCode', 'country'), 'TR'),
            'shipping_first_name' => $shipping_name[0],
            'shipping_last_name' => $shipping_name[1],
            'shipping_phone' => (string) $this->first_not_empty($shipping, array('phone', 'phoneNumber', 'gsm', 'mobilePhone'), ''),
            'shipping_address_1' => (string) $this->first_not_empty($shipping, array('address', 'fullAddress', 'addressDetail', 'addressLine', 'displayAddress', 'displayAddressText'), ''),
            'shipping_city' => (string) $this->first_not_empty($shipping, array('city', 'cityName', 'district'), ''),
            'shipping_postcode' => (string) $this->first_not_empty($shipping, array('postalCode', 'postCode', 'zipCode'), ''),
            'shipping_country' => (string) $this->first_not_empty($shipping, array('countryCode', 'country'), 'TR'),
            'line_items' => $line_items,
        );
    }

    public function build_price_inventory_item_from_product($product, $sync_stock = true, $sync_price = true, $commission_rate = null)
    {
        if (!$product || !is_callable(array($product, 'get_sku'))) {
            return null;
        }

        $sku = is_callable(array($product, 'get_meta')) ? trim((string) $product->get_meta('_multi_sync_external_sku', true)) : '';
        if ($sku === '') {
            $sku = trim((string) $product->get_sku());
        }
        if ($sku === '') {
            return null;
        }

        $item = array('stockCode' => $sku);
        if ($sync_stock) {
            $stock = $product->get_stock_quantity();
            $item['stockQuantity'] = max(0, (int) ($stock === null || $stock === '' ? 0 : $stock));
        }
        if ($sync_price) {
            $regular_raw = is_numeric($product->get_regular_price()) ? (float) $product->get_regular_price() : 0.0;
            $regular = $this->apply_product_commission($regular_raw, $product, $commission_rate);
            $sale_raw = is_callable(array($product, 'get_sale_price')) ? $product->get_sale_price() : '';
            $sale = is_numeric($sale_raw) && (float) $sale_raw > 0 && (float) $sale_raw < $regular_raw ? $this->apply_product_commission((float) $sale_raw, $product, $commission_rate) : $regular;
            $item['listPrice'] = $regular > 0 ? $regular : $sale;
            $item['salesPrice'] = $sale;
        }
        return $item;
    }

    public function fetch_product_categories($supplier, $search = '')
    {
        $response = $this->request_json('GET', self::API_BASE . '/Categories', $supplier);
        if (is_wp_error($response)) return $response;
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
        $result = array();
        $walk = function ($nodes, $parents = array()) use (&$walk, &$result, $needle) {
            foreach ((array) $nodes as $node) {
                $node = is_array($node) ? $node : (array) $node;
                $id = $node['id'] ?? $node['categoryId'] ?? null;
                $name = trim((string) ($node['name'] ?? $node['categoryName'] ?? ''));
                $path = array_merge($parents, $name !== '' ? array($name) : array());
                $children = $node['children'] ?? $node['subCategories'] ?? array();
                if (is_array($children) && $children) $walk($children, $path);
                elseif ($id !== null) {
                    $label = implode(' > ', $path);
                    if ($needle === '' || mb_strpos(mb_strtolower($label, 'UTF-8'), $needle) !== false) $result[] = array('id' => (string) $id, 'name' => $name, 'path' => $label);
                }
            }
        };
        $walk($this->extract_list($response['data'], array('categories', 'items', 'data', 'result')));
        return $result;
    }

    public function fetch_category_attributes($supplier, $category_id)
    {
        $response = $this->request_json('GET', self::API_BASE . '/Categories/' . rawurlencode((string) $category_id) . '/attributes', $supplier);
        if (is_wp_error($response)) return $response;
        $result = array();
        foreach ($this->extract_list($response['data'], array('attributes', 'items', 'data', 'result')) as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $id = $row['id'] ?? $row['attributeId'] ?? null;
            if ($id === null || (empty($row['required']) && empty($row['isRequired']) && empty($row['variant']) && empty($row['isVariant']))) continue;
            $values = array();
            foreach ((array) ($row['values'] ?? $row['attributeValues'] ?? array()) as $option) {
                $option = is_array($option) ? $option : (array) $option;
                $option_id = $option['id'] ?? $option['attributeValueId'] ?? null;
                if ($option_id !== null) $values[] = array('id' => (string) $option_id, 'name' => (string) ($option['name'] ?? $option['value'] ?? ''));
            }
            $result[] = array('id' => (string) $id, 'name' => (string) ($row['name'] ?? $row['attributeName'] ?? ''), 'required' => !empty($row['required']) || !empty($row['isRequired']), 'slicer' => false, 'varianter' => !empty($row['variant']) || !empty($row['isVariant']), 'allow_custom' => empty($values), 'values' => $values);
        }
        return $result;
    }

    public function fetch_product_brands($supplier, $search = '', $category_id = '')
    {
        if ((string) $category_id === '') return new \WP_Error('multi_sync_ciceksepeti_brand_category_required', 'Ciceksepeti marka aramasi icin once kategori secin.');
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
        $attributes = $this->fetch_category_attributes($supplier, $category_id);
        if (is_wp_error($attributes)) return $attributes;
        foreach ($attributes as $attribute) {
            if ($this->ciceksepeti_normalize($attribute['name'] ?? '') !== 'marka') continue;
            return array_values(array_filter((array) ($attribute['values'] ?? array()), function ($brand) use ($needle) {
                return $needle === '' || mb_strpos(mb_strtolower((string) ($brand['name'] ?? ''), 'UTF-8'), $needle) !== false;
            }));
        }
        return array();
    }

    public function build_product_item_from_product($product, $category_mapping = array(), $overrides = array())
    {
        $parent = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;
        if (!$product || (!$product->is_type('simple') && !$parent)) return new \WP_Error('multi_sync_ciceksepeti_unsupported_product', 'Yalnizca basit urunler ve varyasyonlar gonderilebilir.');
        $value = function ($key, $fallback = '') use ($overrides, $product, $parent) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') return trim((string) $overrides[$key]);
            $stored = trim((string) $product->get_meta('_multi_sync_ciceksepeti_' . $key, true));
            if ($stored === '' && $parent) $stored = trim((string) $parent->get_meta('_multi_sync_ciceksepeti_' . $key, true));
            return $stored !== '' ? $stored : $fallback;
        };
        $sku = $value('sku', $product->get_sku());
        $barcode = $value('barcode', $sku);
        $model = $value('product_main_id', $parent ? $parent->get_sku() : $sku);
        $category = $value('category_id', $category_mapping['category_id'] ?? '');
        $vat = $this->get_product_vat_rate($product, $value('vat_rate'));
        $missing = array();
        foreach (array('sku' => array('SKU / Stok Kodu', $sku), 'barcode' => array('Barkod', $barcode), 'product_main_id' => array('Ana Urun / Model Kodu', $model), 'category_id' => array('Ciceksepeti Kategori ID', $category)) as $key => $field) if ($field[1] === '') $missing[] = array('key' => $key, 'label' => $field[0], 'type' => 'text', 'options' => array());
        if (!in_array($vat, array('0', '1', '10', '20'), true)) $missing[] = array('key' => 'vat_rate', 'label' => 'KDV Orani', 'type' => 'select', 'options' => array_map(function ($rate) { return array('id' => $rate, 'name' => '%' . $rate); }, array('0', '1', '10', '20')));
        $attributes = array();
        $mapped_attributes = array();
        foreach ((array) ($category_mapping['attributes'] ?? array()) as $mapped) {
            if (!empty($mapped['attributeId'])) $mapped_attributes[(string) $mapped['attributeId']] = (string) (($mapped['attributeValueIds'][0] ?? null) ?: ($mapped['attributeValue'] ?? ''));
        }
        $color = $this->ciceksepeti_variation_color($product, $parent);
        foreach ((array) ($category_mapping['attribute_definitions'] ?? array()) as $definition) {
            $id = (string) ($definition['id'] ?? '');
            if ($id === '') continue;
            $input = $value('attribute_' . $id);
            if ($input === '') $input = $mapped_attributes[$id] ?? '';
            if ($input === '' && $this->ciceksepeti_normalize($definition['name'] ?? '') === 'marka') $input = (string) ($category_mapping['brand_id'] ?? $category_mapping['brand_name'] ?? '');
            $is_desi = $this->ciceksepeti_normalize($definition['name'] ?? '') === 'desi';
            if ($input === '' && $is_desi) $input = $this->get_product_desi($product);
            $is_color = $this->ciceksepeti_normalize($definition['name'] ?? '') === 'renk';
            if ($input === '' && $is_color) {
                foreach ((array) ($definition['values'] ?? array()) as $option) if ($this->ciceksepeti_normalize($option['name'] ?? '') === $this->ciceksepeti_normalize($color)) { $input = (string) $option['id']; break; }
                if ($input === '' && !empty($definition['allow_custom'])) $input = $color;
            }
            $known = false;
            foreach ((array) ($definition['values'] ?? array()) as $option) if ((string) ($option['id'] ?? '') === $input) { $attributes[] = array('attributeId' => $id, 'attributeValueId' => $input); $known = true; break; }
            if (!$known && $input !== '' && !empty($definition['allow_custom'])) $attributes[] = array('attributeId' => $id, 'attributeValue' => sanitize_text_field($input));
            elseif (!$known && (!empty($definition['required']) || ($parent && $is_color))) $missing[] = array('key' => 'attribute_' . $id, 'label' => (string) ($definition['name'] ?? $id), 'type' => !empty($definition['values']) ? 'select' : 'text', 'options' => (array) ($definition['values'] ?? array()), 'suggested_value' => $is_color ? $color : '');
        }
        $price = $this->build_price_inventory_item_from_product($product, true, true, $category_mapping['commission_rate'] ?? null);
        if (!$price || $price['listPrice'] <= 0 || $price['salesPrice'] <= 0) return new \WP_Error('multi_sync_ciceksepeti_product_price', 'Urun fiyati sifirdan buyuk olmali.');
        $images = $this->ciceksepeti_images($product, $parent, $value('image_url'));
        if (!$images) $missing[] = array('key' => 'image_url', 'label' => 'Gorsel URL', 'type' => 'text', 'options' => array());
        if ($missing) return new \WP_Error('multi_sync_ciceksepeti_product_incomplete', 'Eksik Ciceksepeti bilgilerini doldurun.', array('fields' => $missing));
        $source = $parent ?: $product;
        $description = $source->get_description() ?: $source->get_short_description() ?: $source->get_name();
        return array('productName' => $this->product_export_name($product, $parent), 'mainProductCode' => $model, 'stockCode' => $sku, 'categoryId' => (int) $category, 'barcode' => $barcode, 'isActive' => true, 'salesPrice' => $price['salesPrice'], 'listPrice' => $price['listPrice'], 'stockQuantity' => $price['stockQuantity'], 'vatRate' => (int) $vat, 'description' => mb_substr($description, 0, 20000), 'images' => $images, 'attributes' => $attributes, 'deliveryType' => 2, 'deliveryMessageType' => 5);
    }

    public function push_products($supplier, $items)
    {
        $response = $this->request_json('POST', self::API_BASE . '/Products', $supplier, array('products' => array_values($items)));
        if (is_wp_error($response)) {
            return $response;
        }

        $normalized = is_array($response['data']) ? $response['data'] : array(
            'result' => $response['data'],
        );

        $batch_id = $this->extract_batch_id($response['data']);
        if ($batch_id !== '') {
            $normalized['batchId'] = $batch_id;
            $normalized['batchRequestId'] = $batch_id;
            $normalized['batchRequestIds'] = array($batch_id);
        }

        return $normalized;
    }

    private function ciceksepeti_variation_color($product, $parent)
    {
        if (!$parent) return '';
        foreach ((array) $product->get_attributes() as $name => $value) {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name;
            if ($this->ciceksepeti_normalize($label) !== 'renk' && count($product->get_attributes()) !== 1) continue;
            if (taxonomy_exists($name)) { $term = get_term_by('slug', $value, $name); if ($term && !is_wp_error($term)) return (string) $term->name; }
            return mb_convert_case(str_replace('-', ' ', (string) $value), MB_CASE_TITLE, 'UTF-8');
        }
        return '';
    }

    private function ciceksepeti_normalize($value)
    {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = trim(preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($value, 'UTF-8')));
        if (strpos($value, 'osmanli') !== false) return 'osmanli';
        if (strpos($value, 'turk bayrak') !== false) return 'turk bayrak';
        return in_array($value, array('renk', 'color'), true) ? 'renk' : $value;
    }

    private function ciceksepeti_images($product, $parent, $override)
    {
        $ids = array_merge(array($product->get_image_id()), $parent ? array($parent->get_image_id()) : array(), $parent ? $parent->get_gallery_image_ids() : $product->get_gallery_image_ids());
        $images = preg_match('#^https?://#i', $override) ? array(esc_url_raw($override)) : array();
        foreach (array_unique(array_filter($ids)) as $id) { $url = wp_get_attachment_url($id); if (preg_match('#^https?://#i', (string) $url)) $images[] = $url; if (count($images) === 8) break; }
        return $images;
    }

    public function push_price_inventory_updates($supplier, $items)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        if (!is_array($items) || empty($items)) {
            return array(
                'batchRequestIds' => array(),
            );
        }

        $response = $this->request_json(
            'PUT',
            self::API_BASE . '/Products/price-and-stock',
            $supplier,
            array_values($items)
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $normalized = is_array($response['data']) ? $response['data'] : array(
            'result' => $response['data'],
        );

        $batch_id = $this->extract_batch_id($response['data']);
        if ($batch_id !== '') {
            $normalized['batchId'] = $batch_id;
            $normalized['batchRequestId'] = $batch_id;
            $normalized['batchRequestIds'] = array($batch_id);
        }

        return $normalized;
    }

    public function get_batch_request_result($supplier, $batch_request_id)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $url = self::API_BASE . '/Products/batch-status/' . rawurlencode((string) $batch_request_id);
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        return $response['data'];
    }

    private function read_param($params, $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                return $params[$key];
            }
        }

        return $default;
    }

    private function extract_list_flexible($data, $preferred_keys = array())
    {
        $list = $this->extract_list($data, $preferred_keys);
        if (!empty($list)) {
            return $list;
        }

        if (!is_array($data)) {
            return array();
        }

        $lower = array_change_key_case($data, CASE_LOWER);
        foreach ($preferred_keys as $key) {
            $lookup = strtolower((string) $key);
            if (isset($lower[$lookup]) && is_array($lower[$lookup])) {
                if (isset($lower[$lookup][0])) {
                    return $lower[$lookup];
                }
                $nested = $this->extract_list($lower[$lookup], array('items', 'data', 'content', 'result'));
                if (!empty($nested)) {
                    return $nested;
                }
            }
        }

        foreach ($data as $candidate) {
            if (is_array($candidate) && isset($candidate[0])) {
                return $candidate;
            }
        }

        return array();
    }

    private function collect_image_urls($item)
    {
        $images = array();

        $direct_keys = array('imageUrl', 'imageURL', 'mainImageUrl', 'mainImageURL', 'image');
        foreach ($direct_keys as $key) {
            if (!isset($item[$key])) {
                continue;
            }
            $value = $item[$key];
            if (is_string($value) && trim($value) !== '') {
                $images[] = trim($value);
            }
        }

        $list_items = $this->first_not_empty($item, array('images', 'imageUrls', 'pictures', 'productImages'), array());
        if (is_array($list_items)) {
            foreach ($list_items as $image_item) {
                if (is_string($image_item) && trim($image_item) !== '') {
                    $images[] = trim($image_item);
                    continue;
                }

                $image_data = is_array($image_item) ? $image_item : (array) $image_item;
                $url = $this->first_not_empty($image_data, array('url', 'imageUrl', 'imageURL', 'src'), '');
                if (is_string($url) && trim($url) !== '') {
                    $images[] = trim($url);
                }
            }
        }

        return array_values(array_unique($images));
    }

    private function extract_address($item, $keys)
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                return $item[$key];
            }
        }

        return array();
    }

    private function extract_name_parts($address)
    {
        $first_name = (string) $this->first_not_empty($address, array('firstName', 'name'), '');
        $last_name = (string) $this->first_not_empty($address, array('lastName', 'surname'), '');
        if ($first_name !== '' || $last_name !== '') {
            return array($first_name, $last_name);
        }

        $full_name = trim((string) $this->first_not_empty(
            $address,
            array('fullName', 'nameSurname', 'recipientName', 'companyName', 'title'),
            ''
        ));
        if ($full_name === '') {
            return array('', '');
        }

        $parts = preg_split('/\s+/', $full_name);
        if (count($parts) <= 1) {
            return array($full_name, '');
        }

        $first = array_shift($parts);
        return array($first, implode(' ', $parts));
    }

    private function extract_order_lines($item)
    {
        $lines = $this->first_not_empty($item, array('lineItems', 'orderItems', 'items', 'products'), array());
        if (is_array($lines) && !empty($lines)) {
            return array_values($lines);
        }

        $has_flat_order_row = $this->first_not_empty(
            $item,
            array('orderItemId', 'orderId', 'OrderId', 'productCode', 'code'),
            ''
        );
        if ($has_flat_order_row !== '') {
            return array($item);
        }

        return array();
    }

    private function extract_order_date($item)
    {
        $direct = $this->first_not_empty($item, array('orderDate', 'createdDate', 'createDate', 'lastUpdatedDate'), '');
        if ($direct !== '') {
            return (string) $direct;
        }

        $date_part = trim((string) $this->first_not_empty($item, array('orderCreateDate'), ''));
        $time_part = trim((string) $this->first_not_empty($item, array('orderCreateTime'), ''));
        if ($date_part === '' && $time_part === '') {
            return '';
        }

        $composed = trim($date_part . ' ' . $time_part);
        $formats = array(
            'd/m/Y H:i',
            'd/m/Y H:i:s',
            'Y-m-d H:i',
            'Y-m-d H:i:s',
            'd.m.Y H:i',
            'd.m.Y H:i:s',
        );
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $composed);
            if ($dt instanceof \DateTime) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $timestamp = strtotime($composed);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return $composed;
    }

    private function merge_orders_by_order_id($items)
    {
        if (!is_array($items) || empty($items)) {
            return array();
        }

        $merged = array();

        foreach ($items as $index => $raw_item) {
            $item = is_array($raw_item) ? $raw_item : (array) $raw_item;
            $order_id = trim((string) $this->first_not_empty($item, array('OrderId', 'orderId', 'id'), ''));
            $order_no = trim((string) $this->first_not_empty($item, array('orderNo', 'orderNumber'), ''));

            $key = $order_id !== '' ? $order_id : ($order_no !== '' ? $order_no : 'row_' . $index);
            $lines = $this->extract_order_lines($item);
            $item_total = (float) $this->money_to_float(
                $this->first_not_empty($item, array('totalPrice', 'orderTotal', 'totalAmount', 'amount')),
                0
            );

            if (!isset($merged[$key])) {
                $item['lineItems'] = $lines;
                $item['mergedTotalPrice'] = $item_total;
                $merged[$key] = $item;
                continue;
            }

            $existing = $merged[$key];
            if (!isset($existing['lineItems']) || !is_array($existing['lineItems'])) {
                $existing['lineItems'] = array();
            }
            $existing['lineItems'] = array_merge($existing['lineItems'], $lines);

            $existing_total = (float) $this->money_to_float(
                $this->first_not_empty($existing, array('mergedTotalPrice')),
                0
            );
            $existing['mergedTotalPrice'] = $existing_total + $item_total;

            $existing_date = strtotime((string) $this->first_not_empty($existing, array('orderDate', 'createdDate', 'createDate'), ''));
            $item_date = strtotime((string) $this->first_not_empty($item, array('orderDate', 'createdDate', 'createDate'), ''));
            if ($item_date && (!$existing_date || $item_date > $existing_date)) {
                $fields_to_refresh = array(
                    'orderDate',
                    'createdDate',
                    'createDate',
                    'orderStatus',
                    'orderStatusName',
                    'status',
                    'shippingAddress',
                    'shipmentAddress',
                    'deliveryAddress',
                    'billingAddress',
                    'invoiceAddress',
                    'customerEmail',
                    'email',
                    'currency',
                    'currencyCode',
                );
                foreach ($fields_to_refresh as $field_key) {
                    if (isset($item[$field_key])) {
                        $existing[$field_key] = $item[$field_key];
                    }
                }
            }

            $merged[$key] = $existing;
        }

        return array_values($merged);
    }

    private function normalize_order_status($item, $lines = array())
    {
        $status_text = (string) $this->first_not_empty(
            $item,
            array(
                'orderStatusName',
                'OrderStatusName',
                'orderProductStatus',
                'orderStatus',
                'OrderStatus',
                'status',
            ),
            ''
        );

        if ($status_text === '' && is_array($lines)) {
            foreach ($lines as $line) {
                $line = is_array($line) ? $line : (array) $line;
                $candidate = (string) $this->first_not_empty($line, array('lineItemStatusName', 'orderProductStatus', 'statusName', 'status'), '');
                if ($candidate !== '') {
                    $status_text = $candidate;
                    break;
                }
            }
        }

        $normalized = $this->normalize_status_text($status_text);
        if ($normalized !== '') {
            if (strpos($normalized, 'iptal') !== false || strpos($normalized, 'cancel') !== false) {
                return 'cancelled';
            }
            if (strpos($normalized, 'iade') !== false || strpos($normalized, 'refund') !== false) {
                return 'refunded';
            }
            if (strpos($normalized, 'teslim') !== false || strpos($normalized, 'delivered') !== false || strpos($normalized, 'tamam') !== false) {
                return 'delivered';
            }
            if (strpos($normalized, 'kargo') !== false || strpos($normalized, 'ship') !== false || strpos($normalized, 'hazir') !== false) {
                return 'shipped';
            }
            if (strpos($normalized, 'onay') !== false || strpos($normalized, 'islem') !== false || strpos($normalized, 'processing') !== false) {
                return 'processing';
            }
            if (strpos($normalized, 'bekle') !== false || strpos($normalized, 'new') !== false) {
                return 'pending';
            }
        }

        $status_code = $this->to_int($this->first_not_empty($item, array('orderStatusCode', 'statusCode', 'orderStatus')), null);
        if ($status_code === null) {
            $status_code = $this->to_int($this->first_not_empty($item, array('orderItemStatusId')), null);
        }
        if ($status_code !== null) {
            $status_map = array(
                0 => 'pending',
                1 => 'pending',
                2 => 'processing',
                3 => 'processing',
                4 => 'shipped',
                5 => 'delivered',
                6 => 'cancelled',
                7 => 'refunded',
            );
            if (isset($status_map[$status_code])) {
                return $status_map[$status_code];
            }
        }

        return 'pending';
    }

    private function extract_batch_id($response_data)
    {
        if (is_array($response_data)) {
            $direct_keys = array(
                'batchId',
                'BatchId',
                'batchID',
                'id',
                'requestId',
            );
            foreach ($direct_keys as $key) {
                if (!isset($response_data[$key])) {
                    continue;
                }
                $value = $response_data[$key];
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }

            foreach ($response_data as $nested_value) {
                if (!is_array($nested_value)) {
                    continue;
                }
                $nested = $this->extract_batch_id($nested_value);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function money_to_float($value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            if (isset($value['value']) && is_numeric($value['value'])) {
                return (float) $value['value'];
            }
            if (isset($value['amount']) && is_numeric($value['amount'])) {
                return (float) $value['amount'];
            }
            if (isset($value['valueInt']) && is_numeric($value['valueInt'])) {
                return ((float) $value['valueInt']) / 100;
            }
            if (isset($value['valueString']) && is_string($value['valueString'])) {
                return $this->money_string_to_float($value['valueString'], $default);
            }
        }

        if (is_string($value)) {
            return $this->money_string_to_float($value, $default);
        }

        return $default;
    }

    private function money_string_to_float($value, $default = null)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return $default;
        }

        $normalized = preg_replace('/[^\d,.\-]/u', '', $raw);
        if ($normalized === '' || $normalized === '-' || $normalized === ',' || $normalized === '.') {
            return $default;
        }

        if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (strpos($normalized, ',') !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : $default;
    }

    private function normalize_status_text($value)
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }

        return strtolower($text);
    }

    private function build_request_signature($method, $url, $body)
    {
        $normalized_body = $this->normalize_request_payload($body);
        return strtoupper((string) $method) . '|' . (string) $url . '|' . wp_json_encode($normalized_body);
    }

    private function normalize_request_payload($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->is_assoc_array($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize_request_payload($item);
        }

        return $value;
    }

    private function is_assoc_array($array)
    {
        if (!is_array($array)) {
            return false;
        }

        if ($array === array()) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function build_products_cache_key($supplier, $query_args)
    {
        $supplier_id = (int) $this->get_supplier_row_id($supplier);
        $normalized_args = is_array($query_args) ? $query_args : array();
        ksort($normalized_args);

        return 'multi_sync_ciceksepeti_products_' . $supplier_id . '_' . md5(wp_json_encode($normalized_args));
    }
}
