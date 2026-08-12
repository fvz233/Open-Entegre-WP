<?php

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);

class WP_Error
{
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value); }
function wp_get_attachment_url($id) { return $id ? 'http://example.test/' . $id . '.jpg' : ''; }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function esc_url_raw($value) { return (string) $value; }
function taxonomy_exists($taxonomy) { return false; }
function wc_attribute_label($name, $product = null) { return $name === 'pa_renk' ? 'Renk' : $name; }
function wc_get_product($id) { return (int) $id === 99 ? $GLOBALS['publish_parent'] : null; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function get_ancestors($id, $taxonomy) { return array(3 => array(2, 1), 2 => array(1), 1 => array())[(int) $id] ?? array(); }
function get_object_taxonomies($type) { return array('product_brand'); }
function wp_get_object_terms($id, $taxonomy) { return array((object) array('term_id' => 5, 'name' => 'Demsu')); }

require_once dirname(__DIR__) . '/includes/marketplaces/MarketplaceInterface.php';
require_once dirname(__DIR__) . '/includes/marketplaces/BaseMarketplace.php';
require_once dirname(__DIR__) . '/includes/marketplaces/TrendyolMarketplace.php';
require_once dirname(__DIR__) . '/includes/sync/ProductPublisher.php';

class PublishProduct
{
    public function is_type($type) { return $type === 'simple'; }
    public function get_sku() { return 'SKU-1'; }
    public function get_meta($key) { return array(
        '_multi_sync_trendyol_brand_id' => '12', '_multi_sync_trendyol_category_id' => '34',
        '_multi_sync_trendyol_dimensional_weight' => '2',
        '_multi_sync_vat_rate' => '20', '_multi_sync_trendyol_attributes' => '[{"attributeId":1,"attributeValueId":2}]',
    )[$key] ?? ''; }
    public function get_stock_quantity() { return 5; }
    public function get_regular_price() { return '100'; }
    public function get_sale_price() { return '90'; }
    public function get_image_id() { return 7; }
    public function get_gallery_image_ids() { return array(); }
    public function get_description() { return 'Description'; }
    public function get_short_description() { return ''; }
    public function get_name() { return 'Product'; }
    public function get_id() { return 77; }
    public function get_category_ids() { return array(3); }
    public function get_attributes() { return array(); }
}

class PublishTrendyolFixture extends MultiSync\Marketplaces\TrendyolMarketplace
{
    public $url;
    public $body;
    protected function request_json($method, $url, $supplier, $body = null)
    {
        $this->url = $url;
        $this->body = $body;
        return array('data' => array('batchRequestId' => 'batch-1'));
    }
}

class CategoryTrendyolFixture extends MultiSync\Marketplaces\TrendyolMarketplace
{
    protected function request_json($method, $url, $supplier, $body = null)
    {
        if (strpos($url, '/orders?') !== false) {
            return array('data' => array('content' => array(array('lines' => array(
                array('productCategoryId' => 2, 'commission' => 15),
            )))));
        }
        if (strpos($url, '/values?') !== false) {
            return array('data' => array('content' => array(array('attributeValueId' => 9, 'attributeValue' => 'Pamuk'))));
        }
        if (strpos($url, '/attributes') !== false) {
            return array('data' => array('categoryAttributes' => array(array(
                'attribute' => array('id' => 8, 'name' => 'Materyal'),
                'required' => true,
                'allowCustom' => false,
                'allowMultipleAttributeValues' => false,
            ))));
        }
        return array('data' => array('categories' => array(array(
            'id' => 1,
            'name' => 'Giyim',
            'subCategories' => array(array('id' => 2, 'name' => 'Tişört', 'subCategories' => array())),
        ))));
    }
}

class CategoryMappedProduct extends PublishProduct
{
    public function get_meta($key)
    {
        if (in_array($key, array('_multi_sync_trendyol_category_id', '_multi_sync_trendyol_attributes'), true)) {
            return '';
        }
        return parent::get_meta($key);
    }
}

class ColorVariationProduct extends PublishProduct
{
    private $color;
    public function __construct($color) { $this->color = $color; }
    public function is_type($type) { return $type === 'variation'; }
    public function get_parent_id() { return 99; }
    public function get_attributes() { return array('pa_renk' => $this->color); }
    public function get_meta($key) { return ''; }
    public function get_sku() { return 'VAR-' . md5($this->color); }
    public function get_name() { return 'Çay Kazanı - ' . $this->color; }
}

class DesignVariationProduct extends ColorVariationProduct
{
    public function get_attributes() { return array('pa_renk' => 'Gümüş', 'pa_tasarim' => 'Osmanlı Arması'); }
}

$item = (new MultiSync\Marketplaces\TrendyolMarketplace())->build_product_item_from_product(new PublishProduct());
check(!is_wp_error($item), 'Valid Woo product was rejected.');
check($item['barcode'] === 'SKU-1' && $item['categoryId'] === 34 && $item['salePrice'] === 90.0, 'Trendyol payload mapping failed.');
check($item['images'][0]['url'] === 'http://example.test/7.jpg', 'HTTP image mapping failed.');
$fixture = new PublishTrendyolFixture();
$result = $fixture->push_products((object) array('api_key' => 'key', 'api_secret' => 'secret', 'seller_id' => '42'), array($item));
check($result['batchRequestId'] === 'batch-1', 'Batch ID was not returned.');
check(strpos($fixture->url, '/sellers/42/v2/products') !== false && $fixture->body['items'][0]['barcode'] === 'SKU-1', 'Trendyol V2 publish request is wrong.');
$category_fixture = new CategoryTrendyolFixture();
$supplier = (object) array('api_key' => 'key', 'api_secret' => 'secret', 'seller_id' => '42');
$categories = $category_fixture->fetch_product_categories($supplier, 'tişört');
check($categories === array(array('id' => 2, 'name' => 'Tişört', 'path' => 'Giyim > Tişört')), 'Leaf category mapping failed.');
$commission_rates = $category_fixture->fetch_category_commission_rates($supplier);
check($commission_rates === array('2' => 15.0), 'Trendyol category commission was not resolved from recent orders.');
$attributes = $category_fixture->fetch_category_attributes($supplier, 2);
check($attributes[0]['id'] === 8 && $attributes[0]['values'][0]['id'] === 9, 'Required Trendyol attributes were not fetched.');
$mapped = $category_fixture->build_product_item_from_product(new CategoryMappedProduct(), array(
    'category_id' => 2,
    'commission_rate' => 15,
    'attributes' => array(array('attributeId' => 8, 'attributeValueIds' => array(9))),
));
check($mapped['categoryId'] === 2 && $mapped['attributes'][0]['attributeValueIds'] === array(9), 'Saved category mapping was not applied.');
check($mapped['listPrice'] === 118.0 && $mapped['salePrice'] === 106.0, 'Category commission was not applied to Trendyol preview prices.');
$GLOBALS['publish_parent'] = new PublishProduct();
$unmapped_variation = $category_fixture->build_product_item_from_product(new ColorVariationProduct('Inox'), array(
    'category_id' => 34,
    'attribute_definitions' => array(array('id' => 99, 'name' => 'Renk', 'required' => true, 'slicer' => true, 'allow_custom' => true, 'values' => array())),
));
$unmapped_fields = is_wp_error($unmapped_variation) ? array_column($unmapped_variation->get_error_data()['fields'], 'key') : array();
check(in_array('variation_attribute', $unmapped_fields, true) && in_array('variation_target_attribute_id', $unmapped_fields, true), 'Unselected variation mapping was accepted.');
foreach (array('Osmanlı Arması', 'Türk Bayraklı', 'Inox') as $color) {
    $variation = $category_fixture->build_product_item_from_product(new ColorVariationProduct($color), array(
        'category_id' => 34,
        'attribute_definitions' => array(array('id' => 99, 'name' => 'Renk', 'required' => true, 'allow_custom' => true, 'values' => array())),
    ), array('variation_attribute' => 'pa_renk', 'variation_target_attribute_id' => '99'));
    check(!is_wp_error($variation) && $variation['attributes'][1]['attributeValue'] === $color, 'Woo color variation was not sent as Trendyol Renk: ' . $color);
}
$selected_design = $category_fixture->build_product_item_from_product(new DesignVariationProduct('unused'), array(
    'category_id' => 34,
    'attribute_definitions' => array(array('id' => 99, 'name' => 'Renk', 'required' => true, 'allow_custom' => true, 'values' => array())),
), array('variation_attribute' => 'pa_tasarim', 'variation_target_attribute_id' => '99'));
check(!is_wp_error($selected_design) && $selected_design['attributes'][1]['attributeValue'] === 'Osmanlı Arması', 'Selected Woo variation field was not sent as Trendyol Renk.');
$mapping_method = new ReflectionMethod(MultiSync\Sync\ProductPublisher::class, 'category_mapping');
$mapping_method->setAccessible(true);
$category_product = new class { public function is_type($type) { return false; } public function get_category_ids() { return array(2, 3); } public function get_id() { return 77; } };
$resolved = $mapping_method->invoke(new MultiSync\Sync\ProductPublisher(), $category_product, array(2 => array('category_id' => 20), 3 => array('category_id' => 30)));
check($resolved['category_id'] === 30, 'The deepest Woo category mapping was not selected.');
$product_mapping_method = new ReflectionMethod(MultiSync\Sync\ProductPublisher::class, 'product_mapping');
$product_mapping_method->setAccessible(true);
$manual_commission = $product_mapping_method->invoke(new MultiSync\Sync\ProductPublisher(), $category_product, array(
    'mappings' => array(3 => array('category_id' => 30, 'commission_rate' => 10)),
    'brand_mappings' => array(),
    'commission_rates' => array('30' => 15),
    'marketplace_key' => 'trendyol',
));
check($manual_commission['commission_rate'] === 10, 'Manual category commission must override the API rate.');
$n11_brand_fallback = $product_mapping_method->invoke(new MultiSync\Sync\ProductPublisher(), new PublishProduct(), array(
    'mappings' => array(3 => array('category_id' => 30)),
    'brand_mappings' => array('product_brand:5' => array('brand_id' => '12', 'brand_name' => 'Eski Esleme')),
    'commission_rates' => array(),
    'marketplace_key' => 'n11',
));
check($n11_brand_fallback['brand_name'] === 'Demsu' && empty($n11_brand_fallback['brand_id']), 'n11 did not use the WooCommerce brand name directly.');
$attribute_fields_method = new ReflectionMethod(MultiSync\Sync\ProductPublisher::class, 'attribute_fields');
$attribute_fields_method->setAccessible(true);
$attribute_fields = $attribute_fields_method->invoke(new MultiSync\Sync\ProductPublisher(), array(
    'attributes' => array(array('attributeId' => 8, 'attributeValueIds' => array(9))),
    'attribute_definitions' => array(array('id' => 8, 'name' => 'Materyal', 'values' => array(array('id' => 9, 'name' => 'Pamuk')))),
));
check($attribute_fields[0]['key'] === 'attribute_8' && $attribute_fields[0]['matched_label'] === 'Pamuk', 'Category attributes were not exposed for per-product overrides.');
$publisher_source = file_get_contents(dirname(__DIR__) . '/includes/sync/ProductPublisher.php');
check(strpos($publisher_source, '$supports_update && !$product_overrides') !== false, 'Attribute overrides would be ignored for an already-published product.');
$brand_method = new ReflectionMethod(MultiSync\Sync\ProductPublisher::class, 'brand_mapping');
$brand_method->setAccessible(true);
$brand = $brand_method->invoke(new MultiSync\Sync\ProductPublisher(), new PublishProduct(), array('product_brand:5' => array('brand_id' => '12', 'brand_name' => 'Demsu')));
check($brand['brand_id'] === '12', 'Woo brand mapping was not resolved for marketplace export.');
echo "trendyol-product-publish-test: ok\n";
