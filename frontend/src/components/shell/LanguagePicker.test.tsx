import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { useAuthStore } from '../../lib/auth-store';
import { updateLocale } from '../../features/auth/auth.api';
import { LanguagePicker } from './LanguagePicker';

vi.mock('../../features/auth/auth.api', () => ({ updateLocale: vi.fn() }));
beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({ user: { id: 1, name: 'Reader', email: 'reader@example.com', locale: 'en' } });
});
it('persists a language before updating the interface', async () => {
    vi.mocked(updateLocale).mockResolvedValue({ locale: 'it', supported: ['it', 'en'] });
    const user = userEvent.setup(); render(<LanguagePicker />);
    await user.selectOptions(screen.getByRole('combobox'), 'it');
    expect(updateLocale).toHaveBeenCalledWith('it');
    expect(await screen.findByLabelText('Lingua dell’interfaccia')).toHaveValue('it');
    expect(useAuthStore.getState().user?.locale).toBe('it');
});
it('keeps the previous selection when saving fails and exposes a retryable error', async () => {
    vi.mocked(updateLocale).mockRejectedValue(new Error('offline'));
    const user = userEvent.setup(); render(<LanguagePicker />);
    await user.selectOptions(screen.getByRole('combobox'), 'it');
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save the language. Try again.');
    expect(screen.getByRole('combobox')).toHaveValue('en');
    expect(screen.getByRole('combobox')).toBeEnabled();
});
