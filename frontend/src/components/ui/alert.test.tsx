import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Alert, AlertDescription, AlertIcon, AlertTitle } from './alert';

describe('Alert', () => {
    it('exposes a consistent semantic structure for feedback', () => {
        render(
            <Alert variant="destructive">
                <AlertIcon>
                    <span>!</span>
                </AlertIcon>
                <AlertTitle>Request not completed</AlertTitle>
                <AlertDescription>The live source did not respond.</AlertDescription>
            </Alert>,
        );

        const alert = screen.getByRole('alert');
        expect(alert).toHaveAttribute('data-variant', 'destructive');
        expect(alert.querySelector('[data-slot="alert-icon"]')).not.toBeNull();
        expect(alert.querySelector('[data-slot="alert-title"]')).toHaveTextContent(
            'Request not completed',
        );
        expect(alert.querySelector('[data-slot="alert-description"]')).toHaveTextContent(
            'The live source did not respond.',
        );
    });

    it.each(['default', 'info', 'success', 'warning'] as const)(
        'supports the %s feedback tone',
        (variant) => {
            render(<Alert variant={variant}>Feedback</Alert>);
            expect(screen.getByRole('alert')).toHaveAttribute('data-variant', variant);
        },
    );
});
