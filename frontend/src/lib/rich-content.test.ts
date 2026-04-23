import { describe, it, expect } from 'vitest';
import {
    extractChartBlocks,
    extractActionBlocks,
    renderAction,
    addCodeCopyButtons,
    renderRichContent,
    escapeHtml,
} from './rich-content';

describe('extractChartBlocks (TS port)', () => {
    it('returns empty result for empty input', () => {
        expect(extractChartBlocks('', 1)).toEqual({ html: '', chartCount: 0 });
    });

    it('passes plain text through untouched', () => {
        const out = extractChartBlocks('hello world', 42);
        expect(out.html).toBe('hello world');
        expect(out.chartCount).toBe(0);
    });

    it('replaces a chart block with a canvas placeholder', () => {
        const text = `pre\n\n~~~chart\n{"type":"bar"}\n~~~\n\npost`;
        const out = extractChartBlocks(text, 7, () => 'abc');
        expect(out.chartCount).toBe(1);
        expect(out.html).toContain('id="chart-7-abc"');
        expect(out.html).toContain("data-chart='{\"type\":\"bar\"}'");
        expect(out.html).toContain('pre');
        expect(out.html).toContain('post');
    });

    it('escapes single quotes inside the preserved JSON', () => {
        const text = `~~~chart\n{"title":"it's hot"}\n~~~`;
        const out = extractChartBlocks(text, 1, () => 'x');
        expect(out.html).toContain('&#39;');
    });
});

describe('extractActionBlocks (TS port)', () => {
    it('renders a copy button', () => {
        const text = `~~~actions\n[{"action":"copy","label":"Copy","data":"x"}]\n~~~`;
        const out = extractActionBlocks(text);
        expect(out.actionCount).toBe(1);
        expect(out.html).toContain('data-action="copy"');
        expect(out.html).toContain('data-content="x"');
        expect(out.html).toContain('>Copy<');
    });

    it('drops malformed JSON silently', () => {
        const text = `~~~actions\n{ not json }\n~~~`;
        const out = extractActionBlocks(text);
        expect(out.actionCount).toBe(0);
        expect(out.html).not.toContain('btn-action-');
    });

    it('drops non-array JSON silently', () => {
        const text = `~~~actions\n{"not":"an array"}\n~~~`;
        const out = extractActionBlocks(text);
        expect(out.actionCount).toBe(0);
    });
});

describe('renderAction (TS port)', () => {
    it('returns empty string for invalid shapes', () => {
        expect(renderAction(null)).toBe('');
        expect(renderAction(undefined)).toBe('');
        expect(renderAction({} as never)).toBe('');
    });

    it('renders download anchor with encoded payload', () => {
        const html = renderAction({ action: 'download', label: 'Save', data: 'a b', filename: 'x.txt' });
        expect(html).toContain('download="x.txt"');
        expect(html).toContain('href="data:text/plain;charset=utf-8,a%20b"');
    });
});

describe('addCodeCopyButtons (TS port)', () => {
    it('wraps each pre/code with a copy button', () => {
        const html = '<pre><code class="lang-ts">const a = 1;</code></pre>';
        const out = addCodeCopyButtons(html);
        expect(out).toContain('code-copy-btn');
        expect(out).toContain('data-code="const a = 1;"');
    });
});

describe('renderRichContent (TS port)', () => {
    it('pipes text through chart → actions → markdown → code-copy', () => {
        const out = renderRichContent('hello', 1, (s) => `MD(${s})`);
        expect(out).toBe('MD(hello)');
    });
});

describe('escapeHtml (TS port)', () => {
    it('escapes &, <, >, "', () => {
        expect(escapeHtml('<a href="x">&y</a>')).toBe(
            '&lt;a href=&quot;x&quot;&gt;&amp;y&lt;/a&gt;',
        );
    });
});
