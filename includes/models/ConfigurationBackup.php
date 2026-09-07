<?php

namespace MultiSync\Models;

if (!defined('ABSPATH')) exit;

class ConfigurationBackup
{
    private const MARKETPLACES = array('trendyol', 'n11', 'pazarama', 'ciceksepeti', 'amazon', 'pttavm', 'hepsiburada');
    private const SUPPLIER_FIELDS = array('name', 'marketplace_key', 'active', 'commission_rate', 'color', 'api_key', 'api_secret', 'seller_id', 'amazon_refresh_token', 'ptt_rest_api_key', 'ptt_access_token', 'n11_shipment_template', 'hepsiburada_environment', 'hepsiburada_developer_username', 'hepsiburada_test_api_key', 'hepsiburada_test_api_secret', 'hepsiburada_test_seller_id');
    private const SETTINGS_FIELDS = array('sync_stock', 'sync_price', 'sync_products', 'sync_orders', 'stock_automation_mode', 'schedule', 'interval_minutes');
    private const MAPPINGS = array(
        'categories' => 'multi_sync_category_mappings_%d',
        'categories_test' => 'multi_sync_category_mappings_%d_test',
        'categories_legacy' => 'multi_sync_trendyol_category_mappings_%d',
        'brands' => 'multi_sync_brand_mappings_%d',
        'brands_test' => 'multi_sync_brand_mappings_%d_test',
    );
    private const OPTIONS = array('multi_sync_queue_settings', 'multi_sync_custom_statuses');

    public function list_suppliers()
    {
        $suppliers = (new Supplier())->get_all();
        if (!is_array($suppliers)) return new \WP_Error('multi_sync_backup_read_failed', 'Pazar yeri ayarları okunamadı.', array('status' => 500));
        $rows = array();
        foreach ($suppliers as $supplier) {
            $supported = in_array($supplier->marketplace_key, self::MARKETPLACES, true);
            $current = $supported ? (new Supplier())->get_by_marketplace_key($supplier->marketplace_key) : null;
            $row = array_intersect_key((array) $supplier, array_flip(array('id', 'name', 'marketplace_key', 'seller_id', 'hepsiburada_test_seller_id', 'hepsiburada_environment', 'active')));
            $row['supported'] = $supported;
            $row['selected'] = $current && (int) $current->id === (int) $supplier->id;
            $row['has_credentials'] = !empty($supplier->api_key) || !empty($supplier->api_secret) || !empty($supplier->hepsiburada_test_api_key) || !empty($supplier->amazon_refresh_token) || !empty($supplier->ptt_rest_api_key) || !empty($supplier->ptt_access_token);
            $row['mapping_count'] = 0;
            foreach (self::MAPPINGS as $pattern) $row['mapping_count'] += count((array) get_option(sprintf($pattern, $supplier->id), array()));
            $rows[] = $row;
        }
        return $rows;
    }

