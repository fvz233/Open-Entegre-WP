import React, { useEffect, useState } from 'react';
import api from '../api';
import QuestionReplyModal from '../components/QuestionReplyModal';

const pluginUrl = (typeof window !== 'undefined' && window.multiSyncSettings && window.multiSyncSettings.pluginUrl)
    ? String(window.multiSyncSettings.pluginUrl)
    : '';
const iconsVersion = (typeof window !== 'undefined' && window.multiSyncSettings && window.multiSyncSettings.iconsVersion)
    ? String(window.multiSyncSettings.iconsVersion)
    : '';

const QUESTIONS_REFRESH_TIMEOUT_SILENT_MS = 60000;
const QUESTIONS_REFRESH_TIMEOUT_MANUAL_MS = 180000;
const marketplaceVisuals = {
    trendyol: { short: 'TY', color: '#f27a1a', icon: 'trendyol.png', label: 'Trendyol' },
    n11: { short: 'n11', color: '#4b6cb7', icon: 'n11.png', label: 'n11' },
    pazarama: { short: 'P', color: '#7e57c2', icon: 'pazarama.png', label: 'Pazarama' },
    ciceksepeti: { short: 'ÇS', color: '#e30a17', icon: 'ciceksepeti.png', label: 'Çiçeksepeti' },
    amazon: { short: 'AZ', color: '#146eb4', icon: 'amazon.jpg', label: 'Amazon' },
    pttavm: { short: 'PTT', color: '#1d71b8', icon: 'ptt.png', label: 'PTTAVM' },
    hepsiburada: { short: 'HB', color: '#ff6000', label: 'Hepsiburada' },
};

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

function formatDate(value) {
    if (!value) return '-';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
}

