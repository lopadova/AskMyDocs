import { FormEvent, useState } from 'react';
import { useRegisterMcpServer } from './mcp-tools-hooks';
import type { McpTransport } from './mcp-tools.api';

interface RegisterServerDialogProps {
    open: boolean;
    onClose: () => void;
    onCreated: (id: number) => void;
}

/*
 * v5.0/W2 — Register a new MCP server. Three required fields (name,
 * transport, endpoint) plus an optional auth_config JSON blob. The form
 * validates inline; backend ValidationException maps to per-field
 * errors via the response payload.
 */
export function RegisterServerDialog({ open, onClose, onCreated }: RegisterServerDialogProps) {
    const register = useRegisterMcpServer();
    const [name, setName] = useState('');
    const [transport, setTransport] = useState<McpTransport>('stdio');
    const [endpoint, setEndpoint] = useState('');
    const [authJson, setAuthJson] = useState('');
    const [enabledToolsRaw, setEnabledToolsRaw] = useState('*');
    const [authJsonError, setAuthJsonError] = useState<string | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);

    if (!open) {
        return null;
    }

    function reset() {
        setName('');
        setTransport('stdio');
        setEndpoint('');
        setAuthJson('');
        setEnabledToolsRaw('*');
        setAuthJsonError(null);
        setSubmitError(null);
    }

    function handleClose() {
        reset();
        onClose();
    }

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSubmitError(null);

        let authConfig: Record<string, unknown> | undefined;
        const trimmedAuth = authJson.trim();
        if (trimmedAuth) {
            try {
                const parsed = JSON.parse(trimmedAuth);
                if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                    throw new Error('auth_config must be a JSON object');
                }
                authConfig = parsed as Record<string, unknown>;
                setAuthJsonError(null);
            } catch (error) {
                setAuthJsonError(
                    error instanceof Error ? error.message : 'Invalid JSON',
                );
                return;
            }
        }

        const enabledTools = enabledToolsRaw
            .split(',')
            .map((part) => part.trim())
            .filter(Boolean);

        try {
            const server = await register.mutateAsync({
                name: name.trim(),
                transport,
                endpoint: endpoint.trim(),
                auth_config: authConfig,
                enabled_tools: enabledTools.length > 0 ? enabledTools : ['*'],
            });
            onCreated(server.id);
            handleClose();
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Failed to register');
        }
    }

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="admin-mcp-register-title"
            data-testid="admin-mcp-register-dialog"
            data-state={register.isPending ? 'submitting' : 'idle'}
            style={modalOverlay}
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    handleClose();
                }
            }}
        >
            <form onSubmit={handleSubmit} style={dialogStyle}>
                <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                    <h2
                        id="admin-mcp-register-title"
                        style={{ margin: 0, fontSize: 16, fontWeight: 600 }}
                    >
                        Register MCP server
                    </h2>
                    <button
                        type="button"
                        onClick={handleClose}
                        aria-label="Close dialog"
                        data-testid="admin-mcp-register-close"
                        style={iconBtnStyle}
                    >
                        ×
                    </button>
                </header>

                <Field label="Name" required>
                    <input
                        type="text"
                        required
                        maxLength={100}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        data-testid="admin-mcp-register-name"
                        aria-required="true"
                        style={inputStyle}
                    />
                </Field>

                <Field label="Transport" required>
                    <select
                        value={transport}
                        onChange={(event) => setTransport(event.target.value as McpTransport)}
                        data-testid="admin-mcp-register-transport"
                        aria-label="MCP transport"
                        style={inputStyle}
                    >
                        <option value="stdio">stdio (child process)</option>
                        <option value="sse">sse (Server-Sent Events)</option>
                        <option value="http">http (streamable HTTP)</option>
                    </select>
                </Field>

                <Field
                    label="Endpoint"
                    hint={
                        transport === 'stdio'
                            ? 'Command line, e.g. "npx -y @modelcontextprotocol/server-github"'
                            : 'Full URL, e.g. https://mcp.example.com/sse'
                    }
                    required
                >
                    <input
                        type="text"
                        required
                        maxLength={500}
                        value={endpoint}
                        onChange={(event) => setEndpoint(event.target.value)}
                        data-testid="admin-mcp-register-endpoint"
                        aria-required="true"
                        style={inputStyle}
                    />
                </Field>

                <Field
                    label="Auth config (optional)"
                    hint="JSON object encrypted at rest. Pass tokens / headers / env vars here."
                >
                    <textarea
                        rows={4}
                        value={authJson}
                        onChange={(event) => setAuthJson(event.target.value)}
                        placeholder='{"bearer_token": "sk-..."}'
                        data-testid="admin-mcp-register-auth-json"
                        style={textareaStyle}
                    />
                    {authJsonError ? (
                        <p
                            data-testid="admin-mcp-register-auth-json-error"
                            style={errorTextStyle}
                        >
                            {authJsonError}
                        </p>
                    ) : null}
                </Field>

                <Field
                    label="Enabled tools (CSV or *)"
                    hint='"*" allows everything; otherwise comma-separated names that match the handshake.'
                >
                    <input
                        type="text"
                        value={enabledToolsRaw}
                        onChange={(event) => setEnabledToolsRaw(event.target.value)}
                        data-testid="admin-mcp-register-enabled-tools"
                        style={inputStyle}
                    />
                </Field>

                {submitError ? (
                    <p
                        role="alert"
                        data-testid="admin-mcp-register-error"
                        style={{ ...errorTextStyle, marginTop: 6 }}
                    >
                        {submitError}
                    </p>
                ) : null}

                <footer style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
                    <button
                        type="button"
                        onClick={handleClose}
                        disabled={register.isPending}
                        data-testid="admin-mcp-register-cancel"
                        style={secondaryBtnStyle}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={register.isPending}
                        data-testid="admin-mcp-register-submit"
                        style={primaryBtnStyle(register.isPending)}
                        aria-busy={register.isPending}
                    >
                        {register.isPending ? 'Registering…' : 'Register'}
                    </button>
                </footer>
            </form>
        </div>
    );
}

