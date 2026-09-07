<?php
// Run with PHP: php tests/configuration-backup-test.php
namespace MultiSync\Sync {
    class StockScheduler { public static $ids = array(); public static function sync_supplier_schedule($id) { self::$ids[] = $id; } }
    class OrderScheduler extends StockScheduler {}
}
namespace {
    define('ABSPATH', __DIR__);
    class WP_Error {
        public $message;
        public function __construct($code, $message, $data = array()) { $this->message = $message; }
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
    function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
    function get_object_taxonomies($type) { return array('product_cat', 'product_brand'); }
    function get_term($id, $taxonomy) { return $GLOBALS['terms'][$taxonomy][$id] ?? false; }
    function get_term_by($field, $slug, $taxonomy) {
        foreach ($GLOBALS['terms'][$taxonomy] ?? array() as $term) if ($term->slug === $slug) return $term;
        return false;
    }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) {
        if ($key === ($GLOBALS['fail_option'] ?? null)) return false;
        $GLOBALS['options'][$key] = $value;
        return true;
    }
    function wp_cache_delete($key, $group) {}
    function current_user_can($capability) { return $GLOBALS['admin'] ?? false; }
    function register_rest_route($namespace, $path, $config) { $GLOBALS['routes'][$path] = $config; }
    function rest_ensure_response($value) { return $value; }
    function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
    class BackupDatabase {
        public $prefix = 'wp_';
        public $insert_id = 0;
        public $suppliers = array();
        public $settings = array();
        public $transactions = 0;
        public $engine = 'InnoDB';
        private $snapshot;
        public function prepare($sql, ...$args) {
            foreach ($args as $arg) $sql = preg_replace_callback('/%[sd]/', function () use ($arg) { return "'" . $arg . "'"; }, $sql, 1);
            return $sql;
        }
        public function get_results($sql) { return array_map(function ($row) { return (object) $row; }, array_values($this->suppliers)); }
        public function get_row($sql) {
            if (strpos($sql, 'multi_sync_settings') !== false) {
                preg_match("/supplier_id = '(\d+)'/", $sql, $match);
                return isset($this->settings[$match[1]]) ? (object) $this->settings[$match[1]] : null;
            }
            preg_match("/marketplace_key = '([^']+)'/", $sql, $match);
            foreach ($this->suppliers as $row) if ($row['marketplace_key'] === ($match[1] ?? '')) return (object) $row;
            return null;
        }
        public function get_var($sql) { return $this->engine; }
        public function query($sql) {
            if ($sql === 'START TRANSACTION') {
                $this->transactions++;
                $this->snapshot = array($this->suppliers, $this->settings, $GLOBALS['options']);
            }
            if ($sql === 'ROLLBACK') list($this->suppliers, $this->settings, $GLOBALS['options']) = $this->snapshot;
            return 1;
        }
        public function update($table, $data, $where, ...$formats) {
            if ($table === 'wp_multi_sync_suppliers') $this->suppliers[$where['id']] = array_merge($this->suppliers[$where['id']], $data);
            else $this->settings[$where['supplier_id']] = array_merge($this->settings[$where['supplier_id']], $data);
            return 1;
        }
        public function insert($table, $data, ...$formats) {
            if ($table === 'wp_multi_sync_suppliers') {
                $this->insert_id = max(array_keys($this->suppliers) ?: array(0)) + 1;
                $this->suppliers[$this->insert_id] = array_merge($data, array('id' => $this->insert_id));
            } else $this->settings[$data['supplier_id']] = $data;
            return 1;
        }
    }
    require __DIR__ . '/../includes/models/Supplier.php';
    require __DIR__ . '/../includes/models/SyncSettings.php';
    require __DIR__ . '/../includes/models/ConfigurationBackup.php';
    require __DIR__ . '/../includes/api/RestApi.php';

