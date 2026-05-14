import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

import { ToolMatrix } from './ToolMatrix';
import type { McpServerEntry } from './mcp-tools.api';

function makeServer(overrides: Partial<McpServerEntry> = {}): McpServerEntry {
    return {
        id: 11,
        name: 'github',
        transport: 'stdio',
        endpoint: 'cmd',
        enabled_tools: ['list_repositories'],
        status: 'active',
        last_handshake_at: '2026-05-14T08:00:00Z',
        handshake_response: {
            tools: [
                { name: 'list_repositories' },
                { name: 'create_issue' },
                { name: 'delete_repo' },
            ],
        },
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('ToolMatrix', () => {
    it('renders an "empty" message when no tools have been discovered', () => {
        render(
            <ToolMatrix
                server={makeServer({ handshake_response: null })}
                onSave={() => {}}
                busy={false}
            />,
        );
        expect(screen.getByTestId('admin-mcp-server-11-tool-matrix-empty')).toBeInTheDocument();
    });

    it('lists every discovered tool with its current state', () => {
        render(<ToolMatrix server={makeServer()} onSave={() => {}} busy={false} />);
        expect(screen.getByTestId('admin-mcp-server-11-tool-list_repositories')).toBeChecked();
        expect(screen.getByTestId('admin-mcp-server-11-tool-create_issue')).not.toBeChecked();
        expect(screen.getByTestId('admin-mcp-server-11-tool-delete_repo')).not.toBeChecked();
    });

    it('disables individual checkboxes when "Allow all" is on', () => {
        render(
            <ToolMatrix
                server={makeServer({ enabled_tools: ['*'] })}
                onSave={() => {}}
                busy={false}
            />,
        );
        expect(screen.getByTestId('admin-mcp-server-11-allow-all')).toBeChecked();
        expect(screen.getByTestId('admin-mcp-server-11-tool-create_issue')).toBeDisabled();
    });

    it('Save invokes onSave with the new selection when dirty', () => {
        const onSave = vi.fn();
        render(<ToolMatrix server={makeServer()} onSave={onSave} busy={false} />);
        fireEvent.click(screen.getByTestId('admin-mcp-server-11-tool-create_issue'));
        const saveButton = screen.getByTestId('admin-mcp-server-11-tool-matrix-save');
        expect(saveButton).not.toBeDisabled();
        fireEvent.click(saveButton);
        expect(onSave).toHaveBeenCalledOnce();
        expect(onSave.mock.calls[0][0]).toEqual(expect.arrayContaining(['list_repositories', 'create_issue']));
    });

    it('Reset restores the saved selection (clean state)', () => {
        render(<ToolMatrix server={makeServer()} onSave={() => {}} busy={false} />);
        fireEvent.click(screen.getByTestId('admin-mcp-server-11-tool-create_issue'));
        // Save button enabled — dirty state
        expect(screen.getByTestId('admin-mcp-server-11-tool-matrix-save')).not.toBeDisabled();
        fireEvent.click(screen.getByTestId('admin-mcp-server-11-tool-matrix-reset'));
        // Reset → save disabled again (back to baseline)
        expect(screen.getByTestId('admin-mcp-server-11-tool-matrix-save')).toBeDisabled();
    });

    it('Save is disabled while busy', () => {
        render(<ToolMatrix server={makeServer()} onSave={() => {}} busy={true} />);
        expect(screen.getByTestId('admin-mcp-server-11-tool-matrix-save')).toBeDisabled();
    });

    it('Allow-all checkbox switches the selection to wildcard', () => {
        const onSave = vi.fn();
        render(<ToolMatrix server={makeServer()} onSave={onSave} busy={false} />);
        fireEvent.click(screen.getByTestId('admin-mcp-server-11-allow-all'));
        // Dirty + save
        fireEvent.click(screen.getByTestId('admin-mcp-server-11-tool-matrix-save'));
        expect(onSave).toHaveBeenCalledWith(['*']);
    });
});
