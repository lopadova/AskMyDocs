import { useEffect, useState, type ReactNode } from 'react';
import type { ApiRouteRelation, DrillResult } from './api-connectors.api';
import { prettyJson } from './pretty-json';
import {
    buttonStyle,
    fieldCaptionStyle,
    fieldLabelStyle,
    inputStyle,
    modalBackdropStyle,
    modalPanelStyle,
} from './styles';

/**
 * Drill-test a relation: pick a LIST item (by index into the list route's last
 * test payload, or paste an explicit item) and run it through the field map into
 * the DETAIL route. Renders the mapped arguments + the raw detail response.
 *
 * Presentational — the parent owns the mutation and passes `result`/`error`.
 * R11/R29 testids `api-route-drill-*`. R15: bound labels, role=dialog, Esc closes.
 * R14: a failed drill (missing field → 422, or a failed detail call) surfaces.
 */

export interface DrillTestPanelProps {
    relation: ApiRouteRelation;
    result: DrillResult | null;
    onDrill: (payload: { list_item?: Record<string, unknown>; item_index?: number }) => void;
    onClose: () => void;
    isDrilling?: boolean;
    error?: string | null;
}

export function DrillTestPanel({
    relation,
    result,
    onDrill,
    onClose,
    isDrilling,
    error,
}: DrillTestPanelProps): ReactNode {
    const [itemIndex, setItemIndex] = useState('0');
    const [itemJson, setItemJson] = useState('');
    const [jsonError, setJsonError] = useState<string | null>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    function runByIndex() {
        setJsonError(null);
        const n = Number.parseInt(itemIndex.trim(), 10);
        onDrill({ item_index: Number.isNaN(n) ? 0 : n });
    }

    function runByJson() {
        setJsonError(null);
        const raw = itemJson.trim();
        try {
            const obj = JSON.parse(raw);
            if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
                throw new Error('The list item must be a JSON object.');
            }
            onDrill({ list_item: obj as Record<string, unknown> });
        } catch (e) {
            setJsonError(e instanceof Error ? e.message : 'Invalid JSON.');
        }
    }

    const detail = result?.result ?? null;
    const state: 'idle' | 'loading' | 'ready' | 'error' = isDrilling
        ? 'loading'
        : error
          ? 'error'
          : detail
            ? detail.ok
                ? 'ready'
                : 'error'
            : 'idle';

    const titleId = 'api-route-drill-title';

    return (
        <div
            data-testid="api-route-drill-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={modalBackdropStyle()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isDrilling}
                data-testid="api-route-drill-panel"
                data-state={state}
                style={modalPanelStyle(600)}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Drill-test relation
                </h2>
                <p style={{ margin: 0, ...fieldCaptionStyle() }}>
                    {relation.list_route?.slug ?? `#${relation.list_route_id}`} →{' '}
                    {relation.detail_route?.slug ?? `#${relation.detail_route_id}`}
                </p>

                <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                    <label htmlFor="api-route-drill-item-index" style={{ ...fieldLabelStyle(), width: 200 }}>
                        <span style={fieldCaptionStyle()}>Item index (from last list test)</span>
                        <input
                            id="api-route-drill-item-index"
                            data-testid="api-route-drill-item-index"
                            type="number"
                            min={0}
                            value={itemIndex}
                            onChange={(e) => setItemIndex(e.target.value)}
                            style={inputStyle()}
                        />
                    </label>
                    <button
                        type="button"
                        data-testid="api-route-drill-run"
                        onClick={runByIndex}
                        disabled={isDrilling}
                        style={buttonStyle('primary', !!isDrilling)}
                    >
                        {isDrilling ? 'Drilling…' : 'Drill this item'}
                    </button>
                </div>

                <details>
                    <summary style={{ ...fieldCaptionStyle(), cursor: 'pointer' }}>
                        …or paste an explicit list item
                    </summary>
                    <label htmlFor="api-route-drill-item-json" style={{ ...fieldLabelStyle(), marginTop: 6 }}>
                        <span style={fieldCaptionStyle()}>List item (JSON object)</span>
                        <textarea
                            id="api-route-drill-item-json"
                            data-testid="api-route-drill-item-json"
                            rows={3}
                            value={itemJson}
                            onChange={(e) => setItemJson(e.target.value)}
                            placeholder='{"id": 1, "name": "Ada"}'
                            style={{ ...inputStyle(), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                        />
                        {jsonError && (
                            <span data-testid="api-route-drill-item-json-error" role="alert" style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}>
                                {jsonError}
                            </span>
                        )}
                    </label>
                    <button
                        type="button"
                        data-testid="api-route-drill-run-json"
                        onClick={runByJson}
                        disabled={isDrilling || itemJson.trim() === ''}
                        style={buttonStyle('secondary', isDrilling || itemJson.trim() === '')}
                    >
                        Drill with this item
                    </button>
                </details>

                {error && (
                    <p data-testid="api-route-drill-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                        {error}
                    </p>
                )}

                {result && (
                    <>
                        <div style={fieldLabelStyle()}>
                            <span style={fieldCaptionStyle()}>Mapped detail arguments</span>
                            <pre
                                data-testid="api-route-drill-args"
                                style={preStyle()}
                            >
                                {prettyJson(result.arguments)}
                            </pre>
                        </div>
                        {detail && (
                            <div
                                data-testid="api-route-drill-result"
                                data-ok={detail.ok ? 'true' : 'false'}
                                style={{ display: 'flex', flexDirection: 'column', gap: 6 }}
                            >
                                <span
                                    data-testid="api-route-drill-status"
                                    role="status"
                                    style={{
                                        alignSelf: 'flex-start',
                                        fontSize: 11,
                                        fontWeight: 600,
                                        padding: '2px 8px',
                                        borderRadius: 999,
                                        background: detail.ok ? 'rgba(16,185,129,0.16)' : 'rgba(239,68,68,0.16)',
                                        border: '1px solid ' + (detail.ok ? 'rgba(16,185,129,0.45)' : 'rgba(239,68,68,0.45)'),
                                        color: detail.ok ? '#34d399' : '#fca5a5',
                                    }}
                                >
                                    {detail.ok ? 'OK' : 'Failed'}
                                    {detail.status != null && ` — HTTP ${detail.status}`}
                                </span>
                                {detail.error && (
                                    <p data-testid="api-route-drill-result-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                                        {detail.error}
                                    </p>
                                )}
                                <pre data-testid="api-route-drill-body" style={preStyle()}>
                                    {prettyJson(detail.body)}
                                </pre>
                            </div>
                        )}
                    </>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="api-route-drill-close"
                        onClick={onClose}
                        style={buttonStyle('secondary', false)}
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}

function preStyle() {
    return {
        margin: 0,
        maxHeight: 180,
        overflow: 'auto',
        fontSize: 11,
        fontFamily: 'var(--font-mono, monospace)',
        background: 'var(--bg-3, rgba(255,255,255,.04))',
        borderRadius: 6,
        padding: 8,
        color: 'var(--fg-1)',
    } as const;
}
