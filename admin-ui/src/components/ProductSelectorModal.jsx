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
    const itemKey = (item) => item.selection_key || item.sku;
    const [items, setItems] = useState([]);
    const [stokKodsuzItems, setStokKodsuzItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedSkus, setSelectedSkus] = useState(new Set());
    const [filter, setFilter] = useState('');
    const [previewWarning, setPreviewWarning] = useState('');
    const [publishValues, setPublishValues] = useState({});

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
                setItems(res.data?.success && Array.isArray(res.data.items) ? res.data.items : []);
                setStokKodsuzItems([]);
                setPreviewWarning('');
                setSelectedSkus(new Set());
                setPublishValues({});
            } else {
                const res = await api.previewSync(supplier.id);
                if (res.data.success && Array.isArray(res.data.items)) {
                    setItems(res.data.items);
                    setStokKodsuzItems(Array.isArray(res.data.stok_kodsuz) ? res.data.stok_kodsuz : []);
                    setPreviewWarning('');
                    setSelectedSkus(new Set());
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
            const allSkus = filteredItems
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

    const isPublishReady = (item) => {
        const fields = Array.isArray(item.missing_fields) ? item.missing_fields : [];
        return item.can_import !== false || (fields.length > 0 && fields.every(field => getPublishValue(item, field) !== ''));
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

    const handleStartSync = () => {
        if (selectedSkus.size === 0) {
            if (!confirm(emptySelectionConfirm)) {
                return;
            }
            onSync([], isProductPublishPreview ? publishValues : undefined); // Empty array means all
        } else {
            onSync(Array.from(selectedSkus), isProductPublishPreview ? publishValues : undefined);
        }
        onClose();
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
                            <div style={{ border: '1px solid #eee', marginBottom: '12px' }}>
                                <div style={{ padding: '10px 12px', borderBottom: '1px solid #eee', background: '#fafafa', fontWeight: 600 }}>
                                    {isProductPublishPreview ? 'Gonderilebilir WooCommerce Urunleri' : 'Import Edilebilir Urunler'} ({filteredItems.length})
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
                                            {isProductPublishPreview && <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Kategori Komisyonu</th>}
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Fiyat</th>
                                            <th style={{ padding: '8px', borderBottom: '1px solid #ddd' }}>Stok</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredItems.length === 0 ? (
                                            <tr><td colSpan={isProductPublishPreview ? 7 : 6} style={{ padding: '20px', textAlign: 'center' }}>Urun bulunamadi.</td></tr>
                                        ) : filteredItems.map((item, i) => (
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
                                                    <div>{item.name}</div>
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
                            </div>

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
