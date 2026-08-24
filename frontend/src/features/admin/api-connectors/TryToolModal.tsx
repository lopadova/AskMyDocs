import { useEffect, useState, type ReactNode } from 'react';
import type { ApiRoute } from './api-connectors.api';
import {
    buttonStyle,
    fieldCaptionStyle,
    fieldLabelStyle,
    inputStyle,
    modalBackdropStyle,
    modalPanelStyle,
} from './styles';
import { prettyJson } from './pretty-json';

/**
 * "Prova tool" modal: enter the tool arguments (JSON object), run the route
 * end-to-end via `/try`, and render the executor result. Distinct from the test
 * panel — `/try` runs the full tool executor (auth + transform + output), not a
 * raw probe.
 *
 * R11/R29: testids `api-tool-try-*`. R15: bound label, role=dialog, Esc closes.
 * R14: a failure surfaces loudly via `error`.
 */

export interface TryToolModalProps {
    route: ApiRoute;
    /** Latest executor result, or null if not yet run. */
    result: unknown;
    hasResult: boolean;
    onRun: (args: Record<string, unknown>) => void;
    onClose: () => void;
    isRunning?: boolean;
    error?: string | null;
}

export function TryToolModal({
    route,
    result,
    hasResult,
    onRun,
    onClose,
    isRunning,
    error,
}: TryToolModalProps): ReactNode {
    const [argsText, setArgsText] = useState('{}');
    const [argsError, setArgsError] = useState<string | null>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    function handleRun() {
        setArgsError(null);
        let parsed: Record<string, unknown> = {};
        const raw = argsText.trim();
        if (raw !== '') {
            try {
                const obj = JSON.parse(raw);
                if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
                    throw new Error('Arguments must be a JSON object.');
                }
                parsed = obj as Record<string, unknown>;
            } catch (e) {
                setArgsError(e instanceof Error ? e.message : 'Invalid JSON.');
                return;
            }
        }
        onRun(parsed);
    }

    const titleId = 'api-tool-try-title';
    const state: 'idle' | 'loading' | 'ready' | 'error' = isRunning
        ? 'loading'
        : error
          ? 'error'
          : hasResult
            ? 'ready'
            : 'idle';

    return (
        <div
            data-testid="api-tool-try-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={modalBackdropStyle()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isRunning}
                data-testid="api-tool-try-panel"
                data-state={state}
                style={modalPanelStyle(560)}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Try tool: {route.tool_definition?.name ?? route.name}
                </h2>

                <label htmlFor="api-tool-try-arguments" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Arguments (JSON object)</span>
                    <textarea
                        id="api-tool-try-arguments"
                        data-testid="api-tool-try-arguments"
                        rows={4}
                        value={argsText}
                        onChange={(e) => setArgsText(e.target.value)}
                        style={{ ...inputStyle(), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                    />
                    {argsError && (
                        <span data-testid="api-tool-try-arguments-error" role="alert" style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}>
                            {argsError}
                        </span>
                    )}
                </label>

                <div>
                    <button
                        type="button"
                        data-testid="api-tool-try-run"
                        onClick={handleRun}
                        disabled={isRunning}
                        style={buttonStyle('primary', !!isRunning)}
                    >
                        {isRunning ? 'Running…' : 'Run tool'}
                    </button>
                </div>

                {error && (
                    <p data-testid="api-tool-try-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                        {error}
                    </p>
                )}

                {hasResult && (
                    <div style={fieldLabelStyle()}>
                        <span style={fieldCaptionStyle()}>Result</span>
                        <pre
                            data-testid="api-tool-try-result"
                            style={{
                                margin: 0,
                                maxHeight: 280,
                                overflow: 'auto',
                                fontSize: 11,
                                fontFamily: 'var(--font-mono, monospace)',
                                background: 'var(--bg-3, rgba(255,255,255,.04))',
                                borderRadius: 6,
                                padding: 8,
                                color: 'var(--fg-1)',
                            }}
                        >
                            {prettyJson(result)}
                        </pre>
                    </div>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="api-tool-try-close"
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
