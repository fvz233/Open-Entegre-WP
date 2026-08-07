<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) {
    exit;
}

class Supplier
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'multi_sync_suppliers';
    }

    public function get_all()
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
    }

    public function get_by_marketplace_key($marketplace_key)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE marketplace_key = %s ORDER BY id ASC LIMIT 1",
            sanitize_text_field($marketplace_key)
        ));
    }

    public function ensure_trendyol_supplier()
    {
        return $this->ensure_marketplace_supplier('trendyol');
    }

    public function ensure_n11_supplier()
    {
        return $this->ensure_marketplace_supplier('n11');
    }

    public function ensure_pazarama_supplier()
    {
        return $this->ensure_marketplace_supplier('pazarama');
    }

    public function ensure_ciceksepeti_supplier()
    {
        return $this->ensure_marketplace_supplier('ciceksepeti');
    }

    public function ensure_amazon_supplier()
    {
        return $this->ensure_marketplace_supplier('amazon');
    }

    public function ensure_pttavm_supplier()
    {
        return $this->ensure_marketplace_supplier('pttavm');
    }

    public function ensure_hepsiburada_supplier()
    {
        return $this->ensure_marketplace_supplier('hepsiburada');
    }

    public function ensure_predefined_suppliers()
    {
        $suppliers = array();
        foreach ($this->get_predefined_supplier_blueprints() as $marketplace_key => $defaults) {
            $supplier = $this->ensure_marketplace_supplier($marketplace_key, $defaults);
            if ($supplier) {
                $suppliers[] = $supplier;
            }
        }

        return $suppliers;
    }

    private function ensure_marketplace_supplier($marketplace_key, $defaults = null)
    {
        $marketplace_key = sanitize_key((string) $marketplace_key);
        if ($marketplace_key === '') {
            return null;
        }

        $existing = $this->get_by_marketplace_key($marketplace_key);
        if ($existing) {
            return $existing;
        }

        $all_defaults = $this->get_predefined_supplier_blueprints();
        if (!is_array($defaults)) {
            $defaults = isset($all_defaults[$marketplace_key]) ? $all_defaults[$marketplace_key] : array();
        }

        if (empty($defaults)) {
            return null;
        }

        $id = $this->create(array(
            'name' => isset($defaults['name']) ? $defaults['name'] : ucfirst($marketplace_key),
            'marketplace_key' => $marketplace_key,
            'active' => 1,
            'commission_rate' => 0,
            'color' => isset($defaults['color']) ? $defaults['color'] : '#3498db',
            'api_key' => '',
            'api_secret' => '',
            'seller_id' => '',
            'amazon_refresh_token' => isset($defaults['amazon_refresh_token']) ? $defaults['amazon_refresh_token'] : '',
            'ptt_rest_api_key' => '',
            'ptt_access_token' => '',
        ));

        return $this->get($id);
    }

    private function get_predefined_supplier_blueprints()
    {
        return array(
            'trendyol' => array(
                'name' => 'Trendyol',
                'color' => '#f27a1a',
            ),
            'n11' => array(
                'name' => 'n11',
                'color' => '#4b6cb7',
            ),
            'pazarama' => array(
                'name' => 'Pazarama',
                'color' => '#7e57c2',
            ),
            'ciceksepeti' => array(
                'name' => 'Ciceksepeti',
                'color' => '#e30a17',
            ),
            'amazon' => array(
                'name' => 'Amazon',
                'color' => '#146eb4',
                'amazon_refresh_token' => '',
            ),
            'pttavm' => array(
                'name' => 'PTTAVM',
                'color' => '#1d71b8',
            ),
            'hepsiburada' => array(
                'name' => 'Hepsiburada',
                'color' => '#ff6000',
            ),
        );
    }

    public function get($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
    }

    public function create($data)
    {
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'name' => sanitize_text_field($data['name']),
                'marketplace_key' => isset($data['marketplace_key']) ? sanitize_key($data['marketplace_key']) : 'trendyol',
                'active' => isset($data['active']) ? (int) $data['active'] : 1,
                'commission_rate' => isset($data['commission_rate']) ? (float) $data['commission_rate'] : 0,
                'color' => isset($data['color']) ? sanitize_hex_color($data['color']) : '#3498db',
                'api_key' => isset($data['api_key']) ? $this->sanitize_credential_value($data['api_key']) : '',
                'api_secret' => isset($data['api_secret']) ? $this->sanitize_credential_value($data['api_secret']) : '',
                'seller_id' => isset($data['seller_id']) ? $this->sanitize_credential_value($data['seller_id']) : '',
                'amazon_refresh_token' => isset($data['amazon_refresh_token']) ? $this->sanitize_credential_value($data['amazon_refresh_token']) : '',
                'ptt_rest_api_key' => isset($data['ptt_rest_api_key']) ? $this->sanitize_credential_value($data['ptt_rest_api_key']) : '',
                'ptt_access_token' => isset($data['ptt_access_token']) ? $this->sanitize_credential_value($data['ptt_access_token']) : ''
            ),
            array('%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        return $wpdb->insert_id;
    }

    public function update($id, $data)
    {
        global $wpdb;

        // Build fields to update dynamically - only update what's provided
        $fields = array();
        $formats = array();

        if (isset($data['name'])) {
            $fields['name'] = sanitize_text_field($data['name']);
            $formats[] = '%s';
        }

        if (isset($data['active'])) {
            $fields['active'] = (int) $data['active'];
            $formats[] = '%d';
        }

        if (isset($data['marketplace_key'])) {
            $fields['marketplace_key'] = sanitize_key($data['marketplace_key']);
            $formats[] = '%s';
        }

        if (isset($data['color'])) {
            $fields['color'] = sanitize_hex_color($data['color']);
            $formats[] = '%s';
        }

        if (isset($data['api_key'])) {
            $fields['api_key'] = $this->sanitize_credential_value($data['api_key']);
            $formats[] = '%s';
        }

        if (isset($data['api_secret'])) {
            $fields['api_secret'] = $this->sanitize_credential_value($data['api_secret']);
            $formats[] = '%s';
        }

        if (isset($data['seller_id'])) {
            $fields['seller_id'] = $this->sanitize_credential_value($data['seller_id']);
            $formats[] = '%s';
        }

        if (isset($data['amazon_refresh_token'])) {
            $fields['amazon_refresh_token'] = $this->sanitize_credential_value($data['amazon_refresh_token']);
            $formats[] = '%s';
        }

        if (isset($data['ptt_rest_api_key'])) {
            $fields['ptt_rest_api_key'] = $this->sanitize_credential_value($data['ptt_rest_api_key']);
            $formats[] = '%s';
        }

        if (isset($data['ptt_access_token'])) {
            $fields['ptt_access_token'] = $this->sanitize_credential_value($data['ptt_access_token']);
            $formats[] = '%s';
        }

        if (empty($fields)) {
            return false; // Nothing to update
        }

        if (function_exists('multi_sync_debug_log')) {
            multi_sync_debug_log(array('event' => 'supplier_update', 'supplier_id' => (int) $id, 'fields' => array_keys($fields)));
        }

        return $wpdb->update(
            $this->table_name,
            $fields,
            array('id' => $id),
            $formats,
            array('%d')
        );
    }

    public function delete($id)
    {
        global $wpdb;
        return $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
    }

    private function sanitize_credential_value($value)
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $normalized = wp_unslash((string) $value);
        return trim($normalized);
    }
}
