import { useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, Palette, RotateCcw } from 'lucide-react';

import { api } from '../../../lib/api';
import { DEFAULT_THEME, sanitizeTheme } from '../../../widget/ui/styles';
import type {
    LauncherIcon,
    LauncherShape,
    LauncherSide,
    WidgetFontKey,
    WidgetMode,
    WidgetTheme,
} from '../../../widget/types';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { WidgetThemePreview } from './WidgetThemePreview';

interface WidgetAppearanceDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    keyId: number;
    label: string;
    projectKey: string;
    /** Current theme of the key (resolved server-side, always complete). */
    initialTheme: WidgetTheme;
}

const HEX_RE = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;

const MODE_OPTIONS: { value: WidgetMode; label: string; hint: string }[] = [
    {
        value: 'helper',
        label: 'Helper — floating launcher (KITT)',
        hint: 'A button pinned to the page corner that opens the chat in a popover.',
    },
    {
        value: 'inline',
        label: 'Inline chat — full block',
        hint: 'The chat fills 100% of a container on the host page (no launcher). For a chat bound to a page.',
    },
];
const FONT_OPTIONS: { value: WidgetFontKey; label: string }[] = [
    { value: 'system', label: 'System' },
    { value: 'inter', label: 'Inter' },
    { value: 'roboto', label: 'Roboto' },
    { value: 'georgia', label: 'Georgia (serif)' },
    { value: 'mono', label: 'Monospace' },
];
const SIDE_OPTIONS: { value: LauncherSide; label: string }[] = [
    { value: 'right', label: 'Bottom-right' },
    { value: 'left', label: 'Bottom-left' },
];
const SHAPE_OPTIONS: { value: LauncherShape; label: string }[] = [
    { value: 'pill', label: 'Pill' },
    { value: 'rounded', label: 'Rounded' },
    { value: 'circle', label: 'Circle (icon only)' },
];
const ICON_OPTIONS: { value: LauncherIcon; label: string }[] = [
    { value: 'chat', label: 'Chat bubble' },
    { value: 'sparkles', label: 'Sparkles' },
    { value: 'help', label: 'Help' },
    { value: 'none', label: 'No icon' },
];

/** Pull a human message out of an axios error (422 validation first). */
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
 * Per-key appearance editor: a sectioned form (Launcher / Colors / Panel /
 * Typography) with a live Shadow-DOM preview. Saves the theme via
 * `PATCH /api/admin/widget-keys/{id}` ({ theme }) — the backend sanitizes and
 * persists it; `GET /api/widget/setup` then serves it to the widget.
 *
 * Mounted only while open (parent-guarded) so state initializes from
 * `initialTheme` without an effect. R11 testids, R14 surfaced 422, R15 labels.
 */
