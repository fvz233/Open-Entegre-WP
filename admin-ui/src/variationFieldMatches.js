const normalize = value => String(value || '').toLocaleLowerCase('tr-TR').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();

export const getCommonVariationOptions = groups => {
    const sources = new Map();
    const targets = new Map();
    groups.forEach(([parentKey, children]) => {
        const first = children[0] || {};
        (first.variation_attribute_options || []).forEach(value => {
            const label = (first.variation_attribute_labels || {})[value] || value;
            const key = normalize(label);
            if (!sources.has(key)) sources.set(key, { key, label, groups: [] });
            sources.get(key).groups.push({ parentKey, children, value });
        });
        (first.variation_target_options || []).forEach(option => {
            const key = normalize(option.name);
            if (!targets.has(key)) targets.set(key, { key, label: option.name, groups: [] });
            targets.get(key).groups.push({ parentKey, children, value: String(option.id) });
        });
    });
    const common = options => [...options.values()].filter(option => option.groups.length > 1);
    return { sources: common(sources), targets: common(targets) };
};
