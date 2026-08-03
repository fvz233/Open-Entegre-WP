<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) {
    exit;
}

class SyncChangeHistory
{
    private $table_name;
    private $suppliers_table;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'multi_sync_change_history';
        $this->suppliers_table = $wpdb->prefix . 'multi_sync_suppliers';
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
                'job_type' => isset($row['job_type']) ? sanitize_key((string) $row['job_type']) : '',
                'item_key' => isset($row['item_key']) ? sanitize_text_field((string) $row['item_key']) : '',
                'change_kind' => isset($row['change_kind']) ? sanitize_key((string) $row['change_kind']) : '',
                'before_value' => isset($row['before_value']) ? wp_json_encode($row['before_value']) : wp_json_encode(null),
                'after_value' => isset($row['after_value']) ? wp_json_encode($row['after_value']) : wp_json_encode(null),
                'created_at' => $now,
            );

            $wpdb->insert(
                $this->table_name,
                $fields,
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }

        return true;
    }

    public function list_changes($filters = array())
    {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (!empty($filters['job_type'])) {
            $where[] = 'c.job_type = %s';
            $params[] = sanitize_key((string) $filters['job_type']);
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = 'c.supplier_id = %d';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'c.created_at >= %s';
            $params[] = sanitize_text_field((string) $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'c.created_at <= %s';
            $params[] = sanitize_text_field((string) $filters['date_to']);
        }

        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $per_page = isset($filters['per_page']) ? (int) $filters['per_page'] : 50;
        $per_page = max(1, min(500, $per_page));
        $offset = ($page - 1) * $per_page;

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} c WHERE {$where_sql}";
        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        $list_sql = "SELECT c.*, s.name AS supplier_name
                     FROM {$this->table_name} c
                     LEFT JOIN {$this->suppliers_table} s ON s.id = c.supplier_id
                     WHERE {$where_sql}
                     ORDER BY c.id DESC
                     LIMIT %d OFFSET %d";

        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);
        $items = array();
        foreach ($rows as $row) {
            $items[] = $this->decode_row($row);
        }

        return array(
            'items' => $items,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ),
        );
    }

    public function cleanup_older_than_days($days)
    {
        global $wpdb;

        $days = max(1, (int) $days);
        $threshold = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < %s",
            $threshold
        ));
    }

    private function decode_row($row)
    {
        if (!is_array($row)) {
            return $row;
        }

        foreach (array('id', 'job_id', 'supplier_id') as $int_key) {
            if (isset($row[$int_key])) {
                $row[$int_key] = (int) $row[$int_key];
            }
        }

        $row['before_value'] = $this->decode_json($row['before_value']);
        $row['after_value'] = $this->decode_json($row['after_value']);

        return $row;
    }

    private function decode_json($value)
    {
        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $decoded;
    }
}
