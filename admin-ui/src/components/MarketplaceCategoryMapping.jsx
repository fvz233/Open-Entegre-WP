import React, { useEffect, useState } from 'react';
import api from '../api';

function MarketplaceCategoryMapping({ supplier, onSupplierUpdate }) {
    const sectionStyle = { background: '#f7f9fc', border: '1px solid #e4e8ef', borderRadius: '10px', padding: '16px' };
    const marketplace = supplier.marketplace_label || supplier.name || supplier.marketplace_key;
    const [wooCategories, setWooCategories] = useState([]);
    const [mappings, setMappings] = useState({});
    const [wooBrands, setWooBrands] = useState([]);
    const [brandMappings, setBrandMappings] = useState({});
    const [wooCategoryId, setWooCategoryId] = useState('');
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [trendyolCategory, setTrendyolCategory] = useState(null);
    const [attributes, setAttributes] = useState([]);
    const [values, setValues] = useState({});
    const [loading, setLoading] = useState(false);
    const [wooBrandKey, setWooBrandKey] = useState('');
    const [brandQuery, setBrandQuery] = useState('');
    const [brandResults, setBrandResults] = useState([]);
    const [selectedBrand, setSelectedBrand] = useState(null);
    const [manualBrand, setManualBrand] = useState(false);
    const [manualBrandId, setManualBrandId] = useState('');
    const [manualBrandName, setManualBrandName] = useState('');
    const [commissionRate, setCommissionRate] = useState('');
    const [n11ShipmentTemplate, setN11ShipmentTemplate] = useState(supplier.n11_shipment_template || '');
    const [feedback, setFeedback] = useState(null);

    const load = async () => {
        const res = await api.getMarketplaceCategoryMappings(supplier.id);
        setWooCategories(res.data?.woo_categories || []);
        setMappings(res.data?.mappings || {});
        setWooBrands(res.data?.woo_brands || []);
        setBrandMappings(res.data?.brand_mappings || {});
    };

    useEffect(() => {
        setFeedback(null);
        setN11ShipmentTemplate(supplier.n11_shipment_template || '');
        load().catch(e => setFeedback({ type: 'error', message: e.response?.data?.message || e.message || 'Eşlemeler yüklenemedi.' }));
    }, [supplier.id]);

    const saveN11ShipmentTemplate = async () => {
        setLoading(true);
        setFeedback(null);
        try {
            await api.updateSupplier(supplier.id, { n11_shipment_template: n11ShipmentTemplate.trim() });
            if (onSupplierUpdate) await onSupplierUpdate();
            setN11ShipmentTemplate(n11ShipmentTemplate.trim());
            setFeedback({ type: 'success', message: 'n11 kargo şablonu kaydedildi.' });
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || 'n11 kargo şablonu kaydedilemedi.' });
        }
        setLoading(false);
    };

    const selectWooCategory = (id) => {
        setWooCategoryId(id);
        const mapping = mappings[id];
        setCommissionRate(mapping?.commission_rate ?? '');
        if (!mapping) {
            setTrendyolCategory(null);
            setAttributes([]);
            setValues({});
            return;
        }
        setTrendyolCategory({ id: mapping.category_id, path: mapping.category_name });
        setAttributes(mapping.attribute_definitions || []);
        setValues(Object.fromEntries((mapping.attributes || []).map(attribute => [
            attribute.attributeId,
            attribute.attributeValueIds?.[0] ?? attribute.attributeValue ?? '',
        ])));
    };

    const search = async () => {
        setFeedback(null);
        setLoading(true);
        try {
            const res = await api.searchMarketplaceCategories(supplier.id, query);
            setResults(res.data?.items || []);
            if (!res.data?.items?.length) setFeedback({ type: 'success', message: 'Kategori bulunamadı.' });
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || `${marketplace} kategorileri alınamadı.` });
        }
        setLoading(false);
    };

    const selectCategory = async (category) => {
        if (!category) {
            setTrendyolCategory(null);
            setAttributes([]);
            return;
        }
        setTrendyolCategory(category);
        setFeedback(null);
        setLoading(true);
        try {
            const res = await api.getMarketplaceCategoryAttributes(supplier.id, category.id);
            setAttributes(res.data?.items || []);
            setValues({});
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || 'Kategori nitelikleri alınamadı.' });
        }
        setLoading(false);
    };

    const save = async () => {
        if (!wooCategoryId || !trendyolCategory) return;
        const payload = attributes.filter(attribute => values[attribute.id]).map(attribute => {
            const value = values[attribute.id];
            return attribute.values.length
                ? { attributeId: attribute.id, attributeValueIds: [value] }
                : { attributeId: attribute.id, attributeValue: value };
        });
        setFeedback(null);
        setLoading(true);
        try {
            await api.saveMarketplaceCategoryMapping(supplier.id, {
                woo_category_id: Number(wooCategoryId),
                marketplace_category_id: trendyolCategory.id,
                marketplace_category_name: trendyolCategory.path,
                attributes: payload,
                attribute_definitions: attributes,
                commission_rate: commissionRate,
            });
            await load();
            setTrendyolCategory(null);
            setAttributes([]);
            setValues({});
            setCommissionRate('');
            setFeedback({ type: 'success', message: 'Kategori eşlemesi kaydedildi.' });
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || 'Kategori eşlemesi kaydedilemedi.' });
        }
        setLoading(false);
    };

    const remove = async (categoryId) => {
        setLoading(true);
        setFeedback(null);
        try {
            await api.saveMarketplaceCategoryMapping(supplier.id, { woo_category_id: Number(categoryId), marketplace_category_id: 0 });
            await load();
            setFeedback({ type: 'success', message: 'Kategori eşlemesi silindi.' });
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || 'Kategori eşlemesi silinemedi.' });
        }
        setLoading(false);
    };

    const searchBrand = async () => {
        setFeedback(null);
        setManualBrand(false);
        setLoading(true);
        try {
            const categoryId = trendyolCategory?.id || mappings[wooCategoryId]?.category_id || '';
            const res = await api.searchMarketplaceBrands(supplier.id, brandQuery, categoryId);
            setBrandResults(res.data?.items || []);
            if (!res.data?.items?.length) setFeedback({ type: 'success', message: 'Marka bulunamadı. Aşağıdan elle girebilirsiniz.' });
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || `${marketplace} markaları alınamadı.` });
        }
        setLoading(false);
    };

    const saveBrand = async () => {
        const brand = manualBrand ? { id: manualBrandId.trim(), name: manualBrandName.trim() } : selectedBrand;
        if (!wooBrandKey || !brand?.id) return;
        setFeedback(null);
        setLoading(true);
        try {
            await api.saveMarketplaceBrandMapping(supplier.id, {
                woo_brand_key: wooBrandKey,
                brand_id: brand.id,
                brand_name: brand.name,
            });
            await load();
            setSelectedBrand(null);
            setBrandResults([]);
            setManualBrand(false);
            setManualBrandId('');
            setManualBrandName('');
            setFeedback({ type: 'success', message: 'Marka eşlemesi kaydedildi.' });
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || 'Marka eşlemesi kaydedilemedi.' });
        }
        setLoading(false);
    };

    const removeBrand = async (brandKey) => {
        setLoading(true);
        setFeedback(null);
        try {
            await api.saveMarketplaceBrandMapping(supplier.id, { woo_brand_key: brandKey, brand_id: '' });
            await load();
            setFeedback({ type: 'success', message: 'Marka eşlemesi silindi.' });
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || 'Marka eşlemesi silinemedi.' });
        }
        setLoading(false);
    };

    return (
        <div style={{ marginTop: '18px', borderTop: '1px solid #eee', paddingTop: '14px', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '18px' }}>
            {feedback && (
                <div className={`multi-sync-feedback ${feedback.type}`} role={feedback.type === 'error' ? 'alert' : 'status'} aria-live="polite" style={{ gridColumn: '1 / -1' }}>
                    {feedback.message}
                </div>
            )}
            <div style={sectionStyle}>
                <h4>{marketplace} Kategori Eşlemesi</h4>
                <div style={{ display: 'grid', gap: '8px' }}>
                <select value={wooCategoryId} onChange={e => selectWooCategory(e.target.value)}>
                    <option value="">WooCommerce kategorisi seçin</option>
                    {wooCategories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
                </select>
                <div style={{ display: 'flex', gap: '8px' }}>
                    <input value={query} onChange={e => setQuery(e.target.value)} placeholder={`${marketplace} kategorisi ara`} />
                    <button type="button" className="btn" onClick={search} disabled={loading || !query.trim()}>Ara</button>
                </div>
                {results.length > 0 && (
                    <select value={trendyolCategory?.id || ''} onChange={e => selectCategory(results.find(item => String(item.id) === e.target.value))}>
                        <option value="">En alt {marketplace} kategorisini seçin</option>
                        {results.map(category => <option key={category.id} value={category.id}>{category.path}</option>)}
                    </select>
                )}
                {attributes.map(attribute => (
                    <label key={attribute.id}>
                        {attribute.name} ({[
                            attribute.required && 'zorunlu',
                            (attribute.slicer || attribute.varianter) && 'varyasyon',
                            !attribute.required && !attribute.slicer && !attribute.varianter && 'isteğe bağlı',
                        ].filter(Boolean).join(', ')})
                        {supplier.marketplace_key === 'n11' && attribute.name.trim().toLocaleLowerCase('tr-TR') === 'marka' ? (
                            <small style={{ display: 'block' }}>WooCommerce marka adından alınır.</small>
                        ) : attribute.values.length ? (
                            <select value={values[attribute.id] || ''} onChange={e => setValues({ ...values, [attribute.id]: e.target.value })}>
                                <option value="">Preview'da ürün bazında doldur</option>
                                {attribute.values.map(value => <option key={value.id} value={value.id}>{value.name}</option>)}
                            </select>
                        ) : attribute.allow_custom ? (
                            <input value={values[attribute.id] || ''} placeholder="Boşsa preview'da doldurulur" onChange={e => setValues({ ...values, [attribute.id]: e.target.value })} />
                        ) : (
                            <span style={{ color: '#b42318', display: 'block' }}>{marketplace} değer döndürmedi.</span>
                        )}
                    </label>
                ))}
                {attributes.length > 0 && <small>Boş bırakılan değer tüm kategoriye uygulanmaz; export preview'da ürün/varyasyon bazında istenir.</small>}
                {trendyolCategory && (
                    <label>
                        Komisyon Oranı (%)
                        <input type="number" min="0" max="99.99" step="0.01" value={commissionRate} placeholder="Boşsa API komisyonu kullanılır" onChange={e => setCommissionRate(e.target.value)} />
                    </label>
                )}
                {trendyolCategory && <button type="button" className="btn" onClick={save} disabled={loading || !wooCategoryId}>Eşlemeyi Kaydet</button>}
                </div>
                {Object.entries(mappings).map(([categoryId, mapping]) => (
                    <div key={categoryId} style={{ display: 'flex', justifyContent: 'space-between', marginTop: '8px', padding: '8px', background: '#f7f7f7' }}>
                        <span>{wooCategories.find(item => item.id === Number(categoryId))?.name || categoryId} → {mapping.category_name}{mapping.commission_rate !== undefined ? ` · Komisyon %${mapping.commission_rate}` : ''}</span>
                        <span style={{ display: 'flex', gap: '6px' }}>
                            <button type="button" onClick={() => selectWooCategory(categoryId)} disabled={loading}>Düzenle</button>
                            <button type="button" onClick={() => remove(categoryId)} disabled={loading}>Sil</button>
                        </span>
                    </div>
                ))}
            </div>
            {supplier.marketplace_key !== 'n11' && <div style={sectionStyle}>
                <h4>{marketplace} Marka Eşlemesi</h4>
                {wooBrands.length === 0 ? (
                    <small>WooCommerce marka taksonomisi bulunamadı. Önce ürün markalarını oluşturun.</small>
                ) : (
                    <div style={{ display: 'grid', gap: '8px' }}>
                        <select value={wooBrandKey} onChange={e => setWooBrandKey(e.target.value)}>
                            <option value="">WooCommerce markası seçin</option>
                            {wooBrands.map(brand => <option key={brand.key} value={brand.key}>{brand.name}</option>)}
                        </select>
                        <div style={{ display: 'flex', gap: '8px' }}>
                            <input value={brandQuery} onChange={e => setBrandQuery(e.target.value)} placeholder={`${marketplace} markası ara`} />
                            <button type="button" className="btn" onClick={searchBrand} disabled={loading || !brandQuery.trim()}>Ara</button>
                        </div>
                        {brandResults.length > 0 && (
                            <select value={selectedBrand?.id || ''} onChange={e => setSelectedBrand(brandResults.find(item => String(item.id) === e.target.value))}>
                                <option value="">{marketplace} markasını seçin</option>
                                {brandResults.map(brand => <option key={brand.id} value={brand.id}>{brand.name}</option>)}
                            </select>
                        )}
                        {selectedBrand && <button type="button" className="btn" onClick={saveBrand} disabled={loading || !wooBrandKey}>Eşlemeyi Kaydet</button>}
                        {!manualBrand && (
                            <button type="button" className="btn" style={{ fontSize: '0.85em' }} onClick={() => { setManualBrand(true); setSelectedBrand(null); }} disabled={loading}>{marketplace} markası bulunamadı mı? Elle girin</button>
                        )}
                        {manualBrand && (
                            <div style={{ display: 'grid', gap: '6px' }}>
                                <small>Marka ID ve adını pazaryeri panelinden bulun.</small>
                                <input value={manualBrandId} onChange={e => setManualBrandId(e.target.value)} placeholder="Marka ID" />
                                <input value={manualBrandName} onChange={e => setManualBrandName(e.target.value)} placeholder="Marka adı" />
                                <div style={{ display: 'flex', gap: '6px' }}>
                                    <button type="button" className="btn" onClick={saveBrand} disabled={loading || !wooBrandKey || !manualBrandId.trim()}>Eşlemeyi Kaydet</button>
                                    <button type="button" className="btn" style={{ fontSize: '0.85em' }} onClick={() => { setManualBrand(false); setManualBrandId(''); setManualBrandName(''); }} disabled={loading}>İptal</button>
                                </div>
                            </div>
                        )}
                    </div>
                )}
                {Object.entries(brandMappings).map(([brandKey, mapping]) => (
                    <div key={brandKey} style={{ display: 'flex', justifyContent: 'space-between', marginTop: '8px', padding: '8px', background: '#f7f7f7' }}>
                        <span>{wooBrands.find(item => item.key === brandKey)?.name || brandKey} → {mapping.brand_name}</span>
                        <button type="button" onClick={() => removeBrand(brandKey)} disabled={loading}>Sil</button>
                    </div>
                ))}
            </div>}
            {supplier.marketplace_key === 'n11' && (
                <div style={sectionStyle}>
                    <h4 style={{ marginTop: 0 }}>n11 Genel Ayarları</h4>
                    <label>
                        Global Kargo Şablonu
                        <div style={{ display: 'flex', gap: '8px', marginTop: '6px' }}>
                            <input value={n11ShipmentTemplate} onChange={e => setN11ShipmentTemplate(e.target.value)} placeholder="N11 panelindeki şablon adı" />
                            <button type="button" className="btn" onClick={saveN11ShipmentTemplate} disabled={loading || !n11ShipmentTemplate.trim()}>Kaydet</button>
                        </div>
                    </label>
                </div>
            )}
        </div>
    );
}

export default MarketplaceCategoryMapping;
