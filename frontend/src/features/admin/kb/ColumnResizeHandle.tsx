import { useState } from 'react';
import type { CSSProperties } from 'react';

const MIN = 220;
const MAX = 560;
const DEFAULT = 300;
const KEY = 'kb-file-column-width';
const clamp = (value: number) => Math.max(MIN, Math.min(MAX, value));

export function useFileColumnWidth() {
    const [width, setWidth] = useState(() => {
        try {
            const saved = Number(localStorage.getItem(KEY));
            return saved > 0 ? clamp(saved) : DEFAULT;
        } catch { return DEFAULT; }
    });
    function update(value: number) {
        const next = clamp(value);
        setWidth(next);
        try { localStorage.setItem(KEY, String(next)); } catch { /* Storage is optional. */ }
    }
    return { width, update, style: { '--kb-file-column-width': `${width}px` } as CSSProperties };
}

export function ColumnResizeHandle({ width, onChange }: { width: number; onChange: (width: number) => void }) {
    const [drag, setDrag] = useState<{ x: number; width: number } | null>(null);
    return <div role="separator" aria-label="Resize document list" aria-orientation="vertical"
        aria-valuemin={MIN} aria-valuemax={MAX} aria-valuenow={width} tabIndex={0}
        className="kb-column-resize" data-dragging={drag !== null}
        onPointerDown={event => {
            if (event.button !== 0) return;
            event.currentTarget.setPointerCapture(event.pointerId);
            setDrag({ x: event.clientX, width });
            event.preventDefault();
        }}
        onPointerMove={event => { if (drag) onChange(drag.width + event.clientX - drag.x); }}
        onPointerUp={event => { setDrag(null); event.currentTarget.releasePointerCapture(event.pointerId); }}
        onLostPointerCapture={() => setDrag(null)}
        onPointerCancel={() => setDrag(null)}
        onDoubleClick={() => onChange(DEFAULT)}
        onKeyDown={event => {
            const next = event.key === 'ArrowLeft' ? width - 20 : event.key === 'ArrowRight' ? width + 20 : event.key === 'Home' ? MIN : event.key === 'End' ? MAX : null;
            if (next !== null) { event.preventDefault(); onChange(next); }
        }}><span /></div>;
}
