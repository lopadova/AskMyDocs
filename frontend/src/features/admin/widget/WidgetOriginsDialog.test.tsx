import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { WidgetOriginsDialog, parseOrigins } from './WidgetOriginsDialog';

vi.mock('../../../lib/api', () => ({
    api: { patch: vi.fn() },
}));

import { api } from '../../../lib/api';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = api as any;

function renderDialog(initialOrigins: string[] = ['https://acme.com']) {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={qc}>
            <WidgetOriginsDialog
                open
                onOpenChange={() => {}}
                keyId={42}
                label="Production"
                projectKey="docs-v3"
                initialOrigins={initialOrigins}
            />
        </QueryClientProvider>,
    );
}

describe('parseOrigins', () => {
    it('splits on newline and comma, trims, and de-duplicates preserving order', () => {
        const raw = 'https://b.com\n https://a.com , https://b.com\n\nhttps://c.com';
        expect(parseOrigins(raw)).toEqual([
            'https://b.com',
            'https://a.com',
            'https://c.com',
        ]);
    });

    it('returns an empty array for blank input', () => {
        expect(parseOrigins('   \n , \n')).toEqual([]);
    });
});

describe('WidgetOriginsDialog', () => {
    // mockReset (not clearAllMocks) drains the mock*Once queue so a leftover
    // resolution can't poison the next test.
    beforeEach(() => mockedApi.patch.mockReset());

    it('prefills the textarea with the current origins, one per line', () => {
        renderDialog(['https://a.com', 'https://b.com']);
        const input = screen.getByTestId('admin-widget-origins-input') as HTMLTextAreaElement;
        expect(input.value).toBe('https://a.com\nhttps://b.com');
    });

    it('saves the edited, de-duplicated origins via PATCH', async () => {
        mockedApi.patch.mockResolvedValueOnce({ data: { data: {} } });
        const user = userEvent.setup();
        renderDialog(['https://acme.com']);

        const input = screen.getByTestId('admin-widget-origins-input');
        fireEvent.change(input, {
            target: { value: 'https://acme.com, https://www.acme.com\nhttps://acme.com' },
        });

        await user.click(screen.getByTestId('admin-widget-origins-save'));

        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/42', {
                allowed_origins: ['https://acme.com', 'https://www.acme.com'],
            });
        });
    });

    it('warns that an empty list blocks browser embeds (R16)', () => {
        renderDialog(['https://acme.com']);

        const count = screen.getByTestId('admin-widget-origins-count');
        expect(count.textContent).toContain('1 origin allowed');

        fireEvent.change(screen.getByTestId('admin-widget-origins-input'), {
            target: { value: '' },
        });
        expect(count.textContent).toContain('browser embeds will be blocked');
    });

    it('surfaces a 422 error in the DOM and keeps the dialog open (R14)', async () => {
        mockedApi.patch.mockRejectedValueOnce({
            response: {
                data: { errors: { 'allowed_origins.0': ['The origin may not be greater than 255 characters.'] } },
            },
        });
        const user = userEvent.setup();
        renderDialog(['https://acme.com']);

        await user.click(screen.getByTestId('admin-widget-origins-save'));

        await waitFor(() => {
            const err = screen.getByTestId('admin-widget-origins-error');
            expect(err.textContent).toContain('may not be greater than 255 characters');
        });
        // Dialog stays open so the operator can fix the value.
        expect(screen.getByTestId('admin-widget-origins-dialog')).toBeDefined();
    });
});
