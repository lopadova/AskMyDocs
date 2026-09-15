import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { RealtimeVoiceControl } from './RealtimeVoiceControl';

describe('RealtimeVoiceControl', () => {
    it('stays visible but disabled when provider credentials are missing', () => {
        render(<RealtimeVoiceControl
            availability={{ available: false, reason: 'missing_credentials' }}
            status="idle"
            error={null}
            active={false}
            onStart={vi.fn()}
            onStop={vi.fn()}
        />);

        const button = screen.getByTestId('chat-realtime-toggle');
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('title', 'Live assistant requires provider credentials.');
        expect(button).toHaveClass('ui-button');
        expect(button).toHaveAttribute('data-variant', 'secondary');
    });

    it('announces the active state and ends the session', () => {
        const stop = vi.fn().mockResolvedValue(undefined);
        render(<RealtimeVoiceControl
            availability={{ available: true, reason: null }}
            status="listening"
            error={null}
            active
            onStart={vi.fn()}
            onStop={stop}
        />);

        const button = screen.getByTestId('chat-realtime-toggle');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('status')).toHaveTextContent('Listening');
        fireEvent.click(button);
        expect(stop).toHaveBeenCalledOnce();
    });

    it('keeps an errored connected session stoppable', () => {
        const stop = vi.fn().mockResolvedValue(undefined);
        render(<RealtimeVoiceControl
            availability={{ available: true, reason: null }}
            status="error"
            error={new Error('Provider interrupted')}
            active
            onStart={vi.fn()}
            onStop={stop}
        />);

        const button = screen.getByTestId('chat-realtime-toggle');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('status')).toHaveTextContent('Session error');
        fireEvent.click(button);
        expect(stop).toHaveBeenCalledOnce();
    });
});