function QuestionsPage({ supplier }) {
    const [items, setItems] = useState([]);
    const [pagination, setPagination] = useState({ page: 1, per_page: 20, total: 0, total_pages: 1 });
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [selectedItem, setSelectedItem] = useState(null);
    const [replySubmitting, setReplySubmitting] = useState(false);

    const [filters, setFilters] = useState({
        page: 1,
        per_page: 20,
        supplier_id: String(supplier.id),
        status: '',
        search: '',
    });

    useEffect(() => {
        loadQuestions();
    }, [filters.page, filters.supplier_id, filters.status, filters.search]);

    useEffect(() => {
        bootstrapRefresh();
    }, []);

    const loadQuestions = async () => {
        setLoading(true);
        setError('');
        try {
            const params = {
                page: filters.page,
                per_page: filters.per_page,
            };
            if (filters.supplier_id) params.supplier_id = Number(filters.supplier_id);
            if (filters.status) params.status = filters.status;
            if (filters.search) params.search = filters.search;

            const res = await api.getQuestions(params);
            if (res.data?.success) {
                const nextItems = Array.isArray(res.data.items) ? res.data.items : [];
                setItems(nextItems);
                setPagination(res.data.pagination || { page: 1, per_page: 20, total: 0, total_pages: 1 });

                const autoRefresh = res.data?.auto_refresh;
                const failed = Array.isArray(autoRefresh?.failed_suppliers) ? autoRefresh.failed_suppliers : [];
                if (nextItems.length === 0) {
                    if (failed.length > 0) {
                        const details = failed
                            .slice(0, 3)
                            .map((x) => `${x?.supplier_name || x?.supplier_id || '-'}: ${x?.message || 'hata'}`)
                            .join(' | ');
                        setError(`Soru alınamadı. ${details}`);
                    }
                }
            } else {
                setError(res.data?.message || 'Sorular alınamadı.');
            }
        } catch (e) {
            console.error(e);
            setError(e.response?.data?.message || e.message || 'Sorular alınamadı.');
        }
        setLoading(false);
    };

    const bootstrapRefresh = () => {
        refreshQuestions(true);
    };

    const refreshQuestions = async (silent = false) => {
        setRefreshing(true);
        if (!silent) {
            setNotice('');
            setError('');
        }

        try {
            const supplierId = filters.supplier_id ? Number(filters.supplier_id) : null;
            const res = await api.refreshQuestions(supplierId, {
                timeout: silent ? QUESTIONS_REFRESH_TIMEOUT_SILENT_MS : QUESTIONS_REFRESH_TIMEOUT_MANUAL_MS,
            });
            if (res.data?.success) {
                const summary = res.data.summary || {};
                if (!silent) {
                    const failedCount = Array.isArray(summary.failed_suppliers) ? summary.failed_suppliers.length : 0;
                    if (failedCount > 0) {
                        const failedText = summary.failed_suppliers
                            .slice(0, 3)
                            .map((x) => `${x?.supplier_name || x?.supplier_id || '-'}: ${x?.message || 'hata'}`)
                            .join(' | ');
                        setError(`Yenilemede ${failedCount} tedarikçide hata var. ${failedText}`);
                    }
                    setNotice(`Yenileme tamamlandı. Çekilen: ${summary.fetched || 0}, Güncellenen: ${summary.upserted || 0}`);
                }
            } else if (!silent) {
                setError(res.data?.message || 'Yenileme başarısız.');
            }
        } catch (e) {
            console.error(e);
            const isTimeout = String(e?.code || '').toUpperCase() === 'ECONNABORTED'
                || String(e?.message || '').toLowerCase().includes('timeout');
            if (!silent) {
                setError(
                    isTimeout
                        ? 'Yenileme süresi doldu. Liste cache verisiyle gösteriliyor.'
                        : (e.response?.data?.message || e.message || 'Yenileme başarısız.')
                );
            }
        }

        await loadQuestions();
        setRefreshing(false);
    };

    const getMarketplaceVisual = (marketplaceKey) => {
        const key = String(marketplaceKey || '').toLowerCase();
        const visual = marketplaceVisuals[key];
        if (visual) {
            return {
                ...visual,
                key,
                iconUrl: resolveIconUrl(visual.icon),
            };
        }

        return {
            short: 'MS',
            color: '#5a6b82',
            label: key || 'Bilinmiyor',
            iconUrl: '',
            key,
        };
    };

    const getReplyDisabledReason = (item) => {
        const marketplaceKey = String(item?.marketplace_key || '').toLowerCase();
        if (marketplaceKey !== 'trendyol') {
            return 'Bu platform için yanıtlama desteği yok.';
        }
        if (!item?.can_reply) {
            return 'Bu soru yanıtlanamaz durumda.';
        }
        return '';
    };

    const openReplyModal = (item) => {
        setSelectedItem(item);
    };

    const closeReplyModal = () => {
        if (replySubmitting) {
            return;
        }
        setSelectedItem(null);
    };

    const submitReply = async (answerText) => {
        if (!selectedItem?.id) {
            return;
        }

        setReplySubmitting(true);
        setError('');
        setNotice('');
        try {
            const res = await api.replyQuestion(selectedItem.id, answerText);
            if (res.data?.success) {
                setNotice(res.data?.message || 'Yanıt gönderildi.');
                setSelectedItem(null);
                await loadQuestions();
            } else {
                setError(res.data?.message || 'Yanıt gönderilemedi.');
            }
        } catch (e) {
            console.error(e);
            setError(e.response?.data?.message || e.message || 'Yanıt gönderilemedi.');
        }
        setReplySubmitting(false);
    };

    return (
        <div>
            <h2>Ürün Yorumları</h2>

            <div style={{ display: 'flex', gap: '8px', marginBottom: '12px', flexWrap: 'wrap' }}>

                <select
                    value={filters.status}
                    onChange={(e) => setFilters({ ...filters, status: e.target.value, page: 1 })}
                >
                    <option value="">Tüm durumlar</option>
                    <option value="WAITING_FOR_ANSWER">WAITING_FOR_ANSWER</option>
                    <option value="ANSWERED">ANSWERED</option>
                    <option value="REJECTED">REJECTED</option>
                </select>

                <input
                    type="text"
                    value={filters.search}
                    onChange={(e) => setFilters({ ...filters, search: e.target.value, page: 1 })}
                    placeholder="Soru, ürün, müşteri veya ID ara..."
                    style={{ minWidth: '280px' }}
                />

                <button className="btn" onClick={() => refreshQuestions(false)} disabled={refreshing}>
                    {refreshing ? 'Yenileniyor...' : 'Yenile'}
                </button>
            </div>

            {error && (
                <div style={{ marginBottom: '10px', padding: '8px 10px', border: '1px solid #f1b9b9', borderRadius: '8px', background: '#fff4f4', color: '#8a1c1c' }}>
                    {error}
                </div>
            )}
            {notice && (
                <div style={{ marginBottom: '10px', padding: '8px 10px', border: '1px solid #b8dfc2', borderRadius: '8px', background: '#f1fbf4', color: '#1d5d2f' }}>
                    {notice}
                </div>
            )}

            <div style={{ border: '1px solid #d9e1ea', borderRadius: '10px', overflow: 'hidden', background: '#fff' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                        <tr style={{ background: '#f5f8fc' }}>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Soru ID</th>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Platform / Tedarikçi</th>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Müşteri</th>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Ürün</th>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Soru</th>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Durum</th>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Tarih</th>
                            <th style={{ textAlign: 'left', padding: '10px', borderBottom: '1px solid #e2e8f0' }}>Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr><td colSpan="8" style={{ padding: '12px' }}>Yükleniyor...</td></tr>
                        ) : items.length === 0 ? (
                            <tr><td colSpan="8" style={{ padding: '12px' }}>Kayıt bulunamadı.</td></tr>
                        ) : items.map((item) => {
                            const visual = getMarketplaceVisual(item.marketplace_key);
                            const disabledReason = getReplyDisabledReason(item);
                            const disabled = disabledReason !== '';
                            return (
                                <tr key={item.id}>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7' }}>{item.external_question_id || '-'}</td>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7' }}>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '6px' }}>
                                            <span className="multi-sync-platform-badge" style={{ '--ms-badge-bg': visual.color }}>
                                                <span className="multi-sync-platform-badge__icon">
                                                    {visual.iconUrl ? (
                                                        <img src={visual.iconUrl} alt="" loading="lazy" />
                                                    ) : (
                                                        <span>{visual.short}</span>
                                                    )}
                                                </span>
                                                <span className="multi-sync-platform-badge__label">{visual.label}</span>
                                            </span>
                                            <span style={{ fontSize: '12px', color: '#5a6b82' }}>
                                                {item.supplier_name || supplier.name || '-'}
                                            </span>
                                        </div>
                                    </td>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7' }}>{item.customer_name || '-'}</td>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7' }}>{item.product_name || '-'}</td>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7', maxWidth: '320px' }}>
                                        <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                            {item.question_text || '-'}
                                        </div>
                                    </td>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7' }}>{item.status || '-'}</td>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7' }}>{formatDate(item.asked_at)}</td>
                                    <td style={{ padding: '10px', borderBottom: '1px solid #eef2f7' }}>
                                        <button
                                            className="btn"
                                            style={{ padding: '6px 10px', fontSize: '12px' }}
                                            disabled={disabled}
                                            title={disabledReason}
                                            onClick={() => openReplyModal(item)}
                                        >
                                            Edit / Yanıtla
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div style={{ marginTop: '10px', display: 'flex', gap: '8px', alignItems: 'center' }}>
                <button
                    className="btn"
                    disabled={pagination.page <= 1}
                    style={{ padding: '4px 8px', fontSize: '12px' }}
                    onClick={() => setFilters({ ...filters, page: Math.max(1, pagination.page - 1) })}
                >
                    Önceki
                </button>
                <span style={{ fontSize: '12px' }}>Sayfa {pagination.page} / {pagination.total_pages || 1}</span>
                <button
                    className="btn"
                    disabled={pagination.page >= (pagination.total_pages || 1)}
                    style={{ padding: '4px 8px', fontSize: '12px' }}
                    onClick={() => setFilters({ ...filters, page: pagination.page + 1 })}
                >
                    Sonraki
                </button>
                <span style={{ marginLeft: '8px', fontSize: '12px', color: '#5a6b82' }}>
                    Toplam: {pagination.total || 0}
                </span>
            </div>

            <QuestionReplyModal
                item={selectedItem}
                submitting={replySubmitting}
                onClose={closeReplyModal}
                onSubmit={submitReply}
            />
        </div>
    );
}

export default QuestionsPage;
