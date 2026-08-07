<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class TrendyolMarketplace extends BaseMarketplace
{
    private const QUESTION_REQUEST_MAX_ATTEMPTS = 3;
    private const TRANSIENT_HTTP_STATUS_CODES = array(429, 500, 502, 503, 504, 556);
    private $product_page_tokens = array();
    private $use_legacy_product_endpoint = false;

    public function get_key()
    {
        return 'trendyol';
    }

    public function get_label()
    {
        return 'Trendyol';
    }

    public function fetch_products($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        $size = isset($params['size']) ? max(1, min(100, (int) $params['size'])) : 100;

        if ($this->use_legacy_product_endpoint) {
            return $this->fetch_legacy_products($supplier, $page, $size);
        }

        $token_key = $this->get_seller_id($supplier) . ':' . $page;
        $page_token = !empty($params['nextPageToken'])
            ? (string) $params['nextPageToken']
            : (isset($this->product_page_tokens[$token_key]) ? $this->product_page_tokens[$token_key] : '');
        $query = array('size' => $size);
        if ($page_token !== '') {
            $query['nextPageToken'] = $page_token;
        } else {
            $query['page'] = $page;
        }

        $url = sprintf(
            'https://apigw.trendyol.com/integration/product/sellers/%s/products/approved?%s',
            rawurlencode($this->get_seller_id($supplier)),
            http_build_query($query)
        );

        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            if ($this->should_use_legacy_products($response)) {
                $this->use_legacy_product_endpoint = true;
                return $this->fetch_legacy_products($supplier, $page, $size);
            }
            return $response;
        }

        $response_data = is_array($response['data']) ? $response['data'] : array();
        $next_token = (string) $this->first_not_empty($response_data, array('nextPageToken', 'nextToken'), '');
        if ($next_token !== '') {
            $this->product_page_tokens[$this->get_seller_id($supplier) . ':' . ($page + 1)] = $next_token;
        }

        $parents = $this->extract_list($response_data, array('content', 'items', 'products', 'data'));
        $items = array();
        foreach ($parents as $parent) {
            $parent = is_array($parent) ? $parent : (array) $parent;
            $variants = isset($parent['variants']) && is_array($parent['variants']) ? $parent['variants'] : array();
            if (empty($variants)) {
                $items[] = $parent;
                continue;
            }
            foreach ($variants as $variant) {
                $variant = is_array($variant) ? $variant : (array) $variant;
                unset($parent['variants']);
                $variant['_parent_attributes'] = isset($parent['attributes']) ? $parent['attributes'] : array();
                $items[] = array_merge($parent, $variant);
            }
        }
        return $items;
    }

    private function should_use_legacy_products($error)
    {
        $data = $error->get_error_data();
        $body = is_array($data) && isset($data['body']) ? (string) $data['body'] : '';
        return is_array($data)
            && isset($data['code'])
            && (int) $data['code'] === 404
            && (strpos($body, 'ClientApiDomainNotFoundException') !== false || strpos($body, 'product.not.found') !== false);
    }

    private function fetch_legacy_products($supplier, $page, $size)
    {
        $url = sprintf(
            'https://apigw.trendyol.com/integration/product/sellers/%s/products?%s',
            rawurlencode($this->get_seller_id($supplier)),
            http_build_query(array('page' => $page, 'size' => $size))
        );
        $response = $this->request_json('GET', $url, $supplier);
        return is_wp_error($response)
            ? $response
            : $this->extract_list($response['data'], array('content', 'items', 'products', 'data'));
    }

    public function fetch_orders($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $query_args = array(
            'page' => isset($params['page']) ? max(0, (int) $params['page']) : 0,
            'size' => isset($params['size']) ? max(1, (int) $params['size']) : 200,
        );

        $optional_keys = array(
            'startDate',
            'endDate',
            'status',
            'orderByField',
            'orderByDirection',
        );
        foreach ($optional_keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query_args[$key] = $params[$key];
            }
        }

        $url = sprintf(
            'https://apigw.trendyol.com/integration/order/sellers/%s/orders?%s',
            rawurlencode($this->get_seller_id($supplier)),
            http_build_query($query_args)
        );

        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        return $this->extract_list($response['data'], array('content', 'orders', 'items', 'data', 'result'));
    }

    public function fetch_questions($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
        $size = isset($params['size']) ? max(1, min(100, (int) $params['size'])) : 50;

        $query_args = array(
            'page' => $page,
            'size' => $size,
        );

        $optional_keys = array('status', 'orderByField', 'orderByDirection', 'startDate', 'endDate');
        foreach ($optional_keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query_args[$key] = $params[$key];
            }
        }

        // Prefer newest-first when caller does not force a direction.
        if (!isset($query_args['orderByDirection']) || $query_args['orderByDirection'] === '') {
            $query_args['orderByDirection'] = 'DESC';
        }

        $result = $this->execute_questions_query($supplier, $query_args, $page, $size);
        if (is_wp_error($result)) {
            return $result;
        }

        if (!empty($result['items'])) {
            return $result;
        }

        // Some accounts behave as 1-based pages. Retry once for first page.
        if ($page === 0) {
            $query_args_page_one = $query_args;
            $query_args_page_one['page'] = 1;
            $page_one_result = $this->execute_questions_query($supplier, $query_args_page_one, 1, $size);
            if (!is_wp_error($page_one_result) && !empty($page_one_result['items'])) {
                if (function_exists('multi_sync_debug_log')) {
                    multi_sync_debug_log('Trendyol questions fallback used: page=1 returned data while page=0 was empty.');
                }
                return $page_one_result;
            }
        }

        // If status is not provided, try common status filters so recent questions are not missed.
        if (!isset($query_args['status']) || $query_args['status'] === '') {
            foreach (array('WAITING_FOR_ANSWER', 'ANSWERED', 'REJECTED') as $fallback_status) {
                $status_query_args = $query_args;
                $status_query_args['status'] = $fallback_status;

                $status_result = $this->execute_questions_query($supplier, $status_query_args, $page, $size);
                if (is_wp_error($status_result)) {
                    continue;
                }

                if (!empty($status_result['items'])) {
                    if (function_exists('multi_sync_debug_log')) {
                        multi_sync_debug_log(sprintf(
                            'Trendyol questions fallback used: status=%s returned %d item(s).',
                            $fallback_status,
                            count($status_result['items'])
                        ));
                    }
                    return $status_result;
                }

                if ($page === 0) {
                    $status_query_args['page'] = 1;
                    $status_page_one_result = $this->execute_questions_query($supplier, $status_query_args, 1, $size);
                    if (!is_wp_error($status_page_one_result) && !empty($status_page_one_result['items'])) {
                        if (function_exists('multi_sync_debug_log')) {
                            multi_sync_debug_log(sprintf(
                                'Trendyol questions fallback used: status=%s page=1 returned %d item(s).',
                                $fallback_status,
                                count($status_page_one_result['items'])
                            ));
                        }
                        return $status_page_one_result;
                    }
                }
            }
        }

        return $result;
    }

    public function map_product($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $external_sku = trim((string) $this->first_not_empty($item, array('stockCode'), ''));
        $barcode = trim((string) $this->first_not_empty($item, array('barcode'), ''));
        if ($external_sku === '' && $barcode === '') {
            $title = (string) $this->first_not_empty($item, array('title', 'name', 'barcode', 'productMainId'), 'unknown');
            return new \WP_Error(
                'multi_sync_trendyol_missing_stock_code',
                sprintf('Trendyol urununde stockCode zorunlu. Urun: %s', $title)
            );
        }

        $sku = $barcode !== '' ? $barcode : $external_sku;
        $name = (string) $this->first_not_empty($item, array('title', 'name', 'productMainId', 'barcode'), $sku);
        $price = isset($item['price']) && is_array($item['price']) ? $item['price'] : $item;
        $stock = isset($item['stock']) && is_array($item['stock']) ? $item['stock'] : $item;
        $list_price = $this->to_float($this->first_not_empty($price, array('listPrice', 'list', 'salePrice')), null);
        $sale_price = $this->to_float($this->first_not_empty($price, array('salePrice', 'sale', 'listPrice')), null);
        $quantity = $this->to_int($this->first_not_empty($stock, array('quantity', 'stockQuantity', 'stock')), 0);

        $images = array();
        if (isset($item['images']) && is_array($item['images'])) {
            foreach ($item['images'] as $image_item) {
                $image = is_array($image_item) ? $image_item : (array) $image_item;
                $url = $this->first_not_empty($image, array('url', 'imageUrl', 'src'));
                if (is_string($url) && $url !== '') {
                    $images[] = $url;
                }
            }
        }

        return array(
            'sku' => $sku,
            'name' => $name,
            'regular_price' => $list_price,
            'sale_price' => $sale_price !== null && $list_price !== null && $sale_price < $list_price ? $sale_price : '',
            'stock_quantity' => $quantity,
            'images' => $images,
            'preview_image' => !empty($images) ? $images[0] : '',
            'external_sku' => $external_sku,
            'external_barcode' => $barcode,
            'external_product_id' => (string) $this->first_not_empty($item, array('variantId', 'id', 'productId', 'productCode', 'contentId'), ''),
            'parent_key' => (string) $this->first_not_empty($item, array('productMainId'), ''),
            'variation_attributes' => $this->map_variation_attributes(array_merge(
                (array) $this->first_not_empty($item, array('_parent_attributes'), array()),
                (array) $this->first_not_empty($item, array('attributes'), array())
            )),
        );
    }

    public function map_order($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $shipment = isset($item['shipmentAddress']) && is_array($item['shipmentAddress'])
            ? $item['shipmentAddress']
            : array();
        $invoice = isset($item['invoiceAddress']) && is_array($item['invoiceAddress'])
            ? $item['invoiceAddress']
            : array();

        $email = $this->first_not_empty($item, array('customerEmail', 'email'), '');
        if ($email === '') {
            $email = (string) $this->first_not_empty($invoice, array('email'), '');
        }

        $line_items = array();
        $raw_lines = $this->first_not_empty($item, array('lines', 'lineItems', 'items'), array());
        if (is_array($raw_lines)) {
            foreach ($raw_lines as $line) {
                $line = is_array($line) ? $line : (array) $line;

                $line_items[] = array(
                    'sku' => (string) $this->first_not_empty($line, array('barcode', 'merchantSku', 'stockCode', 'sku'), ''),
                    'external_barcode' => (string) $this->first_not_empty($line, array('barcode'), ''),
                    'external_sku' => (string) $this->first_not_empty($line, array('merchantSku', 'stockCode'), ''),
                    'name' => (string) $this->first_not_empty($line, array('productName', 'name', 'title'), ''),
                    'quantity' => (int) $this->to_int($this->first_not_empty($line, array('quantity')), 1),
                    'price' => (float) $this->to_float($this->first_not_empty($line, array('price', 'amount', 'salePrice')), 0),
                );
            }
        }

        return array(
            'external_id' => (string) $this->first_not_empty($item, array('orderNumber', 'id'), ''),
            'status' => (string) $this->first_not_empty($item, array('status', 'orderStatus'), 'pending'),
            'currency' => (string) $this->first_not_empty($item, array('currencyCode', 'currency'), 'TRY'),
            'total' => (float) $this->to_float($this->first_not_empty($item, array('totalPrice', 'totalAmount')), 0),
            'order_date' => $this->first_not_empty($item, array('orderDate', 'createdDate'), ''),
            'billing_first_name' => (string) $this->first_not_empty($shipment, array('firstName'), ''),
            'billing_last_name' => (string) $this->first_not_empty($shipment, array('lastName'), ''),
            'billing_phone' => (string) $this->first_not_empty($shipment, array('phone'), ''),
            'billing_email' => (string) $email,
            'billing_address_1' => (string) $this->first_not_empty($shipment, array('fullAddress', 'address1', 'address'), ''),
            'billing_city' => (string) $this->first_not_empty($shipment, array('city'), ''),
            'billing_postcode' => (string) $this->first_not_empty($shipment, array('postalCode'), ''),
            'billing_country' => (string) $this->first_not_empty($shipment, array('countryCode', 'country'), 'TR'),
            'shipping_first_name' => (string) $this->first_not_empty($shipment, array('firstName'), ''),
            'shipping_last_name' => (string) $this->first_not_empty($shipment, array('lastName'), ''),
            'shipping_phone' => (string) $this->first_not_empty($shipment, array('phone'), ''),
            'shipping_address_1' => (string) $this->first_not_empty($shipment, array('fullAddress', 'address1', 'address'), ''),
            'shipping_city' => (string) $this->first_not_empty($shipment, array('city'), ''),
            'shipping_postcode' => (string) $this->first_not_empty($shipment, array('postalCode'), ''),
            'shipping_country' => (string) $this->first_not_empty($shipment, array('countryCode', 'country'), 'TR'),
            'line_items' => $line_items,
        );
    }

    public function map_question($raw_item)
    {
        $raw = is_array($raw_item) ? $raw_item : (array) $raw_item;
        $item = $raw;
        if (isset($raw['question']) && (is_array($raw['question']) || is_object($raw['question']))) {
            $question_payload = is_array($raw['question']) ? $raw['question'] : (array) $raw['question'];
            // Use nested question payload as canonical source when present.
            $item = array_merge($raw, $question_payload);
        }

        $question_id = trim((string) $this->first_not_empty($item, array('id', 'questionId', 'questionID', 'questionNumber'), ''));

        $question_text = (string) $this->first_not_empty($item, array('text', 'question', 'questionText', 'content'), '');
        $status = (string) $this->first_not_empty($item, array('status', 'questionStatus'), '');

        $answer = array();
        $raw_answer = $this->first_not_empty($item, array('answer', 'latestAnswer'), array());
        if (is_array($raw_answer)) {
            $answer = $raw_answer;
        } elseif (is_object($raw_answer)) {
            $answer = (array) $raw_answer;
        }

        $answer_text = (string) $this->first_not_empty(
            $item,
            array('answerText'),
            (string) $this->first_not_empty($answer, array('text', 'answerText', 'content'), '')
        );

        $asked_at = $this->normalize_datetime_value($this->first_not_empty(
            $item,
            array('createdDate', 'creationDate', 'createdAt', 'questionDate')
        ));
        $answered_at = $this->normalize_datetime_value($this->first_not_empty(
            $answer,
            array('answeredDate', 'answeredAt', 'createdDate', 'creationDate')
        ));

        $is_synthetic_id = false;
        if ($question_id === '') {
            $question_id = $this->build_synthetic_question_id($item, $question_text, $asked_at);
            $is_synthetic_id = true;
            if (function_exists('multi_sync_debug_log')) {
                multi_sync_debug_log(sprintf(
                    'Trendyol map_question: synthetic question id generated (%s).',
                    $question_id
                ));
            }
        }

        $can_reply = $is_synthetic_id ? false : $this->resolve_question_can_reply($item, $status, $answer_text);

        return array(
            'external_question_id' => $question_id,
            'external_product_id' => (string) $this->first_not_empty($item, array('productId', 'productCode', 'barcode'), ''),
            'product_name' => (string) $this->first_not_empty($item, array('productName', 'itemName', 'productTitle', 'productNameOrTitle'), ''),
            'customer_name' => (string) $this->first_not_empty($item, array('customerName', 'userName', 'username', 'user'), ''),
            'question_text' => $question_text,
            'answer_text' => $answer_text,
            'status' => $status !== '' ? $status : ($answer_text !== '' ? 'ANSWERED' : 'WAITING_FOR_ANSWER'),
            'can_reply' => $can_reply,
            'asked_at' => $asked_at,
            'answered_at' => $answered_at,
            'raw_payload' => $raw,
        );
    }

    public function reply_to_question($supplier, $external_question_id, $answer_text, $question = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $external_question_id = trim((string) $external_question_id);
        if ($external_question_id === '') {
            return new \WP_Error('multi_sync_invalid_question_id', 'Soru kimligi bos olamaz.');
        }

        $seller_id = rawurlencode($this->get_seller_id($supplier));
        $question_id = rawurlencode($external_question_id);
        $url = sprintf(
            'https://apigw.trendyol.com/integration/sellers/%s/questions/%s/answers',
            $seller_id,
            $question_id
        );

        $payload = array(
            'text' => (string) $answer_text,
        );

        $response = $this->request_json('POST', $url, $supplier, $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        $status = (string) $this->first_not_empty($data, array('status', 'questionStatus'), 'ANSWERED');
        $answered_at = $this->normalize_datetime_value($this->first_not_empty(
            $data,
            array('answeredDate', 'answeredAt', 'createdDate', 'creationDate')
        ));
        if ($answered_at === null) {
            $answered_at = current_time('mysql');
        }

        return array(
            'external_question_id' => $external_question_id,
            'answer_text' => (string) $answer_text,
            'status' => $status,
            'answered_at' => $answered_at,
            'raw_response' => $data,
        );
    }

    public function build_price_inventory_item_from_product($product, $sync_stock = true, $sync_price = true, $commission_rate = null)
    {
        if (!$product || !is_callable(array($product, 'get_sku'))) {
            return null;
        }

        $barcode = is_callable(array($product, 'get_meta'))
            ? trim((string) $product->get_meta('_multi_sync_external_barcode', true))
            : '';
        if ($barcode === '') {
            $barcode = trim((string) $product->get_sku());
        }
        if ($barcode === '') {
            return null;
        }

        $item = array(
            'barcode' => $barcode,
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
            $sale = is_numeric($sale_raw) && (float) $sale_raw > 0 && (float) $sale_raw < $regular_raw
                ? $this->apply_product_commission((float) $sale_raw, $product, $commission_rate)
                : $regular;
            $item['salePrice'] = $sale;
            $item['listPrice'] = $regular > 0 ? $regular : $sale;
        }

        return $item;
    }

    public function fetch_product_categories($supplier, $search = '')
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $url = 'https://apigw.trendyol.com/integration/product/product-categories';
        if (trim((string) $search) !== '') {
            $url .= '?' . http_build_query(array('name' => trim((string) $search)));
        }
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        $categories = isset($response['data']['categories']) && is_array($response['data']['categories'])
            ? $response['data']['categories']
            : array();
        $result = array();
        $flatten = function ($nodes, $parents = array()) use (&$flatten, &$result) {
            foreach ((array) $nodes as $node) {
                $node = is_array($node) ? $node : (array) $node;
                $name = trim((string) ($node['name'] ?? ''));
                $path = array_merge($parents, $name !== '' ? array($name) : array());
                $children = isset($node['subCategories']) && is_array($node['subCategories']) ? $node['subCategories'] : array();
                if ($children) {
                    $flatten($children, $path);
                } elseif (!empty($node['id'])) {
                    $result[] = array('id' => (int) $node['id'], 'name' => $name, 'path' => implode(' > ', $path));
                }
            }
        };
        $flatten($categories);
        return $result;
    }

    public function fetch_category_commission_rates($supplier)
    {
        $end = time() * 1000;
        $orders = $this->fetch_orders($supplier, array(
            'startDate' => $end - (14 * DAY_IN_SECONDS * 1000),
            'endDate' => $end,
            'page' => 0,
            'size' => 200,
            'orderByField' => 'PackageLastModifiedDate',
            'orderByDirection' => 'DESC',
        ));
        if (is_wp_error($orders)) return $orders;

        $rates = array();
        // ponytail: newest 200 orders are enough for preview; add cursor history only if sparse categories become common.
        foreach ($orders as $order) {
            $order = is_array($order) ? $order : (array) $order;
            foreach ((array) ($order['lines'] ?? $order['orderLines'] ?? array()) as $line) {
                $line = is_array($line) ? $line : (array) $line;
                $category_id = (string) ($line['productCategoryId'] ?? '');
                $rate = $line['commission'] ?? null;
                if ($category_id !== '' && !isset($rates[$category_id]) && is_numeric($rate) && (float) $rate > 0 && (float) $rate < 100) {
                    $rates[$category_id] = (float) $rate;
                }
            }
        }
        return $rates;
    }

    public function fetch_product_brands($supplier, $search = '', $category_id = '')
    {
        $query = trim((string) $search);
        $url = 'https://apigw.trendyol.com/integration/product/brands/by-name?' . http_build_query(array('name' => $query));
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) return $response;
        $rows = $this->extract_list($response['data'], array('brands', 'content', 'items'));
        if (!$rows && isset($response['data']['id'])) $rows = array($response['data']);
        $result = array();
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if (!empty($row['id'])) $result[] = array('id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? ''));
        }
        return $result;
    }

    public function fetch_category_attributes($supplier, $category_id)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $url = sprintf(
            'https://apigw.trendyol.com/integration/product/categories/%d/attributes',
            (int) $category_id
        );
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        $rows = isset($response['data']['categoryAttributes']) && is_array($response['data']['categoryAttributes'])
            ? $response['data']['categoryAttributes']
            : array();
        $result = array();
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $attribute = isset($row['attribute']) && is_array($row['attribute']) ? $row['attribute'] : array();
            $attribute_id = isset($attribute['id']) ? (int) $attribute['id'] : 0;
            if ($attribute_id <= 0) {
                continue;
            }
            if (empty($row['required']) && empty($row['slicer']) && empty($row['varianter'])) {
                continue;
            }
            $values_url = sprintf(
                'https://apigw.trendyol.com/integration/product/categories/%d/attributes/%d/values?size=1000&page=0',
                (int) $category_id,
                $attribute_id
            );
            $values_response = $this->request_json('GET', $values_url, $supplier);
            if (is_wp_error($values_response)) {
                return $values_response;
            }
            $values = array();
            foreach ($this->extract_list($values_response['data'], array('content')) as $value) {
                $value = is_array($value) ? $value : (array) $value;
                if (!empty($value['attributeValueId'])) {
                    $values[] = array('id' => (int) $value['attributeValueId'], 'name' => (string) ($value['attributeValue'] ?? ''));
                }
            }
            $result[] = array(
                'id' => $attribute_id,
                'name' => (string) ($attribute['name'] ?? ''),
                'required' => !empty($row['required']),
                'slicer' => !empty($row['slicer']),
                'varianter' => !empty($row['varianter']),
                'allow_custom' => !empty($row['allowCustom']),
                'allow_multiple' => !empty($row['allowMultipleAttributeValues']),
                'values' => $values,
            );
        }
        return $result;
    }

    public function build_product_item_from_product($product, $category_mapping = array(), $overrides = array())
    {
        if (!$product || !is_callable(array($product, 'get_sku')) || (!$product->is_type('simple') && !$product->is_type('variation'))) {
            return new \WP_Error('multi_sync_trendyol_unsupported_product', 'Yalnizca basit urunler ve varyasyonlar gonderilebilir.');
        }

        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;
        $meta = function ($key) use ($product, $parent) {
            $value = trim((string) $product->get_meta('_multi_sync_trendyol_' . $key, true));
            return $value !== '' || !$parent ? $value : trim((string) $parent->get_meta('_multi_sync_trendyol_' . $key, true));
        };
        $override = function ($key, $fallback = '') use ($overrides) {
            return isset($overrides[$key]) && trim((string) $overrides[$key]) !== '' ? trim((string) $overrides[$key]) : $fallback;
        };
        $sku = $override('sku', trim((string) $product->get_sku()));
        $barcode = $override('barcode', $meta('barcode') ?: $sku);
        $parent_sku = $parent ? trim((string) $parent->get_sku()) : '';
        $product_main_id = $override('product_main_id', $meta('product_main_id') ?: ($parent_sku ?: $sku));
        $brand_id = (int) $override('brand_id', $category_mapping['brand_id'] ?? $meta('brand_id'));
        $category_id = (int) $override('category_id', $meta('category_id') ?: ($category_mapping['category_id'] ?? 0));
        $dimensional_weight = (float) $meta('dimensional_weight');
        $vat_rate = $override('vat_rate', $this->get_product_vat_rate($product));
        $attributes_raw = $meta('attributes');
        $attributes = $attributes_raw === ''
            ? (isset($category_mapping['attributes']) && is_array($category_mapping['attributes']) ? $category_mapping['attributes'] : array())
            : json_decode($attributes_raw, true);
        $attributes = is_array($attributes) ? $attributes : array();
        $attributes_by_id = array();
        foreach ($attributes as $attribute) {
            if (is_array($attribute) && !empty($attribute['attributeId'])) {
                $attribute['attributeId'] = (int) $attribute['attributeId'];
                if (!empty($attribute['attributeValueIds']) && is_array($attribute['attributeValueIds'])) {
                    $attribute['attributeValueIds'] = array_map('intval', $attribute['attributeValueIds']);
                }
                $attributes_by_id[(int) $attribute['attributeId']] = $attribute;
            }
        }

        $missing = array();
        foreach (array(
            'sku' => array('SKU / Stok Kodu', $sku === '' || mb_strlen($sku) > 100, 'text'),
            'barcode' => array('Barkod', $barcode === '' || mb_strlen($barcode) > 40 || !preg_match('/^[\p{L}\p{N}._-]+$/u', $barcode), 'text'),
            'product_main_id' => array('Model Kodu', $product_main_id === '' || mb_strlen($product_main_id) > 40, 'text'),
            'brand_id' => array('Trendyol Marka ID', $brand_id <= 0, 'number'),
            'category_id' => array('Trendyol Kategori ID', $category_id <= 0, 'number'),
        ) as $key => $field) {
            if ($field[1]) {
                $missing[] = array('key' => $key, 'label' => $field[0], 'type' => $field[2], 'options' => array());
            }
        }
        if (!in_array($vat_rate, array('0', '1', '10', '20'), true)) {
            $missing[] = array('key' => 'vat_rate', 'label' => 'KDV Orani', 'type' => 'select', 'options' => array(
                array('id' => '0', 'name' => '%0'), array('id' => '1', 'name' => '%1'),
                array('id' => '10', 'name' => '%10'), array('id' => '20', 'name' => '%20'),
            ));
        }

        $selected_variation_attribute = $override('variation_attribute', '');
        $selected_variation_target = (int) $override('variation_target_attribute_id', '0');
        $variation_value = $this->get_variation_value($product, $parent, $selected_variation_attribute);
        if ($product->is_type('variation') && $selected_variation_attribute === '') {
            $missing[] = array('key' => 'variation_attribute', 'label' => 'WooCommerce kaynak alanı', 'type' => 'select', 'options' => array_map(static function ($name) use ($parent) {
                return array('id' => $name, 'name' => function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name);
            }, array_keys((array) $product->get_attributes())));
        }
        if ($product->is_type('variation') && $selected_variation_target <= 0) {
            $missing[] = array('key' => 'variation_target_attribute_id', 'label' => 'Trendyol hedef niteliği', 'type' => 'select', 'options' => array_values(array_map(static function ($definition) {
                return array('id' => (string) ($definition['id'] ?? ''), 'name' => (string) ($definition['name'] ?? ''));
            }, array_filter((array) ($category_mapping['attribute_definitions'] ?? array()), static function ($definition) {
                return is_array($definition) && !empty($definition['id']) && (!empty($definition['slicer']) || !empty($definition['varianter']));
            }))));
        }
        foreach ((array) ($category_mapping['attribute_definitions'] ?? array()) as $definition) {
            if (!is_array($definition) || empty($definition['id'])) {
                continue;
            }
            $attribute_id = (int) $definition['id'];
            $input = $override('attribute_' . $attribute_id, '');
            $is_variation_target = $selected_variation_target === $attribute_id;
            if ($input === '' && $is_variation_target && $variation_value !== '') {
                foreach ((array) ($definition['values'] ?? array()) as $value) {
                    if ($this->normalize_color((string) ($value['name'] ?? '')) === $this->normalize_color($variation_value)) {
                        $input = (string) $value['id'];
                        break;
                    }
                }
                if ($input === '' && !empty($definition['allow_custom'])) {
                    $input = $variation_value;
                }
            }
            if ($input !== '') {
                $valid_ids = array_map('intval', array_column((array) ($definition['values'] ?? array()), 'id'));
                if (is_numeric($input) && in_array((int) $input, $valid_ids, true)) {
                    $attributes_by_id[$attribute_id] = array('attributeId' => $attribute_id, 'attributeValueIds' => array((int) $input));
                } elseif (!empty($definition['allow_custom']) && !is_numeric($input)) {
                    $attributes_by_id[$attribute_id] = array('attributeId' => $attribute_id, 'attributeValue' => sanitize_text_field($input));
                }
            }
            $needs_value = !empty($definition['required']) || $is_variation_target;
            if ($needs_value && !isset($attributes_by_id[$attribute_id])) {
                $missing[] = array(
                    'key' => 'attribute_' . $attribute_id,
                    'label' => (string) ($definition['name'] ?? ('Nitelik ' . $attribute_id)),
                    'type' => !empty($definition['values']) ? 'select' : 'text',
                    'options' => array_values((array) ($definition['values'] ?? array())),
                    'suggested_value' => $is_variation_target ? $variation_value : '',
                );
            }
        }

        $price = $this->build_price_inventory_item_from_product($product, true, true, $category_mapping['commission_rate'] ?? null);
        if (!$price || $price['listPrice'] <= 0 || $price['salePrice'] <= 0) {
            return new \WP_Error('multi_sync_trendyol_product_price', 'Urun fiyati sifirdan buyuk olmali.');
        }

        $image_ids = array($product->get_image_id());
        if ($parent) {
            $image_ids = array_merge($image_ids, array($parent->get_image_id()), $parent->get_gallery_image_ids());
        } else {
            $image_ids = array_merge($image_ids, $product->get_gallery_image_ids());
        }
        $images = array();
        foreach (array_unique(array_filter($image_ids)) as $image_id) {
            $url = wp_get_attachment_url($image_id);
            if (is_string($url) && preg_match('#^https?://#i', $url)) {
                $images[] = array('url' => $url);
            }
            if (count($images) === 8) {
                break;
            }
        }
        $override_image = $override('image_url', '');
        if ($override_image !== '' && preg_match('#^https?://#i', $override_image)) {
            array_unshift($images, array('url' => esc_url_raw($override_image)));
        }
        if (empty($images)) {
            $missing[] = array('key' => 'image_url', 'label' => 'Gorsel URL', 'type' => 'text', 'options' => array());
        }
        if ($missing) {
            return new \WP_Error('multi_sync_trendyol_product_incomplete', 'Eksik Trendyol bilgilerini doldurun.', array('fields' => $missing));
        }

        $description = (string) $product->get_description();
        if ($description === '' && $parent) {
            $description = (string) $parent->get_description();
        }
        if ($description === '') {
            $description = (string) $product->get_short_description();
        }
        if ($description === '') {
            $description = (string) $product->get_name();
        }

        $item = array(
            'barcode' => $barcode,
            'title' => mb_substr($this->product_export_name($product, $parent), 0, 100),
            'productMainId' => $product_main_id,
            'brandId' => $brand_id,
            'categoryId' => $category_id,
            'quantity' => $price['quantity'],
            'stockCode' => $sku,
            'description' => mb_substr($description, 0, 30000),
            'listPrice' => $price['listPrice'],
            'salePrice' => $price['salePrice'],
            'vatRate' => (int) $vat_rate,
            'images' => $images,
            'attributes' => array_values($attributes_by_id),
        );
        if ($dimensional_weight > 0) {
            $item['dimensionalWeight'] = $dimensional_weight;
        }
        return $item;
    }

    private function get_variation_value($product, $parent, $selected_attribute)
    {
        if (!$product->is_type('variation')) {
            return '';
        }
        $attributes = $product->get_attributes();
        foreach ($attributes as $name => $value) {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name;
            if ($selected_attribute !== $name && $selected_attribute !== $label) {
                continue;
            }
            if (taxonomy_exists($name)) {
                $term = get_term_by('slug', $value, $name);
                if ($term && !is_wp_error($term)) {
                    return (string) $term->name;
                }
            }
            return mb_convert_case(str_replace('-', ' ', (string) $value), MB_CASE_TITLE, 'UTF-8');
        }
        return '';
    }

    private function normalize_color($value)
    {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($value, 'UTF-8'));
        $value = trim($value);
        if (strpos($value, 'osmanli') !== false) {
            return 'osmanli';
        }
        if (strpos($value, 'turk bayrak') !== false) {
            return 'turk bayrak';
        }
        return in_array($value, array('renk', 'color', 'web color'), true) ? 'renk' : $value;
    }

    public function push_products($supplier, $items)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $url = sprintf(
            'https://apigw.trendyol.com/integration/product/sellers/%s/v2/products',
            rawurlencode($this->get_seller_id($supplier))
        );
        $response = $this->request_json('POST', $url, $supplier, array('items' => array_values($items)));
        return is_wp_error($response) ? $response : $response['data'];
    }

    public function push_price_inventory_updates($supplier, $items)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $seller_id = rawurlencode($this->get_seller_id($supplier));
        $url = sprintf(
            'https://apigw.trendyol.com/integration/inventory/sellers/%s/products/price-and-inventory',
            $seller_id
        );

        $payload = array(
            'items' => array_values($items),
        );

        $response = $this->request_json('POST', $url, $supplier, $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        return $response['data'];
    }

    public function get_batch_request_result($supplier, $batch_request_id)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $seller_id = rawurlencode($this->get_seller_id($supplier));
        $batch_id = rawurlencode((string) $batch_request_id);
        $url = sprintf(
            'https://apigw.trendyol.com/integration/product/sellers/%s/products/batch-requests/%s',
            $seller_id,
            $batch_id
        );

        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        return $response['data'];
    }

    private function map_variation_attributes($attributes)
    {
        $mapped = array();
        if (!is_array($attributes)) {
            return $mapped;
        }
        foreach ($attributes as $attribute) {
            $attribute = is_array($attribute) ? $attribute : (array) $attribute;
            $name = trim((string) $this->first_not_empty($attribute, array('attributeName', 'name'), ''));
            $value = trim((string) $this->first_not_empty($attribute, array('attributeValue', 'value', 'attributeValueName'), ''));
            if ($name !== '' && $value !== '') {
                $mapped[$name] = $value;
            }
        }
        return $mapped;
    }

    private function resolve_question_can_reply($item, $status, $answer_text)
    {
        if (isset($item['canBeAnswered'])) {
            return (bool) $item['canBeAnswered'];
        }
        if (isset($item['canReply'])) {
            return (bool) $item['canReply'];
        }
        if (isset($item['hasAnswer']) && (bool) $item['hasAnswer']) {
            return false;
        }

        $normalized_status = strtolower(str_replace(array('-', ' '), '_', (string) $status));
        if (in_array($normalized_status, array('waiting_for_answer', 'unanswered', 'new', 'pending'), true)) {
            return trim((string) $answer_text) === '';
        }

        if (trim((string) $answer_text) !== '') {
            return false;
        }

        return $normalized_status === '';
    }

    private function execute_questions_query($supplier, $query_args, $requested_page, $size)
    {
        $url = sprintf(
            'https://apigw.trendyol.com/integration/sellers/%s/questions?%s',
            rawurlencode($this->get_seller_id($supplier)),
            http_build_query($query_args)
        );

        $response = $this->request_json_with_retry(
            'GET',
            $url,
            $supplier,
            null,
            self::QUESTION_REQUEST_MAX_ATTEMPTS
        );
        if (is_wp_error($response)) {
            $status_code = $this->extract_http_status_code_from_error($response);
            if ($this->is_transient_http_status_code($status_code)) {
                return new \WP_Error(
                    'multi_sync_marketplace_temporarily_unavailable',
                    sprintf(
                        'Trendyol soru servisi gecici olarak ulasilamiyor (%d). Lutfen kisa sure sonra tekrar deneyin.',
                        $status_code
                    ),
                    $response->get_error_data()
                );
            }
            return $response;
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        return $this->normalize_questions_response($data, (int) $requested_page, (int) $size);
    }

    private function normalize_questions_response($data, $requested_page, $size)
    {
        $data = is_array($data) ? $data : array();

        $sources = array($data);
        if (isset($data['questions']) && (is_array($data['questions']) || is_object($data['questions']))) {
            $sources[] = is_array($data['questions']) ? $data['questions'] : (array) $data['questions'];
        }
        if (isset($data['data']) && (is_array($data['data']) || is_object($data['data']))) {
            $sources[] = is_array($data['data']) ? $data['data'] : (array) $data['data'];
        }

        $items = array();
        $current_page = $requested_page;
        $total_pages = null;

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $candidate_items = $this->extract_questions_items_from_source($source);
            if (!empty($candidate_items)) {
                $items = $candidate_items;
            }

            $source_page = $this->to_int($this->first_not_empty($source, array('page', 'number', 'currentPage')), null);
            if ($source_page !== null) {
                $current_page = $source_page;
            }

            $source_total_pages = $this->to_int($this->first_not_empty($source, array('totalPages', 'pageCount')), null);
            if ($source_total_pages !== null) {
                $total_pages = $source_total_pages;
            }
        }

        $has_next = false;
        if ($total_pages !== null) {
            $has_next = ($current_page + 1) < $total_pages;
        } elseif (count($items) >= $size) {
            $has_next = true;
        }

        return array(
            'items' => $items,
            'page' => $current_page,
            'size' => $size,
            'total_pages' => $total_pages,
            'has_next' => $has_next,
            'next_page' => $current_page + 1,
        );
    }

    private function request_json_with_retry($method, $url, $supplier, $body = null, $max_attempts = self::QUESTION_REQUEST_MAX_ATTEMPTS)
    {
        $max_attempts = max(1, (int) $max_attempts);

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = $this->request_json($method, $url, $supplier, $body);
            if (!is_wp_error($response)) {
                return $response;
            }

            $status_code = $this->extract_http_status_code_from_error($response);
            if (!$this->is_transient_http_status_code($status_code) || $attempt >= $max_attempts) {
                return $response;
            }

            if (function_exists('multi_sync_debug_log')) {
                multi_sync_debug_log(sprintf(
                    'Trendyol questions transient error (%d), retry %d/%d.',
                    $status_code,
                    $attempt + 1,
                    $max_attempts
                ));
            }

            $delay_ms = $this->get_retry_delay_ms($attempt);
            if ($delay_ms > 0 && function_exists('usleep')) {
                usleep((int) $delay_ms * 1000);
            }
        }

        return new \WP_Error('multi_sync_marketplace_request_failed', 'Pazar yeri istegi basarisiz oldu.');
    }

    private function extract_http_status_code_from_error($error)
    {
        if (!is_wp_error($error)) {
            return 0;
        }

        $data = $error->get_error_data();
        if (is_array($data)) {
            if (isset($data['code']) && is_numeric($data['code'])) {
                return (int) $data['code'];
            }
            if (isset($data['status']) && is_numeric($data['status'])) {
                return (int) $data['status'];
            }
        }

        $message = (string) $error->get_error_message();
        if (preg_match('/\((\d{3})\)/', $message, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function is_transient_http_status_code($status_code)
    {
        return in_array((int) $status_code, self::TRANSIENT_HTTP_STATUS_CODES, true);
    }

    private function get_retry_delay_ms($attempt)
    {
        $attempt = max(1, (int) $attempt);
        $delay = 500 * (1 << ($attempt - 1));
        return min(3000, $delay);
    }

    private function extract_questions_items_from_source($source)
    {
        if (!is_array($source)) {
            return array();
        }

        $candidate = $this->extract_list($source, array('content', 'questions', 'items', 'data', 'result'));
        if ($this->is_list_array($candidate)) {
            return $candidate;
        }

        if (is_array($candidate) && !empty($candidate)) {
            $nested = $this->extract_list($candidate, array('content', 'questions', 'items', 'data', 'result'));
            if ($this->is_list_array($nested)) {
                return $nested;
            }
        }

        if ($this->is_list_array($source)) {
            return $source;
        }

        return array();
    }

    private function is_list_array($value)
    {
        if (!is_array($value)) {
            return false;
        }

        if ($value === array()) {
            return true;
        }

        $expected_index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected_index) {
                return false;
            }
            $expected_index++;
        }

        return true;
    }

    private function build_synthetic_question_id($item, $question_text, $asked_at)
    {
        $parts = array(
            (string) $this->first_not_empty($item, array('productId', 'productCode', 'barcode', 'sku'), ''),
            (string) $this->first_not_empty($item, array('customerName', 'userName', 'username', 'user'), ''),
            (string) $question_text,
            (string) $asked_at,
        );
        $seed = implode('|', $parts);
        if (trim($seed) === '' && is_array($item)) {
            $seed = wp_json_encode($item);
        }

        return 'synthetic_' . substr(sha1((string) $seed), 0, 24);
    }

    private function normalize_datetime_value($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $timestamp = (float) $raw;
            if ($timestamp > 9999999999) {
                $timestamp = $timestamp / 1000;
            }
            if ($timestamp <= 0) {
                return null;
            }

            $timestamp = (int) round($timestamp);
            if (function_exists('wp_date')) {
                return wp_date('Y-m-d H:i:s', $timestamp);
            }

            return date('Y-m-d H:i:s', $timestamp);
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', $timestamp);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
