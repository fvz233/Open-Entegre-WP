import React, { useState } from 'react';
import api from '../api';

export default function ConfigurationBackup({ onImported }) {
    const [backup, setBackup] = useState(null);
    const [fileName, setFileName] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    const selectFile = async (event) => {
        const file = event.target.files?.[0];
        setBackup(null);
        setFileName('');
        setMessage('');
        setError('');
        if (!file) return;
        setBusy(true);
        try {
            if (file.size > 10 * 1024 * 1024) throw new Error('Yedek dosyası en fazla 10 MB olabilir.');
            const data = JSON.parse(await file.text());
            if (data?.format !== 'open-entegre-settings' || data.version !== 1 || !Array.isArray(data.suppliers) || !data.suppliers.length) {
                throw new Error('Geçerli bir Open Entegre ayar yedeği seçin.');
            }
            setBackup(data);
            setFileName(file.name);
        } catch (e) {
            setError(e instanceof SyntaxError ? 'Dosya geçerli bir JSON yedeği değil.' : e.message);
        } finally {
            setBusy(false);
            event.target.value = '';
        }
    };

    const exportBackup = async () => {
        setBusy(true);
        setMessage('');
        setError('');
        try {
            const { data } = await api.exportConfiguration();
            const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `open-entegre-ayarlar-${new Date().toISOString().slice(0, 10)}.json`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            setMessage('Yedek dosyası indirildi.');
        } catch (e) {
            setError(e.message || 'Dışa aktarma başarısız.');
        } finally {
            setBusy(false);
        }
    };

    const importBackup = async () => {
        setBusy(true);
        setMessage('');
        setError('');
        try {
            const { data } = await api.importConfiguration(backup);
            setBackup(null);
            setFileName('');
            setMessage(data.message);
            await onImported();
        } catch (e) {
            setError(e.message || 'İçe aktarma başarısız.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <details style={{ marginBottom: 16, padding: 16, background: '#fff', border: '1px solid #dcdcde', borderRadius: 8 }}>
            <summary style={{ cursor: 'pointer', fontWeight: 600 }}>Ayarları İçe / Dışa Aktar</summary>
            <p>Tüm pazar yerlerinin API bilgileri, kategori/marka eşleşmeleri (test ortamı dahil), kategori komisyonları, senkron ve genel ayarları JSON olarak aktarılır.</p>
            <p>Ürün KDV oranları custom meta alanındadır; ürünler, ürün metaları, siparişler ve işlem geçmişi bu yedeğe dahil değildir.</p>
            <p><strong>Dosya API anahtarlarını ve gizli bilgileri açık olarak içerir. Güvenli bir yerde saklayın.</strong></p>
            <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
                <button type="button" className="button" disabled={busy} onClick={exportBackup}>JSON Dışa Aktar</button>
                <label>İçe aktarılacak JSON dosyası: <input type="file" accept=".json,application/json" disabled={busy} onChange={selectFile} /></label>
            </div>
            {backup && <div style={{ marginTop: 16 }}>
                <p><strong>{fileName}</strong> — {backup.suppliers.length} pazar yeri.</p>
                <p>İçe aktarınca dosyadaki ayarlar ve aynı kategori/marka eşleşmeleri mevcut değerlerin üzerine yazılır; diğer eşleşmeler korunur. Zamanlanmış senkronlar aktarılan ayarlara göre çalışır. Kategori ve markalar hedef sitede aynı slug ile mevcut olmalıdır.</p>
                <button type="button" className="button button-primary" disabled={busy} onClick={importBackup}>Ayarları İçe Aktar</button>
            </div>}
            {busy && <p role="status">İşlem sürüyor...</p>}
            {message && <p role="status" style={{ color: '#276738' }}>{message}</p>}
            {error && <p role="alert" style={{ color: '#b32d2e' }}>{error}</p>}
        </details>
    );
}
