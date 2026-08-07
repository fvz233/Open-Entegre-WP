<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class PazaramaMarketplace extends BaseMarketplace
{
    const TOKEN_SCOPE = 'merchantgatewayapi.fullaccess';
    const TOKEN_URL = 'https://isortagimgiris.pazarama.com/connect/token';
    const API_BASE = 'https://isortagimapi.pazarama.com';

    private $in_memory_tokens = array();
    private $in_memory_token_types = array();

    public function get_key()
    {
        return 'pazarama';
    }

    public function get_label()
    {
        return 'Pazarama';
    }

    public function validate_credentials($supplier)
    {
        $api_key = $this->get_api_key($supplier);
        $api_secret = $this->get_api_secret($supplier);

        if ($api_key === '' || $api_secret === '') {
            return new \WP_Error(
                'multi_sync_missing_credentials',
                'Eksik yetki bilgisi: Client ID veya Client Secret.'
            );
        }

        return true;
    }

    protected function build_default_headers($supplier)
    {
        $token = $this->get_cached_access_token($supplier);
        $token_type = $this->get_cached_token_type($supplier);

        return array(
            'Authorization' => $token !== '' ? trim($token_type . ' ' . $token) : '',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );
    }

    public function fetch_products($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $token_check = $this->ensure_access_token($supplier);
        if (is_wp_error($token_check)) {
            return $token_check;
        }

        $page_number = 0;
        if (isset($params['pageNumber'])) {
            $page_number = max(0, (int) $params['pageNumber']);
        } elseif (isset($params['page'])) {
            $page_number = max(0, (int) $params['page']);
        }

        $page_size = 100;
        if (isset($params['pageSize'])) {
            $page_size = max(1, min(250, (int) $params['pageSize']));
        } elseif (isset($params['size'])) {
            $page_size = max(1, min(250, (int) $params['size']));
        }

        $query_args = array(
            'Approved' => 'true',
            'pageNumber' => $page_number,
            'pageSize' => $page_size,
            // Compatibility aliases for environments that expect different keys.
            'page' => $page_number,
            'size' => $page_size,
            'PageNumber' => $page_number,
            'PageSize' => $page_size,
        );

        $optional_keys = array(
            'Code',
            'code',
            'stockCode',
            'Approved',
        );
        foreach ($optional_keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query_args[$key] = $params[$key];
            }
        }

        $url = self::API_BASE . '/product/products?' . http_build_query($query_args);
        $response = $this->request_json_with_token_refresh('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        $items = $this->extract_list($response['data'], array('products', 'items', 'content', 'data', 'result'));
        if (!empty($items) || $page_number !== 0) {
            return $items;
        }

        // Some Pazarama environments start pagination at 1.
        $fallback_args = $query_args;
        $fallback_args['pageNumber'] = 1;
        $fallback_url = self::API_BASE . '/product/products?' . http_build_query($fallback_args);
        $fallback_response = $this->request_json_with_token_refresh('GET', $fallback_url, $supplier);
        if (is_wp_error($fallback_response)) {
            return $items;
        }

        return $this->extract_list($fallback_response['data'], array('products', 'items', 'content', 'data', 'result'));
    }

    public function fetch_orders($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $token_check = $this->ensure_access_token($supplier);
        if (is_wp_error($token_check)) {
            return $token_check;
        }

        $now = current_time('timestamp');
        $default_start_date = date('Y-m-d', $now - (7 * DAY_IN_SECONDS));
        $default_end_date = date('Y-m-d', $now + DAY_IN_SECONDS);

        $start_date = $default_start_date;
        if (isset($params['startDate']) && $params['startDate'] !== '') {
            $start_date = $params['startDate'];
        } elseif (isset($params['StartDate']) && $params['StartDate'] !== '') {
            $start_date = $params['StartDate'];
        }

        $end_date = $default_end_date;
        if (isset($params['endDate']) && $params['endDate'] !== '') {
            $end_date = $params['endDate'];
        } elseif (isset($params['EndDate']) && $params['EndDate'] !== '') {
            $end_date = $params['EndDate'];
        }

        $page_number = 1;
        if (isset($params['pageNumber']) && $params['pageNumber'] !== '') {
            $page_number = max(1, (int) $params['pageNumber']);
        } elseif (isset($params['page']) && $params['page'] !== '') {
            // Internal callers use zero-based page; Pazarama request body expects pageNumber.
            $page_number = max(1, ((int) $params['page']) + 1);
        }

        $page_size = 500;
        if (isset($params['pageSize'])) {
            $page_size = max(1, min(500, (int) $params['pageSize']));
        } elseif (isset($params['size'])) {
            $page_size = max(1, min(500, (int) $params['size']));
        }

        $request_payload = array(
            'startDate' => $start_date,
            'endDate' => $end_date,
            'pageNumber' => $page_number,
            'pageSize' => $page_size,
        );

        $optional_keys = array(
            'status',
            'orderCode',
        );
        foreach ($optional_keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $request_payload[$key] = $params[$key];
            }
        }

        $url = self::API_BASE . '/order/getOrdersForApi';
        $response = $this->request_json_with_token_refresh('POST', $url, $supplier, $request_payload);
        $items = array();

        if (is_wp_error($response)) {
            // Backward-compatible fallback for environments expecting query params with GET.
            $error_data = $response->get_error_data();
            $status_code = is_array($error_data) && isset($error_data['code']) ? (int) $error_data['code'] : 0;
            if (!in_array($status_code, array(404, 405, 415), true)) {
                return $response;
            }

            $fallback_query = $request_payload;
            $fallback_query['page'] = max(0, $page_number - 1);
            $fallback_url = self::API_BASE . '/order/getOrdersForApi?' . http_build_query($fallback_query);
            $fallback_response = $this->request_json_with_token_refresh('GET', $fallback_url, $supplier);
            if (is_wp_error($fallback_response)) {
                return $response;
            }
            $items = $this->extract_list($fallback_response['data'], array('orders', 'items', 'content', 'data', 'result'));
        } else {
            $items = $this->extract_list($response['data'], array('orders', 'items', 'content', 'data', 'result'));
        }

        return $this->merge_orders_by_code($items);
    }

    public function map_product($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $sku = trim((string) $this->first_not_empty($item, array('stockCode'), ''));
        if ($sku === '') {
            $title = (string) $this->first_not_empty($item, array('name', 'title', 'code'), 'unknown');
            return new \WP_Error(
                'multi_sync_pazarama_missing_stock_code',
                sprintf('Pazarama urununde stockCode zorunlu. Urun: %s', $title)
            );
        }

        $images = array();
        $image_items = $this->first_not_empty($item, array('images', 'imageUrls', 'pictures'), array());
        if (is_array($image_items)) {
            foreach ($image_items as $image_item) {
                if (is_string($image_item) && $image_item !== '') {
                    $images[] = $image_item;
                    continue;
                }

                $image = is_array($image_item) ? $image_item : (array) $image_item;
                $url = $this->first_not_empty($image, array('url', 'imageUrl', 'src'));
                if (is_string($url) && $url !== '') {
                    $images[] = $url;
                }
            }
        }

        return array(
            'code' => (string) $this->first_not_empty($item, array('code', 'Code', 'productCode'), ''),
            'sku' => $sku,
            'name' => (string) $this->first_not_empty($item, array('name', 'title', 'displayName'), $sku),
            'regular_price' => $this->to_float($this->first_not_empty($item, array('listPrice', 'price', 'salePrice')), null),
            'sale_price' => $this->to_float($this->first_not_empty($item, array('salePrice')), null),
            'stock_quantity' => $this->to_int($this->first_not_empty($item, array('quantity', 'stockQuantity', 'stock')), 0),
            'images' => $images,
            'preview_image' => !empty($images) ? $images[0] : '',
            'external_sku' => $sku,
            'external_barcode' => (string) $this->first_not_empty($item, array('barcode'), ''),
            'external_product_id' => (string) $this->first_not_empty($item, array('code', 'Code', 'productCode', 'id'), ''),
            'parent_key' => '',
            'variation_attributes' => array(),
        );
    }

    public function map_order($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;
        $shipping = $this->extract_address($item, array('shippingAddress', 'shipmentAddress'));
        $billing = $this->extract_address($item, array('invoiceAddress', 'billingAddress'));

        $shipping_name = $this->extract_name_parts($shipping);
        $billing_name = $this->extract_name_parts($billing);

        $raw_lines = $this->extract_order_lines($item);
        $line_items = array();
        $fallback_total = 0.0;

        foreach ($raw_lines as $line) {
            $line = is_array($line) ? $line : (array) $line;
            $product = isset($line['product']) && is_array($line['product']) ? $line['product'] : array();

            $quantity = (int) $this->to_int($this->first_not_empty($line, array('quantity', 'count')), 1);
            if ($quantity <= 0) {
                $quantity = 1;
            }

            $unit_price = $this->money_to_float(
                $this->first_not_empty($line, array('unitPrice', 'price', 'salePrice', 'paidPrice')),
                null
            );
            $line_total = $this->money_to_float(
                $this->first_not_empty($line, array('totalPrice', 'amount', 'lineAmount')),
                null
            );

            if ($unit_price === null && $line_total !== null && $quantity > 0) {
                $unit_price = $line_total / $quantity;
            }

            $unit_price = $unit_price !== null ? (float) $unit_price : 0.0;
            $fallback_total += ($unit_price * $quantity);

            $line_items[] = array(
                'sku' => (string) $this->first_not_empty($product, array('stockCode'), $this->first_not_empty($line, array('stockCode', 'sku'), '')),
                'name' => (string) $this->first_not_empty($product, array('name', 'title', 'productName'), $this->first_not_empty($line, array('name', 'productName'), '')),
                'quantity' => $quantity,
                'price' => $unit_price,
            );
        }

        $total = $this->money_to_float(
            $this->first_not_empty($item, array('mergedTotalPrice', 'orderAmount', 'totalPrice', 'amount')),
            null
        );
        if ($total === null) {
            $total = $fallback_total;
        }

        $email = (string) $this->first_not_empty(
            $item,
            array('customerEmail', 'email'),
            $this->first_not_empty($billing, array('customerEmail', 'email'), '')
        );

        $status = $this->normalize_pazarama_status($item, $raw_lines);

        return array(
            'external_id' => (string) $this->first_not_empty($item, array('orderNumber', 'orderCode', 'orderId', 'id'), ''),
            'status' => $status,
            'currency' => (string) $this->first_not_empty($item, array('currencyCode', 'currency'), 'TRY'),
            'total' => (float) $total,
            'order_date' => $this->first_not_empty($item, array('orderDate', 'createdDate', 'createDate'), ''),
            'billing_first_name' => $billing_name[0],
            'billing_last_name' => $billing_name[1],
            'billing_phone' => (string) $this->first_not_empty($billing, array('phoneNumber', 'phone', 'gsm'), ''),
            'billing_email' => $email,
            'billing_address_1' => (string) $this->first_not_empty($billing, array('displayAddressText', 'addressDetail', 'address', 'fullAddress', 'address1'), ''),
            'billing_city' => (string) $this->first_not_empty($billing, array('city', 'cityName'), ''),
            'billing_postcode' => (string) $this->first_not_empty($billing, array('postalCode', 'zipCode'), ''),
            'billing_country' => (string) $this->first_not_empty($billing, array('countryCode', 'country'), 'TR'),
            'shipping_first_name' => $shipping_name[0],
            'shipping_last_name' => $shipping_name[1],
            'shipping_phone' => (string) $this->first_not_empty($shipping, array('phoneNumber', 'phone', 'gsm'), ''),
            'shipping_address_1' => (string) $this->first_not_empty($shipping, array('displayAddressText', 'addressDetail', 'address', 'fullAddress', 'address1'), ''),
            'shipping_city' => (string) $this->first_not_empty($shipping, array('city', 'cityName'), ''),
            'shipping_postcode' => (string) $this->first_not_empty($shipping, array('postalCode', 'zipCode'), ''),
            'shipping_country' => (string) $this->first_not_empty($shipping, array('countryCode', 'country'), 'TR'),
            'line_items' => $line_items,
        );
    }

    public function build_price_inventory_item_from_product($product, $sync_stock = true, $sync_price = true, $commission_rate = null)
    {
        if (!$product || !is_callable(array($product, 'get_sku'))) {
            return null;
        }

        $woo_sku = trim((string) $product->get_sku());
        $sku = is_callable(array($product, 'get_meta')) ? trim((string) $product->get_meta('_multi_sync_external_sku', true)) : '';
        if ($sku === '') {
            $sku = $woo_sku;
        }
        if ($sku === '') {
            return null;
        }

        $stored_code = '';
        if (is_callable(array($product, 'get_meta'))) {
            $stored_code = trim((string) $product->get_meta('_multi_sync_pazarama_code', true));
        }
        $product_code = $stored_code !== '' ? $stored_code : $sku;

        $item = array(
            'code' => $product_code,
            'stockCode' => $sku,
        );

        if ($sync_stock) {
            $stock = $product->get_stock_quantity();
            if ($stock === null || $stock === '') {
                $stock = 0;
            }
            $item['quantity'] = max(0, (int) $stock);
        }

        if ($sync_price) {
            $regular_raw = is_numeric($product->get_regular_price()) ? (float) $product->get_regular_price() : 0.0;
            $regular = $this->apply_product_commission($regular_raw, $product, $commission_rate);
            $sale_raw = is_callable(array($product, 'get_sale_price')) ? $product->get_sale_price() : '';
            $sale = is_numeric($sale_raw) && (float) $sale_raw > 0 && (float) $sale_raw < $regular_raw ? $this->apply_product_commission((float) $sale_raw, $product, $commission_rate) : $regular;
            $item['listPrice'] = $regular > 0 ? $regular : $sale;
            $item['salePrice'] = $sale;
        }

        return $item;
    }

    public function fetch_product_categories($supplier, $search = '')
    {
        $token = $this->ensure_access_token($supplier);
        if (is_wp_error($token)) return $token;
        $response = $this->request_json_with_token_refresh('GET', self::API_BASE . '/category/getCategoryTree', $supplier);
        if (is_wp_error($response)) return $response;
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
        $result = array();
        foreach ($this->extract_list($response['data'], array('data')) as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if (empty($row['leaf']) || empty($row['id'])) continue;
            $path = implode(' > ', array_filter(array_merge((array) ($row['parentCategories'] ?? array()), array((string) ($row['displayName'] ?? $row['name'] ?? '')))));
            if ($needle === '' || mb_strpos(mb_strtolower($path, 'UTF-8'), $needle) !== false) {
                $result[] = array('id' => (string) $row['id'], 'name' => (string) ($row['displayName'] ?? $row['name'] ?? ''), 'path' => $path);
            }
        }
        return $result;
    }

    public function fetch_product_brands($supplier, $search = '', $category_id = '')
    {
        $token = $this->ensure_access_token($supplier);
        if (is_wp_error($token)) return $token;
        $url = self::API_BASE . '/brand/getBrands?' . http_build_query(array('Page' => 1, 'Size' => 100, 'name' => trim((string) $search)));
        $response = $this->request_json_with_token_refresh('GET', $url, $supplier);
        if (is_wp_error($response)) return $response;
        $result = array();
        foreach ($this->extract_list($response['data'], array('data', 'items')) as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if (!empty($row['id'])) $result[] = array('id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? ''));
        }
        return $result;
    }

    public function fetch_category_attributes($supplier, $category_id)
    {
        $token = $this->ensure_access_token($supplier);
        if (is_wp_error($token)) return $token;
        $url = self::API_BASE . '/category/getCategoryWithAttributes?' . http_build_query(array('Id' => (string) $category_id));
        $response = $this->request_json_with_token_refresh('GET', $url, $supplier);
        if (is_wp_error($response)) return $response;
        $data = isset($response['data']['data']) && is_array($response['data']['data']) ? $response['data']['data'] : (array) ($response['data'] ?? array());
        $result = array();
        foreach ((array) ($data['attributes'] ?? array()) as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if (empty($row['id']) || (empty($row['isRequired']) && empty($row['isVariantable']))) continue;
            $values = array();
            foreach ((array) ($row['attributeValues'] ?? array()) as $option) {
                $option = is_array($option) ? $option : (array) $option;
                if (!empty($option['id'])) $values[] = array('id' => (string) $option['id'], 'name' => (string) ($option['value'] ?? ''));
            }
            $result[] = array('id' => (string) $row['id'], 'name' => (string) ($row['displayName'] ?? $row['name'] ?? ''), 'required' => !empty($row['isRequired']), 'slicer' => false, 'varianter' => !empty($row['isVariantable']), 'allow_custom' => false, 'values' => $values);
        }
        return $result;
    }

    public function build_product_item_from_product($product, $category_mapping = array(), $overrides = array())
    {
        $parent = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;
        if (!$product || (!$product->is_type('simple') && !$parent)) return new \WP_Error('multi_sync_pazarama_unsupported_product', 'Yalnizca basit urunler ve varyasyonlar gonderilebilir.');
        $value = function ($key, $fallback = '') use ($overrides, $product, $parent) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') return trim((string) $overrides[$key]);
            $stored = trim((string) $product->get_meta('_multi_sync_pazarama_' . $key, true));
            if ($stored === '' && $parent) $stored = trim((string) $parent->get_meta('_multi_sync_pazarama_' . $key, true));
            return $stored !== '' ? $stored : $fallback;
        };
        $sku = $value('sku', $product->get_sku());
        $code = $value('barcode', $sku);
        $group = $value('product_main_id', $parent ? $parent->get_sku() : $sku);
        $category = $value('category_id', $category_mapping['category_id'] ?? '');
        $brand = $value('brand_id', $category_mapping['brand_id'] ?? '');
        $desi = $value('desi', $this->get_product_desi($product));
        $vat = $this->get_product_vat_rate($product, $value('vat_rate'));
        $missing = array();
        foreach (array('sku' => array('SKU / Stok Kodu', $sku), 'barcode' => array('Barkod', $code), 'product_main_id' => array('Grup / Model Kodu', $group), 'category_id' => array('Pazarama Kategori ID', $category), 'brand_id' => array('Pazarama Marka ID', $brand), 'desi' => array('Desi', $desi)) as $key => $field) {
            if ($field[1] === '') $missing[] = array('key' => $key, 'label' => $field[0], 'type' => $key === 'desi' ? 'number' : 'text', 'options' => array());
        }
        if ($vat === '' || !is_numeric($vat)) $missing[] = array('key' => 'vat_rate', 'label' => 'KDV Orani', 'type' => 'number', 'options' => array());
        $attributes = array();
        $mapped_attributes = array();
        foreach ((array) ($category_mapping['attributes'] ?? array()) as $mapped) {
            if (!empty($mapped['attributeId'])) $mapped_attributes[(string) $mapped['attributeId']] = (string) (($mapped['attributeValueIds'][0] ?? null) ?: ($mapped['attributeValue'] ?? ''));
        }
        $color = $this->pazarama_variation_color($product, $parent);
        foreach ((array) ($category_mapping['attribute_definitions'] ?? array()) as $definition) {
            $id = (string) ($definition['id'] ?? '');
            if ($id === '') continue;
            $input = $value('attribute_' . $id);
            if ($input === '') $input = $mapped_attributes[$id] ?? '';
            $is_color = $this->pazarama_normalize($definition['name'] ?? '') === 'renk';
            if ($input === '' && $is_color) {
                foreach ((array) ($definition['values'] ?? array()) as $option) {
                    if ($this->pazarama_normalize($option['name'] ?? '') === $this->pazarama_normalize($color)) { $input = (string) $option['id']; break; }
                }
            }
            if ($input === '' && $this->pazarama_normalize($definition['name'] ?? '') === 'desi') {
                $input = $this->get_product_desi($product);
            }
            if ($input !== '') $attributes[] = array('attributeId' => $id, 'attributeValueId' => $input);
            elseif (!empty($definition['required']) || ($parent && $is_color)) $missing[] = array('key' => 'attribute_' . $id, 'label' => (string) ($definition['name'] ?? $id), 'type' => !empty($definition['values']) ? 'select' : 'text', 'options' => (array) ($definition['values'] ?? array()), 'suggested_value' => $is_color ? $color : '');
        }
        $price = $this->build_price_inventory_item_from_product($product, true, true, $category_mapping['commission_rate'] ?? null);
        if (!$price || $price['listPrice'] <= 0 || $price['salePrice'] <= 0) return new \WP_Error('multi_sync_pazarama_product_price', 'Urun fiyati sifirdan buyuk olmali.');
        $images = $this->pazarama_images($product, $parent, $value('image_url'));
        if (!$images) $missing[] = array('key' => 'image_url', 'label' => 'Gorsel URL', 'type' => 'text', 'options' => array());
        if ($missing) return new \WP_Error('multi_sync_pazarama_product_incomplete', 'Eksik Pazarama bilgilerini doldurun.', array('fields' => $missing));
        $description = $product->get_description() ?: ($parent ? $parent->get_description() : '') ?: $product->get_short_description() ?: $product->get_name();
        $export_name = $this->product_export_name($product, $parent);
        return array('code' => $code, 'name' => mb_substr($export_name, 0, 100), 'displayName' => mb_substr($export_name, 0, 250), 'description' => mb_substr(wp_strip_all_tags($description), 0, 500), 'groupCode' => mb_substr($group, 0, 10), 'brandId' => $brand, 'desi' => (int) $desi, 'stockCount' => max(0, (int) $price['quantity']), 'stockCode' => $sku, 'currencyType' => 'TRY', 'listPrice' => $price['listPrice'], 'salePrice' => $price['salePrice'], 'vatRate' => (int) $vat, 'images' => $images, 'categoryId' => $category, 'attributes' => $attributes);
    }

    public function push_products($supplier, $items)
    {
        $token = $this->ensure_access_token($supplier);
        if (is_wp_error($token)) return $token;
        $response = $this->request_json_with_token_refresh('POST', self::API_BASE . '/product/create', $supplier, array('products' => array_values($items)));
        return is_wp_error($response) ? $response : $response['data'];
    }

    private function pazarama_variation_color($product, $parent)
    {
        if (!$parent) return '';
        foreach ((array) $product->get_attributes() as $name => $value) {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name;
            if ($this->pazarama_normalize($label) !== 'renk' && count($product->get_attributes()) !== 1) continue;
            if (taxonomy_exists($name)) { $term = get_term_by('slug', $value, $name); if ($term && !is_wp_error($term)) return (string) $term->name; }
            return mb_convert_case(str_replace('-', ' ', (string) $value), MB_CASE_TITLE, 'UTF-8');
        }
        return '';
    }

    private function pazarama_normalize($value)
    {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = trim(preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($value, 'UTF-8')));
        if (strpos($value, 'osmanli') !== false) return 'osmanli';
        if (strpos($value, 'turk bayrak') !== false) return 'turk bayrak';
        return in_array($value, array('renk', 'color'), true) ? 'renk' : $value;
    }

    private function pazarama_images($product, $parent, $override)
    {
        $ids = array_merge(array($product->get_image_id()), $parent ? array($parent->get_image_id()) : array(), $parent ? $parent->get_gallery_image_ids() : $product->get_gallery_image_ids());
        $images = preg_match('#^https?://#i', $override) ? array(array('imageurl' => esc_url_raw($override))) : array();
        foreach (array_unique(array_filter($ids)) as $id) { $url = wp_get_attachment_url($id); if (preg_match('#^https?://#i', (string) $url)) $images[] = array('imageurl' => $url); if (count($images) === 8) break; }
        return $images;
    }

    public function push_price_inventory_updates($supplier, $items)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $token_check = $this->ensure_access_token($supplier);
        if (is_wp_error($token_check)) {
            return $token_check;
        }

        if (!is_array($items) || empty($items)) {
            return array(
                'batchRequestIds' => array(),
                'results' => array(),
            );
        }

        $price_items = array();
        $stock_items = array();

        foreach ($items as $item) {
            $item = is_array($item) ? $item : (array) $item;
            $code = trim((string) $this->first_not_empty($item, array('code', 'stockCode'), ''));
            $stock_code = trim((string) $this->first_not_empty($item, array('stockCode', 'code'), ''));
            if ($code === '' && $stock_code === '') {
                continue;
            }

            if (array_key_exists('quantity', $item)) {
                $stock_items[] = array(
                    'code' => $code !== '' ? $code : $stock_code,
                    'stockCode' => $stock_code !== '' ? $stock_code : $code,
                    'quantity' => max(0, (int) $this->to_int($item['quantity'], 0)),
                );
            }

            $has_price = array_key_exists('listPrice', $item) || array_key_exists('salePrice', $item) || array_key_exists('price', $item);
            if ($has_price) {
                $list_price = $this->to_float(
                    $this->first_not_empty($item, array('listPrice', 'salePrice', 'price')),
                    0
                );
                $sale_price = $this->to_float(
                    $this->first_not_empty($item, array('salePrice', 'listPrice', 'price')),
                    0
                );

                $price_items[] = array(
                    'code' => $code !== '' ? $code : $stock_code,
                    'stockCode' => $stock_code !== '' ? $stock_code : $code,
                    'listPrice' => round((float) $list_price, 2),
                    'salePrice' => round((float) $sale_price, 2),
                );
            }
        }

        $results = array();
        $batch_ids = array();

        if (!empty($price_items)) {
            $price_result = $this->request_with_fallback(
                $supplier,
                self::API_BASE . '/product/updatePrice',
                self::API_BASE . '/product/updatePrice-v2',
                array('products' => array_values($price_items))
            );
            if (is_wp_error($price_result)) {
                return $price_result;
            }

            $results['price'] = $price_result;
            $batch_ids = array_merge($batch_ids, $this->extract_batch_request_ids($price_result));
        }

        if (!empty($stock_items)) {
            $stock_result = $this->request_with_fallback(
                $supplier,
                self::API_BASE . '/product/updateStock',
                self::API_BASE . '/product/updateStock-v2',
                array('products' => array_values($stock_items))
            );
            if (is_wp_error($stock_result)) {
                return $stock_result;
            }

            $results['stock'] = $stock_result;
            $batch_ids = array_merge($batch_ids, $this->extract_batch_request_ids($stock_result));
        }

        return array(
            'batchRequestIds' => array_values(array_unique($batch_ids)),
            'results' => $results,
        );
    }

    public function get_batch_request_result($supplier, $batch_request_id)
    {
        return array(
            'batchRequestId' => (string) $batch_request_id,
            'status' => 'unknown',
        );
    }

    private function ensure_access_token($supplier)
    {
        $cached = $this->get_cached_access_token($supplier);
        if ($cached !== '') {
            return $cached;
        }

        $api_key = $this->get_api_key($supplier);
        $api_secret = $this->get_api_secret($supplier);
        $attempts = array(
            array(
                'operation' => 'oauth_token_form_credentials',
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ),
                'body' => array(
                    'grant_type' => 'client_credentials',
                    'scope' => self::TOKEN_SCOPE,
                    'client_id' => $api_key,
                    'client_secret' => $api_secret,
                ),
                'mask_secret_in_body' => true,
            ),
            array(
                'operation' => 'oauth_token_basic_auth',
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
                ),
                'body' => array(
                    'grant_type' => 'client_credentials',
                    'scope' => self::TOKEN_SCOPE,
                ),
                'mask_secret_in_body' => false,
            ),
        );

        $last_error_message = 'Pazarama token alinamadi.';
        foreach ($attempts as $attempt) {
            $request_headers = $attempt['headers'];
            $request_body = http_build_query($attempt['body']);

            $debug_body = $attempt['body'];
            if (!empty($attempt['mask_secret_in_body']) && isset($debug_body['client_secret'])) {
                $debug_body['client_secret'] = $this->mask_sensitive_value($debug_body['client_secret']);
            }

            $debug_entry = array(
                'timestamp' => current_time('mysql'),
                'supplier_id' => $this->get_supplier_row_id($supplier),
                'marketplace_key' => $this->get_key(),
                'operation' => $attempt['operation'],
                'request' => array(
                    'method' => 'POST',
                    'url' => self::TOKEN_URL,
                    'headers' => $this->sanitize_headers_for_debug($request_headers),
                    'body' => $this->truncate_debug_body($debug_body),
                ),
                'response' => array(),
            );

            $response = wp_remote_post(self::TOKEN_URL, array(
                'timeout' => 30,
                'redirection' => 5,
                'headers' => $request_headers,
                'body' => $request_body,
            ));

            if (is_wp_error($response)) {
                $debug_entry['response'] = array(
                    'error' => $response->get_error_message(),
                );
                $this->store_http_debug($supplier, $debug_entry);
                $last_error_message = 'Pazarama token istegi basarisiz: ' . $response->get_error_message();
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $debug_entry['response'] = array(
                'status_code' => $code,
                'body' => $this->truncate_debug_body($raw_body),
            );
            $this->store_http_debug($supplier, $debug_entry);

            if ($code >= 400) {
                $last_error_message = sprintf(
                    'Pazarama token istegi basarisiz (%d): %s',
                    $code,
                    $this->truncate_error_body_for_message($raw_body)
                );
                continue;
            }

            $parsed = $this->parse_token_response($raw_body);
            if ($parsed['token'] === '') {
                $last_error_message = sprintf(
                    'Pazarama token yanitinda access token bulunamadi. Yanit: %s',
                    $this->truncate_error_body_for_message($raw_body)
                );
                continue;
            }

            $token = $parsed['token'];
            $token_type = $parsed['token_type'] !== '' ? $parsed['token_type'] : 'Bearer';
            $expires_in = (int) $parsed['expires_in'];
            if ($expires_in <= 0) {
                $expires_in = 3600;
            }

            $cache_ttl = max(300, $expires_in - 60);
            $cache_key = $this->build_token_cache_key($supplier);
            set_transient($cache_key, $token, $cache_ttl);
            set_transient($cache_key . '_type', $token_type, $cache_ttl);
            $this->in_memory_tokens[$cache_key] = $token;
            $this->in_memory_token_types[$cache_key] = $token_type;

            return $token;
        }

        return new \WP_Error(
            'multi_sync_pazarama_token_missing',
            $last_error_message
        );
    }

    private function get_cached_access_token($supplier)
    {
        $cache_key = $this->build_token_cache_key($supplier);

        if (isset($this->in_memory_tokens[$cache_key]) && is_string($this->in_memory_tokens[$cache_key])) {
            return $this->in_memory_tokens[$cache_key];
        }

        $token = get_transient($cache_key);
        if ($token !== false && is_string($token) && $token !== '') {
            $this->in_memory_tokens[$cache_key] = $token;
            return $token;
        }

        return '';
    }

    private function get_cached_token_type($supplier)
    {
        $cache_key = $this->build_token_cache_key($supplier);

        if (isset($this->in_memory_token_types[$cache_key]) && is_string($this->in_memory_token_types[$cache_key])) {
            return $this->in_memory_token_types[$cache_key];
        }

        $token_type = get_transient($cache_key . '_type');
        if ($token_type !== false && is_string($token_type) && $token_type !== '') {
            $this->in_memory_token_types[$cache_key] = $token_type;
            return $token_type;
        }

        return 'Bearer';
    }

    private function build_token_cache_key($supplier)
    {
        $supplier_id = $this->get_supplier_row_id($supplier);
        $api_key = $this->get_api_key($supplier);
        $api_secret = $this->get_api_secret($supplier);

        return 'multi_sync_pazarama_token_' . md5($supplier_id . '|' . $api_key . '|' . $api_secret);
    }

    private function parse_token_response($raw_body)
    {
        $result = array(
            'token' => '',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        );

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            return $result;
        }

        $candidates = $this->collect_candidate_nodes_for_token($data);
        foreach ($candidates as $node) {
            if (!is_array($node)) {
                continue;
            }

            $token = (string) $this->first_not_empty($node, array('access_token', 'accessToken', 'token', 'Token'), '');
            if ($token !== '') {
                $result['token'] = $token;
                $result['token_type'] = (string) $this->first_not_empty($node, array('token_type', 'tokenType'), 'Bearer');
                $expires = $this->to_int($this->first_not_empty($node, array('expires_in', 'expiresIn', 'expires')), 3600);
                $result['expires_in'] = $expires ? (int) $expires : 3600;
                return $result;
            }
        }

        return $result;
    }

    private function collect_candidate_nodes_for_token($root)
    {
        $nodes = array();
        if (!is_array($root)) {
            return $nodes;
        }

        $nodes[] = $root;
        $keys = array('data', 'result', 'response', 'payload', 'value');
        foreach ($keys as $key) {
            if (!isset($root[$key])) {
                continue;
            }
            if (is_array($root[$key])) {
                $nodes[] = $root[$key];
            }
        }

        return $nodes;
    }

    private function truncate_error_body_for_message($raw_body)
    {
        $raw_body = trim((string) $raw_body);
        if ($raw_body === '') {
            return '(bos yanit)';
        }

        if (strlen($raw_body) <= 300) {
            return $raw_body;
        }

        return substr($raw_body, 0, 300) . '...';
    }

    private function clear_cached_access_token($supplier)
    {
        $cache_key = $this->build_token_cache_key($supplier);
        delete_transient($cache_key);
        delete_transient($cache_key . '_type');
        unset($this->in_memory_tokens[$cache_key]);
        unset($this->in_memory_token_types[$cache_key]);
    }

    private function request_with_fallback($supplier, $v1_endpoint, $v2_endpoint, $payload)
    {
        $v1_response = $this->request_json_with_token_refresh('POST', $v1_endpoint, $supplier, $payload);
        if (!is_wp_error($v1_response)) {
            return $this->normalize_push_response($v1_response['data'], 'v1');
        }

        if (!$this->should_retry_with_v2($v1_response)) {
            return $v1_response;
        }

        $v2_response = $this->request_json_with_token_refresh('POST', $v2_endpoint, $supplier, $payload);
        if (is_wp_error($v2_response)) {
            return $v2_response;
        }

        return $this->normalize_push_response($v2_response['data'], 'v2');
    }

    private function should_retry_with_v2($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $error_data = $error->get_error_data();
        if (!is_array($error_data) || !isset($error_data['code'])) {
            return false;
        }

        $status_code = (int) $error_data['code'];
        return in_array($status_code, array(404, 405), true);
    }

    private function request_json_with_token_refresh($method, $url, $supplier, $payload = null)
    {
        $response = $this->request_json($method, $url, $supplier, $payload);
        if (!is_wp_error($response)) {
            return $response;
        }

        $error_data = $response->get_error_data();
        $status_code = is_array($error_data) && isset($error_data['code']) ? (int) $error_data['code'] : 0;
        if ($status_code !== 401) {
            return $response;
        }

        $this->clear_cached_access_token($supplier);
        $token_check = $this->ensure_access_token($supplier);
        if (is_wp_error($token_check)) {
            return $response;
        }

        return $this->request_json($method, $url, $supplier, $payload);
    }

    private function normalize_push_response($data, $version)
    {
        if (is_array($data)) {
            $data['_endpoint_version'] = $version;
            return $data;
        }

        return array(
            '_endpoint_version' => $version,
            'value' => $data,
        );
    }

    private function extract_batch_request_ids($response_data)
    {
        if (!is_array($response_data)) {
            return array();
        }

        $ids = array();
        $direct_keys = array('batchRequestId', 'taskId', 'id', 'requestId');
        foreach ($direct_keys as $key) {
            if (!isset($response_data[$key])) {
                continue;
            }

            $value = $response_data[$key];
            if (is_scalar($value) && (string) $value !== '') {
                $ids[] = (string) $value;
            }
        }

        $array_keys = array('batchRequestIds', 'ids', 'taskIds');
        foreach ($array_keys as $key) {
            if (!isset($response_data[$key]) || !is_array($response_data[$key])) {
                continue;
            }

            foreach ($response_data[$key] as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $ids[] = (string) $value;
                }
            }
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $ids = array_merge($ids, $this->extract_batch_request_ids($response_data['data']));
        }

        return array_values(array_unique($ids));
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
            array('nameSurname', 'fullName', 'companyName', 'title'),
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
        $lines = $this->first_not_empty($item, array('lines', 'orderItems', 'items', 'products'), array());
        if (!is_array($lines)) {
            return array();
        }

        return array_values($lines);
    }

    private function merge_orders_by_code($items)
    {
        if (!is_array($items) || empty($items)) {
            return array();
        }

        $merged = array();
        foreach ($items as $index => $raw_item) {
            $item = is_array($raw_item) ? $raw_item : (array) $raw_item;
            $order_code = trim((string) $this->first_not_empty($item, array('orderNumber', 'orderCode', 'orderId'), ''));
            $key = $order_code !== '' ? $order_code : 'row_' . $index;

            $lines = $this->extract_order_lines($item);
            $item_total = (float) $this->money_to_float(
                $this->first_not_empty($item, array('orderAmount', 'totalPrice', 'amount')),
                0
            );

            if (!isset($merged[$key])) {
                $item['lines'] = $lines;
                $item['mergedTotalPrice'] = $item_total;
                $merged[$key] = $item;
                continue;
            }

            $existing = $merged[$key];
            if (!isset($existing['lines']) || !is_array($existing['lines'])) {
                $existing['lines'] = array();
            }
            $existing['lines'] = array_merge($existing['lines'], $lines);

            $existing_total = (float) $this->to_float($this->first_not_empty($existing, array('mergedTotalPrice')), 0);
            $existing['mergedTotalPrice'] = $existing_total + $item_total;

            $existing_date = strtotime((string) $this->first_not_empty($existing, array('orderDate', 'createdDate', 'createDate'), ''));
            $item_date = strtotime((string) $this->first_not_empty($item, array('orderDate', 'createdDate', 'createDate'), ''));
            if ($item_date && (!$existing_date || $item_date > $existing_date)) {
                $fields_to_refresh = array(
                    'orderStatus',
                    'status',
                    'orderNumber',
                    'orderId',
                    'orderDate',
                    'createdDate',
                    'createDate',
                    'shippingAddress',
                    'shipmentAddress',
                    'invoiceAddress',
                    'billingAddress',
                    'customerEmail',
                    'customerName',
                    'email',
                    'currencyCode',
                    'currency',
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

    private function normalize_pazarama_status($item, $raw_lines = array())
    {
        $status_name = '';
        if (is_array($raw_lines)) {
            foreach ($raw_lines as $line) {
                $line = is_array($line) ? $line : (array) $line;
                $candidate = (string) $this->first_not_empty($line, array('orderItemStatusName', 'statusName'), '');
                if ($candidate !== '') {
                    $status_name = $candidate;
                    break;
                }
            }
        }

        if ($status_name === '') {
            $status_name = (string) $this->first_not_empty($item, array('orderStatusName', 'statusName', 'status'), '');
        }

        $normalized_name = $this->normalize_status_text($status_name);
        if ($normalized_name !== '') {
            if (strpos($normalized_name, 'teslim') !== false) {
                return 'delivered';
            }
            if (strpos($normalized_name, 'kargo') !== false || strpos($normalized_name, 'sevk') !== false) {
                return 'shipped';
            }
            if (strpos($normalized_name, 'iptal') !== false) {
                return 'cancelled';
            }
            if (strpos($normalized_name, 'iade') !== false) {
                return 'refunded';
            }
            if (strpos($normalized_name, 'bekle') !== false) {
                return 'pending';
            }
            if (strpos($normalized_name, 'hazir') !== false) {
                return 'processing';
            }
        }

        $status_numeric = $this->to_int($this->first_not_empty($item, array('orderStatus')), null);
        if ($status_numeric !== null) {
            $map = array(
                1 => 'pending',
                2 => 'processing',
                3 => 'processing',
                4 => 'shipped',
                5 => 'completed',
                6 => 'cancelled',
                7 => 'refunded',
            );
            if (isset($map[$status_numeric])) {
                return $map[$status_numeric];
            }
        }

        return 'pending';
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
}