export function WidgetAppearanceDialog({
    open,
    onOpenChange,
    keyId,
    label,
    projectKey,
    initialTheme,
}: WidgetAppearanceDialogProps) {
    const qc = useQueryClient();
    const [theme, setTheme] = useState<WidgetTheme>(() => sanitizeTheme(initialTheme));

    const set = (patch: Partial<WidgetTheme>) => setTheme((prev) => ({ ...prev, ...patch }));

    const save = useMutation({
        mutationFn: async () => {
            const { data } = await api.patch(`/api/admin/widget-keys/${keyId}`, { theme });
            return data;
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
            onOpenChange(false);
        },
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                data-testid="admin-widget-appearance-dialog"
                className="sm:max-w-4xl"
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Palette aria-hidden className="size-5" />
                        Customize appearance
                        <Badge variant="muted">{label}</Badge>
                    </DialogTitle>
                    <DialogDescription>
                        Style the launcher button and chat panel for the{' '}
                        <strong>{projectKey}</strong> widget. Saved here, applied automatically to
                        every embed of this key — or bake it inline from the embed dialog.
                    </DialogDescription>
                </DialogHeader>

                {/* Mode — frames everything below: inline has no launcher. */}
                <div className="grid gap-1.5">
                    <SelectField
                        id="mode"
                        label="Widget type"
                        value={theme.mode}
                        options={MODE_OPTIONS}
                        onChange={(v) => set({ mode: v as WidgetMode })}
                    />
                    <p className="text-muted-foreground text-xs">
                        {MODE_OPTIONS.find((o) => o.value === theme.mode)?.hint}
                    </p>
                </div>

                <div className="grid gap-5 lg:grid-cols-2">
                    {/* Controls */}
                    <Tabs defaultValue="launcher" className="min-w-0">
                        <TabsList className="flex-wrap">
                            <TabsTrigger value="launcher" data-testid="admin-widget-appearance-tab-launcher">
                                Launcher
                            </TabsTrigger>
                            <TabsTrigger value="colors" data-testid="admin-widget-appearance-tab-colors">
                                Colors
                            </TabsTrigger>
                            <TabsTrigger value="panel" data-testid="admin-widget-appearance-tab-panel">
                                Panel
                            </TabsTrigger>
                            <TabsTrigger value="type" data-testid="admin-widget-appearance-tab-type">
                                Typography
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="launcher" className="mt-3 grid gap-3">
                            {theme.mode === 'inline' && (
                                <p
                                    className="text-muted-foreground rounded-md border border-dashed border-border p-2 text-xs"
                                    data-testid="admin-widget-appearance-launcher-inline-note"
                                >
                                    Inline chat has no launcher — these settings apply only when the
                                    widget type is <strong>Helper</strong>.
                                </p>
                            )}
                            <SelectField
                                id="launcherSide"
                                label="Position"
                                value={theme.launcherSide}
                                options={SIDE_OPTIONS}
                                onChange={(v) => set({ launcherSide: v as LauncherSide })}
                            />
                            <SelectField
                                id="launcherShape"
                                label="Shape"
                                value={theme.launcherShape}
                                options={SHAPE_OPTIONS}
                                onChange={(v) => set({ launcherShape: v as LauncherShape })}
                            />
                            <SelectField
                                id="launcherIcon"
                                label="Icon"
                                value={theme.launcherIcon}
                                options={ICON_OPTIONS}
                                onChange={(v) => set({ launcherIcon: v as LauncherIcon })}
                            />
                            <TextField
                                id="launcherIconUrl"
                                label="Custom icon URL (https, optional)"
                                value={theme.launcherIconUrl}
                                placeholder="https://cdn.example.com/icon.svg"
                                onChange={(v) => set({ launcherIconUrl: v })}
                            />
                            <TextField
                                id="launcherLabel"
                                label="Button label"
                                value={theme.launcherLabel}
                                placeholder="Chiedi all’assistente"
                                onChange={(v) => set({ launcherLabel: v })}
                            />
                            <div className="grid grid-cols-2 gap-3">
                                <ColorField
                                    id="launcherBackground"
                                    label="Button background"
                                    value={theme.launcherBackground}
                                    onChange={(v) => set({ launcherBackground: v })}
                                />
                                <ColorField
                                    id="launcherForeground"
                                    label="Button text"
                                    value={theme.launcherForeground}
                                    onChange={(v) => set({ launcherForeground: v })}
                                />
                            </div>
                        </TabsContent>

                        <TabsContent value="colors" className="mt-3 grid grid-cols-2 gap-3">
                            <ColorField id="accent" label="Accent" value={theme.accent} onChange={(v) => set({ accent: v })} />
                            <ColorField id="background" label="Panel background" value={theme.background} onChange={(v) => set({ background: v })} />
                            <ColorField id="foreground" label="Text" value={theme.foreground} onChange={(v) => set({ foreground: v })} />
                            <ColorField id="border" label="Border" value={theme.border} onChange={(v) => set({ border: v })} />
                            <ColorField id="headerBackground" label="Header background" value={theme.headerBackground} onChange={(v) => set({ headerBackground: v })} />
                            <ColorField id="headerForeground" label="Header text" value={theme.headerForeground} onChange={(v) => set({ headerForeground: v })} />
                            <ColorField id="userBubbleBackground" label="User bubble" value={theme.userBubbleBackground} onChange={(v) => set({ userBubbleBackground: v })} />
                            <ColorField id="userBubbleForeground" label="User bubble text" value={theme.userBubbleForeground} onChange={(v) => set({ userBubbleForeground: v })} />
                            <ColorField id="assistantBubbleBackground" label="Assistant bubble" value={theme.assistantBubbleBackground} onChange={(v) => set({ assistantBubbleBackground: v })} />
                            <ColorField id="assistantBubbleForeground" label="Assistant bubble text" value={theme.assistantBubbleForeground} onChange={(v) => set({ assistantBubbleForeground: v })} />
                            <ColorField id="muted" label="Muted / status" value={theme.muted} onChange={(v) => set({ muted: v })} />
                        </TabsContent>

                        <TabsContent value="panel" className="mt-3 grid gap-3">
                            <TextField
                                id="panelTitle"
                                label="Panel title"
                                value={theme.panelTitle}
                                placeholder="Assistente"
                                onChange={(v) => set({ panelTitle: v })}
                            />
                            <TextField
                                id="headerLogoUrl"
                                label="Header logo URL (https, optional)"
                                value={theme.headerLogoUrl}
                                placeholder="https://cdn.example.com/logo.png"
                                onChange={(v) => set({ headerLogoUrl: v })}
                            />
                            <RangeField id="panelWidth" label="Width" min={320} max={480} step={10} unit="px" value={theme.panelWidth} onChange={(v) => set({ panelWidth: v })} />
                            <RangeField id="panelHeight" label="Height" min={420} max={680} step={10} unit="px" value={theme.panelHeight} onChange={(v) => set({ panelHeight: v })} />
                            <RangeField id="panelRadius" label="Corner radius" min={0} max={24} step={1} unit="px" value={theme.panelRadius} onChange={(v) => set({ panelRadius: v })} />
                        </TabsContent>

                        <TabsContent value="type" className="mt-3 grid gap-3">
                            <SelectField
                                id="fontFamily"
                                label="Font"
                                value={theme.fontFamily}
                                options={FONT_OPTIONS}
                                onChange={(v) => set({ fontFamily: v as WidgetFontKey })}
                            />
                            <RangeField id="fontSize" label="Base font size" min={12} max={18} step={1} unit="px" value={theme.fontSize} onChange={(v) => set({ fontSize: v })} />
                        </TabsContent>
                    </Tabs>

                    {/* Live preview */}
                    <div className="grid content-start gap-2">
                        <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                            Live preview
                        </span>
                        <WidgetThemePreview theme={theme} />
                    </div>
                </div>

                {save.isError && (
                    <Alert variant="destructive" data-testid="admin-widget-appearance-error">
                        <Ban aria-hidden />
                        <AlertTitle>Could not save the appearance</AlertTitle>
                        <AlertDescription>{extractApiError(save.error)}</AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        data-testid="admin-widget-appearance-reset"
                        onClick={() => setTheme(DEFAULT_THEME)}
                    >
                        <RotateCcw aria-hidden />
                        Reset to defaults
                    </Button>
                    <div className="flex gap-2">
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            data-testid="admin-widget-appearance-save"
                            disabled={save.isPending}
                            onClick={() => save.mutate()}
                        >
                            {save.isPending ? 'Saving…' : 'Save appearance'}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

const CONTROL_CLASS =
    'border-input bg-background ring-offset-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

/** Expand #rgb → #rrggbb and drop alpha so <input type="color"> accepts it. */
function toColorInput(hex: string): string {
    if (/^#[0-9a-fA-F]{3}$/.test(hex)) {
        return `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`;
    }
    if (/^#[0-9a-fA-F]{8}$/.test(hex)) {
        return hex.slice(0, 7);
    }
    return /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#000000';
}

function FieldRow({ id, label, children }: { id: string; label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
        </div>
    );
}

function ColorField({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
}) {
    const valid = HEX_RE.test(value);
    return (
        <FieldRow id={id} label={label}>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    id={id}
                    data-testid={`admin-widget-appearance-field-${id}`}
                    value={toColorInput(value)}
                    onChange={(e) => onChange(e.target.value)}
                    className="border-input h-9 w-10 shrink-0 cursor-pointer rounded-md border p-0.5"
                    aria-label={`${label} color picker`}
                />
                <Input
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    aria-label={`${label} hex value`}
                    aria-invalid={!valid}
                    data-testid={`admin-widget-appearance-hex-${id}`}
                    className="font-mono"
                />
            </div>
        </FieldRow>
    );
}

function TextField({
    id,
    label,
    value,
    placeholder,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    placeholder?: string;
    onChange: (v: string) => void;
}) {
    return (
        <FieldRow id={id} label={label}>
            <Input
                id={id}
                data-testid={`admin-widget-appearance-field-${id}`}
                value={value}
                placeholder={placeholder}
                onChange={(e) => onChange(e.target.value)}
            />
        </FieldRow>
    );
}

function SelectField<T extends string>({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: T;
    options: { value: T; label: string }[];
    onChange: (v: string) => void;
}) {
    return (
        <FieldRow id={id} label={label}>
            <select
                id={id}
                data-testid={`admin-widget-appearance-field-${id}`}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={CONTROL_CLASS}
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </FieldRow>
    );
}

function RangeField({
    id,
    label,
    min,
    max,
    step,
    unit,
    value,
    onChange,
}: {
    id: string;
    label: string;
    min: number;
    max: number;
    step: number;
    unit: string;
    value: number;
    onChange: (v: number) => void;
}) {
    return (
        <FieldRow id={id} label={`${label} — ${value}${unit}`}>
            <input
                type="range"
                id={id}
                data-testid={`admin-widget-appearance-field-${id}`}
                min={min}
                max={max}
                step={step}
                value={value}
                onChange={(e) => onChange(Number(e.target.value))}
                className="accent-[var(--accent-a)]"
                aria-label={label}
                aria-valuetext={`${value}${unit}`}
            />
        </FieldRow>
    );
}
