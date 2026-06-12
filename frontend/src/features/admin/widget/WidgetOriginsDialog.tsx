import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, Globe } from 'lucide-react';

import { api } from '../../../lib/api';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface WidgetOriginsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    keyId: number;
    label: string;
    projectKey: string;
    /** Current allow-list (one browser origin per entry). */
    initialOrigins: string[];
}

/** Pull a human message out of an axios error (422 validation first, then message). */
function extractApiError(err: unknown): string {
    const data = (
        err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    )?.response?.data;
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first.length > 0) {
            return first[0];
        }
    }
    if (typeof data?.message === 'string' && data.message !== '') {
        return data.message;
    }
    const msg = (err as { message?: string })?.message;
    return typeof msg === 'string' && msg !== '' ? msg : 'Something went wrong. Please try again.';
}

/**
 * Parse the textarea into a clean, order-preserving, de-duplicated origin list.
 * Splits on newline OR comma — the same shape the create form accepts — so the
 * operator can paste either style.
 */
export function parseOrigins(raw: string): string[] {
    const seen = new Set<string>();
    const out: string[] = [];
    for (const part of raw.split(/[\n,]/)) {
        const trimmed = part.trim();
        if (trimmed !== '' && !seen.has(trimmed)) {
            seen.add(trimmed);
            out.push(trimmed);
        }
    }
    return out;
}

/**
 * Per-key allow-list editor: edit the browser origins permitted to load the
 * widget, then persist via `PATCH /api/admin/widget-keys/{id}` ({ allowed_origins }).
 * The middleware enforces an EXACT origin match server-side, so an empty
 * list blocks every browser embed (only server-side proxy mode keeps working).
 *
 * Mounted only while open (parent-guarded) so state initializes from
 * `initialOrigins` without an effect. R11 testids, R14 surfaced 422, R15 labels.
 */
export function WidgetOriginsDialog({
    open,
    onOpenChange,
    keyId,
    label,
    projectKey,
    initialOrigins,
}: WidgetOriginsDialogProps) {
    const qc = useQueryClient();
    const [text, setText] = useState(() => initialOrigins.join('\n'));

    const save = useMutation({
        mutationFn: async () => {
            const { data } = await api.patch(`/api/admin/widget-keys/${keyId}`, {
                allowed_origins: parseOrigins(text),
            });
            return data;
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
            onOpenChange(false);
        },
    });

    const parsed = parseOrigins(text);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent data-testid="admin-widget-origins-dialog" className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Globe aria-hidden className="size-5" />
                        Allowed origins
                        <Badge variant="muted">{label}</Badge>
                    </DialogTitle>
                    <DialogDescription>
                        Websites allowed to load the <strong>{projectKey}</strong> widget. The
                        browser origin is matched exactly server-side — requests from any other
                        site are rejected with a 403.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-1.5">
                    <Label htmlFor="wk-edit-origins">Origins</Label>
                    <Textarea
                        id="wk-edit-origins"
                        data-testid="admin-widget-origins-input"
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        placeholder={'https://acme.com\nhttps://www.acme.com'}
                        rows={5}
                        autoFocus
                    />
                    <p className="text-muted-foreground text-xs">
                        One per line (or comma-separated). Include the scheme — e.g.{' '}
                        <code className="font-mono">https://acme.com</code>. A trailing slash and
                        letter-case are ignored.
                    </p>
                    <p
                        className="text-xs"
                        data-testid="admin-widget-origins-count"
                        aria-live="polite"
                    >
                        {parsed.length === 0 ? (
                            <span className="text-destructive">
                                No origins — browser embeds will be blocked. Only server-side proxy
                                mode (the secret) will work.
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                {parsed.length} origin{parsed.length === 1 ? '' : 's'} allowed.
                            </span>
                        )}
                    </p>
                </div>

                {save.isError && (
                    <Alert variant="destructive" data-testid="admin-widget-origins-error">
                        <Ban aria-hidden />
                        <AlertTitle>Could not save the origins</AlertTitle>
                        <AlertDescription>{extractApiError(save.error)}</AlertDescription>
                    </Alert>
                )}

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        data-testid="admin-widget-origins-save"
                        disabled={save.isPending}
                        onClick={() => save.mutate()}
                    >
                        {save.isPending ? 'Saving…' : 'Save origins'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
