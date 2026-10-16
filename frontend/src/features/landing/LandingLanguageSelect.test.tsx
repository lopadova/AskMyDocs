import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { chooseSelectOption } from '@/test-utils/select';
import { LandingLanguageSelect } from './LandingLanguageSelect';

const languages = [{ code: 'it', name: 'Italiano' }, { code: 'en', name: 'English' }, { code: 'fr', name: 'Français' }];

function mountIsland(locale = 'it', ready = true) {
    const container = document.createElement('div');
    container.dataset.locale = locale;
    if (ready) container.dataset.languages = JSON.stringify(languages);
    document.body.append(container);
    render(<LandingLanguageSelect container={container} />, { container });
    return container;
}

describe('LandingLanguageSelect', () => {
    it('reads a locale already resolved by the public script and requests a selection through the event contract', async () => {
        const container = mountIsland('fr');
        const request = vi.fn();
        container.addEventListener('amd:landing-locale-request', request);
        expect(screen.getByRole('combobox')).toHaveAttribute('data-value', 'fr');
        await chooseSelectOption(screen.getByRole('combobox'), 'en');
        expect(request).toHaveBeenCalledOnce();
        expect((request.mock.calls[0][0] as CustomEvent).detail).toEqual({ locale: 'en' });
        expect(container.dataset.locale).toBe('fr');
    });

    it('accepts the public script initialising after React even when the locale remains Italian', async () => {
        const container = mountIsland('it', false);
        await act(async () => {
            container.dataset.languages = JSON.stringify(languages);
            container.dispatchEvent(new CustomEvent('amd:landing-locale-changed'));
        });
        await chooseSelectOption(screen.getByRole('combobox'), 'fr');
        expect(screen.getByRole('combobox')).toHaveAttribute('data-value', 'it');
    });

    it('tracks locale changes published by the public script', async () => {
        const container = mountIsland();
        await act(async () => {
            container.dataset.locale = 'en';
            container.dispatchEvent(new CustomEvent('amd:landing-locale-changed'));
        });
        expect(screen.getByRole('combobox')).toHaveAttribute('data-value', 'en');
        expect(screen.getByRole('combobox')).toHaveTextContent('English');
    });
});
