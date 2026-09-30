import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Markdown } from './Markdown';

describe('Markdown answer presentation', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('opts into editorial typography only for answers and preserves semantic headings and lists', () => {
        const source = '## Spedizione\n\nStato **confermato** per `RL-TRACK-9355`.\n\n- Primo evento\n- Secondo evento\n\n### Prossimi passi\n\n3. Verifica ordine\n4. Contatta cliente';
        const { container, rerender } = render(<Markdown source={source} />);
        expect(container.firstChild).not.toHaveClass('markdown-body--answer');
        rerender(<Markdown source={source} variant="answer" />);
        expect(container.firstChild).toHaveClass('markdown-body--answer');
        expect(screen.getByRole('heading', { level: 2, name: 'Spedizione' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 3, name: 'Prossimi passi' })).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(4);
        expect(container.querySelector('ol')).toHaveAttribute('start', '3');
        expect(container.querySelector('code')).toHaveTextContent('RL-TRACK-9355');
        expect(container.querySelector('strong')).toHaveTextContent('confermato');
    });

    it('keeps GFM tables accessible and independently scrollable without losing alignment or values', () => {
        render(<Markdown variant="answer" source={'| Ordine | Importo |\n| :--- | ---: |\n| PO-5582 | 1.250,00 € |'} />);
        const region = screen.getByRole('region', { name: 'Data table' });
        expect(region).toHaveAttribute('tabindex', '0');
        expect(region).toHaveClass('markdown-table-scroll');
        expect(within(region).getByRole('table')).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Importo' })).toHaveStyle({ textAlign: 'right' });
        expect(screen.getByRole('cell', { name: '1.250,00 €' })).toHaveStyle({ textAlign: 'right' });
    });

    it('preserves nested lists and read-only checklists without inventing sections', () => {
        const { container } = render(<Markdown variant="answer" source={'Un messaggio breve.\n\n- Cliente\n  - Ordine\n\n- [x] Verificato\n- [ ] In attesa'} />);
        expect(screen.queryByRole('heading')).not.toBeInTheDocument();
        expect(container.querySelector('ul ul')).toHaveTextContent('Ordine');
        const boxes = screen.getAllByRole('checkbox');
        expect(boxes[0]).toBeChecked();
        expect(boxes[1]).not.toBeChecked();
        boxes.forEach(box => expect(box).toBeDisabled());
    });

    it('copies the original code with a canonical button and announces success', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        render(<Markdown variant="answer" source={'```json\n{"order": "PO-5582"}\n```'} />);
        expect(screen.getByLabelText('json code')).toHaveAttribute('tabindex', '0');
        const button = screen.getByRole('button', { name: 'Copy code' });
        expect(button).toHaveClass('ui-button');
        fireEvent.click(button);
        await waitFor(() => expect(writeText).toHaveBeenCalledWith('{"order": "PO-5582"}\n'));
        expect(screen.getByRole('status')).toHaveTextContent('Copied');
    });

    it.each([undefined, { writeText: vi.fn().mockRejectedValue(new Error('denied')) }])('does not claim a failed copy succeeded', async (clipboard) => {
        vi.stubGlobal('navigator', { clipboard });
        render(<Markdown source={'```text\nunchanged\n```'} />);
        fireEvent.click(screen.getByRole('button', { name: 'Copy code' }));
        await waitFor(() => expect(screen.getByTestId('markdown-codeblock-copy')).toHaveAttribute('data-state', 'idle'));
    });

    it('preserves content as a streamed code block becomes complete', () => {
        const { rerender } = render(<Markdown variant="answer" source={'## Risultato\n\n```json\n{"status":'} />);
        expect(screen.getByLabelText('json code')).toHaveTextContent('{"status":');
        rerender(<Markdown variant="answer" source={'## Risultato\n\n```json\n{"status": "ready"}\n```\n\nFonte verificata.'} />);
        expect(screen.getAllByTestId('markdown-codeblock')).toHaveLength(1);
        expect(screen.getByLabelText('json code').textContent).toBe('{"status": "ready"}\n');
        expect(screen.getByText('Fonte verificata.')).toBeInTheDocument();
    });

    it('renders callouts with semantic emphasis and a decorative SVG, preserving the original message', () => {
        render(<Markdown variant="answer" source={'> [!warning] Dato da verificare\n> Il corriere non ha confermato la consegna.'} />);
        const callout = screen.getByTestId('chat-callout-warning');
        expect(callout).toHaveClass('markdown-callout');
        expect(callout).toHaveAttribute('data-kind', 'warning');
        expect(within(callout).getByText('Dato da verificare').tagName).toBe('STRONG');
        expect(callout.querySelector('svg')?.parentElement).toHaveAttribute('aria-hidden', 'true');
        expect(callout).toHaveTextContent('Il corriere non ha confermato la consegna.');
    });

    it('does not introduce HTML execution or unsafe link targets', () => {
        const { container } = render(<Markdown variant="answer" source={'<script>alert(1)</script>\n\n[unsafe](javascript:alert%281%29)\n\nTesto normale.'} />);
        expect(container.querySelector('script')).toBeNull();
        expect(screen.getByText('unsafe')).not.toHaveAttribute('href', expect.stringContaining('javascript:'));
        expect(screen.getByText('Testo normale.')).toBeInTheDocument();
    });
});
