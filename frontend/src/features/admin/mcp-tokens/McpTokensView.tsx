import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../../lib/api';

interface McpTenantTokenRow {
    id: number;
    label: string;
    token_last4: string;
    scopes: string[];
    expires_at: string | null;
    revoked_at: string | null;
    created_at: string;
}

interface TokensResponse {
    data: McpTenantTokenRow[];
}

interface CreateTokenResponse {
    data: McpTenantTokenRow;
    plain_token: string;
}

export function McpTokensView() {
    const qc = useQueryClient();
    const [label, setLabel] = useState('');
    const [plainToken, setPlainToken] = useState<string | null>(null);

    const tokens = useQuery({
        queryKey: ['mcp-tenant-tokens'],
        queryFn: async () => {
            const { data } = await api.get<TokensResponse>('/api/admin/mcp/tokens');
            return data.data;
        },
    });

    const createToken = useMutation({
        mutationFn: async () => {
            const { data } = await api.post<CreateTokenResponse>('/api/admin/mcp/tokens', {
                label: label.trim(),
            });
            return data;
        },
        onSuccess: async (payload) => {
            setPlainToken(payload.plain_token);
            setLabel('');
            await qc.invalidateQueries({ queryKey: ['mcp-tenant-tokens'] });
        },
    });

    const revoke = useMutation({
        mutationFn: async (id: number) => {
            await api.post(`/api/admin/mcp/tokens/${id}/revoke`);
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['mcp-tenant-tokens'] });
        },
    });

    return (
        <section data-testid="admin-mcp-tokens-view" style={{ display: 'grid', gap: 14 }}>
            <header>
                <h1 style={{ margin: 0, fontSize: 22 }}>MCP Tenant Tokens</h1>
                <p style={{ marginTop: 6, color: 'var(--fg-2)' }}>
                    Mint one-time display tokens for external MCP consumers and revoke when needed.
                </p>
            </header>

            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <input
                    data-testid="admin-mcp-tokens-label"
                    value={label}
                    onChange={(e) => setLabel(e.target.value)}
                    placeholder="Token label (e.g. CI Agent)"
                    style={{ minWidth: 320 }}
                />
                <button
                    type="button"
                    data-testid="admin-mcp-tokens-mint"
                    disabled={label.trim() === '' || createToken.isPending}
                    onClick={() => void createToken.mutateAsync()}
                >
                    Mint token
                </button>
            </div>

            {plainToken && (
                <div data-testid="admin-mcp-tokens-plain" style={{ padding: 10, border: '1px solid var(--hairline)' }}>
                    <strong>Copy now:</strong> <code>{plainToken}</code>
                </div>
            )}

            <div style={{ display: 'grid', gap: 8 }}>
                {(tokens.data ?? []).map((row) => (
                    <div
                        key={row.id}
                        data-testid={`admin-mcp-tokens-row-${row.id}`}
                        style={{ display: 'flex', justifyContent: 'space-between', gap: 10, border: '1px solid var(--hairline)', padding: 10 }}
                    >
                        <div>
                            <div><strong>{row.label}</strong></div>
                            <div style={{ color: 'var(--fg-2)' }}>••••{row.token_last4}</div>
                            <div style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                                scopes: {(row.scopes ?? []).join(', ') || '(none)'}
                            </div>
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            {row.revoked_at ? (
                                <span data-testid={`admin-mcp-tokens-revoked-${row.id}`}>Revoked</span>
                            ) : (
                                <button
                                    type="button"
                                    data-testid={`admin-mcp-tokens-revoke-${row.id}`}
                                    disabled={revoke.isPending}
                                    onClick={() => void revoke.mutateAsync(row.id)}
                                >
                                    Revoke
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

