CREATE TABLE IF NOT EXISTS {$wpdb->prefix}multi_sync_suppliers (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    marketplace_key varchar(100) DEFAULT 'trendyol',
    active boolean DEFAULT 1,
    commission_rate float DEFAULT 0,
    api_key text,
    api_secret text,
    seller_id varchar(100) DEFAULT '',
    amazon_refresh_token text,
    ptt_rest_api_key text,
    ptt_access_token text,
    n11_shipment_template varchar(190) DEFAULT '',
    hepsiburada_environment varchar(10) DEFAULT 'production',
    hepsiburada_developer_username varchar(190) DEFAULT '',
    hepsiburada_test_api_key text,
    hepsiburada_test_api_secret text,
    hepsiburada_test_seller_id varchar(100) DEFAULT '',
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id)
) {$charset_collate};

CREATE TABLE IF NOT EXISTS {$wpdb->prefix}multi_sync_settings (
    supplier_id bigint(20) NOT NULL,
    sync_stock boolean DEFAULT 0,
    sync_price boolean DEFAULT 0,
    sync_products boolean DEFAULT 0,
    sync_orders boolean DEFAULT 0,
    stock_automation_mode varchar(30) DEFAULT 'scheduled', -- 'scheduled', 'event_driven'
    schedule varchar(50) DEFAULT 'manual', -- 'manual', 'hourly', 'daily', 'per_minute'
    interval_minutes int(11) DEFAULT 5,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (supplier_id)
) {$charset_collate};

CREATE TABLE IF NOT EXISTS {$wpdb->prefix}multi_sync_jobs (
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
    PRIMARY KEY (id),
    KEY supplier_id (supplier_id),
    KEY status (status),
    KEY job_type (job_type),
    KEY created_at (created_at)
) {$charset_collate};

CREATE TABLE IF NOT EXISTS {$wpdb->prefix}multi_sync_job_items (
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
    PRIMARY KEY (id),
    KEY job_id (job_id),
    KEY supplier_id (supplier_id),
    KEY item_key (item_key)
) {$charset_collate};

CREATE TABLE IF NOT EXISTS {$wpdb->prefix}multi_sync_change_history (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    job_id bigint(20) NOT NULL,
    supplier_id bigint(20) NOT NULL,
    job_type varchar(50) NOT NULL,
    item_key varchar(255) NOT NULL,
    change_kind varchar(100) NOT NULL,
    before_value longtext,
    after_value longtext,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY job_id (job_id),
    KEY supplier_id (supplier_id),
    KEY job_type (job_type),
    KEY created_at (created_at)
) {$charset_collate};

CREATE TABLE IF NOT EXISTS {$wpdb->prefix}multi_sync_marketplace_questions (
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
) {$charset_collate};

CREATE TABLE IF NOT EXISTS {$wpdb->prefix}multi_sync_logs (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    supplier_id bigint(20) NOT NULL,
    type varchar(50) NOT NULL, -- 'info', 'error', 'success'
    status varchar(50),
    message longtext,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY supplier_id (supplier_id)
) {$charset_collate};