    public function export($supplier_ids = null)
    {
        $backup = array('format' => 'open-entegre-settings', 'version' => 1, 'exported_at' => gmdate('c'), 'suppliers' => array(), 'options' => array());
        $suppliers = (new Supplier())->get_all();
        if (!is_array($suppliers)) return new \WP_Error('multi_sync_backup_read_failed', 'Pazar yeri ayarları okunamadı.', array('status' => 500));
        if ($supplier_ids !== null) {
            $available_ids = array();
            foreach ($suppliers as $supplier) if (in_array($supplier->marketplace_key, self::MARKETPLACES, true)) $available_ids[] = (int) $supplier->id;
            if (!is_array($supplier_ids) || !$supplier_ids || array_filter($supplier_ids, function ($id) { return !is_int($id) || $id <= 0; }) || array_diff($supplier_ids, $available_ids)) return new \WP_Error('multi_sync_backup_selection', 'Dışa aktarılacak geçerli pazar yeri kayıtlarını seçin.', array('status' => 400));
        }
        foreach ($suppliers as $supplier) {
            if (!in_array($supplier->marketplace_key, self::MARKETPLACES, true)) continue;
            if ($supplier_ids !== null) {
                if (!in_array((int) $supplier->id, $supplier_ids, true)) continue;
            } else {
                // Keep the default export compatible with the account selected by the admin panel.
                $current = (new Supplier())->get_by_marketplace_key($supplier->marketplace_key);
                if (!$current || (int) $current->id !== (int) $supplier->id) continue;
            }
            $entry = array(
                'source_id' => (int) $supplier->id,
                'supplier' => array_intersect_key((array) $supplier, array_flip(self::SUPPLIER_FIELDS)),
                'settings' => array_intersect_key((array) (new SyncSettings())->get($supplier->id), array_flip(self::SETTINGS_FIELDS)),
                'mappings' => array(),
            );
            foreach (self::MAPPINGS as $kind => $pattern) {
                $mappings = get_option(sprintf($pattern, $supplier->id), null);
                if ($mappings === null) continue;
                if (!is_array($mappings)) return new \WP_Error('multi_sync_backup_read_failed', 'Eşleştirme verisi geçersiz.', array('status' => 400));
                $entry['mappings'][$kind] = array();
                foreach ($mappings as $key => $value) {
                    $brand = strpos($kind, 'brands') === 0;
                    list($taxonomy, $term_id) = $brand ? explode(':', $key, 2) : array('product_cat', $key);
                    $term = get_term((int) $term_id, $taxonomy);
                    if (!$term || is_wp_error($term)) {
                        return new \WP_Error('multi_sync_backup_term', 'Eşleştirmede silinmiş kategori/marka var. Önce eşleştirmeyi düzeltin: ' . $key, array('status' => 400));
                    }
                    $entry['mappings'][$kind][] = array('taxonomy' => $taxonomy, 'slug' => $term->slug, 'value' => $value);
                }
            }
            $backup['suppliers'][] = $entry;
        }
        foreach (self::OPTIONS as $key) {
            $value = get_option($key, null);
            if ($value !== null) $backup['options'][$key] = $value;
        }
        return $backup;
    }

