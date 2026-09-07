const marketplaces = new Set(['trendyol', 'n11', 'pazarama', 'ciceksepeti', 'amazon', 'pttavm', 'hepsiburada']);
const text = value => typeof value === 'string' || typeof value === 'number' ? String(value) : '';

export function getImportChoices(entries) {
    const rows = entries.map((entry, index) => {
        const supplier = entry?.supplier || {};
        return {
            id: index,
            source_id: text(entry?.source_id) || index + 1,
            name: text(supplier.name),
            marketplace_key: text(supplier.marketplace_key),
            seller_id: text(supplier.seller_id),
            hepsiburada_test_seller_id: text(supplier.hepsiburada_test_seller_id),
            hepsiburada_environment: text(supplier.hepsiburada_environment),
            active: Number(supplier.active) === 1,
            supported: marketplaces.has(supplier.marketplace_key),
            has_credentials: ['api_key', 'api_secret', 'hepsiburada_test_api_key', 'amazon_refresh_token', 'ptt_rest_api_key', 'ptt_access_token'].some(key => Boolean(text(supplier[key]))),
            mapping_count: Object.values(entry?.mappings || {}).reduce((count, mappings) => count + (Array.isArray(mappings) ? mappings.length : 0), 0),
        };
    });
    const counts = new Map();
    rows.forEach(row => counts.set(row.marketplace_key, (counts.get(row.marketplace_key) || 0) + 1));
    return rows.map(row => ({ ...row, selected: row.supported && counts.get(row.marketplace_key) === 1 }));
}

export function toggleImportChoice(selected, id, rows) {
    if (!rows[id]?.supported) return selected;
    if (selected.includes(id)) return selected.filter(value => value !== id);
    return [...selected.filter(value => rows[value].marketplace_key !== rows[id].marketplace_key), id];
}

export function selectConfiguration(backup, selected) {
    return { ...backup, suppliers: backup.suppliers.filter((entry, index) => selected.includes(index)) };
}
