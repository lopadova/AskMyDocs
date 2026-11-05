import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it } from 'vitest';
import { useAuthStore } from './auth-store';
import { getUiLocale, setLocalUiLocale, translateUi, useUiTranslation } from './ui-locale';

beforeEach(() => useAuthStore.setState({ user: null }));
it('uses the saved account locale and preserves unsupported locale values', () => {
    useAuthStore.setState({ user: { id: 1, name: 'Reader', email: 'reader@example.com', locale: 'it-IT' } });
    expect(getUiLocale()).toBe('it');
    useAuthStore.setState({ user: { id: 1, name: 'Reader', email: 'reader@example.com', locale: 'fr' } });
    expect(getUiLocale()).toBe('en');
    expect(useAuthStore.getState().user?.locale).toBe('fr');
});
it('translates interface messages while preserving interpolated document names', () => {
    expect(translateUi('Welcome to {name}', 'it', { name: 'Terms {2026}' })).toBe('Benvenuto in Terms {2026}');
    expect(translateUi('User-written content', 'it')).toBe('User-written content');
    expect(translateUi('New &amp; promoted', 'en')).toBe('New & promoted');
});
it('changes the interface without remounting or losing a draft', async () => {
    function Form() { const { t } = useUiTranslation(); return <label>{t('Name')}<input aria-label="Document title" defaultValue="" /></label>; }
    const user = userEvent.setup();
    render(<Form />);
    await user.type(screen.getByRole('textbox'), 'Draft title');
    act(() => setLocalUiLocale('it'));
    expect(screen.getByText('Nome')).toBeInTheDocument();
    expect(screen.getByRole('textbox')).toHaveValue('Draft title');
});
