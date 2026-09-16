import { useState, type ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '../../../components/ui/dialog';
import { Button } from '../../../components/Button';

export interface NameDialogProps {
    title: string;
    description: string;
    submitLabel: string;
    initialValue: string;
    /** Rejecting surfaces the message in the dialog; resolving closes it. */
    onSubmit: (name: string) => Promise<unknown>;
    onClose: () => void;
    testId: string;
}

/**
 * One single-field name dialog, used for creating a folder, renaming a
 * folder and renaming a session.
 *
 * Deliberately generic rather than one component per target with a mode
 * flag: a boolean that changes what a component submits to is exactly
 * the shape the repo's code-structure rule warns about. The caller
 * supplies the submit function, so this file has no idea what it is
 * naming.
 *
 * The rejection message is rendered IN THE DOM (R14/R11) — the BE
 * answers 422 for a duplicate folder name, and a silently swallowed
 * mutation would leave the user retyping the same name forever.
 */
export function NameDialog({
    title,
    description,
    submitLabel,
    initialValue,
    onSubmit,
    onClose,
    testId,
}: NameDialogProps): ReactNode {
    const [value, setValue] = useState(initialValue);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async (): Promise<void> => {
        const name = value.trim();
        if (name === '') {
            setError('Enter a name.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            await onSubmit(name);
            onClose();
        } catch (e: unknown) {
            setError(extractMessage(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => (open ? undefined : onClose())}>
            <DialogContent className="chat-sessions-name-dialog" data-testid={testId}>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>

                <label className="chat-sessions-name-label" htmlFor={`${testId}-input`}>
                    Name
                </label>
                <input
                    id={`${testId}-input`}
                    data-testid={`${testId}-input`}
                    value={value}
                    autoFocus
                    onChange={(e) => {
                        setValue(e.target.value);
                        setError(null);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            void submit();
                        }
                    }}
                />

                {error !== null && (
                    <p className="chat-sessions-error" role="alert" data-testid={`${testId}-error`}>
                        {error}
                    </p>
                )}

                <div className="chat-sessions-name-actions">
                    <Button
                        variant="primary"
                        size="sm"
                        busy={busy}
                        data-testid={`${testId}-submit`}
                        onClick={() => void submit()}
                    >
                        {submitLabel}
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        data-testid={`${testId}-cancel`}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Pull the server's field error out of an axios failure so a duplicate
 * name reads as "You already have a folder with this name." rather than
 * "Request failed with status code 422".
 */
function extractMessage(e: unknown): string {
    const response = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response;
    const fieldErrors = response?.data?.errors;
    if (fieldErrors) {
        const first = Object.values(fieldErrors)[0];
        if (Array.isArray(first) && typeof first[0] === 'string') {
            return first[0];
        }
    }
    if (typeof response?.data?.message === 'string' && response.data.message !== '') {
        return response.data.message;
    }
    return e instanceof Error ? e.message : String(e);
}
