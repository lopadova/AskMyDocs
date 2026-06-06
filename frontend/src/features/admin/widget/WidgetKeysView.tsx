import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, Code2, Globe, Info, KeyRound, Palette, Plus, RotateCw, Trash2 } from 'lucide-react';

import { api } from '../../../lib/api';
import { DEFAULT_THEME, sanitizeTheme } from '../../../widget/ui/styles';
import type { WidgetMode, WidgetTheme } from '../../../widget/types';
import { WidgetAppearanceDialog } from './WidgetAppearanceDialog';
import { WidgetOriginsDialog } from './WidgetOriginsDialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { CopyButton } from './CopyButton';
import { EmbedCodeDialog } from './EmbedCodeDialog';

/**
 * WidgetKey row as returned by the admin API.
 */
interface WidgetKeyRow {
    id: number;
    label: string;
    public_key: string;
    project_key: string;
    allowed_origins: string[];
    rate_limit: number;
    skill: string;
    is_active: boolean;
    last_used_at: string | null;
    sessions_count: number;
    /** Resolved appearance theme (always complete — backend merges defaults). */
    theme?: WidgetTheme;
    created_at: string;
    updated_at: string;
}

interface WidgetKeysResponse {
    data: WidgetKeyRow[];
}

interface CreateKeyResponse {
    data: WidgetKeyRow;
    plain_secret: string;
    public_key: string;
}

type RotateKeyResponse = CreateKeyResponse;

/** Target passed to the embed-code dialog (with the secret only right after create/rotate). */
interface EmbedTarget {
    publicKey: string;
    projectKey: string;
    label: string;
    allowedOrigins: string[];
    secret?: string;
    /** Saved appearance — lets the embed dialog bake it inline. */
    theme?: WidgetTheme;
}

/** Target passed to the appearance editor. */
interface AppearanceTarget {
    keyId: number;
    label: string;
    projectKey: string;
    theme: WidgetTheme;
}

/** Target passed to the allowed-origins editor. */
interface OriginsTarget {
    keyId: number;
    label: string;
    projectKey: string;
    origins: string[];
}

