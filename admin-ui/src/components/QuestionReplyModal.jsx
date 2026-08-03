import React, { useEffect, useMemo, useState } from 'react';

function QuestionReplyModal({ item, onClose, onSubmit, submitting = false }) {
    const [answer, setAnswer] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        if (!item) {
            setAnswer('');
            setError('');
            return;
        }

        setAnswer(item.answer_text || '');
        setError('');
    }, [item]);

    const charCount = useMemo(() => {
        return answer.length;
    }, [answer]);

    if (!item) {
        return null;
    }

    const canSubmit = charCount >= 10 && charCount <= 2000 && !submitting;

    const handleSubmit = async () => {
        if (charCount < 10 || charCount > 2000) {
            setError('Yanıt 10 ile 2000 karakter arasında olmalıdır.');
            return;
        }

        setError('');
        await onSubmit(answer);
    };

    return (
        <div className="multi-sync-modal-overlay">
            <div className="multi-sync-modal-card">
                <h2 style={{ marginTop: 0 }}>Soru Yanıtla</h2>
                <div style={{ marginBottom: '12px', fontSize: '13px', color: '#4f6073' }}>
                    <strong>Soru ID:</strong> {item.external_question_id || '-'}
                </div>

                <div className="form-group">
                    <label>Soru Metni</label>
                    <textarea
                        value={item.question_text || ''}
                        readOnly
                        rows={4}
                        style={{ background: '#f8fbff', color: '#334155' }}
                    />
                </div>

                <div className="form-group">
                    <label>Yanıt</label>
                    <textarea
                        value={answer}
                        rows={5}
                        onChange={(e) => setAnswer(e.target.value)}
                        placeholder="Yanıtınızı yazın..."
                    />
                    <div style={{ marginTop: '6px', fontSize: '12px', color: '#5a6b82' }}>
                        {charCount} / 2000
                    </div>
                </div>

                {error && (
                    <div style={{
                        border: '1px solid #f3b6b6',
                        background: '#fff4f4',
                        color: '#9b1c1c',
                        borderRadius: '8px',
                        padding: '8px 10px',
                        marginBottom: '10px'
                    }}>
                        {error}
                    </div>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                    <button className="btn" type="button" onClick={onClose} style={{ background: '#6b7280' }}>
                        Kapat
                    </button>
                    <button className="btn" type="button" onClick={handleSubmit} disabled={!canSubmit}>
                        {submitting ? 'Gönderiliyor...' : 'Yanıtı Gönder'}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default QuestionReplyModal;

