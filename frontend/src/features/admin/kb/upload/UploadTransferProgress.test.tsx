import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { UploadTransferProgress } from './UploadTransferProgress';

afterEach(() => { cleanup(); vi.useRealTimers(); });

describe('UploadTransferProgress', () => {
    it('uses indeterminate progress when the browser does not know the total', () => {
        render(<UploadTransferProgress progress={{ loaded: 25 }} fileCount={2} />);
        expect(screen.getByRole('progressbar')).not.toHaveAttribute('value');
        expect(screen.getByTestId('kb-upload-transfer')).toHaveTextContent('25 B transferred');
    });

    it('warns about a stalled transfer and resets the warning when bytes arrive', () => {
        vi.useFakeTimers();
        const { rerender } = render(<UploadTransferProgress progress={{ loaded: 0, total: 100 }} fileCount={1} />);
        act(() => vi.advanceTimersByTime(15_000));
        expect(screen.getByTestId('kb-upload-slow')).toHaveTextContent('Check your connection');
        rerender(<UploadTransferProgress progress={{ loaded: 10, total: 100 }} fileCount={1} />);
        act(() => vi.advanceTimersByTime(1000));
        expect(screen.queryByTestId('kb-upload-slow')).not.toBeInTheDocument();
    });

    it('distinguishes a slow server response from a stalled network transfer', () => {
        vi.useFakeTimers();
        render(<UploadTransferProgress progress={{ loaded: 100, total: 100 }} fileCount={1} />);
        act(() => vi.advanceTimersByTime(15_000));
        expect(screen.getByTestId('kb-upload-slow')).toHaveTextContent('server is taking longer');
    });

    it('translates progress and recovery messages into Italian', () => {
        localStorage.setItem('amd-ui-locale', 'it');
        render(<UploadTransferProgress progress={{ loaded: 50, total: 100 }} fileCount={2} />);
        expect(screen.getByRole('progressbar', { name: 'Avanzamento del trasferimento' })).toHaveAttribute('value', '50');
        expect(screen.getByTestId('kb-upload-transfer')).toHaveTextContent('Caricamento di 2 file');
    });
});