    $wpdb = new BackupDatabase();
    $options = array();
    $terms = array('product_cat' => array(10 => (object) array('term_id' => 10, 'slug' => 'elbise')), 'product_brand' => array(20 => (object) array('term_id' => 20, 'slug' => 'marka')));
    $wpdb->suppliers[1] = array('id' => 1, 'name' => 'Hepsiburada', 'marketplace_key' => 'hepsiburada', 'api_key' => 'key', 'api_secret' => 'secret\\with"quotes', 'hepsiburada_test_api_key' => 'test-key', 'hepsiburada_environment' => 'test', 'active' => '1', 'commission_rate' => '5', 'created_at' => 'yesterday');
    $wpdb->settings[1] = array('supplier_id' => 1, 'sync_stock' => '1', 'sync_orders' => '0', 'schedule' => 'hourly', 'stock_automation_mode' => 'scheduled', 'interval_minutes' => '5');
    $options['multi_sync_category_mappings_1'] = array(10 => array('category_id' => 'cat-prod', 'commission_rate' => 12, 'attributes' => array(array('attributeId' => 'size', 'attributeValueIds' => array('small')))));
    $options['multi_sync_category_mappings_1_test'] = array(10 => array('category_id' => 'cat-test'));
    $options['multi_sync_trendyol_category_mappings_1'] = array(10 => array('category_id' => 'cat-legacy'));
    $options['multi_sync_brand_mappings_1'] = array('product_brand:20' => array('brand_id' => 'brand-prod', 'brand_name' => 'Marka'));
    $options['multi_sync_brand_mappings_1_test'] = array('product_brand:20' => array('brand_id' => 'brand-test'));
    $options['multi_sync_queue_settings'] = array('suspicious_price_drop_percent' => 20);
    $options['multi_sync_custom_statuses'] = array(array('slug' => 'wc-delivered', 'label' => 'Teslim edildi'));
    $options['unrelated_option'] = 'keep';
    $wpdb->suppliers[2] = array('id' => 2, 'name' => 'Old custom shop', 'marketplace_key' => 'old_custom', 'api_key' => 'legacy-key');
    $wpdb->suppliers[3] = array_merge($wpdb->suppliers[1], array('id' => 3, 'api_key' => 'unused-duplicate'));
    $service = new \MultiSync\Models\ConfigurationBackup();
    $backup = json_decode(json_encode($service->export()), true);
    check(count($backup['suppliers']) === 1 && $backup['suppliers'][0]['supplier']['api_key'] === 'key', 'Export must match the panel account and omit legacy/custom rows.');
    check(isset($wpdb->suppliers[2], $wpdb->suppliers[3]), 'Export must leave legacy database rows untouched.');
    check(!isset($backup['suppliers'][0]['supplier']['id']) && !isset($backup['suppliers'][0]['settings']['supplier_id']), 'Source database IDs must not be exported.');
    check(!isset($backup['options']['unrelated_option']), 'Unrelated options must not be exported.');
    check($backup['suppliers'][0]['mappings']['categories'][0]['slug'] === 'elbise', 'Categories must use portable slugs.');

