import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, cleanup } from '@testing-library/react';
import { ColumnResizeHandle, useFileColumnWidth } from './ColumnResizeHandle';
import { fileKind } from './FileTypeIcon';
import { TreeView } from './TreeView';
import type { KbTreeDocNode } from '../admin.api';

const email: KbTreeDocNode = { type: 'doc', name: 'message.md', path: 'team/connectors/imap/inbox/message.md', meta: {
    id: 1, project_key: 'team', slug: null, is_canonical: false, canonical_type: null,
    canonical_status: null, indexed_at: null, deleted_at: null,
} };
afterEach(() => { cleanup(); localStorage.clear(); });

describe('File browser', () => {
    it('recognizes connector emails even when stored as markdown, with extension fallbacks', () => {
        expect(fileKind(email)).toBe('Email');
        expect(fileKind({ ...email, path: 'docs/report.pdf', name: 'report.pdf' })).toBe('PDF');
        expect(fileKind({ ...email, path: 'docs/data.xlsx', name: 'data.xlsx' })).toBe('Spreadsheet');
    });
    it('searches server pages without re-filtering them and exposes bounded navigation', () => {
        const next = vi.fn();
        render(<TreeView data={{ tree: [email], counts: { docs: 120000, canonical: 0, trashed: 0 }, generated_at: '',
            pagination: { loaded: 200, limit: 200, has_more: true, next_cursor: 200 } }}
            state="ready" q="pending search" onQChange={vi.fn()} mode="all" onModeChange={vi.fn()}
            withTrashed={false} onWithTrashedChange={vi.fn()} selectedPath={null} onSelect={vi.fn()}
            serverSearch pageNumber={1} onNextPage={next} />);
        expect(screen.getByRole('treeitem')).toHaveTextContent('message.md');
        expect(screen.getByRole('button', { name: 'Previous documents' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Next documents' }));
        expect(next).toHaveBeenCalledOnce();
    });
    it('resizes by keyboard, clamps the width and persists it between mounts', () => {
        function Harness() {
            const column = useFileColumnWidth();
            return <ColumnResizeHandle width={column.width} onChange={column.update} />;
        }
        const first = render(<Harness />);
        fireEvent.keyDown(screen.getByRole('separator'), { key: 'ArrowRight' });
        expect(screen.getByRole('separator')).toHaveAttribute('aria-valuenow', '320');
        first.unmount();
        render(<Harness />);
        expect(screen.getByRole('separator')).toHaveAttribute('aria-valuenow', '320');
        fireEvent.keyDown(screen.getByRole('separator'), { key: 'End' });
        fireEvent.keyDown(screen.getByRole('separator'), { key: 'ArrowRight' });
        expect(screen.getByRole('separator')).toHaveAttribute('aria-valuenow', '560');
    });
});
