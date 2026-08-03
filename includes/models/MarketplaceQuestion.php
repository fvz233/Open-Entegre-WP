<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) {
    exit;
}

class MarketplaceQuestion
{
    private $table_name;
    private $suppliers_table;
    private static $ensured_table = false;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'multi_sync_marketplace_questions';
        $this->suppliers_table = $wpdb->prefix . 'multi_sync_suppliers';

        // Safety net: ensure table exists even if activation/migration did not run.
        if (!self::$ensured_table && function_exists('multi_sync_ensure_marketplace_questions_table')) {
            self::$ensured_table = true;
            multi_sync_ensure_marketplace_questions_table();
        }
    }

    public function get($id)
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT q.*, s.name AS supplier_name
             FROM {$this->table_name} q
             LEFT JOIN {$this->suppliers_table} s ON s.id = q.supplier_id
             WHERE q.id = %d
             LIMIT 1",
            (int) $id
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row) {
            return null;
        }

        return $this->decode_row($row);
    }

    public function list_questions($filters = array())
    {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (!empty($filters['supplier_id'])) {
            $where[] = 'q.supplier_id = %d';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['marketplace_key'])) {
            $where[] = 'q.marketplace_key = %s';
            $params[] = sanitize_key((string) $filters['marketplace_key']);
        }

        if (!empty($filters['status'])) {
            $where[] = 'q.status = %s';
            $params[] = sanitize_text_field((string) $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field((string) $filters['search'])) . '%';
            $where[] = '(q.external_question_id LIKE %s OR q.product_name LIKE %s OR q.customer_name LIKE %s OR q.question_text LIKE %s OR q.answer_text LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $per_page = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $per_page = max(1, min(200, $per_page));
        $offset = ($page - 1) * $per_page;

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} q WHERE {$where_sql}";
        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }
        if (!empty($wpdb->last_error) && function_exists('multi_sync_debug_log')) {
            multi_sync_debug_log('MarketplaceQuestion::list_questions count query error: ' . $wpdb->last_error);
        }

        $list_sql = "SELECT
                        q.id,
                        q.supplier_id,
                        q.marketplace_key,
                        q.external_question_id,
                        q.external_product_id,
                        q.product_name,
                        q.customer_name,
                        q.question_text,
                        q.answer_text,
                        q.status,
                        q.can_reply,
                        q.asked_at,
                        q.answered_at,
                        q.last_synced_at,
                        q.last_reply_error,
                        q.created_at,
                        q.updated_at,
                        s.name AS supplier_name
                     FROM {$this->table_name} q
                     LEFT JOIN {$this->suppliers_table} s ON s.id = q.supplier_id
                     WHERE {$where_sql}
                     ORDER BY q.updated_at DESC, q.id DESC
                     LIMIT %d OFFSET %d";

        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);
        if (!empty($wpdb->last_error) && function_exists('multi_sync_debug_log')) {
            multi_sync_debug_log('MarketplaceQuestion::list_questions list query error: ' . $wpdb->last_error);
        }
        $items = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = $this->decode_row($row);
            }
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

    public function upsert($data)
    {
        global $wpdb;

        $supplier_id = isset($data['supplier_id']) ? (int) $data['supplier_id'] : 0;
        $marketplace_key = isset($data['marketplace_key']) ? sanitize_key((string) $data['marketplace_key']) : '';
        $external_question_id = isset($data['external_question_id']) ? sanitize_text_field((string) $data['external_question_id']) : '';

        if ($supplier_id <= 0 || $marketplace_key === '' || $external_question_id === '') {
            return false;
        }

        $now = current_time('mysql');
        $payload = array(
            'supplier_id' => $supplier_id,
            'marketplace_key' => $marketplace_key,
            'external_question_id' => $external_question_id,
            'external_product_id' => isset($data['external_product_id']) ? sanitize_text_field((string) $data['external_product_id']) : '',
            'product_name' => isset($data['product_name']) ? sanitize_text_field((string) $data['product_name']) : '',
            'customer_name' => isset($data['customer_name']) ? sanitize_text_field((string) $data['customer_name']) : '',
            'question_text' => isset($data['question_text']) ? (string) $data['question_text'] : '',
            'answer_text' => isset($data['answer_text']) ? (string) $data['answer_text'] : '',
            'status' => isset($data['status']) ? sanitize_text_field((string) $data['status']) : '',
            'can_reply' => !empty($data['can_reply']) ? 1 : 0,
            'asked_at' => isset($data['asked_at']) ? $this->sanitize_mysql_datetime($data['asked_at']) : null,
            'answered_at' => isset($data['answered_at']) ? $this->sanitize_mysql_datetime($data['answered_at']) : null,
            'last_synced_at' => isset($data['last_synced_at']) ? $this->sanitize_mysql_datetime($data['last_synced_at']) : $now,
            'last_reply_error' => isset($data['last_reply_error']) ? (string) $data['last_reply_error'] : '',
            'raw_payload' => isset($data['raw_payload']) ? wp_json_encode($data['raw_payload']) : null,
            'updated_at' => $now,
        );

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE supplier_id = %d AND marketplace_key = %s AND external_question_id = %s LIMIT 1",
            $supplier_id,
            $marketplace_key,
            $external_question_id
        ));

        $formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s');

        if ($existing_id > 0) {
            $updated = $wpdb->update(
                $this->table_name,
                $payload,
                array('id' => $existing_id),
                $formats,
                array('%d')
            );

            if ($updated === false) {
                if (function_exists('multi_sync_debug_log')) {
                    multi_sync_debug_log(sprintf(
                        'MarketplaceQuestion::upsert update failed supplier=%d marketplace=%s external_question_id=%s db_error=%s',
                        $supplier_id,
                        $marketplace_key,
                        $external_question_id,
                        isset($wpdb->last_error) ? (string) $wpdb->last_error : ''
                    ));
                }
                return false;
            }

            return $existing_id;
        }

        $payload['created_at'] = $now;
        $insert_formats = $formats;
        $insert_formats[] = '%s';

        $inserted = $wpdb->insert($this->table_name, $payload, $insert_formats);
        if ($inserted === false) {
            if (function_exists('multi_sync_debug_log')) {
                multi_sync_debug_log(sprintf(
                    'MarketplaceQuestion::upsert insert failed supplier=%d marketplace=%s external_question_id=%s db_error=%s',
                    $supplier_id,
                    $marketplace_key,
                    $external_question_id,
                    isset($wpdb->last_error) ? (string) $wpdb->last_error : ''
                ));
            }
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function mark_answered($id, $answer_text, $status = 'ANSWERED', $answered_at = null)
    {
        global $wpdb;

        $data = array(
            'answer_text' => (string) $answer_text,
            'status' => sanitize_text_field((string) $status),
            'can_reply' => 0,
            'answered_at' => $this->sanitize_mysql_datetime($answered_at ? $answered_at : current_time('mysql')),
            'last_reply_error' => '',
            'last_synced_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        return $wpdb->update(
            $this->table_name,
            $data,
            array('id' => (int) $id),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d')
        ) !== false;
    }

    public function set_reply_error($id, $message)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            array(
                'last_reply_error' => (string) $message,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $id),
            array('%s', '%s'),
            array('%d')
        ) !== false;
    }

    public function cleanup_older_than_days($days)
    {
        global $wpdb;

        $days = max(1, (int) $days);
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE updated_at < DATE_SUB(%s, INTERVAL %d DAY)",
            current_time('mysql'),
            $days
        ));
    }

    private function decode_row($row)
    {
        if (!is_array($row)) {
            return $row;
        }

        foreach (array('id', 'supplier_id') as $int_key) {
            if (isset($row[$int_key])) {
                $row[$int_key] = (int) $row[$int_key];
            }
        }

        $row['can_reply'] = !empty($row['can_reply']);
        $row['raw_payload'] = $this->decode_json_field(isset($row['raw_payload']) ? $row['raw_payload'] : null);

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

    private function sanitize_mysql_datetime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', $timestamp);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
