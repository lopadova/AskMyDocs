/**
 * Rich-content parsing for chat messages (TypeScript port of
 * `resources/js/rich-content.mjs`).
 *
 * Pure functions extracted from the legacy Blade chat UI so they can
 * be unit-tested in isolation. Handles the two artifact types the AI
 * is prompted to emit:
 *   - ~~~chart  … ~~~      → Chart.js JSON payload (recharts consumer
 *                           reads the JSON from `data-chart` and renders
 *                           a <ChartBlock> in place — see MessageBubble)
 *   - ~~~actions … ~~~      → action-button array (copy, download)
 *
 * Nothing here touches the DOM — callers consume the returned strings.
 * API surface is identical to the .mjs source to keep the legacy spec
 * working during the migration window (see `npm run test:legacy`).
 */

export interface ExtractResult {
    html: string;
    chartCount: number;
}

export interface ExtractActionsResult {
    html: string;
    actionCount: number;
}

export interface ActionDescriptor {
    action?: string;
    label?: string;
    data?: string;
    filename?: string;
}

const CHART_FENCE = /~~~chart\s*\n([\s\S]*?)\n~~~/g;
const ACTIONS_FENCE = /~~~actions\s*\n([\s\S]*?)\n~~~/g;

/**
 * Replace every `~~~chart` fenced block with a canvas placeholder. The
 * original JSON is preserved (single-quote-escaped) in `data-chart` so
 * the React renderer can parse it back out and feed recharts.
 */
export function extractChartBlocks(
    text: string | null | undefined,
    msgId: string | number,
    randSuffix?: () => string,
): ExtractResult {
    if (!text) {
        return { html: '', chartCount: 0 };
    }

    let count = 0;
    const html = text.replace(CHART_FENCE, (_match, json: string) => {
        const suffix = typeof randSuffix === 'function'
            ? randSuffix()
            : Math.random().toString(36).slice(2, 8);
        const chartId = `chart-${msgId}-${suffix}`;
        count += 1;
        const safe = json.replace(/'/g, '&#39;');
        return `<div class="my-3 p-3 bg-gray-50 rounded-lg border"><canvas id="${chartId}" data-chart='${safe}'></canvas></div>`;
    });

    return { html, chartCount: count };
}

/**
 * Replace every `~~~actions` fenced block with interactive buttons.
 * Silently drops malformed JSON blocks (matching the production
 * behaviour — error UI is the caller's job).
 */
export function extractActionBlocks(text: string | null | undefined): ExtractActionsResult {
    if (!text) {
        return { html: '', actionCount: 0 };
    }

    let count = 0;
    const html = text.replace(ACTIONS_FENCE, (_match, json: string) => {
        try {
            const actions: unknown = JSON.parse(json);
            if (!Array.isArray(actions)) {
                return '';
            }
            count += actions.length;
            return `<div class="my-3 flex flex-wrap gap-2">${actions.map((a) => renderAction(a as ActionDescriptor)).join('')}</div>`;
        } catch {
            return '';
        }
    });

    return { html, actionCount: count };
}

/**
 * Render a single action descriptor to HTML. Returns '' for unsupported
 * types so the caller's join('') naturally elides them.
 */
export function renderAction(a: ActionDescriptor | null | undefined): string {
    if (!a || typeof a !== 'object') {
        return '';
    }
    const label = escapeHtml(a.label ?? '');

    if (a.action === 'copy') {
        const data = (a.data ?? '').replace(/"/g, '&quot;');
        return `<button data-action="copy" data-content="${data}" class="btn-action-copy">${label}</button>`;
    }
    if (a.action === 'download') {
        const filename = escapeHtml(a.filename ?? 'file.txt');
        const href = `data:text/plain;charset=utf-8,${encodeURIComponent(a.data ?? '')}`;
        return `<a href="${href}" download="${filename}" class="btn-action-download">${label}</a>`;
    }
    return '';
}

/**
 * Add a copy button after every `<pre><code>` block produced by a
 * markdown renderer. The raw (unescaped) code ends up in `data-code`
 * so a clipboard copy pastes the original source.
 */
export function addCodeCopyButtons(html: string | null | undefined): string {
    if (!html) {
        return '';
    }
    return html.replace(
        /<pre><code([^>]*)>([\s\S]*?)<\/code><\/pre>/g,
        (_match, attrs: string, code: string) => {
            const raw = code
                .replace(/<[^>]+>/g, '')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&amp;/g, '&')
                .replace(/&quot;/g, '"');
            const escaped = raw.replace(/"/g, '&quot;');
            return `<pre style="position:relative"><code${attrs}>${code}</code><button class="code-copy-btn" data-code="${escaped}">Copia</button></pre>`;
        },
    );
}

/**
 * Orchestrator: chart → actions → markdown → code-copy. Markdown
 * parser is injected so tests don't need a full markdown lib. The
 * React chat renderer uses `react-markdown`, not this orchestrator —
 * it exists for compatibility with the legacy Blade flow and the
 * transition window.
 */
export function renderRichContent(
    text: string | null | undefined,
    msgId: string | number,
    markdownParser?: (src: string) => string,
    options: { randSuffix?: () => string } = {},
): string {
    if (!text) {
        return '';
    }
    const afterCharts = extractChartBlocks(text, msgId, options.randSuffix).html;
    const afterActions = extractActionBlocks(afterCharts).html;
    const html = markdownParser ? markdownParser(afterActions) : afterActions;
    return addCodeCopyButtons(html);
}

export function escapeHtml(s: unknown): string {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
