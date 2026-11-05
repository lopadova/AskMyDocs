import { useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { useUiTranslation } from '../../lib/ui-locale';
import './resizable-chat-history.css';

const MIN = 240;
const MAX = 480;
const DEFAULT = 280;
const MIN_THREAD_WIDTH = 480;
const STORAGE_KEY = 'chat-history-width';
const clamp = (width: number, maximum = MAX) => Math.round(Math.max(MIN, Math.min(maximum, width)));

function savedWidth(): number {
    try {
        const saved = Number(localStorage.getItem(STORAGE_KEY));
        return Number.isFinite(saved) && saved > 0 ? clamp(saved) : DEFAULT;
    } catch { return DEFAULT; }
}

/** Desktop rail with bounded, persisted resizing; the mobile drawer stays independent. */
export function ResizableChatHistory({ children }: { children: ReactNode }) {
    const { t } = useUiTranslation();
    const panelId = useId();
    const root = useRef<HTMLDivElement>(null);
    const [preferredWidth, setPreferredWidth] = useState(savedWidth);
    const latestWidth = useRef(preferredWidth);
    const [maximum, setMaximum] = useState(MAX);
    const drag = useRef<{ pointerId: number; x: number; width: number; preferred: number } | null>(null);
    const [resizing, setResizing] = useState(false);
    const width = clamp(preferredWidth, maximum);

    useLayoutEffect(() => {
        const parent = root.current?.parentElement;
        if (!parent) return;
        const measure = () => {
            const available = parent.getBoundingClientRect().width;
            if (available > 0) setMaximum(clamp(available - MIN_THREAD_WIDTH));
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(parent);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!resizing) return;
        document.documentElement.classList.add('chat-history-is-resizing');
        return () => document.documentElement.classList.remove('chat-history-is-resizing');
    }, [resizing]);

    function update(next: number, persist = false) {
        const bounded = clamp(next, maximum);
        latestWidth.current = bounded;
        setPreferredWidth(bounded);
        if (persist) {
            try { localStorage.setItem(STORAGE_KEY, String(bounded)); } catch { /* Optional browser preference. */ }
        }
    }

    function finish() {
        if (!drag.current) return;
        drag.current = null;
        setResizing(false);
        update(latestWidth.current, true);
    }

    return <div ref={root} className="chat-history-desktop chat-history-resizable" data-resizing={resizing}
        style={{ width, flexBasis: width }}>
        <div id={panelId} className="chat-history-panel">{children}</div>
        <div role="separator" aria-label={t('Resize chat list')} aria-orientation="vertical" aria-controls={panelId}
            aria-valuemin={MIN} aria-valuemax={maximum} aria-valuenow={width} aria-valuetext={`${width} px`}
            title={t('Drag to resize. Use arrow keys, Home or End; double-click to reset.')}
            tabIndex={0} className="chat-history-resize-handle"
            onPointerDown={event => {
                if (event.button !== 0 || drag.current) return;
                event.preventDefault();
                event.currentTarget.focus();
                event.currentTarget.setPointerCapture(event.pointerId);
                drag.current = { pointerId: event.pointerId, x: event.clientX, width, preferred: preferredWidth };
                latestWidth.current = width;
                setResizing(true);
            }}
            onPointerMove={event => {
                const current = drag.current;
                if (current && current.pointerId === event.pointerId) update(current.width + event.clientX - current.x);
            }}
            onPointerUp={event => {
                if (drag.current?.pointerId !== event.pointerId) return;
                finish();
                if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
            }}
            onLostPointerCapture={finish}
            onPointerCancel={() => {
                const current = drag.current;
                if (!current) return;
                drag.current = null;
                latestWidth.current = current.preferred;
                setPreferredWidth(current.preferred);
                setResizing(false);
            }}
            onDoubleClick={() => update(DEFAULT, true)}
            onKeyDown={event => {
                const step = event.shiftKey ? 48 : 16;
                const next = event.key === 'ArrowLeft' ? width - step : event.key === 'ArrowRight' ? width + step
                    : event.key === 'Home' ? MIN : event.key === 'End' ? maximum : null;
                if (next !== null) { event.preventDefault(); update(next, true); }
            }}
        ><span aria-hidden="true" /></div>
    </div>;
}
