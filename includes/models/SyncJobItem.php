<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) {
    exit;
}

class SyncJobItem
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'multi_sync_job_items';
    }

    public function create_many($rows)
    {
        if (!is_array($rows) || empty($rows)) {
            return true;
        }

        global $wpdb;
        $now = current_time('mysql');

        foreach ($rows as $row) {
            $fields = array(
                'job_id' => isset($row['job_id']) ? (int) $row['job_id'] : 0,
                'supplier_id' => isset($row['supplier_id']) ? (int) $row['supplier_id'] : 0,
                'item_key' => isset($row['item_key']) ? sanitize_text_field((string) $row['item_key']) : '',
                'item_type' => isset($row['item_type']) ? sanitize_key((string) $row['item_type']) : 'sku',
                'status' => isset($row['status']) ? sanitize_key((string) $row['status']) : 'queued',
                'before_stock' => array_key_exists('before_stock', $row) ? $this->int_or_null($row['before_stock']) : null,
                'after_stock' => array_key_exists('after_stock', $row) ? $this->int_or_null($row['after_stock']) : null,
                'before_price' => array_key_exists('before_price', $row) ? $this->float_or_null($row['before_price']) : null,
                'after_price' => array_key_exists('after_price', $row) ? $this->float_or_null($row['after_price']) : null,
                'before_discount_price' => array_key_exists('before_discount_price', $row) ? $this->float_or_null($row['before_discount_price']) : null,
                'after_discount_price' => array_key_exists('after_discount_price', $row) ? $this->float_or_null($row['after_discount_price']) : null,
                'before_status' => isset($row['before_status']) ? sanitize_text_field((string) $row['before_status']) : '',
                'after_status' => isset($row['after_status']) ? sanitize_text_field((string) $row['after_status']) : '',
                'before_meta' => isset($row['before_meta']) ? wp_json_encode($row['before_meta']) : null,
                'after_meta' => isset($row['after_meta']) ? wp_json_encode($row['after_meta']) : null,
                'message' => isset($row['message']) ? (string) $row['message'] : '',
                'created_at' => $now,
                'updated_at' => $now,
            );

            $inserted = $wpdb->insert(
                $this->table_name,
                $fields,
                array('%d', '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            if ($inserted === false) {
                return false;
            }
        }

        return true;
    }

    public function get_by_job($job_id)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE job_id = %d ORDER BY id ASC",
            (int) $job_id
        ), ARRAY_A);

        if (!is_array($rows)) {
            return array();
        }

        $items = array();
        foreach ($rows as $row) {
            $items[] = $this->decode_item_row($row);
        }

        return $items;
    }

    public function update_status_by_job($job_id, $status, $message = '')
    {
        global $wpdb;
        $fields = array(
            'status' => sanitize_key((string) $status),
            'updated_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s');
        if ($message !== '') {
            $fields['message'] = (string) $message;
            $formats[] = '%s';
        }
        return $wpdb->update(
            $this->table_name,
            $fields,
            array('job_id' => (int) $job_id),
            $formats,
            array('%d')
        ) !== false;
    }

    private function int_or_null($value)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function float_or_null($value)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function decode_item_row($row)
    {
        if (!is_array($row)) {
            return $row;
        }

        foreach (array('id', 'job_id', 'supplier_id') as $int_key) {
            if (isset($row[$int_key])) {
                $row[$int_key] = (int) $row[$int_key];
            }
        }

        $row['before_meta'] = $this->decode_json_field(isset($row['before_meta']) ? $row['before_meta'] : null);
        $row['after_meta'] = $this->decode_json_field(isset($row['after_meta']) ? $row['after_meta'] : null);

        return $row;
    }

    private function decode_json_field($value)
    {
        if (!is_string($value) || $value === '') {
            return array();
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public function delete_by_job($job_id)
    {
        global $wpdb;
        return $wpdb->delete($this->table_name, array('job_id' => (int) $job_id), array('%d'));
    }
}
