<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) {
    exit;
}

class SyncJob
{
    private $table_name;
    private $suppliers_table;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'multi_sync_jobs';
        $this->suppliers_table = $wpdb->prefix . 'multi_sync_suppliers';
    }

    public function create($data)
    {
        global $wpdb;

        $now = current_time('mysql');
        $fields = array(
            'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : 0,
            'job_type' => isset($data['job_type']) ? sanitize_key((string) $data['job_type']) : '',
            'source' => isset($data['source']) ? sanitize_key((string) $data['source']) : 'manual',
            'status' => isset($data['status']) ? sanitize_key((string) $data['status']) : 'queued',
            'payload_json' => isset($data['payload_json']) ? wp_json_encode($data['payload_json']) : wp_json_encode(array()),
            'sync_stock' => array_key_exists('sync_stock', $data) ? (int) ((bool) $data['sync_stock']) : null,
            'sync_price' => array_key_exists('sync_price', $data) ? (int) ((bool) $data['sync_price']) : null,
            'stock_mode' => isset($data['stock_mode']) ? sanitize_key((string) $data['stock_mode']) : '',
            'approval_required' => !empty($data['approval_required']) ? 1 : 0,
            'approval_reason' => isset($data['approval_reason']) ? (string) $data['approval_reason'] : '',
            'approved_by' => isset($data['approved_by']) ? (int) $data['approved_by'] : null,
            'approved_at' => isset($data['approved_at']) ? (string) $data['approved_at'] : null,
            'summary_json' => isset($data['summary_json']) ? wp_json_encode($data['summary_json']) : null,
            'error_message' => isset($data['error_message']) ? (string) $data['error_message'] : '',
            'created_at' => $now,
            'updated_at' => $now,
            'started_at' => isset($data['started_at']) ? (string) $data['started_at'] : null,
            'finished_at' => isset($data['finished_at']) ? (string) $data['finished_at'] : null,
        );

        $result = $wpdb->insert(
            $this->table_name,
            $fields,
            array('%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function get($id)
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT j.*, s.name AS supplier_name
             FROM {$this->table_name} j
             LEFT JOIN {$this->suppliers_table} s ON s.id = j.supplier_id
             WHERE j.id = %d
             LIMIT 1",
            (int) $id
        );

        $job = $wpdb->get_row($sql, ARRAY_A);
        if (!$job) {
            return null;
        }

        return $this->decode_job_row($job);
    }

    public function list_jobs($filters = array())
    {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (!empty($filters['status'])) {
            $where[] = 'j.status = %s';
            $params[] = sanitize_key((string) $filters['status']);
        }

        if (!empty($filters['job_type'])) {
            $where[] = 'j.job_type = %s';
            $params[] = sanitize_key((string) $filters['job_type']);
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = 'j.supplier_id = %d';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'j.created_at >= %s';
            $params[] = sanitize_text_field((string) $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'j.created_at <= %s';
            $params[] = sanitize_text_field((string) $filters['date_to']);
        }

        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $per_page = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $per_page = max(1, min(200, $per_page));
        $offset = ($page - 1) * $per_page;

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} j WHERE {$where_sql}";
        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        $list_sql = "SELECT j.*, s.name AS supplier_name
                     FROM {$this->table_name} j
                     LEFT JOIN {$this->suppliers_table} s ON s.id = j.supplier_id
                     WHERE {$where_sql}
                     ORDER BY j.id DESC
                     LIMIT %d OFFSET %d";

        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);
        $items = array();
        foreach ($rows as $row) {
            $items[] = $this->decode_job_row($row);
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

    public function claim_next_queued()
    {
        global $wpdb;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$this->table_name}
                 WHERE status = 'queued'
                    OR (status = 'waiting_remote' AND updated_at <= %s)
                 ORDER BY id ASC
                 LIMIT 1",
                date('Y-m-d H:i:s', current_time('timestamp') - MINUTE_IN_SECONDS)
            ),
                ARRAY_A
            );

            if (!$candidate || empty($candidate['id'])) {
                return null;
            }

            $id = (int) $candidate['id'];
            $now = current_time('mysql');

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table_name}
                 SET status = 'running', started_at = %s, updated_at = %s
                 WHERE id = %d AND status IN ('queued', 'waiting_remote')",
                $now,
                $now,
                $id
            ));

            if ($updated) {
                return $this->get($id);
            }
        }

        return null;
    }

    public function recover_stale_running()
    {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - (30 * MINUTE_IN_SECONDS));
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE status = 'running' AND updated_at < %s",
            $cutoff
        )));
        if (empty($ids)) {
            return array();
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name}
             SET status = 'failed', error_message = %s, finished_at = %s, updated_at = %s
             WHERE status = 'running' AND updated_at < %s",
            'Worker stopped while processing this job.', current_time('mysql'), current_time('mysql'), $cutoff
        ));
        return $ids;
    }

    public function update_job($id, $fields)
    {
        global $wpdb;

        if (empty($fields) || !is_array($fields)) {
            return false;
        }

        $formats = array();
        $data = array();

        $allowed = array(
            'status' => '%s',
            'summary_json' => '%s',
            'error_message' => '%s',
            'started_at' => '%s',
            'finished_at' => '%s',
            'approved_by' => '%d',
            'approved_at' => '%s',
            'approval_reason' => '%s',
            'approval_required' => '%d',
            'payload_json' => '%s',
        );

        foreach ($allowed as $key => $format) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $value = $fields[$key];
            if ($key === 'summary_json' || $key === 'payload_json') {
                $value = is_string($value) ? $value : wp_json_encode($value);
            }
            if ($key === 'status') {
                $value = sanitize_key((string) $value);
            }
            if ($key === 'approval_required') {
                $value = (int) ((bool) $value);
            }
            if ($key === 'approved_by') {
                $value = (int) $value;
            }

            $data[$key] = $value;
            $formats[] = $format;
        }

        $data['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        return $wpdb->update(
            $this->table_name,
            $data,
            array('id' => (int) $id),
            $formats,
            array('%d')
        ) !== false;
    }

    public function approve($id, $user_id)
    {
        $job = $this->get($id);
        if (!$job || $job['status'] !== 'waiting_approval') {
            return false;
        }

        return $this->update_job($id, array(
            'status' => 'queued',
            'approved_by' => (int) $user_id,
            'approved_at' => current_time('mysql'),
        ));
    }

    public function reject($id, $user_id)
    {
        $job = $this->get($id);
        if (!$job || $job['status'] !== 'waiting_approval') {
            return false;
        }

        return $this->update_job($id, array(
            'status' => 'cancelled',
            'approved_by' => (int) $user_id,
            'approved_at' => current_time('mysql'),
            'finished_at' => current_time('mysql'),
        ));
    }

    public function delete($id)
    {
        $job = $this->get((int) $id);
        if (!$job || $job['status'] === 'running') {
            return false;
        }
        global $wpdb;
        if (class_exists('\MultiSync\Models\SyncJobItem')) {
            (new SyncJobItem())->delete_by_job((int) $id);
        }
        $wpdb->delete($this->table_name, array('id' => (int) $id), array('%d'));
        return true;
    }

    public function delete_all()
    {
        global $wpdb;
        if (class_exists('\MultiSync\Models\SyncJobItem')) {
            $wpdb->query(
                "DELETE FROM {$wpdb->prefix}multi_sync_job_items
                 WHERE job_id IN (SELECT id FROM {$this->table_name} WHERE status <> 'running')"
            );
        }
        return $wpdb->query("DELETE FROM {$this->table_name} WHERE status <> 'running'");
    }

    private function decode_job_row($row)
    {
        if (!is_array($row)) {
            return $row;
        }

        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['sync_stock'] = $row['sync_stock'] === null ? null : (bool) $row['sync_stock'];
        $row['sync_price'] = $row['sync_price'] === null ? null : (bool) $row['sync_price'];
        $row['approval_required'] = !empty($row['approval_required']);
        $row['approved_by'] = $row['approved_by'] === null ? null : (int) $row['approved_by'];
        $row['payload'] = array();
        $row['summary'] = array();

        if (isset($row['payload_json']) && is_string($row['payload_json']) && $row['payload_json'] !== '') {
            $payload = json_decode($row['payload_json'], true);
            if (is_array($payload)) {
                $row['payload'] = $payload;
            }
        }

        if (isset($row['summary_json']) && is_string($row['summary_json']) && $row['summary_json'] !== '') {
            $summary = json_decode($row['summary_json'], true);
            if (is_array($summary)) {
                $row['summary'] = $summary;
            }
        }

        return $row;
    }
}
