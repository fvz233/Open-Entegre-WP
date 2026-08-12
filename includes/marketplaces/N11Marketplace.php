<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class N11Marketplace extends BaseMarketplace
{
    const INTEGRATOR_NAME = 'MultiSync';

    public function get_key()
    {
        return 'n11';
    }

    public function get_label()
    {
        return 'n11';
    }

    public function validate_credentials($supplier)
    {
        $api_key = $this->get_api_key($supplier);
        $api_secret = $this->get_api_secret($supplier);

        if ($api_key === '' || $api_secret === '') {
            return new \WP_Error(
                'multi_sync_missing_credentials',
                'Eksik yetki bilgisi: App Key veya App Secret.'
            );
        }

        return true;
    }

    protected function build_default_headers($supplier)
    {
        return array(
            'appkey' => $this->get_api_key($supplier),
            'appsecret' => $this->get_api_secret($supplier),
            'Content-Type' => 'application/json',
        );
    }

    public function fetch_products($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $query_args = array(
            'page' => isset($params['page']) ? max(0, (int) $params['page']) : 0,
            'size' => isset($params['size']) ? max(1, min(250, (int) $params['size'])) : 250,
        );

        $optional_keys = array(
            'id',
            'productMainId',
            'stockCode',
            'saleStatus',
            'productStatus',
            'brandName',
            'categoryIds',
        );
        foreach ($optional_keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query_args[$key] = $params[$key];
            }
        }

        $url = 'https://api.n11.com/ms/product-query?' . http_build_query($query_args);

        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        return $this->extract_list($response['data'], array('content', 'items', 'data'));
    }

    public function fetch_orders($supplier, $params = array())
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $query_args = array(
            'page' => isset($params['page']) ? max(0, (int) $params['page']) : 0,
            'size' => isset($params['size']) ? max(1, min(100, (int) $params['size'])) : 100,
        );

        $optional_keys = array(
            'startDate',
            'endDate',
            'orderNumber',
            'packageIds',
            'status',
            'orderByField',
            'orderByDirection',
        );
        foreach ($optional_keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query_args[$key] = $params[$key];
            }
        }

        $url = 'https://api.n11.com/rest/delivery/v1/shipmentPackages?' . http_build_query($query_args);
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) {
            return $response;
        }

        $items = $this->extract_list($response['data'], array('content', 'items', 'data'));
        return $this->merge_packages_by_order_number($items);
    }

    public function map_product($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;

        $sku = trim((string) $this->first_not_empty($item, array('stockCode'), ''));
        if ($sku === '') {
            $title = (string) $this->first_not_empty($item, array('title', 'name'), 'unknown');
            return new \WP_Error(
                'multi_sync_n11_missing_stock_code',
                sprintf('n11 urununde stockCode zorunlu. Urun: %s', $title)
            );
        }

        $images = array();
        $image_urls = $this->first_not_empty($item, array('imageUrls', 'images'), array());
        if (is_array($image_urls)) {
            foreach ($image_urls as $image_item) {
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
            'sku' => $sku,
            'name' => (string) $this->first_not_empty($item, array('title', 'name'), $sku),
            'regular_price' => $this->to_float($this->first_not_empty($item, array('listPrice', 'salePrice')), null),
            'sale_price' => $this->to_float($this->first_not_empty($item, array('salePrice')), null),
            'stock_quantity' => $this->to_int($this->first_not_empty($item, array('quantity')), 0),
            'images' => $images,
            'preview_image' => !empty($images) ? $images[0] : '',
            'external_sku' => $sku,
            'external_barcode' => (string) $this->first_not_empty($item, array('barcode'), ''),
            'external_product_id' => (string) $this->first_not_empty($item, array('n11ProductId', 'id', 'productId'), ''),
            'parent_key' => (string) $this->first_not_empty($item, array('productMainId'), ''),
            'variation_attributes' => $this->map_variation_attributes($this->first_not_empty($item, array('attributes'), array())),
        );
    }

    public function map_order($raw_item)
    {
        $item = is_array($raw_item) ? $raw_item : (array) $raw_item;
        $shipping = isset($item['shippingAddress']) && is_array($item['shippingAddress'])
            ? $item['shippingAddress']
            : array();
        $billing = isset($item['billingAddress']) && is_array($item['billingAddress'])
            ? $item['billingAddress']
            : array();

        $shipping_name = $this->split_full_name($this->first_not_empty($shipping, array('fullName', 'name'), ''));
        $billing_name = $this->split_full_name($this->first_not_empty($billing, array('fullName', 'name'), ''));

        $raw_lines = $this->first_not_empty($item, array('lines', 'items', 'lineItems'), array());
        $line_items = array();
        $fallback_total = 0.0;

        if (is_array($raw_lines)) {
            foreach ($raw_lines as $line) {
                $line = is_array($line) ? $line : (array) $line;
                $quantity = (int) $this->to_int($this->first_not_empty($line, array('quantity')), 1);
                $price = (float) $this->to_float(
                    $this->first_not_empty($line, array('price', 'sellerDiscountedPrice', 'dueAmount')),
                    0
                );

                $line_items[] = array(
                    'sku' => (string) $this->first_not_empty($line, array('stockCode', 'sku'), ''),
                    'name' => (string) $this->first_not_empty($line, array('productName', 'name'), ''),
                    'quantity' => $quantity > 0 ? $quantity : 1,
                    'price' => $price,
                );

                $fallback_total += ($price * ($quantity > 0 ? $quantity : 1));
            }
        }

        $total = $this->to_float($this->first_not_empty($item, array('mergedTotalAmount', 'totalAmount')), null);
        if ($total === null) {
            $total = $fallback_total;
        }

        $external_id = (string) $this->first_not_empty($item, array('orderNumber'), '');
        if ($external_id === '') {
            $external_id = (string) $this->first_not_empty($item, array('id'), '');
        }

        $email = (string) $this->first_not_empty($item, array('customerEmail', 'email'), '');

        return array(
            'external_id' => $external_id,
            'status' => (string) $this->first_not_empty($item, array('shipmentPackageStatus', 'status'), 'Created'),
            'currency' => (string) $this->first_not_empty($item, array('currencyType', 'currency'), 'TRY'),
            'total' => (float) $total,
            'order_date' => $this->first_not_empty($item, array('lastModifiedDate', 'agreedDeliveryDate'), ''),
            'billing_first_name' => $billing_name[0],
            'billing_last_name' => $billing_name[1],
            'billing_phone' => (string) $this->first_not_empty($billing, array('gsm', 'phone'), ''),
            'billing_email' => $email,
            'billing_address_1' => (string) $this->first_not_empty($billing, array('address', 'fullAddress', 'address1'), ''),
            'billing_city' => (string) $this->first_not_empty($billing, array('city'), ''),
            'billing_postcode' => (string) $this->first_not_empty($billing, array('postalCode'), ''),
            'billing_country' => 'TR',
            'shipping_first_name' => $shipping_name[0],
            'shipping_last_name' => $shipping_name[1],
            'shipping_phone' => (string) $this->first_not_empty($shipping, array('gsm', 'phone'), ''),
            'shipping_address_1' => (string) $this->first_not_empty($shipping, array('address', 'fullAddress', 'address1'), ''),
            'shipping_city' => (string) $this->first_not_empty($shipping, array('city'), ''),
            'shipping_postcode' => (string) $this->first_not_empty($shipping, array('postalCode'), ''),
            'shipping_country' => 'TR',
            'line_items' => $line_items,
        );
    }

    public function build_price_inventory_item_from_product($product, $sync_stock = true, $sync_price = true, $commission_rate = null)
    {
        if (!$product || !is_callable(array($product, 'get_sku'))) {
            return null;
        }

        $sku = is_callable(array($product, 'get_meta'))
            ? trim((string) $product->get_meta('_multi_sync_external_sku', true))
            : '';
        if ($sku === '') {
            $sku = trim((string) $product->get_sku());
        }
        if ($sku === '') {
            return null;
        }

        $item = array(
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
            $sale = is_numeric($sale_raw) && (float) $sale_raw > 0 && (float) $sale_raw < $regular_raw
                ? $this->apply_product_commission((float) $sale_raw, $product, $commission_rate)
                : $regular;
            $item['listPrice'] = $regular > 0 ? $regular : $sale;
            $item['salePrice'] = $sale;
            $item['currencyType'] = 'TL';
        }

        return $item;
    }

    public function fetch_product_categories($supplier, $search = '')
    {
        $response = $this->request_json('GET', 'https://api.n11.com/cdn/categories', $supplier);
        if (is_wp_error($response)) {
            return $response;
        }
        $result = array();
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
        $walk = function ($nodes, $parents = array()) use (&$walk, &$result, $needle) {
            foreach ((array) $nodes as $node) {
                $node = is_array($node) ? $node : (array) $node;
                $name = trim((string) ($node['name'] ?? ''));
                $path = array_merge($parents, $name !== '' ? array($name) : array());
                $children = $node['subCategories'] ?? array();
                if (is_array($children) && $children) {
                    $walk($children, $path);
                } elseif (!empty($node['id']) && ($needle === '' || mb_strpos(mb_strtolower(implode(' > ', $path), 'UTF-8'), $needle) !== false)) {
                    $result[] = array('id' => (string) $node['id'], 'name' => $name, 'path' => implode(' > ', $path));
                }
            }
        };
        $data = is_array($response['data']) ? $response['data'] : array();
        $walk($data['categories'] ?? $data);
        return $result;
    }

    public function fetch_category_attributes($supplier, $category_id)
    {
        $response = $this->request_json('GET', 'https://api.n11.com/cdn/category/' . rawurlencode((string) $category_id) . '/attribute', $supplier);
        if (is_wp_error($response)) {
            return $response;
        }
        $rows = $response['data']['categoryAttributes'] ?? array();
        $result = array();
        foreach ((array) $rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if (empty($row['attributeId']) || (empty($row['isMandatory']) && empty($row['isVariant']) && empty($row['isSlicer']))) {
                continue;
            }
            $values = array();
            foreach ((array) ($row['attributeValues'] ?? array()) as $value) {
                $value = is_array($value) ? $value : (array) $value;
                if (isset($value['id'])) {
                    $values[] = array('id' => (string) $value['id'], 'name' => (string) ($value['value'] ?? ''));
                }
            }
            $result[] = array(
                'id' => (string) $row['attributeId'],
                'name' => (string) ($row['attributeName'] ?? ''),
                'required' => !empty($row['isMandatory']),
                'slicer' => !empty($row['isSlicer']),
                'varianter' => !empty($row['isVariant']),
                'allow_custom' => !empty($row['isCustomValue']),
                'values' => $values,
            );
        }
        return $result;
    }

    public function fetch_product_brands($supplier, $search = '', $category_id = '')
    {
        if ((string) $category_id === '') return new \WP_Error('multi_sync_n11_brand_category_required', 'n11 marka aramasi icin once kategori secin.');
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
        $attributes = $this->fetch_category_attributes($supplier, $category_id);
        if (is_wp_error($attributes)) return $attributes;
        foreach ($attributes as $attribute) {
            if ($this->normalized_name($attribute['name'] ?? '') !== 'marka') continue;
            return array_values(array_filter((array) ($attribute['values'] ?? array()), function ($brand) use ($needle) {
                return $needle === '' || mb_strpos(mb_strtolower((string) ($brand['name'] ?? ''), 'UTF-8'), $needle) !== false;
            }));
        }
        return array();
    }

    public function build_product_item_from_product($product, $category_mapping = array(), $overrides = array())
    {
        $parent = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;
        if (!$product || (!$product->is_type('simple') && !$parent)) {
            return new \WP_Error('multi_sync_n11_unsupported_product', 'Yalnizca basit urunler ve varyasyonlar gonderilebilir.');
        }
        $value = function ($key, $fallback = '') use ($overrides, $product, $parent) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') {
                return trim((string) $overrides[$key]);
            }
            $stored = trim((string) $product->get_meta('_multi_sync_n11_' . $key, true));
            if ($stored === '' && $parent) {
                $stored = trim((string) $parent->get_meta('_multi_sync_n11_' . $key, true));
            }
            return $stored !== '' ? $stored : $fallback;
        };
        $sku = $value('sku', $product->get_sku());
        $barcode = $value('barcode') ?: null;
        $model = $value('product_main_id', $parent ? $parent->get_sku() : $sku);
        $category_id = $value('category_id', $category_mapping['category_id'] ?? '');
        $shipment = $value('shipment_template', $category_mapping['shipment_template'] ?? '');
        $vat = $this->get_product_vat_rate($product, $value('vat_rate'));
        $missing = array();
        foreach (array('sku' => array('SKU / Stok Kodu', $sku), 'product_main_id' => array('Model Kodu', $model), 'category_id' => array('n11 Kategori ID', $category_id), 'shipment_template' => array('n11 Kargo Sablonu', $shipment)) as $key => $field) {
            if ($field[1] === '') {
                $missing[] = array('key' => $key, 'label' => $field[0], 'type' => 'text', 'options' => array());
            }
        }
        if (!in_array($vat, array('0', '1', '10', '20'), true)) {
            $missing[] = array('key' => 'vat_rate', 'label' => 'KDV Orani', 'type' => 'select', 'options' => array_map(function ($rate) { return array('id' => $rate, 'name' => '%' . $rate); }, array('0', '1', '10', '20')));
        }
        $attributes = array();
        $mapped_attributes = array();
        foreach ((array) ($category_mapping['attributes'] ?? array()) as $mapped) {
            if (!empty($mapped['attributeId'])) $mapped_attributes[(string) $mapped['attributeId']] = (string) (($mapped['attributeValueIds'][0] ?? null) ?: ($mapped['attributeValue'] ?? ''));
        }
        $selected_variation_attribute = $value('variation_attribute');
        $selected_variation_target = (int) $value('variation_target_attribute_id');
        $variation_value = $this->variation_value($product, $parent, $selected_variation_attribute);
        if ($parent && $selected_variation_attribute === '') {
            $missing[] = array('key' => 'variation_attribute', 'label' => 'WooCommerce kaynak alani', 'type' => 'select', 'options' => array_map(function ($name) use ($parent) {
                return array('id' => $name, 'name' => function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name);
            }, array_keys((array) $product->get_attributes())));
        }
        if ($parent && $selected_variation_target <= 0) {
            $missing[] = array('key' => 'variation_target_attribute_id', 'label' => 'n11 hedef niteligi', 'type' => 'select', 'options' => array_values(array_map(function ($definition) {
                return array('id' => (string) ($definition['id'] ?? ''), 'name' => (string) ($definition['name'] ?? ''));
            }, array_filter((array) ($category_mapping['attribute_definitions'] ?? array()), function ($definition) {
                return is_array($definition) && !empty($definition['id']) && (!empty($definition['slicer']) || !empty($definition['varianter']));
            }))));
        }
        foreach ((array) ($category_mapping['attribute_definitions'] ?? array()) as $definition) {
            $id = (string) ($definition['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $input = $value('attribute_' . $id);
            if ($input === '') $input = $mapped_attributes[$id] ?? '';
            if ($input === '' && $this->normalized_name($definition['name'] ?? '') === 'marka') $input = (string) (($category_mapping['brand_id'] ?? '') ?: ($category_mapping['brand_name'] ?? ''));
            $is_variation_target = $selected_variation_target === (int) $id;
            if ($is_variation_target && $variation_value !== '' && empty($overrides['attribute_' . $id])) {
                $input = $variation_value;
            }
            $is_desi = $this->normalized_name($definition['name'] ?? '') === 'desi';
            if ($input === '' && $is_desi) {
                $input = $this->get_product_desi($product);
            }
            $selected = null;
            foreach ((array) ($definition['values'] ?? array()) as $option) {
                if ((string) ($option['id'] ?? '') === $input || $this->normalized_name($option['name'] ?? '') === $this->normalized_name($input)) {
                    $selected = (string) $option['id'];
                    break;
                }
            }
            if ($selected !== null) {
                $attributes[] = array('id' => (int) $id, 'valueId' => (int) $selected, 'customValue' => null);
            } elseif ($input !== '' && !empty($definition['allow_custom'])) {
                $attributes[] = array('id' => (int) $id, 'valueId' => null, 'customValue' => sanitize_text_field($input));
            } elseif (!empty($definition['required']) || $is_variation_target) {
                $missing[] = array('key' => 'attribute_' . $id, 'label' => (string) ($definition['name'] ?? $id), 'type' => !empty($definition['values']) ? 'select' : 'text', 'options' => (array) ($definition['values'] ?? array()), 'suggested_value' => $is_variation_target ? $variation_value : '');
            }
        }
        $price = $this->build_price_inventory_item_from_product($product, true, true, $category_mapping['commission_rate'] ?? null);
        if (!$price || $price['listPrice'] <= 0 || $price['salePrice'] <= 0) return new \WP_Error('multi_sync_n11_product_price', 'Urun fiyati sifirdan buyuk olmali.');
        $images = $this->product_images($product, $parent, $value('image_url'));
        if (!$images) {
            $missing[] = array('key' => 'image_url', 'label' => 'Gorsel URL', 'type' => 'text', 'options' => array());
        }
        if ($missing) {
            return new \WP_Error('multi_sync_n11_product_incomplete', 'Eksik n11 bilgilerini doldurun.', array('fields' => $missing));
        }
        $description = $product->get_description() ?: ($parent ? $parent->get_description() : '') ?: $product->get_short_description() ?: $product->get_name();
        return array(
            'title' => mb_substr($this->product_export_name($product, $parent), 0, 100), 'description' => $description,
            'categoryId' => (int) $category_id, 'currencyType' => 'TL', 'productMainId' => $model,
            'preparingDay' => max(1, (int) $value('preparing_day', 3)), 'shipmentTemplate' => $shipment,
            'stockCode' => $sku, 'barcode' => $barcode, 'quantity' => $price['quantity'],
            'images' => $images, 'attributes' => $attributes, 'salePrice' => $price['salePrice'],
            'listPrice' => $price['listPrice'], 'vatRate' => (int) $vat,
        );
    }

    public function push_products($supplier, $items)
    {
        $response = $this->request_json('POST', 'https://api.n11.com/ms/product/tasks/product-create', $supplier, array('payload' => array('integrator' => self::INTEGRATOR_NAME, 'skus' => array_values($items))));
        return is_wp_error($response) ? $response : $response['data'];
    }

    public function build_product_update_item($create_item)
    {
        return array_intersect_key((array) $create_item, array_flip(array(
            'stockCode', 'preparingDay', 'shipmentTemplate', 'productMainId', 'description', 'vatRate', 'attributes',
        ))) + array('deleteProductMainId' => true);
    }

    public function push_product_updates($supplier, $items)
    {
        $response = $this->request_json('POST', 'https://api.n11.com/ms/product/tasks/product-update', $supplier, array('payload' => array('integrator' => self::INTEGRATOR_NAME, 'skus' => array_values($items))));
        return is_wp_error($response) ? $response : $response['data'];
    }

    private function variation_value($product, $parent, $selected_attribute)
    {
        if (!$parent || $selected_attribute === '') return '';
        foreach ((array) $product->get_attributes() as $name => $value) {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name;
            if ($selected_attribute !== $name && $selected_attribute !== $label) continue;
            if (taxonomy_exists($name)) {
                $term = get_term_by('slug', $value, $name);
                if ($term && !is_wp_error($term)) return (string) $term->name;
            }
            return mb_convert_case(str_replace('-', ' ', (string) $value), MB_CASE_TITLE, 'UTF-8');
        }
        return '';
    }

    private function normalized_name($value)
    {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = trim(preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($value, 'UTF-8')));
        if (strpos($value, 'osmanli') !== false) return 'osmanli';
        if (strpos($value, 'turk bayrak') !== false) return 'turk bayrak';
        return in_array($value, array('renk', 'color'), true) ? 'renk' : $value;
    }

    private function product_images($product, $parent, $override = '')
    {
        $ids = $parent
            ? array($product->get_image_id())
            : array_merge(array($product->get_image_id()), $product->get_gallery_image_ids());
        $images = array();
        if (preg_match('#^https?://#i', $override)) $images[] = array('url' => esc_url_raw($override), 'order' => 0);
        foreach (array_unique(array_filter($ids)) as $id) {
            $url = wp_get_attachment_url($id);
            if (preg_match('#^https?://#i', (string) $url)) $images[] = array('url' => $url, 'order' => count($images));
            if (count($images) === 8) break;
        }
        return $images;
    }

    public function push_price_inventory_updates($supplier, $items)
    {
        $credential_check = $this->validate_credentials($supplier);
        if (is_wp_error($credential_check)) {
            return $credential_check;
        }

        $payload = array(
            'payload' => array(
                'integrator' => self::INTEGRATOR_NAME,
                'skus' => array_values($items),
            ),
        );

        $response = $this->request_json(
            'POST',
            'https://api.n11.com/ms/product/tasks/price-stock-update',
            $supplier,
            $payload
        );
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

        $payload = array(
            'taskId' => is_numeric($batch_request_id) ? (int) $batch_request_id : $batch_request_id,
            'pageable' => array(
                'page' => 0,
                'size' => 1000,
            ),
        );

        $response = $this->request_json(
            'POST',
            'https://api.n11.com/ms/product/task-details/page-query',
            $supplier,
            $payload
        );
        if (is_wp_error($response)) {
            return $response;
        }

        return $response['data'];
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

    private function map_variation_attributes($attributes)
    {
        $mapped = array();
        if (!is_array($attributes)) {
            return $mapped;
        }
        foreach ($attributes as $attribute) {
            $attribute = is_array($attribute) ? $attribute : (array) $attribute;
            $name = trim((string) $this->first_not_empty($attribute, array('attributeName', 'name'), ''));
            $value = trim((string) $this->first_not_empty($attribute, array('attributeValue', 'value'), ''));
            if ($name !== '' && $value !== '') {
                $mapped[$name] = $value;
            }
        }
        return $mapped;
    }

    private function merge_packages_by_order_number($items)
    {
        if (!is_array($items) || empty($items)) {
            return array();
        }

        $merged = array();
        foreach ($items as $index => $raw_item) {
            $item = is_array($raw_item) ? $raw_item : (array) $raw_item;
            $order_number = (string) $this->first_not_empty($item, array('orderNumber'), '');
            $package_id = (string) $this->first_not_empty($item, array('id'), '');
            if ($order_number !== '') {
                $key = $order_number;
            } elseif ($package_id !== '') {
                $key = 'package_' . $package_id;
            } else {
                $key = 'row_' . $index;
            }

            $lines = array();
            if (isset($item['lines']) && is_array($item['lines'])) {
                $lines = array_values($item['lines']);
            }

            if (!isset($merged[$key])) {
                $item['lines'] = $lines;
                $item['mergedTotalAmount'] = (float) $this->to_float(
                    $this->first_not_empty($item, array('totalAmount')),
                    0
                );
                $item['packageIds'] = $package_id !== '' ? array($package_id) : array();
                $merged[$key] = $item;
                continue;
            }

            $existing = $merged[$key];
            if (!isset($existing['lines']) || !is_array($existing['lines'])) {
                $existing['lines'] = array();
            }
            $existing['lines'] = array_merge($existing['lines'], $lines);

            $existing_total = (float) $this->to_float(
                $this->first_not_empty($existing, array('mergedTotalAmount')),
                0
            );
            $new_total = (float) $this->to_float($this->first_not_empty($item, array('totalAmount')), 0);
            $existing['mergedTotalAmount'] = $existing_total + $new_total;

            if (!isset($existing['packageIds']) || !is_array($existing['packageIds'])) {
                $existing['packageIds'] = array();
            }
            if ($package_id !== '' && !in_array($package_id, $existing['packageIds'], true)) {
                $existing['packageIds'][] = $package_id;
            }

            $existing_last_modified = (int) $this->to_int(
                $this->first_not_empty($existing, array('lastModifiedDate')),
                0
            );
            $item_last_modified = (int) $this->to_int(
                $this->first_not_empty($item, array('lastModifiedDate')),
                0
            );
            if ($item_last_modified > $existing_last_modified) {
                $fields_to_refresh = array(
                    'shipmentPackageStatus',
                    'status',
                    'lastModifiedDate',
                    'agreedDeliveryDate',
                    'shippingAddress',
                    'billingAddress',
                    'customerEmail',
                    'customerfullName',
                    'currencyType',
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
}
