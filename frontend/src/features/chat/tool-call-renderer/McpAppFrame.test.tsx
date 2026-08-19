import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { McpAppFrame } from './McpAppFrame';

interface MockBridgeInstance {
    onsandboxready?: (params: Record<string, never>) => void;
    oninitialized?: (params: Record<string, never>) => void;
    onsizechange?: (params: { height?: number; width?: number }) => void;
    oncalltool?: (params: { name: string; arguments?: Record<string, unknown> }) => Promise<unknown>;
    sendSandboxResourceReady: ReturnType<typeof vi.fn>;
    sendToolInput: ReturnType<typeof vi.fn>;
    sendToolResult: ReturnType<typeof vi.fn>;
    connect: ReturnType<typeof vi.fn>;
}

const { getMock, postMock, bridgeInstances, transportCloseMock } = vi.hoisted(() => ({
    getMock: vi.fn(),
    postMock: vi.fn(),
    bridgeInstances: [] as MockBridgeInstance[],
    transportCloseMock: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../../../lib/api', () => ({ api: { get: getMock, post: postMock } }));
vi.mock('@modelcontextprotocol/ext-apps/app-bridge', () => ({
    buildAllowAttribute: vi.fn(() => 'camera'),
    PostMessageTransport: class {
        close = transportCloseMock;
    },
    AppBridge: class {
        onsandboxready?: (params: Record<string, never>) => void;
        oninitialized?: (params: Record<string, never>) => void;
        onsizechange?: (params: { height?: number; width?: number }) => void;
        oncalltool?: (params: { name: string; arguments?: Record<string, unknown> }) => Promise<unknown>;
        sendSandboxResourceReady = vi.fn().mockResolvedValue(undefined);
        sendToolInput = vi.fn().mockResolvedValue(undefined);
        sendToolResult = vi.fn().mockResolvedValue(undefined);
        connect = vi.fn().mockResolvedValue(undefined);
        teardownResource = vi.fn().mockResolvedValue({});

        constructor() {
            bridgeInstances.push(this);
        }
    },
}));

const handle = {
    id: '01MCPAPP',
    resource_uri: 'ui://reports/result.html',
    fallback: 'Static report fallback.',
};

function appResource(overrides: Record<string, unknown> = {}) {
    return {
        app_id: handle.id,
        available: true,
        sandbox_url: 'https://mcp-sandbox.example.test/mcp-apps/sandbox',
        html: '<!doctype html><html><body>Report</body></html>',
        csp: { connectDomains: ['https://api.example.test'] },
        permissions: { camera: {} },
        prefers_border: true,
        description: 'Interactive report',
        tool_input: { period: 'week' },
        tool_result: {
            content: [{ type: 'text', text: 'Fresh report.' }],
            structuredContent: { total: 42 },
            _meta: { widgetState: 'private' },
        },
        ...overrides,
    };
}

describe('McpAppFrame', () => {
    beforeEach(() => {
        bridgeInstances.splice(0);
        getMock.mockReset();
        postMock.mockReset();
        transportCloseMock.mockClear();
    });

    it('uses the safe textual fallback when the sandbox is unavailable', async () => {
        getMock.mockResolvedValueOnce({
            data: {
                app_id: handle.id,
                available: false,
                fallback: 'A dedicated sandbox origin is required.',
            },
        });

        render(<McpAppFrame app={handle} conversationId={17} />);

        expect(await screen.findByText('A dedicated sandbox origin is required.')).toBeInTheDocument();
        expect(screen.queryByTitle('Interactive MCP App')).not.toBeInTheDocument();
        expect(getMock).toHaveBeenCalledWith('/api/conversations/mcp/apps/01MCPAPP', {
            params: { conversation_id: '17' },
        });
    });

    it('loads the double sandbox only after connecting the MCP Apps bridge', async () => {
        getMock.mockResolvedValueOnce({ data: appResource() });

        render(<McpAppFrame app={handle} conversationId={17} />);

        const frame = await screen.findByTestId('mcp-app-01MCPAPP-frame');
        await waitFor(() => expect(bridgeInstances).toHaveLength(1));
        const bridge = bridgeInstances[0];
        await waitFor(() => expect(bridge.connect).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(frame).toHaveAttribute(
            'src',
            'https://mcp-sandbox.example.test/mcp-apps/sandbox',
        ));
        expect(frame).toHaveAttribute('sandbox', 'allow-scripts allow-same-origin');
        expect(frame).toHaveAttribute('allow', 'camera');

        bridge.onsandboxready?.({});
        await waitFor(() => expect(bridge.sendSandboxResourceReady).toHaveBeenCalledWith({
            html: '<!doctype html><html><body>Report</body></html>',
            sandbox: 'allow-scripts',
            csp: { connectDomains: ['https://api.example.test'] },
            permissions: { camera: {} },
        }));

        bridge.oninitialized?.({});
        await waitFor(() => expect(bridge.sendToolInput).toHaveBeenCalledWith({ arguments: { period: 'week' } }));
        await waitFor(() => expect(bridge.sendToolResult).toHaveBeenCalledWith(expect.objectContaining({
            structuredContent: { total: 42 },
            _meta: { widgetState: 'private' },
        })));
        await waitFor(() => expect(frame).toHaveStyle({ display: 'block' }));

        act(() => bridge.onsizechange?.({ height: 5_000 }));
        await waitFor(() => expect(frame).toHaveStyle({ height: '900px' }));
    });

    it('routes app-initiated tool calls through the scoped backend proxy', async () => {
        getMock.mockResolvedValueOnce({ data: appResource() });
        postMock.mockResolvedValueOnce({
            data: {
                status: 'completed',
                result: {
                    content: [{ type: 'text', text: 'Page refreshed.' }],
                    structuredContent: { page: 2 },
                },
            },
        });

        render(<McpAppFrame app={handle} conversationId={21} />);
        await waitFor(() => expect(bridgeInstances).toHaveLength(1));
        const result = await bridgeInstances[0].oncalltool?.({
            name: 'reports.refresh',
            arguments: { page: 2 },
        });

        expect(postMock).toHaveBeenCalledWith('/api/conversations/mcp/apps/01MCPAPP/tools/call', {
            conversation_id: '21',
            name: 'reports.refresh',
            arguments: { page: 2 },
        });
        expect(result).toMatchObject({ structuredContent: { page: 2 }, isError: false });
    });

    it('suspends an app tool request until the user confirms it', async () => {
        getMock.mockResolvedValueOnce({ data: appResource() });
        postMock
            .mockResolvedValueOnce({
                data: {
                    status: 'confirmation_required',
                    pending_interaction_id: '01PENDING',
                    prompt: { message: 'Confirm report publication.' },
                },
            })
            .mockResolvedValueOnce({
                data: {
                    status: 'completed',
                    artifact: {
                        text: 'Report published.',
                        structuredContent: { published: true },
                        provenance: { connection_id: 'must-not-reach-the-app' },
                    },
                },
            });

        render(<McpAppFrame app={handle} conversationId={22} />);
        await waitFor(() => expect(bridgeInstances).toHaveLength(1));
        const pendingResult = bridgeInstances[0].oncalltool?.({ name: 'reports.publish', arguments: {} });

        expect(await screen.findByText('Confirm report publication.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(postMock).toHaveBeenLastCalledWith(
            '/api/conversations/mcp/interactions/01PENDING',
            { conversation_id: '22', response: { confirmed: true } },
        ));
        await expect(pendingResult).resolves.toMatchObject({
            content: [{ type: 'text', text: 'Report published.' }],
            structuredContent: { published: true },
            isError: false,
        });
        await expect(pendingResult).resolves.not.toHaveProperty('provenance');
    });

    it('rejects a sandbox configured on the AskMyDocs origin', async () => {
        getMock.mockResolvedValueOnce({
            data: appResource({ sandbox_url: `${window.location.origin}/mcp-apps/sandbox` }),
        });

        render(<McpAppFrame app={handle} conversationId={17} />);

        expect(await screen.findByRole('alert')).toHaveTextContent('not valid or is not isolated');
        expect(bridgeInstances).toHaveLength(0);
    });
});
