<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class HepsiburadaMarketplace extends BaseMarketplace
{
    const API_BASE = 'https://mpop.hepsiburada.com/product/api';
    const TEST_API_BASE = 'https://mpop-sit.hepsiburada.com/product/api';

    public function get_key() { return 'hepsiburada'; }

    public function get_label() { return 'Hepsiburada'; }

    protected function is_test_environment($supplier)
    {
        return $this->supplier_value($supplier, 'hepsiburada_environment') === 'test';
    }

    protected function get_api_key($supplier)
    {
        return $this->is_test_environment($supplier) ? trim((string) $this->supplier_value($supplier, 'hepsiburada_test_api_key')) : parent::get_api_key($supplier);
    }

    protected function get_api_secret($supplier)
    {
        return $this->is_test_environment($supplier) ? trim((string) $this->supplier_value($supplier, 'hepsiburada_test_api_secret')) : parent::get_api_secret($supplier);
    }

    protected function get_seller_id($supplier)
    {
        return $this->is_test_environment($supplier) ? trim((string) $this->supplier_value($supplier, 'hepsiburada_test_seller_id')) : parent::get_seller_id($supplier);
    }

    protected function api_base($supplier)
    {
        return $this->is_test_environment($supplier) ? self::TEST_API_BASE : self::API_BASE;
    }

    protected function build_user_agent($supplier)
    {
        return 'MultiSync/' . (defined('MULTI_SYNC_VERSION') ? MULTI_SYNC_VERSION : '1.0');
    }

    public function fetch_product_categories($supplier, $search = '')
    {
        $check = $this->validate_credentials($supplier);
        if (is_wp_error($check)) return $check;
        $url = $this->api_base($supplier) . '/categories/get-all-categories?' . http_build_query(array(
            'leaf' => 'true', 'status' => 'active', 'available' => 'true', 'page' => 0, 'size' => 2000, 'version' => 1,
        ));
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) return $response;
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
        $result = array();
        foreach ($this->extract_list($response['data'], array('data', 'content', 'items')) as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if (empty($row['categoryId']) || (isset($row['leaf']) && !$row['leaf']) || (isset($row['available']) && !$row['available'])) continue;
            $path = trim((string) ($row['paths'] ?? $row['path'] ?? $row['name'] ?? ''));
            if ($needle === '' || mb_strpos(mb_strtolower($path, 'UTF-8'), $needle) !== false) {
                $result[] = array('id' => (string) $row['categoryId'], 'name' => (string) ($row['name'] ?? ''), 'path' => $path);
            }
        }
        return $result;
    }

    public function fetch_category_attributes($supplier, $category_id)
    {
        $check = $this->validate_credentials($supplier);
        if (is_wp_error($check)) return $check;
        $url = $this->api_base($supplier) . '/categories/' . rawurlencode((string) $category_id) . '/attributes?version=1';
        $response = $this->request_json('GET', $url, $supplier);
        if (is_wp_error($response)) return $response;
        $result = array();
        foreach ($this->extract_list($response['data'], array('data', 'attributes', 'items')) as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $id = (string) ($row['id'] ?? $row['attributeId'] ?? '');
            if ($id === '') continue;
            $values = array();
            if (strtolower((string) ($row['type'] ?? '')) === 'enum') {
                $value_url = $this->api_base($supplier) . '/categories/' . rawurlencode((string) $category_id) . '/attributes/' . rawurlencode($id) . '/values?' . http_build_query(array('page' => 0, 'size' => 1000, 'version' => 1));
                $value_response = $this->request_json('GET', $value_url, $supplier);
                if (is_wp_error($value_response)) return $value_response;
                foreach ($this->extract_list($value_response['data'], array('data', 'values', 'items')) as $option) {
                    $option = is_array($option) ? $option : (array) $option;
                    if (isset($option['id']) && isset($option['value'])) $values[] = array('id' => (string) $option['id'], 'name' => (string) $option['value']);
                }
            }
            $result[] = array(
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'required' => !empty($row['mandatory']),
                'slicer' => false,
                'varianter' => !empty($row['variant']) || !empty($row['variantable']) || !empty($row['isVariant']),
                'allow_custom' => empty($values),
                'values' => $values,
            );
        }
        return $result;
    }

    public function fetch_product_brands($supplier, $search = '', $category_id = '')
    {
        if ((string) $category_id === '') return new \WP_Error('multi_sync_hepsiburada_brand_category_required', 'Hepsiburada marka aramasi icin once kategori secin.');
        $attributes = $this->fetch_category_attributes($supplier, $category_id);
        if (is_wp_error($attributes)) return $attributes;
        $needle = mb_strtolower(trim((string) $search), 'UTF-8');
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
        if (!$product || (!$product->is_type('simple') && !$parent)) return new \WP_Error('multi_sync_hepsiburada_unsupported_product', 'Yalnizca basit urunler ve varyasyonlar gonderilebilir.');
        $value = function ($key, $fallback = '') use ($overrides, $product, $parent) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') return trim((string) $overrides[$key]);
            $stored = trim((string) $product->get_meta('_multi_sync_hepsiburada_' . $key, true));
            if ($stored === '' && $parent) $stored = trim((string) $parent->get_meta('_multi_sync_hepsiburada_' . $key, true));
            return $stored !== '' ? $stored : $fallback;
        };
        $sku = $this->stock_code($value('sku', $product->get_sku()));
        $barcode = $value('barcode', $sku);
        $group = $this->stock_code($value('variant_group_id', $parent ? $parent->get_sku() : $sku));
        $category = $value('category_id', $category_mapping['category_id'] ?? '');
        $brand = $value('brand', $category_mapping['brand_name'] ?? '');
        $missing = array();
        foreach (array('sku' => array('SKU / Stok Kodu', $sku), 'barcode' => array('Barkod', $barcode), 'variant_group_id' => array('Varyant Grup Kodu', $group), 'category_id' => array('Hepsiburada Kategori ID', $category), 'brand' => array('Hepsiburada Marka', $brand)) as $key => $field) {
            if ($field[1] === '') $missing[] = array('key' => $key, 'label' => $field[0], 'type' => 'text', 'options' => array());
        }

        $mapped = array();
        foreach ((array) ($category_mapping['attributes'] ?? array()) as $item) {
            if (!empty($item['attributeId'])) $mapped[(string) $item['attributeId']] = (string) (($item['attributeValueIds'][0] ?? null) ?: ($item['attributeValue'] ?? ''));
        }
        $attributes = array();
        $color = $this->variation_color($product, $parent);
        foreach ((array) ($category_mapping['attribute_definitions'] ?? array()) as $definition) {
            $id = (string) ($definition['id'] ?? '');
            if ($id === '' || $this->normalized_name($definition['name'] ?? '') === 'marka') continue;
            $input = $value('attribute_' . $id, $mapped[$id] ?? '');
            $is_color = $this->normalized_name($definition['name'] ?? '') === 'renk';
            if ($input === '' && $is_color) $input = $color;
            $is_desi = $this->normalized_name($definition['name'] ?? '') === 'desi';
            if ($input === '' && $is_desi) $input = $this->get_product_desi($product);
            $resolved = $this->attribute_value($input, (array) ($definition['values'] ?? array()));
            if ($resolved !== '') $attributes[$id] = $resolved;
            elseif (!empty($definition['required']) || ($parent && $is_color)) $missing[] = array('key' => 'attribute_' . $id, 'label' => (string) ($definition['name'] ?? $id), 'type' => !empty($definition['values']) ? 'select' : 'text', 'options' => (array) ($definition['values'] ?? array()), 'suggested_value' => $is_color ? $color : '');
        }

        $regular = $this->apply_product_commission($product->get_regular_price(), $product, $category_mapping['commission_rate'] ?? null);
        $sale_raw = $product->get_sale_price();
        $sale = is_numeric($sale_raw) && (float) $sale_raw > 0 ? $this->apply_product_commission($sale_raw, $product, $category_mapping['commission_rate'] ?? null) : $regular;
        $price = $sale > 0 ? $sale : $regular;
        if ($price <= 0) return new \WP_Error('multi_sync_hepsiburada_product_price', 'Urun fiyati sifirdan buyuk olmali.');
        $images = $this->product_images($product, $parent, $value('image_url'));
        if (!$images) $missing[] = array('key' => 'image_url', 'label' => 'Gorsel URL', 'type' => 'text', 'options' => array());
        if ($missing) return new \WP_Error('multi_sync_hepsiburada_product_incomplete', 'Eksik Hepsiburada bilgilerini doldurun.', array('fields' => $missing));

        $source = $parent ?: $product;
        $description = $source->get_description() ?: $source->get_short_description() ?: $source->get_name();
        $payload = array_merge(array(
            'merchantSku' => $sku,
            'VaryantGroupID' => $group,
            'UrunAdi' => mb_substr($this->product_export_name($product, $parent), 0, 200),
            'UrunAciklamasi' => wp_strip_all_tags($description),
            'Barcode' => $barcode,
            'Marka' => $brand,
            'price' => number_format($price, 2, ',', ''),
            'stock' => (string) max(0, (int) $product->get_stock_quantity()),
        ), $attributes);
        if (is_callable(array($source, 'get_weight')) && $source->get_weight() !== '') $payload['kg'] = (string) $source->get_weight();
        foreach ($images as $index => $image) $payload['Image' . ($index + 1)] = $image;
        return array('categoryId' => (int) $category, 'attributes' => $payload);
    }

    public function push_products($supplier, $items)
    {
        $check = $this->validate_credentials($supplier);
        if (is_wp_error($check)) return $check;
        $merchant = $this->get_seller_id($supplier);
        $payload = array_map(function ($item) use ($merchant) {
            return array('categoryId' => (int) $item['categoryId'], 'merchant' => $merchant, 'attributes' => (array) $item['attributes']);
        }, array_values((array) $items));
        return $this->request_multipart($this->api_base($supplier) . '/products/import', $supplier, $payload);
    }

    protected function request_multipart($url, $supplier, $items)
    {
        $boundary = 'MultiSync' . wp_generate_password(24, false, false);
        $body = $this->build_multipart_body($items, $boundary);
        $headers = $this->build_default_headers($supplier);
        $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        $args = array('method' => 'POST', 'timeout' => 60, 'headers' => $headers, 'body' => $body);

        $debug_entry = array(
            'timestamp' => current_time('mysql'),
            'supplier_id' => $this->get_supplier_row_id($supplier),
            'marketplace_key' => is_callable(array($this, 'get_key')) ? $this->get_key() : '',
            'operation' => 'POST ' . (string) parse_url($url, PHP_URL_PATH),
            'request' => array(
                'method' => 'POST',
                'url' => $url,
                'headers' => $this->sanitize_headers_for_debug($headers),
                'body' => $this->truncate_debug_body($body),
            ),
            'response' => array(),
        );

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $debug_entry['response'] = array('error' => $response->get_error_message());
            $this->store_http_debug($supplier, $debug_entry);
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        $debug_entry['response'] = array(
            'status_code' => $code,
            'body' => $this->truncate_debug_body($raw),
        );
        $this->store_http_debug($supplier, $debug_entry);
        if ($code >= 400 || !is_array($data) || (isset($data['success']) && !$data['success'])) return new \WP_Error('multi_sync_hepsiburada_upload_error', sprintf('Hepsiburada urun gonderimi basarisiz oldu (%d): %s', $code, $raw), array('code' => $code, 'body' => $raw));
        if (empty($data['trackingId']) && !empty($data['data']['trackingId'])) $data['trackingId'] = $data['data']['trackingId'];
        if (empty($data['trackingId'])) return new \WP_Error('multi_sync_hepsiburada_tracking_missing', 'Hepsiburada yanitinda trackingId bulunamadi.', array('body' => $raw));
        return $data;
    }

    protected function build_multipart_body($items, $boundary = 'MultiSyncTest')
    {
        $json = wp_json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"products.json\"\r\nContent-Type: application/json\r\n\r\n" . $json . "\r\n--" . $boundary . "--\r\n";
    }

    private function stock_code($value) { return strtoupper((string) preg_replace('/\s+/u', '', trim((string) $value))); }

    private function attribute_value($input, $options)
    {
        if ($input === '') return '';
        foreach ($options as $option) {
            if ((string) ($option['id'] ?? '') === (string) $input) return (string) ($option['name'] ?? $input);
        }
        return (string) $input;
    }

    private function variation_color($product, $parent)
    {
        if (!$parent || !is_callable(array($product, 'get_attributes'))) return '';
        foreach ((array) $product->get_attributes() as $name => $value) {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $parent) : $name;
            if ($this->normalized_name($label) !== 'renk' && count($product->get_attributes()) !== 1) continue;
            if (taxonomy_exists($name)) { $term = get_term_by('slug', $value, $name); if ($term && !is_wp_error($term)) return (string) $term->name; }
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

    private function product_images($product, $parent, $override)
    {
        $ids = array_merge(array($product->get_image_id()), $parent ? array($parent->get_image_id()) : array(), $parent ? $parent->get_gallery_image_ids() : $product->get_gallery_image_ids());
        $images = preg_match('#^https?://#i', $override) ? array(esc_url_raw($override)) : array();
        foreach (array_unique(array_filter($ids)) as $id) { $url = wp_get_attachment_url($id); if (preg_match('#^https?://#i', (string) $url)) $images[] = $url; if (count($images) === 5) break; }
        return array_slice(array_values(array_unique($images)), 0, 5);
    }

    public function fetch_products($supplier, $params = array()) { return new \WP_Error('multi_sync_hepsiburada_not_supported', 'Hepsiburada urun ice aktarma bu surumde desteklenmiyor.'); }
    public function fetch_orders($supplier, $params = array()) { return new \WP_Error('multi_sync_hepsiburada_not_supported', 'Hepsiburada siparis aktarimi bu surumde desteklenmiyor.'); }
    public function map_product($raw_item) { return new \WP_Error('multi_sync_hepsiburada_not_supported', 'Hepsiburada urun ice aktarma bu surumde desteklenmiyor.'); }
    public function map_order($raw_item) { return new \WP_Error('multi_sync_hepsiburada_not_supported', 'Hepsiburada siparis aktarimi bu surumde desteklenmiyor.'); }
    public function build_price_inventory_item_from_product($product, $sync_stock = true, $sync_price = true) { return null; }
    public function push_price_inventory_updates($supplier, $items) { return new \WP_Error('multi_sync_hepsiburada_not_supported', 'Hepsiburada stok/fiyat gonderimi bu surumde desteklenmiyor.'); }
    public function get_batch_request_result($supplier, $batch_request_id) { return new \WP_Error('multi_sync_hepsiburada_not_supported', 'Hepsiburada tracking sorgusu bu surumde desteklenmiyor.'); }
}
