<?php
/**
 * Plugin Name: Open Entegre
 * Description: WooCommerce icin birden fazla dis pazar yerine baglanabilen esnek senkronizasyon eklentisi.
 * Version: 1.0.87
 * Author: Fevzi Demirtaş
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: multi-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

function multi_sync_debug_log($message)
{
    if (!multi_sync_debug_enabled() || !function_exists('wc_get_logger')) {
        return;
    }

    if (is_array($message) || is_object($message)) {
        $message = wp_json_encode(multi_sync_redact_debug_value($message));
    } else {
        $decoded = json_decode((string) $message, true);
        if (is_array($decoded)) {
            $message = wp_json_encode(multi_sync_redact_debug_value($decoded));
        } else {
            $message = preg_replace(
                '/(authorization|credential|secret|token|password|api[_-]?key)\s*[:=]\s*([^,\s}]+)/i',
                '$1=[redacted]',
                (string) $message
            );
        }
    }

    wc_get_logger()->debug((string) $message, array('source' => 'multi-sync'));
}

function multi_sync_debug_enabled()
{
    return defined('WP_DEBUG') && WP_DEBUG && (bool) get_option('multi_sync_debug_enabled', false);
}

function multi_sync_redact_debug_value($value, $key = '')
{
    $sensitive = '/authorization|credential|secret|token|password|api[_-]?key|email|phone|address|customer/i';
    if ($key !== '' && preg_match($sensitive, (string) $key)) {
        return '[redacted]';
    }
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $child_key => $child_value) {
        $value[$child_key] = multi_sync_redact_debug_value($child_value, (string) $child_key);
    }
    return $value;
}

define('MULTI_SYNC_VERSION', '1.0.87');
define('MULTI_SYNC_SCHEMA_VERSION', '20260812-1');
define('MULTI_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MULTI_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check for schema updates
add_action('plugins_loaded', 'multi_sync_check_schema');
function multi_sync_check_schema()
{
    $installed = get_option('multi_sync_schema_version');

    // Run full activation routine when version changes
    if ($installed !== MULTI_SYNC_SCHEMA_VERSION) {
        multi_sync_activate();
        update_option('multi_sync_schema_version', MULTI_SYNC_SCHEMA_VERSION);
        update_option('multi_sync_version', MULTI_SYNC_VERSION);
    }
}

/**
 * Automatic Update Checker
 */
$update_checker_file = MULTI_SYNC_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
if (is_readable($update_checker_file)) {
    require_once $update_checker_file;
    $myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/fvz233/open-entegre-wp',
        __FILE__,
        'sync_plugin'
    );
    $myUpdateChecker->setBranch('main');

    $vcsApi = $myUpdateChecker->getVcsApi();
    if (method_exists($vcsApi, 'enableReleaseAssets')) {
        $vcsApi->enableReleaseAssets();
    }

    if (defined('MULTI_SYNC_GITHUB_TOKEN') && MULTI_SYNC_GITHUB_TOKEN !== '') {
        $myUpdateChecker->setAuthentication(MULTI_SYNC_GITHUB_TOKEN);
    }
}

add_action('admin_post_multi_sync_update', 'multi_sync_handle_update');
function multi_sync_handle_update()
{
    if (!current_user_can('update_plugins')) {
        wp_die('Bu işlem için yetkiniz yok.', '', array('response' => 403));
    }

    check_admin_referer('multi_sync_update');
    global $myUpdateChecker;

    $panel_url = admin_url('admin.php?page=multi-sync');
    if (!isset($myUpdateChecker)) {
        wp_safe_redirect(add_query_arg('multi_sync_update', 'error', $panel_url));
        exit;
    }

    $update = $myUpdateChecker->checkForUpdates();
    if ($update === null) {
        $status = empty($myUpdateChecker->getLastRequestApiErrors()) ? 'current' : 'error';
        wp_safe_redirect(add_query_arg('multi_sync_update', $status, $panel_url));
        exit;
    }

    $plugin_file = plugin_basename(__FILE__);
    wp_safe_redirect(add_query_arg(array(
        'action' => 'upgrade-plugin',
        'plugin' => $plugin_file,
        '_wpnonce' => wp_create_nonce('upgrade-plugin_' . $plugin_file),
    ), admin_url('update.php')));
    exit;
}

/**
 * Autoloader implementation (simple PSR-4 style for includes)
 */
