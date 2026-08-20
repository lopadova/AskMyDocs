import { useEffect, useRef, useState } from 'react';
import type {
    AppBridge,
    McpUiResourceCsp,
    McpUiResourcePermissions,
    PostMessageTransport,
} from '@modelcontextprotocol/ext-apps/app-bridge';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';

import { api } from '../../../lib/api';
import { ToolResultPreview } from './ToolResultPreview';

export interface McpAppHandle {
    id: string;
    resource_uri?: string;
    fallback?: string;
}

interface McpAppResource {
    app_id: string;
    available: boolean;
    fallback?: string;
    sandbox_url?: string;
    html?: string;
    csp?: McpUiResourceCsp;
    permissions?: McpUiResourcePermissions;
    prefers_border?: boolean;
    description?: string | null;
    tool_input?: Record<string, unknown>;
    tool_result?: unknown;
    advanced_enabled?: boolean;
}

interface AppToolCallResponse {
    status: string;
    result?: unknown;
    artifact?: unknown;
    pending_interaction_id?: string;
    prompt?: Record<string, unknown>;
    task_id?: string;
}

interface PendingAppInteraction {
    id: string;
    kind: 'confirmation_required' | 'input_required';
    prompt?: Record<string, unknown>;
}

interface PendingResolver {
    resolve: (result: CallToolResult) => void;
}

interface McpAppFrameProps {
    app: McpAppHandle;
    conversationId: number;
    onSendMessage?: (content: string, appId: string) => Promise<void>;
}

