<?php

define('ABSPATH', __DIR__);

class WP_Error
{
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $value)); }
function wc_get_product($id)
{
    if ((int) $id === 1) {
        return $GLOBALS['test_product'] ?? new FakeProduct();
    }
    return (int) $id === 2 ? new CommissionParentProduct() : null;
}
function wc_get_product_id_by_sku($sku) { return strtolower((string) $sku) === 'woo-sku' ? 1 : 0; }
function get_posts($args) { return array(); }

class WC_Product_Query
{
    public function __construct($args) {}
    public function get_products() { return array(1); }
}

require_once dirname(__DIR__) . '/includes/marketplaces/MarketplaceInterface.php';
require_once dirname(__DIR__) . '/includes/marketplaces/BaseMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/TrendyolMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/N11Marketplace.php';
require_once dirname(__DIR__) . '/includes/sync/ProductImporter.php';
require_once dirname(__DIR__) . '/includes/sync/JobWorker.php';
require_once dirname(__DIR__) . '/includes/sync/StockSync.php';

use MultiSync\Marketplaces\TrendyolMarketplace;
use MultiSync\Marketplaces\N11Marketplace;
use MultiSync\Sync\JobWorker;
use MultiSync\Sync\StockSync;
use function MultiSync\Sync\group_variation_products;
use function MultiSync\Sync\resolve_inherited_commission_rate;

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class TrendyolFixture extends TrendyolMarketplace
{
    public $last_url = '';
    public $urls = array();
    public $response_data = array();
    public $response_queue = array();

    protected function request_json($method, $url, $supplier, $body = null)
    {
        $this->last_url = $url;
        $this->urls[] = $url;
        if (!empty($this->response_queue)) {
            return array_shift($this->response_queue);
        }
        return array('data' => $this->response_data);
    }
}

class FakeProduct
{
    public function get_sku() { return 'WOO-SKU'; }
    public function get_meta($key) { return $key === '_multi_sync_external_barcode' ? '8690001' : ''; }
    public function get_stock_quantity() { return 7; }
    public function get_regular_price() { return '120'; }
    public function get_sale_price() { return '99'; }
}

class NoSaleProduct extends FakeProduct
{
    public function get_sale_price() { return ''; }
}

class CommissionParentProduct extends FakeProduct
{
    public function get_meta($key)
    {
        if ($key === '_multi_sync_commission_rates') {
            return array('trendyol' => 15.5);
        }
        if ($key === '_multi_sync_vat_rate') {
            return '10';
        }
        return parent::get_meta($key);
    }
}

class CommissionVariationProduct extends FakeProduct
{
    public function get_parent_id() { return 2; }
}

class N11VatFixture extends N11Marketplace
{
    public function vat($product) { return $this->get_product_vat_rate($product); }
}

class TrendyolVatFixture extends TrendyolMarketplace
{
    public function vat($product) { return $this->get_product_vat_rate($product); }
}

class StockPreviewFixture
{
    private $page = 0;
    public function fetch_products($supplier, $options)
    {
        if ($this->page++ > 0) {
            return array();
        }
        return array(array(
            'sku' => 'WOO-SKU',
            'external_sku' => 'WOO-SKU',
            'external_product_id' => '',
            'name' => 'Marketplace Product',
            'stock_quantity' => 3,
            'regular_price' => 110,
            'sale_price' => 90,
        ));
    }
    public function map_product($item) { return $item; }
    public function build_price_inventory_item_from_product($product, $sync_stock = true, $sync_price = true)
    {
        return (new TrendyolMarketplace())->build_price_inventory_item_from_product($product, $sync_stock, $sync_price);
    }
}

