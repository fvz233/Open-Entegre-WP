<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) {
    exit;
}

class SyncLog
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'multi_sync_logs';
    }

    public function log($supplier_id, $type, $status, $message)
    {
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'supplier_id' => $supplier_id,
                'type' => $type,
                'status' => $status,
                'message' => $message
            ),
            array('%d', '%s', '%s', '%s')
        );
    }

    public function get_logs($limit = 100, $offset = 0)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset));
    }

    public function clear_logs($supplier_id = null)
    {
        global $wpdb;
        if ($supplier_id) {
            $wpdb->delete($this->table_name, array('supplier_id' => $supplier_id), array('%d'));
        } else {
            $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        }
    }
}
