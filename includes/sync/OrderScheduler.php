<?php

namespace MultiSync\Sync;

use MultiSync\Models\Supplier;
use MultiSync\Models\SyncSettings;

if (!defined('ABSPATH')) {
    exit;
}

class OrderScheduler
{
    public const CRON_HOOK = 'multi_sync_order_import_event';

    private const ALLOWED_SCHEDULES = array('manual', 'hourly', 'daily', 'per_minute');
    private const ALLOWED_INTERVALS = array(5, 10, 15, 30);

    private static $initialized = false;
    private static $reconciled = false;

    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        add_filter('cron_schedules', array(__CLASS__, 'register_custom_schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_scheduled_order_import'), 10, 1);
        add_action('init', array(__CLASS__, 'reconcile_all_supplier_schedules'), 25);
    }

    public static function register_custom_schedules($schedules)
    {
        $custom = array(
            'multi_sync_every_5_minutes' => 5 * MINUTE_IN_SECONDS,
            'multi_sync_every_10_minutes' => 10 * MINUTE_IN_SECONDS,
            'multi_sync_every_15_minutes' => 15 * MINUTE_IN_SECONDS,
            'multi_sync_every_30_minutes' => 30 * MINUTE_IN_SECONDS,
        );

        foreach ($custom as $key => $seconds) {
            if (!isset($schedules[$key])) {
                $schedules[$key] = array(
                    'interval' => $seconds,
                    'display' => sprintf(__('Every %d Minutes', 'multi-sync'), (int) ($seconds / MINUTE_IN_SECONDS)),
                );
            }
        }

        return $schedules;
    }

    public static function reconcile_all_supplier_schedules()
    {
        if (self::$reconciled) {
            return;
        }
        self::$reconciled = true;

        $supplier_model = new Supplier();
        $suppliers = $supplier_model->get_all();
        if (empty($suppliers)) {
            return;
        }

        foreach ($suppliers as $supplier) {
            if (!$supplier || !isset($supplier->id)) {
                continue;
            }
            self::sync_supplier_schedule((int) $supplier->id);
        }
    }

    public static function sync_supplier_schedule($supplier_id)
    {
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return;
        }

        $supplier_model = new Supplier();
        $supplier = $supplier_model->get($supplier_id);

        $settings_model = new SyncSettings();
        $settings = $settings_model->get($supplier_id);

        if (!self::should_schedule($supplier, $settings)) {
            self::clear_supplier_schedule($supplier_id);
            return;
        }

        $recurrence = self::resolve_recurrence($settings);
        if ($recurrence === '') {
            self::clear_supplier_schedule($supplier_id);
            return;
        }

        $args = array($supplier_id);
        $existing_schedule = self::get_existing_schedule($args);
        if ($existing_schedule === $recurrence) {
            return;
        }

        if ($existing_schedule !== '') {
            self::clear_supplier_schedule($supplier_id);
        }
        wp_schedule_event(time() + MINUTE_IN_SECONDS, $recurrence, self::CRON_HOOK, $args);
    }

    public static function run_scheduled_order_import($supplier_id)
    {
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return;
        }

        $supplier_model = new Supplier();
        $supplier = $supplier_model->get($supplier_id);

        $settings_model = new SyncSettings();
        $settings = $settings_model->get($supplier_id);

        if (!self::should_schedule($supplier, $settings)) {
            self::clear_supplier_schedule($supplier_id);
            return;
        }

        $result = JobQueue::enqueue_order_import_job($supplier_id, array(), 'cron');
        if (is_wp_error($result)) {
            $log_model = new \MultiSync\Models\SyncLog();
            $log_model->log(
                $supplier_id,
                'order_import',
                'error',
                '[cron] Siparis import kuyruğa eklenemedi: ' . $result->get_error_message()
            );
        }
    }

    public static function clear_all_schedules()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private static function clear_supplier_schedule($supplier_id)
    {
        wp_clear_scheduled_hook(self::CRON_HOOK, array((int) $supplier_id));
    }

    private static function should_schedule($supplier, $settings)
    {
        if (!$supplier || empty($supplier->active)) {
            return false;
        }

        if (!$settings || empty($settings->sync_orders)) {
            return false;
        }

        $schedule = self::sanitize_schedule(isset($settings->schedule) ? $settings->schedule : 'manual');
        if ($schedule === 'manual') {
            return false;
        }

        return true;
    }

    private static function resolve_recurrence($settings)
    {
        $schedule = self::sanitize_schedule(isset($settings->schedule) ? $settings->schedule : 'manual');
        switch ($schedule) {
            case 'hourly':
                return 'hourly';
            case 'daily':
                return 'daily';
            case 'per_minute':
                $interval = self::sanitize_interval(isset($settings->interval_minutes) ? $settings->interval_minutes : 5);
                return sprintf('multi_sync_every_%d_minutes', $interval);
            default:
                return '';
        }
    }

    private static function sanitize_schedule($schedule)
    {
        $value = sanitize_key((string) $schedule);
        if (!in_array($value, self::ALLOWED_SCHEDULES, true)) {
            return 'manual';
        }
        return $value;
    }

    private static function sanitize_interval($interval)
    {
        $value = (int) $interval;
        if (!in_array($value, self::ALLOWED_INTERVALS, true)) {
            return 5;
        }
        return $value;
    }

    private static function get_existing_schedule($args)
    {
        if (function_exists('wp_get_scheduled_event')) {
            $existing = wp_get_scheduled_event(self::CRON_HOOK, $args);
            if ($existing && isset($existing->schedule) && is_string($existing->schedule)) {
                return $existing->schedule;
            }
            return '';
        }

        if (function_exists('wp_next_scheduled')) {
            $next = wp_next_scheduled(self::CRON_HOOK, $args);
            if ($next) {
                // Legacy WordPress does not expose recurrence details.
                return '__legacy_existing__';
            }
        }

        return '';
    }
}
