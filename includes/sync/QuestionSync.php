<?php

namespace MultiSync\Sync;

use MultiSync\Marketplaces\MarketplaceManager;
use MultiSync\Models\MarketplaceQuestion;
use MultiSync\Models\Supplier;

if (!defined('ABSPATH')) {
    exit;
}

class QuestionSync
{
    public const CLEANUP_HOOK = 'multi_sync_questions_cleanup_event';

    private const RETENTION_DAYS = 30;
    private const MAX_PAGES_PER_SUPPLIER = 5;
    private const PAGE_SIZE = 20;
    private const TRENDYOL_MAX_PAGES_PER_SUPPLIER = 20;
    private const TRENDYOL_PAGE_SIZE = 50;

    private static $initialized = false;

    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        add_action(self::CLEANUP_HOOK, array(__CLASS__, 'cleanup_old_questions'));
        add_action('init', array(__CLASS__, 'ensure_cleanup_schedule'), 35);
    }

    public static function ensure_cleanup_schedule()
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
    }

    public static function clear_all_schedules()
    {
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }

    public static function cleanup_old_questions()
    {
        $model = new MarketplaceQuestion();
        $model->cleanup_older_than_days(self::RETENTION_DAYS);
    }

    public function refresh_questions($supplier_id = 0)
    {
        $supplier_model = new Supplier();
        $manager = new MarketplaceManager();
        $question_model = new MarketplaceQuestion();

        $supplier_id = (int) $supplier_id;
        $suppliers = array();

        $is_single_supplier_request = $supplier_id > 0;

        if ($supplier_id > 0) {
            $supplier = $supplier_model->get($supplier_id);
            if ($supplier) {
                $suppliers[] = $supplier;
            }
        } else {
            $all_suppliers = $supplier_model->get_all();
            if (is_array($all_suppliers)) {
                foreach ($all_suppliers as $supplier) {
                    if (!$supplier || empty($supplier->active)) {
                        continue;
                    }

                    $candidate_adapter = $manager->for_supplier($supplier);
                    if (!$this->supports_questions($candidate_adapter)) {
                        continue;
                    }

                    if ($supplier) {
                        $suppliers[] = $supplier;
                    }
                }
            }
        }

        $summary = array(
            'fetched' => 0,
            'upserted' => 0,
            'failed_suppliers' => array(),
            'skipped_suppliers' => array(),
            'suppliers' => count($suppliers),
            'supported_suppliers' => 0,
            'unsupported_suppliers' => 0,
        );

        foreach ($suppliers as $supplier) {
            if (!$supplier || !isset($supplier->id)) {
                continue;
            }

            if (empty($supplier->active)) {
                continue;
            }

            $supplier_id = (int) $supplier->id;
            $supplier_marketplace_key = isset($supplier->marketplace_key)
                ? sanitize_key((string) $supplier->marketplace_key)
                : '';
            if (function_exists('multi_sync_debug_log')) {
                multi_sync_debug_log(sprintf(
                    'QuestionSync::refresh_questions supplier=%d marketplace=%s start',
                    $supplier_id,
                    $supplier_marketplace_key
                ));
            }
            $adapter = $manager->for_supplier($supplier);
            if (!$this->supports_questions($adapter)) {
                if (function_exists('multi_sync_debug_log')) {
                    multi_sync_debug_log(sprintf(
                        'QuestionSync::refresh_questions supplier=%d skipped (questions not supported).',
                        $supplier_id
                    ));
                }
                $summary['unsupported_suppliers']++;
                if ($is_single_supplier_request) {
                    $summary['failed_suppliers'][] = array(
                        'supplier_id' => $supplier_id,
                        'supplier_name' => isset($supplier->name) ? (string) $supplier->name : '',
                        'marketplace_key' => $supplier_marketplace_key,
                        'message' => 'Bu pazar yeri soru cekmeyi desteklemiyor.',
                        'code' => 'questions_not_supported',
                    );
                }
                continue;
            }

            $summary['supported_suppliers']++;
            $credentials = $adapter->validate_credentials($supplier);
            if (is_wp_error($credentials)) {
                if (function_exists('multi_sync_debug_log')) {
                    multi_sync_debug_log(sprintf(
                        'QuestionSync::refresh_questions supplier=%d credentials invalid: %s',
                        $supplier_id,
                        $credentials->get_error_message()
                    ));
                }

                $summary['failed_suppliers'][] = array(
                    'supplier_id' => $supplier_id,
                    'supplier_name' => isset($supplier->name) ? (string) $supplier->name : '',
                    'marketplace_key' => $supplier_marketplace_key,
                    'message' => $credentials->get_error_message(),
                    'code' => (string) $credentials->get_error_code(),
                );
                continue;
            }

            $fetch_result = $this->refresh_supplier_questions($supplier, $adapter, $question_model);
            if (is_wp_error($fetch_result)) {
                if (function_exists('multi_sync_debug_log')) {
                    multi_sync_debug_log(sprintf(
                        'QuestionSync::refresh_questions supplier=%d fetch error: %s',
                        $supplier_id,
                        $fetch_result->get_error_message()
                    ));
                }

                $summary['failed_suppliers'][] = array(
                    'supplier_id' => $supplier_id,
                    'supplier_name' => isset($supplier->name) ? (string) $supplier->name : '',
                    'marketplace_key' => $supplier_marketplace_key,
                    'message' => $fetch_result->get_error_message(),
                    'code' => (string) $fetch_result->get_error_code(),
                );
                continue;
            }

            $summary['fetched'] += isset($fetch_result['fetched']) ? (int) $fetch_result['fetched'] : 0;
            $summary['upserted'] += isset($fetch_result['upserted']) ? (int) $fetch_result['upserted'] : 0;

            if (function_exists('multi_sync_debug_log')) {
                multi_sync_debug_log(sprintf(
                    'QuestionSync::refresh_questions supplier=%d done fetched=%d upserted=%d map_errors=%d upsert_failures=%d',
                    $supplier_id,
                    isset($fetch_result['fetched']) ? (int) $fetch_result['fetched'] : 0,
                    isset($fetch_result['upserted']) ? (int) $fetch_result['upserted'] : 0,
                    isset($fetch_result['map_errors']) ? (int) $fetch_result['map_errors'] : 0,
                    isset($fetch_result['db_upsert_failures']) ? (int) $fetch_result['db_upsert_failures'] : 0
                ));
            }
        }

        return $summary;
    }

    private function supports_questions($adapter)
    {
        return $adapter
            && method_exists($adapter, 'fetch_questions')
            && method_exists($adapter, 'map_question');
    }

    public function reply_to_question($local_id, $answer_text, $user_id = 0)
    {
        $local_id = (int) $local_id;
        if ($local_id <= 0) {
            return new \WP_Error('multi_sync_invalid_question_id', 'Gecersiz soru kaydi.');
        }

        $answer_text = trim((string) $answer_text);
        $len = function_exists('mb_strlen')
            ? mb_strlen($answer_text, 'UTF-8')
            : strlen($answer_text);

        if ($len < 10 || $len > 2000) {
            return new \WP_Error('multi_sync_invalid_answer_length', 'Yanit 10 ile 2000 karakter arasinda olmalidir.');
        }

        $question_model = new MarketplaceQuestion();
        $question = $question_model->get($local_id);
        if (!$question) {
            return new \WP_Error('multi_sync_question_not_found', 'Soru kaydi bulunamadi.');
        }

        if (empty($question['can_reply'])) {
            return new \WP_Error('multi_sync_question_not_replyable', 'Bu soru platform kurallarina gore yanitlanamaz.');
        }

        $supplier_model = new Supplier();
        $supplier = $supplier_model->get((int) $question['supplier_id']);
        if (!$supplier) {
            return new \WP_Error('multi_sync_supplier_not_found', 'Tedarikci bulunamadi.');
        }

        $manager = new MarketplaceManager();
        $adapter = $manager->for_supplier($supplier);
        if (!$adapter || !method_exists($adapter, 'reply_to_question')) {
            return new \WP_Error(
                'multi_sync_marketplace_questions_not_supported',
                'Bu pazar yeri soru yanitlamayi desteklemiyor.'
            );
        }

        $result = $adapter->reply_to_question(
            $supplier,
            isset($question['external_question_id']) ? (string) $question['external_question_id'] : '',
            $answer_text,
            $question
        );

        if (is_wp_error($result)) {
            $question_model->set_reply_error($local_id, $result->get_error_message());
            return $result;
        }

        $status = isset($result['status']) ? (string) $result['status'] : 'ANSWERED';
        $answered_at = isset($result['answered_at']) ? (string) $result['answered_at'] : current_time('mysql');
        $stored_answer = isset($result['answer_text']) ? (string) $result['answer_text'] : $answer_text;

        $question_model->mark_answered($local_id, $stored_answer, $status, $answered_at);
        return $question_model->get($local_id);
    }

    private function refresh_supplier_questions($supplier, $adapter, $question_model)
    {
        $supplier_id = isset($supplier->id) ? (int) $supplier->id : 0;
        $marketplace_key = isset($supplier->marketplace_key) ? sanitize_key((string) $supplier->marketplace_key) : '';
        $fetch_limits = $this->resolve_fetch_limits($marketplace_key);
        $max_pages = isset($fetch_limits['max_pages']) ? (int) $fetch_limits['max_pages'] : self::MAX_PAGES_PER_SUPPLIER;
        $page_size = isset($fetch_limits['page_size']) ? (int) $fetch_limits['page_size'] : self::PAGE_SIZE;
        if ($max_pages <= 0) {
            $max_pages = self::MAX_PAGES_PER_SUPPLIER;
        }
        if ($page_size <= 0) {
            $page_size = self::PAGE_SIZE;
        }

        $fetched = 0;
        $upserted = 0;
        $map_errors = 0;
        $db_upsert_failures = 0;
        $page = 0;
        $seen_question_ids = array();
        $fetched_pages = array();
        $first_page_current = null;
        $first_page_total = null;

        for ($i = 0; $i < $max_pages; $i++) {
            $requested_page = $page;
            $response = $adapter->fetch_questions($supplier, array(
                'page' => $page,
                'size' => $page_size,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $fetched_pages[(int) $requested_page] = 1;
            $items = array();
            $has_next = false;
            $next_page = $page + 1;

            if (is_array($response) && isset($response['items']) && is_array($response['items'])) {
                $items = $response['items'];
                $has_next = !empty($response['has_next']);
                if (isset($response['next_page']) && is_numeric($response['next_page'])) {
                    $next_page = (int) $response['next_page'];
                }
                if ($i === 0) {
                    if (isset($response['page']) && is_numeric($response['page'])) {
                        $first_page_current = (int) $response['page'];
                    }
                    if (isset($response['total_pages']) && is_numeric($response['total_pages'])) {
                        $first_page_total = (int) $response['total_pages'];
                    }
                }
            } elseif (is_array($response)) {
                $items = $response;
                $has_next = count($items) >= self::PAGE_SIZE;
            }

            if (empty($items)) {
                break;
            }

            $fetched += count($items);
            $new_in_page = 0;

            foreach ($items as $raw_item) {
                $mapped = $adapter->map_question($raw_item);
                if (is_wp_error($mapped) || !is_array($mapped)) {
                    $map_errors++;
                    continue;
                }

                $external_question_id = isset($mapped['external_question_id'])
                    ? trim((string) $mapped['external_question_id'])
                    : '';
                if ($external_question_id !== '') {
                    if (isset($seen_question_ids[$external_question_id])) {
                        continue;
                    }
                    $seen_question_ids[$external_question_id] = 1;
                    $new_in_page++;
                }

                $mapped['supplier_id'] = $supplier_id;
                $mapped['marketplace_key'] = $marketplace_key;
                $mapped['last_synced_at'] = current_time('mysql');
                if (!isset($mapped['raw_payload'])) {
                    $mapped['raw_payload'] = is_array($raw_item) ? $raw_item : (array) $raw_item;
                }

                $upsert_id = $question_model->upsert($mapped);
                if ($upsert_id !== false) {
                    $upserted++;
                } else {
                    $db_upsert_failures++;
                }
            }

            // API page param ignored or repeated payload case: stop early.
            if ($new_in_page === 0 && $page > 0) {
                break;
            }

            if (!$has_next) {
                break;
            }

            $page = max(0, $next_page);
        }

        // Trendyol: always try to include the oldest page once so the oldest question can be synced.
        if ($marketplace_key === 'trendyol' && is_numeric($first_page_total)) {
            $total_pages = max(0, (int) $first_page_total);
            if ($total_pages > 0) {
                $candidate_pages = array();
                if ((int) $first_page_current === 1) {
                    $candidate_pages[] = $total_pages; // one-based paging APIs
                    $candidate_pages[] = $total_pages - 1; // fallback
                } else {
                    $candidate_pages[] = $total_pages - 1; // zero-based paging APIs
                    $candidate_pages[] = $total_pages; // fallback
                }

                $candidate_pages = array_values(array_unique(array_map('intval', $candidate_pages)));
                foreach ($candidate_pages as $candidate_page) {
                    if ($candidate_page < 0 || isset($fetched_pages[$candidate_page])) {
                        continue;
                    }

                    $oldest_response = $adapter->fetch_questions($supplier, array(
                        'page' => $candidate_page,
                        'size' => $page_size,
                    ));

                    if (is_wp_error($oldest_response)) {
                        if (function_exists('multi_sync_debug_log')) {
                            multi_sync_debug_log(sprintf(
                                'QuestionSync::refresh_supplier_questions oldest-page fetch failed supplier=%d page=%d error=%s',
                                $supplier_id,
                                $candidate_page,
                                $oldest_response->get_error_message()
                            ));
                        }
                        continue;
                    }

                    $oldest_items = array();
                    if (is_array($oldest_response) && isset($oldest_response['items']) && is_array($oldest_response['items'])) {
                        $oldest_items = $oldest_response['items'];
                    } elseif (is_array($oldest_response)) {
                        $oldest_items = $oldest_response;
                    }

                    if (empty($oldest_items)) {
                        continue;
                    }

                    $fetched_pages[$candidate_page] = 1;
                    $fetched += count($oldest_items);

                    foreach ($oldest_items as $raw_item) {
                        $mapped = $adapter->map_question($raw_item);
                        if (is_wp_error($mapped) || !is_array($mapped)) {
                            $map_errors++;
                            continue;
                        }

                        $external_question_id = isset($mapped['external_question_id'])
                            ? trim((string) $mapped['external_question_id'])
                            : '';
                        if ($external_question_id !== '') {
                            if (isset($seen_question_ids[$external_question_id])) {
                                continue;
                            }
                            $seen_question_ids[$external_question_id] = 1;
                        }

                        $mapped['supplier_id'] = $supplier_id;
                        $mapped['marketplace_key'] = $marketplace_key;
                        $mapped['last_synced_at'] = current_time('mysql');
                        if (!isset($mapped['raw_payload'])) {
                            $mapped['raw_payload'] = is_array($raw_item) ? $raw_item : (array) $raw_item;
                        }

                        $upsert_id = $question_model->upsert($mapped);
                        if ($upsert_id !== false) {
                            $upserted++;
                        } else {
                            $db_upsert_failures++;
                        }
                    }

                    // one successful oldest-page fetch is enough
                    break;
                }
            }
        }

        return array(
            'fetched' => $fetched,
            'upserted' => $upserted,
            'map_errors' => $map_errors,
            'db_upsert_failures' => $db_upsert_failures,
        );
    }

    private function resolve_fetch_limits($marketplace_key)
    {
        if ($marketplace_key === 'trendyol') {
            return array(
                'max_pages' => self::TRENDYOL_MAX_PAGES_PER_SUPPLIER,
                'page_size' => self::TRENDYOL_PAGE_SIZE,
            );
        }

        return array(
            'max_pages' => self::MAX_PAGES_PER_SUPPLIER,
            'page_size' => self::PAGE_SIZE,
        );
    }
}
