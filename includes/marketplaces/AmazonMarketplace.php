<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class AmazonMarketplace extends BaseMarketplace
{
    private const LWA_TOKEN_URL = 'https://api.amazon.com/auth/o2/token';
    private const SP_API_BASE_URL = 'https://sellingpartnerapi-eu.amazon.com';
    private const TR_MARKETPLACE_ID = 'A33AVAJ2PDY3EV';
    private const REPORT_TYPE = 'GET_MERCHANT_LISTINGS_ALL_DATA';
    private const ORDER_LOOKBACK_DAYS = 7;
    private const REPORT_POLL_ATTEMPTS = 8;
    private const REPORT_POLL_DELAY_SECONDS = 3;
    private const REPORT_CACHE_TTL = 900;

    private $in_memory_access_tokens = array();

    public function get_key()
    {
        return 'amazon';
    }

    public function get_label()
    {
        return 'Amazon';
    }

    public function validate_credentials($supplier)
    {
        $api_key = $this->get_api_key($supplier);
        $api_secret = $this->get_api_secret($supplier);
        $seller_id = $this->get_seller_id($supplier);
        $refresh_token = $this->get_refresh_token($supplier);

        if ($api_key === '' || $api_secret === '' || $seller_id === '' || $refresh_token === '') {
            return new \WP_Error(
                'multi_sync_missing_credentials',
                'Eksik yetki bilgisi: Amazon icin LWA Client ID, LWA Client Secret, Seller ID ve Refresh Token zorunludur.'
            );
        }

        return true;
    }

    public function fetch_products($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        if ($page > 0) {
            return array();
        }

        $force_refresh = !empty($params['force_refresh']);
        if (!$force_refresh) {
            $cached_rows = $this->get_cached_product_report_rows($supplier);
            if (!empty($cached_rows)) {
                return $cached_rows;
            }
        }

        $report_id = $this->create_product_report($supplier);
        if (is_wp_error($report_id)) {
            $cached_rows = $this->get_cached_product_report_rows($supplier);
            if (!empty($cached_rows)) {
                return $cached_rows;
            }
            return $report_id;
        }

        $poll_result = $this->wait_for_report_document($supplier, $report_id);
        if (is_wp_error($poll_result)) {
            $cached_rows = $this->get_cached_product_report_rows($supplier);
            if (!empty($cached_rows)) {
                return $cached_rows;
            }
            return $poll_result;
        }

        if (!empty($poll_result['empty'])) {
            $this->set_cached_product_report_rows($supplier, array());
            return array();
        }

        $document_id = isset($poll_result['report_document_id']) ? (string) $poll_result['report_document_id'] : '';
        if ($document_id === '') {
            return new \WP_Error(
                'multi_sync_amazon_missing_report_document',
                'Amazon rapor dokumani bulunamadi.'
            );
        }

        $document_result = $this->get_report_document($supplier, $document_id);
        if (is_wp_error($document_result)) {
            $cached_rows = $this->get_cached_product_report_rows($supplier);
            if (!empty($cached_rows)) {
                return $cached_rows;
            }
            return $document_result;
        }

        $download_url = isset($document_result['url']) ? (string) $document_result['url'] : '';
        if ($download_url === '') {
            return new \WP_Error(
                'multi_sync_amazon_report_download_url_missing',
                'Amazon rapor indirme baglantisi bulunamadi.'
            );
        }

        $compression = isset($document_result['compression']) ? (string) $document_result['compression'] : '';
        $rows = $this->download_and_parse_product_report($download_url, $compression);
        if (is_wp_error($rows)) {
            $cached_rows = $this->get_cached_product_report_rows($supplier);
            if (!empty($cached_rows)) {
                return $cached_rows;
            }
            return $rows;
        }

        $this->set_cached_product_report_rows($supplier, $rows);

        return $rows;
    }

    public function fetch_orders($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $max_results = isset($params['size']) ? (int) $params['size'] : 100;
        $max_results = max(1, min(100, $max_results));
        $max_pages = isset($params['max_pages']) ? (int) $params['max_pages'] : 5;
        $max_pages = max(1, min(10, $max_pages));

        $last_updated_after = $this->resolve_last_updated_after($params);
        $included_data = 'BUYER,RECIPIENT,FULFILLMENT,PROCEEDS';

        $all_orders = array();
        $pagination_token = '';

        for ($page = 0; $page < $max_pages; $page++) {
            $query = array(
                'marketplaceIds' => self::TR_MARKETPLACE_ID,
                'maxResultsPerPage' => $max_results,
                'includedData' => $included_data,
            );

            if ($pagination_token !== '') {
                $query['paginationToken'] = $pagination_token;
            } else {
                $query['lastUpdatedAfter'] = $last_updated_after;
            }

            $response = $this->search_orders_with_optional_pii_fallback(
                $supplier,
                $query,
                true
            );

            if (is_wp_error($response)) {
                return $response;
            }

            $page_data = $this->extract_orders_page_data($response['data']);
            if (!empty($page_data['orders'])) {
                $all_orders = array_merge($all_orders, $page_data['orders']);
            } elseif ($page === 0) {
                return array();
            }

            $pagination_token = isset($page_data['next_token']) ? (string) $page_data['next_token'] : '';
            if ($pagination_token === '') {
                break;
            }
        }

        return $all_orders;
    }

    public function map_product($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $sku = trim((string) $this->first_not_empty($item, array('seller-sku', 'seller_sku', 'sku', 'sellerSku', 'merchantSku'), ''));
        if ($sku === '') {
            return new \WP_Error(
                'multi_sync_amazon_missing_sku',
                'Amazon urununde SKU bulunamadi.'
            );
        }

        $price = $this->normalize_money_to_float(
            $this->first_not_empty($item, array('price', 'item-price', 'your-price', 'salePrice', 'listPrice'), null),
            null
        );

        $stock = $this->to_int(
            $this->first_not_empty($item, array('quantity', 'available-quantity', 'fulfillable-quantity', 'stockQuantity', 'stock'), 0),
            0
        );

        return array(
            'sku' => $sku,
            'name' => (string) $this->first_not_empty($item, array('item-name', 'item_name', 'title', 'name', 'productName'), $sku),
            'regular_price' => $price,
            'stock_quantity' => (int) $stock,
            'images' => array(),
            'preview_image' => '',
            'external_sku' => $sku,
            'external_barcode' => '',
            'external_product_id' => (string) $this->first_not_empty($item, array('asin', 'item-id', 'productId'), ''),
            'parent_key' => '',
            'variation_attributes' => array(),
        );
    }

    public function map_order($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $buyer = $this->first_not_empty($item, array('buyer', 'buyerInfo'), array());
        if (!is_array($buyer)) {
            $buyer = array();
        }

        $recipient = $this->first_not_empty($item, array('recipient', 'recipientInfo', 'shippingRecipient'), array());
        if (!is_array($recipient)) {
            $recipient = array();
        }

        $delivery_address = $this->first_not_empty($recipient, array('deliveryAddress', 'shippingAddress', 'address'), array());
        if (!is_array($delivery_address)) {
            $delivery_address = array();
        }

        $raw_lines = $this->first_not_empty($item, array('orderItems', 'lineItems', 'items'), array());
        if (!is_array($raw_lines)) {
            $raw_lines = array();
        }

        $line_items = array();
        foreach ($raw_lines as $line) {
            $line = is_array($line) ? $line : (array) $line;
            $product = isset($line['product']) && is_array($line['product']) ? $line['product'] : array();

            $line_quantity = $this->to_int(
                $this->first_not_empty($line, array('quantityOrdered', 'quantity', 'qty'), 1),
                1
            );
            if ($line_quantity <= 0) {
                $line_quantity = 1;
            }

            $line_price = $this->normalize_money_to_float(
                $this->first_not_empty($line, array('itemPrice', 'unitPrice', 'price', 'amount'), 0),
                0
            );

            $line_items[] = array(
                'sku' => (string) $this->first_not_empty($product, array('sellerSku', 'sku'), $this->first_not_empty($line, array('sellerSku', 'sku', 'asin'), '')),
                'name' => (string) $this->first_not_empty($product, array('title', 'name'), $this->first_not_empty($line, array('title', 'name'), '')),
                'quantity' => (int) $line_quantity,
                'price' => (float) $line_price,
            );
        }

        $full_name = trim((string) $this->first_not_empty($delivery_address, array('name', 'fullName'), ''));
        if ($full_name === '') {
            $full_name = trim((string) $this->first_not_empty($recipient, array('name', 'fullName'), ''));
        }

        $name_parts = $this->split_full_name($full_name);
        $currency = (string) $this->first_not_empty(
            array(
                'currency_1' => $this->get_nested_value($item, 'proceeds.grandTotal.currencyCode'),
                'currency_2' => $this->get_nested_value($item, 'orderTotal.currencyCode'),
                'currency_3' => $this->first_not_empty($item, array('currency', 'currencyCode'), ''),
            ),
            array('currency_1', 'currency_2', 'currency_3'),
            'TRY'
        );

        $total = $this->normalize_money_to_float(
            $this->first_not_empty(
                array(
                    'total_1' => $this->get_nested_value($item, 'proceeds.grandTotal.amount'),
                    'total_2' => $this->get_nested_value($item, 'orderTotal.amount'),
                    'total_3' => $this->first_not_empty($item, array('orderTotal', 'totalAmount', 'total'), 0),
                ),
                array('total_1', 'total_2', 'total_3'),
                0
            ),
            0
        );

        $status_raw = (string) $this->first_not_empty($item, array('status', 'orderStatus', 'fulfillmentStatus'), 'pending');
        $normalized_status = $this->normalize_order_status($status_raw);

        return array(
            'external_id' => (string) $this->first_not_empty($item, array('orderId', 'amazonOrderId', 'id'), ''),
            'status' => $normalized_status,
            'currency' => $currency !== '' ? $currency : 'TRY',
            'total' => (float) $total,
            'order_date' => $this->first_not_empty($item, array('createdTime', 'purchaseDate', 'orderDate', 'lastUpdateTime'), ''),
            'billing_first_name' => $name_parts[0],
            'billing_last_name' => $name_parts[1],
            'billing_phone' => (string) $this->first_not_empty($delivery_address, array('phoneNumber', 'phone'), ''),
            'billing_email' => (string) $this->first_not_empty($buyer, array('email', 'buyerEmail'), ''),
            'billing_address_1' => (string) $this->first_not_empty($delivery_address, array('addressLine1', 'line1', 'address1', 'address'), ''),
            'billing_address_2' => (string) $this->first_not_empty($delivery_address, array('addressLine2', 'line2', 'address2'), ''),
            'billing_city' => (string) $this->first_not_empty($delivery_address, array('city', 'cityName'), ''),
            'billing_postcode' => (string) $this->first_not_empty($delivery_address, array('postalCode', 'zipCode'), ''),
            'billing_country' => (string) $this->first_not_empty($delivery_address, array('countryCode', 'country'), 'TR'),
            'shipping_first_name' => $name_parts[0],
            'shipping_last_name' => $name_parts[1],
            'shipping_phone' => (string) $this->first_not_empty($delivery_address, array('phoneNumber', 'phone'), ''),
            'shipping_address_1' => (string) $this->first_not_empty($delivery_address, array('addressLine1', 'line1', 'address1', 'address'), ''),
            'shipping_address_2' => (string) $this->first_not_empty($delivery_address, array('addressLine2', 'line2', 'address2'), ''),
            'shipping_city' => (string) $this->first_not_empty($delivery_address, array('city', 'cityName'), ''),
            'shipping_postcode' => (string) $this->first_not_empty($delivery_address, array('postalCode', 'zipCode'), ''),
            'shipping_country' => (string) $this->first_not_empty($delivery_address, array('countryCode', 'country'), 'TR'),
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
            $price = $product->get_regular_price();
            $item['price'] = is_numeric($price) ? $this->apply_product_commission((float) $price, $product, $commission_rate) : 0.0;
            $item['currency'] = function_exists('get_woocommerce_currency')
                ? (string) get_woocommerce_currency()
                : 'TRY';
        }

        return $item;
    }

    public function fetch_product_categories($supplier, $search = '')
    {
        $query = array('marketplaceIds' => self::TR_MARKETPLACE_ID, 'sellerId' => $this->get_seller_id($supplier), 'locale' => 'tr_TR');
        if (trim((string) $search) !== '') $query['keywords'] = trim((string) $search);
        $response = $this->sp_api_request_json('GET', '/definitions/2020-09-01/productTypes', $supplier, $query);
        if (is_wp_error($response)) return $response;
        $result = array();
        foreach ((array) ($response['data']['productTypes'] ?? array()) as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if (!empty($row['name'])) $result[] = array('id' => (string) $row['name'], 'name' => (string) ($row['displayName'] ?? $row['name']), 'path' => (string) ($row['displayName'] ?? $row['name']));
        }
        return $result;
    }

    public function fetch_category_attributes($supplier, $category_id)
    {
        $query = array('marketplaceIds' => self::TR_MARKETPLACE_ID, 'sellerId' => $this->get_seller_id($supplier), 'requirements' => 'LISTING', 'requirementsEnforced' => 'ENFORCED', 'locale' => 'tr_TR', 'parentageLevel' => 'NONE');
        $response = $this->sp_api_request_json('GET', '/definitions/2020-09-01/productTypes/' . rawurlencode((string) $category_id), $supplier, $query);
        if (is_wp_error($response)) return $response;
        $schema_url = (string) ($response['data']['schema']['link']['resource'] ?? '');
        if ($schema_url === '') return new \WP_Error('multi_sync_amazon_schema_missing', 'Amazon urun tipi semasi bulunamadi.');
        $download = wp_remote_get($schema_url, array('timeout' => 60));
        if (is_wp_error($download)) return $download;
        $schema = json_decode(wp_remote_retrieve_body($download), true);
        if (!is_array($schema)) return new \WP_Error('multi_sync_amazon_schema_invalid', 'Amazon urun tipi semasi okunamadi.');
        $required = array_flip((array) ($schema['required'] ?? array()));
        $result = array();
        foreach ((array) ($schema['properties'] ?? array()) as $name => $definition) {
            if (!isset($required[$name])) continue;
            $definition = is_array($definition) ? $definition : array();
            $result[] = array('id' => (string) $name, 'name' => (string) ($definition['title'] ?? $name), 'required' => true, 'slicer' => false, 'varianter' => false, 'allow_custom' => true, 'values' => array(), 'expects_language' => strpos(wp_json_encode($definition), 'language_tag') !== false);
        }
        return $result;
    }

    public function build_product_item_from_product($product, $category_mapping = array(), $overrides = array())
    {
        $parent = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;
        if (!$product || (!$product->is_type('simple') && !$parent)) return new \WP_Error('multi_sync_amazon_unsupported_product', 'Yalnizca basit urunler ve varyasyonlar gonderilebilir.');
        $value = function ($key, $fallback = '') use ($overrides, $product, $parent) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') return trim((string) $overrides[$key]);
            $stored = trim((string) $product->get_meta('_multi_sync_amazon_' . $key, true));
            if ($stored === '' && $parent) $stored = trim((string) $parent->get_meta('_multi_sync_amazon_' . $key, true));
            return $stored !== '' ? $stored : $fallback;
        };
        $sku = $value('sku', $product->get_sku());
        $product_type = $value('category_id', $category_mapping['category_id'] ?? '');
        $brand = $value('brand', $category_mapping['brand_name'] ?? '');
        $barcode = $value('barcode');
        $missing = array();
        if ($sku === '') $missing[] = array('key' => 'sku', 'label' => 'SKU', 'type' => 'text', 'options' => array());
        if ($product_type === '') $missing[] = array('key' => 'category_id', 'label' => 'Amazon Urun Tipi', 'type' => 'text', 'options' => array());
        $source = $parent ?: $product;
        $description = $source->get_description() ?: $source->get_short_description() ?: $source->get_name();
        $image = $value('image_url');
        if ($image === '') $image = wp_get_attachment_url($product->get_image_id() ?: $source->get_image_id()) ?: '';
        $price = $this->build_price_inventory_item_from_product($product, true, true, $category_mapping['commission_rate'] ?? null);
        if (!$price || $price['price'] <= 0) return new \WP_Error('multi_sync_amazon_product_price', 'Urun fiyati sifirdan buyuk olmali.');
        $attributes = array(
            'item_name' => array(array('value' => $this->product_export_name($product, $parent), 'language_tag' => 'tr_TR', 'marketplace_id' => self::TR_MARKETPLACE_ID)),
            'product_description' => array(array('value' => wp_strip_all_tags($description), 'language_tag' => 'tr_TR', 'marketplace_id' => self::TR_MARKETPLACE_ID)),
            'condition_type' => array(array('value' => 'new_new', 'marketplace_id' => self::TR_MARKETPLACE_ID)),
            'merchant_suggested_asin' => array(),
            'fulfillment_availability' => array(array('fulfillment_channel_code' => 'DEFAULT', 'quantity' => max(0, (int) $price['quantity']))),
            'purchasable_offer' => array(array('currency' => $price['currency'], 'our_price' => array(array('schedule' => array(array('value_with_tax' => $price['price'])))), 'marketplace_id' => self::TR_MARKETPLACE_ID)),
        );
        $mapped_attributes = array();
        foreach ((array) ($category_mapping['attributes'] ?? array()) as $mapped) {
            if (!empty($mapped['attributeId'])) $mapped_attributes[(string) $mapped['attributeId']] = (string) (($mapped['attributeValue'] ?? null) ?: ($mapped['attributeValueIds'][0] ?? ''));
        }
        if ($brand !== '') $attributes['brand'] = array(array('value' => $brand, 'language_tag' => 'tr_TR', 'marketplace_id' => self::TR_MARKETPLACE_ID));
        if ($barcode !== '') $attributes['externally_assigned_product_identifier'] = array(array('type' => strlen(preg_replace('/\D/', '', $barcode)) === 12 ? 'upc' : 'ean', 'value' => $barcode, 'marketplace_id' => self::TR_MARKETPLACE_ID));
        if (preg_match('#^https?://#i', $image)) $attributes['main_product_image_locator'] = array(array('media_location' => $image, 'marketplace_id' => self::TR_MARKETPLACE_ID));
        foreach ((array) ($category_mapping['attribute_definitions'] ?? array()) as $definition) {
            $name = (string) ($definition['id'] ?? '');
            if ($name === '' || !empty($attributes[$name])) continue;
            if ($name === 'externally_assigned_product_identifier' && $barcode === '') {
                $missing[] = array('key' => 'barcode', 'label' => 'GTIN / EAN / UPC Barkod', 'type' => 'text', 'options' => array());
                continue;
            }
            if ($name === 'main_product_image_locator' && !preg_match('#^https?://#i', $image)) {
                $missing[] = array('key' => 'image_url', 'label' => 'Ana Gorsel URL', 'type' => 'text', 'options' => array());
                continue;
            }
            $input = $value('attribute_' . $name);
            if ($input === '') $input = $mapped_attributes[$name] ?? '';
            if ($input === '') {
                $missing[] = array('key' => 'attribute_' . $name, 'label' => (string) ($definition['name'] ?? $name) . ' (deger veya JSON)', 'type' => 'text', 'options' => array());
                continue;
            }
            $decoded = json_decode($input, true);
            $attributes[$name] = is_array($decoded) ? $decoded : array(array_filter(array('value' => sanitize_text_field($input), 'language_tag' => !empty($definition['expects_language']) ? 'tr_TR' : null, 'marketplace_id' => self::TR_MARKETPLACE_ID), function ($v) { return $v !== null; }));
        }
        unset($attributes['merchant_suggested_asin']);
        if ($missing) return new \WP_Error('multi_sync_amazon_product_incomplete', 'Eksik Amazon bilgilerini doldurun.', array('fields' => $missing));
        return array('sku' => $sku, 'productType' => $product_type, 'requirements' => 'LISTING', 'attributes' => $attributes);
    }

    public function push_products($supplier, $items)
    {
        $results = array();
        foreach ((array) $items as $item) {
            $sku = (string) ($item['sku'] ?? '');
            unset($item['sku']);
            $path = sprintf('/listings/2021-08-01/items/%s/%s', rawurlencode($this->get_seller_id($supplier)), rawurlencode($sku));
            $response = $this->sp_api_request_json('PUT', $path, $supplier, array('marketplaceIds' => self::TR_MARKETPLACE_ID, 'issueLocale' => 'tr_TR'), $item);
            if (is_wp_error($response)) return $response;
            $results[] = $response['data'];
        }
        return array('results' => $results);
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

        $seller_id = $this->get_seller_id($supplier);
        $accepted = 0;
        $failed = 0;
        $results = array();

        foreach ($items as $item) {
            $item = is_array($item) ? $item : (array) $item;
            $sku = trim((string) $this->first_not_empty($item, array('sku', 'sellerSku', 'stockCode', 'barcode'), ''));

            if ($sku === '') {
                $failed++;
                $results[] = array(
                    'sku' => '',
                    'status' => 'failed',
                    'message' => 'SKU bos oldugu icin atlandi.',
                );
                continue;
            }

            $patches = $this->build_listing_patches($item);
            if (empty($patches)) {
                $failed++;
                $results[] = array(
                    'sku' => $sku,
                    'status' => 'failed',
                    'message' => 'Gonderilecek stok/fiyat verisi bulunamadi.',
                );
                continue;
            }

            $path = sprintf(
                '/listings/2021-08-01/items/%s/%s',
                rawurlencode($seller_id),
                rawurlencode($sku)
            );

            $query = array(
                'marketplaceIds' => self::TR_MARKETPLACE_ID,
                'issueLocale' => 'tr_TR',
            );

            $payload = array(
                'productType' => 'PRODUCT',
                'requirements' => 'LISTING_OFFER_ONLY',
                'patches' => $patches,
            );

            $response = $this->sp_api_request_json('PATCH', $path, $supplier, $query, $payload);
            if (is_wp_error($response)) {
                $failed++;
                $results[] = array(
                    'sku' => $sku,
                    'status' => 'failed',
                    'message' => $response->get_error_message(),
                );
                continue;
            }

            $accepted++;
            $results[] = array(
                'sku' => $sku,
                'status' => 'accepted',
                'response' => isset($response['data']) ? $response['data'] : array(),
            );
        }

        return array(
            'accepted' => $accepted,
            'failed' => $failed,
            'results' => $results,
            'batchRequestIds' => array(),
        );
    }

    public function get_batch_request_result($supplier, $batch_request_id)
    {
        return array(
            'status' => 'not_applicable',
            'batch_request_id' => (string) $batch_request_id,
            'message' => 'Amazon listings PATCH islemleri senkron oldugu icin batch sonuc sorgulama uygulanmaz.',
        );
    }

    private function get_refresh_token($supplier)
    {
        return trim((string) $this->supplier_value($supplier, 'amazon_refresh_token', ''));
    }

    private function build_listing_patches($item)
    {
        $patches = array();

        if (array_key_exists('quantity', $item)) {
            $quantity = $this->to_int($item['quantity'], 0);
            if ($quantity === null) {
                $quantity = 0;
            }
            $quantity = max(0, (int) $quantity);

            $patches[] = array(
                'op' => 'replace',
                'path' => '/attributes/fulfillment_availability',
                'value' => array(
                    array(
                        'fulfillment_channel_code' => 'DEFAULT',
                        'quantity' => $quantity,
                        'marketplace_id' => self::TR_MARKETPLACE_ID,
                    ),
                ),
            );
        }

        if (array_key_exists('price', $item) || array_key_exists('salePrice', $item) || array_key_exists('listPrice', $item)) {
            $raw_price = $this->first_not_empty($item, array('price', 'salePrice', 'listPrice'), null);
            $price = $this->normalize_money_to_float($raw_price, null);
            if ($price !== null) {
                $currency = (string) $this->first_not_empty(
                    $item,
                    array('currency'),
                    function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'TRY'
                );

                $patches[] = array(
                    'op' => 'replace',
                    'path' => '/attributes/purchasable_offer',
                    'value' => array(
                        array(
                            'currency' => $currency !== '' ? $currency : 'TRY',
                            'marketplace_id' => self::TR_MARKETPLACE_ID,
                            'our_price' => array(
                                array(
                                    'schedule' => array(
                                        array(
                                            'value_with_tax' => round((float) $price, 2),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                );
            }
        }

        return $patches;
    }

    private function resolve_last_updated_after($params)
    {
        $candidate = '';
        if (isset($params['lastUpdatedAfter']) && $params['lastUpdatedAfter'] !== '') {
            $candidate = (string) $params['lastUpdatedAfter'];
        } elseif (isset($params['startDate']) && $params['startDate'] !== '') {
            $candidate = (string) $params['startDate'];
        } elseif (isset($params['StartDate']) && $params['StartDate'] !== '') {
            $candidate = (string) $params['StartDate'];
        }

        if ($candidate !== '') {
            $timestamp = strtotime($candidate);
            if ($timestamp) {
                return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
        }

        $fallback = time() - (self::ORDER_LOOKBACK_DAYS * DAY_IN_SECONDS);
        return gmdate('Y-m-d\TH:i:s\Z', $fallback);
    }

    private function search_orders_with_optional_pii_fallback($supplier, $query, $allow_fallback)
    {
        $response = $this->sp_api_request_json(
            'GET',
            '/orders/2026-01-01/orders',
            $supplier,
            $query
        );

        if (!is_wp_error($response)) {
            return $response;
        }

        if (!$allow_fallback || !$this->is_pii_restriction_error($response)) {
            return $response;
        }

        $fallback_query = $query;
        $fallback_query['includedData'] = 'FULFILLMENT,PROCEEDS';

        return $this->sp_api_request_json(
            'GET',
            '/orders/2026-01-01/orders',
            $supplier,
            $fallback_query
        );
    }

    private function is_pii_restriction_error($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $error_data = $error->get_error_data();
        $status_code = is_array($error_data) && isset($error_data['code'])
            ? (int) $error_data['code']
            : 0;

        if (!in_array($status_code, array(400, 401, 403), true)) {
            return false;
        }

        $message = (string) $error->get_error_message();
        if (is_array($error_data) && isset($error_data['body']) && is_string($error_data['body'])) {
            $message .= ' ' . $error_data['body'];
        }

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($message, 'UTF-8')
            : strtolower($message);

        $needles = array(
            'restricted',
            'insufficient',
            'not authorized',
            'unauthorized',
            'access denied',
            'role',
            'buyer',
            'recipient',
            'pii',
        );

        foreach ($needles as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function extract_orders_page_data($response_data)
    {
        $orders = array();
        $next_token = '';
        $nodes = $this->collect_candidate_nodes($response_data);

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (empty($orders)) {
                $candidate = $this->first_not_empty($node, array('orders', 'orderSummaries', 'items'), null);
                if (is_array($candidate)) {
                    $orders = isset($candidate[0]) ? array_values($candidate) : array();
                }
            }

            if ($next_token === '') {
                $next_token = (string) $this->first_not_empty($node, array('nextToken', 'paginationToken', 'nextPageToken'), '');
                if ($next_token === '' && isset($node['pagination']) && is_array($node['pagination'])) {
                    $next_token = (string) $this->first_not_empty(
                        $node['pagination'],
                        array('nextToken', 'paginationToken', 'nextPageToken'),
                        ''
                    );
                }
            }

            if (!empty($orders) && $next_token !== '') {
                break;
            }
        }

        return array(
            'orders' => $orders,
            'next_token' => $next_token,
        );
    }

    private function normalize_order_status($status)
    {
        $normalized = strtolower(trim((string) $status));
        $map = array(
            'pending' => 'pending',
            'pendingavailability' => 'pending',
            'unsupplied' => 'pending',
            'unshipped' => 'processing',
            'partiallyshipped' => 'processing',
            'invoiceunconfirmed' => 'on-hold',
            'shipped' => 'completed',
            'delivered' => 'completed',
            'canceled' => 'cancelled',
            'cancelled' => 'cancelled',
        );

        return isset($map[$normalized]) ? $map[$normalized] : 'pending';
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
        $last_name = implode(' ', $parts);

        return array($first_name, $last_name);
    }

    private function get_nested_value($source, $path, $default = null)
    {
        if (!is_array($source) || !is_string($path) || $path === '') {
            return $default;
        }

        $parts = explode('.', $path);
        $current = $source;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    private function normalize_money_to_float($value, $default = null)
    {
        if (is_array($value)) {
            if (isset($value['amount'])) {
                $value = $value['amount'];
            } elseif (isset($value['value'])) {
                $value = $value['value'];
            } else {
                return $default;
            }
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

        return is_numeric($normalized) ? (float) $normalized : $default;
    }

    private function create_product_report($supplier)
    {
        $payload = array(
            'reportType' => self::REPORT_TYPE,
            'marketplaceIds' => array(self::TR_MARKETPLACE_ID),
        );

        $response = $this->sp_api_request_json(
            'POST',
            '/reports/2021-06-30/reports',
            $supplier,
            array(),
            $payload
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $report_id = $this->extract_first_scalar($response['data'], array('reportId', 'report_id'));
        if ($report_id === '') {
            return new \WP_Error(
                'multi_sync_amazon_report_id_missing',
                'Amazon rapor olusturuldu ancak reportId alinamadi.'
            );
        }

        return $report_id;
    }

    private function wait_for_report_document($supplier, $report_id)
    {
        for ($attempt = 0; $attempt < self::REPORT_POLL_ATTEMPTS; $attempt++) {
            $response = $this->sp_api_request_json(
                'GET',
                '/reports/2021-06-30/reports/' . rawurlencode((string) $report_id),
                $supplier
            );

            if (is_wp_error($response)) {
                return $response;
            }

            $processing_status = strtoupper($this->extract_first_scalar(
                $response['data'],
                array('processingStatus', 'status')
            ));

            if ($processing_status === 'DONE') {
                $document_id = $this->extract_first_scalar(
                    $response['data'],
                    array('reportDocumentId', 'report_document_id')
                );

                if ($document_id === '') {
                    return new \WP_Error(
                        'multi_sync_amazon_report_document_missing',
                        'Amazon rapor tamamlandi ancak reportDocumentId bulunamadi.'
                    );
                }

                return array(
                    'empty' => false,
                    'report_document_id' => $document_id,
                );
            }

            if ($processing_status === 'DONE_NO_DATA') {
                return array(
                    'empty' => true,
                    'report_document_id' => '',
                );
            }

            if (in_array($processing_status, array('FATAL', 'CANCELLED'), true)) {
                return new \WP_Error(
                    'multi_sync_amazon_report_failed',
                    'Amazon rapor olusturma islemi basarisiz oldu: ' . $processing_status
                );
            }

            if ($attempt < (self::REPORT_POLL_ATTEMPTS - 1)) {
                sleep(self::REPORT_POLL_DELAY_SECONDS);
            }
        }

        return new \WP_Error(
            'multi_sync_amazon_report_pending',
            'Amazon raporu halen hazir degil. Son cache varsa onunla devam edebilirsiniz.'
        );
    }

    private function get_report_document($supplier, $document_id)
    {
        $response = $this->sp_api_request_json(
            'GET',
            '/reports/2021-06-30/documents/' . rawurlencode((string) $document_id),
            $supplier
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $download_url = $this->extract_first_scalar($response['data'], array('url'));
        $compression = strtoupper($this->extract_first_scalar($response['data'], array('compressionAlgorithm', 'compression')));

        return array(
            'url' => $download_url,
            'compression' => $compression,
        );
    }

    private function download_and_parse_product_report($download_url, $compression)
    {
        $response = wp_remote_get($download_url, array(
            'timeout' => 60,
            'redirection' => 5,
            'headers' => array(
                'Accept' => 'text/plain,application/octet-stream,application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return new \WP_Error(
                'multi_sync_amazon_report_download_failed',
                'Amazon raporu indirilemedi: ' . $response->get_error_message()
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);

        if ($status_code >= 400) {
            return new \WP_Error(
                'multi_sync_amazon_report_download_http_error',
                sprintf('Amazon rapor indirilemedi (%d).', $status_code)
            );
        }

        $content = is_string($raw_body) ? $raw_body : '';
        if ($compression === 'GZIP') {
            if (function_exists('gzdecode')) {
                $decoded = @gzdecode($content);
            } else {
                $decoded = @gzinflate(substr($content, 10));
            }

            if ($decoded === false || !is_string($decoded)) {
                return new \WP_Error(
                    'multi_sync_amazon_report_decompress_failed',
                    'Amazon rapor GZIP acilamadi.'
                );
            }

            $content = $decoded;
        }

        return $this->parse_product_report_tsv($content);
    }

    private function parse_product_report_tsv($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return array();
        }

        $lines = preg_split('/\r\n|\n|\r/', $content);
        if (!is_array($lines) || empty($lines)) {
            return array();
        }

        $header_line = array_shift($lines);
        if (!is_string($header_line) || trim($header_line) === '') {
            return array();
        }

        $headers = str_getcsv($header_line, "\t");
        if (!is_array($headers) || empty($headers)) {
            return array();
        }

        $normalized_headers = array();
        foreach ($headers as $header) {
            $normalized_headers[] = strtolower(trim((string) $header));
        }

        $rows = array();
        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, "\t");
            if (!is_array($columns)) {
                continue;
            }

            $assoc = array();
            foreach ($normalized_headers as $index => $header_key) {
                if ($header_key === '') {
                    continue;
                }
                $assoc[$header_key] = isset($columns[$index]) ? trim((string) $columns[$index]) : '';
            }

            $sku = trim((string) $this->first_not_empty($assoc, array('seller-sku', 'seller_sku', 'sku'), ''));
            if ($sku === '') {
                continue;
            }

            $rows[] = array(
                'seller-sku' => $sku,
                'item-name' => (string) $this->first_not_empty(
                    $assoc,
                    array('item-name', 'item_name', 'item-name/description', 'product-name', 'product_name', 'title'),
                    $sku
                ),
                'price' => $this->first_not_empty(
                    $assoc,
                    array('price', 'item-price', 'your-price', 'standard-price'),
                    ''
                ),
                'quantity' => $this->first_not_empty(
                    $assoc,
                    array('quantity', 'available-quantity', 'fulfillable-quantity'),
                    ''
                ),
            );
        }

        return $rows;
    }

    private function get_cached_product_report_rows($supplier)
    {
        $cache_key = $this->build_product_report_cache_key($supplier);
        $cached = get_transient($cache_key);
        return is_array($cached) ? $cached : array();
    }

    private function set_cached_product_report_rows($supplier, $rows)
    {
        $cache_key = $this->build_product_report_cache_key($supplier);
        set_transient($cache_key, is_array($rows) ? $rows : array(), self::REPORT_CACHE_TTL);
    }

    private function build_product_report_cache_key($supplier)
    {
        return 'multi_sync_amazon_products_report_' . md5(
            $this->get_supplier_row_id($supplier) . '|' . $this->get_seller_id($supplier)
        );
    }

    private function ensure_access_token($supplier)
    {
        $cached = $this->get_cached_access_token($supplier);
        if ($cached !== '') {
            return $cached;
        }

        $token_result = $this->request_access_token($supplier);
        if (is_wp_error($token_result)) {
            return $token_result;
        }

        $token = isset($token_result['access_token']) ? (string) $token_result['access_token'] : '';
        $expires_in = isset($token_result['expires_in']) ? (int) $token_result['expires_in'] : 3600;

        if ($token === '') {
            return new \WP_Error(
                'multi_sync_amazon_access_token_missing',
                'Amazon access token alinamadi.'
            );
        }

        $ttl = max(300, $expires_in - 60);
        $cache_key = $this->build_access_token_cache_key($supplier);

        set_transient($cache_key, $token, $ttl);
        $this->in_memory_access_tokens[$cache_key] = $token;

        return $token;
    }

    private function request_access_token($supplier)
    {
        $payload = array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->get_refresh_token($supplier),
            'client_id' => $this->get_api_key($supplier),
            'client_secret' => $this->get_api_secret($supplier),
        );

        $debug_payload = $payload;
        if (isset($debug_payload['refresh_token'])) {
            $debug_payload['refresh_token'] = $this->mask_sensitive_value($debug_payload['refresh_token']);
        }
        if (isset($debug_payload['client_secret'])) {
            $debug_payload['client_secret'] = $this->mask_sensitive_value($debug_payload['client_secret']);
        }

        $headers = array(
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        );

        $debug_entry = array(
            'timestamp' => current_time('mysql'),
            'supplier_id' => $this->get_supplier_row_id($supplier),
            'marketplace_key' => $this->get_key(),
            'operation' => 'amazon_lwa_token',
            'request' => array(
                'method' => 'POST',
                'url' => self::LWA_TOKEN_URL,
                'headers' => $this->sanitize_headers_for_debug($headers),
                'body' => $this->truncate_debug_body($debug_payload),
            ),
            'response' => array(),
        );

        $response = wp_remote_post(self::LWA_TOKEN_URL, array(
            'timeout' => 30,
            'redirection' => 5,
            'headers' => $headers,
            'body' => http_build_query($payload),
        ));

        if (is_wp_error($response)) {
            $debug_entry['response'] = array(
                'error' => $response->get_error_message(),
            );
            $this->store_http_debug($supplier, $debug_entry);
            return new \WP_Error(
                'multi_sync_amazon_access_token_request_failed',
                'Amazon LWA token istegi basarisiz: ' . $response->get_error_message()
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        $debug_entry['response'] = array(
            'status_code' => $status_code,
            'body' => $this->truncate_debug_body($raw_body),
        );
        $this->store_http_debug($supplier, $debug_entry);

        if ($status_code >= 400) {
            return new \WP_Error(
                'multi_sync_amazon_access_token_http_error',
                sprintf('Amazon LWA token istegi basarisiz (%d).', $status_code),
                array('code' => $status_code, 'body' => $raw_body)
            );
        }

        if (!is_array($decoded)) {
            return new \WP_Error(
                'multi_sync_amazon_access_token_json_error',
                'Amazon LWA token yaniti JSON olarak cozumlenemedi.'
            );
        }

        return array(
            'access_token' => isset($decoded['access_token']) ? (string) $decoded['access_token'] : '',
            'expires_in' => isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : 3600,
        );
    }

    private function get_cached_access_token($supplier)
    {
        $cache_key = $this->build_access_token_cache_key($supplier);

        if (isset($this->in_memory_access_tokens[$cache_key]) && is_string($this->in_memory_access_tokens[$cache_key])) {
            return $this->in_memory_access_tokens[$cache_key];
        }

        $token = get_transient($cache_key);
        if ($token !== false && is_string($token) && $token !== '') {
            $this->in_memory_access_tokens[$cache_key] = $token;
            return $token;
        }

        return '';
    }

    private function clear_cached_access_token($supplier)
    {
        $cache_key = $this->build_access_token_cache_key($supplier);
        delete_transient($cache_key);
        unset($this->in_memory_access_tokens[$cache_key]);
    }

    private function build_access_token_cache_key($supplier)
    {
        return 'multi_sync_amazon_access_token_' . md5(
            $this->get_supplier_row_id($supplier) . '|' .
            $this->get_api_key($supplier) . '|' .
            $this->get_api_secret($supplier) . '|' .
            $this->get_refresh_token($supplier)
        );
    }

    private function sp_api_request_json($method, $path, $supplier, $query = array(), $body = null, $options = array())
    {
        $method = strtoupper((string) $method);
        $max_retries = isset($options['max_retries']) ? max(1, (int) $options['max_retries']) : 3;
        $refreshed_after_401 = false;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $token = $this->ensure_access_token($supplier);
            if (is_wp_error($token)) {
                return $token;
            }

            $url = rtrim(self::SP_API_BASE_URL, '/') . '/' . ltrim($path, '/');
            if (!empty($query) && is_array($query)) {
                $url .= '?' . http_build_query($query);
            }

            $headers = array(
                'Accept' => 'application/json',
                'x-amz-access-token' => $token,
                'User-Agent' => 'MultiSync-AmazonSPAPI/1.0',
            );

            $request_args = array(
                'method' => $method,
                'timeout' => 45,
                'redirection' => 5,
                'headers' => $headers,
            );

            $debug_body = null;
            if ($body !== null) {
                $headers['Content-Type'] = 'application/json';
                $request_args['headers'] = $headers;
                $request_args['body'] = is_string($body) ? $body : wp_json_encode($body);
                $debug_body = $body;
            }

            $debug_entry = array(
                'timestamp' => current_time('mysql'),
                'supplier_id' => $this->get_supplier_row_id($supplier),
                'marketplace_key' => $this->get_key(),
                'request' => array(
                    'method' => $method,
                    'url' => $url,
                    'headers' => $this->sanitize_headers_for_debug($headers),
                    'body' => $this->truncate_debug_body($debug_body),
                ),
                'response' => array(),
            );

            $response = wp_remote_request($url, $request_args);
            if (is_wp_error($response)) {
                $debug_entry['response'] = array(
                    'error' => $response->get_error_message(),
                );
                $this->store_http_debug($supplier, $debug_entry);
                if ($attempt < $max_retries) {
                    sleep($attempt);
                    continue;
                }

                return new \WP_Error(
                    'multi_sync_amazon_http_request_failed',
                    'Amazon SP-API istegi basarisiz: ' . $response->get_error_message()
                );
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $decoded = array();

            if (is_string($raw_body) && trim($raw_body) !== '') {
                $json = json_decode($raw_body, true);
                if (is_array($json)) {
                    $decoded = $json;
                }
            }

            $debug_entry['response'] = array(
                'status_code' => $status_code,
                'body' => $this->truncate_debug_body($raw_body),
            );
            $this->store_http_debug($supplier, $debug_entry);

            if ($status_code === 401 && !$refreshed_after_401) {
                $refreshed_after_401 = true;
                $this->clear_cached_access_token($supplier);
                continue;
            }

            if (in_array($status_code, array(429, 503), true) && $attempt < $max_retries) {
                sleep($attempt);
                continue;
            }

            if ($status_code >= 400) {
                $message = sprintf('Amazon SP-API istegi basarisiz (%d).', $status_code);
                if (!empty($decoded) && isset($decoded['errors'][0]['message'])) {
                    $message .= ' ' . (string) $decoded['errors'][0]['message'];
                }

                return new \WP_Error(
                    'multi_sync_amazon_http_error',
                    $message,
                    array(
                        'code' => $status_code,
                        'body' => $raw_body,
                        'data' => $decoded,
                    )
                );
            }

            return array(
                'status_code' => $status_code,
                'body' => $raw_body,
                'data' => $decoded,
            );
        }

        return new \WP_Error(
            'multi_sync_amazon_http_retries_exhausted',
            'Amazon SP-API istegi tekrar denemelerden sonra da basarisiz.'
        );
    }

    private function collect_candidate_nodes($root)
    {
        $nodes = array();
        if (!is_array($root)) {
            return $nodes;
        }

        $nodes[] = $root;
        $queue = array($root);
        $keys = array('payload', 'data', 'result', 'response');

        while (!empty($queue) && count($nodes) < 16) {
            $node = array_shift($queue);
            if (!is_array($node)) {
                continue;
            }

            foreach ($keys as $key) {
                if (!isset($node[$key]) || !is_array($node[$key])) {
                    continue;
                }

                $nodes[] = $node[$key];
                $queue[] = $node[$key];
            }
        }

        return $nodes;
    }

    private function extract_first_scalar($root, $keys)
    {
        $nodes = $this->collect_candidate_nodes($root);

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach ($keys as $key) {
                if (!isset($node[$key])) {
                    continue;
                }

                $value = $node[$key];
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return '';
    }
}