$supplier = (object) array('api_key' => 'key', 'api_secret' => 'secret', 'seller_id' => '42');
$trendyol = new TrendyolFixture();
$trendyol->response_data = array('content' => array(array(
    'productMainId' => 'MAIN-1', 'title' => 'T-Shirt', 'images' => array(array('url' => 'https://example.test/a.jpg')),
    'variants' => array(
        array('variantId' => 'v1', 'barcode' => '8690001', 'stockCode' => 'SELLER-SKU', 'attributes' => array(array('attributeName' => 'Size', 'attributeValue' => 'S')), 'stock' => array('quantity' => 7), 'price' => array('listPrice' => 120, 'salePrice' => 99)),
        array('variantId' => 'v2', 'barcode' => '8690002', 'stockCode' => 'SELLER-SKU', 'attributes' => array(array('attributeName' => 'Size', 'attributeValue' => 'M')), 'stock' => array('quantity' => 3), 'price' => array('listPrice' => 120, 'salePrice' => 95)),
    ),
)));

$raw = $trendyol->fetch_products($supplier, array('page' => 2, 'size' => 500, 'nextPageToken' => 'next-token'));
check(count($raw) === 2, 'Trendyol V2 variants were not flattened.');
check(strpos($trendyol->last_url, '/products/approved?') !== false && strpos($trendyol->last_url, 'nextPageToken=next-token') !== false, 'Trendyol V2 pagination endpoint is wrong.');
check(strpos($trendyol->last_url, 'page=2') === false, 'Trendyol cursor request must not also send a page number.');
$mapped = array_map(array($trendyol, 'map_product'), $raw);
check($mapped[0]['sku'] === '8690001' && $mapped[0]['external_sku'] === 'SELLER-SKU' && $mapped[0]['external_product_id'] === 'v1', 'Trendyol identifiers were not separated.');
check($mapped[0]['regular_price'] === 120.0 && $mapped[0]['sale_price'] === 99.0 && $mapped[0]['stock_quantity'] === 7, 'Trendyol price or stock mapping is wrong.');
check(count(group_variation_products($mapped)) === 1, 'Trendyol variations were not grouped.');
$design_group = group_variation_products(array_map(array($trendyol, 'map_product'), array(
    array('productMainId' => 'P-167', 'barcode' => 'DMS678', 'stockCode' => 'DMS678', '_parent_attributes' => array(array('attributeName' => 'Renk', 'attributeValue' => 'Osmanli'))),
    array('productMainId' => 'P-167', 'barcode' => 'DMS679', 'stockCode' => 'DMS679', '_parent_attributes' => array(array('attributeName' => 'Renk', 'attributeValue' => 'Cicek'))),
)));
check(isset($design_group['P-167']) && $design_group['P-167'][0]['variation_attributes'] === array('Renk' => 'Osmanli'), 'Trendyol parent-level design attributes were not detected as variations.');
$choice_group = group_variation_products(array(
    array('parent_key' => 'KAZAN-1', 'variation_attributes' => array('Renk' => 'Gumus', 'Model' => 'Duz')),
    array('parent_key' => 'KAZAN-1', 'variation_attributes' => array('Renk' => 'Siyah', 'Model' => 'Osmanli')),
), array('KAZAN-1' => 'Renk'));
check(array_keys($choice_group['KAZAN-1'][0]['variation_attributes']) === array('Renk'), 'Selected variation attribute was not applied.');

$legacy_trendyol = new TrendyolFixture();
$legacy_trendyol->response_queue = array(
    new WP_Error('multi_sync_marketplace_http_error', '404', array('code' => 404, 'body' => '{"exception":"ClientApiDomainNotFoundException","errors":[{"key":"product.not.found"}]}')),
    array('data' => array('content' => array(array('barcode' => '8690099', 'stockCode' => 'LEGACY-SKU')))),
);
$legacy_rows = $legacy_trendyol->fetch_products($supplier, array('page' => 0, 'size' => 100));
check(count($legacy_rows) === 1 && strpos($legacy_trendyol->last_url, '/products?page=0&size=100') !== false, 'Trendyol V2 404 did not fall back to the legacy product endpoint.');
$legacy_trendyol->fetch_products($supplier, array('page' => 1, 'size' => 100));
check(strpos($legacy_trendyol->last_url, '/products?page=1&size=100') !== false && count($legacy_trendyol->urls) === 3, 'Trendyol legacy fallback was not reused for later pages.');