spl_autoload_register(function ($class) {
    $prefix = 'MultiSync\\';
    $base_dir = MULTI_SYNC_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    // Replace namespace separators with directory separators
    // Example: MultiSync\Api\SomeClass -> includes/api/SomeClass.php
    // We need to map sub-namespaces to sub-directories in lowercase

    $parts = explode('\\', $relative_class);
    $class_name = array_pop($parts);
    $path_parts = array_map('strtolower', $parts);
    $path_parts[] = $class_name . '.php'; // Keep ClassName capitalized file? Or follow convention? 
    // Plan said "ProductImporter.php" so capitalized.

    $file = $base_dir . implode('/', $path_parts);

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Activation Hook: Create Database Tables
 */
register_activation_hook(__FILE__, 'multi_sync_activate');
register_deactivation_hook(__FILE__, 'multi_sync_deactivate');

function multi_sync_activate()
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $sql_file = MULTI_SYNC_PLUGIN_DIR . 'database/schema.sql';

    if (file_exists($sql_file)) {
        // We need to read the file and replace placeholder if we used it, 
        // but verify if dbDelta handles multiple queries well or if we need to split.
        // dbDelta is tricky.
        // Let's manually read and prep.
        // Actually best practice is to define SQL inline for dbDelta usually? 
        // But plan said schema.sql. Let's include it.

        /* 
           NOTE: schema.sql uses {$wpdb->prefix} which is PHP variable, 
           so we can't just file_get_contents and run it as SQL directly if it expects PHP interpolation.
           But since we wrote it as {$wpdb->prefix}, it seems intended to be evaluated or replaced.
           Let's read content and doing str_replace manually.
        */

        $sql_content = file_get_contents($sql_file);
        $sql_content = str_replace('{$wpdb->prefix}', $wpdb->prefix, $sql_content);
        $sql_content = str_replace('{$charset_collate}', $charset_collate, $sql_content);

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Split by semicolon to ensure dbdelta gets individual queries? 
        // dbDelta documentation says "You must put each field on its own line in your SQL statement."
        // And "You must not put any apostrophes or backticks around field names."
        // My schema looks okay-ish but dbDelta is picky. 
        // Let's try to run it.

        $queries = explode(';', $sql_content);
        foreach ($queries as $query) {
            if (trim($query)) {
                dbDelta($query);
            }
        }

        // Ensure commission_rate column exists (dbDelta doesn't add columns to existing tables)
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}multi_sync_suppliers LIKE 'commission_rate'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}multi_sync_suppliers ADD COLUMN commission_rate FLOAT DEFAULT 0");
        }

        // Ensure color column exists for supplier color settings
        multi_sync_ensure_supplier_color_column();
        // Ensure marketplace/auth columns exist for predefined marketplace workflow
        multi_sync_ensure_supplier_marketplace_columns();
        // Ensure sync settings interval column exists for minute based schedules
        multi_sync_ensure_settings_interval_column();
        // Ensure stock automation mode column exists
        multi_sync_ensure_settings_stock_automation_mode_column();
        // Ensure queue/history tables and settings exist
        multi_sync_ensure_queue_tables();
        multi_sync_ensure_marketplace_questions_table();
        multi_sync_ensure_queue_settings();
        // Ensure predefined marketplace records exist
        multi_sync_ensure_predefined_suppliers();
    }

    update_option('multi_sync_schema_version', MULTI_SYNC_SCHEMA_VERSION);
    update_option('multi_sync_version', MULTI_SYNC_VERSION);
}

function multi_sync_deactivate()
{
    if (class_exists('\MultiSync\Sync\StockScheduler')) {
        \MultiSync\Sync\StockScheduler::clear_all_schedules();
    } else {
        wp_clear_scheduled_hook('multi_sync_stock_push_event');
    }

    if (class_exists('\MultiSync\Sync\OrderScheduler')) {
        \MultiSync\Sync\OrderScheduler::clear_all_schedules();
    } else {
        wp_clear_scheduled_hook('multi_sync_order_import_event');
    }

    if (class_exists('\MultiSync\Sync\JobWorker')) {
        \MultiSync\Sync\JobWorker::clear_all_schedules();
    } else {
        wp_clear_scheduled_hook('multi_sync_job_worker_event');
        wp_clear_scheduled_hook('multi_sync_change_history_cleanup_event');
    }

    if (class_exists('\MultiSync\Sync\QuestionSync')) {
        \MultiSync\Sync\QuestionSync::clear_all_schedules();
    } else {
        wp_clear_scheduled_hook('multi_sync_questions_cleanup_event');
    }

    wp_clear_scheduled_hook('multi_sync_ciceksepeti_batch_poll');
}

function multi_sync_show_ciceksepeti_batch_errors()
{
    if (!current_user_can('manage_woocommerce') || !function_exists('get_transient')) {
        return;
    }
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_multi_sync_cs_batch_status_%'
    ));
    $shown = 0;
    foreach ((array) $rows as $row) {
        if ($shown >= 3) {
            break;
        }
        $value = get_transient(substr((string) $row->option_name, strlen('_transient_')));
        if (!is_array($value) || (string) ($value['status'] ?? '') !== 'failed') {
            continue;
        }
        if (empty($value['checked_at']) || strtotime((string) $value['checked_at']) < time() - 2 * DAY_IN_SECONDS) {
            continue;
        }
        $shown++;
        echo '<div class="notice notice-error"><p><strong>Multi Sync:</strong> ' . esc_html((string) ($value['message'] ?? 'Ciceksepeti batch hatasi.')) . '</p></div>';
    }
}

/**
 * Ensure the supplier color column exists (idempotent).
 * This runs on each load so installs that pre-date the column can self-heal without reactivation.
 */
function multi_sync_ensure_supplier_color_column()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'multi_sync_suppliers';

    $color_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", 'color'));
    if ($color_exists) {
        return;
    }

    $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN color VARCHAR(7) DEFAULT '#3498db' AFTER commission_rate");

    if (function_exists('multi_sync_debug_log')) {
        if ($result === false && !empty($wpdb->last_error)) {
            multi_sync_debug_log('Failed to add color column: ' . $wpdb->last_error);
        } else {
            multi_sync_debug_log('Added color column to ' . $table_name);
        }
    }
}

/**
 * Ensure required supplier columns exist for predefined marketplace model.
 */
function multi_sync_ensure_supplier_marketplace_columns()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'multi_sync_suppliers';

    $columns = array(
        'marketplace_key' => "ALTER TABLE {$table_name} ADD COLUMN marketplace_key VARCHAR(100) DEFAULT 'trendyol' AFTER name",
        'api_key' => "ALTER TABLE {$table_name} ADD COLUMN api_key TEXT NULL AFTER commission_rate",
        'api_secret' => "ALTER TABLE {$table_name} ADD COLUMN api_secret TEXT NULL AFTER api_key",
        'seller_id' => "ALTER TABLE {$table_name} ADD COLUMN seller_id VARCHAR(100) DEFAULT '' AFTER api_secret",
        'amazon_refresh_token' => "ALTER TABLE {$table_name} ADD COLUMN amazon_refresh_token TEXT NULL AFTER seller_id",
        'ptt_rest_api_key' => "ALTER TABLE {$table_name} ADD COLUMN ptt_rest_api_key TEXT NULL AFTER amazon_refresh_token",
        'ptt_access_token' => "ALTER TABLE {$table_name} ADD COLUMN ptt_access_token TEXT NULL AFTER ptt_rest_api_key",
        'n11_shipment_template' => "ALTER TABLE {$table_name} ADD COLUMN n11_shipment_template VARCHAR(190) DEFAULT '' AFTER ptt_access_token",
        'hepsiburada_environment' => "ALTER TABLE {$table_name} ADD COLUMN hepsiburada_environment VARCHAR(10) DEFAULT 'production' AFTER n11_shipment_template",
        'hepsiburada_developer_username' => "ALTER TABLE {$table_name} ADD COLUMN hepsiburada_developer_username VARCHAR(190) DEFAULT '' AFTER hepsiburada_environment",
        'hepsiburada_test_api_key' => "ALTER TABLE {$table_name} ADD COLUMN hepsiburada_test_api_key TEXT NULL AFTER hepsiburada_developer_username",
        'hepsiburada_test_api_secret' => "ALTER TABLE {$table_name} ADD COLUMN hepsiburada_test_api_secret TEXT NULL AFTER hepsiburada_test_api_key",
        'hepsiburada_test_seller_id' => "ALTER TABLE {$table_name} ADD COLUMN hepsiburada_test_seller_id VARCHAR(100) DEFAULT '' AFTER hepsiburada_test_api_secret",
    );

    foreach ($columns as $column => $sql) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", $column));
        if ($exists) {
            continue;
        }

        $result = $wpdb->query($sql);
        if (function_exists('multi_sync_debug_log')) {
            if ($result === false && !empty($wpdb->last_error)) {
                multi_sync_debug_log("Failed adding {$column} column: " . $wpdb->last_error);
            } else {
                multi_sync_debug_log("Added {$column} column to {$table_name}");
            }
        }
    }
}

