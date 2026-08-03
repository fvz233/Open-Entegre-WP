<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template class for all marketplaces.
 * New marketplaces should extend this class and implement required methods.
 */
abstract class BaseMarketplace implements MarketplaceInterface
{
    const DEBUG_BODY_LIMIT = 500000;
    const DEBUG_HISTORY_LIMIT = 60;

    protected function supplier_value($supplier, $field, $default = '')
    {
        if (is_array($supplier)) {
            return isset($supplier[$field]) ? $supplier[$field] : $default;
        }

        if (is_object($supplier)) {
            return isset($supplier->$field) ? $supplier->$field : $default;
        }

        return $default;
    }

    protected function get_seller_id($supplier)
    {
        return trim((string) $this->supplier_value($supplier, 'seller_id', ''));
    }

    protected function get_api_key($supplier)
    {
        return trim((string) $this->supplier_value($supplier, 'api_key', ''));
    }

    protected function get_api_secret($supplier)
    {
        return trim((string) $this->supplier_value($supplier, 'api_secret', ''));
    }

    protected function apply_product_commission($price, $product, $fallback_rate = null)
    {
        if (!is_numeric($price) || !$product || !is_callable(array($product, 'get_meta'))) {
            return is_numeric($price) ? round((float) $price, 2) : 0.0;
        }

        $marketplace_key = is_callable(array($this, 'get_key')) ? $this->get_key() : '';
        $rates = $product->get_meta('_multi_sync_commission_rates', true);
        $rate = is_array($rates) && array_key_exists($marketplace_key, $rates)
            ? (float) $rates[$marketplace_key]
            : null;

        if ($rate === null && is_callable(array($product, 'get_parent_id'))) {
            $parent_id = (int) $product->get_parent_id();
            $parent = $parent_id > 0 ? wc_get_product($parent_id) : null;
            $parent_rates = $parent && is_callable(array($parent, 'get_meta'))
                ? $parent->get_meta('_multi_sync_commission_rates', true)
                : array();
            if (is_array($parent_rates) && array_key_exists($marketplace_key, $parent_rates)) {
                $rate = (float) $parent_rates[$marketplace_key];
            }
        }

        if ($rate === null && is_numeric($fallback_rate)) {
            $rate = (float) $fallback_rate;
        }

        if ($rate === null || $rate <= 0 || $rate >= 100) {
            return round((float) $price, 2);
        }

        return round((float) $price / (1 - ($rate / 100)));
    }

    protected function get_product_vat_rate($product, $fallback = '')
    {
        if (!$product || !is_callable(array($product, 'get_meta'))) {
            return $fallback;
        }

        $rate = trim((string) $product->get_meta('_multi_sync_vat_rate', true));
        if ($rate !== '') {
            return $rate;
        }
        foreach ((array) $product->get_meta('_multi_sync_vat_rates', true) as $legacy_rate) {
            if ((string) $legacy_rate !== '') {
                return (string) $legacy_rate;
            }
        }

        $parent_id = is_callable(array($product, 'get_parent_id')) ? (int) $product->get_parent_id() : 0;
        $parent = $parent_id > 0 ? wc_get_product($parent_id) : null;
        return $parent ? $this->get_product_vat_rate($parent, $fallback) : $fallback;
    }

    protected function estimate_desi_from_dimensions($product)
    {
        if (!$product || !is_callable(array($product, 'get_length'))) {
            return '';
        }
        $length = is_numeric($product->get_length()) ? (float) $product->get_length() : 0.0;
        $width = is_callable(array($product, 'get_width')) && is_numeric($product->get_width()) ? (float) $product->get_width() : 0.0;
        $height = is_callable(array($product, 'get_height')) && is_numeric($product->get_height()) ? (float) $product->get_height() : 0.0;
        if ($length > 0 && $width > 0 && $height > 0) {
            return number_format(($length * $width * $height) / 3000, 2, '.', '');
        }
        return '';
    }

