import { useMemo, useState } from 'react';
import { ExternalLink, Info } from 'lucide-react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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

import { DEFAULT_THEME, sanitizeTheme } from '../../../widget/ui/styles';
import type { WidgetTheme } from '../../../widget/types';
import { CopyButton } from './CopyButton';
import { highlightSnippet } from './highlightSnippet';

interface EmbedCodeDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Public key (pk_…) the host site authenticates with. */
    publicKey: string;
    /** Project the key answers from — shown for context. */
    projectKey: string;
    /** Human label of the key (used in the snippet comment). */
    label: string;
    /** Absolute base URL of THIS AskMyDocs instance (script + API origin). */
    apiBase: string;
    /** Origins the key accepts — shown so the operator can self-check the allowlist. */
    allowedOrigins?: string[];
    /**
     * Plain secret (sk_…) — only available right after create/rotate.
     * Drives the proxy-mode example; absent on the table "Embed" action.
     */
    secret?: string | null;
    /**
     * Saved appearance theme of the key. When present and non-default, the
     * operator can bake it inline into the snippet (`theme: {…}`).
     */
    theme?: WidgetTheme;
}

/** Escape a value so it is safe inside a single-quoted JS string literal. */
function jsString(value: string): string {
    return value.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

/** Trim a trailing slash so `${base}/widget/...` never doubles up. */
function trimTrailingSlash(value: string): string {
    return value.replace(/\/+$/, '');
}

/**
 * Read-only code block in an editor-like frame: a header bar with the
 * language label + copy button, then the highlighted source.
 *
 * `[font-variant-ligatures:none]` is load-bearing: Geist Mono renders
 * coding ligatures, turning `<!--`/`-->` into `—`/`→` and making the HTML
 * comment look corrupt. Disabling ligatures shows the markers literally.
 */
function CodeBlock({
    code,
    copyTestId,
    snippetTestId,
    lang = 'HTML',
}: {
    code: string;
    copyTestId: string;
    snippetTestId: string;
    lang?: string;
}) {
    return (
        <div className="overflow-hidden rounded-md border border-border">
            <div className="bg-muted flex items-center justify-between gap-2 border-b border-border py-1.5 pr-2 pl-3">
                <span className="text-muted-foreground font-mono text-[10px] font-semibold tracking-widest uppercase">
                    {lang}
                </span>
                <CopyButton value={code} testId={copyTestId} />
            </div>
            <pre
                data-testid={snippetTestId}
                className="text-foreground max-h-72 overflow-auto bg-[var(--bg-2)] p-3 font-mono text-xs leading-relaxed [font-variant-ligatures:none]"
            >
                <code>{highlightSnippet(code)}</code>
            </pre>
        </div>
    );
}

export function EmbedCodeDialog({
    open,
    onOpenChange,
    publicKey,
    projectKey,
    label,
    apiBase,
    allowedOrigins = [],
    secret,
    theme,
}: EmbedCodeDialogProps) {
    const [title, setTitle] = useState('');
    const [launcherLabel, setLauncherLabel] = useState('');
    const [autoOpen, setAutoOpen] = useState(false);
    const [includeTheme, setIncludeTheme] = useState(false);
    const [base, setBase] = useState(apiBase);
    // Inline-mode only: the host container the chat block mounts into.
    const [containerId, setContainerId] = useState('askmydocs-chat');
    const [height, setHeight] = useState('600');

    // Layout the key was created with — drives helper vs inline snippet.
    const mode = theme?.mode === 'inline' ? 'inline' : 'helper';

    const resolvedBase = trimTrailingSlash(base.trim());
    const scriptSrc = `${resolvedBase}/widget/askmydocs-widget.js`;

    // Sanitize the container id to a safe HTML id / CSS selector; both the
    // `<div id>` and `mount: '#…'` are derived from this single value.
    const safeContainerId = useMemo(
        () => containerId.trim().replace(/[^A-Za-z0-9_-]/g, '') || 'askmydocs-chat',
        [containerId],
    );
    const mountSelector = `#${safeContainerId}`;
    const containerHeight = useMemo(() => {
        const h = Number.parseInt(height, 10);
        return Number.isFinite(h) && h > 0 ? Math.min(h, 2000) : 600;
    }, [height]);

    // Only the fields that differ from the default theme — keeps the snippet
    // small and the inline override explicit.
    const themeDelta = useMemo<Partial<WidgetTheme>>(() => {
        if (!theme) {
            return {};
        }
        const t = sanitizeTheme(theme);
        const out: Partial<WidgetTheme> = {};
        (Object.keys(DEFAULT_THEME) as (keyof WidgetTheme)[]).forEach((k) => {
            // `mode` is a layout concern surfaced as top-level `mode:` config,
            // not part of the graphical theme block — never bake it here.
            if (k === 'mode') {
                return;
            }
            if (t[k] !== DEFAULT_THEME[k]) {
                (out as Record<string, unknown>)[k] = t[k];
            }
        });
        return out;
    }, [theme]);
    const hasCustomTheme = Object.keys(themeDelta).length > 0;

    const snippet = useMemo(() => {
        const cfg: string[] = [`    key: '${jsString(publicKey)}',`];
        if (resolvedBase) {
            cfg.push(`    apiBase: '${jsString(resolvedBase)}',`);
        }
        if (mode === 'inline') {
            // Inline chat mounts into a host container; both lines are required.
            cfg.push("    mode: 'inline',");
            cfg.push(`    mount: '${jsString(mountSelector)}',`);
        }
        if (title.trim()) {
            cfg.push(`    title: '${jsString(title.trim())}',`);
        }
        // Launcher-only options have no effect in inline mode.
        if (mode === 'helper') {
            if (launcherLabel.trim()) {
                cfg.push(`    launcherLabel: '${jsString(launcherLabel.trim())}',`);
            }
            if (autoOpen) {
                cfg.push('    autoOpen: true,');
            }
        }
        if (includeTheme && hasCustomTheme) {
            // JSON.stringify is safe inside <script> once `</` is broken up so
            // the host markup can't close the script element early.
            const raw = JSON.stringify(themeDelta, null, 2).replace(/<\/(?=script)/gi, '<\\/');
            const entry =
                raw
                    .split('\n')
                    .map((line, i) => (i === 0 ? `    theme: ${line}` : `      ${line}`))
                    .join('\n') + ',';
            cfg.push(entry);
        }

        // Inline mode prepends the host container the chat fills.
        const head =
            mode === 'inline'
                ? [
                      `<!-- AskMyDocs KITT widget (inline chat) — ${label} -->`,
                      `<div id="${safeContainerId}" style="height: ${containerHeight}px;"></div>`,
                  ]
                : [`<!-- AskMyDocs KITT widget — ${label} -->`];

        return [
            ...head,
            '<script>',
            '  window.AskMyDocsWidget = {',
            ...cfg.map((line) => `  ${line}`),
            '  };',
            '</script>',
            `<script src="${scriptSrc}" defer></script>`,
        ].join('\n');
    }, [
        publicKey,
        resolvedBase,
        mode,
        mountSelector,
        safeContainerId,
        containerHeight,
        title,
        launcherLabel,
        autoOpen,
        includeTheme,
        hasCustomTheme,
        themeDelta,
        label,
        scriptSrc,
    ]);

    const proxyConfigSnippet = [
        '<script>',
        '  window.AskMyDocsWidget = {',
        `    key: '${jsString(publicKey)}',`,
        "    apiBase: 'https://your-site.com/api/widget-proxy',",
        '  };',
        '</script>',
        '<script src="https://your-site.com/widget-proxy/askmydocs-widget.js" defer></script>',
    ].join('\n');

    const proxyServerSnippet = [
        '// Server-side proxy (Node/Express) — keeps pk_/sk_ off the browser.',
        "app.post('/api/widget-proxy/*', async (req, res) => {",
        '  const upstream = await fetch(',
        `    '${resolvedBase}/api/widget' + req.path.replace('/api/widget-proxy', ''),`,
        '    {',
        '      method: req.method,',
        '      headers: {',
        "        'Content-Type': 'application/json',",
        "        Accept: 'application/json',",
        `        'X-Widget-Key': '${jsString(publicKey)}',`,
        `        Authorization: 'Bearer ${secret ? jsString(secret) : 'sk_…'}',`,
        '      },',
        '      body: JSON.stringify(req.body),',
        '    },',
        '  );',
        '  res.status(upstream.status).json(await upstream.json());',
        '});',
    ].join('\n');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                data-testid="admin-widget-keys-embed-dialog"
                className="sm:max-w-2xl"
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        Embed the widget
                        <Badge variant="muted">{projectKey}</Badge>
                        {mode === 'inline' && (
                            <Badge variant="muted" data-testid="admin-widget-embed-mode-inline">
                                inline chat
                            </Badge>
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        Paste the snippet into the host site, just before the closing{' '}
                        <code className="font-mono">&lt;/body&gt;</code> tag. The widget loads
                        itself, talks to this AskMyDocs instance, and answers only from the{' '}
                        <strong>{projectKey}</strong> knowledge base.
                    </DialogDescription>
                </DialogHeader>

                <Tabs defaultValue="quickstart">
                    <TabsList>
                        <TabsTrigger
                            value="quickstart"
                            data-testid="admin-widget-embed-tab-quickstart"
                        >
                            Quick start
                        </TabsTrigger>
                        <TabsTrigger
                            value="options"
                            data-testid="admin-widget-embed-tab-options"
                        >
                            Options
                        </TabsTrigger>
                        <TabsTrigger value="proxy" data-testid="admin-widget-embed-tab-proxy">
                            Proxy (advanced)
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="quickstart" className="mt-3 grid gap-3">
                        <CodeBlock
                            code={snippet}
                            lang="HTML"
                            snippetTestId="admin-widget-embed-snippet"
                            copyTestId="admin-widget-embed-copy"
                        />
                        <div
                            className="text-xs"
                            data-testid="admin-widget-embed-allowed-origins"
                        >
                            <span className="text-muted-foreground">This key accepts: </span>
                            {allowedOrigins.length > 0 ? (
                                <span className="text-foreground font-mono">
                                    {allowedOrigins.join(', ')}
                                </span>
                            ) : (
                                <span className="text-[var(--warn)] font-medium">
                                    any origin — no allowlist set (lock this down in production)
                                </span>
                            )}
                        </div>
                        <Alert variant="info">
                            <Info aria-hidden />
                            <AlertTitle>
                                {mode === 'inline'
                                    ? 'A container + two tags'
                                    : 'Two tags, nothing else'}
                            </AlertTitle>
                            <AlertDescription>
                                {mode === 'inline' ? (
                                    <>
                                        The <code className="font-mono">&lt;div&gt;</code> is where
                                        the chat renders — give it the height you want. The first{' '}
                                        <code className="font-mono">&lt;script&gt;</code> sets the
                                        configuration and the{' '}
                                        <code className="font-mono">mount</code> target; the second
                                        downloads the widget. The host page's origin must be in the
                                        list above or the request is rejected (403).
                                    </>
                                ) : (
                                    <>
                                        The first{' '}
                                        <code className="font-mono">&lt;script&gt;</code> sets the
                                        configuration; the second downloads the widget. The host
                                        page's origin must be in the list above or the request is
                                        rejected (403).
                                    </>
                                )}
                            </AlertDescription>
                        </Alert>
                    </TabsContent>

                    <TabsContent value="options" className="mt-3 grid gap-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="embed-opt-title">Panel title</Label>
                                <Input
                                    id="embed-opt-title"
                                    data-testid="admin-widget-embed-opt-title"
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="Assistant"
                                />
                            </div>
                            {mode === 'helper' && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="embed-opt-launcher">Launcher label</Label>
                                    <Input
                                        id="embed-opt-launcher"
                                        data-testid="admin-widget-embed-opt-launcher"
                                        value={launcherLabel}
                                        onChange={(e) => setLauncherLabel(e.target.value)}
                                        placeholder="Ask"
                                    />
                                </div>
                            )}
                            {mode === 'inline' && (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="embed-opt-container">Container id</Label>
                                        <Input
                                            id="embed-opt-container"
                                            data-testid="admin-widget-embed-opt-container"
                                            value={containerId}
                                            onChange={(e) => setContainerId(e.target.value)}
                                            placeholder="askmydocs-chat"
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="embed-opt-height">
                                            Container height (px)
                                        </Label>
                                        <Input
                                            id="embed-opt-height"
                                            type="number"
                                            min={200}
                                            max={2000}
                                            data-testid="admin-widget-embed-opt-height"
                                            value={height}
                                            onChange={(e) => setHeight(e.target.value)}
                                            placeholder="600"
                                        />
                                    </div>
                                </>
                            )}
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="embed-opt-apibase">
                                    API base URL
                                    <span className="text-muted-foreground font-normal">
                                        (this AskMyDocs instance)
                                    </span>
                                </Label>
                                <Input
                                    id="embed-opt-apibase"
                                    data-testid="admin-widget-embed-opt-apibase"
                                    value={base}
                                    onChange={(e) => setBase(e.target.value)}
                                    placeholder="https://kb.example.com"
                                />
                            </div>
                            {mode === 'helper' && (
                                <label
                                    className="flex items-center gap-2 text-sm sm:col-span-2"
                                    htmlFor="embed-opt-autoopen"
                                >
                                    <input
                                        id="embed-opt-autoopen"
                                        type="checkbox"
                                        data-testid="admin-widget-embed-opt-autoopen"
                                        checked={autoOpen}
                                        onChange={(e) => setAutoOpen(e.target.checked)}
                                        className="size-4 accent-[var(--accent-a)]"
                                    />
                                    Open the panel automatically on page load
                                </label>
                            )}
                            {theme && (
                                <label
                                    className="flex items-center gap-2 text-sm sm:col-span-2"
                                    htmlFor="embed-opt-theme"
                                >
                                    <input
                                        id="embed-opt-theme"
                                        type="checkbox"
                                        data-testid="admin-widget-embed-opt-theme"
                                        checked={includeTheme}
                                        disabled={!hasCustomTheme}
                                        onChange={(e) => setIncludeTheme(e.target.checked)}
                                        className="size-4 accent-[var(--accent-a)]"
                                    />
                                    <span>
                                        Bake the saved appearance inline
                                        {!hasCustomTheme && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (currently default — nothing to bake)
                                            </span>
                                        )}
                                    </span>
                                </label>
                            )}
                        </div>
                        <CodeBlock
                            code={snippet}
                            lang="HTML"
                            snippetTestId="admin-widget-embed-snippet-options"
                            copyTestId="admin-widget-embed-copy-options"
                        />
                    </TabsContent>

                    <TabsContent value="proxy" className="mt-3 grid gap-3">
                        <Alert variant="info">
                            <Info aria-hidden />
                            <AlertTitle>Keep the key off the browser</AlertTitle>
                            <AlertDescription>
                                In proxy mode the host site forwards widget calls through its own
                                backend with a secret (<code className="font-mono">sk_…</code>), so
                                neither key is exposed client-side.{' '}
                                {secret ? (
                                    <span>
                                        Your secret is shown below — store it now, it is not
                                        retrievable later.
                                    </span>
                                ) : (
                                    <span>
                                        Rotate this key to mint a fresh secret, or run{' '}
                                        <code className="font-mono">
                                            php artisan widget:issue-secret {publicKey}
                                        </code>
                                        .
                                    </span>
                                )}
                            </AlertDescription>
                        </Alert>
                        <div className="grid gap-1.5">
                            <span className="text-muted-foreground text-xs font-medium">
                                1. Browser config (points at YOUR proxy)
                            </span>
                            <CodeBlock
                                code={proxyConfigSnippet}
                                lang="HTML"
                                snippetTestId="admin-widget-embed-proxy-config"
                                copyTestId="admin-widget-embed-proxy-config-copy"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <span className="text-muted-foreground text-xs font-medium">
                                2. Server proxy (adds the Bearer secret)
                            </span>
                            <CodeBlock
                                code={proxyServerSnippet}
                                lang="JS"
                                snippetTestId="admin-widget-embed-proxy-server"
                                copyTestId="admin-widget-embed-proxy-server-copy"
                            />
                        </div>
                    </TabsContent>
                </Tabs>

                <a
                    href="https://github.com/lopadova/AskMyDocs/blob/main/frontend/src/widget/README.md"
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-xs"
                    data-testid="admin-widget-embed-docs-link"
                >
                    <ExternalLink aria-hidden className="size-3" />
                    Full integration guide
                </a>
            </DialogContent>
        </Dialog>
    );
}