/**
 * Ensure sync settings interval column exists for minute based stock scheduling.
 */
function multi_sync_ensure_settings_interval_column()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'multi_sync_settings';

    $interval_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", 'interval_minutes'));
    if ($interval_exists) {
        return;
    }

    $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN interval_minutes INT(11) DEFAULT 5 AFTER schedule");

    if (function_exists('multi_sync_debug_log')) {
        if ($result === false && !empty($wpdb->last_error)) {
            multi_sync_debug_log('Failed adding interval_minutes column: ' . $wpdb->last_error);
        } else {
            multi_sync_debug_log('Added interval_minutes column to ' . $table_name);
        }
    }
}

/**
 * Ensure stock automation mode column exists on sync settings table.
 */
function multi_sync_ensure_settings_stock_automation_mode_column()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'multi_sync_settings';

    $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", 'stock_automation_mode'));
    if ($column_exists) {
        return;
    }

    $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN stock_automation_mode VARCHAR(30) DEFAULT 'scheduled' AFTER sync_orders");

    if (function_exists('multi_sync_debug_log')) {
        if ($result === false && !empty($wpdb->last_error)) {
            multi_sync_debug_log('Failed adding stock_automation_mode column: ' . $wpdb->last_error);
        } else {
            multi_sync_debug_log('Added stock_automation_mode column to ' . $table_name);
        }
    }
}

/**
 * Ensure queue and change history tables exist.
 */
function multi_sync_ensure_queue_tables()
{
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();

    $jobs_table = $wpdb->prefix . 'multi_sync_jobs';
    $job_items_table = $wpdb->prefix . 'multi_sync_job_items';
    $history_table = $wpdb->prefix . 'multi_sync_change_history';

    $sql_jobs = "CREATE TABLE {$jobs_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        supplier_id bigint(20) NOT NULL,
        job_type varchar(50) NOT NULL,
        source varchar(30) DEFAULT 'manual',
        status varchar(50) NOT NULL DEFAULT 'queued',
        payload_json longtext,
        sync_stock tinyint(1) DEFAULT NULL,
        sync_price tinyint(1) DEFAULT NULL,
        stock_mode varchar(50) DEFAULT '',
        approval_required tinyint(1) DEFAULT 0,
        approval_reason text,
        approved_by bigint(20) DEFAULT NULL,
        approved_at datetime DEFAULT NULL,
        summary_json longtext,
        error_message longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        started_at datetime DEFAULT NULL,
        finished_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY supplier_id (supplier_id),
        KEY status (status),
        KEY job_type (job_type),
        KEY created_at (created_at)
    ) {$charset_collate};";

    $sql_job_items = "CREATE TABLE {$job_items_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        job_id bigint(20) NOT NULL,
        supplier_id bigint(20) NOT NULL,
        item_key varchar(255) NOT NULL,
        item_type varchar(50) DEFAULT 'sku',
        status varchar(50) DEFAULT 'queued',
        before_stock bigint(20) DEFAULT NULL,
        after_stock bigint(20) DEFAULT NULL,
        before_price decimal(18,4) DEFAULT NULL,
        after_price decimal(18,4) DEFAULT NULL,
        before_discount_price decimal(18,4) DEFAULT NULL,
        after_discount_price decimal(18,4) DEFAULT NULL,
        before_status varchar(100) DEFAULT '',
        after_status varchar(100) DEFAULT '',
        before_meta longtext,
        after_meta longtext,
        message text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY job_id (job_id),
        KEY supplier_id (supplier_id),
        KEY item_key (item_key)
    ) {$charset_collate};";

    $sql_history = "CREATE TABLE {$history_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        job_id bigint(20) NOT NULL,
        supplier_id bigint(20) NOT NULL,
        job_type varchar(50) NOT NULL,
        item_key varchar(255) NOT NULL,
        change_kind varchar(100) NOT NULL,
        before_value longtext,
        after_value longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY job_id (job_id),
        KEY supplier_id (supplier_id),
        KEY job_type (job_type),
        KEY created_at (created_at)
    ) {$charset_collate};";

    dbDelta($sql_jobs);
    dbDelta($sql_job_items);
    dbDelta($sql_history);
}

/**
 * Ensure marketplace question cache table exists.
 */
