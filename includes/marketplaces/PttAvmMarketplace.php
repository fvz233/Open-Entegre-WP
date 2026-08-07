<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class PttAvmMarketplace extends BaseMarketplace
{
    private const WSDL_URL = 'https://ws.pttavm.com:93/service.svc/service?wsdl';
    private const SERVICE_URL = 'https://ws.pttavm.com:93/service.svc';
    private const SOAP_ACTION_PREFIX = 'http://tempuri.org/IService/';
    private const ORDER_LOOKBACK_DAYS = 7;
    private const MAX_REQUEST_RETRIES = 3;
    private const PUSH_CHUNK_SIZE = 500;
    private const DEFAULT_VAT_RATE_PERCENT = 20.0;
    private const REST_API_BASE = 'https://integration-api.pttavm.com/api/v1';

    public function get_key()
    {
        return 'pttavm';
    }

    public function get_label()
    {
        return 'PTTAVM';
    }

    public function validate_credentials($supplier)
    {
        $api_key = $this->get_api_key($supplier);
        $api_secret = $this->get_api_secret($supplier);

        if ($api_key === '' || $api_secret === '') {
            return new \WP_Error(
                'multi_sync_missing_credentials',
                'Eksik yetki bilgisi: PTTAVM icin kullanici adi ve sifre zorunludur.'
            );
        }

        return true;
    }

    protected function build_default_headers($supplier)
    {
        return array(
            'Api-Key' => trim((string) $this->supplier_value($supplier, 'ptt_rest_api_key', '')),
            'Access-Token' => trim((string) $this->supplier_value($supplier, 'ptt_access_token', '')),
            'X-Correlation-Id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('multi-sync-', true),
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

        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        $payload = $this->build_stock_control_payload($params);
        $response = $this->request_soap($supplier, 'StokKontrolListesi', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        $result = $this->extract_method_payload($response['data'], 'StokKontrolListesi');
        $items = $this->extract_list_flexible($result, array(
            'StockList',
            'StokListesi',
            'Products',
            'Urunler',
            'items',
            'data',
            'result',
        ));

        if (empty($items) && is_array($result) && $this->looks_like_product($result)) {
            return array($result);
        }

        // Some PTTAVM tenants behave differently for page indexing/filter defaults.
        // If first page is empty, try a few safe payload variations before giving up.
        if (empty($items) && $page === 0) {
            $fallback_payloads = $this->build_stock_control_fallback_payloads($params, $payload);
            foreach ($fallback_payloads as $fallback_payload) {
                $fallback_response = $this->request_soap($supplier, 'StokKontrolListesi', $fallback_payload);
                if (is_wp_error($fallback_response)) {
                    continue;
                }

                $fallback_result = $this->extract_method_payload($fallback_response['data'], 'StokKontrolListesi');
                $fallback_items = $this->extract_list_flexible($fallback_result, array(
                    'StockList',
                    'StokListesi',
                    'Products',
                    'Urunler',
                    'items',
                    'data',
                    'result',
                ));

                if (empty($fallback_items) && is_array($fallback_result) && $this->looks_like_product($fallback_result)) {
                    return array($fallback_result);
                }

                if (!empty($fallback_items)) {
                    return $fallback_items;
                }
            }
        }

        if (!empty($items) && is_array($items)) {
            $items = $this->hydrate_products_with_barcode_details($supplier, $items);
        }

        return $items;
    }

    public function fetch_orders($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $request_payload = $this->build_order_control_payload($params);
        $used_method = 'SiparisKontrolListesiV2';

        $response = $this->request_soap($supplier, 'SiparisKontrolListesiV2', $request_payload);
        if (is_wp_error($response) && $this->is_method_not_supported_fault($response)) {
            $used_method = 'SiparisKontrolV2';
            $response = $this->request_soap($supplier, 'SiparisKontrolV2', $request_payload);
        }

        if (is_wp_error($response) && $this->is_method_not_supported_fault($response)) {
            $used_method = 'SiparisKontrolListesi';
            $response = $this->request_soap($supplier, 'SiparisKontrolListesi', $request_payload);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $result = $this->extract_method_payload($response['data'], $used_method);
        $orders = $this->extract_list_flexible($result, array(
            'SiparisListesi',
            'SiparisList',
            'Orders',
            'OrderList',
            'order',
            'items',
            'data',
            'result',
        ));

        if ($used_method === 'SiparisKontrolListesi') {
            $orders = $this->merge_v1_order_rows($orders);
        }

        if (empty($orders) && is_array($result) && $this->looks_like_order($result)) {
            return array($result);
        }

        return $orders;
    }

    public function map_product($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $sku = trim((string) $this->find_first_key_value($item, array(
            'StockCode',
            'stockCode',
            'Barkod',
            'Barcode',
            'SKU',
            'Sku',
                'sellerSku',
        ), ''));

        if ($sku === '') {
            return new \WP_Error(
                'multi_sync_pttavm_missing_sku',
                'PTTAVM urununde SKU/StockCode bulunamadi.'
            );
        }

        $pricing = $this->extract_product_pricing_with_vat($item);
        $price = $pricing['regular_price'];
        $discounted_price = $pricing['discounted_price'];

        // PTT tarafinda stok icin ana kaynak Miktar alanidir.
        $raw_stock = $this->find_first_key_value($item, array(
            'Miktar',
            'miktar',
        ), null);
        if ($raw_stock === null || $raw_stock === '') {
            $raw_stock = $this->find_first_key_value($item, array(
                'Adet',
                'adet',
                'StockCount',
                'StokAdet',
                'StokAdedi',
                'StokMiktar',
                'StokMiktari',
                'StokMiktariAdet',
                'AvailableStock',
                'AvailableQuantity',
                'QuantityAvailable',
                'Inventory',
                'StockQuantity',
                'Stock',
                'Quantity',
                'quantity',
                'Mevcut',
                'mevcut',
            ), null);
        }
        $stock = $this->normalize_quantity($raw_stock, null);

        $result = array(
            'sku' => $sku,
            'name' => (string) $this->find_first_key_value($item, array(
                'ProductName',
                'UrunAdi',
                'Name',
                'name',
                'Title',
                'title',
            ), $sku),
            'images' => array(),
            'preview_image' => '',
            'external_sku' => $sku,
            'external_barcode' => (string) $this->find_first_key_value($item, array('Barkod', 'Barcode'), ''),
            'external_product_id' => (string) $this->find_first_key_value($item, array('ProductId', 'UrunId', 'id'), ''),
            'parent_key' => '',
            'variation_attributes' => array(),
        );

        if ($price !== null) {
            $result['regular_price'] = round((float) $price, 2);
        }

        if ($discounted_price !== null) {
            $result['discounted_price'] = round((float) $discounted_price, 2);
            $result['sale_price'] = round((float) $discounted_price, 2);
        }

        if ($stock !== null) {
            $result['stock_quantity'] = max(0, (int) $stock);
        }

        if (($price === null || $stock === null) && function_exists('multi_sync_debug_log')) {
            static $ptt_map_missing_field_logs = 0;
            if ($ptt_map_missing_field_logs < 5) {
                $ptt_map_missing_field_logs++;
                multi_sync_debug_log(
                    'PTTAVM map_product eksik alan. SKU: ' . $sku .
                    ' | price_raw: ' . print_r($pricing['raw_regular'], true) .
                    ' | discount_raw: ' . print_r($pricing['raw_discount'], true) .
                    ' | vat_rate: ' . (string) $pricing['vat_rate'] .
                    ' | stock_raw: ' . print_r($raw_stock, true) .
                    ' | top_keys: ' . implode(',', array_slice(array_map('strval', array_keys($item)), 0, 30))
                );
            }
        }

        return $result;
    }

    public function map_order($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;
        $line_items = $this->parse_line_items_from_order($item);

        if (empty($line_items)) {
            $fallback_line = $this->build_single_line_from_flat_row($item);
            if (!empty($fallback_line)) {
                $line_items[] = $fallback_line;
            }
        }

        $total = $this->normalize_money($this->find_first_key_value($item, array(
            'ToplamTutar',
            'TotalPrice',
            'OrderTotal',
            'total',
            'amount',
        ), null), null);

        if ($total === null) {
            $total = 0.0;
            foreach ($line_items as $line_item) {
                $quantity = isset($line_item['quantity']) ? max(1, (int) $line_item['quantity']) : 1;
                $price = isset($line_item['price']) ? (float) $line_item['price'] : 0.0;
                $total += ($price * $quantity);
            }
        }

        $shipping_address = $this->extract_address_from_order($item, 'shipping');
        $billing_address = $this->extract_address_from_order($item, 'billing');
        $shipping_name = $this->split_full_name((string) $this->find_first_key_value($shipping_address, array('name', 'fullName', 'full_name'), ''));
        $billing_name = $this->split_full_name((string) $this->find_first_key_value($billing_address, array('name', 'fullName', 'full_name'), ''));

        if ($billing_name[0] === '' && $shipping_name[0] !== '') {
            $billing_name = $shipping_name;
        }
        if ($shipping_name[0] === '' && $billing_name[0] !== '') {
            $shipping_name = $billing_name;
        }

        $raw_status = (string) $this->find_first_key_value($item, array(
            'SiparisDurumu',
            'OrderStatus',
            'Status',
            'statu',
            'status',
        ), 'pending');

        $external_id = (string) $this->find_first_key_value($item, array(
            'SiparisId',
            'OrderId',
            'orderId',
            'SiparisNo',
            'OrderNo',
            'orderNo',
            'id',
        ), '');

        return array(
            'external_id' => $external_id,
            'status' => $this->normalize_order_status($raw_status),
            'currency' => (string) $this->find_first_key_value($item, array('Currency', 'currency', 'CurrencyCode', 'currencyCode'), 'TRY'),
            'total' => (float) $total,
            'order_date' => (string) $this->find_first_key_value($item, array(
                'SiparisTarihi',
                'OrderDate',
                'CreateDate',
                'CreatedDate',
                'created_at',
            ), ''),
            'billing_first_name' => $billing_name[0],
            'billing_last_name' => $billing_name[1],
            'billing_phone' => (string) $this->find_first_key_value($billing_address, array('phone', 'Phone', 'gsm', 'mobile'), ''),
            'billing_email' => (string) $this->find_first_key_value($item, array('Email', 'email', 'CustomerEmail', 'customerEmail'), ''),
            'billing_address_1' => (string) $this->find_first_key_value($billing_address, array('address', 'Address', 'address1', 'fullAddress'), ''),
            'billing_city' => (string) $this->find_first_key_value($billing_address, array('city', 'City', 'il', 'Il'), ''),
            'billing_postcode' => (string) $this->find_first_key_value($billing_address, array('postalCode', 'PostCode', 'zipCode'), ''),
            'billing_country' => (string) $this->find_first_key_value($billing_address, array('country', 'Country', 'countryCode'), 'TR'),
            'shipping_first_name' => $shipping_name[0],
            'shipping_last_name' => $shipping_name[1],
            'shipping_phone' => (string) $this->find_first_key_value($shipping_address, array('phone', 'Phone', 'gsm', 'mobile'), ''),
            'shipping_address_1' => (string) $this->find_first_key_value($shipping_address, array('address', 'Address', 'address1', 'fullAddress'), ''),
            'shipping_city' => (string) $this->find_first_key_value($shipping_address, array('city', 'City', 'il', 'Il'), ''),
            'shipping_postcode' => (string) $this->find_first_key_value($shipping_address, array('postalCode', 'PostCode', 'zipCode'), ''),
            'shipping_country' => (string) $this->find_first_key_value($shipping_address, array('country', 'Country', 'countryCode'), 'TR'),
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

        $item = array(
            'sku' => $sku,
        );

        if ($sync_stock) {
            $stock = $product->get_stock_quantity();
            if ($stock === null || $stock === '') {
                $stock = 0;
            }
            $item['quantity'] = max(0, (int) $stock);
        }

        if ($sync_price) {
            $regular_price = $product->get_regular_price();
            $sale_price = is_callable(array($product, 'get_sale_price')) ? $product->get_sale_price() : null;

            $regular_raw = is_numeric($regular_price) ? (float) $regular_price : 0.0;
            $sale_raw = is_numeric($sale_price) ? (float) $sale_price : null;
            $regular = $this->apply_product_commission($regular_raw, $product, $commission_rate);
            $sale = $sale_raw !== null ? $this->apply_product_commission($sale_raw, $product, $commission_rate) : null;

            if ($sale !== null && $sale > 0 && ($regular_raw <= 0 || $sale_raw < $regular_raw)) {
                // Woo fiyatlari KDV dahil kabul edilir; PTT'ye de KDV dahil fiyat gonderiyoruz.
                $item['price'] = $sale;
                $item['list_price'] = $regular > 0 ? $regular : $sale;
            } else {
                $item['price'] = $regular;
                $item['list_price'] = $regular;
            }
        }

        return $item;
    }

    public function fetch_product_categories($supplier, $search = '')
    {
        $credential_check = $this->validate_rest_credentials($supplier);
        if (is_wp_error($credential_check)) return $credential_check;
        $response = $this->request_json('GET', self::REST_API_BASE . '/categories/category-tree', $supplier);
        if (is_wp_error($response)) return $response;
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
        $result = array();
        $walk = function ($nodes, $parents = array()) use (&$walk, &$result, $needle) {
            foreach ((array) $nodes as $node) {
                $node = is_array($node) ? $node : (array) $node;
                $name = trim((string) ($node['name'] ?? ''));
                $path = array_merge($parents, $name !== '' ? array($name) : array());
                $children = is_array($node['children'] ?? null) ? $node['children'] : array();
                if ($children) $walk($children, $path);
                elseif (isset($node['id'])) {
                    $label = implode(' > ', $path);
                    if ($needle === '' || mb_strpos(mb_strtolower($label, 'UTF-8'), $needle) !== false) $result[] = array('id' => (string) $node['id'], 'name' => $name, 'path' => $label);
                }
            }
        };
        $walk($response['data']['category_tree'] ?? array());
        return $result;
    }

    public function fetch_category_attributes($supplier, $category_id)
    {
        return array();
    }

    public function build_product_item_from_product($product, $category_mapping = array(), $overrides = array())
    {
        $parent = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;
        if (!$product || (!$product->is_type('simple') && !$parent)) return new \WP_Error('multi_sync_pttavm_unsupported_product', 'Yalnizca basit urunler ve varyasyonlar gonderilebilir.');
        $value = function ($key, $fallback = '') use ($overrides, $product, $parent) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') return trim((string) $overrides[$key]);
            $stored = trim((string) $product->get_meta('_multi_sync_pttavm_' . $key, true));
            if ($stored === '' && $parent) $stored = trim((string) $parent->get_meta('_multi_sync_pttavm_' . $key, true));
            return $stored !== '' ? $stored : $fallback;
        };
        $sku = $value('sku', $product->get_sku());
        $barcode = $value('barcode', $parent ? $parent->get_sku() : $sku);
        $category = $value('category_id', $category_mapping['category_id'] ?? '');
        $vat = $this->get_product_vat_rate($product, $value('vat_rate'));
        $desi = $value('desi', $this->get_product_desi($product));
        $missing = array();
        foreach (array('sku' => array('SKU / Varyant Barkodu', $sku), 'barcode' => array('Ana Urun Barkodu', $barcode), 'category_id' => array('PTTAVM Kategori ID', $category), 'desi' => array('Desi', $desi)) as $key => $field) {
            if ($field[1] === '') $missing[] = array('key' => $key, 'label' => $field[0], 'type' => $key === 'desi' ? 'number' : 'text', 'options' => array());
        }
        if (!in_array($vat, array('0', '1', '10', '20'), true)) $missing[] = array('key' => 'vat_rate', 'label' => 'KDV Orani', 'type' => 'select', 'options' => array_map(function ($rate) { return array('id' => $rate, 'name' => '%' . $rate); }, array('0', '1', '10', '20')));
        $price = $this->build_price_inventory_item_from_product($product, true, true, $category_mapping['commission_rate'] ?? null);
        if (!$price || $price['price'] <= 0) return new \WP_Error('multi_sync_pttavm_product_price', 'Urun fiyati sifirdan buyuk olmali.');
        $images = $this->ptt_images($product, $parent, $value('image_url'));
        if (!$images) $missing[] = array('key' => 'image_url', 'label' => 'Gorsel URL', 'type' => 'text', 'options' => array());
        if ($missing) return new \WP_Error('multi_sync_pttavm_product_incomplete', 'Eksik PTTAVM bilgilerini doldurun.', array('fields' => $missing));
        $source = $parent ?: $product;
        $description = $source->get_description() ?: $source->get_short_description() ?: $source->get_name();
        $item = array('__parent_id' => (int) $source->get_id(), 'categoryId' => (int) $category, 'barcode' => $barcode, 'name' => mb_substr($this->product_export_name($product, $parent), 0, 200), 'priceWithVat' => $price['price'], 'vatRate' => (int) $vat, 'shortDescription' => mb_substr(wp_strip_all_tags($source->get_short_description() ?: $description), 0, 500), 'longDescription' => $description, 'quantity' => max(0, (int) $price['quantity']), 'desi' => (float) $desi, 'images' => $images, 'active' => true, 'brand' => $value('brand', $category_mapping['brand_name'] ?? ''));
        if ($parent) {
            $color = $this->ptt_variation_color($product, $parent);
            $item['variants'] = array(array('variantBarcode' => $sku, 'attributes' => $color !== '' ? array(array('definition' => 'Renk', 'value' => $color)) : array(), 'quantity' => max(0, (int) $price['quantity']), 'price' => $price['price']));
            $item['quantity'] = 0;
        }
        return $item;
    }

    public function push_products($supplier, $items)
    {
        $credential_check = $this->validate_rest_credentials($supplier);
        if (is_wp_error($credential_check)) return $credential_check;
        $grouped = array();
        foreach ((array) $items as $item) {
            $key = (string) ($item['__parent_id'] ?? $item['barcode'] ?? count($grouped));
            unset($item['__parent_id']);
            if (!isset($grouped[$key])) $grouped[$key] = $item;
            elseif (!empty($item['variants'])) $grouped[$key]['variants'] = array_merge((array) ($grouped[$key]['variants'] ?? array()), $item['variants']);
        }
        $response = $this->request_json('POST', self::REST_API_BASE . '/products/upsert', $supplier, array('items' => array_values($grouped)));
        return is_wp_error($response) ? $response : $response['data'];
    }

    private function validate_rest_credentials($supplier)
    {
        if (trim((string) $this->supplier_value($supplier, 'ptt_rest_api_key', '')) === '' || trim((string) $this->supplier_value($supplier, 'ptt_access_token', '')) === '') {
            return new \WP_Error('multi_sync_pttavm_rest_credentials', 'PTTAVM urun gonderimi icin REST API Key ve Access Token zorunludur.');
        }
        return true;
    }

    private function ptt_variation_color($product, $parent)
    {
        foreach ((array) $product->get_attributes() as $name => $value) {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name;
            $normalized = function_exists('remove_accents') ? remove_accents(mb_strtolower($label, 'UTF-8')) : mb_strtolower($label, 'UTF-8');
            if (trim($normalized) !== 'renk' && count($product->get_attributes()) !== 1) continue;
            if (taxonomy_exists($name)) { $term = get_term_by('slug', $value, $name); if ($term && !is_wp_error($term)) return (string) $term->name; }
            return mb_convert_case(str_replace('-', ' ', (string) $value), MB_CASE_TITLE, 'UTF-8');
        }
        return '';
    }

    private function ptt_images($product, $parent, $override)
    {
        $ids = array_merge(array($product->get_image_id()), $parent ? array($parent->get_image_id()) : array(), $parent ? $parent->get_gallery_image_ids() : $product->get_gallery_image_ids());
        $images = preg_match('#^https?://#', $override) ? array(array('url' => esc_url_raw($override))) : array();
        foreach (array_unique(array_filter($ids)) as $id) { $url = wp_get_attachment_url($id); if (preg_match('#^https?://#', (string) $url)) $images[] = array('url' => $url); if (count($images) === 8) break; }
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
                'accepted' => 0,
                'failed' => 0,
                'results' => array(),
                'batchRequestIds' => array(),
            );
        }

        $normalized_rows = array();
        $results = array();
        foreach ($items as $item) {
            $normalized = $this->normalize_update_item($item);
            if (is_wp_error($normalized)) {
                $results[] = array(
                    'sku' => '',
                    'status' => 'failed',
                    'message' => $normalized->get_error_message(),
                );
                continue;
            }
            $normalized_rows[] = $normalized;
        }

        if (empty($normalized_rows)) {
            return array(
                'accepted' => 0,
                'failed' => count($results),
                'results' => $results,
                'batchRequestIds' => array(),
            );
        }

        $accepted = 0;
        $failed = count($results);
        $batch_ids = array();

        $chunks = array_chunk($normalized_rows, self::PUSH_CHUNK_SIZE);
        foreach ($chunks as $chunk_rows) {
            $chunk_response = $this->push_update_chunk($supplier, $chunk_rows);
            if (is_wp_error($chunk_response)) {
                $failed += count($chunk_rows);
                foreach ($chunk_rows as $row) {
                    $results[] = array(
                        'sku' => $row['sku'],
                        'status' => 'failed',
                        'message' => $chunk_response->get_error_message(),
                    );
                }
                continue;
            }

            $response_payload = $this->extract_method_payload($chunk_response['data'], 'UpdateProductsStockPrice');
            $tracking_id = $this->extract_tracking_id($response_payload);
            if ($tracking_id === '') {
                $tracking_id = $this->extract_tracking_id($chunk_response['data']);
            }

            if ($tracking_id !== '') {
                $batch_ids[] = $tracking_id;
            }

            $accepted += count($chunk_rows);
            foreach ($chunk_rows as $row) {
                $results[] = array(
                    'sku' => $row['sku'],
                    'status' => $tracking_id !== '' ? 'accepted' : 'pending',
                    'tracking_id' => $tracking_id,
                    'message' => $tracking_id !== '' ? 'Kuyruga alindi.' : 'Tracking id donmedi.',
                );
            }
        }

        return array(
            'accepted' => $accepted,
            'failed' => $failed,
            'results' => $results,
            'batchRequestIds' => array_values(array_unique($batch_ids)),
        );
    }

    public function get_batch_request_result($supplier, $batch_request_id)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $batch_request_id = trim((string) $batch_request_id);
        if ($batch_request_id === '') {
            return new \WP_Error(
                'multi_sync_pttavm_batch_id_required',
                'Batch request id zorunludur.'
            );
        }

        $payload_candidates = array(
            array('trackingId' => $batch_request_id),
            array('TrackingId' => $batch_request_id),
            array('id' => $batch_request_id),
        );

        $response = null;
        $last_error = null;
        foreach ($payload_candidates as $payload) {
            $attempt = $this->request_soap($supplier, 'GetProductsTrackingResult', $payload);
            if (is_wp_error($attempt)) {
                $last_error = $attempt;
                if ($this->is_parameter_mismatch_fault($attempt)) {
                    continue;
                }
                return $attempt;
            }

            $response = $attempt;
            break;
        }

        if (!is_array($response)) {
            return $last_error ?: new \WP_Error(
                'multi_sync_pttavm_tracking_query_failed',
                'PTTAVM tracking sonucu sorgulanamadi.'
            );
        }

        $result = $this->extract_method_payload($response['data'], 'GetProductsTrackingResult');
        $raw_status = (string) $this->find_first_key_value($result, array(
            'Status',
            'status',
            'Durum',
        ), '');

        $progress = $this->to_int($this->find_first_key_value($result, array('Progress', 'progress'), null), null);
        $line_items = $this->extract_list_flexible($result, array(
            'ProductTrackingResults',
            'ProductResults',
            'Products',
            'items',
            'data',
            'result',
        ));

        return array(
            'batch_request_id' => $batch_request_id,
            'status' => $this->normalize_tracking_status($raw_status),
            'raw_status' => $raw_status,
            'progress' => $progress,
            'items' => $line_items,
            'data' => $result,
        );
    }

    private function push_update_chunk($supplier, $chunk_rows)
    {
        $payload_candidates = $this->build_update_payload_candidates($chunk_rows);
        $last_error = null;

        foreach ($payload_candidates as $payload) {
            $response = $this->request_soap($supplier, 'UpdateProductsStockPrice', $payload);
            if (is_wp_error($response)) {
                $last_error = $response;
                if ($this->is_parameter_mismatch_fault($response)) {
                    continue;
                }
                return $response;
            }

            return $response;
        }

        return $last_error ?: new \WP_Error(
            'multi_sync_pttavm_update_payload_error',
            'PTTAVM UpdateProductsStockPrice istegi olusturulamadi.'
        );
    }

    private function build_update_payload_candidates($chunk_rows)
    {
        $rows_stock_code = array();
        $rows_barcode = array();
        $rows_generic = array();

        foreach ($chunk_rows as $row) {
            $base_sku = $row['sku'];

            $stock_code_row = array(
                'StockCode' => $base_sku,
            );
            $barcode_row = array(
                'Barkod' => $base_sku,
            );
            $generic_row = array(
                'sku' => $base_sku,
            );

            if (array_key_exists('quantity', $row)) {
                $stock_code_row['StockCount'] = (int) $row['quantity'];
                $barcode_row['StokAdedi'] = (int) $row['quantity'];
                $generic_row['quantity'] = (int) $row['quantity'];
            }

            if (array_key_exists('price', $row)) {
                $stock_code_row['SalePrice'] = (float) $row['price'];
                $barcode_row['Fiyat'] = (float) $row['price'];
                $generic_row['price'] = (float) $row['price'];
            }

            if (array_key_exists('list_price', $row)) {
                $stock_code_row['ListPrice'] = (float) $row['list_price'];
                $barcode_row['ListeFiyati'] = (float) $row['list_price'];
                $generic_row['listPrice'] = (float) $row['list_price'];
            }

            $rows_stock_code[] = $stock_code_row;
            $rows_barcode[] = $barcode_row;
            $rows_generic[] = $generic_row;
        }

        return array(
            array(
                'products' => array(
                    'Product' => $rows_stock_code,
                ),
            ),
            array(
                'products' => array(
                    'Product' => $rows_barcode,
                ),
            ),
            array(
                'products' => $rows_stock_code,
            ),
            array(
                'products' => $rows_barcode,
            ),
            array(
                'productList' => array(
                    'Product' => $rows_generic,
                ),
            ),
            array(
                'request' => array(
                    'products' => array(
                        'Product' => $rows_stock_code,
                    ),
                ),
            ),
        );
    }

    private function normalize_update_item($item)
    {
        $item = is_array($item) ? $item : (array) $item;

        $sku = trim((string) $this->first_not_empty($item, array(
            'sku',
            'stockCode',
            'StockCode',
            'barcode',
            'Barkod',
        ), ''));

        if ($sku === '') {
            return new \WP_Error(
                'multi_sync_pttavm_update_missing_sku',
                'SKU bos oldugu icin guncelleme satiri atlandi.'
            );
        }

        $row = array(
            'sku' => $sku,
        );

        $has_change = false;

        if (array_key_exists('quantity', $item)) {
            $quantity = $this->to_int($item['quantity'], 0);
            if ($quantity === null) {
                $quantity = 0;
            }
            $row['quantity'] = max(0, (int) $quantity);
            $has_change = true;
        }

        if (array_key_exists('price', $item) || array_key_exists('salePrice', $item) || array_key_exists('listPrice', $item)) {
            $raw_price = $this->first_not_empty($item, array('price', 'salePrice', 'listPrice'), null);
            $price = $this->normalize_money($raw_price, null);
            if ($price !== null) {
                $row['price'] = round((float) $price, 2);
                $has_change = true;
            }
        }

        if (array_key_exists('list_price', $item) || array_key_exists('listPrice', $item) || array_key_exists('regular_price', $item)) {
            $raw_list_price = $this->first_not_empty($item, array('list_price', 'listPrice', 'regular_price'), null);
            $list_price = $this->normalize_money($raw_list_price, null);
            if ($list_price !== null) {
                $row['list_price'] = round((float) $list_price, 2);
                $has_change = true;
            }
        }

        if (!isset($row['price'])) {
            if (isset($row['list_price'])) {
                $row['price'] = $row['list_price'];
            }
        }

        if (!$has_change) {
            return new \WP_Error(
                'multi_sync_pttavm_update_no_change',
                sprintf('SKU %s icin gonderilecek stok/fiyat verisi bulunamadi.', $sku)
            );
        }

        return $row;
    }

    private function build_stock_control_payload($params)
    {
        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        $size = isset($params['size']) ? max(1, min(300, (int) $params['size'])) : 100;
        $search_page = isset($params['SearchPage'])
            ? max(0, (int) $params['SearchPage'])
            : $page;
        $search_page_size = isset($params['SearchPageSize'])
            ? max(1, min(300, (int) $params['SearchPageSize']))
            : $size;

        $payload = array(
            'SearchAktifPasif' => isset($params['SearchAktifPasif']) ? (int) $params['SearchAktifPasif'] : 0,
            'SearchPage' => $search_page,
            'SearchPageSize' => $search_page_size,
        );

        $optional_pairs = array(
            'SearchUrunAdi' => array('SearchUrunAdi', 'searchUrunAdi', 'name'),
            'SearchBarkod' => array('SearchBarkod', 'searchBarkod', 'barcode', 'sku'),
            'SearchYeniKategoriId' => array('SearchYeniKategoriId', 'categoryId', 'category'),
        );

        foreach ($optional_pairs as $target_key => $source_keys) {
            $value = $this->first_not_empty($params, $source_keys, '');
            if ($value !== '') {
                $payload[$target_key] = $value;
            }
        }

        return $payload;
    }

    private function build_stock_control_fallback_payloads($params, $initial_payload = array())
    {
        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        $size = isset($params['size']) ? max(1, min(300, (int) $params['size'])) : 100;

        $variants = array(
            array('SearchAktifPasif' => 1, 'SearchPage' => $page, 'SearchPageSize' => $size),
            array('SearchAktifPasif' => 1, 'SearchPage' => $page + 1, 'SearchPageSize' => $size),
            array('SearchAktifPasif' => 0, 'SearchPage' => $page, 'SearchPageSize' => $size),
            array('SearchAktifPasif' => 0, 'SearchPage' => $page + 1, 'SearchPageSize' => $size),
            array('SearchAktifPasif' => 1, 'SearchPage' => $page, 'SearchPageSize' => 100),
            array('SearchAktifPasif' => 1, 'SearchPage' => $page + 1, 'SearchPageSize' => 100),
            array('SearchAktifPasif' => 0, 'SearchPage' => $page, 'SearchPageSize' => 100),
            array('SearchAktifPasif' => 0, 'SearchPage' => $page + 1, 'SearchPageSize' => 100),
            array('SearchAktifPasif' => 1, 'SearchPage' => $page, 'SearchPageSize' => 50),
            array('SearchAktifPasif' => 1, 'SearchPage' => $page + 1, 'SearchPageSize' => 50),
            array('SearchAktifPasif' => 0, 'SearchPage' => $page, 'SearchPageSize' => 50),
            array('SearchAktifPasif' => 0, 'SearchPage' => $page + 1, 'SearchPageSize' => 50),
            array('SearchAktifPasif' => 2, 'SearchPage' => $page, 'SearchPageSize' => $size),
            array('SearchAktifPasif' => 2, 'SearchPage' => $page + 1, 'SearchPageSize' => $size),
        );

        $payloads = array();
        $seen = array();
        if (!empty($initial_payload)) {
            $seen[wp_json_encode($initial_payload)] = true;
        }

        foreach ($variants as $variant) {
            $candidate_params = array_merge((array) $params, $variant);
            $candidate_payload = $this->build_stock_control_payload($candidate_params);
            $signature = wp_json_encode($candidate_payload);

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $payloads[] = $candidate_payload;
        }

        return $payloads;
    }

    private function hydrate_products_with_barcode_details($supplier, $items)
    {
        if (!is_array($items) || empty($items)) {
            return $items;
        }

        $barcodes = array();
        foreach ($items as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $barcode = trim((string) $this->find_first_key_value($row, array(
                'Barkod',
                'Barcode',
                'StockCode',
                'stockCode',
                'SKU',
                'Sku',
            ), ''));

            if ($barcode === '') {
                continue;
            }

            $barcodes[$this->normalize_identifier_key($barcode)] = $barcode;
        }

        if (empty($barcodes)) {
            return $items;
        }

        $details = $this->fetch_barcode_detail_map($supplier, array_values($barcodes));
        if (empty($details)) {
            return $items;
        }

        $hydrated = array();
        $matched = 0;

        foreach ($items as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $barcode = trim((string) $this->find_first_key_value($row, array(
                'Barkod',
                'Barcode',
                'StockCode',
                'stockCode',
                'SKU',
                'Sku',
            ), ''));

            $key = $this->normalize_identifier_key($barcode);
            if ($key !== '' && isset($details[$key]) && is_array($details[$key])) {
                $row = $this->merge_missing_fields($row, $details[$key]);
                $matched++;
            }

            $hydrated[] = $row;
        }

        if (function_exists('multi_sync_debug_log')) {
            multi_sync_debug_log(sprintf(
                'PTTAVM BarkodKontrolBulk hydrate eslesme: %d/%d',
                $matched,
                count($items)
            ));
        }

        return $hydrated;
    }

    private function fetch_barcode_detail_map($supplier, $barcodes)
    {
        if (!is_array($barcodes) || empty($barcodes)) {
            return array();
        }

        $detail_map = array();
        $chunks = array_chunk($barcodes, 100);

        foreach ($chunks as $chunk) {
            $rows = $this->fetch_barcode_detail_chunk($supplier, $chunk);
            if (empty($rows) || !is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $row = is_array($row) ? $row : (array) $row;
                $barcode = trim((string) $this->find_first_key_value($row, array(
                    'Barkod',
                    'Barcode',
                    'StockCode',
                    'stockCode',
                    'SKU',
                    'Sku',
                ), ''));

                if ($barcode === '') {
                    continue;
                }

                $key = $this->normalize_identifier_key($barcode);
                if ($key === '') {
                    continue;
                }

                if (!isset($detail_map[$key])) {
                    $detail_map[$key] = $row;
                    continue;
                }

                $detail_map[$key] = $this->merge_missing_fields($detail_map[$key], $row);
            }
        }

        return $detail_map;
    }

    private function fetch_barcode_detail_chunk($supplier, $barcodes)
    {
        if (!is_array($barcodes) || empty($barcodes)) {
            return array();
        }

        $payload_candidates = $this->build_barcode_bulk_payload_candidates($barcodes);

        foreach ($payload_candidates as $payload) {
            $response = $this->request_soap($supplier, 'BarkodKontrolBulk', $payload);
            if (is_wp_error($response)) {
                if ($this->is_parameter_mismatch_fault($response)) {
                    continue;
                }
                return array();
            }

            $result = $this->extract_method_payload($response['data'], 'BarkodKontrolBulk');
            $items = $this->extract_list_flexible($result, array(
                'ProductList',
                'Products',
                'Urunler',
                'BarkodListesi',
                'items',
                'data',
                'result',
            ));

            if (empty($items) && is_array($result) && $this->looks_like_product($result)) {
                return array($result);
            }

            if (!empty($items)) {
                return $items;
            }
        }

        return array();
    }

    private function build_barcode_bulk_payload_candidates($barcodes)
    {
        $barcodes = array_values(array_filter(array_map('strval', (array) $barcodes), function ($value) {
            return trim($value) !== '';
        }));

        if (empty($barcodes)) {
            return array();
        }

        return array(
            array(
                'Barkodlar' => array(
                    'string' => $barcodes,
                ),
            ),
            array(
                'BarkodListesi' => array(
                    'string' => $barcodes,
                ),
            ),
            array(
                'barcodes' => array(
                    'string' => $barcodes,
                ),
            ),
            array(
                'Barkodlar' => $barcodes,
            ),
            array(
                'BarkodListesi' => $barcodes,
            ),
            array(
                'barcodes' => $barcodes,
            ),
        );
    }

    private function merge_missing_fields($base, $extra)
    {
        $base = is_array($base) ? $base : (array) $base;
        $extra = is_array($extra) ? $extra : (array) $extra;

        foreach ($extra as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $base[$key] = $value;
                continue;
            }

            $current = $base[$key];
            $current_empty = $current === null || $current === '';
            if ($current_empty && $value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function normalize_identifier_key($value)
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($normalized, 'UTF-8');
        }

        return strtolower($normalized);
    }

    private function build_order_control_payload($params)
    {
        $fallback = time() - (self::ORDER_LOOKBACK_DAYS * DAY_IN_SECONDS);
        $raw_date = $this->first_not_empty($params, array('pDate', 'startDate', 'StartDate', 'lastUpdatedAfter'), '');
        $timestamp = $this->parse_date_input($raw_date, $fallback);

        $payload = array(
            'pDate' => date('Y-m-d H:i:s', $timestamp),
        );

        $optional_pairs = array(
            'pStatu' => array('pStatu', 'status', 'Status'),
            'faturaNo' => array('faturaNo', 'invoiceNo', 'InvoiceNo'),
            'page' => array('page', 'Page'),
        );

        foreach ($optional_pairs as $target_key => $source_keys) {
            $value = $this->first_not_empty($params, $source_keys, '');
            if ($value !== '') {
                $payload[$target_key] = $value;
            }
        }

        return $payload;
    }

    private function parse_date_input($value, $fallback_timestamp)
    {
        if ($value === null || $value === '') {
            return (int) $fallback_timestamp;
        }

        if (is_numeric($value)) {
            $value = (int) $value;
            if ($value > 9999999999) {
                $value = (int) ($value / 1000);
            }
            return $value > 0 ? $value : (int) $fallback_timestamp;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return (int) $fallback_timestamp;
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return (int) $timestamp;
        }

        $formats = array(
            'Y-m-d H:i:s',
            'Y-m-d',
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
        );

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date instanceof \DateTime) {
                return $date->getTimestamp();
            }
        }

        return (int) $fallback_timestamp;
    }

    private function parse_line_items_from_order($item)
    {
        if (!is_array($item)) {
            return array();
        }

        $line_candidates = $this->extract_list_flexible($item, array(
            'OrderItems',
            'SiparisUrunleri',
            'SiparisUrunListesi',
            'Products',
            'items',
            'lineItems',
            'lines',
        ));

        $lines = array();
        foreach ($line_candidates as $line_item) {
            $line_item = is_array($line_item) ? $line_item : (array) $line_item;
            $product = $this->find_first_array_by_keys($line_item, array('Product', 'Urun', 'product'));
            if (!is_array($product)) {
                $product = array();
            }

            $sku = trim((string) $this->find_first_key_value($line_item, array(
                'StockCode',
                'stockCode',
                'Barkod',
                'Barcode',
                'SKU',
                'Sku',
            ), $this->find_first_key_value($product, array('StockCode', 'Barkod', 'SKU', 'Sku'), '')));

            $name = (string) $this->find_first_key_value($line_item, array(
                'ProductName',
                'UrunAdi',
                'name',
                'Name',
                'title',
            ), $this->find_first_key_value($product, array('ProductName', 'UrunAdi', 'name', 'Name'), ''));

            $quantity = $this->to_int($this->find_first_key_value($line_item, array(
                'Quantity',
                'quantity',
                'Adet',
                'Miktar',
            ), 1), 1);
            if ($quantity === null || $quantity <= 0) {
                $quantity = 1;
            }

            $price = $this->normalize_money($this->find_first_key_value($line_item, array(
                'Price',
                'price',
                'BirimFiyat',
                'SalePrice',
                'SatisFiyati',
            ), null), null);

            if ($price === null) {
                $line_total = $this->normalize_money($this->find_first_key_value($line_item, array(
                    'LineTotal',
                    'ToplamTutar',
                    'TotalPrice',
                ), null), null);
                if ($line_total !== null && $quantity > 0) {
                    $price = (float) ($line_total / $quantity);
                } else {
                    $price = 0.0;
                }
            }

            if ($sku === '' && $name === '') {
                continue;
            }

            $lines[] = array(
                'sku' => $sku,
                'name' => $name,
                'quantity' => (int) $quantity,
                'price' => (float) $price,
            );
        }

        return $lines;
    }

    private function build_single_line_from_flat_row($item)
    {
        if (!is_array($item)) {
            return array();
        }

        $sku = trim((string) $this->find_first_key_value($item, array(
            'StockCode',
            'stockCode',
            'Barkod',
            'Barcode',
            'SKU',
            'Sku',
        ), ''));

        $name = (string) $this->find_first_key_value($item, array(
            'ProductName',
            'UrunAdi',
            'name',
            'Name',
            'title',
        ), '');

        if ($sku === '' && $name === '') {
            return array();
        }

        $quantity = $this->to_int($this->find_first_key_value($item, array('Quantity', 'quantity', 'Adet', 'Miktar'), 1), 1);
        if ($quantity === null || $quantity <= 0) {
            $quantity = 1;
        }

        $price = $this->normalize_money($this->find_first_key_value($item, array(
            'Price',
            'price',
            'BirimFiyat',
            'SalePrice',
            'SatisFiyati',
        ), 0), 0);

        return array(
            'sku' => $sku,
            'name' => $name,
            'quantity' => (int) $quantity,
            'price' => (float) $price,
        );
    }

    private function merge_v1_order_rows($rows)
    {
        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $merged = array();
        foreach ($rows as $index => $raw_row) {
            $row = is_array($raw_row) ? $raw_row : (array) $raw_row;

            $order_id = trim((string) $this->find_first_key_value($row, array(
                'SiparisId',
                'OrderId',
                'orderId',
                'SiparisNo',
                'OrderNo',
                'orderNo',
                'id',
            ), ''));

            if ($order_id === '') {
                $order_id = 'row_' . $index;
            }

            $line_items = $this->parse_line_items_from_order($row);
            if (empty($line_items)) {
                $single_line = $this->build_single_line_from_flat_row($row);
                if (!empty($single_line)) {
                    $line_items[] = $single_line;
                }
            }

            if (!isset($merged[$order_id])) {
                $row['OrderItems'] = $line_items;
                $merged[$order_id] = $row;
                continue;
            }

            $existing = $merged[$order_id];
            if (!isset($existing['OrderItems']) || !is_array($existing['OrderItems'])) {
                $existing['OrderItems'] = array();
            }

            $existing['OrderItems'] = array_merge($existing['OrderItems'], $line_items);

            $existing_total = $this->normalize_money($this->find_first_key_value($existing, array('ToplamTutar', 'TotalPrice'), 0), 0);
            $row_total = $this->normalize_money($this->find_first_key_value($row, array('ToplamTutar', 'TotalPrice'), 0), 0);
            if ((float) $existing_total <= 0 && (float) $row_total > 0) {
                $existing['ToplamTutar'] = $row_total;
            }

            $merged[$order_id] = $existing;
        }

        return array_values($merged);
    }

    private function extract_address_from_order($item, $type)
    {
        $candidate_keys = $type === 'shipping'
            ? array(
                'TeslimatAdresi',
                'ShippingAddress',
                'KargoAdresi',
                'deliveryAddress',
                'shippingAddress',
            )
            : array(
                'FaturaAdresi',
                'BillingAddress',
                'invoiceAddress',
                'billingAddress',
            );

        $found = $this->find_first_array_by_keys($item, $candidate_keys);
        if (is_array($found) && !empty($found)) {
            return $found;
        }

        $fallback = array(
            'name' => (string) $this->find_first_key_value($item, array(
                $type === 'shipping' ? 'AliciAdi' : 'FaturaAdi',
                $type === 'shipping' ? 'RecipientName' : 'BillingName',
                'CustomerName',
                'AdSoyad',
            ), ''),
            'phone' => (string) $this->find_first_key_value($item, array(
                $type === 'shipping' ? 'AliciTelefon' : 'FaturaTelefon',
                $type === 'shipping' ? 'RecipientPhone' : 'BillingPhone',
                'Phone',
                'phone',
            ), ''),
            'address' => (string) $this->find_first_key_value($item, array(
                $type === 'shipping' ? 'AliciAdres' : 'FaturaAdres',
                $type === 'shipping' ? 'RecipientAddress' : 'BillingAddressText',
                'Address',
                'address',
            ), ''),
            'city' => (string) $this->find_first_key_value($item, array(
                $type === 'shipping' ? 'AliciIl' : 'FaturaIl',
                'City',
                'city',
            ), ''),
            'postalCode' => (string) $this->find_first_key_value($item, array(
                $type === 'shipping' ? 'AliciPostaKodu' : 'FaturaPostaKodu',
                'PostCode',
                'postalCode',
                'zipCode',
            ), ''),
            'country' => 'TR',
        );

        return $fallback;
    }

    private function normalize_order_status($status)
    {
        $status = strtolower(trim((string) $status));
        $map = array(
            'pending' => 'pending',
            'new' => 'pending',
            'waiting' => 'pending',
            'created' => 'pending',
            'approved' => 'processing',
            'processing' => 'processing',
            'preparing' => 'processing',
            'inprogress' => 'processing',
            'shipped' => 'completed',
            'delivered' => 'completed',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'cancel' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
        );

        return isset($map[$status]) ? $map[$status] : 'pending';
    }

    private function normalize_tracking_status($status)
    {
        $normalized = strtolower(trim((string) $status));
        if ($normalized === '') {
            return 'unknown';
        }

        $map = array(
            'waiting' => 'waiting',
            'inprogress' => 'in_progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'failed' => 'cancelled',
        );

        return isset($map[$normalized]) ? $map[$normalized] : $normalized;
    }

    private function extract_product_pricing_with_vat($item)
    {
        $item = is_array($item) ? $item : (array) $item;
        $vat_rate = $this->parse_product_vat_rate($item);

        $raw_regular_included = $this->find_first_key_value($item, array(
            'KDVli',
            'Kdvli',
            'SatisFiyatiKDVli',
            'PriceVatIncluded',
            'PriceWithVat',
            'FinalPrice',
            'VergiliFiyat',
            'KdvDahilFiyat',
        ), null);
        $raw_regular_excluded = $this->find_first_key_value($item, array(
            'KDVsiz',
            'Kdvsiz',
            'VergisizFiyat',
            'SatisFiyatiKDVsiz',
            'SatisFiyatiKdvHaric',
            'PriceVatExcluded',
            'PriceWithoutVat',
            'NetPrice',
            'PttAVMFiyat',
            'PttAvmFiyat',
            'PttFiyat',
            'SalePrice',
            'SatisFiyat',
            'SatisFiyati',
            'ListPrice',
            'ListeFiyat',
            'ListeFiyati',
            'Fiyat',
            'Price',
            'price',
        ), null);

        $raw_discount_included = $this->find_first_key_value($item, array(
            'IndirimliFiyatKDVli',
            'KampanyaliFiyatKDVli',
            'DiscountedPriceVatIncluded',
            'DiscountPriceVatIncluded',
            'DiscountedPriceWithVat',
        ), null);
        $raw_discount_excluded = $this->find_first_key_value($item, array(
            'IndirimliFiyat',
            'IndirimliFiyatKDVsiz',
            'KampanyaliFiyat',
            'DiscountedPrice',
            'DiscountPrice',
            'DiscountedPriceWithoutVat',
            'CampaignPrice',
        ), null);

        $regular_included = $this->normalize_money($raw_regular_included, null);
        $regular_excluded = $this->normalize_money($raw_regular_excluded, null);
        $discount_included = $this->normalize_money($raw_discount_included, null);
        $discount_excluded = $this->normalize_money($raw_discount_excluded, null);

        if (($regular_included === null || (float) $regular_included <= 0.0) && $regular_excluded !== null) {
            $regular_included = $this->apply_vat_to_price($regular_excluded, $vat_rate);
        }

        if (($discount_included === null || (float) $discount_included <= 0.0) && $discount_excluded !== null) {
            $discount_included = $this->apply_vat_to_price($discount_excluded, $vat_rate);
        }

        if ($discount_included !== null && $regular_included !== null && $discount_included > $regular_included) {
            $discount_included = $regular_included;
        }

        return array(
            'regular_price' => $regular_included !== null ? round((float) $regular_included, 2) : null,
            'discounted_price' => $discount_included !== null ? round((float) $discount_included, 2) : null,
            'vat_rate' => $vat_rate,
            'raw_regular' => $raw_regular_included !== null ? $raw_regular_included : $raw_regular_excluded,
            'raw_discount' => $raw_discount_included !== null ? $raw_discount_included : $raw_discount_excluded,
        );
    }

    private function parse_product_vat_rate($item)
    {
        $item = is_array($item) ? $item : (array) $item;

        $raw_vat = $this->find_first_key_value($item, array(
            'KDVOrani',
            'KdvOrani',
            'KDV',
            'kdv',
            'VatRate',
            'vatRate',
            'TaxRate',
            'taxRate',
        ), null);

        $vat_rate = $this->normalize_scalar_number($raw_vat, self::DEFAULT_VAT_RATE_PERCENT, 2);
        if ($vat_rate === null) {
            return self::DEFAULT_VAT_RATE_PERCENT;
        }

        $vat_rate = (float) $vat_rate;
        if ($vat_rate > 0 && $vat_rate <= 1) {
            $vat_rate *= 100;
        }

        if ($vat_rate < 0) {
            $vat_rate = 0;
        }

        if ($vat_rate > 100) {
            $vat_rate = self::DEFAULT_VAT_RATE_PERCENT;
        }

        return round($vat_rate, 2);
    }

    private function apply_vat_to_price($price_without_vat, $vat_rate_percent)
    {
        if ($price_without_vat === null || !is_numeric($price_without_vat)) {
            return null;
        }

        $base = (float) $price_without_vat;
        $rate = is_numeric($vat_rate_percent) ? (float) $vat_rate_percent : self::DEFAULT_VAT_RATE_PERCENT;
        if ($rate < 0) {
            $rate = 0;
        }

        return round($base * (1 + ($rate / 100)), 2);
    }

    private function normalize_money($value, $default = null)
    {
        if (is_array($value)) {
            $value = $this->find_first_key_value($value, array('amount', 'value', '_'), null);
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return $default;
        }

        $has_dot = strpos($normalized, '.') !== false;
        $has_comma = strpos($normalized, ',') !== false;
        if ($has_dot && $has_comma) {
            $normalized = str_replace(',', '', $normalized);
        } elseif ($has_comma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (is_numeric($normalized)) {
            return (float) $normalized;
        }

        return $this->normalize_scalar_number($normalized, $default, 2);
    }

    private function normalize_quantity($value, $default = null)
    {
        $number = $this->normalize_scalar_number($value, $default, 0);
        if ($number === null) {
            return $default;
        }

        return (int) max(0, round((float) $number));
    }

    private function normalize_scalar_number($value, $default = null, $precision = 2)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) || is_float($value) || is_numeric($value)) {
            $number = (float) $value;
            return $precision === 0 ? round($number) : round($number, (int) $precision);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return $default;
        }

        // Keep only number-ish chars and normalize locale separators.
        $normalized = preg_replace('/[^0-9,\.\-]/', '', $raw);
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return $default;
        }

        $has_dot = strpos($normalized, '.') !== false;
        $has_comma = strpos($normalized, ',') !== false;

        if ($has_dot && $has_comma) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($has_comma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized)) {
            return $default;
        }

        $number = (float) $normalized;
        return $precision === 0 ? round($number) : round($number, (int) $precision);
    }

    private function split_full_name($full_name)
    {
        $name = trim((string) $full_name);
        if ($name === '') {
            return array('', '');
        }

        $parts = preg_split('/\s+/', $name);
        if (count($parts) <= 1) {
            return array($name, '');
        }

        $first_name = array_shift($parts);
        return array($first_name, implode(' ', $parts));
    }

    private function request_soap($supplier, $method, $params = array())
    {
        $method = trim((string) $method);
        if ($method === '') {
            return new \WP_Error(
                'multi_sync_pttavm_empty_method',
                'SOAP metodu bos olamaz.'
            );
        }

        $envelope = $this->build_soap_envelope($supplier, $method, is_array($params) ? $params : array());
        $headers = array(
            'Content-Type' => 'text/xml; charset=UTF-8',
            'SOAPAction' => '"' . self::SOAP_ACTION_PREFIX . $method . '"',
            'Accept' => 'text/xml',
        );

        for ($attempt = 1; $attempt <= self::MAX_REQUEST_RETRIES; $attempt++) {
            $attempt_started_at = microtime(true);
            $debug_entry = array(
                'timestamp' => current_time('mysql'),
                'supplier_id' => $this->get_supplier_row_id($supplier),
                'marketplace_key' => $this->get_key(),
                'operation' => $method,
                'attempt' => $attempt,
                'request' => array(
                    'method' => 'POST',
                    'url' => self::SERVICE_URL,
                    'wsdl' => self::WSDL_URL,
                    'headers' => $this->sanitize_headers_for_debug($headers),
                    'body' => $this->truncate_debug_body($this->mask_xml_for_debug($envelope)),
                ),
                'response' => array(),
            );

            $response = wp_remote_post(self::SERVICE_URL, array(
                'timeout' => 45,
                'redirection' => 5,
                'headers' => $headers,
                'body' => $envelope,
            ));

            if (is_wp_error($response)) {
                $debug_entry['response'] = array(
                    'error' => $response->get_error_message(),
                    'duration_ms' => (int) round((microtime(true) - $attempt_started_at) * 1000),
                );
                $this->store_http_debug($supplier, $debug_entry);

                if ($attempt < self::MAX_REQUEST_RETRIES) {
                    sleep($attempt);
                    continue;
                }

                return new \WP_Error(
                    'multi_sync_pttavm_http_request_failed',
                    'PTTAVM SOAP istegi basarisiz: ' . $response->get_error_message()
                );
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);

            $debug_entry['response'] = array(
                'status_code' => $status_code,
                'body' => $this->truncate_debug_body($this->mask_xml_for_debug($raw_body)),
                'duration_ms' => (int) round((microtime(true) - $attempt_started_at) * 1000),
                'body_length' => is_string($raw_body) ? strlen($raw_body) : 0,
            );
            $this->store_http_debug($supplier, $debug_entry);

            if (in_array($status_code, array(429, 500, 502, 503, 504), true) && $attempt < self::MAX_REQUEST_RETRIES) {
                sleep($attempt);
                continue;
            }

            if ($status_code >= 400) {
                return new \WP_Error(
                    'multi_sync_pttavm_http_error',
                    sprintf('PTTAVM SOAP istegi basarisiz (%d).', $status_code),
                    array(
                        'code' => $status_code,
                        'body' => $raw_body,
                        'method' => $method,
                    )
                );
            }

            $parsed = $this->parse_xml_to_array($raw_body);
            if (is_wp_error($parsed)) {
                return $parsed;
            }

            $fault = $this->extract_soap_fault($parsed);
            if (is_array($fault)) {
                $fault_message = isset($fault['message']) ? (string) $fault['message'] : 'PTTAVM SOAP Fault';
                $fault_code = isset($fault['code']) ? (string) $fault['code'] : '';

                return new \WP_Error(
                    'multi_sync_pttavm_soap_fault',
                    $fault_message,
                    array(
                        'fault_code' => $fault_code,
                        'fault' => $fault,
                        'method' => $method,
                        'body' => $raw_body,
                        'method_not_supported' => $this->fault_looks_like_method_not_supported($fault_message, $fault_code, $raw_body),
                    )
                );
            }

            return array(
                'status_code' => $status_code,
                'body' => $raw_body,
                'data' => $parsed,
            );
        }

        return new \WP_Error(
            'multi_sync_pttavm_request_retries_exhausted',
            'PTTAVM SOAP istegi tekrar denemelerden sonra da basarisiz.'
        );
    }

    private function build_soap_envelope($supplier, $method, $params)
    {
        $username = $this->escape_xml_text($this->get_api_key($supplier));
        $password = $this->escape_xml_text($this->get_api_secret($supplier));
        $params_for_body = is_array($params) ? $params : array();

        $body_xml = $this->build_xml_for_value($params_for_body, 'tem', 'item');

        return
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/" ' .
            'xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">' .
                '<s:Header>' .
                    '<o:Security s:mustUnderstand="1">' .
                        '<o:UsernameToken>' .
                            '<o:Username>' . $username . '</o:Username>' .
                            '<o:Password>' . $password . '</o:Password>' .
                        '</o:UsernameToken>' .
                    '</o:Security>' .
                '</s:Header>' .
                '<s:Body>' .
                    '<tem:' . $this->sanitize_xml_tag($method, 'Method') . '>' .
                        $body_xml .
                    '</tem:' . $this->sanitize_xml_tag($method, 'Method') . '>' .
                '</s:Body>' .
            '</s:Envelope>';
    }

    private function build_xml_for_value($value, $namespace_prefix, $item_tag)
    {
        if (is_array($value)) {
            if ($this->is_assoc($value)) {
                $xml = '';
                foreach ($value as $key => $child_value) {
                    $tag = $this->sanitize_xml_tag($key, $item_tag);

                    if (is_array($child_value) && !$this->is_assoc($child_value)) {
                        foreach ($child_value as $child_row) {
                            $xml .= '<' . $namespace_prefix . ':' . $tag . '>';
                            $xml .= $this->build_xml_for_value($child_row, $namespace_prefix, 'item');
                            $xml .= '</' . $namespace_prefix . ':' . $tag . '>';
                        }
                        continue;
                    }

                    $xml .= '<' . $namespace_prefix . ':' . $tag . '>';
                    $xml .= $this->build_xml_for_value($child_value, $namespace_prefix, 'item');
                    $xml .= '</' . $namespace_prefix . ':' . $tag . '>';
                }
                return $xml;
            }

            $xml = '';
            foreach ($value as $child_value) {
                $tag = $this->sanitize_xml_tag($item_tag, 'item');
                $xml .= '<' . $namespace_prefix . ':' . $tag . '>';
                $xml .= $this->build_xml_for_value($child_value, $namespace_prefix, 'item');
                $xml .= '</' . $namespace_prefix . ':' . $tag . '>';
            }
            return $xml;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return $this->escape_xml_text((string) $value);
    }

    private function parse_xml_to_array($xml_string)
    {
        if (!is_string($xml_string) || trim($xml_string) === '') {
            return new \WP_Error(
                'multi_sync_pttavm_empty_xml_response',
                'PTTAVM SOAP yaniti bos.'
            );
        }

        if (!function_exists('simplexml_load_string')) {
            return new \WP_Error(
                'multi_sync_pttavm_simplexml_missing',
                'SimpleXML uzantisi bulunamadi.'
            );
        }

        $previous_errors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);

            $message = 'PTTAVM SOAP yaniti XML olarak cozumlenemedi.';
            if (!empty($errors) && isset($errors[0]->message)) {
                $message .= ' ' . trim((string) $errors[0]->message);
            }

            return new \WP_Error(
                'multi_sync_pttavm_xml_parse_error',
                $message
            );
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);

        $json = wp_json_encode($xml);
        $data = json_decode($json, true);
        if (is_array($data) && !empty($data)) {
            return $data;
        }

        $dom_data = $this->parse_xml_with_dom_fallback($xml_string);
        if (is_array($dom_data) && !empty($dom_data)) {
            return $dom_data;
        }

        return new \WP_Error(
            'multi_sync_pttavm_xml_to_array_error',
            'PTTAVM XML verisi diziye donusturulemedi.'
        );
    }

    private function parse_xml_with_dom_fallback($xml_string)
    {
        if (!class_exists('\DOMDocument')) {
            return array();
        }

        $dom = new \DOMDocument();
        $previous_errors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml_string);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);

        if (!$loaded || !$dom->documentElement) {
            return array();
        }

        $root = $dom->documentElement;
        $root_name = $root->localName ? $root->localName : $root->nodeName;

        return array(
            $root_name => $this->dom_node_to_array($root),
        );
    }

    private function dom_node_to_array($node)
    {
        if (!$node || !$node->hasChildNodes()) {
            return '';
        }

        $result = array();
        $text_buffer = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text_buffer .= $child->nodeValue;
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $name = $child->localName ? $child->localName : $child->nodeName;
            $value = $this->dom_node_to_array($child);

            if (array_key_exists($name, $result)) {
                if (!is_array($result[$name]) || !isset($result[$name][0])) {
                    $result[$name] = array($result[$name]);
                }
                $result[$name][] = $value;
            } else {
                $result[$name] = $value;
            }
        }

        $trimmed_text = trim($text_buffer);
        if ($trimmed_text !== '' && empty($result)) {
            return $trimmed_text;
        }

        if ($trimmed_text !== '') {
            $result['_text'] = $trimmed_text;
        }

        return $result;
    }

    private function extract_soap_fault($response_data)
    {
        $nodes = $this->collect_nodes($response_data, 120);
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach ($node as $key => $value) {
                if ($this->normalize_key($key) !== 'fault') {
                    continue;
                }

                $fault = is_array($value) ? $value : array('message' => (string) $value);
                $code = (string) $this->find_first_key_value($fault, array(
                    'faultcode',
                    'Code',
                    'Value',
                ), '');
                $message = (string) $this->find_first_key_value($fault, array(
                    'faultstring',
                    'Reason',
                    'Text',
                    'message',
                ), '');

                if ($message === '') {
                    $message = 'PTTAVM SOAP Fault alindi.';
                }

                return array(
                    'code' => $code,
                    'message' => $message,
                    'detail' => $fault,
                );
            }
        }

        return null;
    }

    private function extract_method_payload($response_data, $method)
    {
        if (!is_array($response_data)) {
            return array();
        }

        $method_key = strtolower(trim((string) $method));
        $method_response_key = $method_key . 'response';
        $method_result_key = $method_key . 'result';

        $nodes = $this->collect_nodes($response_data, 140);
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach ($node as $key => $value) {
                $normalized = $this->normalize_key($key);
                if ($normalized === $method_result_key) {
                    return is_array($value) ? $value : array('value' => $value);
                }

                if ($normalized === $method_response_key) {
                    if (is_array($value)) {
                        $inner = $this->find_first_key_value($value, array(
                            $method . 'Result',
                            'return',
                            'result',
                            'data',
                        ), null);
                        if ($inner !== null && $inner !== '') {
                            return is_array($inner) ? $inner : array('value' => $inner);
                        }
                        return $value;
                    }

                    return array('value' => $value);
                }
            }
        }

        return $response_data;
    }

    private function extract_list_flexible($source, $preferred_keys = array())
    {
        if (!is_array($source)) {
            return array();
        }

        if (isset($source[0])) {
            return array_values($source);
        }

        $preferred_lookup = array();
        foreach ($preferred_keys as $key) {
            $preferred_lookup[] = $this->normalize_key($key);
        }

        $nodes = $this->collect_nodes($source, 160);
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach ($node as $key => $value) {
                $normalized_key = $this->normalize_key($key);
                if (!in_array($normalized_key, $preferred_lookup, true)) {
                    continue;
                }

                $normalized_list = $this->normalize_array_list($value);
                if (!empty($normalized_list)) {
                    return $normalized_list;
                }
            }
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach ($node as $value) {
                if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                    return array_values($value);
                }
            }
        }

        if ($this->looks_like_order($source) || $this->looks_like_product($source) || $this->looks_like_line_item($source)) {
            return array($source);
        }

        return array();
    }

    private function normalize_array_list($value)
    {
        if (!is_array($value)) {
            return array();
        }

        if (isset($value[0])) {
            return array_values($value);
        }

        if ($this->looks_like_order($value) || $this->looks_like_product($value) || $this->looks_like_line_item($value)) {
            return array($value);
        }

        foreach ($value as $child) {
            if (is_array($child) && isset($child[0])) {
                return array_values($child);
            }
        }

        return array();
    }

    private function collect_nodes($root, $limit = 120)
    {
        $nodes = array();
        if (!is_array($root)) {
            return $nodes;
        }

        $queue = array($root);
        while (!empty($queue) && count($nodes) < $limit) {
            $node = array_shift($queue);
            if (!is_array($node)) {
                continue;
            }

            $nodes[] = $node;
            foreach ($node as $child) {
                if (is_array($child)) {
                    $queue[] = $child;
                }
            }
        }

        return $nodes;
    }

    private function find_first_key_value($source, $candidate_keys, $default = null)
    {
        if (!is_array($source) || empty($candidate_keys)) {
            return $default;
        }

        $lookup = array();
        foreach ($candidate_keys as $key) {
            $lookup[] = $this->normalize_key($key);
        }

        $nodes = $this->collect_nodes($source, 200);
        foreach ($nodes as $node) {
            foreach ($node as $key => $value) {
                if (!in_array($this->normalize_key($key), $lookup, true)) {
                    continue;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                return $value;
            }
        }

        return $default;
    }

    private function find_first_array_by_keys($source, $candidate_keys)
    {
        if (!is_array($source) || empty($candidate_keys)) {
            return null;
        }

        $lookup = array();
        foreach ($candidate_keys as $key) {
            $lookup[] = $this->normalize_key($key);
        }

        $nodes = $this->collect_nodes($source, 200);
        foreach ($nodes as $node) {
            foreach ($node as $key => $value) {
                if (!in_array($this->normalize_key($key), $lookup, true)) {
                    continue;
                }
                if (is_array($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function looks_like_product($item)
    {
        if (!is_array($item)) {
            return false;
        }

        $sku = $this->find_first_key_value($item, array('StockCode', 'Barkod', 'Barcode', 'SKU', 'Sku'), '');
        return $sku !== '';
    }

    private function looks_like_order($item)
    {
        if (!is_array($item)) {
            return false;
        }

        $order_id = $this->find_first_key_value($item, array(
            'SiparisId',
            'OrderId',
            'SiparisNo',
            'OrderNo',
            'orderId',
            'orderNo',
        ), '');

        if ($order_id !== '') {
            return true;
        }

        $status = $this->find_first_key_value($item, array('SiparisDurumu', 'OrderStatus', 'status'), '');
        return $status !== '';
    }

    private function looks_like_line_item($item)
    {
        if (!is_array($item)) {
            return false;
        }

        $sku = $this->find_first_key_value($item, array('StockCode', 'Barkod', 'SKU', 'Sku', 'stockCode'), '');
        $name = $this->find_first_key_value($item, array('ProductName', 'UrunAdi', 'name', 'Name'), '');

        return $sku !== '' || $name !== '';
    }

    private function extract_tracking_id($source)
    {
        $tracking_id = $this->find_first_key_value($source, array(
            'trackingId',
            'TrackingId',
            'trackingID',
            'batchRequestId',
            'BatchRequestId',
            'taskId',
            'TaskId',
            'requestId',
            'id',
        ), '');

        return is_scalar($tracking_id) ? trim((string) $tracking_id) : '';
    }

    private function is_method_not_supported_fault($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $data = $error->get_error_data();
        if (is_array($data) && !empty($data['method_not_supported'])) {
            return true;
        }

        $message = (string) $error->get_error_message();
        if (is_array($data) && isset($data['body']) && is_string($data['body'])) {
            $message .= ' ' . $data['body'];
        }
        if (is_array($data) && isset($data['fault']['message']) && is_string($data['fault']['message'])) {
            $message .= ' ' . $data['fault']['message'];
        }

        return $this->fault_looks_like_method_not_supported($message, '', $message);
    }

    private function is_parameter_mismatch_fault($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $data = $error->get_error_data();
        $message = (string) $error->get_error_message();
        if (is_array($data) && isset($data['body']) && is_string($data['body'])) {
            $message .= ' ' . $data['body'];
        }
        if (is_array($data) && isset($data['fault']['message']) && is_string($data['fault']['message'])) {
            $message .= ' ' . $data['fault']['message'];
        }

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($message, 'UTF-8')
            : strtolower($message);

        $needles = array(
            'deserialize',
            'serialization',
            'parameter',
            'invalid',
            'expected',
            'cannot convert',
            'cannot deserialize',
            'missing',
            'required',
        );

        foreach ($needles as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function fault_looks_like_method_not_supported($message, $fault_code, $raw_body)
    {
        $candidate = trim((string) $message . ' ' . (string) $fault_code . ' ' . (string) $raw_body);
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($candidate, 'UTF-8')
            : strtolower($candidate);

        $needles = array(
            'actionnotsupported',
            'not supported',
            'method not found',
            'contractfilter mismatch',
            'message with action',
            'cannot be processed at the receiver',
        );

        foreach ($needles as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function sanitize_xml_tag($tag, $fallback = 'item')
    {
        $tag = preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $tag);
        if ($tag === '' || preg_match('/^[0-9]/', $tag)) {
            return $fallback;
        }

        return $tag;
    }

    private function escape_xml_text($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function normalize_key($key)
    {
        $key = (string) $key;
        if ($key === '') {
            return '';
        }

        if (strpos($key, ':') !== false) {
            $parts = explode(':', $key);
            $key = end($parts);
        }

        $key = trim($key);
        if ($key === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($key, 'UTF-8');
        }

        return strtolower($key);
    }

    private function is_assoc($array)
    {
        if (!is_array($array)) {
            return false;
        }

        if (array() === $array) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function mask_xml_for_debug($xml)
    {
        if (!is_string($xml) || $xml === '') {
            return $xml;
        }

        $patterns = array(
            '~(<(?:\w+:)?Password[^>]*>)(.*?)(</(?:\w+:)?Password>)~is',
            '~(<(?:\w+:)?Token[^>]*>)(.*?)(</(?:\w+:)?Token>)~is',
            '~(<(?:\w+:)?RefreshToken[^>]*>)(.*?)(</(?:\w+:)?RefreshToken>)~is',
            '~(<(?:\w+:)?ClientSecret[^>]*>)(.*?)(</(?:\w+:)?ClientSecret>)~is',
            '~(<(?:\w+:)?ApiKey[^>]*>)(.*?)(</(?:\w+:)?ApiKey>)~is',
        );

        foreach ($patterns as $pattern) {
            $xml = preg_replace_callback($pattern, function ($matches) {
                return $matches[1] . $this->mask_sensitive_value($matches[2]) . $matches[3];
            }, $xml);
        }

        return $xml;
    }

}
