import { describe, expect, it } from 'vitest';
import {
    addCodeCopyButtons,
    extractActionBlocks,
    extractChartBlocks,
    renderAction,
    renderRichContent,
} from '../../resources/js/rich-content.mjs';

describe('extractChartBlocks', () => {
    it('returns original text when no chart block present', () => {
        const out = extractChartBlocks('plain text', 42);
        expect(out.html).toBe('plain text');
        expect(out.chartCount).toBe(0);
    });

    it('replaces a single ~~~chart block with a canvas placeholder', () => {
        const text = `pre\n\n~~~chart\n{"type":"bar","labels":["A","B"],"datasets":[{"data":[1,2]}]}\n~~~\n\npost`;
        const out = extractChartBlocks(text, 7, () => 'abc123');

        expect(out.chartCount).toBe(1);
        expect(out.html).toContain('<canvas');
        expect(out.html).toContain('id="chart-7-abc123"');
        expect(out.html).toContain('data-chart=');
        expect(out.html).toContain('pre');
        expect(out.html).toContain('post');
    });

    it('counts and replaces multiple chart blocks', () => {
        const text = `~~~chart\n{"type":"pie"}\n~~~\n\n~~~chart\n{"type":"line"}\n~~~`;
        let i = 0;
        const out = extractChartBlocks(text, 1, () => `s${i++}`);

        expect(out.chartCount).toBe(2);
        expect(out.html).toContain('id="chart-1-s0"');
        expect(out.html).toContain('id="chart-1-s1"');
    });

    it('escapes single quotes inside JSON to keep the data-chart attribute valid', () => {
        const text = `~~~chart\n{"title":"it's hot"}\n~~~`;
        const out = extractChartBlocks(text, 9, () => 'x');
        expect(out.html).toContain('&#39;');
        expect(out.html).not.toMatch(/data-chart='[^']*it's/);
    });

    it('returns empty result for nullish input', () => {
        expect(extractChartBlocks('', 1)).toEqual({ html: '', chartCount: 0 });
        expect(extractChartBlocks(null, 1)).toEqual({ html: '', chartCount: 0 });
    });
});

describe('extractActionBlocks', () => {
    it('renders copy button for action=copy', () => {
        const text = `~~~actions\n[{"action":"copy","label":"Copia config","data":"PORT=8080"}]\n~~~`;
        const out = extractActionBlocks(text);

        expect(out.actionCount).toBe(1);
        expect(out.html).toContain('data-action="copy"');
        expect(out.html).toContain('data-content="PORT=8080"');
        expect(out.html).toContain('>Copia config<');
    });

    it('renders download link for action=download with filename', () => {
        const text = `~~~actions\n[{"action":"download","label":"Scarica","data":"hello","filename":"f.txt"}]\n~~~`;
        const out = extractActionBlocks(text);

        expect(out.html).toContain('download="f.txt"');
        expect(out.html).toContain('href="data:text/plain;charset=utf-8,hello"');
        expect(out.html).toContain('>Scarica<');
    });

    it('sums action counts across blocks', () => {
        const text = `~~~actions\n[{"action":"copy","label":"A"},{"action":"copy","label":"B"}]\n~~~`;
        const out = extractActionBlocks(text);
        expect(out.actionCount).toBe(2);
    });

    it('silently drops malformed JSON (does not throw)', () => {
        const text = `~~~actions\n{not valid\n~~~`;
        const out = extractActionBlocks(text);
        expect(out.html).toBe('');
        expect(out.actionCount).toBe(0);
    });

    it('drops unsupported action types but keeps the wrapper', () => {
        const text = `~~~actions\n[{"action":"nuke","label":"X"}]\n~~~`;
        const out = extractActionBlocks(text);
        expect(out.html).toContain('<div class="my-3');
        expect(out.html).not.toContain('nuke');
    });
});

describe('renderAction', () => {
    it('escapes HTML in label', () => {
        const html = renderAction({ action: 'copy', label: '<b>Evil</b>', data: 'x' });
        expect(html).toContain('&lt;b&gt;Evil&lt;/b&gt;');
        expect(html).not.toContain('<b>Evil</b>');
    });

    it('URL-encodes download data', () => {
        const html = renderAction({ action: 'download', label: 'd', data: 'a b&c' });
        expect(html).toContain('a%20b%26c');
    });

    it('returns empty for nullish or unknown', () => {
        expect(renderAction(null)).toBe('');
        expect(renderAction({})).toBe('');
    });
});

describe('addCodeCopyButtons', () => {
    it('adds a Copia button after every <pre><code> block', () => {
        const html = '<pre><code class="language-php">&lt;?php echo 1;</code></pre>';
        const out = addCodeCopyButtons(html);
        expect(out).toContain('<button class="code-copy-btn"');
        expect(out).toContain('data-code=');
        expect(out).toContain('<?php echo 1;');
    });

    it('handles plain <pre><code> with no attributes', () => {
        const out = addCodeCopyButtons('<pre><code>echo x;</code></pre>');
        expect(out).toContain('>Copia<');
    });

    it('returns empty for falsy input', () => {
        expect(addCodeCopyButtons('')).toBe('');
        expect(addCodeCopyButtons(null)).toBe('');
    });
});

describe('renderRichContent (end-to-end pure)', () => {
    it('pipes through chart → actions → markdown → code-copy', () => {
        const text = [
            '# Title',
            '',
            '~~~chart',
            '{"type":"bar"}',
            '~~~',
            '',
            '~~~actions',
            '[{"action":"copy","label":"Go","data":"v"}]',
            '~~~',
            '',
            '```php',
            'echo 1;',
            '```',
        ].join('\n');

        // trivial markdown stub — wraps fenced code blocks
        const md = src => src
            .replace(/```php\n([\s\S]*?)```/g, '<pre><code class="language-php">$1</code></pre>')
            .replace(/^# (.+)$/m, '<h1>$1</h1>');

        const out = renderRichContent(text, 42, md, { randSuffix: () => 'z' });

        expect(out).toContain('<canvas id="chart-42-z"');
        expect(out).toContain('data-action="copy"');
        expect(out).toContain('<h1>Title</h1>');
        expect(out).toContain('code-copy-btn');
    });

    it('returns empty string for empty input', () => {
        expect(renderRichContent('', 1, s => s)).toBe('');
    });
});