    protected function get_product_desi($product, $fallback = '')
    {
        if (!$product || !is_callable(array($product, 'get_meta'))) {
            return $fallback;
        }
        $desi = trim((string) $product->get_meta('_multi_sync_desi', true));
        if ($desi === '') {
            $estimated = $this->estimate_desi_from_dimensions($product);
            if ($estimated !== '') {
                return $estimated;
            }
            $parent_id = is_callable(array($product, 'get_parent_id')) ? (int) $product->get_parent_id() : 0;
            $parent = $parent_id > 0 ? wc_get_product($parent_id) : null;
            return $parent ? $this->get_product_desi($parent, $fallback) : $fallback;
        }
        return $desi;
    }

    public function validate_credentials($supplier)
    {
        $api_key = $this->get_api_key($supplier);
        $api_secret = $this->get_api_secret($supplier);
        $seller_id = $this->get_seller_id($supplier);

        if ($api_key === '' || $api_secret === '' || $seller_id === '') {
            return new \WP_Error(
                'multi_sync_missing_credentials',
                'Eksik yetki bilgisi: API Key, API Secret veya Seller ID.'
            );
        }

        return true;
    }

    protected function build_basic_auth_header($supplier)
    {
        $token = base64_encode($this->get_api_key($supplier) . ':' . $this->get_api_secret($supplier));
        return 'Basic ' . $token;
    }

    protected function build_user_agent($supplier)
    {
        // Trendyol docs require: "{SellerId} - SelfIntegration"
        return $this->get_seller_id($supplier) . ' - SelfIntegration';
    }

    protected function build_default_headers($supplier)
    {
        return array(
            'Authorization' => $this->build_basic_auth_header($supplier),
            'User-Agent' => $this->build_user_agent($supplier),
            'Content-Type' => 'application/json',
        );
    }

    protected function request_json($method, $url, $supplier, $body = null)
    {
        $timeout = 60;

        $args = array(
            'method' => strtoupper($method),
            'timeout' => $timeout,
            'redirection' => 5,
            'headers' => $this->build_default_headers($supplier),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $debug_entry = array(
            'timestamp' => current_time('mysql'),
            'supplier_id' => $this->get_supplier_row_id($supplier),
            'marketplace_key' => is_callable(array($this, 'get_key')) ? $this->get_key() : '',
            'request' => array(
                'method' => strtoupper($method),
                'url' => $url,
                'headers' => $this->sanitize_headers_for_debug($args['headers']),
                'body' => $this->truncate_debug_body($body),
            ),
            'response' => array(),
        );

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $debug_entry['response'] = array(
                'error' => $response->get_error_message(),
            );
            $this->store_http_debug($supplier, $debug_entry);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);
        $debug_entry['response'] = array(
            'status_code' => $code,
            'body' => $this->truncate_debug_body($raw_body),
        );

        if ($code >= 400) {
            $this->store_http_debug($supplier, $debug_entry);
            return new \WP_Error(
                'multi_sync_marketplace_http_error',
                sprintf('Pazar yeri istegi basarisiz oldu (%d): %s', $code, is_string($raw_body) ? $raw_body : ''),
                array('code' => $code, 'body' => $raw_body)
            );
        }

        if ($raw_body !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->store_http_debug($supplier, $debug_entry);
            return new \WP_Error(
                'multi_sync_marketplace_json_error',
                'Pazar yeri yaniti JSON olarak cozumlenemedi.'
            );
        }

        $this->store_http_debug($supplier, $debug_entry);

        return array(
            'status_code' => $code,
            'body' => $raw_body,
            'data' => $data,
        );
    }

    protected function extract_list($data, $preferred_keys = array('content', 'data', 'items', 'products', 'orders', 'result'))
    {
        if (is_array($data)) {
            if (isset($data[0])) {
                return $data;
            }

            foreach ($preferred_keys as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    return $data[$key];
                }
            }
        }

