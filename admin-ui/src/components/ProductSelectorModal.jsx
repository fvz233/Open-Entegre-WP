import React, { useState, useEffect } from 'react';
import api from '../api';
import { getCommonVariationOptions } from '../variationFieldMatches';

function ProductSelectorModal({
    supplier,
    onClose,
    onSync,
    previewType = 'product',
    syncStock = true,
    syncPrice = false,
    title = 'Ice Aktarilacak Urunleri Sec',
    submitText = 'Senkronu Baslat',
    emptySelectionConfirm = 'Hic urun secilmedi. Tum urunler senkron edilsin mi?',
}) {
    const isStockPricePreview = previewType === 'stock_price';
    const isProductPublishPreview = previewType === 'product_publish';
    const isProductImportPreview = !isStockPricePreview && !isProductPublishPreview;
    const itemKey = (item) => item.selection_key || item.sku;
    const [items, setItems] = useState([]);
    const [stokKodsuzItems, setStokKodsuzItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedSkus, setSelectedSkus] = useState(new Set());
    const [filter, setFilter] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [previewWarning, setPreviewWarning] = useState('');
    const [publishValues, setPublishValues] = useState({});
    const [productTab, setProductTab] = useState('simple');
    const [variationChoices, setVariationChoices] = useState({});
    const [variationTargetChoices, setVariationTargetChoices] = useState({});
    const [commonVariationSource, setCommonVariationSource] = useState('');
    const [commonVariationTarget, setCommonVariationTarget] = useState('');
    const [commonVariationApplied, setCommonVariationApplied] = useState(0);
    const [expandedProducts, setExpandedProducts] = useState(new Set());

    useEffect(() => {
        fetchPreview();
    }, [supplier, previewType, syncStock, syncPrice]);

    const fetchPreview = async () => {
        setLoading(true);
        try {
            if (isStockPricePreview) {
                const res = await api.previewStockPriceSync(
                    supplier.id,
                    syncStock,
                    syncPrice
                );

                if (res.data.success && Array.isArray(res.data.items)) {
                    setItems(res.data.items);
                    setStokKodsuzItems([]);
                    setPreviewWarning(res.data.warning || '');
                    setSelectedSkus(new Set());
                } else {
                    setItems([]);
                    setStokKodsuzItems([]);
                    setPreviewWarning('');
                    alert(res.data?.message || 'Onizleme yuklenemedi.');
                }
            } else if (isProductPublishPreview) {
                const res = await api.previewProductPublish(supplier.id);
                const previewItems = res.data?.success && Array.isArray(res.data.items) ? res.data.items : [];
                setItems(previewItems);
                setStokKodsuzItems([]);
                setPreviewWarning(res.data?.catalog_error || '');
                setSelectedSkus(new Set());
                setCategoryFilter('');
                setStatusFilter('');
                setPublishValues({});
                setVariationChoices({});
                setVariationTargetChoices({});
                setCommonVariationSource('');
                setCommonVariationTarget('');
                setCommonVariationApplied(0);
                setExpandedProducts(new Set());
                setProductTab('simple');
            } else {
                const res = await api.previewSync(supplier.id);
                if (res.data.success && Array.isArray(res.data.items)) {
                    setItems(res.data.items);
                    setStokKodsuzItems(Array.isArray(res.data.stok_kodsuz) ? res.data.stok_kodsuz : []);
                    setPreviewWarning('');
                    setSelectedSkus(new Set());
                    const choices = {};
                    res.data.items.forEach(item => {
                        const options = Array.isArray(item.variation_attribute_options) ? item.variation_attribute_options : [];
                        if (item.variation_parent_key && options.length && !choices[item.variation_parent_key]) {
                            choices[item.variation_parent_key] = options[0];
                        }
                    });
                    setVariationChoices(choices);
                    setProductTab('simple');
                } else {
                    setItems([]);
                    setStokKodsuzItems([]);
                    setPreviewWarning('');
                    alert('Onizleme yuklenemedi.');
                }
            }
        } catch (e) {
            console.error(e);
            setItems([]);
            setStokKodsuzItems([]);
            setPreviewWarning('');
            alert(e.response?.data?.message || e.message || 'Onizleme yuklenirken hata olustu.');
        }
        setLoading(false);
    };

    const handleToggle = (sku) => {
        const newSet = new Set(selectedSkus);
        if (newSet.has(sku)) {
            newSet.delete(sku);
        } else {
            newSet.add(sku);
        }
        setSelectedSkus(newSet);
    };

    const handleSelectAll = (e) => {
        if (e.target.checked) {
            const allSkus = visibleItems
                .filter(i => itemKey(i) && i.can_push !== false && (isProductPublishPreview ? isPublishReady(i) : i.can_import !== false))
                .map(itemKey);
            setSelectedSkus(new Set(allSkus));
        } else {
            setSelectedSkus(new Set());
        }
    };

    const updatePublishValue = (item, key, value) => {
        const id = itemKey(item);
        setPublishValues(current => ({ ...current, [id]: { ...(current[id] || {}), [key]: value } }));
    };

    const getPublishValue = (item, field) => publishValues[itemKey(item)]?.[field.key] || '';

    const toggleExpandedProduct = (item) => setExpandedProducts(current => {
        const next = new Set(current);
        const key = itemKey(item);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        return next;
    });

    const hasPublishDetails = (item) => Array.isArray(item.attribute_fields) && item.attribute_fields.length > 0;

    const renderAttributeOverrides = (item) => Array.isArray(item.attribute_fields) && item.attribute_fields.length > 0 && (
        <div style={{ marginBottom: item.catalog_comparison ? '12px' : 0 }}>
            <strong style={{ display: 'block', marginBottom: '7px' }}>Özellikleri değiştir</strong>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px 16px' }}>
                {item.attribute_fields.map(field => (
                    <label key={field.key} style={{ display: 'grid', gap: '4px', minWidth: '220px', fontSize: '12px' }}>
                        {field.label}
                        {field.type === 'select' ? (
                            <select value={getPublishValue(item, field)} onChange={e => updatePublishValue(item, field.key, e.target.value)}>
                                <option value="">{field.matched_label || '-'}</option>
                                {(field.options || []).map(option => <option key={option.id} value={option.id}>{option.name}</option>)}
                            </select>
                        ) : (
                            <input value={getPublishValue(item, field)} placeholder={field.matched_label || ''} onChange={e => updatePublishValue(item, field.key, e.target.value)} />
                        )}
                    </label>
                ))}
            </div>
        </div>
    );

    const isVariationFieldResolved = (item, field) => {
        const values = publishValues[itemKey(item)] || {};
        return values.variation_attribute && field.key === `attribute_${values.variation_target_attribute_id}`;
    };

    const unresolvedMissingFields = (item) => (Array.isArray(item.missing_fields) ? item.missing_fields : []).filter(field =>
        getPublishValue(item, field) === '' && !isVariationFieldResolved(item, field)
    );

    const publishWarning = (item) => {
        const fields = unresolvedMissingFields(item);
        return fields.length ? `Eksik: ${fields.map(field => field.label).join(', ')}` : item.preview_warning;
    };

    const isPublishReady = (item) => {
        const fields = Array.isArray(item.missing_fields) ? item.missing_fields : [];
        return item.can_import !== false || (fields.length > 0 && unresolvedMissingFields(item).length === 0);
    };

    const categories = [...new Set(items.flatMap(item => Array.isArray(item.category_names) ? item.category_names : []))].sort((a, b) => a.localeCompare(b, 'tr'));

    const filteredItems = items.filter(item => {
        if (categoryFilter && !(Array.isArray(item.category_names) && item.category_names.includes(categoryFilter))) return false;
        if (statusFilter && item.upload_action !== statusFilter) return false;
        if (!filter) return true;
        const search = filter.toLowerCase();
        return (item.name && item.name.toLowerCase().includes(search)) ||
            (item.sku && item.sku.toLowerCase().includes(search)) ||
            (item.external_sku && item.external_sku.toLowerCase().includes(search)) ||
            (item.status_text && item.status_text.toLowerCase().includes(search));
    });

    const filteredStokKodsuzItems = stokKodsuzItems.filter(item => {
        if (!filter) return true;
        const search = filter.toLowerCase();
        return (item.name && item.name.toLowerCase().includes(search)) ||
            (item.merchant_sku && item.merchant_sku.toLowerCase().includes(search));
    });

    const simpleItems = filteredItems.filter(item => item.row_type !== 'variation');
    const variableItems = filteredItems.filter(item => item.row_type === 'variation');
    const groupVariations = variationItems => Object.entries(variationItems.reduce((groups, item) => {
        (groups[item.variation_parent_key] ||= []).push(item);
        return groups;
    }, {}));
    const variableGroups = groupVariations(variableItems);
    const allVariableGroups = groupVariations(items.filter(item => item.row_type === 'variation'));
    const commonVariationOptions = getCommonVariationOptions(allVariableGroups);
    const selectedCommonSource = commonVariationOptions.sources.find(option => option.key === commonVariationSource);
    const selectedCommonTarget = commonVariationOptions.targets.find(option => option.key === commonVariationTarget);
    const commonVariationGroups = selectedCommonSource && selectedCommonTarget ? selectedCommonSource.groups.flatMap(source => {
        const target = selectedCommonTarget.groups.find(group => group.parentKey === source.parentKey);
        return target ? [{ ...source, targetId: target.value }] : [];
    }) : [];
    const variableGroupCount = variableGroups.length;
    const visibleItems = (isProductImportPreview || isProductPublishPreview)
        ? (productTab === 'variable' ? variableItems : simpleItems)
        : filteredItems;

    const formatValue = (value, type = 'text') => {
        if (value === null || value === undefined || value === '') {
            return '-';
        }

        if (type === 'price') {
            const num = Number(value);
            if (!Number.isNaN(num)) {
                return num.toFixed(2);
            }
        }

        return String(value);
    };

    const changeColors = { price: '#9a5b5b', sale: '#57728f', stock: '#5d7c66' };

    const renderValueChange = (before, after, willUpdate, type, color) => {
        const beforeText = formatValue(before, type);
        if (!willUpdate) return <span style={{ color: '#667085' }}>{beforeText}</span>;
        return (
            <div style={{ lineHeight: 1.35 }}>
                <div style={{ display: 'inline-block', position: 'relative', color: '#667085' }}>
                    {beforeText}
                    <span aria-hidden="true" style={{ position: 'absolute', left: '-2px', right: '-2px', top: '50%', height: '1px', background: '#667085', transform: 'rotate(-8deg)', transformOrigin: 'center' }} />
                </div>
                <div style={{ color, fontWeight: 600 }}>{formatValue(after, type)}</div>
            </div>
        );
    };

    const renderPublishPrice = (item) => {
        const comparison = item.catalog_comparison;
        if (!comparison || item.upload_action !== 'update') return <>{item.regular_price}{item.sale_price ? ` / ${item.sale_price}` : ''}</>;
        return <>
            {renderValueChange(comparison.price_before, comparison.price_after, comparison.price_before !== comparison.price_after, 'text', changeColors.price)}
            {(comparison.sale_price_before !== '-' || comparison.sale_price_after !== '-') && <div style={{ marginTop: '5px' }}>
                {renderValueChange(comparison.sale_price_before, comparison.sale_price_after, comparison.sale_price_before !== comparison.sale_price_after, 'text', changeColors.sale)}
            </div>}
        </>;
    };

    const renderPublishStock = (item) => item.catalog_comparison && item.upload_action === 'update'
        ? renderValueChange(item.catalog_comparison.stock_before, item.catalog_comparison.stock_after, !!item.catalog_comparison.stock_changed, 'text', changeColors.stock)
        : item.stock_quantity;

    const renderImportAction = (item) => isProductImportPreview && item.can_import !== false && item.import_action && (
        <small style={{ marginLeft: '7px', padding: '1px 5px', borderRadius: '8px', background: '#f1f5f9', color: '#64748b', fontSize: '10px', fontWeight: 500 }}>
           {item.import_action === 'update' ? 'Güncellenecek' : 'Yeni'}
       </small>
   );
    const renderPublishAction = (item) => isProductPublishPreview && item.upload_action && (
        <small style={{ marginLeft: '7px', padding: '1px 5px', borderRadius: '8px', background: item.upload_action === 'update' ? '#ecfdf3' : (item.upload_action === 'unchanged' ? '#f8fafc' : '#eff6ff'), color: item.upload_action === 'update' ? '#067647' : (item.upload_action === 'unchanged' ? '#98a2b3' : '#1d4ed8'), fontSize: '10px', fontWeight: 500 }}>
            {item.upload_action === 'update' ? 'Güncellenecek' : (item.upload_action === 'unchanged' ? 'Değişiklik yok' : 'Yüklenecek')}
        </small>
    );

    const handleStartSync = () => {
        if (selectedSkus.size === 0) {
            if (!confirm(emptySelectionConfirm)) {
                return;
            }
            onSync([], isProductPublishPreview ? publishValues : (isProductImportPreview ? variationChoices : undefined)); // Empty array means all
        } else {
            onSync(Array.from(selectedSkus), isProductPublishPreview ? publishValues : (isProductImportPreview ? variationChoices : undefined));
        }
        onClose();
    };

    const toggleVariationGroup = (children, checked) => {
        const next = new Set(selectedSkus);
        children.forEach(item => {
            const key = itemKey(item);
            if (key && (isProductPublishPreview ? isPublishReady(item) : item.can_import !== false)) checked ? next.add(key) : next.delete(key);
        });
        setSelectedSkus(next);
    };

    const setVariationField = (parentKey, children, value) => {
        setVariationChoices(current => ({ ...current, [parentKey]: value }));
        if (isProductPublishPreview) {
            setPublishValues(current => children.reduce((next, item) => ({
                ...next,
                [itemKey(item)]: { ...(next[itemKey(item)] || {}), variation_attribute: value },
            }), { ...current }));
        }
    };

    const setVariationTarget = (parentKey, children, value) => {
        setVariationTargetChoices(current => ({ ...current, [parentKey]: value }));
        setPublishValues(current => children.reduce((next, item) => ({
            ...next,
            [itemKey(item)]: { ...(next[itemKey(item)] || {}), variation_target_attribute_id: value },
        }), { ...current }));
    };

    return (
        <div style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            backgroundColor: 'rgba(0,0,0,0.5)',
            display: 'flex',
            justifyContent: 'center',
            alignItems: 'center',
            padding: '3vh 2vw',
            overflowY: 'auto',
            zIndex: 99999
        }}>
            <div style={{
                backgroundColor: 'white', padding: '12px', borderRadius: '4px',
                width: '100%',
                height: '100%',
                display: 'flex',
                flexDirection: 'column'
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '10px' }}>
                    <h2>{title}</h2>
                    <button onClick={onClose} style={{ border: 'none', background: 'none', fontSize: '20px', cursor: 'pointer' }}>&times;</button>
                </div>

                <input
                    type="text"
                    placeholder={isStockPricePreview ? 'Isim veya SKU ile ara...' : 'Isim, SKU veya Merchant SKU ile ara...'}
                    value={filter}
                    onChange={e => setFilter(e.target.value)}
                    style={{ marginBottom: '10px', padding: '8px', width: '100%' }}
                />

                {isProductPublishPreview && categories.length > 0 && (
                    <div style={{ display: 'flex', gap: '6px', marginBottom: '10px' }}>
                        <select
                            value={categoryFilter}
                            onChange={e => setCategoryFilter(e.target.value)}
                            style={{ padding: '6px 10px', fontSize: '13px', minWidth: '200px' }}
                        >
                            <option value="">Tum Kategoriler</option>
                            {categories.map(category => (
                                <option key={category} value={category}>{category}</option>
                            ))}
                        </select>
                        <select
                            value={statusFilter}
                            onChange={e => setStatusFilter(e.target.value)}
                            style={{ padding: '6px 10px', fontSize: '13px', minWidth: '160px' }}
                        >
                            <option value="">Tum Durumlar</option>
                            <option value="upload">Yüklenecek</option>
                            <option value="update">Güncellenecek</option>
                            <option value="unchanged">Değişiklik yok</option>
                        </select>
                    </div>
                )}

                {previewWarning && (
                    <div style={{ marginBottom: '10px', padding: '8px 10px', background: '#fff8e8', border: '1px solid #f0d79b', borderRadius: '4px', color: '#7a5b00' }}>
                        {previewWarning}
                    </div>
                )}

                {(isProductImportPreview || isProductPublishPreview) && !loading && (
                    <div style={{ display: 'flex', gap: '6px', marginBottom: '10px' }} role="tablist" aria-label="Urun tipi">
                        <button type="button" className="btn" onClick={() => setProductTab('simple')} style={{ background: productTab === 'simple' ? '#2271b1' : '#eef2f6', color: productTab === 'simple' ? 'white' : '#333' }}>
                            Basit Urunler ({simpleItems.length})
                        </button>
                        <button type="button" className="btn" onClick={() => setProductTab('variable')} style={{ background: productTab === 'variable' ? '#2271b1' : '#eef2f6', color: productTab === 'variable' ? 'white' : '#333' }}>
                            Varyasyonlu Urunler ({variableGroupCount})
                        </button>
                    </div>
                )}

                {loading ? (
                    <div style={{ textAlign: 'center', color: '#475467', padding: '48px 0', fontWeight: 500 }}>
                        {isProductPublishPreview ? 'Pazar yerindeki mevcut urunler aliniyor, katalog karsilastiriliyor...' : 'Onizleme yukleniyor...'}
                    </div>
                ) : (
                    isStockPricePreview ? (
                        <div style={{ flex: 1, overflowY: 'auto' }}>
                            <div style={{ border: '1px solid #eee' }}>
                                <div style={{ padding: '10px 12px', borderBottom: '1px solid #eee', background: '#fafafa', fontWeight: 600 }}>
                                    Stok/Fiyat Onizleme ({filteredItems.length})
                                </div>
                                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                    <thead style={{ position: 'sticky', top: 0, background: 'white' }}>
                                        <tr>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>
                                                <input type="checkbox" onChange={handleSelectAll} />
                                            </th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Gorsel</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>SKU</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Ad</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd', textAlign: 'center' }}>Stok</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Fiyat</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Indirimli Fiyat</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredItems.length === 0 ? (
                                            <tr><td colSpan="7" style={{ padding: '20px', textAlign: 'center' }}>Urun bulunamadi.</td></tr>
                                        ) : filteredItems.map((item, i) => (
                                            <tr key={itemKey(item) || i} style={{ borderBottom: '1px solid #eee' }}>
                                                <td style={{ padding: '8px' }}>
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedSkus.has(itemKey(item))}
                                                        onChange={() => handleToggle(itemKey(item))}
                                                        disabled={!itemKey(item) || item.can_push === false}
                                                    />
                                                </td>
                                                <td style={{ padding: '8px' }}>
                                                    {item.preview_image ? (
                                                        <img src={item.preview_image} alt="" style={{ width: '50px', height: '50px', objectFit: 'cover' }} />
                                                    ) : '-'}
                                                </td>
                                                <td style={{ padding: '8px' }}>{item.sku || <span style={{ color: 'red' }}>SKU Eksik</span>}</td>
                                                <td style={{ padding: '8px' }}>{item.name || '-'}</td>
                                                <td style={{ padding: '8px', textAlign: 'center' }}>
                                                    {renderValueChange(item.before_stock, item.after_stock, !!item.will_update_stock, 'stock', changeColors.stock)}
                                                </td>
                                                <td style={{ padding: '8px' }}>
                                                    {renderValueChange(item.before_price, item.after_price, !!item.will_update_price, 'price', changeColors.price)}
                                                </td>
                                                <td style={{ padding: '8px' }}>
                                                    {renderValueChange(
                                                        item.before_discount_price,
                                                        item.after_discount_price,
                                                        typeof item.will_update_discount_price === 'boolean'
                                                            ? item.will_update_discount_price
                                                            : !!item.will_update_price,
                                                        'price',
                                                        changeColors.sale
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ) : (
                        <div style={{ flex: 1, overflowY: 'auto' }}>
                            {(isProductImportPreview || isProductPublishPreview) && productTab === 'variable' ? (
                                <div style={{ marginBottom: '12px' }}>
                                    <div style={{ marginBottom: '8px', fontWeight: 600 }}>
                                        Varyasyonlu Urunler ({variableGroupCount} urun / {visibleItems.length} varyasyon)
                                    </div>
                                    {isProductPublishPreview && commonVariationOptions.sources.length > 0 && commonVariationOptions.targets.length > 0 && (
                                        <div style={{ marginBottom: '10px', padding: '10px', border: '1px solid #c5d9ed', borderRadius: '8px', background: '#eef6ff' }}>
                                            <strong style={{ display: 'block', marginBottom: '7px' }}>Tüm listedeki ortak varyasyon alanları</strong>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
                                                <label style={{ fontSize: '12px', fontWeight: 600 }}>WooCommerce kaynak alanı</label>
                                                <select value={commonVariationSource} onChange={e => { setCommonVariationSource(e.target.value); setCommonVariationApplied(0); }}>
                                                    <option value="">Ortak alan seçin</option>
                                                    {commonVariationOptions.sources.map(option => <option key={option.key} value={option.key}>{option.label} ({option.groups.length} ürün)</option>)}
                                                </select>
                                                <span style={{ color: '#667085' }}>→</span>
                                                <label style={{ fontSize: '12px', fontWeight: 600 }}>{(supplier?.name || 'Pazar yeri')} hedef niteliği</label>
                                                <select value={commonVariationTarget} onChange={e => { setCommonVariationTarget(e.target.value); setCommonVariationApplied(0); }}>
                                                    <option value="">Ortak nitelik seçin</option>
                                                    {commonVariationOptions.targets.map(option => <option key={option.key} value={option.key}>{option.label} ({option.groups.length} ürün)</option>)}
                                                </select>
                                                <button
                                                    type="button"
                                                    disabled={commonVariationGroups.length < 2}
                                                    aria-live="polite"
                                                    onClick={() => {
                                                        commonVariationGroups.forEach(group => {
                                                            setVariationField(group.parentKey, group.children, group.value);
                                                            setVariationTarget(group.parentKey, group.children, group.targetId);
                                                        });
                                                        setCommonVariationApplied(commonVariationGroups.length);
                                                    }}
                                                    style={commonVariationApplied ? { borderColor: '#78b68a', background: '#e9f7ed', color: '#236b37' } : undefined}
                                                >
                                                    {commonVariationApplied ? `✓ ${commonVariationApplied} ürüne uygulandı` : (commonVariationGroups.length > 1 ? `${commonVariationGroups.length} ürüne uygula` : 'Uygula')}
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                    {variableGroups.length === 0 ? (
                                        <div style={{ padding: '20px', textAlign: 'center', border: '1px solid #e5e7eb', borderRadius: '6px' }}>Urun bulunamadi.</div>
                                    ) : variableGroups.map(([parentKey, children]) => {
                                        const selectable = children.filter(item => itemKey(item) && (isProductPublishPreview ? isPublishReady(item) : item.can_import !== false));
                                        const allSelected = selectable.length > 0 && selectable.every(item => selectedSkus.has(itemKey(item)));
                                        const first = children[0];
                                        const targetOptions = first.variation_target_options || [];
                                        return (
                                            <details key={parentKey} style={{ marginBottom: '8px', border: '1px solid #dfe3e8', borderRadius: '7px', background: '#f8fafc', overflow: 'hidden' }}>
                                                <summary style={{ padding: '12px', cursor: 'pointer', fontWeight: 600, color: '#1f2937' }}>
                                                    <span style={{ marginLeft: '6px' }}>{parentKey}</span>
                                                    {first.variation_parent_name && <span style={{ marginLeft: '8px', color: '#475467', fontWeight: 400 }}>— {first.variation_parent_name}</span>}
                                                    <span style={{ marginLeft: '8px', color: '#667085', fontSize: '12px', fontWeight: 400 }}>{children.length} varyasyon</span>
                                                </summary>
                                                <div style={{ padding: '0 12px 12px' }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '9px 10px', background: '#eef2f6', borderRadius: '5px', marginBottom: '8px' }}>
                                                        {isProductPublishPreview && targetOptions.length === 0 ? (
                                                            <small>Trendyol bu kategoride varyasyon niteliği sunmuyor; her varyasyon ayrı ürün gönderilecek.</small>
                                                        ) : <>
                                                            <label style={{ fontSize: '12px', fontWeight: 600 }}>{isProductPublishPreview ? 'WooCommerce kaynak alanı' : 'Varyasyon özelliği'}</label>
                                                            <select
                                                                value={variationChoices[parentKey] || ''}
                                                                onChange={e => setVariationField(parentKey, children, e.target.value)}
                                                            >
                                                                {isProductPublishPreview && <option value="">Seçin</option>}
                                                                {(first.variation_attribute_options || []).map(option => <option key={option} value={option}>{(first.variation_attribute_labels || {})[option] || option}</option>)}
                                                            </select>
                                                        </>}
                                                        {isProductPublishPreview && targetOptions.length > 0 && <>
                                                            <span style={{ color: '#667085' }}>→</span>
                                                            <label style={{ fontSize: '12px', fontWeight: 600 }}>{(supplier?.name || 'Pazar yeri')} hedef niteliği</label>
                                                            <select value={variationTargetChoices[parentKey] || ''} onChange={e => setVariationTarget(parentKey, children, e.target.value)}>
                                                                <option value="">Karşılık gelen niteliği seçin</option>
                                                                {targetOptions.map(option => <option key={option.id} value={option.id}>{option.name}</option>)}
                                                            </select>
                                                        </>}
                                                    </div>
                                                    <table style={{ width: '100%', borderCollapse: 'collapse', background: '#fff' }}>
                                                        <thead><tr>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}><input type="checkbox" checked={allSelected} onChange={e => toggleVariationGroup(children, e.target.checked)} /></th>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Görsel</th>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Stok Kodu</th>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Ad</th>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Değer</th>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Fiyat</th>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb', textAlign: 'center' }}>Stok</th>
                                                        </tr></thead>
                                                        <tbody>{children.map((item, i) => (
                                                            <React.Fragment key={itemKey(item) || i}>
                                                            <tr style={{ borderBottom: '1px solid #eef0f2' }}>
                                                                <td style={{ padding: '7px' }}><input type="checkbox" checked={selectedSkus.has(itemKey(item))} onChange={() => handleToggle(itemKey(item))} disabled={!itemKey(item) || (isProductPublishPreview ? !isPublishReady(item) : item.can_import === false)} /></td>
                                                                <td style={{ padding: '7px' }}>{item.preview_image ? <img src={item.preview_image} alt="" style={{ width: '42px', height: '42px', objectFit: 'cover' }} /> : '-'}</td>
                                                                <td style={{ padding: '7px', fontWeight: 600 }}>{item.sku || <span style={{ color: 'red' }}>SKU Eksik</span>}</td>
                                                                <td style={{ padding: '7px' }}>
                                                                   <div>{item.name || '-'}{renderImportAction(item)}</div>
                                                                    {isProductPublishPreview && renderPublishAction(item)}
                                                                   {item.preview_warning && (!isProductPublishPreview || !isPublishReady(item)) && <div style={{ color: '#b45309', fontSize: '12px' }}>{isProductPublishPreview ? publishWarning(item) : item.preview_warning}</div>}
                                                                    {isProductPublishPreview && Array.isArray(item.missing_fields) && item.missing_fields.filter(field => !field.key.startsWith('attribute_') && !['variation_attribute', 'variation_target_attribute_id'].includes(field.key) && !isVariationFieldResolved(item, field)).map(field => (
                                                                        <label key={field.key} style={{ display: 'block', marginTop: '6px', fontSize: '12px' }}>
                                                                            {field.label}
                                                                            {field.type === 'select' ? (
                                                                                <select value={getPublishValue(item, field)} onChange={e => updatePublishValue(item, field.key, e.target.value)} style={{ marginLeft: '6px', maxWidth: '240px' }}>
                                                                                    <option value="">Seçin</option>
                                                                                    {(field.options || []).map(option => <option key={option.id} value={option.id}>{option.name}</option>)}
                                                                                </select>
                                                                            ) : <input type={field.type || 'text'} value={getPublishValue(item, field)} placeholder={field.suggested_value || ''} onChange={e => updatePublishValue(item, field.key, e.target.value)} style={{ marginLeft: '6px', maxWidth: '240px' }} />}
                                                                        </label>
                                                                    ))}
                                                                    {isProductPublishPreview && Array.isArray(item.attribute_fields) && item.attribute_fields.length > 0 && <>
                                                                        <button type="button" onClick={() => toggleExpandedProduct(item)} style={{ marginTop: '7px', marginLeft: '7px', padding: 0, border: 0, background: 'none', cursor: 'pointer', color: '#2271b1' }}>
                                                                            {expandedProducts.has(itemKey(item)) ? '▼' : '▶'} Özellikleri değiştir
                                                                        </button>
                                                                    </>}
                                                                </td>
                                                                <td style={{ padding: '7px', color: '#2271b1' }}>{isProductImportPreview ? ((item.variation_attributes || {})[variationChoices[parentKey]] || '-') : ((item.variation_attributes || {})[variationChoices[parentKey]] || Object.values(item.variation_attributes || {}).join(', ') || '-')}</td>
                                                                <td style={{ padding: '7px' }}>{isProductPublishPreview ? renderPublishPrice(item) : <>{item.regular_price}{item.sale_price ? ` / ${item.sale_price}` : ''}</>}</td>
                                                                <td style={{ padding: '7px', textAlign: 'center' }}>{isProductPublishPreview ? renderPublishStock(item) : item.stock_quantity}</td>
                                                            </tr>
                                                            {isProductPublishPreview && expandedProducts.has(itemKey(item)) && (
                                                                <tr style={{ background: '#f8fafc' }}>
                                                                    <td colSpan={7} style={{ padding: '10px 16px', borderBottom: '1px solid #eef0f2' }}>{renderAttributeOverrides(item)}</td>
                                                                </tr>
                                                            )}
                                                            </React.Fragment>
                                                        ))}</tbody>
                                                    </table>
                                                </div>
                                            </details>
                                        );
                                    })}
                                </div>
                            ) : <div style={{ border: '1px solid #eee', marginBottom: '12px' }}>
                                <div style={{ padding: '10px 12px', borderBottom: '1px solid #eee', background: '#fafafa', fontWeight: 600 }}>
                                    {isProductPublishPreview ? `Gonderilebilir WooCommerce Urunleri (${visibleItems.length})` : (productTab === 'variable' ? `Varyasyonlu Urunler (${variableGroupCount} urun / ${visibleItems.length} varyasyon)` : `Basit Urunler (${visibleItems.length})`)}
                                </div>
                                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                    <thead style={{ position: 'sticky', top: 0, background: 'white' }}>
                                        <tr>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>
                                                <input type="checkbox" onChange={handleSelectAll} />
                                            </th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Gorsel</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>SKU</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Ad</th>
                                            {isProductImportPreview && productTab === 'variable' && <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Varyasyon Ozelligi</th>}
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Fiyat</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd', textAlign: 'center' }}>Stok</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visibleItems.length === 0 ? (
                                            <tr><td colSpan={isProductPublishPreview ? 6 : (isProductImportPreview && productTab === 'variable' ? 7 : 6)} style={{ padding: '20px', textAlign: 'center' }}>Urun bulunamadi.</td></tr>
                                        ) : visibleItems.map((item, i) => (
                                            <React.Fragment key={itemKey(item) || i}>
                                            <tr style={{ borderBottom: '1px solid #eee' }}>
                                                <td style={{ padding: '8px' }}>
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedSkus.has(itemKey(item))}
                                                        onChange={() => handleToggle(itemKey(item))}
                                                        disabled={!itemKey(item) || (isProductPublishPreview ? !isPublishReady(item) : item.can_import === false)}
                                                    />
                                                    {isProductPublishPreview && hasPublishDetails(item) && (
                                                        <span style={{ cursor: 'pointer', fontSize: '14px', marginLeft: '4px', color: '#667eea', userSelect: 'none', verticalAlign: 'middle' }}
                                                            onClick={() => toggleExpandedProduct(item)}
                                                            role="button" aria-label="Ürün niteliklerini aç veya kapat"
                                                            >{expandedProducts.has(itemKey(item)) ? '▼' : '▶'}</span>
                                                    )}
                                                </td>
                                                <td style={{ padding: '8px' }}>
                                                    {item.preview_image ? (
                                                        <img src={item.preview_image} alt="" style={{ width: '50px', height: '50px', objectFit: 'cover' }} />
                                                    ) : (item.images && item.images.length > 0 ? (
                                                        <img src={typeof item.images[0] === 'string' ? item.images[0] : ''} alt="" style={{ width: '50px', height: '50px', objectFit: 'cover' }} />
                                                    ) : '-')}
                                                </td>
                                                <td style={{ padding: '8px' }}>{item.sku || <span style={{ color: 'red' }}>SKU Eksik</span>}</td>
                                                <td style={{ padding: '8px' }}>
                                                   <div>{item.name}{renderImportAction(item)}</div>
                                                    {isProductPublishPreview && renderPublishAction(item)}
                                                   {item.row_type === 'variation' && (
                                                        <small style={{ color: '#2271b1' }}>Variation · Parent: {item.variation_parent_key}</small>
                                                    )}
                                                    {item.preview_warning && (
                                                        <div style={{ color: '#b45309', fontSize: '12px' }}>{isProductPublishPreview ? publishWarning(item) : item.preview_warning}</div>
                                                    )}
                                                    {isProductPublishPreview && Array.isArray(item.missing_fields) && item.missing_fields.filter(field => !field.key.startsWith('attribute_')).map(field => (
                                                        <label key={field.key} style={{ display: 'block', marginTop: '6px', fontSize: '12px' }}>
                                                            {field.label}
                                                            {field.type === 'select' ? (
                                                                <select
                                                                    value={getPublishValue(item, field)}
                                                                    onChange={e => updatePublishValue(item, field.key, e.target.value)}
                                                                    style={{ marginLeft: '6px', maxWidth: '240px' }}
                                                                >
                                                                    <option value="">Seçin</option>
                                                                    {(field.options || []).map(option => <option key={option.id} value={option.id}>{option.name}</option>)}
                                                                </select>
                                                            ) : (
                                                                <input
                                                                    type={field.type || 'text'}
                                                                    value={getPublishValue(item, field)}
                                                                    placeholder={field.suggested_value || ''}
                                                                    onChange={e => updatePublishValue(item, field.key, e.target.value)}
                                                                    style={{ marginLeft: '6px', maxWidth: '240px' }}
                                                                />
                                                            )}
                                                            {field.suggested_value && <small style={{ marginLeft: '6px', color: '#667085' }}>Öneri: {field.suggested_value}</small>}
                                                        </label>
                                                    ))}
                                                </td>
                                                {isProductImportPreview && productTab === 'variable' && (
                                                    <td style={{ padding: '8px' }}>
                                                        <select
                                                            value={variationChoices[item.variation_parent_key] || ''}
                                                            onChange={e => setVariationChoices(current => ({ ...current, [item.variation_parent_key]: e.target.value }))}
                                                        >
                                                            {(item.variation_attribute_options || []).map(option => <option key={option} value={option}>{option}</option>)}
                                                        </select>
                                                        <div style={{ marginTop: '4px', color: '#2271b1', fontSize: '12px' }}>
                                                            {(item.variation_attributes || {})[variationChoices[item.variation_parent_key]] || '-'}
                                                        </div>
                                                    </td>
                                                )}
                                                <td style={{ padding: '8px' }}>
                                                    {isProductPublishPreview ? renderPublishPrice(item) : <>{item.regular_price}{item.sale_price ? ` / ${item.sale_price}` : ''}</>}
                                                </td>
                                                <td style={{ padding: '8px', textAlign: 'center' }}>{isProductPublishPreview ? renderPublishStock(item) : item.stock_quantity}</td>
                                            </tr>
                                            {isProductPublishPreview && hasPublishDetails(item) && expandedProducts.has(itemKey(item)) && (
                                                <tr style={{ background: '#f8fafc' }}>
                                                    <td colSpan={6} style={{ padding: '10px 16px', borderBottom: '1px solid #eee' }}>
                                                        {renderAttributeOverrides(item)}
                                                    </td>
                                                </tr>
                                            )}
                                            </React.Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>}

                            {!isProductPublishPreview && <div style={{ border: '1px solid #eee' }}>
                                <div style={{ padding: '10px 12px', borderBottom: '1px solid #eee', background: '#fff8e8', fontWeight: 600 }}>
                                    Stok Kodsuz ({filteredStokKodsuzItems.length})
                                </div>
                                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                    <thead style={{ background: 'white' }}>
                                        <tr>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Gorsel</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Merchant SKU</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Ad</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Durum</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredStokKodsuzItems.length === 0 ? (
                                            <tr><td colSpan="4" style={{ padding: '20px', textAlign: 'center' }}>Stok kodsuz urun yok.</td></tr>
                                        ) : filteredStokKodsuzItems.map((item, i) => (
                                            <tr key={`${item.merchant_sku || item.name || 'stok-kodsuz'}-${i}`} style={{ borderBottom: '1px solid #eee' }}>
                                                <td style={{ padding: '8px' }}>
                                                    {item.preview_image ? (
                                                        <img src={item.preview_image} alt="" style={{ width: '50px', height: '50px', objectFit: 'cover' }} />
                                                    ) : '-'}
                                                </td>
                                                <td style={{ padding: '8px' }}>{item.merchant_sku || '-'}</td>
                                                <td style={{ padding: '8px' }}>{item.name || '-'}</td>
                                                <td style={{ padding: '8px', color: '#9a6700' }}>{item.reason || 'Stock kodu eksik'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>}
                        </div>
                    )
                )}

                <div style={{ marginTop: '20px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '10px' }}>
                    {isProductPublishPreview && (
                        <span style={{ color: '#475467', fontSize: '13px', fontWeight: 500 }}>
                            {filteredItems.filter(item => item.upload_action === 'upload').length} yüklenecek · {filteredItems.filter(item => item.upload_action === 'update').length} güncellenecek
                            {filteredItems.some(item => item.upload_action === 'unchanged') ? ` · ${filteredItems.filter(item => item.upload_action === 'unchanged').length} değişiklik yok` : ''}
                        </span>
                    )}
                    <div style={{ display: 'flex', gap: '10px' }}>
                        <button className="btn" style={{ background: '#eee', color: '#333' }} onClick={onClose}>Iptal</button>
                        <button className="btn" onClick={handleStartSync} disabled={loading}>
                            {submitText} {selectedSkus.size > 0 ? `(${selectedSkus.size})` : '(Tum urunler)'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default ProductSelectorModal;
