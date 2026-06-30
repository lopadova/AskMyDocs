import { useEffect, useState, type ReactNode } from 'react';
import type { ApiRoute, TestRouteResponse, ToolDefinition } from './api-connectors.api';
import {
    buttonStyle,
    fieldCaptionStyle,
    fieldLabelStyle,
    inputStyle,
    modalBackdropStyle,
    modalPanelStyle,
} from './styles';

/**
 * "Test connessione" modal: run a live test against the route's target, then
 * render the HTTP status, a response-body preview, the inferred input/output
 * schema, and the generated tool definition with editable name/description.
 *
 * The test result is owned by the parent (passed in via `result`); this panel
 * only triggers the run (`onTest`) and the save of the edited tool definition
 * (`onSaveDefinition`). A failed test (HTTP 200 with `ok:false`) renders the
 * error loudly (R14).
 *
 * R11/R29: testids `api-route-test-*`. R15: bound labels, role=dialog, Esc closes.
 */

export interface TestConnectionPanelProps {
    route: ApiRoute;
    /** Latest test outcome, or null if the panel was just opened. */
    result: TestRouteResponse | null;
    onTest: (exampleArgs: Record<string, unknown>) => void;
    onSaveDefinition: (definition: ToolDefinition) => void;
    onClose: () => void;
    isTesting?: boolean;
    isSavingDefinition?: boolean;
    /** Surface a test/save failure loudly (R14). */
    error?: string | null;
}

