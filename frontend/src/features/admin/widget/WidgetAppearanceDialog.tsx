import { useMemo, useRef, useState, type ChangeEvent, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, Bot, CheckCircle2, FileJson, Palette, RotateCcw, Upload } from 'lucide-react';

import { api } from '../../../lib/api';
import { DEFAULT_THEME, sanitizeTheme } from '../../../widget/ui/styles';
import { DEFAULT_INTRO, sanitizeIntro } from '../../../widget/ui/intro';
import type {
    LauncherIcon,
    LauncherShape,
    LauncherSide,
    WidgetFontKey,
    WidgetMode,
    WidgetShadow,
    WidgetTheme,
    WidgetIntro,
    WidgetIntroIcon,
    WidgetIntroVariant,
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
import { Textarea } from '@/components/ui/textarea';

import { CopyButton } from './CopyButton';
import { WidgetThemePreview } from './WidgetThemePreview';
import {
    buildWidgetThemeAgentHandoff,
    MAX_WIDGET_THEME_PROFILE_CHARS,
    parseWidgetThemeProfile,
    WidgetThemeProfileError,
} from './widget-theme-exchange';

interface WidgetAppearanceDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    keyId: number;
    label: string;
    projectKey: string;
    /** Current theme of the key (resolved server-side, always complete). */
    initialTheme: WidgetTheme;
    initialIntro?: WidgetIntro;
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
    {
        value: 'fullscreen',
        label: 'Fullscreen chat — entire viewport',
        hint: 'A dedicated, always-open chat surface that fills the browser viewport.',
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
const SHADOW_OPTIONS: { value: WidgetShadow; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'soft', label: 'Soft' },
    { value: 'medium', label: 'Medium' },
    { value: 'strong', label: 'Strong' },
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
 * Per-key appearance editor: a sectioned form (Branding / Launcher / Chat /
 * Composer / Sources) with a live Shadow-DOM preview. Saves the theme via
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
    initialIntro,
}: WidgetAppearanceDialogProps) {
    const qc = useQueryClient();
    const [theme, setTheme] = useState<WidgetTheme>(() => sanitizeTheme(initialTheme));
    const [intro, setIntro] = useState<WidgetIntro>(() => sanitizeIntro(initialIntro ?? DEFAULT_INTRO));
    const [introBulletsDraft, setIntroBulletsDraft] = useState(() =>
        sanitizeIntro(initialIntro ?? DEFAULT_INTRO).bullets.join('\n'),
    );
    const [introSuggestionsDraft, setIntroSuggestionsDraft] = useState(() =>
        sanitizeIntro(initialIntro ?? DEFAULT_INTRO).suggestions
            .map((item) => `${item.label} | ${item.prompt}`)
            .join('\n'),
    );
    const [exchangePanel, setExchangePanel] = useState<'handoff' | 'import' | null>(null);
    const [importSource, setImportSource] = useState('');
    const [importFeedback, setImportFeedback] = useState<
        { kind: 'success' | 'error'; message: string } | null
    >(null);
    const importFileRequest = useRef(0);
    const agentHandoff = useMemo(() => buildWidgetThemeAgentHandoff(theme), [theme]);

    const set = (patch: Partial<WidgetTheme>) => setTheme((prev) => ({ ...prev, ...patch }));
    const setWelcome = (patch: Partial<WidgetIntro>) =>
        setIntro((prev) => ({ ...prev, ...patch }));

    const applyImportedProfile = () => {
        try {
            const profile = parseWidgetThemeProfile(importSource);
            setTheme(profile.theme);
            setImportFeedback({
                kind: 'success',
                message:
                    'JSON applied to the draft and live preview. Review it, then save explicitly.',
            });
        } catch (error) {
            setImportFeedback({
                kind: 'error',
                message: error instanceof WidgetThemeProfileError
                    ? error.message
                    : 'The JSON profile could not be imported.',
            });
        }
    };

    const loadImportFile = async (event: ChangeEvent<HTMLInputElement>) => {
        const input = event.currentTarget;
        const file = input.files?.[0];
        input.value = '';
        const requestId = ++importFileRequest.current;
        if (!file) return;
        // A new file selection supersedes the previous source immediately so
        // a failed/oversized read can never leave stale JSON applicable.
        setImportSource('');
        setImportFeedback(null);
        if (file.size > MAX_WIDGET_THEME_PROFILE_CHARS) {
            setImportFeedback({
                kind: 'error',
                message: 'The JSON profile exceeds the 64 KB limit.',
            });
            return;
        }
        try {
            const source = await file.text();
            if (requestId !== importFileRequest.current) return;
            setImportSource(source);
        } catch {
            if (requestId !== importFileRequest.current) return;
            setImportFeedback({
                kind: 'error',
                message: 'The selected JSON file could not be read.',
            });
        }
    };

    const save = useMutation({
        mutationFn: async () => {
            const payload: { theme: WidgetTheme; intro?: WidgetIntro } = {
                theme,
            };
            if (initialIntro !== undefined || JSON.stringify(intro) !== JSON.stringify(DEFAULT_INTRO)) {
                payload.intro = sanitizeIntro(intro);
            }
            const { data } = await api.patch(`/api/admin/widget-keys/${keyId}`, payload);
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
                className="max-h-[92vh] overflow-y-auto sm:max-w-6xl"
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

                <section
                    className="grid gap-3 rounded-lg border border-border bg-[var(--bg-2)] p-3"
                    data-testid="admin-widget-appearance-agent-tools"
                    aria-label="Agent-assisted appearance setup"
                >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h3 className="m-0 flex items-center gap-2 text-sm font-semibold">
                                <Bot aria-hidden className="size-4 text-[var(--accent-a)]" />
                                Agent-assisted setup
                            </h3>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Give the handoff script to an agent inside the host application,
                                then import its complete JSON profile here. Import only changes the
                                draft and preview; it never saves automatically.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant={exchangePanel === 'handoff' ? 'default' : 'secondary'}
                                data-testid="admin-widget-appearance-handoff"
                                aria-expanded={exchangePanel === 'handoff'}
                                aria-controls="admin-widget-appearance-handoff-panel"
                                onClick={() =>
                                    setExchangePanel((current) =>
                                        current === 'handoff' ? null : 'handoff',
                                    )
                                }
                            >
                                <Bot aria-hidden />
                                Agent handoff
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={exchangePanel === 'import' ? 'default' : 'secondary'}
                                data-testid="admin-widget-appearance-import-json"
                                aria-expanded={exchangePanel === 'import'}
                                aria-controls="admin-widget-appearance-import-panel"
                                onClick={() =>
                                    setExchangePanel((current) =>
                                        current === 'import' ? null : 'import',
                                    )
                                }
                            >
                                <FileJson aria-hidden />
                                Import JSON
                            </Button>
                        </div>
                    </div>

                    {exchangePanel === 'handoff' && (
                        <div
                            id="admin-widget-appearance-handoff-panel"
                            className="grid gap-2"
                            data-testid="admin-widget-appearance-handoff-panel"
                        >
                            <p className="text-muted-foreground text-xs">
                                Copy this self-contained script into your coding agent. It contains
                                only the visual contract and a credential-free starting theme.
                                Widget keys, tenant/project data, free-form text, asset URLs and API
                                credentials are not copied.
                            </p>
                            <div className="overflow-hidden rounded-md border border-border">
                                <div className="bg-muted flex items-center justify-between gap-2 border-b border-border py-1.5 pr-2 pl-3">
                                    <span className="text-muted-foreground font-mono text-[10px] font-semibold tracking-widest uppercase">
                                        Agent handoff script
                                    </span>
                                    <CopyButton
                                        value={agentHandoff}
                                        testId="admin-widget-appearance-handoff-copy"
                                        label="Copy handoff"
                                    />
                                </div>
                                <pre
                                    className="text-foreground max-h-72 overflow-auto bg-background p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap [font-variant-ligatures:none]"
                                    data-testid="admin-widget-appearance-handoff-text"
                                >
                                    {agentHandoff}
                                </pre>
                            </div>
                        </div>
                    )}

                    {exchangePanel === 'import' && (
                        <div
                            id="admin-widget-appearance-import-panel"
                            className="grid gap-3"
                            data-testid="admin-widget-appearance-import-panel"
                        >
                            <div className="grid gap-1.5">
                                <Label htmlFor="admin-widget-appearance-import-input">
                                    Paste the complete JSON profile
                                </Label>
                                <Textarea
                                    id="admin-widget-appearance-import-input"
                                    className="min-h-56 font-mono text-xs [font-variant-ligatures:none]"
                                    data-testid="admin-widget-appearance-import-input"
                                    value={importSource}
                                    spellCheck={false}
                                    placeholder={
                                        '{\n  "_meta": { "format": "askmydocs.widget-theme", "version": 1 },\n  "theme": { ... }\n}'
                                    }
                                    onChange={(event) => {
                                        importFileRequest.current += 1;
                                        setImportSource(event.target.value);
                                        setImportFeedback(null);
                                    }}
                                />
                            </div>
                            <div className="flex flex-wrap items-end justify-between gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="admin-widget-appearance-import-file">
                                        Or load a JSON file
                                    </Label>
                                    <Input
                                        id="admin-widget-appearance-import-file"
                                        type="file"
                                        accept="application/json,.json"
                                        className="max-w-sm"
                                        data-testid="admin-widget-appearance-import-file"
                                        onChange={(event) => void loadImportFile(event)}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    data-testid="admin-widget-appearance-import-apply"
                                    disabled={importSource.trim() === ''}
                                    onClick={applyImportedProfile}
                                >
                                    <Upload aria-hidden />
                                    Apply to draft
                                </Button>
                            </div>

                            {importFeedback?.kind === 'error' && (
                                <Alert
                                    variant="destructive"
                                    data-testid="admin-widget-appearance-import-error"
                                >
                                    <Ban aria-hidden />
                                    <AlertTitle>JSON not imported</AlertTitle>
                                    <AlertDescription>{importFeedback.message}</AlertDescription>
                                </Alert>
                            )}
                            {importFeedback?.kind === 'success' && (
                                <div
                                    role="status"
                                    className="flex items-start gap-2 rounded-md border border-emerald-500/35 bg-emerald-500/10 px-3 py-2 text-sm"
                                    data-testid="admin-widget-appearance-import-success"
                                >
                                    <CheckCircle2 aria-hidden className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                    <span>{importFeedback.message}</span>
                                </div>
                            )}
                        </div>
                    )}
                </section>

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
                    <p
                        className="text-muted-foreground text-xs"
                        data-testid="admin-widget-appearance-mode-note"
                    >
                        Applies to newly-generated embed snippets and the preview. Widgets already
                        embedded keep the type set in their <code>&lt;script&gt;</code> tag — colors
                        update live, layout does not.
                    </p>
                </div>

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(320px,.85fr)]">
                    {/* Controls */}
                    <Tabs defaultValue="branding" className="min-w-0">
                        <TabsList className="h-auto w-full flex-wrap justify-start">
                            <TabsTrigger value="branding" data-testid="admin-widget-appearance-tab-branding">
                                <span data-testid="admin-widget-appearance-tab-colors">Branding</span>
                            </TabsTrigger>
                            <TabsTrigger value="launcher" data-testid="admin-widget-appearance-tab-launcher">
                                Launcher
                            </TabsTrigger>
                            <TabsTrigger value="chat" data-testid="admin-widget-appearance-tab-chat">
                                Chat
                            </TabsTrigger>
                            <TabsTrigger value="composer" data-testid="admin-widget-appearance-tab-composer">
                                Composer
                            </TabsTrigger>
                            <TabsTrigger value="sources" data-testid="admin-widget-appearance-tab-sources">
                                Sources
                            </TabsTrigger>
                            <TabsTrigger value="welcome" data-testid="admin-widget-appearance-tab-welcome">
                                Welcome
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="branding" className="mt-3 grid gap-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <TextField id="panelTitle" label="Panel title" value={theme.panelTitle} placeholder="Assistente" onChange={(v) => set({ panelTitle: v })} />
                                <TextField id="headerLogoUrl" label="Header logo URL (https, optional)" value={theme.headerLogoUrl} placeholder="https://cdn.example.com/logo.png" onChange={(v) => set({ headerLogoUrl: v })} />
                                <SelectField id="fontFamily" label="Font" value={theme.fontFamily} options={FONT_OPTIONS} onChange={(v) => set({ fontFamily: v as WidgetFontKey })} />
                                <RangeField id="fontSize" label="Base font size" min={12} max={18} step={1} unit="px" value={theme.fontSize} onChange={(v) => set({ fontSize: v })} />
                                <ColorField id="accent" label="Accent" value={theme.accent} onChange={(v) => set({ accent: v })} />
                                <ColorField id="accentForeground" label="Accent text" value={theme.accentForeground} onChange={(v) => set({ accentForeground: v })} />
                                <RangeField id="logoHeight" label="Logo height" min={16} max={64} step={1} unit="px" value={theme.logoHeight} onChange={(v) => set({ logoHeight: v })} />
                            </div>
                        </TabsContent>

                        <TabsContent value="launcher" className="mt-3 grid gap-3">
                            {theme.mode !== 'helper' && (
                                <p
                                    className="text-muted-foreground rounded-md border border-dashed border-border p-2 text-xs"
                                    data-testid="admin-widget-appearance-launcher-inline-note"
                                >
                                    This chat mode has no launcher — these settings apply only when
                                    the widget type is <strong>Helper</strong>.
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
                            <div className="grid gap-3 sm:grid-cols-2">
                                <RangeField id="launcherOffsetX" label="Horizontal offset" min={0} max={96} step={1} unit="px" value={theme.launcherOffsetX} onChange={(v) => set({ launcherOffsetX: v })} />
                                <RangeField id="launcherOffsetY" label="Bottom offset" min={0} max={96} step={1} unit="px" value={theme.launcherOffsetY} onChange={(v) => set({ launcherOffsetY: v })} />
                                <RangeField id="launcherSize" label="Button height / circle size" min={40} max={80} step={1} unit="px" value={theme.launcherSize} onChange={(v) => set({ launcherSize: v })} />
                                <SelectField id="launcherShadow" label="Shadow" value={theme.launcherShadow} options={SHADOW_OPTIONS} onChange={(v) => set({ launcherShadow: v as WidgetShadow })} />
                            </div>
                        </TabsContent>

                        <TabsContent value="chat" className="mt-3 grid gap-4">
                            <div className="grid grid-cols-2 gap-3">
                                <ColorField id="background" label="Panel background" value={theme.background} onChange={(v) => set({ background: v })} />
                                <ColorField id="foreground" label="Text" value={theme.foreground} onChange={(v) => set({ foreground: v })} />
                                <ColorField id="border" label="Border" value={theme.border} onChange={(v) => set({ border: v })} />
                                <ColorField id="muted" label="Muted / status" value={theme.muted} onChange={(v) => set({ muted: v })} />
                                <ColorField id="headerBackground" label="Header background" value={theme.headerBackground} onChange={(v) => set({ headerBackground: v })} />
                                <ColorField id="headerForeground" label="Header text" value={theme.headerForeground} onChange={(v) => set({ headerForeground: v })} />
                                <ColorField id="userBubbleBackground" label="User bubble" value={theme.userBubbleBackground} onChange={(v) => set({ userBubbleBackground: v })} />
                                <ColorField id="userBubbleForeground" label="User bubble text" value={theme.userBubbleForeground} onChange={(v) => set({ userBubbleForeground: v })} />
                                <ColorField id="assistantBubbleBackground" label="Assistant bubble" value={theme.assistantBubbleBackground} onChange={(v) => set({ assistantBubbleBackground: v })} />
                                <ColorField id="assistantBubbleForeground" label="Assistant bubble text" value={theme.assistantBubbleForeground} onChange={(v) => set({ assistantBubbleForeground: v })} />
                                <ColorField id="systemBackground" label="System state" value={theme.systemBackground} onChange={(v) => set({ systemBackground: v })} />
                                <ColorField id="systemForeground" label="System text" value={theme.systemForeground} onChange={(v) => set({ systemForeground: v })} />
                                <ColorField id="errorBackground" label="Error state" value={theme.errorBackground} onChange={(v) => set({ errorBackground: v })} />
                                <ColorField id="errorForeground" label="Error text" value={theme.errorForeground} onChange={(v) => set({ errorForeground: v })} />
                                <ColorField id="confirmBackground" label="Confirmation state" value={theme.confirmBackground} onChange={(v) => set({ confirmBackground: v })} />
                                <ColorField id="confirmForeground" label="Confirmation text" value={theme.confirmForeground} onChange={(v) => set({ confirmForeground: v })} />
                                <ColorField id="confirmBorder" label="Confirmation border" value={theme.confirmBorder} onChange={(v) => set({ confirmBorder: v })} />
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <RangeField id="panelWidth" label="Panel width" min={320} max={720} step={10} unit="px" value={theme.panelWidth} onChange={(v) => set({ panelWidth: v })} />
                                <RangeField id="panelHeight" label="Panel height" min={420} max={900} step={10} unit="px" value={theme.panelHeight} onChange={(v) => set({ panelHeight: v })} />
                                <RangeField id="panelRadius" label="Panel radius" min={0} max={24} step={1} unit="px" value={theme.panelRadius} onChange={(v) => set({ panelRadius: v })} />
                                <SelectField id="panelShadow" label="Panel shadow" value={theme.panelShadow} options={SHADOW_OPTIONS} onChange={(v) => set({ panelShadow: v as WidgetShadow })} />
                                <RangeField id="headerPaddingX" label="Header horizontal padding" min={0} max={40} step={1} unit="px" value={theme.headerPaddingX} onChange={(v) => set({ headerPaddingX: v })} />
                                <RangeField id="headerPaddingY" label="Header vertical padding" min={0} max={40} step={1} unit="px" value={theme.headerPaddingY} onChange={(v) => set({ headerPaddingY: v })} />
                                <RangeField id="messagesPadding" label="Messages padding" min={0} max={40} step={1} unit="px" value={theme.messagesPadding} onChange={(v) => set({ messagesPadding: v })} />
                                <RangeField id="messageGap" label="Message gap" min={0} max={32} step={1} unit="px" value={theme.messageGap} onChange={(v) => set({ messageGap: v })} />
                                <RangeField id="bubblePaddingX" label="Bubble horizontal padding" min={0} max={32} step={1} unit="px" value={theme.bubblePaddingX} onChange={(v) => set({ bubblePaddingX: v })} />
                                <RangeField id="bubblePaddingY" label="Bubble vertical padding" min={0} max={32} step={1} unit="px" value={theme.bubblePaddingY} onChange={(v) => set({ bubblePaddingY: v })} />
                                <RangeField id="bubbleRadius" label="Bubble radius" min={0} max={32} step={1} unit="px" value={theme.bubbleRadius} onChange={(v) => set({ bubbleRadius: v })} />
                                <RangeField id="bubbleMaxWidth" label="Bubble max width" min={50} max={100} step={1} unit="%" value={theme.bubbleMaxWidth} onChange={(v) => set({ bubbleMaxWidth: v })} />
                            </div>
                        </TabsContent>

                        <TabsContent value="composer" className="mt-3 grid gap-4">
                            <div className="grid grid-cols-2 gap-3">
                                <ColorField id="composerBackground" label="Composer background" value={theme.composerBackground} onChange={(v) => set({ composerBackground: v })} />
                                <ColorField id="inputBackground" label="Input background" value={theme.inputBackground} onChange={(v) => set({ inputBackground: v })} />
                                <ColorField id="inputForeground" label="Input text" value={theme.inputForeground} onChange={(v) => set({ inputForeground: v })} />
                                <ColorField id="inputPlaceholder" label="Placeholder" value={theme.inputPlaceholder} onChange={(v) => set({ inputPlaceholder: v })} />
                                <ColorField id="focusRing" label="Focus ring" value={theme.focusRing} onChange={(v) => set({ focusRing: v })} />
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <RangeField id="composerPadding" label="Composer padding" min={0} max={32} step={1} unit="px" value={theme.composerPadding} onChange={(v) => set({ composerPadding: v })} />
                                <RangeField id="inputRadius" label="Input radius" min={0} max={32} step={1} unit="px" value={theme.inputRadius} onChange={(v) => set({ inputRadius: v })} />
                                <RangeField id="buttonRadius" label="Button radius" min={0} max={32} step={1} unit="px" value={theme.buttonRadius} onChange={(v) => set({ buttonRadius: v })} />
                            </div>
                        </TabsContent>

                        <TabsContent value="sources" className="mt-3 grid gap-4">
                            <p className="text-muted-foreground text-xs">
                                These tokens style citation chips and the document viewer opened
                                from an answer.
                            </p>
                            <div className="grid grid-cols-2 gap-3">
                                <ColorField id="citationBackground" label="Citation chip" value={theme.citationBackground} onChange={(v) => set({ citationBackground: v })} />
                                <ColorField id="citationForeground" label="Citation text" value={theme.citationForeground} onChange={(v) => set({ citationForeground: v })} />
                                <ColorField id="sourceSidebarBackground" label="Sidebar background" value={theme.sourceSidebarBackground} onChange={(v) => set({ sourceSidebarBackground: v })} />
                                <ColorField id="sourceSidebarForeground" label="Sidebar text" value={theme.sourceSidebarForeground} onChange={(v) => set({ sourceSidebarForeground: v })} />
                                <ColorField id="sourceBackdrop" label="Viewer backdrop" value={theme.sourceBackdrop} onChange={(v) => set({ sourceBackdrop: v })} />
                            </div>
                            <RangeField id="sourceViewerWidth" label="Viewer width" min={560} max={1200} step={20} unit="px" value={theme.sourceViewerWidth} onChange={(v) => set({ sourceViewerWidth: v })} />
                            <RangeField id="sourceViewerRadius" label="Viewer radius" min={0} max={32} step={1} unit="px" value={theme.sourceViewerRadius} onChange={(v) => set({ sourceViewerRadius: v })} />
                        </TabsContent>
                        <TabsContent value="welcome" className="mt-3 grid gap-4">
                            <label className="flex items-center gap-2 text-sm" htmlFor="intro-enabled">
                                <input
                                    id="intro-enabled"
                                    type="checkbox"
                                    data-testid="admin-widget-appearance-field-intro-enabled"
                                    checked={intro.enabled}
                                    onChange={(event) => setWelcome({ enabled: event.target.checked })}
                                    className="size-4 accent-[var(--accent-a)]"
                                />
                                Show a welcome card before the first message
                            </label>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <SelectField
                                    id="intro-variant"
                                    label="Layout"
                                    value={intro.variant}
                                    options={[
                                        { value: 'compact', label: 'Compact' },
                                        { value: 'card', label: 'Card' },
                                        { value: 'hero', label: 'Hero' },
                                    ]}
                                    onChange={(value) => setWelcome({ variant: value as WidgetIntroVariant })}
                                />
                                <SelectField
                                    id="intro-icon"
                                    label="Icon"
                                    value={intro.icon}
                                    options={[
                                        { value: 'sparkles', label: 'Sparkles' },
                                        { value: 'chat', label: 'Chat' },
                                        { value: 'search', label: 'Search' },
                                        { value: 'help', label: 'Help' },
                                        { value: 'none', label: 'No icon' },
                                    ]}
                                    onChange={(value) => setWelcome({ icon: value as WidgetIntroIcon })}
                                />
                                <TextField id="intro-eyebrow" label="Eyebrow" value={intro.eyebrow} placeholder="Documentation assistant" onChange={(value) => setWelcome({ eyebrow: value })} />
                                <TextField id="intro-title" label="Title" value={intro.title} placeholder="How can I help?" onChange={(value) => setWelcome({ title: value })} />
                                <TextField id="intro-subtitle" label="Subtitle" value={intro.subtitle} placeholder="Answers from official sources" onChange={(value) => setWelcome({ subtitle: value })} />
                                <TextField id="intro-image-url" label="Image URL (https, optional)" value={intro.imageUrl} placeholder="https://cdn.example.com/assistant.webp" onChange={(value) => setWelcome({ imageUrl: value })} />
                                <TextField id="intro-image-alt" label="Image alternative text" value={intro.imageAlt} placeholder="Product assistant" onChange={(value) => setWelcome({ imageAlt: value })} />
                            </div>
                            <FieldRow id="intro-body" label="Description">
                                <Textarea
                                    id="intro-body"
                                    data-testid="admin-widget-appearance-field-intro-body"
                                    value={intro.body}
                                    maxLength={600}
                                    onChange={(event) => setWelcome({ body: event.target.value })}
                                    placeholder="Explain what visitors can ask this chat."
                                />
                            </FieldRow>
                            <FieldRow id="intro-bullets" label="Benefits — one per line, maximum four">
                                <Textarea
                                    id="intro-bullets"
                                    data-testid="admin-widget-appearance-field-intro-bullets"
                                    value={introBulletsDraft}
                                    onChange={(event) => {
                                        setIntroBulletsDraft(event.target.value);
                                        setWelcome({ bullets: event.target.value.split('\n').slice(0, 4) });
                                    }}
                                    placeholder={'Search official documentation\nGet verifiable sources'}
                                />
                            </FieldRow>
                            <FieldRow id="intro-suggestions" label="Suggested questions — Label | prompt, one per line">
                                <Textarea
                                    id="intro-suggestions"
                                    data-testid="admin-widget-appearance-field-intro-suggestions"
                                    value={introSuggestionsDraft}
                                    onChange={(event) => {
                                        setIntroSuggestionsDraft(event.target.value);
                                        setWelcome({ suggestions: event.target.value.split('\n').slice(0, 4).flatMap((line) => {
                                            const separator = line.indexOf('|');
                                            if (separator < 0) return [];
                                            return [{ label: line.slice(0, separator).trim(), prompt: line.slice(separator + 1).trim() }];
                                        }) });
                                    }}
                                    placeholder={'How do I start? | Explain how to start using the product'}
                                />
                            </FieldRow>
                            <div className="flex flex-wrap gap-4">
                                <label className="flex items-center gap-2 text-sm" htmlFor="intro-dismissible">
                                    <input id="intro-dismissible" type="checkbox" checked={intro.dismissible} onChange={(event) => setWelcome({ dismissible: event.target.checked })} className="size-4 accent-[var(--accent-a)]" />
                                    Can be dismissed
                                </label>
                                <label className="flex items-center gap-2 text-sm" htmlFor="intro-hide-first">
                                    <input id="intro-hide-first" type="checkbox" checked={intro.hideAfterFirstMessage} onChange={(event) => setWelcome({ hideAfterFirstMessage: event.target.checked })} className="size-4 accent-[var(--accent-a)]" />
                                    Hide after first message
                                </label>
                            </div>
                        </TabsContent>
                    </Tabs>

                    {/* Live preview */}
                    <div className="grid content-start gap-2">
                        <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                            Live preview
                        </span>
                        <WidgetThemePreview theme={theme} intro={intro} />
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
                        onClick={() => {
                            setTheme(DEFAULT_THEME);
                            setIntro(DEFAULT_INTRO);
                            setIntroBulletsDraft('');
                            setIntroSuggestionsDraft('');
                            setImportFeedback(null);
                        }}
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
