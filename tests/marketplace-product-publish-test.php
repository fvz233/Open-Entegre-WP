<?php

define('ABSPATH', __DIR__);

class WP_Error
{
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function sanitize_text_field($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function esc_url_raw($value) { return (string) $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wp_generate_password($length = 12) { return str_repeat('x', $length); }
function current_time($type) { return '2026-01-01 00:00:00'; }
function wp_get_attachment_url($id) { return $id ? 'http://example.test/' . $id . '.jpg' : ''; }
function wp_remote_request($url, $args) { $GLOBALS['hepsiburada_request'] = array('url' => $url, 'args' => $args); return array('code' => 200, 'body' => '{"success":true,"data":{"trackingId":"tracking-1"}}'); }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function taxonomy_exists($name) { return false; }
function remove_accents($value) { return strtr($value, array('ı' => 'i', 'İ' => 'I', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U', 'ö' => 'o', 'Ö' => 'O', 'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G')); }
function wc_get_product($id) { return $GLOBALS['woo_products'][(int) $id] ?? ((int) $id === 21 ? ($GLOBALS['hepsiburada_parent'] ?? null) : null); }
function get_woocommerce_currency() { return 'TRY'; }

require_once dirname(__DIR__) . '/includes/marketplaces/MarketplaceInterface.php';
require_once dirname(__DIR__) . '/includes/marketplaces/BaseMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/N11Marketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/PazaramaMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/CiceksepetiMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/AmazonMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/PttAvmMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/HepsiburadaMarketplace.php';
require_once dirname(__DIR__) . '/includes/sync/ProductPublisher.php';

class MarketplacePublishProduct
{
    public function is_type($type) { return $type === 'simple'; }
    public function get_sku() { return '8690000000001'; }
    public function get_meta($key) { return ''; }
    public function get_stock_quantity() { return 4; }
    public function get_regular_price() { return '120'; }
    public function get_sale_price() { return '100'; }
    public function get_image_id() { return 7; }
    public function get_gallery_image_ids() { return array(); }
    public function get_description() { return 'Urun aciklamasi'; }
    public function get_short_description() { return 'Kisa aciklama'; }
    public function get_name() { return 'Test Urunu'; }
    public function get_id() { return 11; }
}

class HepsiburadaFixture extends MultiSync\Marketplaces\HepsiburadaMarketplace
{
    public $responses = array();
    protected function request_json($method, $url, $supplier, $body = null) { $GLOBALS['hepsiburada_json_request'] = array('method' => $method, 'url' => $url); return array_shift($this->responses); }
    public function multipart($items) { return $this->build_multipart_body($items, 'Boundary'); }
    public function api_base_for($supplier) { return $this->api_base($supplier); }
    public function listing_api_base_for($supplier) { return $this->listing_api_base($supplier); }
    public function user_agent_for($supplier) { return $this->build_user_agent($supplier); }
}

class CiceksepetiFixture extends MultiSync\Marketplaces\CiceksepetiMarketplace
{
    public $response = array();
    protected function request_json($method, $url, $supplier, $body = null) { return $this->response; }
}

class N11Fixture extends MultiSync\Marketplaces\N11Marketplace
{
    public $body = array();
    protected function request_json($method, $url, $supplier, $body = null) { $this->body = $body; return array('data' => array('id' => 1)); }
}

class N11VariableParent extends MarketplacePublishProduct
{
    public function is_type($type) { return $type === 'variable'; }
    public function get_id() { return 31; }
    public function get_sku() { return 'PARENT-SKU'; }
    public function get_gallery_image_ids() { return array(8); }
    public function get_children() { return array(41, 42); }
}

class N11VariationProduct extends MarketplacePublishProduct
{
    private $id;
    private $sku;
    public function __construct($id, $sku) { $this->id = $id; $this->sku = $sku; }
    public function is_type($type) { return $type === 'variation'; }
    public function get_id() { return $this->id; }
    public function get_parent_id() { return 31; }
    public function get_sku() { return $this->sku; }
    public function get_image_id() { return 9; }
    public function get_attributes() { return array('pa_renk' => 'colorfull'); }
}

class HepsiburadaParentProduct extends MarketplacePublishProduct
{
    public function get_sku() { return 'parent 1'; }
    public function get_id() { return 21; }
}

class HepsiburadaVariationProduct extends MarketplacePublishProduct
{
    public function is_type($type) { return $type === 'variation'; }
    public function get_parent_id() { return 21; }
    public function get_sku() { return 'variant 1'; }
    public function get_attributes() { return array('Renk' => 'osmanlı'); }
}

$product = new MarketplacePublishProduct();

$n11 = (new MultiSync\Marketplaces\N11Marketplace())->build_product_item_from_product($product, array(
    'commission_rate' => 10,
    'shipment_template' => 'Global Standart',
    'attributes' => array(array('attributeId' => '1', 'attributeValueIds' => array('99'))),
    'attribute_definitions' => array(array('id' => '1', 'name' => 'Marka', 'required' => true, 'values' => array(array('id' => '99', 'name' => 'Demsu')))),
), array(
    'category_id' => '100', 'vat_rate' => '20',
));
check(!is_wp_error($n11) && $n11['stockCode'] === '8690000000001' && $n11['attributes'][0]['valueId'] === 99 && $n11['salePrice'] === 111.0 && $n11['shipmentTemplate'] === 'Global Standart', 'n11 product payload failed.');
check($n11['barcode'] === null, 'n11 barcode incorrectly defaulted to the stock code.');
check($n11['images'][0]['url'] === 'http://example.test/7.jpg', 'n11 HTTP image mapping failed.');
$n11_brand_mapping = (new MultiSync\Marketplaces\N11Marketplace())->build_product_item_from_product($product, array(
    'shipment_template' => 'Global Standart',
    'brand_id' => '99',
    'brand_name' => 'Demsu',
    'attribute_definitions' => array(array('id' => '1', 'name' => 'Marka', 'required' => true, 'values' => array(array('id' => '99', 'name' => 'Demsu')))),
), array('category_id' => '100', 'vat_rate' => '20'));
check(!is_wp_error($n11_brand_mapping) && $n11_brand_mapping['attributes'][0]['valueId'] === 99, 'n11 brand mapping was not reused for category attribute.');
$n11_custom_brand = (new MultiSync\Marketplaces\N11Marketplace())->build_product_item_from_product($product, array(
    'shipment_template' => 'Global Standart',
    'brand_id' => '',
    'brand_name' => 'Kendi Markamiz',
    'attribute_definitions' => array(array('id' => '1', 'name' => 'Marka', 'required' => true, 'allow_custom' => true, 'values' => array())),
), array('category_id' => '100', 'vat_rate' => '20'));
check(!is_wp_error($n11_custom_brand) && $n11_custom_brand['attributes'][0] === array('id' => 1, 'valueId' => null, 'customValue' => 'Kendi Markamiz'), 'n11 custom brand name was not sent without a brand ID.');
$n11_commission = (new MultiSync\Marketplaces\N11Marketplace())->build_price_inventory_item_from_product($product, false, true, 10);
check($n11_commission['listPrice'] === 133.0 && $n11_commission['salePrice'] === 111.0, 'Category commission was not applied to marketplace prices.');
$GLOBALS['woo_products'] = array(31 => new N11VariableParent(), 41 => new N11VariationProduct(41, 'COLORFULL-SKU'), 42 => new N11VariationProduct(42, 'BLACK-SKU'));
$n11_mapping = array(
    'shipment_template' => 'Global Standart',
    'attribute_definitions' => array(array('id' => '2', 'name' => 'Renk', 'required' => true, 'varianter' => true, 'allow_custom' => true, 'values' => array())),
);
$n11_variation = (new MultiSync\Marketplaces\N11Marketplace())->build_product_item_from_product($GLOBALS['woo_products'][41], $n11_mapping, array(
    'category_id' => '100', 'vat_rate' => '20', 'variation_attribute' => 'pa_renk', 'variation_target_attribute_id' => '2',
));
check(!is_wp_error($n11_variation) && $n11_variation['productMainId'] === 'PARENT-SKU' && $n11_variation['stockCode'] === 'COLORFULL-SKU', 'n11 variation identifiers were not separated.');
check($n11_variation['attributes'][0]['customValue'] === 'Colorfull', 'n11 variation attribute was not mapped.');
check(array_column($n11_variation['images'], 'url') === array('http://example.test/9.jpg'), 'n11 variation payload included parent images.');
$expand = new ReflectionMethod(MultiSync\Sync\ProductPublisher::class, 'expand_variation_product_ids');
$expand->setAccessible(true);
check($expand->invoke(new MultiSync\Sync\ProductPublisher(), array(41)) === array(41, 42), 'n11 selected variation did not expand to its whole family.');
$family_overrides = array(41 => array('variation_attribute' => 'pa_renk', 'variation_target_attribute_id' => '2', 'barcode' => 'child-only'));
$expanded_ids = $expand->invokeArgs(new MultiSync\Sync\ProductPublisher(), array(array(41), &$family_overrides));
check($expanded_ids === array(41, 42) && $family_overrides[42] === array('variation_attribute' => 'pa_renk', 'variation_target_attribute_id' => '2'), 'n11 variation mapping was not propagated to sibling SKUs.');
$n11_fixture = new N11Fixture();
$n11_fixture->push_products((object) array(), array($n11_variation, array_merge($n11_variation, array('stockCode' => 'BLACK-SKU'))));
check(count($n11_fixture->body['payload']['skus']) === 2 && count(array_unique(array_column($n11_fixture->body['payload']['skus'], 'productMainId'))) === 1, 'n11 variants were not sent as sibling SKUs under one productMainId.');
$n11_fixture->push_product_updates((object) array(), array($n11_fixture->build_product_update_item($n11_variation)));
check($n11_fixture->body['payload']['skus'][0]['productMainId'] === 'PARENT-SKU' && $n11_fixture->body['payload']['skus'][0]['deleteProductMainId'] === true, 'n11 existing variation regroup update failed.');
check(MultiSync\Sync\ProductPublisher::n11_batch_verdict(array('status' => 'IN_QUEUE')) === 'pending', 'n11 queued task was treated as complete.');
check(MultiSync\Sync\ProductPublisher::n11_batch_verdict(array('status' => 'PROCESSED', 'skus' => array('content' => array(array('status' => 'SUCCESS'))))) === 'completed', 'n11 successful task was not completed.');
check(MultiSync\Sync\ProductPublisher::n11_batch_verdict(array('status' => 'PROCESSED', 'skus' => array('content' => array(array('status' => 'SUCCESS'), array('status' => 'FAIL'))))) === 'failed', 'n11 failed SKU was treated as complete.');

$pazarama = (new MultiSync\Marketplaces\PazaramaMarketplace())->build_product_item_from_product($product, array(
    'commission_rate' => 10,
    'brand_id' => '1dcfce4a-8fa2-41ae-b0ce-08d8dcdcce53',
    'attributes' => array(array('attributeId' => 'a1', 'attributeValueIds' => array('v1'))),
    'attribute_definitions' => array(array('id' => 'a1', 'name' => 'Marka', 'required' => true, 'values' => array(array('id' => 'v1', 'name' => 'Demsu')))),
), array(
    'category_id' => '429844d8-a148-40cd-ad25-aa4f200c7041', 'desi' => '1', 'vat_rate' => '20',
));
check(!is_wp_error($pazarama) && $pazarama['attributes'][0]['attributeValueId'] === 'v1' && $pazarama['salePrice'] === 111.0, 'Pazarama product payload failed.');
check($pazarama['images'][0]['imageurl'] === 'http://example.test/7.jpg', 'Pazarama HTTP image mapping failed.');

$cicek = (new MultiSync\Marketplaces\CiceksepetiMarketplace())->build_product_item_from_product($product, array('commission_rate' => 10), array('category_id' => '42', 'vat_rate' => '20'));
check(!is_wp_error($cicek) && $cicek['mainProductCode'] === '8690000000001' && $cicek['categoryId'] === 42 && $cicek['salesPrice'] === 111.0, 'Ciceksepeti product payload failed.');
check($cicek['images'][0] === 'http://example.test/7.jpg', 'Ciceksepeti HTTP image mapping failed.');
$cicek_fixture = new CiceksepetiFixture();
$cicek_fixture->response = array('data' => array('categoryAttributes' => array(
    array('attributeId' => 1, 'attributeName' => 'Marka', 'required' => true, 'varianter' => false, 'attributeValues' => array(array('id' => 10, 'name' => 'Demsu'))),
    array('attributeId' => 2, 'attributeName' => 'Renk', 'required' => false, 'varianter' => true, 'attributeValues' => array()),
)));
$cicek_attributes = $cicek_fixture->fetch_category_attributes((object) array(), '42');
check(count($cicek_attributes) === 2 && $cicek_attributes[0]['required'] === true && $cicek_attributes[1]['varianter'] === true, 'Ciceksepeti category attribute flags failed.');

$amazon = (new MultiSync\Marketplaces\AmazonMarketplace())->build_product_item_from_product($product, array('commission_rate' => 10), array('category_id' => 'PRODUCT', 'brand' => 'Demsu', 'barcode' => '8690000000001'));
check(!is_wp_error($amazon) && $amazon['productType'] === 'PRODUCT' && isset($amazon['attributes']['purchasable_offer']), 'Amazon listing payload failed.');
check($amazon['attributes']['purchasable_offer'][0]['our_price'][0]['schedule'][0]['value_with_tax'] === 133.0, 'Amazon category commission failed.');
check($amazon['attributes']['main_product_image_locator'][0]['media_location'] === 'http://example.test/7.jpg', 'Amazon HTTP image mapping failed.');

$ptt = (new MultiSync\Marketplaces\PttAvmMarketplace())->build_product_item_from_product($product, array('commission_rate' => 10), array('category_id' => '55', 'vat_rate' => '20', 'desi' => '1'));
check(!is_wp_error($ptt) && $ptt['barcode'] === '8690000000001' && $ptt['priceWithVat'] === 111.0, 'PTTAVM product payload failed.');

$hepsiburada = new HepsiburadaFixture();
$hepsiburada_mapping = array(
    'category_id' => '123',
    'brand_name' => 'Demsu',
    'commission_rate' => 10,
    'attributes' => array(array('attributeId' => 'material', 'attributeValueIds' => array('steel'))),
    'attribute_definitions' => array(
        array('id' => 'material', 'name' => 'Materyal', 'required' => true, 'values' => array(array('id' => 'steel', 'name' => 'Çelik'))),
        array('id' => 'package-front', 'name' => 'Paket Görseli (ön)', 'required' => true, 'values' => array()),
        array('id' => 'package-back', 'name' => 'Paket Görseli (arka)', 'required' => true, 'values' => array()),
    ),
);
$hb_item = $hepsiburada->build_product_item_from_product($product, $hepsiburada_mapping);
check(!is_wp_error($hb_item) && $hb_item['attributes']['merchantSku'] === '8690000000001', 'Hepsiburada SKU mapping failed.');
check($hb_item['attributes']['price'] === '111,00', 'Hepsiburada category commission failed.');
check($hb_item['attributes']['material'] === 'Çelik', 'Hepsiburada enum value mapping failed.');
check($hb_item['attributes']['Image1'] === 'http://example.test/7.jpg', 'Hepsiburada HTTP image mapping failed.');

$GLOBALS['hepsiburada_parent'] = new HepsiburadaParentProduct();
$hb_variation_mapping = array(
    'category_id' => '123',
    'brand_name' => 'Demsu',
    'attribute_definitions' => array(array('id' => 'color', 'name' => 'Renk', 'required' => true, 'values' => array())),
);
$hb_variation = $hepsiburada->build_product_item_from_product(new HepsiburadaVariationProduct(), $hb_variation_mapping);
check(!is_wp_error($hb_variation) && $hb_variation['attributes']['merchantSku'] === 'VARIANT1' && $hb_variation['attributes']['VaryantGroupID'] === 'PARENT1', 'Hepsiburada variation grouping failed.');
check($hb_variation['attributes']['color'] === 'Osmanlı', 'Hepsiburada variation color failed.');

$multipart = $hepsiburada->multipart(array(array('categoryId' => 123, 'merchant' => 'merchant-1', 'attributes' => $hb_item['attributes'])));
check(strpos($multipart, 'filename="products.json"') !== false && strpos($multipart, '"merchant":"merchant-1"') !== false, 'Hepsiburada multipart JSON failed.');
$hb_result = $hepsiburada->push_products((object) array('api_key' => 'user', 'api_secret' => 'pass', 'seller_id' => 'merchant-1', 'hepsiburada_developer_username' => 'developer-user'), array($hb_item));
check($hb_result['trackingId'] === 'tracking-1' && strpos($GLOBALS['hepsiburada_request']['args']['body'], '"merchant":"merchant-1"') !== false, 'Hepsiburada tracking ID or merchant upload failed.');
$hb_test_supplier = (object) array('hepsiburada_environment' => 'test', 'hepsiburada_developer_username' => 'developer-user', 'hepsiburada_test_api_key' => 'test-user', 'hepsiburada_test_api_secret' => 'test-pass', 'hepsiburada_test_seller_id' => 'test-merchant');
check($hepsiburada->api_base_for($hb_test_supplier) === 'https://mpop-sit.hepsiburada.com/product/api', 'Hepsiburada SIT API base failed.');
check($hepsiburada->mapping_option_suffix($hb_test_supplier) === '_test' && $hepsiburada->mapping_option_suffix((object) array()) === '', 'Hepsiburada SIT mapping isolation failed.');
check($hepsiburada->listing_api_base_for($hb_test_supplier) === 'https://listing-external-sit.hepsiburada.com', 'Hepsiburada SIT listing base failed.');
$hepsiburada->push_products($hb_test_supplier, array($hb_item));
check(strpos($GLOBALS['hepsiburada_request']['url'], 'https://mpop-sit.hepsiburada.com/product/api/products/import') === 0 && $GLOBALS['hepsiburada_request']['args']['headers']['Authorization'] === 'Basic ' . base64_encode('test-user:test-pass') && $GLOBALS['hepsiburada_request']['args']['headers']['User-Agent'] === 'developer-user' && strpos($GLOBALS['hepsiburada_request']['args']['body'], '"merchant":"test-merchant"') !== false, 'Hepsiburada SIT credentials, User-Agent or endpoint failed.');
$hepsiburada->responses = array(
    array('data' => array('success' => true, 'totalPages' => 2, 'data' => array(array('merchantSku' => 'SKU1', 'importStatus' => 'PROCESSING')))),
    array('data' => array('success' => true, 'totalPages' => 2, 'data' => array(array('merchantSku' => 'SKU2', 'importStatus' => 'PROCESSING')))),
);
$hb_status = $hepsiburada->get_batch_request_result($hb_test_supplier, 'tracking 1');
check(count($hb_status['data']) === 2 && $hb_status['data'][0]['importStatus'] === 'PROCESSING' && strpos($GLOBALS['hepsiburada_json_request']['url'], 'https://mpop-sit.hepsiburada.com/product/api/products/status/tracking%201?page=1') === 0, 'Hepsiburada SIT tracking status pagination failed.');
check(MultiSync\Sync\ProductPublisher::ciceksepeti_batch_verdict($hb_status) === 'pending', 'Hepsiburada processing status was treated as complete.');
check(MultiSync\Sync\ProductPublisher::ciceksepeti_batch_verdict(array('success' => false)) === 'failed', 'Hepsiburada failed status was treated as complete.');

$hepsiburada->responses = array(
    array('data' => array('data' => array('attributes' => array(array('id' => 'material', 'name' => 'Materyal', 'mandatory' => true, 'type' => 'enum'), array('id' => 'package-front', 'name' => 'Paket Görseli (ön)', 'mandatory' => true, 'type' => 'string')), 'variantAttributes' => array(array('id' => 'color', 'name' => 'Renk', 'mandatory' => false, 'type' => 'string'))))),
    array('data' => array('totalPages' => 1, 'data' => array(array('value' => 'Çelik')))),
);
$hb_attributes = $hepsiburada->fetch_category_attributes((object) array('api_key' => 'user', 'api_secret' => 'pass', 'seller_id' => 'merchant', 'hepsiburada_developer_username' => 'developer-user'), '123');
check(count($hb_attributes) === 3 && $hb_attributes[0]['required'] === true && $hb_attributes[0]['values'][0]['id'] === 'Çelik' && $hb_attributes[1]['required'] === false && $hb_attributes[2]['varianter'] === true, 'Hepsiburada category attributes failed.');
check(strpos($GLOBALS['hepsiburada_json_request']['url'], '/attribute/material/values?version=5') !== false, 'Hepsiburada attribute values endpoint failed.');
$hb_supplier = (object) array('api_key' => 'user', 'api_secret' => 'pass', 'seller_id' => 'merchant', 'hepsiburada_developer_username' => 'developer-user');
check($hepsiburada->user_agent_for($hb_supplier) === 'developer-user', 'Hepsiburada variable User-Agent failed.');
$hepsiburada->responses = array(
    array('data' => array('totalPages' => 2, 'data' => array(array('categoryId' => 122, 'name' => 'Tencere', 'paths' => array('Ev', 'Mutfak', 'Tencere'), 'leaf' => true, 'available' => true)))),
    array('data' => array('totalPages' => 2, 'data' => array(array('categoryId' => 123, 'name' => 'Semaver', 'paths' => array('Ev', 'Mutfak', 'Semaver'), 'leaf' => true, 'available' => true)))),
);
$hb_categories = $hepsiburada->fetch_product_categories($hb_supplier, 'semaver');
check(count($hb_categories) === 1 && $hb_categories[0]['path'] === 'Ev > Mutfak > Semaver', 'Hepsiburada category search failed.');
check(strpos($GLOBALS['hepsiburada_json_request']['url'], 'status=ACTIVE') !== false && strpos($GLOBALS['hepsiburada_json_request']['url'], 'page=1') !== false, 'Hepsiburada category status or pagination failed.');
$hb_brands = $hepsiburada->fetch_product_brands($hb_supplier, 'dem', '123');
check(count($hb_brands) === 1 && $hb_brands[0]['name'] === 'dem' && $hb_brands[0]['id'] === 'dem', 'Hepsiburada free-text brand search failed.');

$hepsiburada->responses = array(
    array('data' => array('data' => array())),
    array('data' => array('data' => array())),
    array('data' => array('data' => array(array('merchantSku' => 'SKU1', 'hbSku' => 'HB1', 'productName' => 'Semaver', 'barcode' => '8691', 'price' => 100, 'stock' => 4)))),
    array('data' => array('data' => array())),
    array('data' => array('data' => array())),
    array('data' => array('data' => array())),
    array('data' => array('data' => array())),
);
$hb_products = $hepsiburada->fetch_products($hb_supplier, array('page' => 0, 'size' => 300));
$hb_mapped = $hepsiburada->map_product($hb_products[0]);
check(count($hb_products) === 1 && $hb_mapped['sku'] === 'SKU1' && $hb_mapped['external_product_id'] === 'HB1', 'Hepsiburada product import mapping failed.');

$hb_inventory = $hepsiburada->build_price_inventory_item_from_product($product, true, true, 10);
check($hb_inventory['merchantSku'] === '8690000000001' && $hb_inventory['availableStock'] === 4 && $hb_inventory['price'] === '111.00', 'Hepsiburada listing payload failed.');
$hb_inventory_result = $hepsiburada->push_price_inventory_updates($hb_test_supplier, array($hb_inventory));
check(!is_wp_error($hb_inventory_result) && strpos($GLOBALS['hepsiburada_request']['url'], 'https://listing-external-sit.hepsiburada.com/listings/merchantid/test-merchant/inventory-uploads') === 0, 'Hepsiburada listing endpoint failed.');
check(strpos($GLOBALS['hepsiburada_request']['args']['body'], '<MerchantSku>8690000000001</MerchantSku>') !== false && strpos($GLOBALS['hepsiburada_request']['args']['body'], '<Price>111,00</Price>') !== false && strpos($GLOBALS['hepsiburada_request']['args']['body'], '<AvailableStock>4</AvailableStock>') !== false, 'Hepsiburada listing XML failed.');

$comparison_method = new ReflectionMethod(MultiSync\Sync\ProductPublisher::class, 'comparison_before_after');
$comparison_method->setAccessible(true);
$comparison = $comparison_method->invoke(
    new MultiSync\Sync\ProductPublisher(),
    $product,
    array('regular_price' => 110.0, 'sale_price' => 95.0, 'stock_quantity' => 4),
    array('listPrice' => 110.0, 'salePrice' => 95.0, 'quantity' => 4)
);
check($comparison['price_changed'] === false && $comparison['stock_changed'] === false && $comparison['price_after'] === '110.00', 'Published product comparison failed for unchanged item.');
$comparison_changed = $comparison_method->invoke(
    new MultiSync\Sync\ProductPublisher(),
    $product,
    array('regular_price' => 130.0, 'sale_price' => '', 'stock_quantity' => 9),
    array('listPrice' => 110.0, 'salePrice' => 110.0, 'quantity' => 4)
);
check($comparison_changed['price_changed'] === true && $comparison_changed['stock_changed'] === true, 'Published product comparison failed for changed item.');

$unchanged_method = new ReflectionMethod(MultiSync\Sync\ProductPublisher::class, 'product_unchanged');
$unchanged_method->setAccessible(true);
check($unchanged_method->invoke(new MultiSync\Sync\ProductPublisher(), array('price' => '100.00', 'availableStock' => 5), array('regular_price' => 100, 'sale_price' => null, 'stock_quantity' => 4)) === false, 'Hepsiburada stock change was ignored.');

echo "marketplace-product-publish-test: ok\n";
