import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { findingEvent } from './progressive-answer.test-fixtures';
import { progressiveFindings } from '../agent/progressive-findings';
import { ProgressiveAnswer } from './ProgressiveAnswer';


describe('progressive findings', () => {
    it('shows only independently accepted findings from the current run and deduplicates replay', () => {
        expect(progressiveFindings([findingEvent, findingEvent])).toHaveLength(1);
        expect(progressiveFindings([{ ...findingEvent, data: { ...findingEvent.data, validation: { status: 'pending' } } }])).toEqual([]);
        expect(progressiveFindings([{ ...findingEvent, type: 'tool.completed' }])).toEqual([]);
        expect(progressiveFindings([findingEvent, { ...findingEvent, run_id: 'other', type: 'run.started', data: {} }])).toEqual([]);
    });

    it('keeps the source passage open and focus stable as the next finding arrives', () => {
        const open = vi.fn();
        const { rerender, container } = render(<ProgressiveAnswer events={[findingEvent]} active onOpenSource={open} />);
        expect(screen.getByRole('status')).toHaveTextContent('Sono in arrivo altri dati');
        const summary = screen.getByText('Passaggio della fonte');
        fireEvent.click(summary);
        const details = container.querySelector('details')!;
        details.open = true;
        summary.focus();
        const next = { ...findingEvent, sequence: 2, data: { ...findingEvent.data, findings: [{
            id: 'second', text: 'Alle 15:38 il cliente conferma di aver trovato il barcode.',
            quote: 'Entschuldigung. Barcode ist da.', citation: { document_id: 12, title: 'Email del 3 luglio, 15:38', source_path: null },
        }] } };
        rerender(<ProgressiveAnswer events={[findingEvent, next]} active onOpenSource={open} />);
        expect(container.querySelectorAll('li')).toHaveLength(2);
        expect(container.querySelector('details')).toBe(details);
        expect(details.open).toBe(true);
        expect(document.activeElement).toBe(summary);
        fireEvent.click(screen.getByRole('button', { name: 'Apri fonte: Email del 3 luglio, 13:52' }));
        expect(open).toHaveBeenCalledWith(expect.objectContaining({ document_id: 11 }));
    });

    it.each(['run.failed', 'run.cancelled'])('retains findings after %s', (type) => {
        render(<ProgressiveAnswer events={[findingEvent, { ...findingEvent, sequence: 2, type, data: {} }]} active={false} />);
        expect(screen.getByRole('status')).toHaveTextContent('Ricerca interrotta · Risultati parziali');
        expect(screen.getByText('Il cliente segnala il barcode mancante il 3 luglio.')).toBeInTheDocument();
    });

    it('does not claim the worker stopped when only event delivery was interrupted', () => {
        render(<ProgressiveAnswer events={[findingEvent]} active={false} />);
        expect(screen.getByRole('status')).toHaveTextContent('Aggiornamenti interrotti');
        expect(screen.getByRole('status')).not.toHaveTextContent('Ricerca interrotta');
    });

    it('distinguishes a pause from final consolidation and renders source content as plain text', () => {
        const unsafe = { ...findingEvent, locale: 'en', data: { ...findingEvent.data, findings: [{
            id: 'html', text: '<img src=x onerror=alert(1)>', quote: '<script>bad()</script>',
            citation: { document_id: 11, title: 'Source', source_path: null },
        }] } };
        const { rerender, container } = render(<ProgressiveAnswer events={[unsafe]} active={false} paused />);
        expect(screen.getByRole('status')).toHaveTextContent('Research paused');
        expect(container.querySelector('img, script')).toBeNull();
        rerender(<ProgressiveAnswer events={[unsafe, { ...unsafe, sequence: 2, type: 'synthesis.started', data: {} }]} active />);
        expect(screen.getByRole('status')).toHaveTextContent('Combining findings into the final answer');
    });
});