function multi_sync_ensure_marketplace_questions_table()
{
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'multi_sync_marketplace_questions';

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        supplier_id bigint(20) NOT NULL,
        marketplace_key varchar(100) NOT NULL,
        external_question_id varchar(255) NOT NULL,
        external_product_id varchar(255) DEFAULT '',
        product_name varchar(255) DEFAULT '',
        customer_name varchar(255) DEFAULT '',
        question_text longtext,
        answer_text longtext,
        status varchar(100) DEFAULT '',
        can_reply tinyint(1) DEFAULT 0,
        asked_at datetime DEFAULT NULL,
        answered_at datetime DEFAULT NULL,
        last_synced_at datetime DEFAULT CURRENT_TIMESTAMP,
        last_reply_error text,
        raw_payload longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_question (supplier_id, marketplace_key, external_question_id),
        KEY supplier_id (supplier_id),
        KEY marketplace_key (marketplace_key),
        KEY status (status),
        KEY updated_at (updated_at)
    ) {$charset_collate};";

    dbDelta($sql);
}

/**
 * Ensure queue settings option exists.
 */
function multi_sync_ensure_queue_settings()
{
    $settings = get_option('multi_sync_queue_settings', null);
    if (!is_array($settings)) {
        update_option('multi_sync_queue_settings', array(
            'suspicious_price_drop_percent' => 20,
        ));
        return;
    }

    if (!array_key_exists('suspicious_price_drop_percent', $settings)) {
        $settings['suspicious_price_drop_percent'] = 20;
        update_option('multi_sync_queue_settings', $settings);
    }
}

/**
 * Ensure predefined marketplace suppliers exist.
 */
function multi_sync_ensure_predefined_suppliers()
{
    $supplier_model = new \MultiSync\Models\Supplier();
    $supplier_model->ensure_predefined_suppliers();
}

/**
 * Backward-compatible helper for old calls.
 */
function multi_sync_ensure_trendyol_supplier()
{
    $supplier_model = new \MultiSync\Models\Supplier();
    $supplier_model->ensure_trendyol_supplier();
}

/**
 * Admin Menu & Assets
 */
add_action('admin_menu', 'multi_sync_admin_menu');
function multi_sync_admin_menu()
{
    add_menu_page(
        'Open Entegre',
        'Open Entegre',
        'manage_options',
        'multi-sync',
        'multi_sync_render_admin_page',
        'dashicons-update',
        56
    );

}

function multi_sync_render_admin_page()
{
    echo '<div id="multi-sync-admin-root">Uygulama yukleniyor...</div>';
}

add_action('admin_enqueue_scripts', 'multi_sync_enqueue_scripts');
function multi_sync_enqueue_scripts($hook)
{
    $page_param = '';
    if (isset($_GET['page'])) {
        $page_param = sanitize_key(wp_unslash((string) $_GET['page']));
    }

    $allowed_hooks = array(
        'toplevel_page_multi-sync',
    );
    $allowed_pages = array(
        'multi-sync',
    );

    if (
        !in_array((string) $hook, $allowed_hooks, true)
        && !in_array($page_param, $allowed_pages, true)
    ) {
        return;
    }

    // React build assets
    // Assuming Vite build outputs to admin-ui/build/assets
    // We will need a dynamic way to find the hashed filenames or just standard `index.js` if configured so.
    // For now, let's placeholder. In React implementation step we will fix paths.

    wp_enqueue_script('multi-sync-js', MULTI_SYNC_PLUGIN_URL . 'admin-ui/build/assets/main.js', array(), MULTI_SYNC_VERSION, true);
    wp_enqueue_style('multi-sync-css', MULTI_SYNC_PLUGIN_URL . 'admin-ui/build/assets/main.css', array(), MULTI_SYNC_VERSION);

    wp_localize_script('multi-sync-js', 'multiSyncSettings', array(
        'root' => esc_url_raw(rest_url()),
        'nonce' => wp_create_nonce('wp_rest'),
        'pluginUrl' => esc_url_raw(MULTI_SYNC_PLUGIN_URL),
        'version' => MULTI_SYNC_VERSION,
        'iconsVersion' => multi_sync_get_icon_cache_version(),
        'updateUrl' => add_query_arg(array(
            'action' => 'multi_sync_update',
            '_wpnonce' => wp_create_nonce('multi_sync_update'),
        ), admin_url('admin-post.php')),
        'updateStatus' => isset($_GET['multi_sync_update'])
            ? sanitize_key(wp_unslash((string) $_GET['multi_sync_update']))
            : '',
    ));
}

/**
 * Build a version string based on latest icon file mtime for cache busting.
 */
function multi_sync_get_icon_cache_version()
{
    $icons_dir = trailingslashit(MULTI_SYNC_PLUGIN_DIR . 'icons');
    if (!is_dir($icons_dir)) {
        return (string) MULTI_SYNC_VERSION;
    }

    $latest_mtime = 0;
    $files = glob($icons_dir . '*');
    if (is_array($files)) {
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false) {
                $latest_mtime = max($latest_mtime, (int) $mtime);
            }
        }
    }

    if ($latest_mtime <= 0) {
        return (string) MULTI_SYNC_VERSION;
    }

    return (string) $latest_mtime;
}

/**
 * Register REST API (Placeholder for now)
 */
add_action('rest_api_init', function () {
    $api = new \MultiSync\Api\RestApi();
    $api->register_routes();
});

add_action('plugins_loaded', 'multi_sync_boot_schedulers', 20);
add_action('multi_sync_ciceksepeti_batch_poll', array('MultiSync\Sync\ProductPublisher', 'ciceksepeti_batch_poll_cron'), 10, 3);
add_action('admin_notices', 'multi_sync_show_ciceksepeti_batch_errors');
function multi_sync_boot_schedulers()
{
    if (class_exists('\MultiSync\Sync\StockScheduler')) {
        \MultiSync\Sync\StockScheduler::init();
    }

    if (class_exists('\MultiSync\Sync\OrderScheduler')) {
        \MultiSync\Sync\OrderScheduler::init();
    }

    if (class_exists('\MultiSync\Sync\JobWorker')) {
        \MultiSync\Sync\JobWorker::init();
    }

    if (class_exists('\MultiSync\Sync\StockEventDispatcher')) {
        \MultiSync\Sync\StockEventDispatcher::init();
    }

    if (class_exists('\MultiSync\Sync\QuestionSync')) {
        \MultiSync\Sync\QuestionSync::init();
    }
}

