import { visit } from 'unist-util-visit';
import type { Root, Text, Parent } from 'mdast';
import type { Plugin } from 'unified';

/**
 * Parses `[[slug]]` / `[[slug|label]]` wikilinks out of markdown text
 * nodes and replaces them with a custom mdast node type the renderer
 * picks up via react-markdown's `components` map.
 *
 * Implementation note: we stay on plain Text nodes (no link node)
 * because react-markdown v10 freezes unknown link behaviour and we
 * want precise control over hover cards.
 */
export interface WikilinkNode {
    type: 'wikilink';
    slug: string;
    label: string;
    data: {
        hName: string;
        hProperties: Record<string, string>;
    };
}

const WIKILINK_PATTERN = /\[\[([^\]|\n]+?)(?:\|([^\]\n]+?))?\]\]/g;

export const remarkWikilink: Plugin<[], Root> = () => {
    return (tree: Root) => {
        visit(tree, 'text', (node: Text, index: number | undefined, parent: Parent | undefined) => {
            if (!parent || typeof index !== 'number') {
                return;
            }
            if (!node.value.includes('[[')) {
                return;
            }

            const segments = splitByWikilink(node.value);
            if (segments.length === 1 && segments[0].type === 'text') {
                return;
            }

            parent.children.splice(
                index,
                1,
                ...segments.map((seg) =>
                    seg.type === 'text'
                        ? ({ type: 'text', value: seg.value } as Text)
                        : buildWikilinkNode(seg.slug, seg.label),
                ),
            );
        });
    };
};

type Segment =
    | { type: 'text'; value: string }
    | { type: 'wikilink'; slug: string; label: string };

function splitByWikilink(value: string): Segment[] {
    const out: Segment[] = [];
    let last = 0;
    for (const hit of value.matchAll(WIKILINK_PATTERN)) {
        const idx = hit.index ?? 0;
        if (idx > last) {
            out.push({ type: 'text', value: value.slice(last, idx) });
        }
        const slug = hit[1].trim();
        const label = (hit[2] ?? hit[1]).trim();
        out.push({ type: 'wikilink', slug, label });
        last = idx + hit[0].length;
    }
    if (last < value.length) {
        out.push({ type: 'text', value: value.slice(last) });
    }
    return out;
}

function buildWikilinkNode(slug: string, label: string): WikilinkNode {
    return {
        type: 'wikilink',
        slug,
        label,
        data: {
            hName: 'wikilink',
            hProperties: { 'data-slug': slug, 'data-label': label },
        },
    };
}
