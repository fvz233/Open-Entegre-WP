<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

interface MarketplaceInterface
{
    public function get_key();

    public function get_label();

    public function validate_credentials($supplier);

    public function fetch_products($supplier, $params = array());

    public function fetch_orders($supplier, $params = array());

    public function map_product($raw_item);

    public function map_order($raw_item);

    public function build_price_inventory_item_from_product($product, $sync_stock = true, $sync_price = true);

    public function push_price_inventory_updates($supplier, $items);

    public function get_batch_request_result($supplier, $batch_request_id);
}