interface FieldProps {
    label: string;
    hint?: string;
    required?: boolean;
    children: React.ReactNode;
}

function Field({ label, hint, required, children }: FieldProps) {
    return (
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 10 }}>
            <span style={{ fontSize: 12.5, color: 'var(--fg-1)', fontWeight: 600 }}>
                {label}
                {required ? <span aria-hidden="true" style={{ color: 'var(--danger-fg)', marginLeft: 4 }}>*</span> : null}
            </span>
            {children}
            {hint ? (
                <span style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>{hint}</span>
            ) : null}
        </label>
    );
}

const modalOverlay: React.CSSProperties = {
    position: 'fixed',
    inset: 0,
    background: 'rgba(0,0,0,0.55)',
    display: 'flex',
    alignItems: 'flex-start',
    justifyContent: 'center',
    paddingTop: 80,
    zIndex: 100,
};

const dialogStyle: React.CSSProperties = {
    background: 'var(--bg-1)',
    border: '1px solid var(--border-1)',
    borderRadius: 14,
    padding: '20px 22px 18px',
    minWidth: 460,
    maxWidth: 560,
    boxShadow: '0 18px 32px rgba(0,0,0,0.35)',
};

const inputStyle: React.CSSProperties = {
    padding: '8px 10px',
    borderRadius: 8,
    border: '1px solid var(--border-2)',
    background: 'var(--bg-2)',
    color: 'var(--fg-1)',
    fontSize: 13,
    fontFamily: 'inherit',
};

const textareaStyle: React.CSSProperties = {
    ...inputStyle,
    fontFamily: 'var(--font-mono, ui-monospace)',
    fontSize: 12.5,
    resize: 'vertical',
};

const errorTextStyle: React.CSSProperties = {
    margin: 0,
    fontSize: 12,
    color: 'var(--danger-fg)',
};

const iconBtnStyle: React.CSSProperties = {
    width: 28,
    height: 28,
    borderRadius: 8,
    border: '1px solid var(--border-2)',
    background: 'transparent',
    color: 'var(--fg-2)',
    cursor: 'pointer',
    fontSize: 16,
};

const secondaryBtnStyle: React.CSSProperties = {
    padding: '8px 14px',
    borderRadius: 8,
    border: '1px solid var(--border-2)',
    background: 'transparent',
    color: 'var(--fg-1)',
    cursor: 'pointer',
    fontSize: 13,
    fontWeight: 600,
};

function primaryBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '8px 16px',
        borderRadius: 8,
        border: '1px solid transparent',
        background: disabled ? 'var(--bg-2)' : 'var(--accent-bg)',
        color: disabled ? 'var(--fg-2)' : 'var(--accent-fg)',
        cursor: disabled ? 'wait' : 'pointer',
        fontSize: 13,
        fontWeight: 600,
    };
}
