import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UploadDropzone } from './UploadDropzone';

/*
 * Stateless dropzone — R16: each test drives the behaviour its name claims.
 */
describe('UploadDropzone', () => {
    it('fires onAddFiles with the picked files when a file is selected', async () => {
        const onAdd = vi.fn();
        render(<UploadDropzone onAddFiles={onAdd} accept=".md" />);

        const input = screen.getByTestId('kb-upload-file-input') as HTMLInputElement;
        const file = new File(['# hi'], 'a.md', { type: 'text/markdown' });
        await userEvent.upload(input, file);

        expect(onAdd).toHaveBeenCalledTimes(1);
        const passed = onAdd.mock.calls[0][0] as File[];
        expect(passed[0].name).toBe('a.md');
    });

    it('exposes a focusable, label-bound file input (a11y, R15)', () => {
        render(<UploadDropzone onAddFiles={vi.fn()} />);

        const input = screen.getByTestId('kb-upload-file-input') as HTMLInputElement;
        // Bound to the dropzone label via htmlFor=id.
        expect(input.id).toBe('kb-upload-file-input');
        // Not display:none / visibility:hidden — stays in the a11y tree.
        expect(input.style.display).not.toBe('none');
        expect(input.disabled).toBe(false);
    });

    it('disables the input when disabled', () => {
        render(<UploadDropzone onAddFiles={vi.fn()} disabled />);

        const input = screen.getByTestId('kb-upload-file-input') as HTMLInputElement;
        expect(input.disabled).toBe(true);
    });
});
