<?php
if (!defined('ABSPATH')) exit;

add_action('bulk_edit_custom_box', 'multi_sync_render_product_vat_bulk_edit', 10, 2);
function multi_sync_render_product_vat_bulk_edit($column, $post_type) {
    if ($column !== 'multi_sync_vat' || $post_type !== 'product') return;
    ?>
    <label class="alignright">
        <span class="title">KDV Orani</span>
        <select name="multi_sync_vat_rate">
            <option value=""><?php esc_html_e('— No change —', 'multi-sync'); ?></option>
            <option value="0">%0</option>
            <option value="1">%1</option>
            <option value="10">%10</option>
            <option value="20">%20</option>
        </select>
    </label>
    <?php
}

add_action('wp_ajax_woocommerce_bulk_edit_variations', 'multi_sync_register_bulk_vat', 1);
function multi_sync_register_bulk_vat() {
    $post_ids = !empty($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('absint', $_POST['post_ids']) : array();
    if (empty($post_ids)) return;
    $vat_rate = isset($_POST['multi_sync_vat_rate']) ? trim((string) wp_unslash($_POST['multi_sync_vat_rate'])) : '';
    $valid = in_array($vat_rate, array('0', '1', '10', '20'), true);
    foreach ($post_ids as $post_id) {
        $product = wc_get_product($post_id);
        if (!$product) continue;
        if ($valid) $product->update_meta_data('_multi_sync_vat_rate', $vat_rate);
        else $product->delete_meta_data('_multi_sync_vat_rate');
        $product->delete_meta_data('_multi_sync_vat_rates');
        $product->save();
    }
}
