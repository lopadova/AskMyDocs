import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';

import { highlightSnippet } from './highlightSnippet';

function renderedText(code: string): string {
    const { container } = render(<>{highlightSnippet(code)}</>);
    return container.textContent ?? '';
}

describe('highlightSnippet', () => {
    it('reproduces the HTML snippet byte-for-byte (no char loss)', () => {
        const code = [
            '<!-- AskMyDocs KITT widget — Production -->',
            '<script>',
            '  window.AskMyDocsWidget = {',
            "    key: 'pk_live_abc',",
            "    apiBase: 'https://kb.example.com',",
            '  };',
            '</script>',
            '<script src="https://kb.example.com/widget/askmydocs-widget.js" defer></script>',
        ].join('\n');

        expect(renderedText(code)).toBe(code);
    });

    it('reproduces the JS proxy snippet (incl. arrow fn) byte-for-byte', () => {
        const code = [
            '// Server-side proxy (Node/Express) — keeps pk_/sk_ off the browser.',
            "app.post('/api/widget-proxy/*', async (req, res) => {",
            '  const upstream = await fetch(url, {',
            "    headers: { Authorization: 'Bearer sk_secret' },",
            '  });',
            '});',
        ].join('\n');

        expect(renderedText(code)).toBe(code);
    });

    it('classes comments, strings and object keys distinctly', () => {
        const { container } = render(<>{highlightSnippet("key: 'v' // note")}</>);

        expect(container.querySelector('.italic')?.textContent).toBe('// note');
        const classes = Array.from(container.querySelectorAll('span')).map((s) => s.className);
        // a key span (accent-a) and a string span (ok) both exist
        expect(classes.some((c) => c.includes('accent-a'))).toBe(true);
        expect(classes.some((c) => c.includes('--ok'))).toBe(true);
    });

    it('does not colour the > of an arrow function as a tag', () => {
        const { container } = render(<>{highlightSnippet('(a, b) => b')}</>);
        // only "=>" arrow, no tag-coloured spans
        const tagSpans = Array.from(container.querySelectorAll('span')).filter((s) =>
            s.className.includes('accent-b'),
        );
        expect(tagSpans).toHaveLength(0);
    });
});
