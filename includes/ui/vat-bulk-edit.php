<?php
if (!defined('ABSPATH')) exit;

// Render KDV dropdown at end of WC bulk edit form
add_action('woocommerce_product_bulk_edit_end', 'multi_sync_render_product_vat_bulk_edit');
function multi_sync_render_product_vat_bulk_edit() {
    ?>
    <label>
        <span class="title">KDV Orani</span>
        <span class="input-text-wrap">
            <select name="multi_sync_vat_rate">
                <option value=""><?php esc_html_e('— No change —', 'multi-sync'); ?></option>
                <option value="0">%0</option>
                <option value="1">%1</option>
                <option value="10">%10</option>
                <option value="20">%20</option>
            </select>
        </span>
    </label>
    <?php
}

// Save on bulk edit
add_action('woocommerce_product_bulk_edit_save', 'multi_sync_save_product_vat_bulk_edit');
function multi_sync_save_product_vat_bulk_edit($product) {
    if (!$product || !isset($_REQUEST['multi_sync_vat_rate'])) return;
    $vat_rate = trim((string) wp_unslash($_REQUEST['multi_sync_vat_rate']));
    if ($vat_rate === '') return; // no change
    $valid = in_array($vat_rate, array('0', '1', '10', '20'), true);
    if ($valid) {
        $product->update_meta_data('_multi_sync_vat_rate', $vat_rate);
    } else {
        $product->delete_meta_data('_multi_sync_vat_rate');
    }
    $product->delete_meta_data('_multi_sync_vat_rates');
    // save handled by WC's bulk_edit_save after this hook fires
}
