import React, { useState, useEffect } from 'react';
import api from './api';
import Authorization from './components/Tabs/Authorization';
import SyncSettings from './components/Tabs/SyncSettings';
import SyncCenter from './components/Tabs/SyncCenter';
import MarketplaceCategoryMapping from './components/MarketplaceCategoryMapping';
import QuestionsPage from './pages/QuestionsPage';

const pluginUrl = (typeof window !== 'undefined' && window.multiSyncSettings && window.multiSyncSettings.pluginUrl)
    ? String(window.multiSyncSettings.pluginUrl)
    : '';
const iconsVersion = (typeof window !== 'undefined' && window.multiSyncSettings && window.multiSyncSettings.iconsVersion)
    ? String(window.multiSyncSettings.iconsVersion)
    : '';
const updateUrl = (typeof window !== 'undefined' && window.multiSyncSettings && window.multiSyncSettings.updateUrl)
    ? String(window.multiSyncSettings.updateUrl)
    : '';
const updateStatus = (typeof window !== 'undefined' && window.multiSyncSettings && window.multiSyncSettings.updateStatus)
    ? String(window.multiSyncSettings.updateStatus)
    : '';

const marketplaceVisuals = {
    trendyol: { short: 'TY', color: '#f27a1a', icon: 'trendyol.png' },
    n11: { short: 'n11', color: '#4b6cb7', icon: 'n11.png' },
    pazarama: { short: 'P', color: '#7e57c2', icon: 'pazarama.png' },
    ciceksepeti: { short: 'CS', color: '#e30a17', icon: 'ciceksepeti.png' },
    amazon: { short: 'AZ', color: '#146eb4', icon: 'amazon.jpg' },
    pttavm: { short: 'PTT', color: '#1d71b8', icon: 'ptt.png' },
    hepsiburada: { short: 'HB', color: '#ff6000' }
};

const mappingMarketplaces = new Set(Object.keys(marketplaceVisuals));
const questionMarketplaces = new Set(['trendyol']);

function getSupplierTabs(supplier) {
    const marketplaceKey = String(supplier?.marketplace_key || '').toLowerCase();
    return [
        { key: 'authorization', label: 'Yetkilendirme' },
        { key: 'syncsettings', label: 'Senkron Ayarları' },
        mappingMarketplaces.has(marketplaceKey) && { key: 'mappings', label: 'Eşleştirmeler' },
        questionMarketplaces.has(marketplaceKey) && { key: 'questions', label: 'Ürün Yorumları' },
    ].filter(Boolean);
}

function resolveIconUrl(fileName) {
    if (!fileName || !pluginUrl) {
        return '';
    }

    const baseUrl = `${pluginUrl}icons/${fileName}`;
    if (!iconsVersion) {
        return baseUrl;
    }

    return `${baseUrl}?v=${encodeURIComponent(iconsVersion)}`;
}

const syncCenterIconUrl = resolveIconUrl('sync.png');

function getMarketplaceVisual(supplier) {
    const key = String(supplier?.marketplace_key || '').toLowerCase();
    if (marketplaceVisuals[key]) {
        const item = marketplaceVisuals[key];
        return {
            ...item,
            iconUrl: resolveIconUrl(item.icon),
        };
    }

    const short = String(supplier?.name || 'M').trim().slice(0, 2).toUpperCase();
    return { short: short || 'M', color: supplier?.color || '#5a6b82', iconUrl: '' };
}

