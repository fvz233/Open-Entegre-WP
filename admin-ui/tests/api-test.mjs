import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { getCommonVariationOptions } from '../src/variationFieldMatches.js';
import { getImportChoices, toggleImportChoice, selectConfiguration } from '../src/configurationSelection.js';

global.window = {
    multiSyncSettings: {
        root: 'https://example.test/wp-json/',
        nonce: 'test-nonce',
    },
};

let request;
global.fetch = async (url, options) => {
    request = { url, options };
    return { ok: true, text: async () => JSON.stringify({ success: true }) };
};

const { default: api } = await import('../src/api.js');
const response = await api.getJobs({ status: 'pending' });

assert.deepEqual(response.data, { success: true });
assert.equal(request.url, 'https://example.test/wp-json/multi-sync/v1/jobs?status=pending');
assert.equal(request.options.headers['X-WP-Nonce'], 'test-nonce');

await api.exportConfiguration();
assert.equal(request.url, 'https://example.test/wp-json/multi-sync/v1/settings/backup');
assert.equal(request.options.method, 'GET');
await api.exportConfiguration([1, 3]);
assert.equal(request.url, 'https://example.test/wp-json/multi-sync/v1/settings/backup?supplier_ids=1%2C3');
await api.getConfigurationSuppliers();
assert.match(request.url, /\/settings\/backup\/suppliers\?_=/);
assert.equal(request.options.headers['X-WP-Nonce'], 'test-nonce');
const settingsBackup = { format: 'open-entegre-settings', version: 1, suppliers: [], options: {} };
await api.importConfiguration(settingsBackup);
assert.equal(request.options.method, 'POST');
assert.equal(request.options.headers['X-WP-Nonce'], 'test-nonce');
assert.deepEqual(JSON.parse(request.options.body), settingsBackup);

const duplicateBackup = { ...settingsBackup, suppliers: [
    { supplier: { name: 'Eski Trendyol', marketplace_key: 'trendyol', api_key: 'old' }, mappings: {} },
    { supplier: { name: 'Güncel Trendyol', marketplace_key: 'trendyol', api_key: 'chosen' }, mappings: { categories: [{}] } },
    { supplier: { name: 'n11', marketplace_key: 'n11' }, mappings: {} },
    { supplier: { name: 'Legacy', marketplace_key: 'custom' }, mappings: {} },
] };
const choices = getImportChoices(duplicateBackup.suppliers);
assert.deepEqual(choices.filter(row => row.selected).map(row => row.id), [2]);
assert.equal(choices[1].mapping_count, 1);
assert.equal(choices[1].has_credentials, true);
let selected = toggleImportChoice([2], 0, choices);
selected = toggleImportChoice(selected, 1, choices);
assert.deepEqual(selected, [2, 1]);
assert.deepEqual(toggleImportChoice(selected, 3, choices), selected);
assert.deepEqual(selectConfiguration(duplicateBackup, selected).suppliers, [duplicateBackup.suppliers[1], duplicateBackup.suppliers[2]]);
assert.deepEqual(toggleImportChoice(selected, 1, choices), [2]);
assert.deepEqual(selectConfiguration(duplicateBackup, []).suppliers, []);
assert.equal(duplicateBackup.suppliers.length, 4);

await api.getMarketplaceCategoryMappings(7);
assert.match(request.url, /^https:\/\/example\.test\/wp-json\/multi-sync\/v1\/marketplaces\/category-mappings\/7\?_\=\d+$/);

global.fetch = async () => ({
    ok: false,
    status: 500,
    statusText: 'Server Error',
    text: async () => JSON.stringify({ code: 'save_failed', message: 'Kaydedilemedi' }),
});

await assert.rejects(
    api.getSuppliers(),
    error => error.message === 'Kaydedilemedi' && error.response.data.code === 'save_failed'
);

global.fetch = async () => ({
    ok: false,
    status: 502,
    statusText: 'Bad Gateway',
    text: async () => '<html>proxy error</html>',
});

await assert.rejects(
    api.getSuppliers(),
    error => error.message === 'Bad Gateway' && error.response.status === 502
);

global.fetch = (url, options) => new Promise((resolve, reject) => {
    options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
});

await assert.rejects(
    api.refreshQuestions(null, { timeout: 1 }),
    error => error.code === 'ECONNABORTED'
);

const appSource = readFileSync(new URL('../src/App.jsx', import.meta.url), 'utf8');
const settingsSource = readFileSync(new URL('../src/components/Tabs/SyncSettings.jsx', import.meta.url), 'utf8');
const categoryMappingSource = readFileSync(new URL('../src/components/MarketplaceCategoryMapping.jsx', import.meta.url), 'utf8');
const productSelectorSource = readFileSync(new URL('../src/components/ProductSelectorModal.jsx', import.meta.url), 'utf8');
const baseMarketplaceSource = readFileSync(new URL('../../includes/marketplaces/BaseMarketplace.php', import.meta.url), 'utf8');
assert.match(appSource, /Eşleştirmeler/);
assert.match(appSource, /questionMarketplaces = new Set\(\['trendyol'\]\)/);
assert.doesNotMatch(settingsSource, /TrendyolCategoryMapping/);
assert.match(settingsSource, /watchStockPriceJob\(jobId\)/);
assert.match(settingsSource, /\['completed', 'failed', 'cancelled', 'waiting_remote'\]/);
assert.match(settingsSource, /setPublishPopup\(true\)/);
assert.match(baseMarketplaceSource, /strpos\(\$send_target, 'stock'\)/);
assert.match(baseMarketplaceSource, /strpos\(\$send_target, 'price'\)/);
assert.match(categoryMappingSource, /onClick=\{\(\) => selectWooCategory\(categoryId\)\}[^>]*>Düzenle<\/button>/);
assert.match(categoryMappingSource, /attribute\.required && 'zorunlu'/);
assert.match(categoryMappingSource, /attribute\.slicer \|\| attribute\.varianter/);
assert.match(categoryMappingSource, /'isteğe bağlı'/);
assert.match(categoryMappingSource, /supplier\.marketplace_key === 'n11'.*WooCommerce marka adından alınır\./s);
assert.doesNotMatch(categoryMappingSource, /supplier\.marketplace_key !== 'n11'/);
assert.match(productSelectorSource, /✓ \$\{commonVariationApplied\} ürüne uygulandı/);

const commonVariationOptions = getCommonVariationOptions([
        ['one', [{ variation_attribute_options: ['option'], variation_attribute_labels: { option: 'Seçenek' }, variation_target_options: [{ id: 2, name: 'Renk' }] }]],
        ['two', [{ variation_attribute_options: ['pa_option'], variation_attribute_labels: { pa_option: 'SEÇENEK' }, variation_target_options: [{ id: 9, name: 'RENK' }] }]],
        ['three', [{ variation_attribute_options: ['size'], variation_attribute_labels: { size: 'Beden' }, variation_target_options: [{ id: 5, name: 'Beden' }] }]],
]);
assert.deepEqual(commonVariationOptions.sources.map(option => [option.label, option.groups.map(group => group.parentKey)]), [['Seçenek', ['one', 'two']]]);
assert.deepEqual(commonVariationOptions.targets.map(option => [option.label, option.groups.map(group => [group.parentKey, group.value])]), [['Renk', [['one', '2'], ['two', '9']]]]);

console.log('api-test: ok');
