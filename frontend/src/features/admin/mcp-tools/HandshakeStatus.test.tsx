import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

import { HandshakeStatus } from './HandshakeStatus';
import type { McpServerEntry } from './mcp-tools.api';

function makeServer(overrides: Partial<McpServerEntry> = {}): McpServerEntry {
    return {
        id: 7,
        name: 'github',
        transport: 'stdio',
        endpoint: 'npx -y @modelcontextprotocol/server-github',
        enabled_tools: ['*'],
        status: 'active',
        last_handshake_at: '2026-05-14T08:00:00Z',
        handshake_response: {
            ok: true,
            protocol_version: '2024-11-05',
            tools: [
                { name: 'list_repositories', description: 'List repos' },
                { name: 'create_issue', description: 'Open an issue' },
            ],
            duration_ms: 412,
        },
        created_at: '2026-05-14T07:00:00Z',
        updated_at: '2026-05-14T08:00:00Z',
        ...overrides,
    };
}

describe('HandshakeStatus', () => {
    it('renders an "Active" pill for active servers', () => {
        render(<HandshakeStatus server={makeServer()} onRetry={() => {}} busy={false} />);
        const pill = screen.getByTestId('admin-mcp-server-7-status-pill');
        expect(pill).toHaveTextContent(/Active/i);
    });

    it('shows the tools-discovered count from the handshake response', () => {
        render(<HandshakeStatus server={makeServer()} onRetry={() => {}} busy={false} />);
        const summary = screen.getByTestId('admin-mcp-server-7-handshake-summary');
        expect(summary).toHaveTextContent(/Tools discovered:\s*2/i);
    });

    it('renders the error message for errored servers', () => {
        render(
            <HandshakeStatus
                server={makeServer({
                    status: 'errored',
                    handshake_response: { status: 'error', message: 'Stdio MCP server crashed at startup' },
                })}
                onRetry={() => {}}
                busy={false}
            />,
        );
        const error = screen.getByTestId('admin-mcp-server-7-handshake-error');
        expect(error).toHaveTextContent('Stdio MCP server crashed at startup');
    });

    it('disables the retry button while busy', () => {
        render(<HandshakeStatus server={makeServer()} onRetry={() => {}} busy={true} />);
        const button = screen.getByTestId('admin-mcp-server-7-handshake-retry');
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent(/Handshaking…/i);
    });

    it('invokes onRetry when the retry button is clicked', () => {
        const onRetry = vi.fn();
        render(<HandshakeStatus server={makeServer()} onRetry={onRetry} busy={false} />);
        fireEvent.click(screen.getByTestId('admin-mcp-server-7-handshake-retry'));
        expect(onRetry).toHaveBeenCalledOnce();
    });

    it('shows "Not yet contacted" for never-handshaked pending servers', () => {
        render(
            <HandshakeStatus
                server={makeServer({ status: 'pending', last_handshake_at: null, handshake_response: null })}
                onRetry={() => {}}
                busy={false}
            />,
        );
        expect(screen.getByText(/Not yet contacted/i)).toBeInTheDocument();
    });

    it('renders the Disabled pill for disabled servers', () => {
        render(
            <HandshakeStatus
                server={makeServer({ status: 'disabled' })}
                onRetry={() => {}}
                busy={false}
            />,
        );
        const pill = screen.getByTestId('admin-mcp-server-7-status-pill');
        expect(pill).toHaveTextContent(/Disabled/i);
    });
});