export function McpAppFrame({ app, conversationId, onSendMessage }: McpAppFrameProps) {
    const iframeRef = useRef<HTMLIFrameElement>(null);
    const bridgeRef = useRef<AppBridge | null>(null);
    const onSendMessageRef = useRef(onSendMessage);
    const pendingResolverRef = useRef<PendingResolver | null>(null);
    const [resource, setResource] = useState<McpAppResource | null>(null);
    const [loading, setLoading] = useState(true);
    const [ready, setReady] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [height, setHeight] = useState(320);
    const [pendingInteraction, setPendingInteraction] = useState<PendingAppInteraction | null>(null);
    const [inputJson, setInputJson] = useState('{}');
    const [submitting, setSubmitting] = useState(false);
    const [displayMode, setDisplayMode] = useState<'inline' | 'fullscreen'>('inline');

    useEffect(() => {
        onSendMessageRef.current = onSendMessage;
    }, [onSendMessage]);

    useEffect(() => {
        let disposed = false;
        setLoading(true);
        setReady(false);
        setError(null);
        setResource(null);

        api.get<McpAppResource>(`/api/conversations/mcp/apps/${app.id}`, {
            params: { conversation_id: String(conversationId) },
        }).then(({ data }) => {
            if (!disposed) setResource(data);
        }).catch((cause: unknown) => {
            if (!disposed) setError(apiErrorMessage(cause, 'Could not load this MCP App.'));
        }).finally(() => {
            if (!disposed) setLoading(false);
        });

        return () => {
            disposed = true;
        };
    }, [app.id, conversationId]);

    useEffect(() => {
        const iframe = iframeRef.current;
        if (!iframe || !resource?.available || !resource.sandbox_url || !resource.html) return;

        const sandboxUrl = safeSandboxUrl(resource.sandbox_url);
        if (sandboxUrl === null) {
            setError('The MCP App sandbox origin is not valid or is not isolated from AskMyDocs.');
            return;
        }
        const frameWindow = iframe.contentWindow;
        if (!frameWindow) {
            setError('The browser could not create the MCP App sandbox.');
            return;
        }

        let disposed = false;
        let bridge: AppBridge | null = null;
        let transport: PostMessageTransport | null = null;

        void (async () => {
            const bridgeModule = await import('@modelcontextprotocol/ext-apps/app-bridge');
            if (disposed) return;

            const nextTransport = new bridgeModule.PostMessageTransport(frameWindow, frameWindow);
            const nextBridge = new bridgeModule.AppBridge(
                null,
                { name: 'AskMyDocs', version: '1.0.0' },
                {
                    openLinks: {},
                    serverTools: {},
                    logging: {},
                    ...(resource.advanced_enabled
                        ? {
                              downloadFile: {},
                              message: { text: {} },
                              updateModelContext: { text: {}, structuredContent: {} },
                          }
                        : {}),
                    sandbox: {
                        csp: resource.csp,
                        permissions: resource.permissions,
                    },
                },
                {
                    hostContext: {
                        theme: currentTheme(),
                        displayMode: 'inline',
                        availableDisplayModes: resource.advanced_enabled ? ['inline', 'fullscreen'] : ['inline'],
                        containerDimensions: { maxHeight: 900 },
                        locale: navigator.language,
                        timeZone: currentTimeZone(),
                        userAgent: 'AskMyDocs',
                        platform: 'web',
                        deviceCapabilities: {
                            touch: navigator.maxTouchPoints > 0,
                            hover: window.matchMedia?.('(hover: hover)').matches ?? false,
                        },
                    },
                },
            );
            bridge = nextBridge;
            bridgeRef.current = nextBridge;
            transport = nextTransport;

            nextBridge.onsandboxready = () => {
                void nextBridge.sendSandboxResourceReady({
                    html: resource.html ?? '',
                    sandbox: 'allow-scripts',
                    csp: resource.csp,
                    permissions: resource.permissions,
                }).catch(() => {
                    if (!disposed) setError('The MCP App sandbox rejected its resource.');
                });
            };
            nextBridge.oninitialized = () => {
                void (async () => {
                    await nextBridge.sendToolInput({ arguments: resource.tool_input ?? {} });
                    await nextBridge.sendToolResult(normalizeCallToolResult(resource.tool_result));
                    if (!disposed) setReady(true);
                })().catch(() => {
                    if (!disposed) setError('The MCP App could not be initialized.');
                });
            };
            nextBridge.onsizechange = ({ height: requestedHeight }) => {
                if (typeof requestedHeight === 'number' && Number.isFinite(requestedHeight)) {
                    setHeight(Math.max(120, Math.min(Math.ceil(requestedHeight), 900)));
                }
            };
            nextBridge.onopenlink = async ({ url }) => {
                try {
                    const target = new URL(url);
                    if (target.protocol !== 'https:') return { isError: true };
                    window.open(target.href, '_blank', 'noopener,noreferrer');
                    return {};
                } catch {
                    return { isError: true };
                }
            };
            nextBridge.oncalltool = async ({ name, arguments: toolArguments }) => {
                if (pendingResolverRef.current !== null) {
                    return errorResult('Complete the current MCP App interaction before starting another tool call.');
                }
                const { data } = await api.post<AppToolCallResponse>(
                    `/api/conversations/mcp/apps/${app.id}/tools/call`,
                    {
                        conversation_id: String(conversationId),
                        name,
                        arguments: toolArguments ?? {},
                    },
                );

                if (
                    (data.status === 'confirmation_required' || data.status === 'input_required')
                    && data.pending_interaction_id
                ) {
                    return new Promise<CallToolResult>((resolve) => {
                        pendingResolverRef.current = { resolve };
                        setPendingInteraction({
                            id: data.pending_interaction_id as string,
                            kind: data.status as PendingAppInteraction['kind'],
                            prompt: data.prompt,
                        });
                    });
                }

                return normalizeCallToolResult(data.result ?? data.artifact);
            };
            if (resource.advanced_enabled) {
                nextBridge.onmessage = async ({ role, content }) => {
                    if (role !== 'user' || !onSendMessageRef.current) return { isError: true };
                    const text = content
                        .map((block) => (block.type === 'text' ? block.text : ''))
                        .filter((value) => value !== '')
                        .join('\n')
                        .trim();
                    if (text === '' || text.length > 10_000) return { isError: true };
                    try {
                        await onSendMessageRef.current(text, app.id);
                        return {};
                    } catch {
                        return { isError: true };
                    }
                };
                nextBridge.onupdatemodelcontext = async ({ content, structuredContent }) => {
                    await api.put(`/api/conversations/mcp/apps/${app.id}/model-context`, {
                        conversation_id: String(conversationId),
                        content: content ?? [],
                        ...(structuredContent ? { structuredContent } : {}),
                    });
                    return {};
                };
                nextBridge.ondownloadfile = async ({ contents }) => {
                    if (!window.confirm('Download files requested by this MCP App?')) return { isError: true };
                    try {
                        const { data } = await api.post<{
                            downloads: Array<{ name: string; url: string }>;
                        }>(`/api/conversations/mcp/apps/${app.id}/downloads`, {
                            conversation_id: String(conversationId),
                            contents,
                        });
                        for (const download of data.downloads) triggerDownload(download.url, download.name);
                        return {};
                    } catch {
                        return { isError: true };
                    }
                };
                nextBridge.onrequestdisplaymode = async ({ mode }) => {
                    const actual = mode === 'fullscreen' ? 'fullscreen' : 'inline';
                    setDisplayMode(actual);
                    nextBridge.setHostContext({
                        displayMode: actual,
                        availableDisplayModes: ['inline', 'fullscreen'],
                    });
                    return { mode: actual };
                };
            }

            await nextBridge.connect(nextTransport);
            if (!disposed) iframe.src = sandboxUrl.href;
        })().catch(() => {
            if (!disposed) setError('The MCP Apps bridge could not connect to its sandbox.');
        });

        return () => {
            disposed = true;
            pendingResolverRef.current?.resolve(errorResult('The MCP App was closed before the tool call completed.'));
            pendingResolverRef.current = null;
            if (bridgeRef.current === bridge) bridgeRef.current = null;
            const activeBridge = bridge;
            const activeTransport = transport;
            if (activeBridge !== null) {
                void activeBridge.teardownResource({}).catch(() => undefined).finally(() => {
                    if (activeTransport !== null) void activeTransport.close();
                });
            } else if (activeTransport !== null) {
                void activeTransport.close();
            }
            iframe.src = 'about:blank';
        };
    }, [app.id, conversationId, resource]);

    function changeDisplayMode(mode: 'inline' | 'fullscreen') {
        setDisplayMode(mode);
        bridgeRef.current?.setHostContext({
            displayMode: mode,
            availableDisplayModes: ['inline', 'fullscreen'],
        });
    }

    async function respondToInteraction(response: Record<string, unknown>) {
        if (!pendingInteraction) return;
        setSubmitting(true);
        setError(null);
        try {
            const { data } = await api.post<AppToolCallResponse>(
                `/api/conversations/mcp/interactions/${pendingInteraction.id}`,
                { conversation_id: String(conversationId), response },
            );
            const result = data.status === 'declined'
                ? errorResult('The user declined this MCP tool call.')
                : normalizeCallToolResult(data.result ?? data.artifact);
            pendingResolverRef.current?.resolve(result);
            pendingResolverRef.current = null;
            setPendingInteraction(null);
            setInputJson('{}');
        } catch (cause) {
            setError(apiErrorMessage(cause, 'Could not resume this MCP App tool call.'));
        } finally {
            setSubmitting(false);
        }
    }

    function submitInput() {
        try {
            const parsed: unknown = JSON.parse(inputJson);
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                throw new Error('Input responses must be a JSON object.');
            }
            void respondToInteraction(parsed as Record<string, unknown>);
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Invalid JSON input.');
        }
    }

    const fallback = resource?.fallback ?? app.fallback ?? 'This MCP tool returned an interactive app.';
    const iframeAllow = permissionAllowAttribute(resource?.permissions);

    return (
        <div
            data-testid={`mcp-app-${app.id}`}
            style={{
                marginTop: 10,
                overflow: 'hidden',
                border: resource?.prefers_border === false ? 0 : '1px solid var(--border-2)',
                borderRadius: 10,
                background: 'var(--bg-2)',
                ...(displayMode === 'fullscreen'
                    ? {
                          position: 'fixed',
                          inset: 16,
                          zIndex: 1000,
                          display: 'flex',
                          flexDirection: 'column',
                          boxShadow: '0 24px 80px rgba(0,0,0,.45)',
                      }
                    : {}),
            }}
        >
            {displayMode === 'fullscreen' ? (
                <button
                    type="button"
                    onClick={() => changeDisplayMode('inline')}
                    style={{ ...buttonStyle, margin: 8, marginLeft: 'auto' }}
                >
                    Exit fullscreen
                </button>
            ) : null}
            {loading ? <div style={noticeStyle}>Loading interactive MCP App…</div> : null}
            {!loading && !resource?.available ? (
                <div style={noticeStyle}>
                    <div>{fallback}</div>
                    {error ? <div role="alert" style={{ color: 'var(--danger-fg)', marginTop: 6 }}>{error}</div> : null}
                </div>
            ) : null}
            {resource?.available ? (
                <>
                    {resource.description ? <div style={descriptionStyle}>{resource.description}</div> : null}
                    {!ready ? <div style={noticeStyle}>Starting secure app…</div> : null}
                    <iframe
                        ref={iframeRef}
                        title={resource.description ?? 'Interactive MCP App'}
                        src="about:blank"
                        sandbox="allow-scripts allow-same-origin"
                        allow={iframeAllow || undefined}
                        data-testid={`mcp-app-${app.id}-frame`}
                        style={{
                            display: ready ? 'block' : 'none',
                            width: '100%',
                            height: displayMode === 'fullscreen' ? '100%' : height,
                            flex: displayMode === 'fullscreen' ? 1 : undefined,
                            border: 0,
                            background: 'transparent',
                        }}
                    />
                </>
            ) : null}
            {resource?.available && error ? (
                <div role="alert" style={{ ...noticeStyle, color: 'var(--danger-fg)' }}>{error}</div>
            ) : null}
            {pendingInteraction ? (
                <div data-testid={`mcp-app-${app.id}-interaction`} style={interactionStyle}>
                    <div style={{ fontWeight: 600 }}>
                        {pendingInteraction.kind === 'confirmation_required' ? 'Confirmation required' : 'Additional input required'}
                    </div>
                    {typeof pendingInteraction.prompt?.message === 'string'
                        ? <div>{pendingInteraction.prompt.message}</div>
                        : null}
                    {pendingInteraction.kind === 'confirmation_required' ? (
                        <div style={{ display: 'flex', gap: 8 }}>
                            <button type="button" disabled={submitting} onClick={() => void respondToInteraction({ confirmed: true })} style={buttonStyle}>Confirm</button>
                            <button type="button" disabled={submitting} onClick={() => void respondToInteraction({ confirmed: false })} style={buttonStyle}>Decline</button>
                        </div>
                    ) : (
                        <>
                            {pendingInteraction.prompt?.inputRequests
                                ? <ToolResultPreview value={pendingInteraction.prompt.inputRequests} />
                                : null}
                            <textarea
                                aria-label="MCP App input responses"
                                value={inputJson}
                                onChange={(event) => setInputJson(event.target.value)}
                                rows={4}
                                style={inputStyle}
                            />
                            <button type="button" disabled={submitting} onClick={submitInput} style={buttonStyle}>Send input</button>
                        </>
                    )}
                </div>
            ) : null}
        </div>
    );
}