$payload = $trendyol->build_price_inventory_item_from_product(new FakeProduct(), true, false);
check($payload === array('barcode' => '8690001', 'quantity' => 7), 'Stock-only Trendyol payload changed price fields.');
$payload = $trendyol->build_price_inventory_item_from_product(new FakeProduct(), false, true);
check($payload['listPrice'] === 120.0 && $payload['salePrice'] === 99.0, 'Trendyol list/sale price payload is wrong.');
$trendyol->response_data = array('batchRequestId' => 'batch-1');
$trendyol->push_price_inventory_updates($supplier, array($payload));
check(strpos($trendyol->last_url, '/integration/inventory/sellers/42/products/price-and-inventory') !== false, 'Trendyol inventory endpoint is wrong.');
$trendyol->response_data = array('status' => 'COMPLETED');
$trendyol->get_batch_request_result($supplier, 'batch-1');
check(strpos($trendyol->last_url, '/integration/product/sellers/42/products/batch-requests/batch-1') !== false, 'Trendyol batch result endpoint is wrong.');

$n11 = new N11Marketplace();
$n11_rows = array(
    $n11->map_product(array('stockCode' => 'N11-S', 'productMainId' => 'N11-MAIN', 'title' => 'Shoe', 'listPrice' => 300, 'salePrice' => 250, 'quantity' => 2, 'attributes' => array(array('attributeName' => 'Size', 'attributeValue' => '40'), array('attributeName' => 'Brand', 'attributeValue' => 'Acme')))),
    $n11->map_product(array('stockCode' => 'N11-M', 'productMainId' => 'N11-MAIN', 'title' => 'Shoe', 'listPrice' => 300, 'salePrice' => 240, 'quantity' => 1, 'attributes' => array(array('attributeName' => 'Size', 'attributeValue' => '41'), array('attributeName' => 'Brand', 'attributeValue' => 'Acme')))),
);
$n11_group = group_variation_products($n11_rows);
check(isset($n11_group['N11-MAIN']) && $n11_group['N11-MAIN'][0]['variation_attributes'] === array('Size' => '40'), 'n11 grouped a non-varying attribute.');
check(group_variation_products(array(array('sku' => 'SIMPLE', 'parent_key' => '', 'variation_attributes' => array('Size' => 'S')))) === array(), 'A marketplace without proven parent data was grouped.');
$n11_stock_only = $n11->build_price_inventory_item_from_product(new FakeProduct(), true, false);
check(!isset($n11_stock_only['listPrice']) && !isset($n11_stock_only['salePrice']), 'n11 stock-only payload changed prices.');
$n11_price = $n11->build_price_inventory_item_from_product(new FakeProduct(), false, true);
check($n11_price['listPrice'] === 120.0 && $n11_price['salePrice'] === 99.0, 'n11 list/sale price payload is wrong.');
check((new N11VatFixture())->vat(new CommissionVariationProduct()) === '10' && (new TrendyolVatFixture())->vat(new CommissionVariationProduct()) === '10', 'Global VAT was not shared across marketplaces or inherited from the parent product.');

check(resolve_inherited_commission_rate(array('trendyol' => 3), array('trendyol' => 5), 'trendyol', 8) === 3.0, 'Variation commission override failed.');
check(resolve_inherited_commission_rate(array(), array('trendyol' => 5), 'trendyol', 8) === 5.0, 'Parent commission inheritance failed.');
$importer = (new ReflectionClass(MultiSync\Sync\ProductImporter::class))->newInstanceWithoutConstructor();
$owned_product_method = new ReflectionMethod(MultiSync\Sync\ProductImporter::class, 'find_product');
$owned_product_method->setAccessible(true);
check($owned_product_method->invoke($importer, array('sku' => 'WOO-SKU'), 999) === 1, 'Global Woo SKU was rejected because of supplier ownership.');
check(JobWorker::normalize_remote_batch_state(array('status' => 'PROCESSING')) === 'pending', 'Pending batch state failed.');
check(JobWorker::normalize_remote_batch_state(array('status' => 'SUCCESS')) === 'completed', 'Successful batch state failed.');
check(JobWorker::normalize_remote_batch_state(array('status' => 'FAILED')) === 'failed', 'Failed batch state failed.');

