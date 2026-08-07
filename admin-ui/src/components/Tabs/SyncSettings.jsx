import React, { useState, useEffect } from 'react';
import api from '../../api';
import ProductSelectorModal from '../ProductSelectorModal';
import OrderSelectorModal from '../OrderSelectorModal';

const ALLOWED_INTERVALS = [5, 10, 15, 30];
const ALLOWED_SCHEDULES = ['manual', 'hourly', 'daily', 'per_minute'];
const ALLOWED_STOCK_AUTOMATION_MODES = ['scheduled', 'event_driven'];
const toCheckboxValue = (value) => value === true || value === 1 || value === '1';

function SyncSettings({ supplier, onSupplierUpdate }) {
    const isHepsiburada = supplier?.marketplace_key === 'hepsiburada';
    const [settings, setSettings] = useState({
        sync_stock: false,
        sync_price: false,
        sync_products: false,
        sync_orders: false,
        stock_automation_mode: 'scheduled',
        schedule: 'manual',
        interval_minutes: 5
    });
    const [loading, setLoading] = useState(false);
    const [showProductModal, setShowProductModal] = useState(false);
    const [showOrderModal, setShowOrderModal] = useState(false);
    const [showStockPriceModal, setShowStockPriceModal] = useState(false);
    const [showProductPublishModal, setShowProductPublishModal] = useState(false);
    const [manualSyncStock, setManualSyncStock] = useState(true);
    const [manualSyncPrice, setManualSyncPrice] = useState(false);
    const [showDebugMenu] = useState(true);
    const [debugLoading, setDebugLoading] = useState(false);
    const [debugError, setDebugError] = useState('');
    const [debugEntry, setDebugEntry] = useState(null);
    const [debugEntries, setDebugEntries] = useState([]);
    const [debugSelectedIndex, setDebugSelectedIndex] = useState(0);
    const [debugFilterOperation, setDebugFilterOperation] = useState('');
    const [debugFilterStatus, setDebugFilterStatus] = useState('');
    const [rawProductsLoading, setRawProductsLoading] = useState(false);
    const [rawProductsError, setRawProductsError] = useState('');
    const [rawProductsData, setRawProductsData] = useState(null);
    const [feedback, setFeedback] = useState(null);

    useEffect(() => {
        loadSettings();
        setDebugError('');
        setDebugEntry(null);
        setDebugEntries([]);
        setDebugSelectedIndex(0);
        setDebugFilterOperation('');
        setDebugFilterStatus('');
        setRawProductsLoading(false);
        setRawProductsError('');
        setRawProductsData(null);
        setFeedback(null);
    }, [supplier]);

    const loadSettings = async () => {
        if (!supplier) return;
        try {
            const response = await api.getSyncSettings(supplier.id);
            if (response.data) {
                const incomingSchedule = String(response.data.schedule || 'manual');
                const schedule = ALLOWED_SCHEDULES.includes(incomingSchedule) ? incomingSchedule : 'manual';
                const incomingStockAutomationMode = String(response.data.stock_automation_mode || 'scheduled');
                const stock_automation_mode = ALLOWED_STOCK_AUTOMATION_MODES.includes(incomingStockAutomationMode)
                    ? incomingStockAutomationMode
                    : 'scheduled';
                const intervalFromApi = Number(response.data.interval_minutes);
                const interval_minutes = ALLOWED_INTERVALS.includes(intervalFromApi) ? intervalFromApi : 5;

                setSettings({
                    sync_stock: toCheckboxValue(response.data.sync_stock),
                    sync_price: false,
                    sync_products: false,
                    sync_orders: toCheckboxValue(response.data.sync_orders),
                    stock_automation_mode,
                    schedule,
                    interval_minutes
                });
            }
        } catch (e) {
            console.error('Ayar yükleme hatası:', e);
        }
    };

    const handleSave = async () => {
        setFeedback(null);
        setLoading(true);
        try {
            // Save sync settings
            await api.saveSyncSettings(supplier.id, settings);
            setFeedback({ type: 'success', message: 'Tüm ayarlar kaydedildi.' });

            // Refresh supplier data to show updated values
            if (onSupplierUpdate) {
                await onSupplierUpdate();
            }
        } catch (e) {
            console.error(e);
            setFeedback({ type: 'error', message: e.response?.data?.message || e.message || 'Ayarlar kaydedilemedi.' });
        }
        setLoading(false);
    };

    const handleManualSync = async (type, selectedItems = [], variationChoices = {}) => {
        if (type === 'product' && selectedItems.length === 0 && !showProductModal) {
            // If just clicked button for products, show modal first
            // But we need a way to support "Sync All" bypassing modal? 
            // The modal handles "Sync All" if empty.
            setShowProductModal(true);
            return;
        }

        setLoading(true);
        setFeedback(null);
        try {
            const res = await api.runSync(supplier.id, type, selectedItems, variationChoices);
            if (res.data.success) {
                if (type === 'order') {
                    const jobId = Number(res.data.job_id || 0);
                    const requiresApproval = !!res.data.requires_approval;
                    const queued = !!res.data.queued;

                    if (queued && requiresApproval) {
                        setFeedback({ type: 'success', message: `Sipariş işi onay bekliyor. İş #${jobId}` });
                    } else if (queued) {
                        setFeedback({ type: 'success', message: `Sipariş işi kuyruğa alındı. İş #${jobId}` });
                    } else {
                        setFeedback({ type: 'success', message: res.data.message || 'Sipariş işlemi tamamlandı.' });
                    }
                } else {
                    setFeedback({ type: 'success', message: `Senkron başlatıldı: ${res.data.message || 'İşlem başarılı'}` });
                }
            } else {
                setFeedback({ type: 'error', message: `Senkron başarısız: ${res.data.message || 'Bilinmeyen hata'}` });
            }
        } catch (e) {
            console.error('Sync error:', e);
            setFeedback({ type: 'error', message: `Senkron başlatma hatası: ${e.response?.data?.message || e.message || 'Bilinmeyen hata'}` });
        }
        setLoading(false);
    };

    const handleDirectSync = (items, variationChoices) => {
        handleManualSync('product', items, variationChoices);
    };

    const handleOrderSync = (items) => {
        handleManualSync('order', items);
    };

    const handleStockPriceSync = async (selectedItems = []) => {
        setFeedback(null);
        setLoading(true);
        try {
            const res = await api.runStockPriceSync(
                supplier.id,
                selectedItems,
                manualSyncStock,
                manualSyncPrice
            );
            if (res.data && res.data.success) {
                const queued = !!res.data.queued;
                const requiresApproval = !!res.data.requires_approval;
                const jobId = Number(res.data.job_id || 0);
                const reason = String(res.data.reason || '');

                if (!queued && reason === 'no_change') {
                    setFeedback({ type: 'success', message: 'Stok/fiyat değişikliği yok. Kuyruk oluşturulmadı.' });
                } else if (queued && requiresApproval) {
                    setFeedback({ type: 'success', message: `Şüpheli fiyat düşüşü bulundu. İş onay bekliyor. İş #${jobId}` });
                } else if (queued) {
                    setFeedback({ type: 'success', message: `Stok/fiyat gönderimi kuyruğa alındı. İş #${jobId}` });
                } else {
                    setFeedback({ type: 'success', message: res.data.message || 'Stok/fiyat işlemi tamamlandı.' });
                }
            } else {
                setFeedback({ type: 'error', message: `Stok/fiyat gönderimi başarısız: ${res.data?.message || 'Bilinmeyen hata'}` });
            }
        } catch (e) {
            console.error(e);
            setFeedback({ type: 'error', message: e.response?.data?.message || e.message || 'Stok/fiyat gönderim hatası.' });
        }
        setLoading(false);
    };

    const handleProductPublish = async (selectedItems = [], productOverrides = {}) => {
        setFeedback(null);
        setLoading(true);
        try {
           const res = await api.publishProducts(supplier.id, selectedItems, productOverrides);
           const result = res.data?.result || {};
           const batchId = result.response?.batchId || result.response?.batchRequestId || result.response?.trackingId || result.response?.id || '-';
            const uploaded = result.uploaded || 0;
            const updated = result.updated || 0;
            const unchanged = result.unchanged || 0;
            const detail = (uploaded && updated) ? ` (${uploaded} yeni, ${updated} güncelleme)` : (uploaded ? ' (yeni)' : (updated ? ' (güncelleme)' : ''));
            const extra = unchanged ? `, ${unchanged} değişiklik yok (atlandı)` : '';
            const batchNote = result.batch_status === 'pending' ? ' (Ciceksepeti onay bekliyor)' : (result.batch_status === 'completed' ? ' (onaylandı)' : '');
            setFeedback({ type: 'success', message: `${result.sent || 0} ürün ${supplier.name || supplier.marketplace_key}'a gönderildi${detail}${extra}. Batch ID: ${batchId}${batchNote}` });
            if (result.batch_status === 'pending' && batchId !== '-') {
                pollBatchStatus(batchId);
            }
        } catch (e) {
            setFeedback({ type: 'error', message: e.response?.data?.message || e.message || 'Ürün gönderimi başarısız.' });
        }
        setLoading(false);
    };

    const pollBatchStatus = async (batchId, attempt = 1) => {
        try {
            const res = await api.getPublishBatchStatus(supplier.id, batchId);
            const status = String(res.data?.status || 'pending');
            const msg = String(res.data?.message || '');
            if (status === 'completed') {
                setFeedback({ type: 'success', message: `Batch ${batchId}: Ciceksepeti onayladı. Ürünler yayında.` });
                return;
            }
            if (status === 'failed' || status === 'timeout' || status === 'error') {
                setFeedback({ type: 'error', message: `Batch ${batchId}: ${msg || status}` });
                return;
            }
            setFeedback({ type: 'success', message: `Batch ${batchId}: Ciceksepeti onay bekliyor (kontrol #${attempt})${msg ? ' — ' + msg : ''}` });
        } catch (e) {
            setFeedback({ type: 'error', message: `Batch ${batchId} durum sorgusu hatası: ${e.response?.data?.message || e.message}` });
        }
        if (attempt < 20) {
            setTimeout(() => pollBatchStatus(batchId, attempt + 1), 15000);
        }
    };

    const loadMarketplaceDebug = async (opOverride = null, statusOverride = null) => {
        if (!supplier) return;

        setDebugLoading(true);
        setDebugError('');
        try {
            const options = { limit: 40 };
            const operation = opOverride !== null ? opOverride : debugFilterOperation.trim();
            const status = statusOverride !== null ? statusOverride : debugFilterStatus;
            if (operation !== '') {
                options.operation = operation;
            }
            if (status !== '') {
                options.status_code = Number(status);
            }

            const res = await api.getMarketplaceHttpDebug(supplier.id, supplier.marketplace_key || '', options);
            const history = Array.isArray(res.data?.history) ? res.data.history : [];

            if (res.data && (res.data.success || history.length > 0)) {
                const entries = history.length > 0
                    ? history
                    : (res.data?.entry ? [res.data.entry] : []);

                setDebugEntries(entries);
                setDebugSelectedIndex(0);
                setDebugEntry(entries.length > 0 ? entries[0] : null);
                if (entries.length === 0) {
                    setDebugError('Debug kaydı bulunamadı');
                }
            } else {
                setDebugEntries([]);
                setDebugSelectedIndex(0);
                setDebugEntry(null);
                setDebugError(res.data?.message || 'Debug kaydı bulunamadı');
            }
        } catch (e) {
            console.error(e);
            setDebugEntries([]);
            setDebugSelectedIndex(0);
            setDebugEntry(null);
            setDebugError(e.response?.data?.message || e.message || 'Debug verisi alınamadı');
        }
        setDebugLoading(false);
    };

    const loadRawProducts = async () => {
        if (!supplier) return;

        setRawProductsLoading(true);
        setRawProductsError('');
        try {
            const res = await api.getMarketplaceRawProducts(supplier.id, { page: 0, size: 30 });
            if (res.data && res.data.success) {
                setRawProductsData(res.data);
            } else {
                setRawProductsData(res.data || null);
                setRawProductsError(res.data?.message || 'Ham ürün verisi alınamadı');
            }
        } catch (e) {
            console.error(e);
            setRawProductsData(e.response?.data || null);
            setRawProductsError(e.response?.data?.message || e.message || 'Ham ürün verisi alınamadı');
        }
        setRawProductsLoading(false);
    };

    const formatDebugValue = (value) => {
        const tryParseJson = (input) => {
            if (typeof input !== 'string') return null;
            const trimmed = input.trim();
            if (!trimmed) return null;
            if (!(trimmed.startsWith('{') || trimmed.startsWith('['))) return null;
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                return null;
            }
        };

        const normalizeForPretty = (input) => {
            if (Array.isArray(input)) {
                return input.map(normalizeForPretty);
            }

            if (input && typeof input === 'object') {
                const normalized = {};
                Object.keys(input).forEach((key) => {
                    normalized[key] = normalizeForPretty(input[key]);
                });
                return normalized;
            }

            if (typeof input === 'string') {
                const parsed = tryParseJson(input);
                if (parsed !== null) {
                    return normalizeForPretty(parsed);
                }
                return input;
            }

            return input;
        };

        if (value === null || typeof value === 'undefined') {
            return '';
        }

        try {
            return JSON.stringify(normalizeForPretty(value), null, 2);
        } catch (e) {
            return String(value);
        }
    };

    const formatDebugTime = (value) => {
        if (!value) return '-';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return String(value);
        }
        return parsed.toLocaleString();
    };

    const getDebugOperation = (entry) => {
        if (!entry || typeof entry !== 'object') return '-';
        if (entry.operation) return String(entry.operation);
        if (entry.request && entry.request.url) return String(entry.request.url);
        return '-';
    };

    const getDebugStatusCode = (entry) => {
        if (!entry || !entry.response || typeof entry.response !== 'object') return '';
        if (typeof entry.response.status_code !== 'undefined') {
            return String(entry.response.status_code);
        }
        if (entry.response.error) return 'ERR';
        return '';
    };

    const getDebugDuration = (entry) => {
        if (!entry || !entry.response || typeof entry.response !== 'object') return '';
        const value = entry.response.duration_ms;
        if (typeof value === 'number') return `${value} ms`;
        return '';
    };

    const selectDebugEntry = (index) => {
        if (!Array.isArray(debugEntries) || !debugEntries[index]) {
            return;
        }
        setDebugSelectedIndex(index);
        setDebugEntry(debugEntries[index]);
    };

    return (
        <div>
            <h2>Senkron Ayarları</h2>

            {feedback && (
                <div className={`multi-sync-feedback ${feedback.type}`} role={feedback.type === 'error' ? 'alert' : 'status'} aria-live="polite">
                    {feedback.message}
                </div>
            )}

            <div className="grid grid-2">
                <div>
                    <h4>Otomasyon</h4>
                    <div className="form-group">
                        <label className="checkbox-inline-label">
                            <input
                                className="checkbox-inline-input"
                                type="checkbox"
                                checked={settings.sync_stock}
                                onChange={e => setSettings({ ...settings, sync_stock: e.target.checked })}
                            />
                            Stok Gönderimi Otomasyonu
                        </label>
                    </div>
                    <div className="form-group">
                        <label className="checkbox-inline-label">
                            <input
                                className="checkbox-inline-input"
                                type="checkbox"
                                checked={settings.sync_orders}
                                onChange={e => setSettings({ ...settings, sync_orders: e.target.checked })}
                            />
                            Siparişleri Otomatik İçe Aktar
                        </label>
                    </div>

                    <div className="form-group">
                        <label>
                            Stok Otomasyon Modu
                        </label>
                        <select
                            value={settings.stock_automation_mode}
                            onChange={e => setSettings({ ...settings, stock_automation_mode: e.target.value })}
                        >
                            <option value="scheduled">Zamanlanmış (scheduled)</option>
                            <option value="event_driven">Olay Bazlı (event_driven)</option>
                        </select>
                    </div>

                    <div className="form-group">
                        <label>Sipariş Çekim Sıklığı</label>
                        <select value={settings.schedule} onChange={e => setSettings({ ...settings, schedule: e.target.value })}>
                            <option value="manual">Sadece Manuel</option>
                            <option value="hourly">Saatlik</option>
                            <option value="daily">Günlük</option>
                            <option value="per_minute">Dakikalık</option>
                        </select>
                    </div>
                    {settings.schedule === 'per_minute' && (
                        <div className="form-group">
                            <label>Kaç Dakikada Bir</label>
                            <select
                                value={settings.interval_minutes}
                                onChange={e => setSettings({ ...settings, interval_minutes: Number(e.target.value) })}
                            >
                                {ALLOWED_INTERVALS.map((minute) => (
                                    <option key={minute} value={minute}>
                                        {minute} Dakika
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div className="info-note">
                        <span className="info-note-icon" aria-hidden="true">i</span>
                        <span>Sipariş çekim sıklığı sipariş otomasyonu için kullanılır. Stok otomasyon modu "Zamanlanmış" ise stok gönderimi de bu zamanlamayı kullanır. "Olay Bazlı" modda sipariş oluşturma/import ve stok değişimlerinde sadece ilgili SKU'lar kuyruğa alınır.</span>
                    </div>
                    <div className="info-note">
                        <span className="info-note-icon" aria-hidden="true">i</span>
                        <span>Not: Otomatik fiyat güncelleme ve otomatik ürün içe aktarımı kaldırıldı. Fiyat gönderimi sadece manuel stok/fiyat ekranından yapılır.</span>
                    </div>

                    <button className="btn" onClick={handleSave} disabled={loading}>
                        {loading ? 'Kaydediliyor...' : 'Tüm Ayarları Kaydet'}
                    </button>
                </div>

                <div style={{ borderLeft: '1px solid #eee', paddingLeft: '20px' }}>
                    <h4>Manuel İşlemler</h4>
                    {!isHepsiburada && <div className="form-group checkbox-stack" style={{ marginBottom: '10px' }}>
                        <label className="checkbox-inline-label">
                            <input
                                className="checkbox-inline-input"
                                type="checkbox"
                                checked={manualSyncStock}
                                onChange={e => setManualSyncStock(e.target.checked)}
                            />
                            Manuel Gönderimde Stok Güncelle
                        </label>
                        <label className="checkbox-inline-label">
                            <input
                                className="checkbox-inline-input"
                                type="checkbox"
                                checked={manualSyncPrice}
                                onChange={e => setManualSyncPrice(e.target.checked)}
                            />
                            Manuel Gönderimde Fiyat Güncelle
                        </label>
                    </div>}
                    <div style={{ display: 'flex', gap: '10px', flexDirection: 'column' }}>
                        {!isHepsiburada && <button className="btn" onClick={() => setShowProductModal(true)} disabled={loading}>Ürün Senkronu Çalıştır (İçe Aktar)</button>}
                        <button className="btn" onClick={() => setShowProductPublishModal(true)} disabled={loading} style={{ background: '#f27a1a', color: 'white' }}>
                            Woo Ürünlerini {supplier.name || supplier.marketplace_key}'a Gönder
                        </button>
                        {!isHepsiburada && <button className="btn" onClick={() => setShowOrderModal(true)} disabled={loading} style={{ background: '#17a2b8', color: 'white' }}>Sipariş Senkronu Çalıştır (İçe Aktar)</button>}
                        {!isHepsiburada && <button className="btn" onClick={() => setShowStockPriceModal(true)} disabled={loading} style={{ background: '#f27a1a', color: 'white' }}>
                            {`Stok ve Fiyatı ${supplier?.name || 'Pazar Yeri'}'ne Gönder`}
                        </button>}
                    </div>

                    {showDebugMenu && (
                        <div style={{ marginTop: '12px', border: '1px dashed #b4b8bf', borderRadius: '6px', padding: '12px', background: '#fafbfd' }}>
                            <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '8px', flexWrap: 'wrap' }}>
                                <strong>Debug Menüsü (HTTP Geçmişi)</strong>
                                <input
                                    value={debugFilterOperation}
                                    onChange={e => setDebugFilterOperation(e.target.value)}
                                    placeholder="Operasyon filtrele (örn: StokKontrolListesi)"
                                    style={{ minWidth: '220px', fontSize: '12px', padding: '4px 6px' }}
                                />
                                <select
                                    value={debugFilterStatus}
                                    onChange={e => setDebugFilterStatus(e.target.value)}
                                    style={{ width: '110px', fontSize: '12px', padding: '4px 6px' }}
                                >
                                    <option value="">Tüm Status</option>
                                    <option value="200">200</option>
                                    <option value="400">400</option>
                                    <option value="401">401</option>
                                    <option value="500">500</option>
                                </select>
                                <button
                                    className="btn"
                                    onClick={loadMarketplaceDebug}
                                    disabled={debugLoading}
                                    style={{ padding: '4px 10px', fontSize: '12px' }}
                                >
                                    {debugLoading ? 'Yükleniyor...' : 'Debug Geçmişini Getir'}
                                </button>
                                <button
                                    className="btn"
                                    onClick={() => loadMarketplaceDebug('products', '400')}
                                    disabled={debugLoading}
                                    style={{ padding: '4px 10px', fontSize: '12px', background: '#c0392b', color: 'white' }}
                                >
                                    Ürün Gönderim Hatası (400)
                                </button>
                                <button
                                    className="btn"
                                    onClick={loadRawProducts}
                                    disabled={rawProductsLoading}
                                    style={{ padding: '4px 10px', fontSize: '12px', background: '#2f6fed', color: 'white' }}
                                >
                                    {rawProductsLoading ? 'Yükleniyor...' : 'Ham Ürün Verisini Getir'}
                                </button>
                            </div>

                            <p style={{ marginTop: 0, color: '#555' }}>
                                Bu panelde seçili pazar yeri için son SOAP/HTTP çağrı geçmişi listelenir.
                            </p>

                            {debugError && <p style={{ color: '#c0392b', marginBottom: '8px' }}>{debugError}</p>}
                            {rawProductsError && <p style={{ color: '#c0392b', marginBottom: '8px' }}>{rawProductsError}</p>}

                            {debugEntries.length > 0 && (
                                <div style={{ marginBottom: '10px', border: '1px solid #d6dbe3', borderRadius: '4px', maxHeight: '220px', overflowY: 'auto' }}>
                                    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                        <thead style={{ position: 'sticky', top: 0, background: '#f3f6fb' }}>
                                            <tr>
                                                <th style={{ textAlign: 'left', padding: '6px', borderBottom: '1px solid #d6dbe3' }}>Zaman</th>
                                                <th style={{ textAlign: 'left', padding: '6px', borderBottom: '1px solid #d6dbe3' }}>Operasyon</th>
                                                <th style={{ textAlign: 'left', padding: '6px', borderBottom: '1px solid #d6dbe3' }}>Status</th>
                                                <th style={{ textAlign: 'left', padding: '6px', borderBottom: '1px solid #d6dbe3' }}>Süre</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {debugEntries.map((entry, index) => (
                                                <tr
                                                    key={`debug-entry-${index}`}
                                                    onClick={() => selectDebugEntry(index)}
                                                    style={{
                                                        cursor: 'pointer',
                                                        background: debugSelectedIndex === index ? '#eaf3ff' : 'transparent',
                                                    }}
                                                >
                                                    <td style={{ padding: '6px', borderBottom: '1px solid #edf0f4', fontSize: '12px' }}>
                                                        {formatDebugTime(entry?.timestamp)}
                                                    </td>
                                                    <td style={{ padding: '6px', borderBottom: '1px solid #edf0f4', fontSize: '12px' }}>
                                                        {getDebugOperation(entry)}
                                                    </td>
                                                    <td style={{ padding: '6px', borderBottom: '1px solid #edf0f4', fontSize: '12px' }}>
                                                        {getDebugStatusCode(entry) || '-'}
                                                    </td>
                                                    <td style={{ padding: '6px', borderBottom: '1px solid #edf0f4', fontSize: '12px' }}>
                                                        {getDebugDuration(entry) || '-'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {debugEntry && (
                                <div style={{ display: 'grid', gap: '8px' }}>
                                    <div style={{ fontSize: '12px', color: '#4a5568' }}>
                                        <strong>Seçili Kayıt:</strong> {getDebugOperation(debugEntry)} | Status: {getDebugStatusCode(debugEntry) || '-'} | Süre: {getDebugDuration(debugEntry) || '-'}
                                    </div>
                                    <div>
                                        <strong style={{ fontSize: '12px' }}>Request</strong>
                                        <textarea
                                            readOnly
                                            value={formatDebugValue(debugEntry.request)}
                                            style={{
                                                width: '100%',
                                                height: '180px',
                                                marginTop: '4px',
                                                fontFamily: 'monospace',
                                                fontSize: '12px',
                                                lineHeight: 1.4,
                                                resize: 'vertical',
                                                overflow: 'auto',
                                                whiteSpace: 'pre',
                                                boxSizing: 'border-box',
                                            }}
                                        />
                                    </div>
                                    <div>
                                        <strong style={{ fontSize: '12px' }}>Response</strong>
                                        <textarea
                                            readOnly
                                            value={formatDebugValue(debugEntry.response)}
                                            style={{
                                                width: '100%',
                                                height: '220px',
                                                marginTop: '4px',
                                                fontFamily: 'monospace',
                                                fontSize: '12px',
                                                lineHeight: 1.4,
                                                resize: 'vertical',
                                                overflow: 'auto',
                                                whiteSpace: 'pre',
                                                boxSizing: 'border-box',
                                            }}
                                        />
                                    </div>
                                </div>
                            )}

                            {rawProductsData && (
                                <div style={{ marginTop: '10px', display: 'grid', gap: '8px' }}>
                                    <div style={{ fontSize: '12px', color: '#4a5568' }}>
                                        <strong>Ham Ürün Özet:</strong> Toplam {rawProductsData.total_items || 0} kayıt, gösterilen {rawProductsData.shown_items || 0} kayıt.
                                    </div>
                                    {Array.isArray(rawProductsData.first_item_keys) && rawProductsData.first_item_keys.length > 0 && (
                                        <div style={{ fontSize: '12px', color: '#4a5568' }}>
                                            <strong>İlk kayıt alanları:</strong> {rawProductsData.first_item_keys.join(', ')}
                                        </div>
                                    )}
                                    <div>
                                        <strong style={{ fontSize: '12px' }}>Ham Ürünler (JSON)</strong>
                                        <textarea
                                            readOnly
                                            value={formatDebugValue(rawProductsData.items || [])}
                                            style={{
                                                width: '100%',
                                                height: '220px',
                                                marginTop: '4px',
                                                fontFamily: 'monospace',
                                                fontSize: '12px',
                                                lineHeight: 1.4,
                                                resize: 'vertical',
                                                overflow: 'auto',
                                                whiteSpace: 'pre',
                                                boxSizing: 'border-box',
                                            }}
                                        />
                                    </div>
                                    {rawProductsData.debug && (
                                        <div>
                                            <strong style={{ fontSize: '12px' }}>Hata Detayı (Son HTTP Debug)</strong>
                                            <textarea
                                                readOnly
                                                value={formatDebugValue(rawProductsData.debug)}
                                                style={{
                                                    width: '100%',
                                                    height: '180px',
                                                    marginTop: '4px',
                                                    fontFamily: 'monospace',
                                                    fontSize: '12px',
                                                    lineHeight: 1.4,
                                                    resize: 'vertical',
                                                    overflow: 'auto',
                                                    whiteSpace: 'pre',
                                                    boxSizing: 'border-box',
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {showProductModal && (
                <ProductSelectorModal
                    supplier={supplier}
                    onClose={() => setShowProductModal(false)}
                    onSync={handleDirectSync}
                />
            )}

            {showOrderModal && (
                <OrderSelectorModal
                    supplier={supplier}
                    onClose={() => setShowOrderModal(false)}
                    onSync={handleOrderSync}
                />
            )}

            {showStockPriceModal && (
                <ProductSelectorModal
                    supplier={supplier}
                    onClose={() => setShowStockPriceModal(false)}
                    onSync={handleStockPriceSync}
                    previewType="stock_price"
                    syncStock={manualSyncStock}
                    syncPrice={manualSyncPrice}
                    title={`Stok/Fiyat Gönderilecek Ürünleri Seç (${supplier?.name || 'Pazar Yeri'})`}
                    submitText="Gönderimi Başlat"
                    emptySelectionConfirm="Hiç ürün seçilmedi. Tüm eşleşen Woo ürünleri gönderilsin mi?"
                />
            )}

            {showProductPublishModal && (
                <ProductSelectorModal
                    supplier={supplier}
                    onClose={() => setShowProductPublishModal(false)}
                    onSync={handleProductPublish}
                    previewType="product_publish"
                    title={`${supplier.name || supplier.marketplace_key}'a Gönderilecek Woo Ürünlerini Seç`}
                    submitText={`${supplier.name || supplier.marketplace_key}'a Gönder`}
                    emptySelectionConfirm={`Hiç ürün seçilmedi. Hazır durumdaki tüm Woo ürünleri ${supplier.name || supplier.marketplace_key}'a gönderilsin mi?`}
                />
            )}
        </div>
    );
}

export default SyncSettings;
