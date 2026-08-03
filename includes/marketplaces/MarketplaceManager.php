<?php

namespace MultiSync\Marketplaces;

if (!defined('ABSPATH')) {
    exit;
}

class MarketplaceManager
{
    private $adapters = array();

    public function __construct()
    {
        $this->adapters = array(
            'trendyol' => new TrendyolMarketplace(),
            'n11' => new N11Marketplace(),
            'pazarama' => new PazaramaMarketplace(),
            'ciceksepeti' => new CiceksepetiMarketplace(),
            'amazon' => new AmazonMarketplace(),
            'pttavm' => new PttAvmMarketplace(),
            'hepsiburada' => new HepsiburadaMarketplace(),
        );
    }

    public function get($marketplace_key)
    {
        $key = strtolower(trim((string) $marketplace_key));
        if (isset($this->adapters[$key])) {
            return $this->adapters[$key];
        }

        return null;
    }

    public function for_supplier($supplier)
    {
        $marketplace_key = 'trendyol';
        if (is_object($supplier) && isset($supplier->marketplace_key) && $supplier->marketplace_key) {
            $marketplace_key = $supplier->marketplace_key;
        } elseif (is_array($supplier) && !empty($supplier['marketplace_key'])) {
            $marketplace_key = $supplier['marketplace_key'];
        }

        $adapter = $this->get($marketplace_key);
        if ($adapter) {
            return $adapter;
        }

        return $this->get('trendyol');
    }

    public function all()
    {
        return $this->adapters;
    }
}
