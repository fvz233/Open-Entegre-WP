import React, { useState, useEffect } from 'react';
import api from '../api';

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
    const [previewWarning, setPreviewWarning] = useState('');
    const [publishValues, setPublishValues] = useState({});
    const [productTab, setProductTab] = useState('simple');
    const [variationChoices, setVariationChoices] = useState({});
    const [variationTargetChoices, setVariationTargetChoices] = useState({});

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
                setPreviewWarning('');
                setSelectedSkus(new Set());
                setPublishValues({});
                setVariationChoices({});
                setVariationTargetChoices({});
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

    const isVariationFieldResolved = (item, field) => {
        const values = publishValues[itemKey(item)] || {};
        return values.variation_attribute && field.key === `attribute_${values.variation_target_attribute_id}`;
    };

    const isPublishReady = (item) => {
        const fields = Array.isArray(item.missing_fields) ? item.missing_fields : [];
        return item.can_import !== false || (fields.length > 0 && fields.every(field => getPublishValue(item, field) !== '' || isVariationFieldResolved(item, field)));
    };

    const filteredItems = items.filter(item => {
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
    const variableGroups = Object.entries(variableItems.reduce((groups, item) => {
        (groups[item.variation_parent_key] ||= []).push(item);
        return groups;
    }, {}));
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

    const getChangeColor = (before, after, willUpdate) => {
        if (!willUpdate) {
            return '#667085';
        }

        const beforeNum = Number(before);
        const afterNum = Number(after);
        if (Number.isNaN(beforeNum) || Number.isNaN(afterNum)) {
            return '#667085';
        }

        if (afterNum > beforeNum) {
            return '#067647';
        }
        if (afterNum < beforeNum) {
            return '#b42318';
        }

        return '#475467';
    };

    const renderBeforeAfterLines = (before, after, willUpdate, type = 'text') => {
        const beforeText = formatValue(before, type);
        const effectiveAfter = willUpdate ? after : before;
        const afterText = formatValue(effectiveAfter, type);
        const afterColor = getChangeColor(before, effectiveAfter, willUpdate);

        return (
            <div style={{ lineHeight: 1.35 }}>
                <div style={{ color: '#667085' }}>Once: {beforeText}</div>
                <div style={{ color: afterColor, fontWeight: 600 }}>Sonra: {afterText}</div>
            </div>
        );
    };

    const renderImportAction = (item) => isProductImportPreview && item.can_import !== false && item.import_action && (
        <small style={{ marginLeft: '7px', padding: '1px 5px', borderRadius: '8px', background: '#f1f5f9', color: '#64748b', fontSize: '10px', fontWeight: 500 }}>
            {item.import_action === 'update' ? 'Güncellenecek' : 'Yeni'}
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
                backgroundColor: 'white', padding: '20px', borderRadius: '8px',
                width: 'min(1100px, 96vw)',
                height: '84vh',
                maxHeight: '84vh',
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
                        <button type="button" className="btn" onClick={() => setProductTab('variable')} style={{ background: productTab === 'variable' ? '#5b21b6' : '#eef2f6', color: productTab === 'variable' ? 'white' : '#333' }}>
                            Varyasyonlu Urunler ({variableGroupCount})
                        </button>
                    </div>
                )}

                {loading ? (
                    <div>Onizleme yukleniyor...</div>
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
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Stok (Once / Sonra)</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Fiyat (Once / Sonra)</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Indirimli Fiyat (Once / Sonra)</th>
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
                                                <td style={{ padding: '8px' }}>
                                                    {renderBeforeAfterLines(item.before_stock, item.after_stock, !!item.will_update_stock, 'stock')}
                                                </td>
                                                <td style={{ padding: '8px' }}>
                                                    {renderBeforeAfterLines(item.before_price, item.after_price, !!item.will_update_price, 'price')}
                                                </td>
                                                <td style={{ padding: '8px' }}>
                                                    {renderBeforeAfterLines(
                                                        item.before_discount_price,
                                                        item.after_discount_price,
                                                        typeof item.will_update_discount_price === 'boolean'
                                                            ? item.will_update_discount_price
                                                            : !!item.will_update_price,
                                                        'price'
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
                                    {variableGroups.length === 0 ? (
                                        <div style={{ padding: '20px', textAlign: 'center', border: '1px solid #e5e7eb', borderRadius: '6px' }}>Urun bulunamadi.</div>
                                    ) : variableGroups.map(([parentKey, children]) => {
                                        const selectable = children.filter(item => itemKey(item) && (isProductPublishPreview ? isPublishReady(item) : item.can_import !== false));
                                        const allSelected = selectable.length > 0 && selectable.every(item => selectedSkus.has(itemKey(item)));
                                        const first = children[0];
                                        return (
                                            <details key={parentKey} style={{ marginBottom: '8px', border: '1px solid #dfe3e8', borderRadius: '7px', background: '#f8fafc', overflow: 'hidden' }}>
                                                <summary style={{ padding: '12px', cursor: 'pointer', fontWeight: 600, color: '#1f2937' }}>
                                                    <span style={{ marginLeft: '6px' }}>{parentKey}</span>
                                                    {first.variation_parent_name && <span style={{ marginLeft: '8px', color: '#475467', fontWeight: 400 }}>— {first.variation_parent_name}</span>}
                                                    <span style={{ marginLeft: '8px', color: '#667085', fontSize: '12px', fontWeight: 400 }}>{children.length} varyasyon</span>
                                                </summary>
                                                <div style={{ padding: '0 12px 12px' }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '9px 10px', background: '#eef2f6', borderRadius: '5px', marginBottom: '8px' }}>
                                                        <label style={{ fontSize: '12px', fontWeight: 600 }}>{isProductPublishPreview ? 'WooCommerce kaynak alanı' : 'Varyasyon özelliği'}</label>
                                                        <select
                                                            value={variationChoices[parentKey] || ''}
                                                            onChange={e => setVariationField(parentKey, children, e.target.value)}
                                                        >
                                                            {isProductPublishPreview && <option value="">Seçin</option>}
                                                            {(first.variation_attribute_options || []).map(option => <option key={option} value={option}>{(first.variation_attribute_labels || {})[option] || option}</option>)}
                                                        </select>
                                                        {isProductPublishPreview && supplier?.marketplace_key === 'trendyol' && <>
                                                            <span style={{ color: '#667085' }}>→</span>
                                                            <label style={{ fontSize: '12px', fontWeight: 600 }}>Trendyol hedef niteliği</label>
                                                            <select value={variationTargetChoices[parentKey] || ''} onChange={e => setVariationTarget(parentKey, children, e.target.value)}>
                                                                <option value="">API niteliğini seçin</option>
                                                                {(first.variation_target_options || []).map(option => <option key={option.id} value={option.id}>{option.name}</option>)}
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
                                                            {isProductPublishPreview && <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Kategori Komisyonu</th>}
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Fiyat</th>
                                                            <th style={{ padding: '7px', borderBottom: '1px solid #e5e7eb' }}>Stok</th>
                                                        </tr></thead>
                                                        <tbody>{children.map((item, i) => (
                                                            <tr key={itemKey(item) || i} style={{ borderBottom: '1px solid #eef0f2' }}>
                                                                <td style={{ padding: '7px' }}><input type="checkbox" checked={selectedSkus.has(itemKey(item))} onChange={() => handleToggle(itemKey(item))} disabled={!itemKey(item) || (isProductPublishPreview ? !isPublishReady(item) : item.can_import === false)} /></td>
                                                                <td style={{ padding: '7px' }}>{item.preview_image ? <img src={item.preview_image} alt="" style={{ width: '42px', height: '42px', objectFit: 'cover' }} /> : '-'}</td>
                                                                <td style={{ padding: '7px', fontWeight: 600 }}>{item.sku || <span style={{ color: 'red' }}>SKU Eksik</span>}</td>
                                                                <td style={{ padding: '7px' }}>
                                                                    <div>{item.name || '-'}{renderImportAction(item)}</div>
                                                                    {item.preview_warning && (!isProductPublishPreview || !isPublishReady(item)) && <div style={{ color: '#b45309', fontSize: '12px' }}>{item.preview_warning}</div>}
                                                                    {isProductPublishPreview && Array.isArray(item.missing_fields) && item.missing_fields.filter(field => !['variation_attribute', 'variation_target_attribute_id'].includes(field.key) && !isVariationFieldResolved(item, field)).map(field => (
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
                                                                </td>
                                                                <td style={{ padding: '7px', color: '#5b21b6' }}>{isProductImportPreview ? ((item.variation_attributes || {})[variationChoices[parentKey]] || '-') : (Object.values(item.variation_attributes || {}).join(', ') || '-')}</td>
                                                                {isProductPublishPreview && <td style={{ padding: '7px' }}>{item.category_commission_rate ? `%${item.category_commission_rate}` : '-'}</td>}
                                                                <td style={{ padding: '7px' }}>{item.regular_price}{item.sale_price ? ` / ${item.sale_price}` : ''}</td>
                                                                <td style={{ padding: '7px' }}>{item.stock_quantity}</td>
                                                            </tr>
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
                                            {isProductPublishPreview && <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Kategori Komisyonu</th>}
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Fiyat</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Stok</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visibleItems.length === 0 ? (
                                            <tr><td colSpan={isProductPublishPreview ? 7 : (isProductImportPreview && productTab === 'variable' ? 7 : 6)} style={{ padding: '20px', textAlign: 'center' }}>Urun bulunamadi.</td></tr>
                                        ) : visibleItems.map((item, i) => (
                                            <tr key={itemKey(item) || i} style={{ borderBottom: '1px solid #eee' }}>
                                                <td style={{ padding: '8px' }}>
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedSkus.has(itemKey(item))}
                                                        onChange={() => handleToggle(itemKey(item))}
                                                        disabled={!itemKey(item) || (isProductPublishPreview ? !isPublishReady(item) : item.can_import === false)}
                                                    />
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
                                                    {item.row_type === 'variation' && (
                                                        <small style={{ color: '#5b21b6' }}>Variation · Parent: {item.variation_parent_key}</small>
                                                    )}
                                                    {item.preview_warning && (
                                                        <div style={{ color: '#b45309', fontSize: '12px' }}>{item.preview_warning}</div>
                                                    )}
                                                    {isProductPublishPreview && Array.isArray(item.missing_fields) && item.missing_fields.map(field => (
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
                                                        <div style={{ marginTop: '4px', color: '#5b21b6', fontSize: '12px' }}>
                                                            {(item.variation_attributes || {})[variationChoices[item.variation_parent_key]] || '-'}
                                                        </div>
                                                    </td>
                                                )}
                                                {isProductPublishPreview && (
                                                    <td style={{ padding: '8px' }}>
                                                        {item.category_commission_rate ? `%${item.category_commission_rate}` : '-'}
                                                    </td>
                                                )}
                                                <td style={{ padding: '8px' }}>
                                                    {item.regular_price}{item.sale_price ? ` / ${item.sale_price}` : ''}
                                                </td>
                                                <td style={{ padding: '8px' }}>{item.stock_quantity}</td>
                                            </tr>
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

                <div style={{ marginTop: '20px', display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                    <button className="btn" style={{ background: '#eee', color: '#333' }} onClick={onClose}>Iptal</button>
                    <button className="btn" onClick={handleStartSync} disabled={loading}>
                        {submitText} {selectedSkus.size > 0 ? `(${selectedSkus.size})` : '(Tum urunler)'}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default ProductSelectorModal;