function App() {
    const [suppliers, setSuppliers] = useState([]);
    const [activeSupplier, setActiveSupplier] = useState(null);
    const [activeTab, setActiveTab] = useState('authorization');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const supplierTabs = getSupplierTabs(activeSupplier);

    useEffect(() => {
        fetchSuppliers();
    }, []);

    const fetchSuppliers = async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await api.getSuppliers();
            setSuppliers(res.data);
            if (res.data.length > 0) {
                const activeId = activeSupplier && activeSupplier.id ? Number(activeSupplier.id) : null;
                const selected = activeId
                    ? res.data.find(supplier => Number(supplier.id) === activeId)
                    : null;
                setActiveSupplier(selected || res.data[0]);
            } else {
                setActiveSupplier(null);
            }
        } catch (e) {
            console.error(e);
            setError(e.message || 'Pazar yeri bilgileri alınamadı');
        }
        setLoading(false);
    };

    if (loading && !activeSupplier && suppliers.length === 0) return <div>Veriler yükleniyor...</div>;
    if (error && suppliers.length === 0) return <div style={{ color: 'red' }}>Hata: {error} <button onClick={fetchSuppliers}>Tekrar Dene</button></div>;

    const isSyncCenterActive = activeTab === 'synccenter';

    const selectSyncCenter = () => {
        setActiveTab('synccenter');
    };

    const selectSupplier = (supplier) => {
        setActiveSupplier(supplier);
        setActiveTab((prev) => getSupplierTabs(supplier).some(tab => tab.key === prev) ? prev : 'authorization');
    };

    return (
        <div className="multi-sync-container">
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <h1>Open Entegre</h1>
                {updateUrl && <a className="button button-small" href={updateUrl}>Güncelle</a>}
                {updateStatus === 'current' && <span style={{ color: '#50705a' }}>Sürüm güncel.</span>}
                {updateStatus === 'error' && <span style={{ color: '#b32d2e' }}>Güncelleme kontrol edilemedi.</span>}
            </div>

            <div className="marketplace-selector">
                <div className="marketplace-carousel" role="tablist" aria-label="Genel ve pazar yeri seçimi">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={isSyncCenterActive}
                        className={`marketplace-pill global-center ${isSyncCenterActive ? 'active' : ''}`}
                        onClick={selectSyncCenter}
                    >
                        <span className="marketplace-logo global-logo">
                            {syncCenterIconUrl ? (
                                <img
                                    className="marketplace-logo-image"
                                    src={syncCenterIconUrl}
                                    alt="Senkron Merkezi"
                                    loading="lazy"
                                />
                            ) : (
                                'SC'
                            )}
                        </span>
                        <span className="marketplace-pill-name">Senkron Merkezi</span>
                        <span className="marketplace-pill-badge">Genel</span>
                    </button>

                    {suppliers.map(supplier => {
                        const visual = getMarketplaceVisual(supplier);
                        const isSupplierActive = !isSyncCenterActive && Number(activeSupplier?.id) === Number(supplier.id);
                        return (
                            <button
                                key={supplier.id}
                                type="button"
                                role="tab"
                                aria-selected={isSupplierActive}
                                className={`marketplace-pill ${isSupplierActive ? 'active' : ''}`}
                                onClick={() => selectSupplier(supplier)}
                            >
                                <span
                                    className="marketplace-logo"
                                    style={visual.iconUrl ? undefined : { backgroundColor: visual.color }}
                                >
                                    {visual.iconUrl ? (
                                        <img
                                            className="marketplace-logo-image"
                                            src={visual.iconUrl}
                                            alt={supplier.name}
                                            loading="lazy"
                                        />
                                    ) : (
                                        visual.short
                                    )}
                                </span>
                                <span className="marketplace-pill-name">{supplier.name}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {!isSyncCenterActive && (
                <div className="multi-sync-nav">
                    {supplierTabs.map(tab => {
                        const key = tab.key;
                        return (
                            <div
                                key={key}
                                className={`nav-item ${activeTab === key ? 'active' : ''}`}
                                onClick={() => setActiveTab(key)}
                            >
                                {tab.label}
                            </div>
                        );
                    })}
                </div>
            )}

            <div className="tab-content">
                {isSyncCenterActive && <SyncCenter suppliers={suppliers} />}
                {!isSyncCenterActive && activeTab === 'authorization' && activeSupplier && <Authorization supplier={activeSupplier} onSupplierUpdate={fetchSuppliers} />}
                {!isSyncCenterActive && activeTab === 'authorization' && !activeSupplier && <p>Pazar yeri bulunamadı.</p>}
                {!isSyncCenterActive && activeTab === 'syncsettings' && activeSupplier && <SyncSettings supplier={activeSupplier} onSupplierUpdate={fetchSuppliers} />}
                {!isSyncCenterActive && activeTab === 'syncsettings' && !activeSupplier && <p>Pazar yeri bulunamadı.</p>}
                {!isSyncCenterActive && activeTab === 'mappings' && activeSupplier && <MarketplaceCategoryMapping supplier={activeSupplier} />}
                {!isSyncCenterActive && activeTab === 'questions' && activeSupplier && <QuestionsPage key={activeSupplier.id} supplier={activeSupplier} />}
            </div>
        </div>
    );
}

export default App;