add_filter('script_loader_tag', 'multi_sync_add_type_attribute', 10, 3);
function multi_sync_add_type_attribute($tag, $handle, $src)
{
    if ('multi-sync-js' !== $handle) {
        return $tag;
    }
    return '<script type="module" src="' . esc_url($src) . '"></script>';
}

/**
 * Custom Order Columns: Remove "Source/Origin" and add "Pazar Yeri"
 * Supports both classic (CPT) and HPOS order storage
 * Priority 99 ensures this runs AFTER WooCommerce adds the Origin column
 */
// Classic CPT-based orders
add_filter('manage_edit-shop_order_columns', 'multi_sync_custom_order_columns', 99);
// HPOS-based orders
add_filter('manage_woocommerce_page_wc-orders_columns', 'multi_sync_custom_order_columns', 99);

function multi_sync_custom_order_columns($columns)
{
    // Remove "Source" and "Origin" columns (all possible variations)
    unset($columns['source']);
    unset($columns['order_source']);
    unset($columns['origin']);
    unset($columns['order_origin']);
    unset($columns['wc_actions']); // Sometimes origin is part of actions

    // Debug: Log available column keys to find the correct one
    multi_sync_debug_log('Available order columns: ' . implode(', ', array_keys($columns)));

    // Add "Pazar Yeri" column after order_status
    $new_columns = array();
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        if ($key === 'order_status') {
            $new_columns['multi_sync_marketplace'] = 'Pazar Yeri';
        }
    }

    // If order_status wasn't found, add at the end
    if (!isset($new_columns['multi_sync_marketplace'])) {
        $new_columns['multi_sync_marketplace'] = 'Pazar Yeri';
    }

    return $new_columns;
}

// Classic CPT-based orders
add_action('manage_shop_order_posts_custom_column', 'multi_sync_custom_order_column_content', 10, 2);

function multi_sync_custom_order_column_content($column, $post_id)
{
    if ($column === 'multi_sync_marketplace') {
        $order = wc_get_order($post_id);
        multi_sync_render_marketplace_column($order);
    }
}

// HPOS-based orders
add_action('manage_woocommerce_page_wc-orders_custom_column', 'multi_sync_hpos_order_column_content', 10, 2);

function multi_sync_hpos_order_column_content($column, $order)
{
    if ($column === 'multi_sync_marketplace') {
        multi_sync_render_marketplace_column($order);
    }
}

/**
 * Render the Pazar Yeri column content with supplier color
 */
function multi_sync_render_marketplace_column($order)
{
    if (!$order) {
        echo '-';
        return;
    }

    // Try get_created_via() method first (most reliable)
    $created_via = '';
    if (is_callable(array($order, 'get_created_via'))) {
        $created_via = $order->get_created_via();
    }

    // Fallback to meta
    if (empty($created_via)) {
        $created_via = $order->get_meta('_created_via', true);
    }

    if (empty($created_via)) {
        echo '-';
        return;
    }

    // Prefer explicit supplier_id stored on the order (added during import after this patch)
    $supplier_id = $order->get_meta('_multi_sync_supplier_id', true);
    $supplier_id = is_numeric($supplier_id) ? (int) $supplier_id : null;

    $supplier_visual = multi_sync_get_supplier_visual($created_via, $supplier_id);
    if (!$supplier_visual || !is_array($supplier_visual)) {
        echo esc_html($created_via);
        return;
    }

    $display_name = isset($supplier_visual['display_name']) && $supplier_visual['display_name'] !== ''
        ? (string) $supplier_visual['display_name']
        : (string) $created_via;
    $supplier_color = isset($supplier_visual['color']) ? sanitize_hex_color($supplier_visual['color']) : '';
    if (empty($supplier_color)) {
        $supplier_color = '#5a6b82';
    }
    $marketplace_key = isset($supplier_visual['marketplace_key'])
        ? sanitize_key((string) $supplier_visual['marketplace_key'])
        : '';
    $marketplace_key = multi_sync_resolve_marketplace_key($marketplace_key, $display_name, $created_via);

    $text_color = multi_sync_get_contrast_color($supplier_color);
    if ($marketplace_key === 'trendyol') {
        $text_color = '#ffffff';
    }

    $icon_url = multi_sync_get_marketplace_icon_url($marketplace_key);
    $fallback_abbr = multi_sync_get_supplier_abbreviation($display_name);

    $icon_html = '';
    if (!empty($icon_url)) {
        $icon_html = sprintf(
            '<img class="multi-sync-marketplace-badge__icon-image" src="%s" alt="" loading="lazy" />',
            esc_url($icon_url)
        );
    } else {
        $icon_html = sprintf(
            '<span class="multi-sync-marketplace-badge__icon-fallback">%s</span>',
            esc_html($fallback_abbr)
        );
    }

    printf(
        '<span class="multi-sync-marketplace-badge" style="--ms-badge-bg:%1$s;--ms-badge-fg:%2$s;"><span class="multi-sync-marketplace-badge__icon">%3$s</span><span class="multi-sync-marketplace-badge__label">%4$s</span></span>',
        esc_attr($supplier_color),
        esc_attr($text_color),
        $icon_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        esc_html($display_name)
    );
}

/**
 * Get supplier visual data by name or created_via value.
 */
function multi_sync_get_supplier_visual($created_via, $supplier_id = null)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'multi_sync_suppliers';

    // Ensure column exists so lookups don't silently fail on older installs
    if (function_exists('multi_sync_ensure_supplier_color_column')) {
        multi_sync_ensure_supplier_color_column();
    }

    // If we have a supplier_id, trust it first (avoids string matching ambiguity)
    if (!empty($supplier_id) && is_numeric($supplier_id)) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, color, marketplace_key FROM {$table_name} WHERE id = %d LIMIT 1",
            (int) $supplier_id
        ));

        if ($row) {
            return array(
                'display_name' => !empty($row->name) ? (string) $row->name : (string) $created_via,
                'color' => !empty($row->color) ? (string) $row->color : '',
                'marketplace_key' => !empty($row->marketplace_key) ? (string) $row->marketplace_key : '',
            );
        }
    }

    // First try exact match by name (case-insensitive)
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, color, marketplace_key FROM {$table_name} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
        $created_via
    ));

    // If not found, try partial match (supplier name might be contained in created_via or vice versa)
    if (!$row) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, color, marketplace_key FROM {$table_name} WHERE LOWER(%s) LIKE CONCAT('%%', LOWER(name), '%%') OR LOWER(name) LIKE CONCAT('%%', LOWER(%s), '%%') LIMIT 1",
            $created_via,
            $created_via
        ));
    }

    if ($row) {
        return array(
            'display_name' => !empty($row->name) ? (string) $row->name : (string) $created_via,
            'color' => !empty($row->color) ? (string) $row->color : '',
            'marketplace_key' => !empty($row->marketplace_key) ? (string) $row->marketplace_key : '',
        );
    }

    return null;
}

