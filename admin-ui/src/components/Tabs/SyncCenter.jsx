import React, { useEffect, useMemo, useState } from 'react';
import api from '../../api';

function formatDate(value) {
    if (!value) return '-';
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
}

function pretty(value) {
    if (value === null || typeof value === 'undefined') return '-';
    if (typeof value === 'string') return value;
    try {
        return JSON.stringify(value);
    } catch (e) {
        return String(value);
    }
}

function SyncCenter({ suppliers = [] }) {
    const [jobs, setJobs] = useState([]);
    const [changes, setChanges] = useState([]);
    const [pagination, setPagination] = useState({ page: 1, per_page: 20, total: 0, total_pages: 1 });
    const [changesPagination, setChangesPagination] = useState({ page: 1, per_page: 20, total: 0, total_pages: 1 });
    const [loadingJobs, setLoadingJobs] = useState(false);
    const [loadingChanges, setLoadingChanges] = useState(false);
    const [selectedJob, setSelectedJob] = useState(null);
    const [selectedJobItems, setSelectedJobItems] = useState([]);
    const [loadingDetail, setLoadingDetail] = useState(false);

    const [settings, setSettings] = useState({ suspicious_price_drop_percent: 20 });
    const [savingSettings, setSavingSettings] = useState(false);

    const [filters, setFilters] = useState({
        status: '',
        job_type: '',
        supplier_id: '',
        page: 1,
        per_page: 20,
    });

    const supplierNameById = useMemo(() => {
        const map = {};
        suppliers.forEach((supplier) => {
            map[Number(supplier.id)] = supplier.name;
        });
        return map;
    }, [suppliers]);

    const waitingApprovalJobs = useMemo(
        () => jobs.filter((job) => String(job.status) === 'waiting_approval'),
        [jobs]
    );

    useEffect(() => {
        loadSettings();
    }, []);

    useEffect(() => {
        loadJobs();
    }, [filters.page, filters.status, filters.job_type, filters.supplier_id]);

    useEffect(() => {
        loadChanges();
    }, []);

    const loadSettings = async () => {
        try {
            const res = await api.getJobSettings();
            if (res.data?.success && res.data.settings) {
                setSettings(res.data.settings);
            }
        } catch (e) {
            console.error('Queue settings load error:', e);
        }
    };

    const loadJobs = async () => {
        setLoadingJobs(true);
        try {
            const params = {
                page: filters.page,
                per_page: filters.per_page,
            };
            if (filters.status) params.status = filters.status;
            if (filters.job_type) params.job_type = filters.job_type;
            if (filters.supplier_id) params.supplier_id = filters.supplier_id;

            const res = await api.getJobs(params);
            if (res.data?.success) {
                setJobs(Array.isArray(res.data.items) ? res.data.items : []);
                setPagination(res.data.pagination || { page: 1, per_page: 20, total: 0, total_pages: 1 });
            }
        } catch (e) {
            console.error('Jobs load error:', e);
            setJobs([]);
        }
        setLoadingJobs(false);
    };

    const loadChanges = async () => {
        setLoadingChanges(true);
        try {
            const res = await api.getChanges({ page: 1, per_page: 20 });
            if (res.data?.success) {
                setChanges(Array.isArray(res.data.items) ? res.data.items : []);
                setChangesPagination(res.data.pagination || { page: 1, per_page: 20, total: 0, total_pages: 1 });
            }
        } catch (e) {
            console.error('Changes load error:', e);
            setChanges([]);
        }
        setLoadingChanges(false);
    };

    const loadJobDetail = async (jobId) => {
        setLoadingDetail(true);
        try {
            const res = await api.getJob(jobId);
            if (res.data?.success) {
                setSelectedJob(res.data.job || null);
                setSelectedJobItems(Array.isArray(res.data.items) ? res.data.items : []);
            }
        } catch (e) {
            console.error('Job detail load error:', e);
            setSelectedJob(null);
            setSelectedJobItems([]);
        }
        setLoadingDetail(false);
    };

    const handleApprove = async (jobId) => {
        try {
            const res = await api.approveJob(jobId);
            if (!res.data?.success) {
                alert(res.data?.message || 'Onaylanamadı');
                return;
            }
            await loadJobs();
            if (selectedJob?.id === jobId) {
                await loadJobDetail(jobId);
            }
        } catch (e) {
            console.error(e);
            alert('Onaylama hatası');
        }
    };

    const handleReject = async (jobId) => {
        if (!confirm('Bu işi reddetmek istediğinize emin misiniz?')) {
            return;
        }

        try {
            const res = await api.rejectJob(jobId);
            if (!res.data?.success) {
                alert(res.data?.message || 'Reddedilemedi');
                return;
            }
            await loadJobs();
            if (selectedJob?.id === jobId) {
                setSelectedJob(null);
                setSelectedJobItems([]);
            }
        } catch (e) {
            console.error(e);
            alert('Reddetme hatası');
        }
    };

    const handleDeleteJob = async (jobId) => {
        if (!confirm(`#${jobId} işi ve kayıtları silinsin mi?`)) {
            return;
        }
        try {
            const res = await api.deleteJob(jobId);
            if (!res.data?.success) {
                alert(res.data?.message || 'Silinemedi');
                return;
            }
            await loadJobs();
            if (selectedJob?.id === jobId) {
                setSelectedJob(null);
                setSelectedJobItems([]);
            }
        } catch (e) {
            console.error(e);
            alert('Silme hatası');
        }
    };

    const handleClearJobs = async () => {
        if (!confirm('Çalışmayan TÜM kuyruk işi kayıtları silinsin mi? (çalışan işler korunur)')) {
            return;
        }
        try {
            const res = await api.clearJobs();
            if (!res.data?.success) {
                alert(res.data?.message || 'Temizlenemedi');
                return;
            }
            await loadJobs();
            setSelectedJob(null);
            setSelectedJobItems([]);
            alert(res.data.message || 'Temizlendi');
        } catch (e) {
            console.error(e);
            alert('Temizleme hatası');
        }
    };

    const handleSaveSettings = async () => {
        setSavingSettings(true);
        try {
            const res = await api.saveJobSettings({
                suspicious_price_drop_percent: Number(settings.suspicious_price_drop_percent),
            });
            if (res.data?.success && res.data.settings) {
                setSettings(res.data.settings);
                alert('Ayar kaydedildi');
            } else {
                alert('Ayar kaydedilemedi');
            }
        } catch (e) {
            console.error(e);
            alert('Ayar kaydetme hatası');
        }
        setSavingSettings(false);
    };

    return (
        <div>
            <h2>Senkron Merkezi</h2>

            <div style={{ marginBottom: '16px', border: '1px solid #e8ebf0', borderRadius: '6px', padding: '12px' }}>
                <h4 style={{ margin: '0 0 10px' }}>Risk Ayarları</h4>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <label>Şüpheli fiyat düşüşü eşiği (%)</label>
                    <input
                        type="number"
                        min="1"
                        max="95"
                        value={settings.suspicious_price_drop_percent}
                        onChange={(e) => setSettings({ ...settings, suspicious_price_drop_percent: e.target.value })}
                        style={{ width: '90px' }}
                    />
                    <button className="btn" onClick={handleSaveSettings} disabled={savingSettings}>
                        {savingSettings ? 'Kaydediliyor...' : 'Kaydet'}
                    </button>
                </div>
            </div>

            <div style={{ marginBottom: '16px', border: '1px solid #e8ebf0', borderRadius: '6px', padding: '12px' }}>
                <h4 style={{ margin: '0 0 10px' }}>Onay Bekleyen İşler ({waitingApprovalJobs.length})</h4>
                {waitingApprovalJobs.length === 0 ? (
                    <p style={{ margin: 0 }}>Onay bekleyen iş yok.</p>
                ) : (
                    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Job</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Tedarikçi</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Neden</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            {waitingApprovalJobs.map((job) => (
                                <tr key={job.id}>
                                    <td style={{ padding: '6px' }}>#{job.id}</td>
                                    <td style={{ padding: '6px' }}>{job.supplier_name || supplierNameById[Number(job.supplier_id)] || '-'}</td>
                                    <td style={{ padding: '6px' }}>{job.approval_reason || '-'}</td>
                                    <td style={{ padding: '6px', display: 'flex', gap: '6px' }}>
                                        <button className="btn" onClick={() => handleApprove(job.id)} style={{ padding: '4px 8px', fontSize: '12px' }}>Onayla</button>
                                        <button className="btn" onClick={() => handleReject(job.id)} style={{ padding: '4px 8px', fontSize: '12px', background: '#d14343' }}>Reddet</button>
                                        <button className="btn" onClick={() => handleDeleteJob(job.id)} style={{ padding: '4px 8px', fontSize: '12px', background: '#8b1a1a', color: 'white' }}>Sil</button>
                                        <button className="btn" onClick={() => loadJobDetail(job.id)} style={{ padding: '4px 8px', fontSize: '12px', background: '#6c7a89' }}>Detay</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <div style={{ marginBottom: '16px', border: '1px solid #e8ebf0', borderRadius: '6px', padding: '12px' }}>
                <h4 style={{ margin: '0 0 10px' }}>Kuyruk İşleri</h4>

                <div style={{ display: 'flex', gap: '8px', marginBottom: '10px', flexWrap: 'wrap' }}>
                    <select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value, page: 1 })}>
                        <option value="">Tüm Durumlar</option>
                        <option value="queued">queued</option>
                        <option value="running">running</option>
                        <option value="waiting_approval">waiting_approval</option>
                        <option value="completed">completed</option>
                        <option value="failed">failed</option>
                        <option value="cancelled">cancelled</option>
                    </select>
                    <select value={filters.job_type} onChange={(e) => setFilters({ ...filters, job_type: e.target.value, page: 1 })}>
                        <option value="">Tüm Tipler</option>
                        <option value="stock_push">stock_push</option>
                        <option value="order_import">order_import</option>
                    </select>
                    <select value={filters.supplier_id} onChange={(e) => setFilters({ ...filters, supplier_id: e.target.value, page: 1 })}>
                        <option value="">Tüm Tedarikçiler</option>
                        {suppliers.map((supplier) => (
                            <option key={supplier.id} value={supplier.id}>{supplier.name}</option>
                        ))}
                    </select>
                    <button className="btn" onClick={() => loadJobs()} style={{ padding: '6px 10px' }}>Yenile</button>
                    <button className="btn" onClick={handleClearJobs} style={{ padding: '6px 10px', background: '#8b1a1a', color: 'white' }}>Tümünü Temizle</button>
                </div>

                {loadingJobs ? (
                    <div>Yükleniyor...</div>
                ) : (
                    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>ID</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Tedarikçi</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Tip</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Durum</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Kaynak</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Oluşturma</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            {jobs.length === 0 ? (
                                <tr><td colSpan="7" style={{ padding: '10px' }}>Kayıt bulunamadı.</td></tr>
                            ) : jobs.map((job) => (
                                <tr key={job.id}>
                                    <td style={{ padding: '6px' }}>#{job.id}</td>
                                    <td style={{ padding: '6px' }}>{job.supplier_name || supplierNameById[Number(job.supplier_id)] || '-'}</td>
                                    <td style={{ padding: '6px' }}>{job.job_type}</td>
                                    <td style={{ padding: '6px' }}>{job.status}</td>
                                    <td style={{ padding: '6px' }}>{job.source}</td>
                                    <td style={{ padding: '6px' }}>{formatDate(job.created_at)}</td>
                                    <td style={{ padding: '6px', display: 'flex', gap: '6px' }}>
                                        <button className="btn" style={{ padding: '4px 8px', fontSize: '12px' }} onClick={() => loadJobDetail(job.id)}>Detay</button>
                                        <button className="btn" style={{ padding: '4px 8px', fontSize: '12px', background: '#8b1a1a', color: 'white' }} onClick={() => handleDeleteJob(job.id)}>Sil</button>
                                        {job.status === 'waiting_approval' && (
                                            <>
                                                <button className="btn" style={{ padding: '4px 8px', fontSize: '12px' }} onClick={() => handleApprove(job.id)}>Onayla</button>
                                                <button className="btn" style={{ padding: '4px 8px', fontSize: '12px', background: '#d14343' }} onClick={() => handleReject(job.id)}>Reddet</button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <div style={{ marginTop: '10px', display: 'flex', gap: '8px', alignItems: 'center' }}>
                    <button
                        className="btn"
                        disabled={pagination.page <= 1}
                        onClick={() => setFilters({ ...filters, page: Math.max(1, pagination.page - 1) })}
                        style={{ padding: '4px 8px', fontSize: '12px' }}
                    >
                        Önceki
                    </button>
                    <span style={{ fontSize: '12px' }}>Sayfa {pagination.page} / {pagination.total_pages || 1}</span>
                    <button
                        className="btn"
                        disabled={pagination.page >= (pagination.total_pages || 1)}
                        onClick={() => setFilters({ ...filters, page: pagination.page + 1 })}
                        style={{ padding: '4px 8px', fontSize: '12px' }}
                    >
                        Sonraki
                    </button>
                </div>
            </div>

            <div style={{ marginBottom: '16px', border: '1px solid #e8ebf0', borderRadius: '6px', padding: '12px' }}>
                <h4 style={{ margin: '0 0 10px' }}>Değişim Geçmişi (Son 30 Gün)</h4>
                {loadingChanges ? (
                    <div>Yükleniyor...</div>
                ) : (
                    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Tarih</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Tedarikçi</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Tip</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Anahtar</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Değişim</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Önce</th>
                                <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Sonra</th>
                            </tr>
                        </thead>
                        <tbody>
                            {changes.length === 0 ? (
                                <tr><td colSpan="7" style={{ padding: '10px' }}>Değişim kaydı bulunamadı.</td></tr>
                            ) : changes.map((change) => (
                                <tr key={change.id}>
                                    <td style={{ padding: '6px' }}>{formatDate(change.created_at)}</td>
                                    <td style={{ padding: '6px' }}>{change.supplier_name || supplierNameById[Number(change.supplier_id)] || '-'}</td>
                                    <td style={{ padding: '6px' }}>{change.job_type}</td>
                                    <td style={{ padding: '6px' }}>{change.item_key}</td>
                                    <td style={{ padding: '6px' }}>{change.change_kind}</td>
                                    <td style={{ padding: '6px', maxWidth: '220px', overflow: 'hidden', textOverflow: 'ellipsis' }}>{pretty(change.before_value)}</td>
                                    <td style={{ padding: '6px', maxWidth: '220px', overflow: 'hidden', textOverflow: 'ellipsis' }}>{pretty(change.after_value)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                <p style={{ marginBottom: 0, marginTop: '8px', color: '#666', fontSize: '12px' }}>
                    Toplam kayıt: {changesPagination.total || 0}
                </p>
            </div>

            <div style={{ border: '1px solid #e8ebf0', borderRadius: '6px', padding: '12px' }}>
                <h4 style={{ margin: '0 0 10px' }}>Job Detayı</h4>
                {!selectedJob && <p style={{ margin: 0 }}>Detay görmek için bir job seçin.</p>}
                {selectedJob && (
                    <>
                        <div style={{ marginBottom: '8px' }}>
                            <strong>#{selectedJob.id}</strong> - {selectedJob.job_type} - {selectedJob.status}
                        </div>
                        <div style={{ marginBottom: '8px', fontSize: '12px', color: '#666' }}>
                            Tedarikçi: {selectedJob.supplier_name || supplierNameById[Number(selectedJob.supplier_id)] || '-'}
                            {' | '}Kaynak: {selectedJob.source}
                            {' | '}Oluşturma: {formatDate(selectedJob.created_at)}
                        </div>
                        {selectedJob.approval_reason && (
                            <p style={{ marginTop: 0, color: '#9a6700' }}>Onay Nedeni: {selectedJob.approval_reason}</p>
                        )}
                        {selectedJob.error_message && (
                            <p style={{ marginTop: 0, color: '#b42318' }}>Hata: {selectedJob.error_message}</p>
                        )}

                        {loadingDetail ? (
                            <div>Detay yükleniyor...</div>
                        ) : (
                            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                <thead>
                                    <tr>
                                        <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Anahtar</th>
                                        <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Durum</th>
                                        <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Before</th>
                                        <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>After</th>
                                        <th style={{ textAlign: 'left', borderBottom: '1px solid #ddd', padding: '6px' }}>Mesaj</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {selectedJobItems.length === 0 ? (
                                        <tr><td colSpan="5" style={{ padding: '8px' }}>Item kaydı yok.</td></tr>
                                    ) : selectedJobItems.map((item) => {
                                        const beforeText = item.item_type === 'order'
                                            ? `status=${item.before_status || '-'} meta=${pretty(item.before_meta)}`
                                            : `stok=${pretty(item.before_stock)} fiyat=${pretty(item.before_price)} indirim=${pretty(item.before_discount_price)}`;
                                        const afterText = item.item_type === 'order'
                                            ? `status=${item.after_status || '-'} meta=${pretty(item.after_meta)}`
                                            : `stok=${pretty(item.after_stock)} fiyat=${pretty(item.after_price)} indirim=${pretty(item.after_discount_price)}`;

                                        return (
                                            <tr key={item.id}>
                                                <td style={{ padding: '6px' }}>{item.item_key}</td>
                                                <td style={{ padding: '6px' }}>{item.status}</td>
                                                <td style={{ padding: '6px' }}>{beforeText}</td>
                                                <td style={{ padding: '6px' }}>{afterText}</td>
                                                <td style={{ padding: '6px' }}>{item.message || '-'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

export default SyncCenter;
