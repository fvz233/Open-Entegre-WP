import React, { useEffect, useState } from 'react';
import api from '../api';
import { getImportChoices, toggleImportChoice, selectConfiguration } from '../configurationSelection';

function RecordChoices({ rows, selected, onToggle, disabled }) {
    return <div style={{ maxHeight: 320, overflowY: 'auto', margin: '12px 0' }}>
        {rows.map(row => <label key={row.id} style={{ display: 'flex', alignItems: 'start', gap: 8, padding: '8px 0', borderBottom: '1px solid #eee' }}>
            <input type="checkbox" checked={selected.includes(row.id)} disabled={disabled || !row.supported} onChange={() => onToggle(row.id)} />
            <span>
                <strong>{row.name || row.marketplace_key}</strong> — {row.marketplace_key} · Kayıt #{row.source_id ?? row.id}
                <small style={{ display: 'block', color: '#50575e' }}>
                    {row.seller_id && `Satıcı: ${row.seller_id} · `}
                    {row.hepsiburada_test_seller_id && `Test satıcısı: ${row.hepsiburada_test_seller_id} · `}
                    {row.marketplace_key === 'hepsiburada' && `${row.hepsiburada_environment === 'test' ? 'Test' : 'Üretim'} · `}
                    {Number(row.active) === 1 ? 'Aktif' : 'Pasif'} · API bilgisi {row.has_credentials ? 'var' : 'yok'} · {row.mapping_count} eşleştirme
                    {!row.supported && ' · Desteklenmeyen eski/özel kayıt'}
                </small>
            </span>
        </label>)}
    </div>;
}

export default function ConfigurationBackup({ onImported }) {
    const [backup, setBackup] = useState(null);
    const [fileName, setFileName] = useState('');
    const [exportRows, setExportRows] = useState([]);
    const [exportSelected, setExportSelected] = useState([]);
    const [importRows, setImportRows] = useState([]);
    const [importSelected, setImportSelected] = useState([]);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [open, setOpen] = useState(false);

    const loadExportChoices = async () => {
        setBusy(true);
        setError('');
        try {
            const { data } = await api.getConfigurationSuppliers();
            const rows = data.map(row => ({ ...row, id: Number(row.id) }));
            setExportRows(rows);
            setExportSelected(rows.filter(row => row.selected).map(row => row.id));
        } catch (e) {
            setError(e.message || 'Pazar yeri kayıtları alınamadı.');
        } finally {
            setBusy(false);
        }
    };
    useEffect(() => { loadExportChoices(); }, []);

    const selectFile = async (event) => {
        const input = event.target;
        const file = input.files?.[0];
        setBackup(null);
        setImportRows([]);
        setImportSelected([]);
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
            const rows = getImportChoices(data.suppliers);
            setImportRows(rows);
            setImportSelected(rows.filter(row => row.selected).map(row => row.id));
            setBackup(data);
            setFileName(file.name);
        } catch (e) {
            setError(e instanceof SyntaxError ? 'Dosya geçerli bir JSON yedeği değil.' : e.message);
        } finally {
            setBusy(false);
            input.value = '';
        }
    };

    const exportBackup = async () => {
        setBusy(true);
        setMessage('');
        setError('');
        try {
            const { data } = await api.exportConfiguration(exportSelected);
            const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `open-entegre-ayarlar-${new Date().toISOString().slice(0, 10)}.json`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            setMessage('Seçilen kayıtların yedeği indirildi.');
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
            const { data } = await api.importConfiguration(selectConfiguration(backup, importSelected));
            setBackup(null);
            setImportRows([]);
            setImportSelected([]);
            setFileName('');
            setMessage(data.message);
            await onImported();
            await loadExportChoices();
        } catch (e) {
            setError(e.message || 'İçe aktarma başarısız.');
        } finally {
            setBusy(false);
        }
    };

    return <>
        <button type="button" className="button button-small" onClick={() => setOpen(true)}>İçe / Dışa Aktar</button>
        {open && <div className="multi-sync-modal-overlay" role="presentation" onMouseDown={event => event.target === event.currentTarget && setOpen(false)}>
            <div className="multi-sync-modal-card" role="dialog" aria-modal="true" aria-labelledby="configuration-backup-title">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
                    <h2 id="configuration-backup-title" style={{ margin: 0 }}>Ayarları İçe / Dışa Aktar</h2>
                    <button type="button" className="button" onClick={() => setOpen(false)}>Kapat</button>
                </div>
            <p>Seçtiğiniz kayıtların API bilgileri, kategori/marka eşleşmeleri (test ortamı dahil), kategori komisyonları ve senkron ayarları aktarılır. Genel ayarlar da dosyaya dahildir.</p>
            <p>Ürün KDV oranları custom meta alanındadır; ürünler, ürün metaları, siparişler ve işlem geçmişi bu yedeğe dahil değildir.</p>
            <p><strong>Dosya API anahtarlarını ve gizli bilgileri açık olarak içerir. Güvenli bir yerde saklayın.</strong></p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 20 }}>
                <fieldset style={{ minWidth: 0 }}>
                    <legend><strong>Dışa aktarılacak kayıtlar</strong></legend>
                    <p>Panelde kullanılan kayıtlar seçili gelir. Diğer desteklenen kayıtları da seçebilirsiniz.</p>
                    <RecordChoices rows={exportRows} selected={exportSelected} disabled={busy} onToggle={id => setExportSelected(values => values.includes(id) ? values.filter(value => value !== id) : [...values, id])} />
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button type="button" className="button" disabled={busy || !exportSelected.length} onClick={exportBackup}>Seçilenleri Dışa Aktar ({exportSelected.length})</button>
                        <button type="button" className="button" disabled={busy} onClick={loadExportChoices}>Kayıtları Yenile</button>
                    </div>
                </fieldset>
                <fieldset style={{ minWidth: 0 }}>
                    <legend><strong>İçe aktarılacak kayıtlar</strong></legend>
                    <label>JSON dosyası: <input type="file" accept=".json,application/json" disabled={busy} onChange={selectFile} style={{ maxWidth: '100%' }} /></label>
                    {backup && <>
                        <p><strong>{fileName}</strong> — {backup.suppliers.length} kayıt, {importSelected.length} seçili.</p>
                        <p>Her entegrasyon için bir kayıt seçin. Birden fazla kaydı olan entegrasyonlar otomatik seçilmez. Diğer kaydı işaretlediğinizde önceki seçim kaldırılır.</p>
                        <RecordChoices rows={importRows} selected={importSelected} disabled={busy} onToggle={id => setImportSelected(values => toggleImportChoice(values, id, importRows))} />
                        <p>Seçilen kayıtlar hedef sitedeki aynı entegrasyonun panelde kullanılan hesabını günceller; seçilmeyen entegrasyonlar korunur. Dosyadaki genel ayarlar da uygulanır.</p>
                        <p>Aynı kategori/marka eşleşmeleri güncellenir, diğerleri korunur. Kategori ve markalar hedef sitede aynı slug ile mevcut olmalıdır. Zamanlanmış senkronlar aktarılan ayarlara göre çalışır.</p>
                        <button type="button" className="button button-primary" disabled={busy || !importSelected.length} onClick={importBackup}>Seçilenleri İçe Aktar ({importSelected.length})</button>
                    </>}
                </fieldset>
            </div>
            {busy && <p role="status">İşlem sürüyor...</p>}
            {message && <p role="status" style={{ color: '#276738' }}>{message}</p>}
            {error && <p role="alert" style={{ color: '#b32d2e' }}>{error}</p>}
            </div>
        </div>}
    </>;
}
