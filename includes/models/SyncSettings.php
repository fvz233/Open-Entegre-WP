<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) {
    exit;
}

class SyncSettings
{
    private $table_name;
    private const ALLOWED_SCHEDULES = array('manual', 'hourly', 'daily', 'per_minute');
    private const ALLOWED_INTERVALS = array(5, 10, 15, 30);
    private const ALLOWED_STOCK_AUTOMATION_MODES = array('scheduled', 'event_driven');

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'multi_sync_settings';
    }

    public function get($supplier_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE supplier_id = %d", $supplier_id));
    }

    public function save($supplier_id, $data)
    {
        global $wpdb;

        $exists = $this->get($supplier_id);

        $schedule = isset($data['schedule']) ? sanitize_key((string) $data['schedule']) : 'manual';
        if (!in_array($schedule, self::ALLOWED_SCHEDULES, true)) {
            $schedule = 'manual';
        }

        $existing_mode = ($exists && isset($exists->stock_automation_mode))
            ? sanitize_key((string) $exists->stock_automation_mode)
            : 'scheduled';
        $stock_automation_mode = isset($data['stock_automation_mode'])
            ? sanitize_key((string) $data['stock_automation_mode'])
            : $existing_mode;
        if (!in_array($stock_automation_mode, self::ALLOWED_STOCK_AUTOMATION_MODES, true)) {
            $stock_automation_mode = 'scheduled';
        }

        $interval_minutes = isset($data['interval_minutes']) ? (int) $data['interval_minutes'] : 5;
        if (!in_array($interval_minutes, self::ALLOWED_INTERVALS, true)) {
            $interval_minutes = 5;
        }

        $fields = array(
            'sync_stock' => isset($data['sync_stock']) ? (int) $data['sync_stock'] : 0,
            // Price/product auto sync has been removed from automation settings.
            'sync_price' => 0,
            'sync_products' => 0,
            'sync_orders' => isset($data['sync_orders']) ? (int) $data['sync_orders'] : 0,
            'stock_automation_mode' => $stock_automation_mode,
            'schedule' => $schedule,
            'interval_minutes' => $interval_minutes
        );

        if ($exists) {
            $result = $wpdb->update(
                $this->table_name,
                $fields,
                array('supplier_id' => $supplier_id),
                array('%d', '%d', '%d', '%d', '%s', '%s', '%d'),
                array('%d')
            );
        } else {
            $fields['supplier_id'] = $supplier_id;
            $result = $wpdb->insert(
                $this->table_name,
                $fields,
                array('%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d')
            );
        }

        if ($result === false) {
            return false;
        }

        return true;
    }
}