$preview_alias_method = new ReflectionMethod(StockSync::class, 'group_marketplace_product_aliases');
$preview_alias_method->setAccessible(true);
$preview_product = array('sku' => 'DMS006', 'external_sku' => 'DMS006', 'external_product_id' => '123');
$preview_groups = $preview_alias_method->invoke(null, array(
    'dms006' => $preview_product,
    '123' => $preview_product,
), array('dms006' => new FakeProduct()));
check(count($preview_groups) === 1, 'Marketplace aliases created duplicate stock preview rows.');
check($preview_groups[0]['selection_key'] === 'dms006' && $preview_groups[0]['woo_product'] instanceof FakeProduct, 'Stock preview lost the matched Woo SKU alias.');

$direct_preview_method = new ReflectionMethod(StockSync::class, 'build_direct_preview');
$direct_preview_method->setAccessible(true);
$GLOBALS['test_product'] = new CommissionVariationProduct();
$direct_preview = $direct_preview_method->invoke(null, 1, (object) array('marketplace_key' => 'trendyol'), new StockPreviewFixture(), array(), array(), true, true, false);
check(count($direct_preview['items']) === 1, 'Price preview did not use the marketplace product list.');
check($direct_preview['items'][0]['before_price'] === 110.0 && $direct_preview['items'][0]['before_discount_price'] === 90.0, 'Marketplace prices are missing from price preview.');
check($direct_preview['items'][0]['after_price'] === 142.01 && $direct_preview['items'][0]['after_discount_price'] === 117.16, 'Product commission is missing from Trendyol price preview.');

$commission_payload = $trendyol->build_price_inventory_item_from_product(new CommissionVariationProduct(), false, true);
check($commission_payload['listPrice'] === 142.01 && $commission_payload['salePrice'] === 117.16, 'Parent product commission is missing from Trendyol variation payload.');

$discount_method = new ReflectionMethod(StockSync::class, 'get_product_discount_price');
$discount_method->setAccessible(true);
check($discount_method->invoke(null, new NoSaleProduct()) === null, 'Regular price was copied into an empty discount price.');

$importer_source = file_get_contents(dirname(__DIR__) . '/includes/sync/ProductImporter.php');
$order_source = file_get_contents(dirname(__DIR__) . '/includes/sync/OrderImporter.php');
$job_source = file_get_contents(dirname(__DIR__) . '/includes/models/SyncJob.php');
check(strpos($importer_source, 'multi_sync_product_ownership_conflict') === false && strpos($importer_source, "'post_type' => 'product_variation'") !== false, 'Global SKU adoption or safe variation migration is missing.');
check(strpos($order_source, '_multi_sync_external_barcode') !== false && strpos($order_source, '_multi_sync_supplier_id') !== false, 'Supplier-scoped external order line resolution is missing.');
check(strpos($job_source, 'recover_stale_running') !== false && strpos($job_source, '30 * MINUTE_IN_SECONDS') !== false, 'Stale job recovery is missing.');
$updater_file = dirname(__DIR__) . '/includes/plugin-update-checker/plugin-update-checker.php';
require_once $updater_file;
check(class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory'), 'Vendored auto-updater is missing.');
$plugin_source = file_get_contents(dirname(__DIR__) . '/plugin.php');
check(strpos($plugin_source, "setBranch('main')") !== false, 'Auto-updater must use the main branch.');
$rest_source = file_get_contents(dirname(__DIR__) . '/includes/api/RestApi.php');
check(substr_count($rest_source, 'multi_sync_mapping_save_failed') === 2, 'Mapping saves must report database write failures.');

echo "marketplace-variation-test: ok\n";
