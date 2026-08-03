import React, { useState, useEffect } from 'react';
import api from '../../api';

function Authorization({ supplier, onSupplierUpdate }) {
    const marketplaceKey = supplier?.marketplace_key || 'trendyol';
    const isN11 = marketplaceKey === 'n11';
    const isPazarama = marketplaceKey === 'pazarama';
    const isCiceksepeti = marketplaceKey === 'ciceksepeti';
    const isAmazon = marketplaceKey === 'amazon';
    const isPttAvm = marketplaceKey === 'pttavm';
    const isHepsiburada = marketplaceKey === 'hepsiburada';
    const showApiSecret = !isCiceksepeti;
    const showSellerId = !isN11 && !isPazarama && !isPttAvm;
    const [formData, setFormData] = useState({
        api_key: '',
        api_secret: '',
        seller_id: '',
        amazon_refresh_token: '',
        ptt_rest_api_key: '',
        ptt_access_token: ''
    });
    const [loading, setLoading] = useState(false);
    const [msg, setMsg] = useState('');

    useEffect(() => {
        if (!supplier) return;
        setFormData({
            api_key: supplier.api_key || '',
            api_secret: supplier.api_secret || '',
            seller_id: supplier.seller_id || '',
            amazon_refresh_token: supplier.amazon_refresh_token || '',
            ptt_rest_api_key: supplier.ptt_rest_api_key || '',
            ptt_access_token: supplier.ptt_access_token || '',
        });
    }, [supplier]);

    const handleSave = async () => {
        if (!supplier) return;
        setLoading(true);
        try {
            const payload = {
                api_key: formData.api_key,
                api_secret: isCiceksepeti ? '' : formData.api_secret,
                marketplace_key: marketplaceKey,
                amazon_refresh_token: isAmazon ? formData.amazon_refresh_token : '',
                ptt_rest_api_key: isPttAvm ? formData.ptt_rest_api_key : '',
                ptt_access_token: isPttAvm ? formData.ptt_access_token : '',
            };

            if (showSellerId) {
                payload.seller_id = formData.seller_id;
            } else {
                payload.seller_id = '';
            }

            const res = await api.updateSupplier(supplier.id, {
                ...payload,
            });
            if (res.data?.success) {
                setMsg(res.data.message || 'Kaydedildi!');

                if (typeof onSupplierUpdate === 'function') {
                    await onSupplierUpdate();
                }

                setTimeout(() => setMsg(''), 3500);
            } else {
                setMsg(res.data?.message || 'Kaydetme hatasi');
            }
        } catch (e) {
            console.error(e);
            setMsg(e.response?.data?.message || 'Kaydetme hatasi');
        }
        setLoading(false);
    };

    return (
        <div>
            <h2>Yetkilendirme</h2>
            <div className="form-group">
                <p style={{ marginTop: 0, color: '#666' }}>
                    {isN11
                        ? 'n11 entegrasyonu icin App Key ve App Secret bilgilerini girin.'
                        : isPazarama
                            ? 'Pazarama entegrasyonu icin Client ID ve Client Secret bilgilerini girin.'
                            : isCiceksepeti
                                ? 'Ciceksepeti entegrasyonu icin API Key girin. Supplier ID alani opsiyoneldir.'
                                : isAmazon
                                    ? 'Amazon TR entegrasyonu icin LWA Client ID, LWA Client Secret, Refresh Token ve Seller ID bilgilerini girin.'
                                    : isPttAvm
                                        ? 'PTTAVM SOAP entegrasyonu icin kullanici adi (API Key) ve sifre (API Secret) bilgilerini girin.'
                                    : isHepsiburada
                                        ? 'Hepsiburada katalog entegrasyonu icin Basic Auth kullanici adi, sifre ve Merchant ID bilgilerini girin.'
                        : 'Trendyol entegrasyonu icin API Key, API Secret ve Satici ID bilgilerini girin.'}
                </p>
            </div>

            <div className="grid grid-2">
                <div className="form-group">
                    <label>{isN11 ? 'App Key' : isPazarama ? 'Client ID' : isAmazon ? 'LWA Client ID' : isPttAvm || isHepsiburada ? 'Kullanici Adi' : 'API Key'}</label>
                    <input
                        value={formData.api_key}
                        onChange={e => setFormData({ ...formData, api_key: e.target.value })}
                        placeholder={
                            isN11
                                ? 'n11 App Key degeri'
                                : isPazarama
                                    ? 'Pazarama Client ID degeri'
                                    : isAmazon
                                        ? 'Amazon LWA Client ID degeri'
                                    : isPttAvm
                                        ? 'PTTAVM kullanici adi'
                                    : isCiceksepeti
                                        ? 'Ciceksepeti API Key degeri'
                                    : isHepsiburada
                                        ? 'Hepsiburada Basic Auth kullanici adi'
                                    : 'Trendyol API Key degeri'
                        }
                    />
                </div>
                {showApiSecret && (
                    <div className="form-group">
                        <label>{isN11 ? 'App Secret' : isPazarama ? 'Client Secret' : isAmazon ? 'LWA Client Secret' : isPttAvm || isHepsiburada ? 'Sifre' : 'API Secret'}</label>
                        <input
                            type="password"
                            value={formData.api_secret}
                            onChange={e => setFormData({ ...formData, api_secret: e.target.value })}
                            placeholder={
                                isN11
                                    ? 'n11 App Secret degeri'
                                    : isPazarama
                                        ? 'Pazarama Client Secret degeri'
                                        : isAmazon
                                            ? 'Amazon LWA Client Secret degeri'
                                        : isPttAvm
                                            ? 'PTTAVM sifre'
                                        : isHepsiburada
                                            ? 'Hepsiburada Basic Auth sifresi'
                                        : 'Trendyol API Secret degeri'
                            }
                        />
                    </div>
                )}
                {showSellerId && (
                    <div className="form-group">
                        <label>{isCiceksepeti ? 'Supplier ID (Opsiyonel)' : isAmazon ? 'Seller ID' : isHepsiburada ? 'Merchant ID' : 'Satici ID'}</label>
                        <input
                            value={formData.seller_id}
                            onChange={e => setFormData({ ...formData, seller_id: e.target.value })}
                            placeholder={isCiceksepeti ? 'Ornek: 123456 (opsiyonel)' : isAmazon ? 'Ornek: A1BC2DEFGHIJKL' : isHepsiburada ? 'Hepsiburada Magaza ID' : 'Ornek: 123456'}
                        />
                    </div>
                )}
                {isAmazon && (
                    <div className="form-group">
                        <label>Refresh Token</label>
                        <input
                            type="password"
                            value={formData.amazon_refresh_token}
                            onChange={e => setFormData({ ...formData, amazon_refresh_token: e.target.value })}
                            placeholder="Amazon LWA Refresh Token degeri"
                        />
                    </div>
                )}
                {isPttAvm && (
                    <>
                        <div className="form-group">
                            <label>REST API Key (Urun Gonderimi)</label>
                            <input type="password" value={formData.ptt_rest_api_key} onChange={e => setFormData({ ...formData, ptt_rest_api_key: e.target.value })} />
                        </div>
                        <div className="form-group">
                            <label>REST Access Token (Urun Gonderimi)</label>
                            <input type="password" value={formData.ptt_access_token} onChange={e => setFormData({ ...formData, ptt_access_token: e.target.value })} />
                        </div>
                    </>
                )}
            </div>

            <button className="btn" onClick={handleSave} disabled={loading}>
                {loading ? 'Kaydediliyor...' : 'Bilgileri Kaydet'}
            </button>
            {msg && <span style={{ marginLeft: '10px' }}>{msg}</span>}
        </div>
    );
}

export default Authorization;