    // A different site's IDs, existing unrelated mapping, and old credentials.
    $wpdb->suppliers = array(42 => array('id' => 42, 'name' => 'Old', 'marketplace_key' => 'hepsiburada', 'api_key' => 'old'));
    $wpdb->settings = array();
    $terms['product_cat'] = array(110 => (object) array('term_id' => 110, 'slug' => 'elbise'));
    $terms['product_cat'][999] = (object) array('term_id' => 999, 'slug' => 'existing-category');
    $terms['product_brand'] = array(120 => (object) array('term_id' => 120, 'slug' => 'marka'));
    $options = array('multi_sync_category_mappings_42' => array(999 => array('category_id' => 'keep')), 'unrelated_option' => 'keep');
    $result = $service->import($backup);
    check(!is_wp_error($result), 'Valid backup should import.');
    check($wpdb->suppliers[42]['api_secret'] === $backup['suppliers'][0]['supplier']['api_secret'], 'Credentials must round trip exactly.');
    check($wpdb->suppliers[42]['hepsiburada_test_api_key'] === 'test-key', 'Test credentials must be restored.');
    check($options['multi_sync_category_mappings_42'][110]['category_id'] === 'cat-prod' && isset($options['multi_sync_category_mappings_42'][999]), 'Remap term IDs and preserve unrelated mappings.');
    check($options['multi_sync_category_mappings_42_test'][110]['category_id'] === 'cat-test', 'Test mappings must remain isolated.');
    check($options['multi_sync_trendyol_category_mappings_42'][110]['category_id'] === 'cat-legacy', 'Legacy mappings must transfer.');
    check($options['multi_sync_brand_mappings_42']['product_brand:120']['brand_id'] === 'brand-prod', 'Brand IDs must be remapped.');
    check($wpdb->settings[42]['schedule'] === 'hourly' && $wpdb->settings[42]['sync_price'] === 0, 'Settings must use existing normalization.');
    check($options['unrelated_option'] === 'keep', 'Unrelated options must remain untouched.');
    check(!is_wp_error($service->import($backup)) && count($wpdb->suppliers) === 1, 'Repeated imports must not duplicate suppliers.');

    foreach (array('', null, 'production', 'live', 'test', 'missing') as $environment) {
        $legacy_environment = $backup;
        $legacy_environment['suppliers'][0]['supplier']['hepsiburada_environment'] = $environment;
        if ($environment === 'missing') unset($legacy_environment['suppliers'][0]['supplier']['hepsiburada_environment']);
        check(!is_wp_error($service->import($legacy_environment)), 'Legacy environment values must follow the existing adapter behavior.');
        check($wpdb->suppliers[42]['hepsiburada_environment'] === ($environment === 'test' ? 'test' : 'production'), 'Only test must select the test environment; legacy values default to production.');
        check($wpdb->suppliers[42]['api_secret'] === $backup['suppliers'][0]['supplier']['api_secret'] && $wpdb->suppliers[42]['hepsiburada_test_api_key'] === 'test-key', 'Environment normalization must preserve both credential sets.');
    }