/**
 * Backward-compatible helper.
 */
function multi_sync_get_supplier_color($created_via, $supplier_id = null)
{
    $visual = multi_sync_get_supplier_visual($created_via, $supplier_id);
    if (!is_array($visual) || empty($visual['color'])) {
        return null;
    }

    return (string) $visual['color'];
}

/**
 * Get marketplace icon URL for order list badges.
 */
function multi_sync_get_marketplace_icon_url($marketplace_key)
{
    $marketplace_key = sanitize_key((string) $marketplace_key);
    $file_map = array(
        'trendyol' => 'trendyol.png',
        'n11' => 'n11.png',
        'pazarama' => 'pazarama.png',
        'ciceksepeti' => 'ciceksepeti.png',
        'amazon' => 'amazon.jpg',
        'pttavm' => 'ptt.png',
    );

    if (!isset($file_map[$marketplace_key])) {
        return '';
    }

    $file_name = $file_map[$marketplace_key];
    $file_path = MULTI_SYNC_PLUGIN_DIR . 'icons/' . $file_name;
    if (!file_exists($file_path)) {
        return '';
    }
    $url = MULTI_SYNC_PLUGIN_URL . 'icons/' . $file_name;
    $mtime = @filemtime($file_path);
    if ($mtime !== false) {
        return add_query_arg('v', (string) $mtime, $url);
    }

    return $url;
}

/**
 * Resolve marketplace key from persisted key + visible labels to avoid wrong icon fallbacks.
 */
function multi_sync_resolve_marketplace_key($marketplace_key, $display_name = '', $created_via = '')
{
    $marketplace_key = sanitize_key((string) $marketplace_key);

    $from_display_name = multi_sync_guess_marketplace_key_from_text($display_name);
    if ($from_display_name !== '') {
        return $from_display_name;
    }

    $from_created_via = multi_sync_guess_marketplace_key_from_text($created_via);
    if ($from_created_via !== '') {
        return $from_created_via;
    }

    return $marketplace_key;
}

/**
 * Guess marketplace key from supplier label or created_via text.
 */
function multi_sync_guess_marketplace_key_from_text($text)
{
    $text = strtolower(trim((string) $text));
    if ($text === '') {
        return '';
    }

    $replace_map = array(
        'ç' => 'c',
        'ğ' => 'g',
        'ı' => 'i',
        'İ' => 'i',
        'ö' => 'o',
        'ş' => 's',
        'ü' => 'u',
    );
    $normalized = strtr($text, $replace_map);

    $patterns = array(
        'trendyol' => array('trendyol'),
        'n11' => array('n11'),
        'pazarama' => array('pazarama'),
        'ciceksepeti' => array('ciceksepeti', 'cicek sepeti'),
        'amazon' => array('amazon'),
        'pttavm' => array('pttavm', 'ptt avm', 'ptt'),
    );

    foreach ($patterns as $marketplace_key => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                return $marketplace_key;
            }
        }
    }

    return '';
}

/**
 * Build short abbreviation for fallback marketplace badge icon.
 */
function multi_sync_get_supplier_abbreviation($text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return 'MS';
    }

    $parts = preg_split('/\s+/', $text);
    if (is_array($parts) && count($parts) >= 2) {
        $a = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
        $b = function_exists('mb_substr') ? mb_substr($parts[1], 0, 1, 'UTF-8') : substr($parts[1], 0, 1);
        return strtoupper($a . $b);
    }

    $abbr = function_exists('mb_substr')
        ? mb_substr($text, 0, 2, 'UTF-8')
        : substr($text, 0, 2);
    return strtoupper($abbr);
}

/**
 * Get contrasting text color (black or white) based on background color
 */
function multi_sync_get_contrast_color($hex_color)
{
    // Remove # if present
    $hex = ltrim($hex_color, '#');

    // Convert to RGB
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    // Calculate luminance
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

    return $luminance > 0.5 ? '#000000' : '#ffffff';
}

add_action('admin_head', 'multi_sync_print_order_marketplace_badge_styles');
function multi_sync_print_order_marketplace_badge_styles()
{
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !isset($screen->id)) {
        return;
    }

    $allowed_ids = array('edit-shop_order', 'woocommerce_page_wc-orders');
    if (!in_array((string) $screen->id, $allowed_ids, true)) {
        return;
    }

    echo '<style>
        .multi-sync-marketplace-badge{
            --ms-badge-bg:#5a6b82;
            --ms-badge-fg:#ffffff;
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:5px 10px 5px 5px;
            border-radius:999px;
            background:var(--ms-badge-bg);
            color:var(--ms-badge-fg);
            font-size:12px;
            font-weight:600;
            line-height:1;
            white-space:nowrap;
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.12), 0 1px 2px rgba(15,23,42,.14);
        }
        .multi-sync-marketplace-badge__icon{
            width:22px;
            height:22px;
            border-radius:999px;
            background:rgba(255,255,255,.18);
            display:inline-flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
            flex:0 0 auto;
            border:1px solid rgba(255,255,255,.25);
        }
        .multi-sync-marketplace-badge__icon-image{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .multi-sync-marketplace-badge__icon-fallback{
            font-size:10px;
            font-weight:700;
            letter-spacing:.2px;
            text-transform:uppercase;
        }
        .multi-sync-marketplace-badge__label{
            max-width:160px;
            overflow:hidden;
            text-overflow:ellipsis;
        }
    </style>';
}