/** Pull a human message out of an axios error (422 validation first, then message). */
function extractApiError(err: unknown): string {
    const data = (
        err as {
            response?: { data?: { message?: string; errors?: Record<string, string[]> } };
        }
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

    return typeof msg === 'string' && msg !== ''
        ? msg
        : 'Something went wrong. Please try again.';
}

/** Absolute origin of THIS AskMyDocs instance — the widget's script + API base. */
function instanceOrigin(): string {
    return typeof window !== 'undefined' ? window.location.origin : '';
}

/** The two widget layouts, chosen at creation (stored in the key's theme). */
const MODE_OPTIONS: { value: WidgetMode; label: string; hint: string }[] = [
    {
        value: 'helper',
        label: 'Helper — floating launcher (KITT)',
        hint: 'A button pinned to the page corner that opens the chat in a popover. Classic site assistant.',
    },
    {
        value: 'inline',
        label: 'Inline chat — full block',
        hint: 'The whole chat is embedded at 100% of a container you place on the page. For a chat bound to a page.',
    },
];

/** shadcn-styled control class shared by the native <select> here. */
const SELECT_CLASS =
    'border-input bg-background ring-offset-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

/**
 * M6.4 — Widget admin keys management view.
 *
 * Lists keys, creates / rotates / revokes / deletes them, and — new in
 * this iteration — explains every field inline and generates the
 * copy-paste embed snippet for the host site via {@link EmbedCodeDialog}.
 * R11 testids, R14 surfaced failures, R15 a11y.
 */
export function WidgetKeysView() {
    const qc = useQueryClient();
    const [showCreate, setShowCreate] = useState(false);
    const [newLabel, setNewLabel] = useState('');
    const [newProjectKey, setNewProjectKey] = useState('');
    const [newMode, setNewMode] = useState<WidgetMode>('helper');
    const [newOrigins, setNewOrigins] = useState('');
    const [newRateLimit, setNewRateLimit] = useState('');
    const [newSkill, setNewSkill] = useState('');
    const [rotatedCreds, setRotatedCreds] = useState<RotateKeyResponse | null>(null);
    const [createdCreds, setCreatedCreds] = useState<CreateKeyResponse | null>(null);
    const [embedTarget, setEmbedTarget] = useState<EmbedTarget | null>(null);
    const [appearanceTarget, setAppearanceTarget] = useState<AppearanceTarget | null>(null);
    const [originsTarget, setOriginsTarget] = useState<OriginsTarget | null>(null);

    const keys = useQuery({
        queryKey: ['admin-widget-keys'],
        queryFn: async () => {
            const { data } = await api.get<WidgetKeysResponse>('/api/admin/widget-keys');
            return data.data;
        },
    });

    const resetCreateForm = () => {
        setNewLabel('');
        setNewProjectKey('');
        setNewMode('helper');
        setNewOrigins('');
        setNewRateLimit('');
        setNewSkill('');
    };

    const createKey = useMutation({
        mutationFn: async () => {
            const origins = newOrigins
                .split(/[\n,]/)
                .map((s) => s.trim())
                .filter(Boolean);

            const payload: Record<string, unknown> = {
                label: newLabel.trim(),
                project_key: newProjectKey.trim(),
                allowed_origins: origins,
            };
            const rate = Number.parseInt(newRateLimit, 10);
            if (newRateLimit.trim() !== '' && Number.isFinite(rate)) {
                payload.rate_limit = rate;
            }
            if (newSkill.trim() !== '') {
                payload.skill = newSkill.trim();
            }
            // Only persist a theme when the operator picks a non-default layout —
            // keeps `theme_config` null (minimal snippet) for plain helper keys.
            if (newMode !== 'helper') {
                payload.theme = { mode: newMode };
            }

            const { data } = await api.post<CreateKeyResponse>(
                '/api/admin/widget-keys',
                payload,
            );
            return data;
        },
        onSuccess: async (payload) => {
            setCreatedCreds(payload);
            resetCreateForm();
            setShowCreate(false);
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    const rotateKey = useMutation({
        mutationFn: async (id: number) => {
            const { data } = await api.post<RotateKeyResponse>(
                `/api/admin/widget-keys/${id}/rotate`,
            );
            return data;
        },
        onSuccess: async (payload) => {
            setRotatedCreds(payload);
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    const revokeKey = useMutation({
        mutationFn: async (id: number) => {
            await api.post(`/api/admin/widget-keys/${id}/revoke`);
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    const destroyKey = useMutation({
        mutationFn: async (id: number) => {
            await api.delete(`/api/admin/widget-keys/${id}`);
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    const canSubmit =
        newLabel.trim() !== '' && newProjectKey.trim() !== '' && !createKey.isPending;

    return (
        <section data-testid="admin-widget-keys-view" className="grid gap-5">
            <header>
                <h1 className="m-0 flex items-center gap-2 text-[22px] font-semibold">
                    <KeyRound aria-hidden className="size-5 text-[var(--accent-a)]" />
                    Widget Keys
                </h1>
                <p className="text-muted-foreground mt-1.5 text-sm">
                    Manage embeddable KITT widget credentials. Create a key, copy the snippet,
                    and paste it into any website to launch the AI chat widget grounded in your
                    knowledge base.
                </p>
            </header>

            {/* How it works (R14: explain, don't assume) */}
            <Alert variant="info">
                <Info aria-hidden />
                <AlertTitle>How a widget key works</AlertTitle>
                <AlertDescription>
                    <ol className="list-decimal space-y-0.5 pl-4">
                        <li>
                            Create a key and bind it to one knowledge-base project — the widget
                            answers only from that project.
                        </li>
                        <li>
                            List the websites allowed to load it under <em>Allowed origins</em>{' '}
                            (the browser origin is enforced server-side).
                        </li>
                        <li>
                            Copy the generated <code className="font-mono">&lt;script&gt;</code>{' '}
                            snippet and paste it before{' '}
                            <code className="font-mono">&lt;/body&gt;</code> on the host site.
                        </li>
                    </ol>
                    The public key (<code className="font-mono">pk_…</code>) is safe in the
                    browser; the secret (<code className="font-mono">sk_…</code>) is shown once at
                    creation and is only needed for server-side proxy mode.
                </AlertDescription>
            </Alert>

            {/* Create */}
            {!showCreate ? (
                <div>
                    <Button
                        type="button"
                        data-testid="admin-widget-keys-create-btn"
                        onClick={() => setShowCreate(true)}
                    >
                        <Plus aria-hidden />
                        Create Key
                    </Button>
                </div>
            ) : (
                <Card data-testid="admin-widget-keys-create-form">
                    <CardHeader>
                        <CardTitle>New widget key</CardTitle>
                        <CardDescription>
                            Fields marked <span className="text-destructive">*</span> are
                            required. Everything else has a sensible default.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="wk-mode">
                                Widget type <span className="text-destructive">*</span>
                            </Label>
                            <select
                                id="wk-mode"
                                data-testid="admin-widget-keys-mode"
                                value={newMode}
                                onChange={(e) => setNewMode(e.target.value as WidgetMode)}
                                className={SELECT_CLASS}
                            >
                                {MODE_OPTIONS.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">
                                {MODE_OPTIONS.find((o) => o.value === newMode)?.hint}
                                {' '}You can change this later under <em>Appearance</em>.
                            </p>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="wk-label">
                                Key label <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="wk-label"
                                data-testid="admin-widget-keys-label"
                                value={newLabel}
                                onChange={(e) => setNewLabel(e.target.value)}
                                placeholder="e.g. Production website"
                            />
                            <p className="text-muted-foreground text-xs">
                                A name to recognise this key in the list. Internal only — never
                                shown to visitors.
                            </p>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="wk-project">
                                Project key <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="wk-project"
                                data-testid="admin-widget-keys-project"
                                value={newProjectKey}
                                onChange={(e) => setNewProjectKey(e.target.value)}
                                placeholder="e.g. modelsgenerator"
                            />
                            <p className="text-muted-foreground text-xs">
                                Which knowledge-base project the widget retrieves from. Answers
                                and citations are grounded strictly in this project.
                            </p>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="wk-origins">Allowed origins</Label>
                            <Textarea
                                id="wk-origins"
                                data-testid="admin-widget-keys-origins"
                                value={newOrigins}
                                onChange={(e) => setNewOrigins(e.target.value)}
                                placeholder={'https://acme.com\nhttps://www.acme.com'}
                                rows={2}
                            />
                            <p className="text-muted-foreground text-xs">
                                Comma- or newline-separated list of sites allowed to load the
                                widget. The server matches the browser origin exactly and rejects
                                any other. Leave empty to block all browser embeds — only
                                server-side proxy mode (the secret) will work. You can change this
                                later under <em>Origins</em>.
                            </p>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="wk-rate">Rate limit (req/min)</Label>
                                <Input
                                    id="wk-rate"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    data-testid="admin-widget-keys-rate-limit"
                                    value={newRateLimit}
                                    onChange={(e) => setNewRateLimit(e.target.value)}
                                    placeholder="60"
                                />
                                <p className="text-muted-foreground text-xs">
                                    Max widget API calls per minute per visitor. Default 60.
                                </p>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="wk-skill">Assistant skill</Label>
                                <Input
                                    id="wk-skill"
                                    data-testid="admin-widget-keys-skill"
                                    value={newSkill}
                                    onChange={(e) => setNewSkill(e.target.value)}
                                    placeholder="askmydocs-assistant@1"
                                />
                                <p className="text-muted-foreground text-xs">
                                    The skill/persona the widget runs. Leave blank for the
                                    default.
                                </p>
                            </div>
                        </div>

                        {createKey.isError && (
                            <Alert
                                variant="destructive"
                                data-testid="admin-widget-keys-create-error"
                            >
                                <Ban aria-hidden />
                                <AlertTitle>Could not create the key</AlertTitle>
                                <AlertDescription>
                                    {extractApiError(createKey.error)}
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="flex gap-2">
                            <Button
                                type="button"
                                data-testid="admin-widget-keys-create-submit"
                                disabled={!canSubmit}
                                onClick={() => createKey.mutate()}
                            >
                                {createKey.isPending ? 'Creating…' : 'Create key'}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setShowCreate(false);
                                    resetCreateForm();
                                    createKey.reset();
                                }}
                            >
                                Cancel
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Created credentials — show ONCE */}
            {createdCreds && (
                <CredentialsCard
                    testId="admin-widget-keys-created-creds"
                    title="Key created — copy the secret now, it won't be shown again"
                    creds={createdCreds}
                    onEmbed={() =>
                        setEmbedTarget({
                            publicKey: createdCreds.public_key,
                            projectKey: createdCreds.data.project_key,
                            label: createdCreds.data.label,
                            allowedOrigins: createdCreds.data.allowed_origins,
                            secret: createdCreds.plain_secret,
                            theme: createdCreds.data.theme,
                        })
                    }
                    onDismiss={() => setCreatedCreds(null)}
                />
            )}

            {/* Rotated credentials — show ONCE */}
            {rotatedCreds && (
                <CredentialsCard
                    testId="admin-widget-keys-rotated-creds"
                    title="Credentials rotated — copy the new secret now"
                    creds={rotatedCreds}
                    onEmbed={() =>
                        setEmbedTarget({
                            publicKey: rotatedCreds.public_key,
                            projectKey: rotatedCreds.data.project_key,
                            label: rotatedCreds.data.label,
                            allowedOrigins: rotatedCreds.data.allowed_origins,
                            secret: rotatedCreds.plain_secret,
                            theme: rotatedCreds.data.theme,
                        })
                    }
                    onDismiss={() => setRotatedCreds(null)}
                />
            )}

            {/* Loading / error states (R14) */}
            {keys.isLoading && (
                <div
                    data-testid="admin-widget-keys-loading"
                    className="text-muted-foreground text-sm"
                >
                    Loading widget keys…
                </div>
            )}
            {keys.isError && (
                <Alert variant="destructive" data-testid="admin-widget-keys-error">
                    <Ban aria-hidden />
                    <AlertTitle>Failed to load widget keys.</AlertTitle>
                    <AlertDescription>{extractApiError(keys.error)}</AlertDescription>
                </Alert>
            )}

            {/* Empty state */}
            {keys.data && keys.data.length === 0 && (
                <div
                    data-testid="admin-widget-keys-empty"
                    className="text-muted-foreground rounded-lg border border-dashed border-border p-6 text-center text-sm"
                >
                    No widget keys yet. Create one to get started.
                </div>
            )}

            {/* Key list */}
            {keys.data && keys.data.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-border">
                    <table
                        data-testid="admin-widget-keys-table"
                        className="w-full border-collapse text-[13px]"
                    >
                        <thead>
                            <tr className="text-muted-foreground border-b border-border text-left [&>th]:px-3 [&>th]:py-2 [&>th]:font-medium">
                                <th>Label</th>
                                <th>Public Key</th>
                                <th>Project</th>
                                <th>Mode</th>
                                <th>Origins</th>
                                <th>Rate</th>
                                <th>Status</th>
                                <th>Sessions</th>
                                <th>Last Used</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {keys.data.map((key) => (
                                <tr
                                    key={key.id}
                                    data-testid={`admin-widget-keys-row-${key.id}`}
                                    className="border-b border-border last:border-0 [&>td]:px-3 [&>td]:py-2 [&>td]:align-middle"
                                >
                                    <td className="font-medium">{key.label}</td>
                                    <td className="font-mono text-[11px]">{key.public_key}</td>
                                    <td>{key.project_key}</td>
                                    <td>
                                        <Badge
                                            variant="muted"
                                            data-testid={`admin-widget-keys-mode-${key.id}`}
                                        >
                                            {key.theme?.mode === 'inline' ? 'Inline' : 'Helper'}
                                        </Badge>
                                    </td>
                                    <td className="max-w-[180px] truncate">
                                        {key.allowed_origins.length === 0 ? (
                                            <span className="text-muted-foreground">any</span>
                                        ) : (
                                            key.allowed_origins.join(', ')
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap">{key.rate_limit}/min</td>
                                    <td>
                                        <Badge
                                            data-testid={`admin-widget-keys-status-${key.id}`}
                                            variant={key.is_active ? 'success' : 'destructive'}
                                        >
                                            {key.is_active ? 'Active' : 'Revoked'}
                                        </Badge>
                                    </td>
                                    <td>{key.sessions_count}</td>
                                    <td className="whitespace-nowrap">
                                        {key.last_used_at
                                            ? new Date(key.last_used_at).toLocaleDateString()
                                            : '—'}
                                    </td>
                                    <td>
                                        <div className="flex flex-wrap justify-end gap-1.5">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                data-testid={`admin-widget-keys-embed-${key.id}`}
                                                onClick={() =>
                                                    setEmbedTarget({
                                                        publicKey: key.public_key,
                                                        projectKey: key.project_key,
                                                        label: key.label,
                                                        allowedOrigins: key.allowed_origins,
                                                        theme: key.theme,
                                                    })
                                                }
                                                title="Get the embed snippet"
                                                aria-label={`Embed code for ${key.label}`}
                                            >
                                                <Code2 aria-hidden />
                                                Embed
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                data-testid={`admin-widget-keys-appearance-${key.id}`}
                                                onClick={() =>
                                                    setAppearanceTarget({
                                                        keyId: key.id,
                                                        label: key.label,
                                                        projectKey: key.project_key,
                                                        theme: sanitizeTheme(key.theme ?? DEFAULT_THEME),
                                                    })
                                                }
                                                title="Customize the launcher and chat appearance"
                                                aria-label={`Customize appearance for ${key.label}`}
                                            >
                                                <Palette aria-hidden />
                                                Appearance
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                data-testid={`admin-widget-keys-origins-${key.id}`}
                                                onClick={() =>
                                                    setOriginsTarget({
                                                        keyId: key.id,
                                                        label: key.label,
                                                        projectKey: key.project_key,
                                                        origins: key.allowed_origins,
                                                    })
                                                }
                                                title="Edit the websites allowed to load this widget"
                                                aria-label={`Edit allowed origins for ${key.label}`}
                                            >
                                                <Globe aria-hidden />
                                                Origins
                                            </Button>
                                            {key.is_active && (
                                                <>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        data-testid={`admin-widget-keys-rotate-${key.id}`}
                                                        disabled={rotateKey.isPending}
                                                        onClick={() => {
                                                            if (
                                                                confirm(
                                                                    'Rotating will invalidate the current credentials. Continue?',
                                                                )
                                                            ) {
                                                                rotateKey.mutate(key.id);
                                                            }
                                                        }}
                                                        title="Rotate credentials (generates new pk_ + sk_)"
                                                        aria-label={`Rotate key ${key.label}`}
                                                    >
                                                        <RotateCw aria-hidden />
                                                        Rotate
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        data-testid={`admin-widget-keys-revoke-${key.id}`}
                                                        disabled={revokeKey.isPending}
                                                        onClick={() => {
                                                            if (
                                                                confirm(
                                                                    'Revoke this key? It will stop accepting requests.',
                                                                )
                                                            ) {
                                                                revokeKey.mutate(key.id);
                                                            }
                                                        }}
                                                        title="Revoke (set inactive)"
                                                        aria-label={`Revoke key ${key.label}`}
                                                    >
                                                        <Ban aria-hidden />
                                                        Revoke
                                                    </Button>
                                                </>
                                            )}
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive hover:text-destructive"
                                                data-testid={`admin-widget-keys-delete-${key.id}`}
                                                disabled={destroyKey.isPending}
                                                onClick={() => {
                                                    if (
                                                        confirm(
                                                            'Permanently delete this key and all its sessions?',
                                                        )
                                                    ) {
                                                        destroyKey.mutate(key.id);
                                                    }
                                                }}
                                                title="Hard delete (cascading)"
                                                aria-label={`Delete key ${key.label}`}
                                            >
                                                <Trash2 aria-hidden />
                                                Delete
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {embedTarget && (
                <EmbedCodeDialog
                    open={embedTarget !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setEmbedTarget(null);
                        }
                    }}
                    publicKey={embedTarget.publicKey}
                    projectKey={embedTarget.projectKey}
                    label={embedTarget.label}
                    apiBase={instanceOrigin()}
                    allowedOrigins={embedTarget.allowedOrigins}
                    secret={embedTarget.secret}
                    theme={embedTarget.theme}
                />
            )}

            {appearanceTarget && (
                <WidgetAppearanceDialog
                    open={appearanceTarget !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setAppearanceTarget(null);
                        }
                    }}
                    keyId={appearanceTarget.keyId}
                    label={appearanceTarget.label}
                    projectKey={appearanceTarget.projectKey}
                    initialTheme={appearanceTarget.theme}
                />
            )}

            {originsTarget && (
                <WidgetOriginsDialog
                    open={originsTarget !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setOriginsTarget(null);
                        }
                    }}
                    keyId={originsTarget.keyId}
                    label={originsTarget.label}
                    projectKey={originsTarget.projectKey}
                    initialOrigins={originsTarget.origins}
                />
            )}
        </section>
    );
}

/** One-time credentials reveal: copyable pk_/sk_ plus an "Embed code" launcher. */
function CredentialsCard({
    testId,
    title,
    creds,
    onEmbed,
    onDismiss,
}: {
    testId: string;
    title: string;
    creds: CreateKeyResponse;
    onEmbed: () => void;
    onDismiss: () => void;
}) {
    return (
        <Card data-testid={testId} className="border-[var(--accent-a)]/40">
            <CardHeader>
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3">
                <CredRow label="Public key" value={creds.public_key} testId="created-pk" />
                <CredRow label="Secret" value={creds.plain_secret} testId="created-sk" />
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        data-testid="admin-widget-keys-creds-embed"
                        onClick={onEmbed}
                    >
                        <Code2 aria-hidden />
                        Get embed code
                    </Button>
                    <Button type="button" variant="ghost" onClick={onDismiss}>
                        Dismiss
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

/** A labelled, monospaced, copyable credential value. */
function CredRow({
    label,
    value,
    testId,
}: {
    label: string;
    value: string;
    testId: string;
}) {
    return (
        <div className="flex items-center gap-3">
            <span className="text-muted-foreground w-20 shrink-0 text-xs font-medium">
                {label}
            </span>
            <code className="bg-muted flex-1 overflow-x-auto rounded-md border border-border px-2 py-1 font-mono text-xs">
                {value}
            </code>
            <CopyButton value={value} testId={`admin-widget-keys-copy-${testId}`} />
        </div>
    );
}
