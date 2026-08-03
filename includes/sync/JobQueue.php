<?php

namespace MultiSync\Sync;

use MultiSync\Models\Supplier;
use MultiSync\Models\SyncJob;
use MultiSync\Models\SyncJobItem;

if (!defined('ABSPATH')) {
    exit;
}

class JobQueue
{
    const OPTION_KEY = 'multi_sync_queue_settings';

    public static function get_settings()
    {
        $defaults = array(
            'suspicious_price_drop_percent' => 20,
        );

        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }

        $settings = array_merge($defaults, $saved);
        $settings['suspicious_price_drop_percent'] = self::sanitize_threshold(
            isset($settings['suspicious_price_drop_percent']) ? $settings['suspicious_price_drop_percent'] : 20
        );

        return $settings;
    }

    public static function save_settings($data)
    {
        $settings = self::get_settings();

        if (is_array($data) && array_key_exists('suspicious_price_drop_percent', $data)) {
            $settings['suspicious_price_drop_percent'] = self::sanitize_threshold($data['suspicious_price_drop_percent']);
        }

        update_option(self::OPTION_KEY, $settings);

        return $settings;
    }

    public static function enqueue_stock_push_job(
        $supplier_id,
        $stock_mode = 'marketplace_match',
        $selected_skus = array(),
        $runtime_sync = array(),
        $source = 'manual'
    ) {
        $supplier = self::get_supplier_or_error($supplier_id);
        if (is_wp_error($supplier)) {
            return $supplier;
        }

        $stock_mode = self::sanitize_stock_mode($stock_mode);
        $runtime_sync = self::normalize_runtime_sync($runtime_sync);
        $source = self::sanitize_source($source);

        $preview = StockSync::preview_for_supplier(
            (int) $supplier_id,
            array(),
            is_array($selected_skus) ? $selected_skus : array(),
            $stock_mode,
            $runtime_sync,
            array(
                'log_warnings' => false,
            )
        );

        if (is_wp_error($preview)) {
            return $preview;
        }

        $preview_items = array();
        if (is_array($preview) && isset($preview['items']) && is_array($preview['items'])) {
            $preview_items = $preview['items'];
        }

        $changed_items = array();
        $changed_skus = array();
        $suspicious_items = array();

        $threshold = self::get_settings()['suspicious_price_drop_percent'];

        foreach ($preview_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $can_push = !isset($item['can_push']) || (bool) $item['can_push'];
            $sku = isset($item['sku']) ? trim((string) $item['sku']) : '';
            $selection_key = isset($item['selection_key']) ? trim((string) $item['selection_key']) : $sku;
            if (!$can_push || $sku === '') {
                continue;
            }

            $stock_changed = !empty($item['will_update_stock'])
                && self::is_different_numeric(
                    isset($item['before_stock']) ? $item['before_stock'] : null,
                    isset($item['after_stock']) ? $item['after_stock'] : null,
                    0
                );
            $price_changed = !empty($item['will_update_price'])
                && self::is_different_numeric(
                    isset($item['before_price']) ? $item['before_price'] : null,
                    isset($item['after_price']) ? $item['after_price'] : null,
                    2
                );
            $discount_changed = !empty($item['will_update_discount_price'])
                && self::is_different_numeric(
                    isset($item['before_discount_price']) ? $item['before_discount_price'] : null,
                    isset($item['after_discount_price']) ? $item['after_discount_price'] : null,
                    2
                );

            if (!$stock_changed && !$price_changed && !$discount_changed) {
                continue;
            }

            $change_info = array(
                'sku' => $sku,
                'name' => isset($item['name']) ? (string) $item['name'] : '',
                'status_text' => isset($item['status_text']) ? (string) $item['status_text'] : '',
                'before_stock' => isset($item['before_stock']) ? $item['before_stock'] : null,
                'after_stock' => isset($item['after_stock']) ? $item['after_stock'] : null,
                'before_price' => isset($item['before_price']) ? $item['before_price'] : null,
                'after_price' => isset($item['after_price']) ? $item['after_price'] : null,
                'before_discount_price' => isset($item['before_discount_price']) ? $item['before_discount_price'] : null,
                'after_discount_price' => isset($item['after_discount_price']) ? $item['after_discount_price'] : null,
                'stock_changed' => $stock_changed,
                'price_changed' => $price_changed,
                'discount_changed' => $discount_changed,
                'price_drop_percent' => 0,
            );

            if ($price_changed) {
                $drop_percent = self::calculate_price_drop_percent(
                    isset($item['before_price']) ? $item['before_price'] : null,
                    isset($item['after_price']) ? $item['after_price'] : null
                );
                $change_info['price_drop_percent'] = $drop_percent;
                if ($drop_percent >= $threshold) {
                    $suspicious_items[] = $change_info;
                }
            }

            $changed_items[] = $change_info;
            $changed_skus[] = $selection_key;
        }

        if (empty($changed_items)) {
            return array(
                'success' => true,
                'queued' => false,
                'reason' => 'no_change',
                'requires_approval' => false,
                'job_id' => 0,
                'job_status' => 'skipped',
            );
        }

        $changed_skus = array_values(array_unique($changed_skus));
        $requires_approval = !empty($suspicious_items);
        $job_status = $requires_approval ? 'waiting_approval' : 'queued';

        $approval_reason = '';
        if ($requires_approval) {
            $approval_reason = sprintf(
                'Supheli fiyat dususu tespit edildi. Esik: %d%%, etkilenen urun: %d',
                (int) $threshold,
                count($suspicious_items)
            );
        }

        $job_model = new SyncJob();
        $job_id = $job_model->create(array(
            'supplier_id' => (int) $supplier_id,
            'job_type' => 'stock_push',
            'source' => $source,
            'status' => $job_status,
            'payload_json' => array(
                'supplier_id' => (int) $supplier_id,
                'stock_mode' => $stock_mode,
                'selected_skus' => $changed_skus,
                'runtime_sync' => $runtime_sync,
                'requested_skus' => is_array($selected_skus) ? array_values($selected_skus) : array(),
            ),
            'sync_stock' => (bool) $runtime_sync['sync_stock'],
            'sync_price' => (bool) $runtime_sync['sync_price'],
            'stock_mode' => $stock_mode,
            'approval_required' => $requires_approval,
            'approval_reason' => $approval_reason,
            'summary_json' => array(
                'changed_items' => count($changed_items),
                'suspicious_items' => count($suspicious_items),
                'threshold' => (int) $threshold,
            ),
        ));

        if (!$job_id) {
            return new \WP_Error('multi_sync_job_create_failed', 'Kuyruk isi olusturulamadi.');
        }

        $item_model = new SyncJobItem();
        $job_items = array();
        foreach ($changed_items as $changed_item) {
            $job_items[] = array(
                'job_id' => $job_id,
                'supplier_id' => (int) $supplier_id,
                'item_key' => $changed_item['sku'],
                'item_type' => 'sku',
                'status' => $job_status,
                'before_stock' => $changed_item['before_stock'],
                'after_stock' => $changed_item['after_stock'],
                'before_price' => $changed_item['before_price'],
                'after_price' => $changed_item['after_price'],
                'before_discount_price' => $changed_item['before_discount_price'],
                'after_discount_price' => $changed_item['after_discount_price'],
                'message' => $changed_item['status_text'],
            );
        }
        if (!$item_model->create_many($job_items)) {
            $job_model->update_job($job_id, array('status' => 'failed', 'error_message' => 'Job items could not be saved.', 'finished_at' => current_time('mysql')));
            return new \WP_Error('multi_sync_job_items_create_failed', 'Kuyruk kalemleri kaydedilemedi.');
        }

        return array(
            'success' => true,
            'queued' => true,
            'reason' => '',
            'requires_approval' => $requires_approval,
            'job_id' => (int) $job_id,
            'job_status' => $job_status,
        );
    }

    public static function enqueue_stock_push_job_for_skus($supplier_id, $skus = array(), $source = 'event')
    {
        $supplier = self::get_supplier_or_error($supplier_id);
        if (is_wp_error($supplier)) {
            return $supplier;
        }

        $normalized_skus = array();
        if (is_array($skus)) {
            foreach ($skus as $sku) {
                if (!is_scalar($sku)) {
                    continue;
                }

                $value = trim((string) sanitize_text_field((string) $sku));
                if ($value !== '') {
                    $normalized_skus[] = $value;
                }
            }
        }
        $normalized_skus = array_values(array_unique($normalized_skus));

        if (empty($normalized_skus)) {
            return array(
                'success' => true,
                'queued' => false,
                'reason' => 'no_sku',
                'requires_approval' => false,
                'job_id' => 0,
                'job_status' => 'skipped',
            );
        }

        $source = self::sanitize_source($source);
        $runtime_sync = array(
            'sync_stock' => true,
            'sync_price' => false,
        );

        $job_model = new SyncJob();
        $job_id = $job_model->create(array(
            'supplier_id' => (int) $supplier_id,
            'job_type' => 'stock_push',
            'source' => $source,
            'status' => 'queued',
            'payload_json' => array(
                'supplier_id' => (int) $supplier_id,
                'stock_mode' => 'direct',
                'selected_skus' => $normalized_skus,
                'runtime_sync' => $runtime_sync,
                'requested_skus' => $normalized_skus,
            ),
            'sync_stock' => true,
            'sync_price' => false,
            'stock_mode' => 'direct',
            'approval_required' => false,
            'approval_reason' => '',
            'summary_json' => array(
                'changed_items' => count($normalized_skus),
                'suspicious_items' => 0,
                'threshold' => 0,
            ),
        ));

        if (!$job_id) {
            return new \WP_Error('multi_sync_job_create_failed', 'Kuyruk isi olusturulamadi.');
        }

        $item_model = new SyncJobItem();
        $job_items = array();
        foreach ($normalized_skus as $sku) {
            $job_items[] = array(
                'job_id' => (int) $job_id,
                'supplier_id' => (int) $supplier_id,
                'item_key' => (string) $sku,
                'item_type' => 'sku',
                'status' => 'queued',
                'before_stock' => null,
                'after_stock' => null,
                'before_price' => null,
                'after_price' => null,
                'before_discount_price' => null,
                'after_discount_price' => null,
                'message' => '[event] SKU tetigi',
            );
        }
        if (!$item_model->create_many($job_items)) {
            $job_model->update_job($job_id, array('status' => 'failed', 'error_message' => 'Job items could not be saved.', 'finished_at' => current_time('mysql')));
            return new \WP_Error('multi_sync_job_items_create_failed', 'Kuyruk kalemleri kaydedilemedi.');
        }

        return array(
            'success' => true,
            'queued' => true,
            'reason' => '',
            'requires_approval' => false,
            'job_id' => (int) $job_id,
            'job_status' => 'queued',
        );
    }

    public static function enqueue_order_import_job($supplier_id, $selected_external_ids = array(), $source = 'manual')
    {
        $supplier = self::get_supplier_or_error($supplier_id);
        if (is_wp_error($supplier)) {
            return $supplier;
        }

        $external_ids = array();
        if (is_array($selected_external_ids)) {
            foreach ($selected_external_ids as $external_id) {
                if (!is_scalar($external_id)) {
                    continue;
                }
                $value = trim((string) sanitize_text_field((string) $external_id));
                if ($value !== '') {
                    $external_ids[] = $value;
                }
            }
        }

        $job_model = new SyncJob();
        $job_id = $job_model->create(array(
            'supplier_id' => (int) $supplier_id,
            'job_type' => 'order_import',
            'source' => self::sanitize_source($source),
            'status' => 'queued',
            'payload_json' => array(
                'supplier_id' => (int) $supplier_id,
                'selected_external_ids' => array_values(array_unique($external_ids)),
            ),
            'approval_required' => false,
            'approval_reason' => '',
            'summary_json' => array(
                'requested_external_ids' => count($external_ids),
            ),
        ));

        if (!$job_id) {
            return new \WP_Error('multi_sync_job_create_failed', 'Kuyruk isi olusturulamadi.');
        }

        return array(
            'success' => true,
            'queued' => true,
            'reason' => '',
            'requires_approval' => false,
            'job_id' => (int) $job_id,
            'job_status' => 'queued',
        );
    }

    public static function approve_job($job_id, $user_id)
    {
        $job_model = new SyncJob();
        $approved = $job_model->approve((int) $job_id, (int) $user_id);
        if (!$approved) {
            return new \WP_Error('multi_sync_job_approve_failed', 'Is onaylanamadi.');
        }

        $item_model = new SyncJobItem();
        $item_model->update_status_by_job((int) $job_id, 'queued');

        $job = $job_model->get((int) $job_id);
        return is_array($job) ? $job : array();
    }

    public static function reject_job($job_id, $user_id)
    {
        $job_model = new SyncJob();
        $rejected = $job_model->reject((int) $job_id, (int) $user_id);
        if (!$rejected) {
            return new \WP_Error('multi_sync_job_reject_failed', 'Is reddedilemedi.');
        }

        $item_model = new SyncJobItem();
        $item_model->update_status_by_job((int) $job_id, 'cancelled');

        $job = $job_model->get((int) $job_id);
        return is_array($job) ? $job : array();
    }

    private static function get_supplier_or_error($supplier_id)
    {
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return new \WP_Error('multi_sync_invalid_supplier_id', 'supplier_id eksik.');
        }

        $supplier_model = new Supplier();
        $supplier = $supplier_model->get($supplier_id);
        if (!$supplier || empty($supplier->active)) {
            return new \WP_Error('multi_sync_invalid_supplier', 'Satici bulunamadi veya pasif.');
        }

        return $supplier;
    }

    private static function sanitize_threshold($value)
    {
        $threshold = (int) $value;
        if ($threshold < 1) {
            return 1;
        }
        if ($threshold > 95) {
            return 95;
        }

        return $threshold;
    }

    private static function sanitize_source($source)
    {
        $source = sanitize_key((string) $source);
        if (!in_array($source, array('manual', 'cron', 'event'), true)) {
            return 'manual';
        }

        return $source;
    }

    private static function sanitize_stock_mode($mode)
    {
        $mode = sanitize_key((string) $mode);
        if (!in_array($mode, array('marketplace_match', 'direct'), true)) {
            return 'marketplace_match';
        }

        return $mode;
    }

    private static function normalize_runtime_sync($runtime_sync)
    {
        $normalized = array(
            'sync_stock' => true,
            'sync_price' => false,
        );

        if (is_array($runtime_sync)) {
            if (array_key_exists('sync_stock', $runtime_sync)) {
                $normalized['sync_stock'] = (bool) $runtime_sync['sync_stock'];
            }
            if (array_key_exists('sync_price', $runtime_sync)) {
                $normalized['sync_price'] = (bool) $runtime_sync['sync_price'];
            }
        }

        if (!$normalized['sync_stock'] && !$normalized['sync_price']) {
            $normalized['sync_stock'] = true;
        }

        return $normalized;
    }

    private static function is_different_numeric($before, $after, $precision)
    {
        if (!is_numeric($before) || !is_numeric($after)) {
            if ($before === null && $after === null) {
                return false;
            }

            return (string) $before !== (string) $after;
        }

        $before_num = round((float) $before, (int) $precision);
        $after_num = round((float) $after, (int) $precision);

        return $before_num !== $after_num;
    }

    private static function calculate_price_drop_percent($before, $after)
    {
        if (!is_numeric($before) || !is_numeric($after)) {
            return 0;
        }

        $before_price = (float) $before;
        $after_price = (float) $after;

        if ($before_price <= 0 || $after_price >= $before_price) {
            return 0;
        }

        $drop = (($before_price - $after_price) / $before_price) * 100;
        return round($drop, 2);
    }
}