function triggerDownload(url: string, name: string): void {
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.rel = 'noopener noreferrer';
    document.body.appendChild(link);
    link.click();
    link.remove();
}

function safeSandboxUrl(raw: string): URL | null {
    try {
        const url = new URL(raw, window.location.href);
        if (!['http:', 'https:'].includes(url.protocol) || url.origin === window.location.origin) return null;
        return url;
    } catch {
        return null;
    }
}

function permissionAllowAttribute(permissions: McpUiResourcePermissions | undefined): string {
    if (!permissions) return '';
    const features: Array<[keyof McpUiResourcePermissions, string]> = [
        ['camera', 'camera'],
        ['microphone', 'microphone'],
        ['geolocation', 'geolocation'],
        ['clipboardWrite', 'clipboard-write'],
    ];

    return features
        .filter(([key]) => permissions[key] !== undefined)
        .map(([, feature]) => feature)
        .join('; ');
}

function normalizeCallToolResult(value: unknown): CallToolResult {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return errorResult('The MCP tool returned an invalid result.');
    }
    const record = value as Record<string, unknown>;
    const content = Array.isArray(record.content)
        ? record.content
        : typeof record.text === 'string' && record.text !== ''
            ? [{ type: 'text' as const, text: record.text }]
            : [];

    const meta = record._meta && typeof record._meta === 'object' && !Array.isArray(record._meta)
        ? { ...(record._meta as Record<string, unknown>) }
        : {};
    if (Array.isArray(record.attachments)) {
        meta['askmydocs/attachments'] = record.attachments;
    }
    const structuredContent = record.structuredContent
        && typeof record.structuredContent === 'object'
        && !Array.isArray(record.structuredContent)
        ? record.structuredContent as Record<string, unknown>
        : undefined;

    return {
        content,
        ...(structuredContent ? { structuredContent } : {}),
        isError: record.isError === true,
        ...(Object.keys(meta).length > 0 ? { _meta: meta } : {}),
    } as CallToolResult;
}

