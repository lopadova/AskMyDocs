import { useState } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LiveSourceCatalog, LiveSourceSelection } from './chat.api';
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

// jsdom has no layout. Give Radix a visible reference and viewport so
// hideWhenDetached does not hide every popup; collision geometry is browser-tested.
beforeEach(() => {
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(1024);
    vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockReturnValue(768);
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
        if (this.classList.contains('chat-live-source-trigger')) return new DOMRect(16, 600, 100, 32);
        if (this.classList.contains('chat-live-source-popover')) return new DOMRect(16, 300, 340, 240);
        return new DOMRect(0, 0, 1024, 768);
    });
});
afterEach(() => vi.restoreAllMocks());

describe('LiveSourcesControl', () => {
    it('starts with every available live source enabled', () => {
        render(<LiveSourcesControl sources={sources} onChange={() => undefined} />);

        expect(screen.getByRole('button', { name: 'MCP sources: 2 of 2 enabled' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'API sources: 1 of 1 enabled' })).toBeInTheDocument();
    });

    it('can disable one connection without affecting the other connections', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(
            <LiveSourcesControl
                sources={sources}
                selection={{ mcp: ['mcp:hubhive', 'mcp:gescat'], api: ['api:7'] }}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByTestId('chat-live-source-mcp-trigger'));
        await user.click(screen.getByTestId('chat-live-source-option-mcp:hubhive'));

        expect(onChange).toHaveBeenCalledWith('mcp', ['mcp:gescat']);
    });

    it('offers a single action to disable a whole source kind', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<LiveSourcesControl sources={sources} onChange={onChange} />);

        await user.click(screen.getByTestId('chat-live-source-api-trigger'));
        await user.click(screen.getByRole('switch', { name: /Disable all/i }));

        expect(onChange).toHaveBeenCalledWith('api', []);
    });

    it('keeps a large connection catalog bounded and searchable', async () => {
        const user = userEvent.setup();
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

        await user.click(screen.getByTestId('chat-live-source-mcp-trigger'));
        const search = screen.getByRole('searchbox', { name: 'Search MCP connections' });
        await user.type(search, 'Server 11');

        expect(screen.getByText('Server 11')).toBeInTheDocument();
        expect(screen.queryByText('Server 2')).not.toBeInTheDocument();
    });

    it('renders its dialog outside a clipped control container', async () => {
        const user = userEvent.setup();
        render(<div data-testid="clipped-container" style={{ overflow: 'hidden', width: 100 }}>
            <LiveSourcesControl sources={sources} onChange={() => undefined} />
        </div>);
        const trigger = screen.getByTestId('chat-live-source-api-trigger');
        await user.click(trigger);
        const dialog = screen.getByRole('dialog', { name: 'API source settings' });
        expect(screen.getByTestId('clipped-container')).not.toContainElement(dialog);
        expect(document.body).toContainElement(dialog);
        expect(trigger).toHaveAttribute('aria-controls', dialog.id);
    });

    it('keeps the portalled dialog open while source switches update controlled state', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        function ControlledSources() {
            const [selection, setSelection] = useState<LiveSourceSelection>({ mcp: ['mcp:hubhive', 'mcp:gescat'], api: ['api:7'] });
            return <LiveSourcesControl sources={sources} selection={selection} onChange={(kind, keys) => {
                onChange(kind, keys);
                setSelection(current => ({ ...current, [kind]: keys }));
            }} />;
        }
        render(<ControlledSources />);
        await user.click(screen.getByTestId('chat-live-source-mcp-trigger'));
        const connection = screen.getByTestId('chat-live-source-option-mcp:hubhive');
        await user.click(connection);
        expect(connection).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('dialog', { name: 'MCP source settings' })).toBeVisible();
        expect(screen.getByTestId('chat-live-source-api-trigger')).toHaveAccessibleName('API sources: 1 of 1 enabled');
        await user.click(screen.getByTestId('chat-live-source-mcp-toggle-all'));
        expect(connection).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('dialog', { name: 'MCP source settings' })).toBeVisible();
        expect(onChange.mock.calls).toEqual([
            ['mcp', ['mcp:gescat']],
            ['mcp', ['mcp:hubhive', 'mcp:gescat']],
        ]);
    });

    it.each(['Escape', 'Close'])('returns focus to its trigger after %s closes the dialog', async (action) => {
        const user = userEvent.setup();
        render(<LiveSourcesControl sources={sources} onChange={() => undefined} />);
        const trigger = screen.getByTestId('chat-live-source-api-trigger');
        await user.click(trigger);
        expect(screen.getByRole('dialog', { name: 'API source settings' })).toBeVisible();
        if (action === 'Escape') await user.keyboard('{Escape}');
        else await user.click(screen.getByTestId('chat-live-source-api-close'));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await waitFor(() => expect(trigger).toHaveFocus());
    });

    it('dismisses on an outside click without taking focus from the clicked action', async () => {
        const user = userEvent.setup();
        const outsideAction = vi.fn();
        render(<><LiveSourcesControl sources={sources} onChange={() => undefined} />
            <button onClick={outsideAction}>Other action</button>
        </>);
        await user.click(screen.getByTestId('chat-live-source-api-trigger'));
        const outside = screen.getByRole('button', { name: 'Other action' });
        await user.click(outside);
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(outside).toHaveFocus();
        expect(outsideAction).toHaveBeenCalledTimes(1);
    });

    it('closes when disabled and remains closed after being enabled again', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const { rerender } = render(<LiveSourcesControl sources={sources} onChange={onChange} />);
        const trigger = screen.getByTestId('chat-live-source-api-trigger');
        await user.click(trigger);
        expect(screen.getByRole('dialog', { name: 'API source settings' })).toBeVisible();
        rerender(<LiveSourcesControl sources={sources} disabled onChange={onChange} />);
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(trigger).toBeDisabled();
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        rerender(<LiveSourcesControl sources={sources} onChange={onChange} />);
        expect(trigger).toBeEnabled();
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
    });
});
