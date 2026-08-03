<?php

namespace MultiSync\Sync;

use MultiSync\Models\SyncChangeHistory;
use MultiSync\Models\SyncJob;
use MultiSync\Models\SyncJobItem;
use MultiSync\Models\SyncLog;
use MultiSync\Models\Supplier;
use MultiSync\Marketplaces\MarketplaceManager;

if (!defined('ABSPATH')) {
    exit;
}

class JobWorker
{
    public const CRON_HOOK = 'multi_sync_job_worker_event';
    public const CLEANUP_HOOK = 'multi_sync_change_history_cleanup_event';

    private const LOCK_KEY = 'multi_sync_job_worker_lock';
    private const LOCK_TTL = 50;
    private const MAX_JOBS_PER_TICK = 5;
    private const HISTORY_RETENTION_DAYS = 30;

    private static $initialized = false;

    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        add_filter('cron_schedules', array(__CLASS__, 'register_custom_schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'process_queue'));
        add_action(self::CLEANUP_HOOK, array(__CLASS__, 'cleanup_change_history'));
        add_action('init', array(__CLASS__, 'ensure_cron_schedules'), 30);
    }

    public static function register_custom_schedules($schedules)
    {
        if (!isset($schedules['multi_sync_every_minute'])) {
            $schedules['multi_sync_every_minute'] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display' => __('Every Minute', 'multi-sync'),
            );
        }

        return $schedules;
    }

