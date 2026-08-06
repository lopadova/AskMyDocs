import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { TenantWelcomeView } from './TenantWelcomeView';

describe('TenantWelcomeView', () => {
    it('announces the assigned company and continues on explicit confirmation', async () => {
        const user = userEvent.setup();
        const onContinue = vi.fn();

        render(<TenantWelcomeView teamName="Acme Corporation" onContinue={onContinue} />);

        expect(screen.getByTestId('tenant-welcome-view')).toHaveAttribute('data-state', 'ready');
        expect(
            screen.getByRole('heading', { name: 'Benvenuto in Acme Corporation' }),
        ).toBeInTheDocument();

        const button = screen.getByTestId('tenant-welcome-continue');
        expect(button).toHaveAccessibleName('Entra in Acme Corporation');
        await user.click(button);

        expect(onContinue).toHaveBeenCalledOnce();
    });
});
