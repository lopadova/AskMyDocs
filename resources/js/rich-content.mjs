/**
 * Rich-content parsing for chat messages.
 *
 * Pure functions extracted from chat.blade.php to be unit-testable.
 * Handles the two artifact types the AI is prompted to emit:
 *   - ~~~chart  … ~~~      → Chart.js JSON payload
 *   - ~~~actions … ~~~      → action-button array (copy, download)
 *
 * Nothing here touches the DOM — callers consume the returned strings/data.
 */

const CHART_FENCE = /~~~chart\s*\n([\s\S]*?)\n~~~/g;
const ACTIONS_FENCE = /~~~actions\s*\n([\s\S]*?)\n~~~/g;

/**
 * Replace every ~~~chart fenced block with a canvas placeholder.
 * The original JSON is preserved (single-quote-escaped) in `data-chart`.
 *
 * @param {string} text - raw assistant text
 * @param {string|number} msgId - message id used to namespace canvas ids
 * @param {() => string} [randSuffix] - optional seeded id suffix for tests
 * @returns {{ html: string, chartCount: number }}
 */
export function extractChartBlocks(text, msgId, randSuffix) {
    if (!text) return { html: '', chartCount: 0 };

    let count = 0;
    const html = text.replace(CHART_FENCE, (_, json) => {
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
 * Replace every ~~~actions fenced block with interactive buttons.
 * Silently drops malformed JSON blocks (matching the production behavior).
 *
 * @param {string} text
 * @returns {{ html: string, actionCount: number }}
 */
export function extractActionBlocks(text) {
    if (!text) return { html: '', actionCount: 0 };

    let count = 0;
    const html = text.replace(ACTIONS_FENCE, (_, json) => {
        try {
            const actions = JSON.parse(json);
            if (!Array.isArray(actions)) return '';
            count += actions.length;
            return `<div class="my-3 flex flex-wrap gap-2">${actions.map(renderAction).join('')}</div>`;
        } catch {
            return '';
        }
    });

    return { html, actionCount: count };
}

/**
 * Render a single action object to its HTML representation.
 * Returns '' for unsupported action types.
 *
 * @param {{action: string, label?: string, data?: string, filename?: string}} a
 */
export function renderAction(a) {
    if (!a || typeof a !== 'object') return '';
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
 * Add a 'Copia' button after every <pre><code> block produced by a markdown
 * renderer. The raw (unescaped) code ends up in data-code so clipboard copy
 * pastes the original source.
 *
 * @param {string} html - already-parsed markdown HTML
 * @returns {string}
 */
export function addCodeCopyButtons(html) {
    if (!html) return '';
    return html.replace(
        /<pre><code([^>]*)>([\s\S]*?)<\/code><\/pre>/g,
        (_, attrs, code) => {
            const raw = code
                .replace(/<[^>]+>/g, '')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&amp;/g, '&')
                .replace(/&quot;/g, '"');
            const escaped = raw.replace(/"/g, '&quot;');
            return `<pre style="position:relative"><code${attrs}>${code}</code><button class="code-copy-btn" data-code="${escaped}">Copia</button></pre>`;
        }
    );
}

/**
 * Orchestrator: chart → actions → markdown → code-copy.
 * Markdown parser is injected so tests don't need a full markdown lib.
 *
 * @param {string} text
 * @param {string|number} msgId
 * @param {(src: string) => string} markdownParser
 * @param {{ randSuffix?: () => string }} [options]
 */
export function renderRichContent(text, msgId, markdownParser, options = {}) {
    if (!text) return '';
    const afterCharts = extractChartBlocks(text, msgId, options.randSuffix).html;
    const afterActions = extractActionBlocks(afterCharts).html;
    const html = markdownParser ? markdownParser(afterActions) : afterActions;
    return addCodeCopyButtons(html);
}

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