/**
 * Register Custom Order Statuses
 */
add_action('init', 'multi_sync_register_custom_statuses');
function multi_sync_register_custom_statuses()
{
    $statuses = get_option('multi_sync_custom_statuses', array());

    if (!empty($statuses) && is_array($statuses)) {
        foreach ($statuses as $status) {
            $slug = isset($status['slug']) ? $status['slug'] : '';
            $label = isset($status['label']) ? $status['label'] : '';

            if ($slug && $label) {
                register_post_status($slug, array(
                    'label' => $label,
                    'public' => true,
                    'exclude_from_search' => false,
                    'show_in_admin_all_list' => true,
                    'show_in_admin_status_list' => true,
                    'label_count' => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>')
                ));
            }
        }
    }
}

add_filter('wc_order_statuses', 'multi_sync_add_custom_statuses_to_wc');
function multi_sync_add_custom_statuses_to_wc($order_statuses)
{
    $statuses = get_option('multi_sync_custom_statuses', array());

    if (!empty($statuses) && is_array($statuses)) {
        foreach ($statuses as $status) {
            $slug = isset($status['slug']) ? $status['slug'] : '';
            $label = isset($status['label']) ? $status['label'] : '';

            if ($slug && $label) {
                $order_statuses[$slug] = $label;
            }
        }
    }
    return $order_statuses;
}

/**
 * Product-level marketplace commission overrides.
 */
function multi_sync_get_marketplace_labels()
{
    $labels = array();
    $manager = new \MultiSync\Marketplaces\MarketplaceManager();

    foreach ($manager->all() as $marketplace) {
        $labels[$marketplace->get_key()] = $marketplace->get_label();
    }

    return $labels;
}

function multi_sync_get_product_commission_rates($product)
{
    $rates = $product ? $product->get_meta('_multi_sync_commission_rates', true) : array();
    return is_array($rates) ? $rates : array();
}

function multi_sync_get_product_vat_rate($product)
{
    if (!$product) return '';
    $rate = trim((string) $product->get_meta('_multi_sync_vat_rate', true));
    if ($rate !== '') return $rate;
    foreach ((array) $product->get_meta('_multi_sync_vat_rates', true) as $legacy_rate) {
        if (in_array((string) $legacy_rate, array('0', '1', '10', '20'), true)) return (string) $legacy_rate;
    }
    return '';
}

function multi_sync_save_product_commission_rates($product)
{
    $rates = array();
    $submitted = false;

    foreach (multi_sync_get_marketplace_labels() as $key => $label) {
        $field = 'multi_sync_commission_rate_' . $key;
        if (!isset($_POST[$field])) {
            continue;
        }

        $submitted = true;
        $value = trim((string) wp_unslash($_POST[$field]));
        if ($value === '') {
            continue;
        }

        $value = wc_format_decimal($value);
        if (is_numeric($value)) {
            $rates[$key] = min(100, max(0, (float) $value));
        }
    }

    if (!$submitted) {
        return;
    }

    if ($rates) {
        $product->update_meta_data('_multi_sync_commission_rates', $rates);
    } else {
        $product->delete_meta_data('_multi_sync_commission_rates');
    }
}

add_action('woocommerce_product_options_general_product_data', 'multi_sync_render_product_commission_rates');
function multi_sync_render_product_commission_rates()
{
    global $product_object;
    $rates = multi_sync_get_product_commission_rates($product_object);

    echo '<div class="options_group"><p class="form-field"><strong>'
        . esc_html__('Marketplace commission rates', 'multi-sync')
        . '</strong><br><span class="description">'
        . esc_html__('Leave blank to use the marketplace default rate.', 'multi-sync')
        . '</span></p>';

    foreach (multi_sync_get_marketplace_labels() as $key => $label) {
        woocommerce_wp_text_input(array(
            'id' => 'multi_sync_commission_rate_' . $key,
            'label' => $label . ' (%)',
            'type' => 'number',
            'value' => array_key_exists($key, $rates) ? $rates[$key] : '',
            'custom_attributes' => array('min' => '0', 'max' => '100', 'step' => '0.01'),
        ));
    }

    echo '</div>';
}

add_action('woocommerce_admin_process_product_object', 'multi_sync_save_product_commission_rates');

add_action('woocommerce_product_options_general_product_data', 'multi_sync_render_product_vat_rates');
function multi_sync_render_product_vat_rates()
{
    global $product_object;
    echo '<div class="options_group">';
    woocommerce_wp_select(array(
        'id' => 'multi_sync_vat_rate',
        'label' => 'KDV oranı',
        'description' => 'Tüm pazar yerlerinde kullanılır. Varyasyon boşsa ana ürünün oranını kullanır.',
        'value' => multi_sync_get_product_vat_rate($product_object),
        'options' => array('' => 'Seçin', '0' => '%0', '1' => '%1', '10' => '%10', '20' => '%20'),
    ));
    echo '</div>';
}

add_action('woocommerce_admin_process_product_object', 'multi_sync_save_product_vat_rates');
function multi_sync_save_product_vat_rates($product)
{
    if (!isset($_POST['multi_sync_vat_rate'])) return;
    $value = trim((string) wp_unslash($_POST['multi_sync_vat_rate']));
    if (in_array($value, array('0', '1', '10', '20'), true)) $product->update_meta_data('_multi_sync_vat_rate', $value);
    else $product->delete_meta_data('_multi_sync_vat_rate');
    $product->delete_meta_data('_multi_sync_vat_rates');
    $product->delete_meta_data('_multi_sync_trendyol_vat_rate');
}

