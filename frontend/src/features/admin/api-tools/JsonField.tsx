import { useEffect, useId, useState } from 'react';
export function JsonField({ label, value, onChange, objectOnly = false }: { label: string; value: unknown; onChange: (value: unknown) => void; objectOnly?: boolean }) {
    const id = useId();
    const serialized = JSON.stringify(value, null, 2);
    const [text, setText] = useState(serialized);
    const [error, setError] = useState('');
    useEffect(() => { setText(serialized); setError(''); }, [serialized]);
    return <label htmlFor={id}>{label}<textarea id={id} rows={6} spellCheck={false} className="api-tool-json" value={text} onChange={(event) => {
        setText(event.target.value);
        try {
            const parsed: unknown = JSON.parse(event.target.value);
            if (objectOnly && (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed))) throw new Error('È richiesto un oggetto JSON.');
            event.target.setCustomValidity(''); setError(''); onChange(parsed);
        } catch (reason) { const message = reason instanceof Error ? reason.message : 'JSON non valido'; event.target.setCustomValidity(message); setError(message); }
    }} />{error && <small role="alert">JSON non valido: {error}</small>}</label>;
}
