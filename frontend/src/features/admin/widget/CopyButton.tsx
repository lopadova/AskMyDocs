import { useState } from 'react';
import { Check, Copy } from 'lucide-react';

import { Button } from '@/components/ui/button';

interface CopyButtonProps {
    value: string;
    testId: string;
    label?: string;
    size?: 'sm' | 'default';
}

/**
 * Copy-to-clipboard button with a transient "Copied" confirmation.
 *
 * Surfaces failure instead of pretending success (R14): when the
 * Clipboard API is missing or rejects, the label flips to "Copy failed"
 * and the variant turns destructive so the operator knows to select and
 * copy by hand. `data-state` exposes the async state for E2E waits (R11).
 */
export function CopyButton({
    value,
    testId,
    label = 'Copy',
    size = 'sm',
}: CopyButtonProps) {
    const [state, setState] = useState<'idle' | 'copied' | 'error'>('idle');

    const copy = async () => {
        try {
            if (!navigator.clipboard?.writeText) {
                throw new Error('Clipboard API unavailable');
            }
            await navigator.clipboard.writeText(value);
            setState('copied');
            window.setTimeout(() => setState('idle'), 1800);
        } catch {
            setState('error');
            window.setTimeout(() => setState('idle'), 2400);
        }
    };

    return (
        <Button
            type="button"
            size={size}
            variant={state === 'error' ? 'destructive' : 'secondary'}
            data-testid={testId}
            data-state={state}
            onClick={() => void copy()}
            aria-label={`${label} to clipboard`}
        >
            {state === 'copied' ? <Check aria-hidden /> : <Copy aria-hidden />}
            {state === 'copied' ? 'Copied' : state === 'error' ? 'Copy failed' : label}
        </Button>
    );
}