    $invalid_cases = array();
    $bad = $backup; $bad['version'] = 99; $invalid_cases[] = $bad;
    $bad = $backup; $bad['options']['admin_email'] = 'evil'; $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['supplier']['id'] = 99; $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['supplier']['api_key'] = array('nested'); $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['supplier']['hepsiburada_environment'] = array('test'); $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['supplier']['seller_id'] = str_repeat('x', 101); $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['mappings']['categories'][0]['value']['category_name'] = array('nested'); $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['mappings']['categories'][0]['value']['attributes'][0]['attributeValueIds'] = 'invalid'; $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['settings']['schedule'] = 'invalid'; $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][0]['mappings']['categories'][0]['slug'] = 'missing'; $invalid_cases[] = $bad;
    $bad = $backup; $bad['suppliers'][] = $bad['suppliers'][0]; $invalid_cases[] = $bad;
    $before = array($wpdb->suppliers, $wpdb->settings, $options, $wpdb->transactions);
    foreach ($invalid_cases as $bad) {
        check(is_wp_error($service->import($bad)), 'Invalid backup must be rejected.');
        check($before === array($wpdb->suppliers, $wpdb->settings, $options, $wpdb->transactions), 'Invalid backup must not start writing.');
    }
    $wpdb->engine = 'MyISAM';
    check(is_wp_error($service->import($backup)), 'Non-transactional tables must be rejected.');
    $wpdb->engine = 'InnoDB';
    $before = array($wpdb->suppliers, $wpdb->settings, $options);
    $bad = $backup;
    $bad['suppliers'][0]['supplier']['api_key'] = 'changed';
    $bad['options']['multi_sync_queue_settings']['suspicious_price_drop_percent'] = 30;
    $fail_option = 'multi_sync_queue_settings';
    check(is_wp_error($service->import($bad)), 'Failed save must return an error.');
    check($before === array($wpdb->suppliers, $wpdb->settings, $options), 'Failed save must roll back all stores.');
    unset($fail_option);
    $new = $backup;
    $new['suppliers'][0]['supplier']['marketplace_key'] = 'trendyol';
    check(!is_wp_error($service->import($new)) && isset($wpdb->suppliers[43]), 'Missing supplier must be created using a local ID.');
    $legacy = $backup;
    unset($legacy['suppliers'][0]['mappings']['categories']);
    check(!is_wp_error($service->import($legacy)) && $options['multi_sync_category_mappings_42'][110]['category_id'] === 'cat-legacy', 'Legacy-only backup must update the effective category mapping.');

    // Old exports contained all rows: seven integrations plus five custom marketplaces.
    $mixed = $backup;
    $mixed['suppliers'] = array();
    foreach (array('trendyol', 'n11', 'pazarama', 'ciceksepeti', 'amazon', 'pttavm', 'hepsiburada') as $marketplace) {
        $entry = $backup['suppliers'][0];
        $entry['supplier']['marketplace_key'] = $marketplace;
        $entry['supplier']['api_key'] = 'current-' . $marketplace;
        $mixed['suppliers'][] = $entry;
    }
    for ($index = 0; $index < 5; $index++) {
        $entry = $backup['suppliers'][0];
        $entry['supplier']['marketplace_key'] = 'old_custom_' . $index;
        $entry['mappings']['categories'][0]['slug'] = 'deleted-legacy-category';
        $mixed['suppliers'][] = $entry;
    }
    $wpdb->suppliers[90] = array('id' => 90, 'name' => 'Legacy', 'marketplace_key' => 'old_custom_0', 'api_key' => 'keep-legacy');
    $result = $service->import($mixed);
    check(!is_wp_error($result) && $result['imported'] === 7 && $result['skipped'] === 5, 'A twelve-row legacy backup must import supported integrations and report skipped custom records.');
    check(strpos($result['message'], '5 desteklenmeyen') !== false, 'Skipped count must be visible to the user.');
    check($wpdb->suppliers[90]['api_key'] === 'keep-legacy' && count($wpdb->suppliers) === 8, 'Legacy records must not be overwritten or recreated.');
    check($wpdb->suppliers[42]['api_key'] === 'current-hepsiburada', 'Current integration credentials must still import.');
    check(count($service->export()['suppliers']) === 7, 'New exports must exclude legacy records.');
    check(!is_wp_error($service->import($mixed)) && count($wpdb->suppliers) === 8, 'Reimport must not duplicate legacy or current records.');
    $only_custom = $mixed;
    $only_custom['suppliers'] = array_slice($mixed['suppliers'], 7);
    $before = array($wpdb->suppliers, $wpdb->settings, $options, $wpdb->transactions);
    check(is_wp_error($service->import($only_custom)), 'A legacy-only backup must not report a successful restore.');
    check($before === array($wpdb->suppliers, $wpdb->settings, $options, $wpdb->transactions), 'A legacy-only backup must not change global settings.');

    $rest = new \MultiSync\Api\RestApi();
    $rest->register_routes();
    foreach ($routes['/settings/backup'] as $route) {
        $admin = false; check(!call_user_func($route['permission_callback']), 'Backup routes must deny non-admins.');
        $admin = true; check(call_user_func($route['permission_callback']), 'Backup routes must allow admins.');
    }
    $request = new class {
        public function get_body() { return str_repeat('x', 10 * 1024 * 1024 + 1); }
    };
    check(is_wp_error($rest->import_configuration($request)), 'Oversized uploads must be rejected before parsing.');
    echo "configuration-backup-test: ok\n";
}