    public function import($backup)
    {
        global $wpdb;
        $option_names = array();
        $started = false;
        try {
            $this->require_valid(is_array($backup) && ($backup['format'] ?? '') === 'open-entegre-settings' && ($backup['version'] ?? null) === 1, 'Geçerli bir Open Entegre ayar yedeği seçin.');
            $this->require_valid(isset($backup['suppliers'], $backup['options']) && is_array($backup['suppliers']) && is_array($backup['options']), 'Yedek yapısı geçersiz.');
            $this->require_valid(count($backup['suppliers']) > 0, 'Yedekte pazar yeri kaydı yok.');
            $plans = array();
            $seen = array();
            $skipped = 0;
            foreach ($backup['suppliers'] as $entry) {
                $this->require_valid(is_array($entry) && isset($entry['supplier'], $entry['settings'], $entry['mappings']) && is_array($entry['mappings']), 'Pazar yeri kaydı geçersiz.');
                $supplier = $this->fields($entry['supplier'], self::SUPPLIER_FIELDS);
                $marketplace = $supplier['marketplace_key'] ?? '';
                $this->require_valid(is_string($marketplace) && $marketplace !== '', 'Pazar yeri anahtarı geçersiz.');
                if (!in_array($marketplace, self::MARKETPLACES, true)) {
                    $skipped++;
                    continue;
                }
                $this->require_valid(!isset($seen[$marketplace]), 'Aynı entegrasyon için yalnızca bir kayıt seçin: ' . $marketplace . '.');
                $this->require_valid(!empty($supplier['name']), 'Pazar yeri adı eksik.');
                $seen[$marketplace] = true;
                // Match Supplier and the adapter: only the exact value "test" selects the test environment.
                $supplier['hepsiburada_environment'] = ($supplier['hepsiburada_environment'] ?? '') === 'test' ? 'test' : 'production';
                $settings = $this->fields($entry['settings'], self::SETTINGS_FIELDS);
                foreach (array('active', 'sync_stock', 'sync_price', 'sync_products', 'sync_orders') as $field) {
                    $value = $supplier[$field] ?? $settings[$field] ?? 0;
                    $this->require_valid(in_array((string) $value, array('0', '1'), true), 'Açık/kapalı ayarı geçersiz.');
                }
                $this->require_valid(!isset($supplier['commission_rate']) || (is_numeric($supplier['commission_rate']) && $supplier['commission_rate'] >= 0 && $supplier['commission_rate'] < 100), 'Komisyon oranı geçersiz.');
                $this->require_valid(!isset($supplier['color']) || preg_match('/^#[a-f0-9]{6}$/i', $supplier['color']), 'Pazar yeri rengi geçersiz.');
                foreach (array('stock_automation_mode' => array('scheduled', 'event_driven'), 'schedule' => array('manual', 'hourly', 'daily', 'per_minute'), 'interval_minutes' => array('5', '10', '15', '30')) as $field => $allowed) {
                    $value = $supplier[$field] ?? $settings[$field] ?? null;
                    $this->require_valid($value === null || in_array((string) $value, $allowed, true), 'Geçersiz ayar: ' . $field);
                }
                $mappings = array();
                foreach ($entry['mappings'] as $kind => $rows) {
                    $this->require_valid(isset(self::MAPPINGS[$kind]) && is_array($rows), 'Eşleştirme türü geçersiz.');
                    $mappings[$kind] = array();
                    foreach ($rows as $row) {
                        $this->require_valid(is_array($row) && isset($row['taxonomy'], $row['slug'], $row['value']) && is_string($row['taxonomy']) && is_string($row['slug']) && is_array($row['value']), 'Eşleştirme kaydı geçersiz.');
                        $brand = strpos($kind, 'brands') === 0;
                        $taxonomy = $row['taxonomy'];
                        $this->require_valid($brand ? (in_array($taxonomy, get_object_taxonomies('product'), true) && preg_match('/brand|marka/i', $taxonomy)) : $taxonomy === 'product_cat', 'Kategori/marka türü geçersiz.');
                        $term = get_term_by('slug', $row['slug'], $taxonomy);
                        $this->require_valid($term && !is_wp_error($term), 'Hedef sitede kategori/marka bulunamadı: ' . $row['slug'] . '. Önce WooCommerce verilerini aktarın.');
                        $key = $brand ? $taxonomy . ':' . $term->term_id : (int) $term->term_id;
                        $this->require_valid(!isset($mappings[$kind][$key]), 'Tekrarlanan kategori/marka eşleştirmesi.');
                        $value = $row['value'];
                        $id_field = $brand ? 'brand_id' : 'category_id';
                        $this->require_valid(isset($value[$id_field]) && is_scalar($value[$id_field]) && (string) $value[$id_field] !== '', 'Pazar yeri kategori/marka kimliği eksik.');
                        $this->validate_mapping($value);
                        if (isset($value['commission_rate'])) $this->require_valid(is_numeric($value['commission_rate']) && $value['commission_rate'] >= 0 && $value['commission_rate'] < 100, 'Kategori komisyon oranı geçersiz.');
                        $mappings[$kind][$key] = $this->clean_tree($value);
                    }
                }
                if (!isset($mappings['categories']) && isset($mappings['categories_legacy'])) $mappings['categories'] = $mappings['categories_legacy'];
                $plans[] = array($supplier, $settings, $mappings);
            }
            $this->require_valid(count($plans) > 0, 'Yedekte desteklenen pazar yeri kaydı yok; hiçbir ayar değiştirilmedi.');
            $this->require_valid(!array_diff(array_keys($backup['options']), self::OPTIONS), 'Yedekte desteklenmeyen genel ayar var.');
            foreach ($backup['options'] as $key => $value) $this->require_valid(is_array($value), 'Genel ayar yapısı geçersiz.');
            $options = $this->clean_tree($backup['options']);
            if (isset($options['multi_sync_queue_settings'])) {
                $threshold = $options['multi_sync_queue_settings']['suspicious_price_drop_percent'] ?? null;
                $this->require_valid(is_numeric($threshold) && $threshold >= 0 && $threshold <= 100, 'Fiyat düşüş eşiği geçersiz.');
            }
            foreach ($options['multi_sync_custom_statuses'] ?? array() as $status) {
                $this->require_valid(is_array($status) && isset($status['slug'], $status['label']) && is_string($status['slug']) && is_string($status['label']) && preg_match('/^wc-[a-z0-9-]{1,17}$/', $status['slug']), 'Sipariş durumu geçersiz.');
            }
            // Rollback must cover all three stores; refuse non-transactional installations.
            foreach (array('multi_sync_suppliers', 'multi_sync_settings', 'options') as $table) {
                $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix . $table));
                $this->require_valid(strtoupper((string) $engine) === 'INNODB', 'Güvenli içe aktarma için ilgili veritabanı tabloları InnoDB olmalıdır.');
            }
            if ($wpdb->query('START TRANSACTION') === false) throw new \RuntimeException();
            $started = true;
            $ids = array();
            foreach ($plans as list($supplier, $settings, $mappings)) {
                $existing = (new Supplier())->get_by_marketplace_key($supplier['marketplace_key']);
                if ($existing) {
                    $id = (int) $existing->id;
                    $result = $wpdb->update($wpdb->prefix . 'multi_sync_suppliers', $supplier, array('id' => $id));
                } else {
                    $result = $wpdb->insert($wpdb->prefix . 'multi_sync_suppliers', $supplier);
                    $id = (int) $wpdb->insert_id;
                }
                if ($result === false || !$id) throw new \RuntimeException();
                if ($settings && !(new SyncSettings())->save($id, $settings)) throw new \RuntimeException();
                foreach ($mappings as $kind => $values) {
                    $key = sprintf(self::MAPPINGS[$kind], $id);
                    $existing_values = get_option($key, array());
                    $this->require_valid(is_array($existing_values), 'Mevcut eşleştirme verisi geçersiz.');
                    $options[$key] = array_replace($existing_values, $values);
                }
                $ids[] = $id;
            }
            foreach ($options as $key => $value) {
                $option_names[] = $key;
                update_option($key, $value, false);
                if (get_option($key, null) !== $value) throw new \RuntimeException();
            }
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException();
        } catch (\Throwable $error) {
            if ($started) $wpdb->query('ROLLBACK');
            foreach ($option_names as $key) wp_cache_delete($key, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            return new \WP_Error('multi_sync_backup_import_failed', $error instanceof \InvalidArgumentException ? $error->getMessage() : 'Ayarlar kaydedilemedi; değişiklikler geri alındı.', array('status' => $error instanceof \InvalidArgumentException ? 400 : 500));
        }
        foreach ($ids as $id) {
            \MultiSync\Sync\StockScheduler::sync_supplier_schedule($id);
            \MultiSync\Sync\OrderScheduler::sync_supplier_schedule($id);
        }
        $message = count($ids) . ' pazar yerinin ayarları içe aktarıldı.';
        if ($skipped) $message .= ' ' . $skipped . ' desteklenmeyen eski/özel pazar yeri kaydı atlandı.';
        return array('success' => true, 'imported' => count($ids), 'skipped' => $skipped, 'message' => $message);
    }

    private function fields($data, $allowed)
    {
        $this->require_valid(is_array($data) && !array_diff(array_keys($data), $allowed), 'Desteklenmeyen ayar alanı.');
        $lengths = array('name' => 255, 'seller_id' => 100, 'hepsiburada_test_seller_id' => 100, 'n11_shipment_template' => 190, 'hepsiburada_developer_username' => 190);
        foreach ($data as $key => $value) {
            $this->require_valid($value === null || is_scalar($value), 'Ayar değeri geçersiz: ' . $key);
            $this->require_valid(strlen((string) $value) <= ($lengths[$key] ?? 65535), 'Ayar değeri çok uzun: ' . $key);
            if ($value === null) $data[$key] = '';
        }
        return $data;
    }

    private function validate_mapping($value)
    {
        // Only the known list fields may contain nested data.
        foreach ($value as $key => $item) {
            if (in_array($key, array('attributes', 'attribute_definitions', 'values'), true)) {
                $this->require_valid(is_array($item), 'Kategori nitelik listesi geçersiz.');
                foreach ($item as $row) {
                    $this->require_valid(is_array($row), 'Kategori niteliği geçersiz.');
                    $this->validate_mapping($row);
                }
            } elseif ($key === 'attributeValueIds') {
                $this->require_valid(is_array($item), 'Nitelik değerleri geçersiz.');
                foreach ($item as $id) $this->require_valid(is_scalar($id), 'Nitelik değeri geçersiz.');
            } else {
                $this->require_valid($item === null || is_scalar($item), 'Eşleştirme alanı geçersiz: ' . $key);
            }
        }
    }

    private function clean_tree($value)
    {
        if (is_array($value)) return array_map(array($this, 'clean_tree'), $value);
        $this->require_valid($value === null || is_scalar($value), 'Yedek değeri geçersiz.');
        return is_string($value) ? sanitize_text_field($value) : $value;
    }

    private function require_valid($valid, $message)
    {
        if (!$valid) throw new \InvalidArgumentException($message);
    }
}
