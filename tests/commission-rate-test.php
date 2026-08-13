<?php

define('ABSPATH', __DIR__);
require_once dirname(__DIR__) . '/includes/sync/ProductImporter.php';

use function MultiSync\Sync\resolve_commission_rate;

$cases = array(
    array(array(), 'trendyol', 12, 12.0),
    array(array('trendyol' => 8.5), 'trendyol', 12, 12.0),
    array(array('trendyol' => 0), 'trendyol', 12, 12.0),
);

foreach ($cases as $case) {
    if (resolve_commission_rate($case[0], $case[1], $case[2]) !== $case[3]) {
        throw new RuntimeException('Commission rate resolution failed.');
    }
}
