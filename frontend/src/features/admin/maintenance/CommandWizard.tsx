import { useMemo, useState } from 'react';
import {
    usePreviewCommand,
    useRunCommand,
    useCommandHistory,
    type CatalogueEntry,
    type HistoryRow,
} from './maintenance.api';

/*
 * Phase H2 — Command Wizard.
 *
 * Three steps:
 *   1. Preview : renders a form derived from args_schema, POSTs
 *      /preview. For destructive commands the response carries a
 *      confirm_token that we stash for Step 3.
 *   2. Confirm (destructive only): the operator must type the exact
 *      command name to prove intent — classic "type-to-confirm" UX.
 *   3. Run: POSTs /run with the stashed confirm_token, polls
 *      /history filtered to the returned audit_id until status !==
 *      'started'. Shows stdout_head inline on success or the 4xx/5xx
 *      error message.
 *
 * The wizard itself holds zero permanent state — leaving the modal
 * or clicking Cancel drops the confirm_token server-side only
 * (expires in 5min; the unused row gets pruned by the daily
 * admin-nonces:prune scheduler).
 */

export interface CommandWizardProps {
    command: string;
    spec: CatalogueEntry;
    onClose: () => void;
}

type Step = 'preview' | 'confirm' | 'run' | 'result';

export function CommandWizard({ command, spec, onClose }: CommandWizardProps) {
    const [step, setStep] = useState<Step>('preview');
    const [args, setArgs] = useState<Record<string, unknown>>({});
    const [confirmToken, setConfirmToken] = useState<string | null>(null);
    const [confirmInput, setConfirmInput] = useState('');
    const [runResult, setRunResult] = useState<{ audit_id: number } | null>(null);

    const previewMutation = usePreviewCommand();
    const runMutation = useRunCommand();

    // Poll history filtered to the specific audit row until the
    // terminal state lands. 2s cadence is plenty for commands that
    // finish in under a second; longer commands get a loading
    // indicator while status=started.
    const historyQuery = useCommandHistory(
        { command, page: 1 },
        { pollMs: step === 'run' ? 2000 : undefined },
    );
    const auditRow = useMemo<HistoryRow | null>(() => {
        if (runResult === null) return null;
        const rows = historyQuery.data?.data ?? [];
        return rows.find((r) => r.id === runResult.audit_id) ?? null;
    }, [historyQuery.data, runResult]);

    const terminal = auditRow !== null && auditRow.status !== 'started';
    const effectiveStep: Step = step === 'run' && terminal ? 'result' : step;

    // ------------------------------------------------------------------
    // step: preview
    // ------------------------------------------------------------------

    async function handlePreview() {
        try {
            const res = await previewMutation.mutateAsync({ command, args });
            if (res.destructive) {
                setConfirmToken(res.confirm_token ?? null);
                setStep('confirm');
                return;
            }
            // Non-destructive: jump straight to Run.
            await executeRun(null);
        } catch {
            // mutation error surfaces via previewMutation.error below.
        }
    }

    async function executeRun(token: string | null) {
        setStep('run');
        try {
            const res = await runMutation.mutateAsync({
                command,
                args,
                confirm_token: token ?? undefined,
            });
            setRunResult({ audit_id: res.audit_id });
        } catch {
            // run error surfaces via runMutation.error.
        }
    }

    async function handleConfirmContinue() {
        if (confirmToken === null) return;
        if (confirmInput !== command) return; // defensive; button is disabled
        await executeRun(confirmToken);
    }

    // ------------------------------------------------------------------
    // rendering
    // ------------------------------------------------------------------

    return (
        <div
            data-testid="command-wizard"
            data-step={effectiveStep}
            role="dialog"
            aria-label={`Run ${command}`}
            style={{
                position: 'fixed',
                inset: 0,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                background: 'rgba(0,0,0,0.4)',
                zIndex: 100,
            }}
        >
            <div
                style={{
                    background: 'var(--bg-1)',
                    border: '1px solid var(--hairline)',
                    borderRadius: 10,
                    padding: 20,
                    minWidth: 480,
                    maxWidth: 640,
                    maxHeight: '80vh',
                    overflow: 'auto',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                }}
            >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <h2 style={{ fontSize: 16, margin: 0, fontWeight: 600 }}>
                        {command}{' '}
                        {spec.destructive ? (
                            <span
                                style={{
                                    fontSize: 10.5,
                                    padding: '2px 6px',
                                    borderRadius: 4,
                                    marginLeft: 6,
                                    background: 'var(--danger-bg, #fee)',
                                    color: 'var(--danger-fg, #b91c1c)',
                                }}
                            >
                                DESTRUCTIVE
                            </span>
                        ) : null}
                    </h2>
                    <button
                        type="button"
                        data-testid="wizard-cancel"
                        onClick={onClose}
                        style={{
                            background: 'transparent',
                            border: 'none',
                            fontSize: 18,
                            color: 'var(--fg-2)',
                            cursor: 'pointer',
                        }}
                    >
                        ×
                    </button>
                </div>

                <p style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-3)' }}>{spec.description}</p>

                {effectiveStep === 'preview' ? (
                    <PreviewStep
                        schema={spec.args_schema}
                        args={args}
                        setArgs={setArgs}
                        onRun={handlePreview}
                        loading={previewMutation.isPending}
                        error={extractAxiosMessage(previewMutation.error)}
                    />
                ) : null}

                {effectiveStep === 'confirm' ? (
                    <ConfirmStep
                        command={command}
                        confirmInput={confirmInput}
                        setConfirmInput={setConfirmInput}
                        onBack={() => setStep('preview')}
                        onContinue={handleConfirmContinue}
                        loading={runMutation.isPending}
                    />
                ) : null}

                {effectiveStep === 'run' ? (
                    <RunStep auditId={runResult?.audit_id ?? null} error={extractAxiosMessage(runMutation.error)} />
                ) : null}

                {effectiveStep === 'result' ? (
                    <ResultStep row={auditRow} onClose={onClose} />
                ) : null}
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Steps
// ---------------------------------------------------------------------------

interface PreviewStepProps {
    schema: Record<string, import('./maintenance.api').CommandArgsSchemaRule>;
    args: Record<string, unknown>;
    setArgs: (a: Record<string, unknown>) => void;
    onRun: () => void;
    loading: boolean;
    error: string | null;
}

function PreviewStep({ schema, args, setArgs, onRun, loading, error }: PreviewStepProps) {
    const keys = Object.keys(schema);
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }} data-testid="wizard-step-preview">
            {keys.length === 0 ? (
                <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                    This command takes no arguments.
                </p>
            ) : null}
            {keys.map((key) => {
                const rule = schema[key];
                const value = args[key];
                return (
                    <label key={key} style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                        <span style={{ fontSize: 11, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                            {key}
                            {rule.required ? ' *' : ''}
                        </span>
                        {rule.type === 'bool' ? (
                            <input
                                type="checkbox"
                                data-testid={`wizard-arg-${key}`}
                                checked={Boolean(value)}
                                onChange={(e) => setArgs({ ...args, [key]: e.target.checked })}
                            />
                        ) : rule.type === 'int' ? (
                            <input
                                type="number"
                                data-testid={`wizard-arg-${key}`}
                                min={rule.min}
                                max={rule.max}
                                value={value === undefined || value === null ? '' : String(value)}
                                onChange={(e) => {
                                    const raw = e.target.value;
                                    setArgs({
                                        ...args,
                                        [key]: raw === '' ? undefined : Number(raw),
                                    });
                                }}
                                style={inputStyle}
                            />
                        ) : (
                            <input
                                type="text"
                                data-testid={`wizard-arg-${key}`}
                                maxLength={rule.max}
                                value={value === undefined || value === null ? '' : String(value)}
                                onChange={(e) =>
                                    setArgs({
                                        ...args,
                                        [key]: e.target.value === '' ? undefined : e.target.value,
                                    })
                                }
                                style={inputStyle}
                            />
                        )}
                    </label>
                );
            })}

            {error ? (
                <div
                    data-testid="wizard-preview-error"
                    style={{
                        padding: 8,
                        fontSize: 12,
                        color: 'var(--danger-fg, #b91c1c)',
                        border: '1px solid var(--danger-fg, #b91c1c)',
                        borderRadius: 6,
                    }}
                >
                    {error}
                </div>
            ) : null}

            <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
                <button
                    type="button"
                    data-testid="wizard-step-preview-run"
                    onClick={onRun}
                    disabled={loading}
                    style={primaryBtnStyle}
                >
                    {loading ? 'Checking…' : 'Preview'}
                </button>
            </div>
        </div>
    );
}

