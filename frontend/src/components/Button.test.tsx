import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { Icon } from './Icons';
import { Button } from './Button';

describe('Button', () => {
    it('exposes the selected hierarchy and size', () => {
        render(<Button variant="primary" size="lg">Continue</Button>);

        const button = screen.getByRole('button', { name: 'Continue' });
        expect(button).toHaveAttribute('data-variant', 'primary');
        expect(button).toHaveAttribute('data-size', 'lg');
    });

    it('keeps decorative icons outside the accessible name', () => {
        render(<Button leadingIcon={<Icon.Plus />} trailingIcon={<Icon.Chevron />}>New chat</Button>);

        expect(screen.getByRole('button', { name: 'New chat' })).toBeInTheDocument();
    });

    it('disables interaction and exposes busy state while loading', () => {
        const onClick = vi.fn();
        render(<Button busy onClick={onClick}>Saving</Button>);

        const button = screen.getByRole('button', { name: 'Saving' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
        fireEvent.click(button);
        expect(onClick).not.toHaveBeenCalled();
    });

    it('supports an accessible icon-only action', () => {
        render(<Button iconOnly aria-label="More actions"><Icon.MoreH /></Button>);

        expect(screen.getByRole('button', { name: 'More actions' })).toBeInTheDocument();
    });

    it('preserves an explicit external aria-busy state', () => {
        render(<Button aria-busy={false}>Notifications</Button>);

        expect(screen.getByRole('button', { name: 'Notifications' })).toHaveAttribute('aria-busy', 'false');
    });
});