function prettyJson(value: unknown): string {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

export function TestConnectionPanel({
    route,
    result,
    onTest,
    onSaveDefinition,
    onClose,
    isTesting,
    isSavingDefinition,
    error,
}: TestConnectionPanelProps): ReactNode {
    const [exampleArgsText, setExampleArgsText] = useState('{}');
    const [argsError, setArgsError] = useState<string | null>(null);
    const [toolName, setToolName] = useState(result?.tool_definition?.name ?? route.tool_definition?.name ?? '');
    const [toolDescription, setToolDescription] = useState(
        result?.tool_definition?.description ?? route.tool_definition?.description ?? '',
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    // R17 — when a fresh test result arrives with its own tool definition, sync
    // the editable fields so the operator edits the just-generated values.
    useEffect(() => {
        if (result?.tool_definition) {
            setToolName(result.tool_definition.name);
            setToolDescription(result.tool_definition.description);
        }
    }, [result]);

    function handleTest() {
        setArgsError(null);
        let parsed: Record<string, unknown> = {};
        const raw = exampleArgsText.trim();
        if (raw !== '') {
            try {
                const obj = JSON.parse(raw);
                if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
                    throw new Error('Example args must be a JSON object.');
                }
                parsed = obj as Record<string, unknown>;
            } catch (e) {
                setArgsError(e instanceof Error ? e.message : 'Invalid JSON.');
                return;
            }
        }
        onTest(parsed);
    }

    const test = result?.test ?? null;
    const inputSchema = result?.input_schema ?? route.input_schema;
    const outputSchema = result?.output_schema ?? route.output_schema;
    const titleId = 'api-route-test-title';

    const state: 'idle' | 'loading' | 'ready' | 'error' = isTesting
        ? 'loading'
        : test === null
          ? 'idle'
          : test.ok
            ? 'ready'
            : 'error';

    return (
        <div
            data-testid="api-route-test-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={modalBackdropStyle()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isTesting}
                data-testid="api-route-test-panel"
                data-state={state}
                style={modalPanelStyle(640)}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Test connection: {route.name}
                </h2>
                <p style={{ margin: 0, ...fieldCaptionStyle() }}>
                    {route.http_method} {route.url}
                </p>

                <label htmlFor="api-route-test-example-args" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Example arguments (JSON object)</span>
                    <textarea
                        id="api-route-test-example-args"
                        data-testid="api-route-test-example-args"
                        rows={3}
                        value={exampleArgsText}
                        onChange={(e) => setExampleArgsText(e.target.value)}
                        style={{ ...inputStyle(), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                    />
                    {argsError && (
                        <span data-testid="api-route-test-example-args-error" role="alert" style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}>
                            {argsError}
                        </span>
                    )}
                </label>

                <div>
                    <button
                        type="button"
                        data-testid="api-route-test-run"
                        onClick={handleTest}
                        disabled={isTesting}
                        style={buttonStyle('primary', !!isTesting)}
                    >
                        {isTesting ? 'Testing…' : 'Test connessione'}
                    </button>
                </div>

                {error && (
                    <p data-testid="api-route-test-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                        {error}
                    </p>
                )}

                {test && (
                    <div
                        data-testid="api-route-test-result"
                        data-ok={test.ok ? 'true' : 'false'}
                        style={{
                            border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                            borderRadius: 8,
                            padding: 10,
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 8,
                        }}
                    >
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                            <span
                                data-testid="api-route-test-status"
                                role="status"
                                style={{
                                    fontSize: 11,
                                    fontWeight: 600,
                                    padding: '2px 8px',
                                    borderRadius: 999,
                                    background: test.ok ? 'rgba(16,185,129,0.16)' : 'rgba(239,68,68,0.16)',
                                    border: '1px solid ' + (test.ok ? 'rgba(16,185,129,0.45)' : 'rgba(239,68,68,0.45)'),
                                    color: test.ok ? '#34d399' : '#fca5a5',
                                }}
                            >
                                {test.ok ? 'OK' : 'Failed'}
                                {test.status != null && ` — HTTP ${test.status}`}
                                {test.status_label && ` ${test.status_label}`}
                            </span>
                        </div>

                        {test.error && (
                            <p data-testid="api-route-test-result-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                                {test.error}
                            </p>
                        )}

                        <div style={fieldLabelStyle()}>
                            <span style={fieldCaptionStyle()}>Response body preview</span>
                            <pre
                                data-testid="api-route-test-body"
                                style={{
                                    margin: 0,
                                    maxHeight: 180,
                                    overflow: 'auto',
                                    fontSize: 11,
                                    fontFamily: 'var(--font-mono, monospace)',
                                    background: 'var(--bg-3, rgba(255,255,255,.04))',
                                    borderRadius: 6,
                                    padding: 8,
                                    color: 'var(--fg-1)',
                                }}
                            >
                                {prettyJson(test.body)}
                            </pre>
                        </div>
                    </div>
                )}

                {(inputSchema || outputSchema) && (
                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                        <div style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                            <span style={fieldCaptionStyle()}>Inferred input schema</span>
                            <pre
                                data-testid="api-route-test-input-schema"
                                style={{
                                    margin: 0,
                                    maxHeight: 160,
                                    overflow: 'auto',
                                    fontSize: 11,
                                    fontFamily: 'var(--font-mono, monospace)',
                                    background: 'var(--bg-3, rgba(255,255,255,.04))',
                                    borderRadius: 6,
                                    padding: 8,
                                    color: 'var(--fg-1)',
                                }}
                            >
                                {prettyJson(inputSchema ?? {})}
                            </pre>
                        </div>
                        <div style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                            <span style={fieldCaptionStyle()}>Inferred output schema</span>
                            <pre
                                data-testid="api-route-test-output-schema"
                                style={{
                                    margin: 0,
                                    maxHeight: 160,
                                    overflow: 'auto',
                                    fontSize: 11,
                                    fontFamily: 'var(--font-mono, monospace)',
                                    background: 'var(--bg-3, rgba(255,255,255,.04))',
                                    borderRadius: 6,
                                    padding: 8,
                                    color: 'var(--fg-1)',
                                }}
                            >
                                {prettyJson(outputSchema ?? {})}
                            </pre>
                        </div>
                    </div>
                )}

                {/* Editable tool definition */}
                <fieldset
                    data-testid="api-route-test-tool-definition"
                    style={{
                        border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                        borderRadius: 8,
                        padding: 10,
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 8,
                    }}
                >
                    <legend style={{ ...fieldCaptionStyle(), padding: '0 4px' }}>Tool definition</legend>
                    <label htmlFor="api-route-test-tool-name" style={fieldLabelStyle()}>
                        <span style={fieldCaptionStyle()}>Tool name</span>
                        <input
                            id="api-route-test-tool-name"
                            data-testid="api-route-test-tool-name"
                            type="text"
                            value={toolName}
                            onChange={(e) => setToolName(e.target.value)}
                            style={{ ...inputStyle(), fontFamily: 'var(--font-mono, monospace)' }}
                        />
                    </label>
                    <label htmlFor="api-route-test-tool-description" style={fieldLabelStyle()}>
                        <span style={fieldCaptionStyle()}>Tool description</span>
                        <textarea
                            id="api-route-test-tool-description"
                            data-testid="api-route-test-tool-description"
                            rows={2}
                            value={toolDescription}
                            onChange={(e) => setToolDescription(e.target.value)}
                            style={{ ...inputStyle(), resize: 'vertical' }}
                        />
                    </label>
                    <div>
                        <button
                            type="button"
                            data-testid="api-route-test-tool-save"
                            onClick={() =>
                                onSaveDefinition({
                                    name: toolName.trim(),
                                    description: toolDescription.trim(),
                                    input_schema:
                                        result?.tool_definition?.input_schema ??
                                        route.tool_definition?.input_schema ??
                                        (inputSchema as Record<string, unknown>) ??
                                        {},
                                })
                            }
                            disabled={isSavingDefinition}
                            style={buttonStyle('primary', !!isSavingDefinition)}
                        >
                            {isSavingDefinition ? 'Saving…' : 'Save tool definition'}
                        </button>
                    </div>
                </fieldset>

                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="api-route-test-close"
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