interface ConfirmStepProps {
    command: string;
    confirmInput: string;
    setConfirmInput: (s: string) => void;
    onBack: () => void;
    onContinue: () => void;
    loading: boolean;
}

function ConfirmStep({ command, confirmInput, setConfirmInput, onBack, onContinue, loading }: ConfirmStepProps) {
    const matches = confirmInput === command;
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }} data-testid="wizard-step-confirm">
            <p style={{ fontSize: 13, color: 'var(--fg-1)', margin: 0 }}>
                This is a <strong>destructive</strong> command. Type the command name
                exactly to confirm:
            </p>
            <input
                data-testid="wizard-confirm-input"
                value={confirmInput}
                onChange={(e) => setConfirmInput(e.target.value)}
                placeholder={command}
                style={inputStyle}
            />
            <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
                <button type="button" onClick={onBack} style={secondaryBtnStyle}>
                    Back
                </button>
                <button
                    type="button"
                    data-testid="wizard-confirm-continue"
                    disabled={!matches || loading}
                    onClick={onContinue}
                    style={{
                        ...primaryBtnStyle,
                        opacity: matches && !loading ? 1 : 0.5,
                        cursor: matches && !loading ? 'pointer' : 'not-allowed',
                    }}
                >
                    {loading ? 'Running…' : 'Run'}
                </button>
            </div>
        </div>
    );
}

