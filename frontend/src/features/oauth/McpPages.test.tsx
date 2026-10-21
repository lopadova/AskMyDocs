import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { chooseSelectOption } from '@/test-utils/select';
import { McpConnectPage } from './McpConnectPage';
import { McpImportReviewPage, type ImportReview } from './McpImportReviewPage';
import { api, ensureCsrfCookie } from '../../lib/api';

vi.mock('../../lib/api', () => ({ api: { get: vi.fn(), post: vi.fn() }, ensureCsrfCookie: vi.fn() }));
vi.mock('../auth/LoginPage', () => ({ LoginPage: () => <p>Sign in to continue</p> }));
const review: ImportReview = { import_id: 'one', tenant_id: 'acme', project_key: 'engineering', status: 'awaiting_approval', ready: false,
    documents: [{ filename: 'spec.md', bytes: 48, sha256: 'abc', preview: '<script>unsafe()</script>' }],
    items: [{ filename: 'spec.md', status: 'staged', error: null, document_id: null }] };

beforeEach(() => { vi.resetAllMocks(); vi.mocked(ensureCsrfCookie).mockResolvedValue(undefined); });

describe('MCP connection setup', () => {
    it('creates a connection only after activation and exposes its download and unique URL', async () => {
        vi.mocked(api.post).mockResolvedValue({ data: { id: 'one', mcp_url: 'https://askmydocs.test/mcp/knowledge/one', download_url: '/api/oauth/mcp-connections/one/plugin.zip' } });
        render(<McpConnectPage />);
        expect(api.post).not.toHaveBeenCalled();
        await userEvent.click(screen.getByRole('button', { name: 'Prepare connection' }));
        expect(api.post).toHaveBeenCalledWith('/api/oauth/mcp-connections');
        expect(await screen.findByRole('link', { name: 'Download Codex plugin' })).toHaveAttribute('href', '/api/oauth/mcp-connections/one/plugin.zip');
        expect(screen.getByText('https://askmydocs.test/mcp/knowledge/one')).toBeInTheDocument();
        await userEvent.click(screen.getByText('Connect through the Codex CLI'));
        expect(screen.getByText("codex mcp add 'askmydocs-one' --url 'https://askmydocs.test/mcp/knowledge/one'")).toBeInTheDocument();
        expect(screen.getByText("codex mcp login 'askmydocs-one'")).toBeInTheDocument();
    });
    it('asks for sign-in on an expired session', async () => {
        vi.mocked(api.post).mockRejectedValue({ isAxiosError: true, response: { status: 401 } });
        render(<McpConnectPage />);
        await userEvent.click(screen.getByRole('button', { name: 'Prepare connection' }));
        expect(await screen.findByText('Sign in to continue')).toBeInTheDocument();
    });
    it('keeps one URL when switching client instructions and generates distinct names for another connection', async () => {
        const user = userEvent.setup();
        vi.mocked(api.post).mockResolvedValueOnce({ data: { id: 'one', mcp_url: 'https://askmydocs.test/mcp/knowledge/one' } })
            .mockResolvedValueOnce({ data: { id: 'two', mcp_url: 'https://askmydocs.test/mcp/knowledge/two' } });
        render(<McpConnectPage />);
        await chooseSelectOption(screen.getByLabelText('Application'), 'claude-code');
        expect(api.post).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Prepare connection' }));
        expect(await screen.findByText("claude mcp add --transport http 'askmydocs-one' 'https://askmydocs.test/mcp/knowledge/one'")).toBeInTheDocument();
        await chooseSelectOption(screen.getByLabelText('Application'), 'cursor');
        await user.click(screen.getByRole('button', { name: 'Copy configuration' }));
        expect(JSON.parse(await navigator.clipboard.readText())).toEqual({ mcpServers: {
            'askmydocs-one': { url: 'https://askmydocs.test/mcp/knowledge/one' },
        } });
        expect(api.post).toHaveBeenCalledTimes(1);
        await user.click(screen.getByRole('button', { name: 'Prepare another connection' }));
        await user.click(screen.getByRole('button', { name: 'Copy configuration' }));
        expect(JSON.parse(await navigator.clipboard.readText())).toEqual({ mcpServers: {
            'askmydocs-two': { url: 'https://askmydocs.test/mcp/knowledge/two' },
        } });
    });
    it('explains Claude cloud reachability and automatic registration without manual OAuth credentials', async () => {
        vi.mocked(api.post).mockResolvedValue({ data: { id: 'one', mcp_url: 'https://askmydocs.test/mcp/knowledge/one' } });
        render(<McpConnectPage />);
        await chooseSelectOption(screen.getByLabelText('Application'), 'claude');
        expect(screen.getByRole('note')).toHaveTextContent('Anthropic cannot reach a local .test address.');
        await userEvent.click(screen.getByRole('button', { name: 'Prepare connection' }));
        expect(await screen.findByText(/Choose Sign in now and Register automatically/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Download Codex plugin' })).not.toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });
});

describe('MCP import approval', () => {
    it('renders the destination and untrusted text safely and never approves during load', async () => {
        vi.mocked(api.get).mockResolvedValue({ data: review });
        render(<McpImportReviewPage importId="one" />);
        expect(await screen.findByText('acme / engineering')).toBeInTheDocument();
        expect(screen.getByText('<script>unsafe()</script>')).toBeInTheDocument();
        expect(document.querySelector('script')).toBeNull();
        expect(api.post).not.toHaveBeenCalled();
    });
    it('approves the opaque server import handle only after explicit user activation', async () => {
        vi.mocked(api.get).mockResolvedValue({ data: review });
        vi.mocked(api.post).mockResolvedValue({ data: { ...review, status: 'processing' } });
        render(<McpImportReviewPage importId="one" />);
        await userEvent.click(await screen.findByRole('button', { name: 'Approve and index documents' }));
        expect(ensureCsrfCookie).toHaveBeenCalled();
        expect(api.post).toHaveBeenCalledWith('/api/oauth/mcp-imports/one/confirm');
        expect(await screen.findByRole('button', { name: 'Refresh status' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Approve and index documents' })).not.toBeInTheDocument();
    });
    it('keeps unavailable imports from presenting an approval action', async () => {
        vi.mocked(api.get).mockRejectedValue({ isAxiosError: true, response: { status: 403 } });
        render(<McpImportReviewPage importId="one" />);
        expect(await screen.findByRole('alert')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Approve and index documents' })).not.toBeInTheDocument();
    });
});
