<?php

$plugin = file_get_contents(dirname(__DIR__) . '/plugin.php');
$app = file_get_contents(dirname(__DIR__) . '/admin-ui/src/App.jsx');

foreach (array(
    array($plugin, 'admin_post_multi_sync_update'),
    array($plugin, "check_admin_referer('multi_sync_update')"),
    array($plugin, '$myUpdateChecker->checkForUpdates()'),
    array($plugin, "add_query_arg('multi_sync_update', \$status, \$panel_url)"),
    array($app, "updateStatus === 'current'"),
) as [$source, $expected]) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Missing update-button wiring: {$expected}");
    }
}

echo "update-button-test: ok\n";