function RunStep({ auditId, error }: { auditId: number | null; error: string | null }) {
    return (
        <div
            data-testid="wizard-run"
            data-state={error ? 'error' : 'loading'}
            style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
        >
            {error ? (
                <div
                    data-testid="wizard-error"
                    style={{
                        padding: 10,
                        color: 'var(--danger-fg, #b91c1c)',
                        border: '1px solid var(--danger-fg, #b91c1c)',
                        borderRadius: 6,
                        fontSize: 12.5,
                    }}
                >
                    {error}
                </div>
            ) : (
                <p style={{ margin: 0, fontSize: 13, color: 'var(--fg-2)' }}>
                    Running (audit #{auditId ?? '—'})… polling history.
                </p>
            )}
        </div>
    );
}

function ResultStep({ row, onClose }: { row: HistoryRow | null; onClose: () => void }) {
    const status = row?.status ?? 'unknown';
    const stateAttr = row?.status === 'completed' ? 'ready' : 'error';
    return (
        <div
            data-testid="wizard-result"
            data-state={stateAttr}
            style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
        >
            <div style={{ fontSize: 13, color: 'var(--fg-1)' }}>
                <strong>Status:</strong> {status}
                {row?.exit_code !== null && row?.exit_code !== undefined ? (
                    <>
                        {' '}
                        · <strong>Exit:</strong> {row.exit_code}
                    </>
                ) : null}
            </div>
            {row?.error_message ? (
                <div
                    data-testid="wizard-error"
                    style={{
                        padding: 10,
                        color: 'var(--danger-fg, #b91c1c)',
                        border: '1px solid var(--danger-fg, #b91c1c)',
                        borderRadius: 6,
                        fontSize: 12,
                    }}
                >
                    {row.error_message}
                </div>
            ) : null}
            {row?.stdout_head ? (
                <pre
                    data-testid="wizard-stdout"
                    style={{
                        padding: 8,
                        fontSize: 11,
                        background: 'var(--bg-0)',
                        border: '1px solid var(--hairline)',
                        borderRadius: 6,
                        maxHeight: '30vh',
                        overflow: 'auto',
                    }}
                >
                    {row.stdout_head}
                </pre>
            ) : null}
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                <button type="button" onClick={onClose} style={primaryBtnStyle}>
                    Close
                </button>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function extractAxiosMessage(err: unknown): string | null {
    if (err === null || err === undefined) return null;
    const e = err as { response?: { status?: number; data?: { message?: string } } };
    const status = e?.response?.status;
    const msg = e?.response?.data?.message;
    if (status !== undefined) {
        return `${status}${msg ? ': ' + msg : ''}`;
    }
    return msg ?? 'Unexpected error';
}

const inputStyle: React.CSSProperties = {
    padding: '6px 8px',
    fontSize: 12.5,
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    color: 'var(--fg-1)',
};

const primaryBtnStyle: React.CSSProperties = {
    padding: '7px 14px',
    fontSize: 13,
    background: 'var(--accent, #3b82f6)',
    color: 'white',
    border: 'none',
    borderRadius: 6,
    cursor: 'pointer',
};

const secondaryBtnStyle: React.CSSProperties = {
    padding: '7px 14px',
    fontSize: 13,
    background: 'transparent',
    color: 'var(--fg-2)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    cursor: 'pointer',
};
