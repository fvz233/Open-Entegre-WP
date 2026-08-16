import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { getCommonVariationOptions } from '../src/variationFieldMatches.js';

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
assert.match(appSource, /Eşleştirmeler/);
assert.match(appSource, /questionMarketplaces = new Set\(\['trendyol'\]\)/);
assert.doesNotMatch(settingsSource, /TrendyolCategoryMapping/);
assert.match(categoryMappingSource, /onClick=\{\(\) => selectWooCategory\(categoryId\)\}[^>]*>Düzenle<\/button>/);
assert.match(categoryMappingSource, /attribute\.required && 'zorunlu'/);
assert.match(categoryMappingSource, /attribute\.slicer \|\| attribute\.varianter/);
assert.match(categoryMappingSource, /'isteğe bağlı'/);
assert.match(categoryMappingSource, /supplier\.marketplace_key === 'n11'.*WooCommerce marka adından alınır\./s);
assert.match(categoryMappingSource, /supplier\.marketplace_key !== 'n11' && <div style=\{sectionStyle\}>/);
assert.match(productSelectorSource, /✓ \$\{commonVariationApplied\} ürüne uygulandı/);

const commonVariationOptions = getCommonVariationOptions([
        ['one', [{ variation_attribute_options: ['option'], variation_attribute_labels: { option: 'Seçenek' }, variation_target_options: [{ id: 2, name: 'Renk' }] }]],
        ['two', [{ variation_attribute_options: ['pa_option'], variation_attribute_labels: { pa_option: 'SEÇENEK' }, variation_target_options: [{ id: 9, name: 'RENK' }] }]],
        ['three', [{ variation_attribute_options: ['size'], variation_attribute_labels: { size: 'Beden' }, variation_target_options: [{ id: 5, name: 'Beden' }] }]],
]);
assert.deepEqual(commonVariationOptions.sources.map(option => [option.label, option.groups.map(group => group.parentKey)]), [['Seçenek', ['one', 'two']]]);
assert.deepEqual(commonVariationOptions.targets.map(option => [option.label, option.groups.map(group => [group.parentKey, group.value])]), [['Renk', [['one', '2'], ['two', '9']]]]);

console.log('api-test: ok');
