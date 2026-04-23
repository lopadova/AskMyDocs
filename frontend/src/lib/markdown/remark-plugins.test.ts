import { describe, it, expect } from 'vitest';
import { unified } from 'unified';
import remarkParse from 'remark-parse';
import type { Root } from 'mdast';
import { remarkWikilink } from './remark-wikilink';
import { remarkObsidianTag } from './remark-obsidian-tag';
import { remarkCallout } from './remark-callout';

/**
 * We don't assert on serialised output (remark-stringify doesn't know
 * about the custom node types); instead we feed markdown through a
 * plugin, walk the mdast, and assert the expected nodes are present.
 */
async function processWith(plugin: typeof remarkWikilink, md: string): Promise<Root> {
    const tree = unified().use(remarkParse).use(plugin).parse(md) as Root;
    await unified().use(plugin).run(tree);
    return tree;
}

function collectTypes(tree: Root): string[] {
    const out: string[] = [];
    const walk = (node: unknown): void => {
        if (!node || typeof node !== 'object') {
            return;
        }
        const n = node as { type?: string; children?: unknown[] };
        if (n.type) {
            out.push(n.type);
        }
        if (Array.isArray(n.children)) {
            for (const c of n.children) {
                walk(c);
            }
        }
    };
    walk(tree);
    return out;
}

describe('remarkWikilink', () => {
    it('produces a wikilink node for [[slug]]', async () => {
        const tree = await processWith(remarkWikilink, 'See [[remote-work-policy]] for details.');
        expect(collectTypes(tree)).toContain('wikilink');
    });

    it('parses label form [[slug|label]]', async () => {
        const tree = await processWith(remarkWikilink, 'See [[remote-work-policy|the policy]] please.');
        const types = collectTypes(tree);
        expect(types).toContain('wikilink');
    });

    it('leaves plain text untouched', async () => {
        const tree = await processWith(remarkWikilink, 'Plain paragraph with no brackets.');
        expect(collectTypes(tree)).not.toContain('wikilink');
    });
});

describe('remarkObsidianTag', () => {
    it('extracts a tag from a line', async () => {
        const tree = await processWith(remarkObsidianTag, 'This is #urgent content.');
        expect(collectTypes(tree)).toContain('tag');
    });

    it('ignores hashes inside URLs', async () => {
        // URL hashes are typically in link nodes, not text nodes — here we
        // just ensure the `#` pattern requires a leading whitespace / start.
        const tree = await processWith(remarkObsidianTag, 'word#nottag continues.');
        expect(collectTypes(tree)).not.toContain('tag');
    });
});

describe('remarkCallout', () => {
    it('transforms a blockquote `[!note]` into a callout node', async () => {
        const md = '> [!note] Heads up\n> body text line';
        const tree = await processWith(remarkCallout, md);
        expect(collectTypes(tree)).toContain('callout');
    });

    it('keeps regular blockquotes untouched', async () => {
        const tree = await processWith(remarkCallout, '> just a blockquote');
        expect(collectTypes(tree)).not.toContain('callout');
    });
});

// Compile-time sanity so the parsed types stay export-compatible.
const _unusedType: Root = { type: 'root', children: [] };
void _unusedType;