        return array();
    }

    protected function first_not_empty($source, $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (!isset($source[$key])) {
                continue;
            }

            $value = $source[$key];
            if ($value === null || $value === '') {
                continue;
            }

            return $value;
        }

        return $default;
    }

    protected function to_float($value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    protected function to_int($value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    protected function get_supplier_row_id($supplier)
    {
        return (int) $this->supplier_value($supplier, 'id', 0);
    }

    protected function sanitize_headers_for_debug($headers)
    {
        if (!is_array($headers)) {
            return array();
        }

        $masked = array();
        foreach ($headers as $key => $value) {
            $normalized_key = strtolower((string) $key);
            if (in_array($normalized_key, array(
                'authorization',
                'appsecret',
                'x-api-key',
                'api-key',
                'access-token',
                'x-amz-access-token',
                'x-amzn-access-token',
                'x-amz-security-token',
            ), true)) {
                $masked[$key] = $this->mask_sensitive_value($value);
                continue;
            }
            $masked[$key] = $value;
        }

        return $masked;
    }

    protected function mask_sensitive_value($value)
    {
        $value = (string) $value;
        $len = strlen($value);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 3) . str_repeat('*', max(3, $len - 5)) . substr($value, -2);
    }

    protected function truncate_debug_body($value)
    {
        if ($value === null) {
            return null;
        }

        if (function_exists('multi_sync_redact_debug_value')) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
            $value = multi_sync_redact_debug_value($value);
        }

        if (!is_string($value)) {
            $value = wp_json_encode($value);
        }

        if (!is_string($value)) {
            return '';
        }

        if (strlen($value) <= self::DEBUG_BODY_LIMIT) {
            return $value;
        }

        return substr($value, 0, self::DEBUG_BODY_LIMIT) . '...<truncated>';
    }

    protected function store_http_debug($supplier, $entry)
    {
        if (!is_array($entry) || !function_exists('multi_sync_debug_enabled') || !multi_sync_debug_enabled()) {
            return;
        }

        if (function_exists('multi_sync_redact_debug_value')) {
            $entry = multi_sync_redact_debug_value($entry);
        }

        $marketplace_key = '';
        if (is_callable(array($this, 'get_key'))) {
            $marketplace_key = strtolower(trim((string) $this->get_key()));
        }
        $supplier_id = $this->get_supplier_row_id($supplier);

        set_transient('multi_sync_marketplace_debug_http_global', $entry, HOUR_IN_SECONDS);
        $this->append_http_debug_history('multi_sync_marketplace_debug_http_history_global', $entry);

        if ($marketplace_key !== '') {
            set_transient('multi_sync_marketplace_debug_http_marketplace_' . $marketplace_key, $entry, HOUR_IN_SECONDS);
            $this->append_http_debug_history('multi_sync_marketplace_debug_http_history_marketplace_' . $marketplace_key, $entry);
        }

        if ($supplier_id > 0) {
            set_transient('multi_sync_marketplace_debug_http_supplier_' . $supplier_id, $entry, HOUR_IN_SECONDS);
            $this->append_http_debug_history('multi_sync_marketplace_debug_http_history_supplier_' . $supplier_id, $entry);
            if ($marketplace_key !== '') {
                set_transient(
                    'multi_sync_marketplace_debug_http_supplier_' . $supplier_id . '_' . $marketplace_key,
                    $entry,
                    HOUR_IN_SECONDS
                );
                $this->append_http_debug_history(
                    'multi_sync_marketplace_debug_http_history_supplier_' . $supplier_id . '_' . $marketplace_key,
                    $entry
                );
            }
        }
    }

    protected function append_http_debug_history($transient_key, $entry)
    {
        $history = get_transient($transient_key);
        if (!is_array($history)) {
            $history = array();
        }

        array_unshift($history, $entry);
        if (count($history) > self::DEBUG_HISTORY_LIMIT) {
            $history = array_slice($history, 0, self::DEBUG_HISTORY_LIMIT);
        }

        set_transient($transient_key, $history, HOUR_IN_SECONDS);
    }

    public static function get_last_http_debug($supplier_id = 0, $marketplace_key = '')
    {
        $supplier_id = (int) $supplier_id;
        $marketplace_key = strtolower(trim((string) $marketplace_key));

        $candidates = array();

        if ($supplier_id > 0 && $marketplace_key !== '') {
            $candidates[] = 'multi_sync_marketplace_debug_http_supplier_' . $supplier_id . '_' . $marketplace_key;
            $candidates[] = 'multi_sync_marketplace_debug_http_supplier_' . $supplier_id;
            $candidates[] = 'multi_sync_marketplace_debug_http_marketplace_' . $marketplace_key;
        } elseif ($supplier_id > 0) {
            $candidates[] = 'multi_sync_marketplace_debug_http_supplier_' . $supplier_id;
            $candidates[] = 'multi_sync_marketplace_debug_http_global';
        } elseif ($marketplace_key !== '') {
            $candidates[] = 'multi_sync_marketplace_debug_http_marketplace_' . $marketplace_key;
            $candidates[] = 'multi_sync_marketplace_debug_http_global';
        } else {
            $candidates[] = 'multi_sync_marketplace_debug_http_global';
        }

        foreach ($candidates as $transient_key) {
            $entry = get_transient($transient_key);
            if ($entry !== false && is_array($entry)) {
                return $entry;
            }
        }

        return null;
    }

    public static function get_http_debug_history($supplier_id = 0, $marketplace_key = '', $limit = 20, $filters = array())
    {
        $supplier_id = (int) $supplier_id;
        $marketplace_key = strtolower(trim((string) $marketplace_key));
        $limit = max(1, min(100, (int) $limit));

        $candidates = array();
        if ($supplier_id > 0 && $marketplace_key !== '') {
            $candidates[] = 'multi_sync_marketplace_debug_http_history_supplier_' . $supplier_id . '_' . $marketplace_key;
            $candidates[] = 'multi_sync_marketplace_debug_http_history_supplier_' . $supplier_id;
            $candidates[] = 'multi_sync_marketplace_debug_http_history_marketplace_' . $marketplace_key;
        } elseif ($supplier_id > 0) {
            $candidates[] = 'multi_sync_marketplace_debug_http_history_supplier_' . $supplier_id;
            $candidates[] = 'multi_sync_marketplace_debug_http_history_global';
        } elseif ($marketplace_key !== '') {
            $candidates[] = 'multi_sync_marketplace_debug_http_history_marketplace_' . $marketplace_key;
            $candidates[] = 'multi_sync_marketplace_debug_http_history_global';
        } else {
            $candidates[] = 'multi_sync_marketplace_debug_http_history_global';
        }

        $history = array();
        foreach ($candidates as $transient_key) {
            $rows = get_transient($transient_key);
            if (is_array($rows) && !empty($rows)) {
                $history = $rows;
                break;
            }
        }

        if (empty($history)) {
            $last = self::get_last_http_debug($supplier_id, $marketplace_key);
            if (is_array($last)) {
                $history = array($last);
            }
        }

        if (!empty($history) && is_array($filters)) {
            $operation_filter = isset($filters['operation']) ? strtolower(trim((string) $filters['operation'])) : '';
            $status_filter = isset($filters['status_code']) ? (int) $filters['status_code'] : 0;

            if ($operation_filter !== '' || $status_filter > 0) {
                $history = array_values(array_filter($history, function ($entry) use ($operation_filter, $status_filter) {
                    if (!is_array($entry)) {
                        return false;
                    }

                    if ($status_filter > 0) {
                        $status_code = 0;
                        if (isset($entry['response']) && is_array($entry['response']) && isset($entry['response']['status_code'])) {
                            $status_code = (int) $entry['response']['status_code'];
                        }
                        if ($status_code !== $status_filter) {
                            return false;
                        }
                    }

                    if ($operation_filter !== '') {
                        $operation = '';
                        if (isset($entry['operation'])) {
                            $operation = strtolower(trim((string) $entry['operation']));
                        } elseif (isset($entry['request']) && is_array($entry['request']) && isset($entry['request']['url'])) {
                            $operation = strtolower(trim((string) $entry['request']['url']));
                        }
                        if ($operation === '' || strpos($operation, $operation_filter) === false) {
                            return false;
                        }
                    }

                    return true;
                }));
            }
        }

        return array_slice($history, 0, $limit);
    }
}