function errorResult(message: string): CallToolResult {
    return {
        content: [{ type: 'text', text: message }],
        isError: true,
    };
}

function apiErrorMessage(cause: unknown, fallback: string): string {
    const data = (cause as { response?: { data?: { message?: string; error?: string } }; message?: string })
        ?.response?.data;
    return data?.message ?? data?.error ?? fallback;
}

function currentTheme(): 'light' | 'dark' {
    if (document.documentElement.classList.contains('dark')) return 'dark';
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function currentTimeZone(): string | undefined {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch {
        return undefined;
    }
}

const noticeStyle: React.CSSProperties = {
    padding: '12px 14px',
    color: 'var(--fg-2)',
};

const descriptionStyle: React.CSSProperties = {
    padding: '8px 12px',
    borderBottom: '1px solid var(--border-2)',
    color: 'var(--fg-2)',
    fontSize: 12,
};

const interactionStyle: React.CSSProperties = {
    display: 'grid',
    gap: 8,
    padding: 12,
    borderTop: '1px solid var(--border-2)',
};

const buttonStyle: React.CSSProperties = {
    width: 'fit-content',
    border: '1px solid rgba(245,158,11,.4)',
    borderRadius: 7,
    background: 'rgba(245,158,11,.12)',
    color: 'var(--fg-0)',
    padding: '6px 10px',
    cursor: 'pointer',
    font: 'inherit',
};

const inputStyle: React.CSSProperties = {
    width: '100%',
    boxSizing: 'border-box',
    border: '1px solid var(--border-2)',
    borderRadius: 7,
    background: 'var(--bg-1)',
    color: 'var(--fg-0)',
    padding: 8,
    fontFamily: 'var(--font-mono, ui-monospace)',
    fontSize: 12,
};