    public static function ensure_cron_schedules()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'multi_sync_every_minute', self::CRON_HOOK);
        }

        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
    }

    public static function clear_all_schedules()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }

    public static function process_queue()
    {
        if (get_transient(self::LOCK_KEY)) {
            return;
        }

        set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);

        try {
            $job_model = new SyncJob();
            $item_model = new SyncJobItem();
            $change_model = new SyncChangeHistory();
            foreach ($job_model->recover_stale_running() as $stale_job_id) {
                $item_model->update_status_by_job($stale_job_id, 'failed', 'Worker stopped while processing this job.');
            }

            for ($i = 0; $i < self::MAX_JOBS_PER_TICK; $i++) {
                $job = $job_model->claim_next_queued();
                if (!$job || !is_array($job)) {
                    break;
                }

                try {
                    $job_type = isset($job['job_type']) ? sanitize_key((string) $job['job_type']) : '';
                    if ($job_type === 'stock_push') {
                        self::process_stock_push_job($job, $job_model, $item_model, $change_model);
                        continue;
                    }
                    if ($job_type === 'order_import') {
                        self::process_order_import_job($job, $job_model, $item_model, $change_model);
                        continue;
                    }
                    throw new \RuntimeException('Desteklenmeyen job tipi: ' . $job_type);
                } catch (\Throwable $error) {
                    $job_model->update_job($job['id'], array(
                        'status' => 'failed', 'finished_at' => current_time('mysql'), 'error_message' => $error->getMessage(),
                    ));
                    $item_model->update_status_by_job($job['id'], 'failed', $error->getMessage());
                }
            }
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    public static function cleanup_change_history()
    {
        $change_model = new SyncChangeHistory();
        $change_model->cleanup_older_than_days(self::HISTORY_RETENTION_DAYS);
    }

    private static function process_stock_push_job($job, $job_model, $item_model, $change_model)
    {
        $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : array();

        $supplier_id = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : (int) $job['supplier_id'];
        $stock_mode = isset($payload['stock_mode']) ? sanitize_key((string) $payload['stock_mode']) : 'marketplace_match';
        $selected_skus = isset($payload['selected_skus']) && is_array($payload['selected_skus'])
            ? $payload['selected_skus']
            : array();
        $runtime_sync = isset($payload['runtime_sync']) && is_array($payload['runtime_sync'])
            ? $payload['runtime_sync']
            : array();

        if (!empty($payload['remote_batch_ids'])) {
            self::poll_remote_batches($job, $payload, $supplier_id, $job_model, $item_model);
            return;
        }

        $result = StockSync::run_for_supplier(
            $supplier_id,
            array(),
            $selected_skus,
            $stock_mode,
            $runtime_sync,
            array(
                'log_enabled' => false,
            )
        );

        if (is_wp_error($result)) {
            $job_model->update_job($job['id'], array(
                'status' => 'failed',
                'finished_at' => current_time('mysql'),
                'error_message' => $result->get_error_message(),
                'summary_json' => array(
                    'processed' => 0,
                    'sent' => 0,
                    'error' => $result->get_error_message(),
                ),
            ));
            $item_model->update_status_by_job($job['id'], 'failed', $result->get_error_message());
            return;
        }

        if (!empty($result['errors'])) {
            $message = implode('; ', array_map('strval', (array) $result['errors']));
            $job_model->update_job($job['id'], array(
                'status' => 'failed', 'finished_at' => current_time('mysql'), 'error_message' => $message, 'summary_json' => $result,
            ));
            $item_model->update_status_by_job($job['id'], 'failed', $message);
            return;
        }

        $batch_ids = isset($result['batch_request_ids']) && is_array($result['batch_request_ids'])
            ? array_values(array_filter(array_map('strval', $result['batch_request_ids'])))
            : array();
        $supplier = (new Supplier())->get($supplier_id);
        $async_marketplace = $supplier && in_array(sanitize_key((string) $supplier->marketplace_key), array('trendyol', 'n11', 'ciceksepeti'), true);
        if (!empty($batch_ids) && $async_marketplace) {
            $payload['remote_batch_ids'] = $batch_ids;
            $payload['remote_attempts'] = 0;
            $job_model->update_job($job['id'], array(
                'status' => 'waiting_remote', 'payload_json' => $payload, 'summary_json' => $result, 'error_message' => '',
            ));
            $item_model->update_status_by_job($job['id'], 'waiting_remote');
            return;
        }

        $job_model->update_job($job['id'], array(
            'status' => 'completed',
            'finished_at' => current_time('mysql'),
            'summary_json' => is_array($result) ? $result : array(),
            'error_message' => '',
        ));
        $item_model->update_status_by_job($job['id'], 'completed');

        $items = $item_model->get_by_job($job['id']);
        $changes = self::build_stock_changes_from_job_items($job, $items);
        if (!empty($changes)) {
            $change_model->create_many($changes);

            $summary = is_array($result) ? $result : array();
            $sent = isset($summary['sent']) ? (int) $summary['sent'] : 0;
            $processed = isset($summary['processed']) ? (int) $summary['processed'] : 0;
            $batch_count = isset($summary['batch_request_ids']) && is_array($summary['batch_request_ids'])
                ? count($summary['batch_request_ids'])
                : 0;

            if ($sent > 0) {
                $log_model = new SyncLog();
                $log_model->log(
                    $supplier_id,
                    'stock_push',
                    'success',
                    sprintf(
                        '[queue] Stock/fiyat push tamamlandi. Islenen: %d, Gonderilen: %d, Batch: %d',
                        $processed,
                        $sent,
                        $batch_count
                    )
                );
            }
        }
    }

    private static function poll_remote_batches($job, $payload, $supplier_id, $job_model, $item_model)
    {
        $supplier = (new Supplier())->get($supplier_id);
        $adapter = $supplier ? (new MarketplaceManager())->for_supplier($supplier) : null;
        if (!$adapter) {
            throw new \RuntimeException('Remote batch marketplace adapter not found.');
        }
        $pending = false;
        $errors = array();
        foreach ((array) $payload['remote_batch_ids'] as $batch_id) {
            $result = $adapter->get_batch_request_result($supplier, $batch_id);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
                continue;
            }
            $state = self::normalize_remote_batch_state($result);
            if ($state === 'failed') {
                $errors[] = 'Remote batch failed: ' . $batch_id;
            } elseif ($state !== 'completed') {
                $pending = true;
            }
        }
        if (!empty($errors)) {
            $job_model->update_job($job['id'], array(
                'status' => 'failed', 'finished_at' => current_time('mysql'), 'error_message' => implode('; ', $errors),
            ));
            $item_model->update_status_by_job($job['id'], 'failed', implode('; ', $errors));
            return;
        }
        $attempts = isset($payload['remote_attempts']) ? (int) $payload['remote_attempts'] + 1 : 1;
        if ($pending && $attempts < 30) {
            $payload['remote_attempts'] = $attempts;
            $job_model->update_job($job['id'], array('status' => 'waiting_remote', 'payload_json' => $payload));
            return;
        }
        if ($pending) {
            throw new \RuntimeException('Remote batch confirmation timed out.');
        }
        $job_model->update_job($job['id'], array('status' => 'completed', 'finished_at' => current_time('mysql'), 'error_message' => ''));
        $item_model->update_status_by_job($job['id'], 'completed');
    }

    public static function normalize_remote_batch_state($result)
    {
        $states = array();
        $failed = false;
        $walk = function ($value, $key = '') use (&$walk, &$states, &$failed) {
            if (is_object($value)) {
                $value = get_object_vars($value);
            }
            if (is_array($value)) {
                foreach ($value as $child_key => $child) {
                    $walk($child, (string) $child_key);
                }
                return;
            }
            $normalized_key = strtolower($key);
            $normalized_value = strtolower(trim((string) $value));
            if (preg_match('/error|fail/', $normalized_key) && $normalized_value !== '' && !in_array($normalized_value, array('0', 'false', 'null'), true)) {
                $failed = true;
            }
            if (preg_match('/status|state|result/', $normalized_key) && $normalized_value !== '') {
                $states[] = $normalized_value;
            }
        };
        $walk($result);
        if ($failed || preg_grep('/failed|failure|error|unsuccess|rejected/', $states)) {
            return 'failed';
        }
        if (preg_grep('/pending|processing|progress|created|queued|running/', $states)) {
            return 'pending';
        }
        return preg_grep('/completed|complete|success|finished|done/', $states) ? 'completed' : 'pending';
    }

    private static function process_order_import_job($job, $job_model, $item_model, $change_model)
    {
        $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : array();

        $supplier_id = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : (int) $job['supplier_id'];
        $selected_external_ids = isset($payload['selected_external_ids']) && is_array($payload['selected_external_ids'])
            ? $payload['selected_external_ids']
            : array();

        $importer = new OrderImporter();
        $report = $importer->run_sync_with_report($supplier_id, $selected_external_ids, array(
            'log' => false,
            'strict' => true,
        ));

        if (is_wp_error($report)) {
            $job_model->update_job($job['id'], array(
                'status' => 'failed',
                'finished_at' => current_time('mysql'),
                'error_message' => $report->get_error_message(),
                'summary_json' => array(
                    'created' => 0,
                    'updated' => 0,
                    'failed' => 1,
                    'error' => $report->get_error_message(),
                ),
            ));
            return;
        }

        if (!is_array($report)) {
            $report = array();
        }

        $changes = isset($report['changes']) && is_array($report['changes']) ? $report['changes'] : array();

        $job_items = array();
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }

            $job_items[] = array(
                'job_id' => (int) $job['id'],
                'supplier_id' => (int) $supplier_id,
                'item_key' => isset($change['external_id']) ? (string) $change['external_id'] : '',
                'item_type' => 'order',
                'status' => isset($change['status']) ? sanitize_key((string) $change['status']) : 'completed',
                'before_status' => isset($change['before_status']) ? (string) $change['before_status'] : '',
                'after_status' => isset($change['after_status']) ? (string) $change['after_status'] : '',
                'before_meta' => isset($change['before_meta']) && is_array($change['before_meta']) ? $change['before_meta'] : array(),
                'after_meta' => isset($change['after_meta']) && is_array($change['after_meta']) ? $change['after_meta'] : array(),
                'message' => isset($change['message']) ? (string) $change['message'] : '',
            );
        }

        if (!empty($job_items)) {
            $item_model->create_many($job_items);
        }

        $created = isset($report['created']) ? (int) $report['created'] : 0;
        $updated = isset($report['updated']) ? (int) $report['updated'] : 0;
        $failed = isset($report['failed']) ? (int) $report['failed'] : 0;

        $status = ($created + $updated) > 0 ? 'completed' : ($failed > 0 ? 'failed' : 'completed');
        $error_message = $status === 'failed' && !empty($report['errors']) && is_array($report['errors'])
            ? implode('; ', array_map('strval', $report['errors']))
            : '';

        $job_model->update_job($job['id'], array(
            'status' => $status,
            'finished_at' => current_time('mysql'),
            'error_message' => $error_message,
            'summary_json' => array(
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ),
        ));

        $items = $item_model->get_by_job($job['id']);
        if ($status === 'completed') {
            $item_model->update_status_by_job($job['id'], 'completed');
        } else {
            $item_model->update_status_by_job($job['id'], 'failed');
        }

        $changes_for_history = self::build_order_changes_from_job_items($job, $items);
        if (!empty($changes_for_history)) {
            $change_model->create_many($changes_for_history);
        }

        if (($created + $updated) > 0) {
            $log_model = new SyncLog();
            $log_model->log(
                $supplier_id,
                'order_import',
                'success',
                sprintf(
                    '[queue] Siparis import tamamlandi. Olusan: %d, Guncellenen: %d, Basarisiz: %d',
                    $created,
                    $updated,
                    $failed
                )
            );
        }
    }

    private static function build_stock_changes_from_job_items($job, $items)
    {
        $rows = array();
        $supplier_id = isset($job['supplier_id']) ? (int) $job['supplier_id'] : 0;
        $job_id = isset($job['id']) ? (int) $job['id'] : 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_key = isset($item['item_key']) ? (string) $item['item_key'] : '';
            if ($item_key === '') {
                continue;
            }

            if (self::is_numeric_change($item, 'before_stock', 'after_stock', 0)) {
                $rows[] = array(
                    'job_id' => $job_id,
                    'supplier_id' => $supplier_id,
                    'job_type' => 'stock_push',
                    'item_key' => $item_key,
                    'change_kind' => 'stock',
                    'before_value' => $item['before_stock'],
                    'after_value' => $item['after_stock'],
                );
            }

            if (self::is_numeric_change($item, 'before_price', 'after_price', 2)) {
                $rows[] = array(
                    'job_id' => $job_id,
                    'supplier_id' => $supplier_id,
                    'job_type' => 'stock_push',
                    'item_key' => $item_key,
                    'change_kind' => 'price',
                    'before_value' => $item['before_price'],
                    'after_value' => $item['after_price'],
                );
            }

            if (self::is_numeric_change($item, 'before_discount_price', 'after_discount_price', 2)) {
                $rows[] = array(
                    'job_id' => $job_id,
                    'supplier_id' => $supplier_id,
                    'job_type' => 'stock_push',
                    'item_key' => $item_key,
                    'change_kind' => 'discount_price',
                    'before_value' => $item['before_discount_price'],
                    'after_value' => $item['after_discount_price'],
                );
            }
        }

        return $rows;
    }

    private static function build_order_changes_from_job_items($job, $items)
    {
        $rows = array();
        $supplier_id = isset($job['supplier_id']) ? (int) $job['supplier_id'] : 0;
        $job_id = isset($job['id']) ? (int) $job['id'] : 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_key = isset($item['item_key']) ? (string) $item['item_key'] : '';
            if ($item_key === '') {
                continue;
            }

            $before_status = isset($item['before_status']) ? (string) $item['before_status'] : '';
            $after_status = isset($item['after_status']) ? (string) $item['after_status'] : '';
            if ($before_status !== $after_status) {
                $rows[] = array(
                    'job_id' => $job_id,
                    'supplier_id' => $supplier_id,
                    'job_type' => 'order_import',
                    'item_key' => $item_key,
                    'change_kind' => 'order_status',
                    'before_value' => $before_status,
                    'after_value' => $after_status,
                );
            }

            $before_meta = isset($item['before_meta']) && is_array($item['before_meta']) ? $item['before_meta'] : array();
            $after_meta = isset($item['after_meta']) && is_array($item['after_meta']) ? $item['after_meta'] : array();
            if ($before_meta !== $after_meta) {
                $rows[] = array(
                    'job_id' => $job_id,
                    'supplier_id' => $supplier_id,
                    'job_type' => 'order_import',
                    'item_key' => $item_key,
                    'change_kind' => 'order_meta',
                    'before_value' => $before_meta,
                    'after_value' => $after_meta,
                );
            }
        }

        return $rows;
    }

    private static function is_numeric_change($item, $before_key, $after_key, $precision)
    {
        $before = isset($item[$before_key]) ? $item[$before_key] : null;
        $after = isset($item[$after_key]) ? $item[$after_key] : null;

        if (!is_numeric($before) || !is_numeric($after)) {
            return false;
        }

        return round((float) $before, (int) $precision) !== round((float) $after, (int) $precision);
    }
}
