import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { LiveSourceCatalog } from './chat.api';
import { LiveSourcesControl } from './LiveSourcesControl';

const sources: LiveSourceCatalog = {
    mcp: [
        { key: 'mcp:hubhive', kind: 'mcp', name: 'HubHive', description: null, project_key: 'date', tool_count: 3 },
        { key: 'mcp:gescat', kind: 'mcp', name: 'Gescat', description: null, project_key: null, tool_count: 5 },
    ],
    api: [
        { key: 'api:7', kind: 'api', name: 'Commerce API', description: null, project_key: 'date', tool_count: 4 },
    ],
};

describe('LiveSourcesControl', () => {
    it('starts with every available live source enabled', () => {
        render(<LiveSourcesControl sources={sources} onChange={() => undefined} />);

        expect(screen.getByRole('button', { name: 'MCP sources: 2 of 2 enabled' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'API sources: 1 of 1 enabled' })).toBeInTheDocument();
    });

    it('can disable one connection without affecting the other connections', () => {
        const onChange = vi.fn();
        render(
            <LiveSourcesControl
                sources={sources}
                selection={{ mcp: ['mcp:hubhive', 'mcp:gescat'], api: ['api:7'] }}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByTestId('chat-live-source-mcp-trigger'));
        fireEvent.click(screen.getByTestId('chat-live-source-option-mcp:hubhive'));

        expect(onChange).toHaveBeenCalledWith('mcp', ['mcp:gescat']);
    });

    it('offers a single action to disable a whole source kind', () => {
        const onChange = vi.fn();
        render(<LiveSourcesControl sources={sources} onChange={onChange} />);

        fireEvent.click(screen.getByTestId('chat-live-source-api-trigger'));
        fireEvent.click(screen.getByRole('switch', { name: /Disable all/i }));

        expect(onChange).toHaveBeenCalledWith('api', []);
    });

    it('keeps a large connection catalog bounded and searchable', () => {
        const manySources: LiveSourceCatalog = {
            api: [],
            mcp: Array.from({ length: 12 }, (_, index) => ({
                key: `mcp:server-${index}`,
                kind: 'mcp' as const,
                name: `Server ${index}`,
                description: null,
                project_key: null,
                tool_count: index + 1,
            })),
        };
        render(<LiveSourcesControl sources={manySources} onChange={() => undefined} />);

        fireEvent.click(screen.getByTestId('chat-live-source-mcp-trigger'));
        const search = screen.getByRole('searchbox', { name: 'Search MCP connections' });
        fireEvent.change(search, { target: { value: 'Server 11' } });

        expect(screen.getByText('Server 11')).toBeInTheDocument();
        expect(screen.queryByText('Server 2')).not.toBeInTheDocument();
    });
});
