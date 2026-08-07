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
function esc_url_raw($value) { return (string) $value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wp_generate_password($length = 12) { return str_repeat('x', $length); }
function wp_get_attachment_url($id) { return $id ? 'http://example.test/' . $id . '.jpg' : ''; }
function wp_remote_request($url, $args) { $GLOBALS['hepsiburada_request'] = array('url' => $url, 'args' => $args); return array('code' => 200, 'body' => '{"success":true,"data":{"trackingId":"tracking-1"}}'); }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function taxonomy_exists($name) { return false; }
function remove_accents($value) { return strtr($value, array('ı' => 'i', 'İ' => 'I', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U', 'ö' => 'o', 'Ö' => 'O', 'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G')); }
function wc_get_product($id) { return (int) $id === 21 ? ($GLOBALS['hepsiburada_parent'] ?? null) : null; }
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
    protected function request_json($method, $url, $supplier, $body = null) { return array_shift($this->responses); }
    public function multipart($items) { return $this->build_multipart_body($items, 'Boundary'); }
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
    'attributes' => array(array('attributeId' => '1', 'attributeValueIds' => array('99'))),
    'attribute_definitions' => array(array('id' => '1', 'name' => 'Marka', 'required' => true, 'values' => array(array('id' => '99', 'name' => 'Demsu')))),
), array(
    'category_id' => '100', 'shipment_template' => 'Standart', 'vat_rate' => '20',
));
check(!is_wp_error($n11) && $n11['stockCode'] === '8690000000001' && $n11['attributes'][0]['valueId'] === 99 && $n11['salePrice'] === 111.0, 'n11 product payload failed.');
check($n11['images'][0]['url'] === 'http://example.test/7.jpg', 'n11 HTTP image mapping failed.');
$n11_commission = (new MultiSync\Marketplaces\N11Marketplace())->build_price_inventory_item_from_product($product, false, true, 10);
check($n11_commission['listPrice'] === 133.0 && $n11_commission['salePrice'] === 111.0, 'Category commission was not applied to marketplace prices.');

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
$hb_result = $hepsiburada->push_products((object) array('api_key' => 'user', 'api_secret' => 'pass', 'seller_id' => 'merchant-1'), array($hb_item));
check($hb_result['trackingId'] === 'tracking-1' && strpos($GLOBALS['hepsiburada_request']['args']['body'], '"merchant":"merchant-1"') !== false, 'Hepsiburada tracking ID or merchant upload failed.');

$hepsiburada->responses = array(
    array('data' => array('data' => array(array('id' => 'brand', 'name' => 'Marka', 'mandatory' => true, 'type' => 'enum')))),
    array('data' => array('data' => array(array('id' => 'demsu', 'value' => 'Demsu')))),
);
$hb_attributes = $hepsiburada->fetch_category_attributes((object) array('api_key' => 'user', 'api_secret' => 'pass', 'seller_id' => 'merchant'), '123');
check($hb_attributes[0]['required'] === true && $hb_attributes[0]['values'][0]['name'] === 'Demsu', 'Hepsiburada category attributes failed.');
$hb_supplier = (object) array('api_key' => 'user', 'api_secret' => 'pass', 'seller_id' => 'merchant');
$hepsiburada->responses = array(array('data' => array('data' => array(array('categoryId' => 123, 'name' => 'Semaver', 'paths' => 'Ev > Mutfak > Semaver', 'leaf' => true, 'available' => true)))));
$hb_categories = $hepsiburada->fetch_product_categories($hb_supplier, 'semaver');
check(count($hb_categories) === 1 && $hb_categories[0]['path'] === 'Ev > Mutfak > Semaver', 'Hepsiburada category search failed.');
$hepsiburada->responses = array(
    array('data' => array('data' => array(array('id' => 'brand', 'name' => 'Marka', 'mandatory' => true, 'type' => 'enum')))),
    array('data' => array('data' => array(array('id' => 'demsu', 'value' => 'Demsu'), array('id' => 'other', 'value' => 'Diger')))),
);
$hb_brands = $hepsiburada->fetch_product_brands($hb_supplier, 'dem', '123');
check(count($hb_brands) === 1 && $hb_brands[0]['name'] === 'Demsu', 'Hepsiburada brand search failed.');

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

echo "marketplace-product-publish-test: ok\n";
