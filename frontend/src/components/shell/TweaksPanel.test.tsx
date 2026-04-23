import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TweaksPanel } from './TweaksPanel';

describe('TweaksPanel', () => {
    it('renders nothing when closed', () => {
        const { container } = render(
            <TweaksPanel
                open={false}
                onClose={() => undefined}
                theme="dark"
                setTheme={() => undefined}
                density="balanced"
                setDensity={() => undefined}
                font="geist"
                setFont={() => undefined}
                section="chat"
                setSection={() => undefined}
            />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('calls setTheme when toggling to light', async () => {
        const user = userEvent.setup();
        const setTheme = vi.fn();
        render(
            <TweaksPanel
                open
                onClose={() => undefined}
                theme="dark"
                setTheme={setTheme}
                density="balanced"
                setDensity={() => undefined}
                font="geist"
                setFont={() => undefined}
                section="chat"
                setSection={() => undefined}
            />,
        );
        await user.click(screen.getByRole('radio', { name: /light/i }));
        expect(setTheme).toHaveBeenCalledWith('light');
    });
});
