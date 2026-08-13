<?php
if (!defined('ABSPATH')) exit;

// Render shared product fields at end of WC bulk edit form
add_action('woocommerce_product_bulk_edit_end', 'multi_sync_render_product_vat_bulk_edit');
function multi_sync_render_product_vat_bulk_edit() {
    ?>
    <label>
        <span class="title">Desi</span>
        <span class="input-text-wrap">
            <input type="number" name="multi_sync_desi" min="0" step="0.01" placeholder="<?php esc_attr_e('— No change —', 'multi-sync'); ?>">
        </span>
    </label>
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
    if (!$product) return;
    $changed = false;
    if (isset($_REQUEST['multi_sync_desi']) && trim((string) wp_unslash($_REQUEST['multi_sync_desi'])) !== '') {
        $desi = wc_format_decimal(wp_unslash($_REQUEST['multi_sync_desi']));
        if (is_numeric($desi) && (float) $desi >= 0) {
            $product->update_meta_data('_multi_sync_desi', $desi);
            $product->delete_meta_data('_multi_sync_trendyol_dimensional_weight');
            $changed = true;
        }
    }
    if (isset($_REQUEST['multi_sync_vat_rate']) && trim((string) wp_unslash($_REQUEST['multi_sync_vat_rate'])) !== '') {
        $vat_rate = trim((string) wp_unslash($_REQUEST['multi_sync_vat_rate']));
        if (in_array($vat_rate, array('0', '1', '10', '20'), true)) {
            $product->update_meta_data('_multi_sync_vat_rate', $vat_rate);
        } else {
            $product->delete_meta_data('_multi_sync_vat_rate');
        }
        $product->delete_meta_data('_multi_sync_vat_rates');
        $changed = true;
    }
    // WC fires this hook AFTER $product->save() (class-wc-admin-post-types.php), so persist meta explicitly.
    if ($changed) $product->save_meta_data();
}
