import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ButtonSystemDemo } from './ButtonSystemDemo';

describe('ButtonSystemDemo', () => {
    it('documents every action hierarchy and interaction state', () => {
        render(<ButtonSystemDemo />);

        expect(screen.getByRole('heading', { name: 'Application chrome' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Notifications demo' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Core foundations' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Feedback & alerts' })).toBeInTheDocument();
        expect(screen.getByText('Request not completed')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Primary action' })).toHaveAttribute('data-variant', 'primary');
        expect(screen.getByRole('button', { name: 'Secondary action' })).toHaveAttribute('data-variant', 'secondary');
        expect(screen.getByRole('button', { name: 'Quiet action' })).toHaveAttribute('data-variant', 'quiet');
        expect(screen.getByRole('button', { name: 'Destructive action' })).toHaveAttribute('data-variant', 'danger');
        expect(screen.getByText('Keyboard focus')).toBeInTheDocument();
        expect(screen.getByText('Working')).toBeInTheDocument();
    });

    it('demonstrates accessible exclusive button groups', () => {
        render(<ButtonSystemDemo />);

        const overview = screen.getByRole('button', { name: 'Overview' });
        const details = screen.getByRole('button', { name: 'Details' });
        expect(overview).toHaveAttribute('aria-pressed', 'true');
        expect(details).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(details);

        expect(overview).toHaveAttribute('aria-pressed', 'false');
        expect(details).toHaveAttribute('aria-pressed', 'true');
    });
});
