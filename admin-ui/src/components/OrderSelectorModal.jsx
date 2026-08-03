import React, { useState, useEffect } from 'react';
import api from '../api';

function OrderSelectorModal({ supplier, onClose, onSync }) {
    const [orders, setOrders] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selectedOrders, setSelectedOrders] = useState([]);

    useEffect(() => {
        loadOrders();
    }, [supplier]);

    const loadOrders = async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await api.previewOrders(supplier.id);
            if (res.data.success) {
                setOrders(res.data.items || []);
            } else {
                setError(res.data.message || 'Siparisler yuklenemedi');
            }
        } catch (e) {
            console.error('Siparis yukleme hatasi:', e);
            setError(e.response?.data?.message || e.message || 'Siparisler yuklenemedi');
        }
        setLoading(false);
    };

    const handleSelectAll = (checked) => {
        if (checked) {
            const allExternalIds = orders.map(o => o.external_id);
            setSelectedOrders(allExternalIds);
        } else {
            setSelectedOrders([]);
        }
    };

    const handleSelectOrder = (externalId, checked) => {
        if (checked) {
            setSelectedOrders([...selectedOrders, externalId]);
        } else {
            setSelectedOrders(selectedOrders.filter(id => id !== externalId));
        }
    };

    const handleSyncSelected = () => {
        onSync(selectedOrders);
        onClose();
    };

    const handleSyncAll = () => {
        onSync([]);
        onClose();
    };

    const newOrders = orders.filter(o => !o.already_imported);
    const existingOrders = orders.filter(o => o.already_imported);

    return (
        <div className="modal-overlay" style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            background: 'rgba(0,0,0,0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '3vh 2vw',
            overflowY: 'auto',
            zIndex: 99999
        }}>
            <div className="modal-content" style={{
                background: 'white',
                padding: '20px',
                borderRadius: '8px',
                width: 'min(1000px, 96vw)',
                height: '84vh',
                maxHeight: '84vh',
                overflow: 'auto'
            }}>
                <h2 style={{ marginTop: 0 }}>Ice Aktarilacak Siparisleri Sec</h2>

                {loading && <p>Siparisler API'den yukleniyor...</p>}

                {error && (
                    <div style={{
                        padding: '10px',
                        background: '#fee',
                        border: '1px solid #c00',
                        borderRadius: '4px',
                        marginBottom: '10px'
                    }}>
                        <strong>Hata:</strong> {error}
                    </div>
                )}

                {!loading && !error && orders.length === 0 && (
                    <p>API'den siparis bulunamadi. Lutfen Siparis GET baglantisini kontrol edin.</p>
                )}

                {!loading && orders.length > 0 && (
                    <>
                        <div style={{ marginBottom: '10px' }}>
                            <label>
                                <input
                                    type="checkbox"
                                    checked={selectedOrders.length === orders.length && orders.length > 0}
                                    onChange={e => handleSelectAll(e.target.checked)}
                                    disabled={orders.length === 0}
                                />
                                {' '}Tumunu Sec ({orders.length})
                            </label>
                        </div>

                        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: '15px' }}>
                            <thead>
                                <tr style={{ background: '#f5f5f5' }}>
                                    <th style={{ padding: '8px', textAlign: 'left', borderBottom: '1px solid #ddd' }}></th>
                                    <th style={{ padding: '8px', textAlign: 'left', borderBottom: '1px solid #ddd' }}>Harici ID</th>
                                    <th style={{ padding: '8px', textAlign: 'left', borderBottom: '1px solid #ddd' }}>Durum</th>
                                    <th style={{ padding: '8px', textAlign: 'left', borderBottom: '1px solid #ddd' }}>Musteri</th>
                                    <th style={{ padding: '8px', textAlign: 'right', borderBottom: '1px solid #ddd' }}>Toplam</th>
                                    <th style={{ padding: '8px', textAlign: 'center', borderBottom: '1px solid #ddd' }}>Kalem</th>
                                    <th style={{ padding: '8px', textAlign: 'center', borderBottom: '1px solid #ddd' }}>Aktarildi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.map((order, idx) => (
                                    <tr key={order.external_id || idx} style={{
                                        background: order.already_imported ? '#f9f9f9' : 'white',
                                        opacity: order.already_imported ? 0.6 : 1
                                    }}>
                                        <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>
                                            <input
                                                type="checkbox"
                                                checked={selectedOrders.includes(order.external_id)}
                                                onChange={e => handleSelectOrder(order.external_id, e.target.checked)}
                                            />
                                        </td>
                                        <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>
                                            {order.external_id || '-'}
                                        </td>
                                        <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>
                                            <span style={{
                                                padding: '2px 8px',
                                                borderRadius: '12px',
                                                fontSize: '0.85em',
                                                background: order.status === 'completed' ? '#d4edda' :
                                                    order.status === 'processing' ? '#fff3cd' : '#e2e3e5',
                                                color: order.status === 'completed' ? '#155724' :
                                                    order.status === 'processing' ? '#856404' : '#383d41'
                                            }}>
                                                {order.status}
                                            </span>
                                        </td>
                                        <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>
                                            {order.customer_name || order.customer_email || '-'}
                                        </td>
                                        <td style={{ padding: '8px', borderBottom: '1px solid #eee', textAlign: 'right' }}>
                                            {order.total ? `${order.currency || '$'} ${parseFloat(order.total).toFixed(2)}` : '-'}
                                        </td>
                                        <td style={{ padding: '8px', borderBottom: '1px solid #eee', textAlign: 'center' }}>
                                            {order.line_items_count || 0}
                                        </td>
                                        <td style={{ padding: '8px', borderBottom: '1px solid #eee', textAlign: 'center' }}>
                                            {order.already_imported ? 'Evet' : ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <div style={{
                            padding: '10px',
                            background: '#f8f9fa',
                            borderRadius: '4px',
                            marginBottom: '15px',
                            fontSize: '0.9em'
                        }}>
                            <strong>Ozet:</strong> {newOrders.length} yeni siparis, {existingOrders.length} daha once aktarilmis
                        </div>
                    </>
                )}

                <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                    <button
                        className="btn"
                        onClick={onClose}
                        style={{ background: '#e6e6e6', color: '#333' }}
                    >
                        Iptal
                    </button>
                    <button
                        className="btn"
                        onClick={handleSyncAll}
                        disabled={loading || orders.length === 0}
                        style={{ background: '#6c757d', color: 'white' }}
                    >
                        Tumunu Senkron Et ({orders.length})
                    </button>
                    <button
                        className="btn"
                        onClick={handleSyncSelected}
                        disabled={loading || selectedOrders.length === 0}
                    >
                        Secilenleri Ice Aktar/Guncelle ({selectedOrders.length})
                    </button>
                </div>
            </div>
        </div>
    );
}

export default OrderSelectorModal;