add_action('woocommerce_product_options_general_product_data', 'multi_sync_render_trendyol_product_fields');
function multi_sync_render_trendyol_product_fields()
{
    echo '<div class="options_group"><p class="form-field"><strong>Trendyol ürün gönderimi</strong></p>';
    foreach (array(
        'barcode' => array('Trendyol Barkod', 'text'),
        'product_main_id' => array('Trendyol Model Kodu', 'text'),
        'dimensional_weight' => array('Desi (opsiyonel)', 'number'),
    ) as $key => $field) {
        woocommerce_wp_text_input(array('id' => '_multi_sync_trendyol_' . $key, 'label' => $field[0], 'type' => $field[1]));
    }
    woocommerce_wp_textarea_input(array(
        'id' => '_multi_sync_trendyol_attributes',
        'label' => 'Trendyol Nitelikleri (JSON)',
        'description' => 'Örn: [{"attributeId":338,"attributeValueId":6980}]',
    ));
    echo '</div>';
}

add_action('woocommerce_admin_process_product_object', 'multi_sync_save_trendyol_product_fields');
function multi_sync_save_trendyol_product_fields($product)
{
    foreach (array('barcode', 'product_main_id', 'brand_id', 'category_id', 'dimensional_weight', 'attributes') as $key) {
        $field = '_multi_sync_trendyol_' . $key;
        if (!isset($_POST[$field])) continue;
        $value = trim((string) wp_unslash($_POST[$field]));
        if ($value === '') {
            $product->delete_meta_data($field);
        } else {
            $product->update_meta_data($field, $key === 'attributes' ? wp_kses_post($value) : sanitize_text_field($value));
        }
    }
}
add_action('woocommerce_product_quick_edit_save', 'multi_sync_save_product_commission_rates_quick_edit');
function multi_sync_save_product_commission_rates_quick_edit($product)
{
    multi_sync_save_product_commission_rates($product);
    if (isset($_POST['multi_sync_vat_rate']) && trim((string) wp_unslash($_POST['multi_sync_vat_rate'])) !== '') {
        multi_sync_save_product_vat_rates($product);
    }
    $product->save_meta_data();
}

add_filter('manage_edit-product_columns', 'multi_sync_add_product_commission_column');
function multi_sync_add_product_commission_column($columns)
{
    $columns['multi_sync_commissions'] = __('Commissions', 'multi-sync');
    $columns['multi_sync_vat'] = __('KDV', 'multi-sync');
    return $columns;
}

add_action('manage_product_posts_custom_column', 'multi_sync_render_product_commission_column', 10, 2);
function multi_sync_render_product_commission_column($column, $post_id)
{
    if ($column !== 'multi_sync_commissions') {
        return;
    }

    $rates = multi_sync_get_product_commission_rates(wc_get_product($post_id));
    $display = array();
    foreach (multi_sync_get_marketplace_labels() as $key => $label) {
        if (array_key_exists($key, $rates)) {
            $display[] = $label . ': ' . $rates[$key] . '%';
        }
    }

    echo $display ? esc_html(implode(', ', $display)) : '&mdash;';
    echo '<span class="multi-sync-commission-data" data-rates="'
        . esc_attr(wp_json_encode($rates)) . '" hidden></span>';
}

add_action('manage_product_posts_custom_column', 'multi_sync_render_product_vat_column', 10, 2);
function multi_sync_render_product_vat_column($column, $post_id)
{
    if ($column !== 'multi_sync_vat') {
        return;
    }
    $rate = multi_sync_get_product_vat_rate(wc_get_product($post_id));
    $label = in_array($rate, array('0', '1', '10', '20'), true) ? '%' . $rate : '&mdash;';
    echo esc_html($label);
    echo '<span class="multi-sync-vat-data" data-vat="' . esc_attr((string) $rate) . '" hidden></span>';
}

add_action('quick_edit_custom_box', 'multi_sync_render_product_commission_quick_edit', 10, 2);
function multi_sync_render_product_commission_quick_edit($column, $post_type)
{
    if ($column !== 'multi_sync_commissions' || $post_type !== 'product') {
        return;
    }
    ?>
    <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <span class="title"><?php esc_html_e('Marketplace commission rates', 'multi-sync'); ?></span>
            <?php foreach (multi_sync_get_marketplace_labels() as $key => $label) : ?>
                <label>
                    <span class="title"><?php echo esc_html($label); ?> (%)</span>
                    <span class="input-text-wrap">
                        <input type="number" name="multi_sync_commission_rate_<?php echo esc_attr($key); ?>" min="0" max="100" step="0.01">
                    </span>
                </label>
            <?php endforeach; ?>
            <label>
                <span class="title">KDV oranı</span>
                <span class="input-text-wrap">
                    <select name="multi_sync_vat_rate">
                        <option value=""><?php esc_html_e('No change', 'multi-sync'); ?></option>
                        <option value="0">%0</option>
                        <option value="1">%1</option>
                        <option value="10">%10</option>
                        <option value="20">%20</option>
                    </select>
                </span>
            </label>
        </div>
    </fieldset>
    <?php
}

add_action('admin_footer-edit.php', 'multi_sync_product_commission_quick_edit_script');
function multi_sync_product_commission_quick_edit_script()
{
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'product') {
        return;
    }
    ?>
    <script>
    jQuery(function ($) {
        const edit = inlineEditPost.edit;
        inlineEditPost.edit = function (id) {
            edit.apply(this, arguments);
            const postId = typeof id === 'object' ? parseInt(this.getId(id), 10) : parseInt(id, 10);
            const rates = JSON.parse($('#post-' + postId + ' .multi-sync-commission-data').attr('data-rates') || '{}');
            const row = $('#edit-' + postId);

            <?php foreach (multi_sync_get_marketplace_labels() as $key => $label) : ?>
            row.find('[name="multi_sync_commission_rate_<?php echo esc_js($key); ?>"]').val(
                Object.prototype.hasOwnProperty.call(rates, '<?php echo esc_js($key); ?>') ? rates['<?php echo esc_js($key); ?>'] : ''
            );
            <?php endforeach; ?>
            const vat = $('#post-' + postId + ' .multi-sync-vat-data').attr('data-vat') || '';
            row.find('[name="multi_sync_vat_rate"]').val(vat);
        };
    });
    </script>
    <?php
}
// KDV bulk edit
require_once MULTI_SYNC_PLUGIN_DIR . 'includes/ui/vat-bulk-edit.php';
