import { visit } from 'unist-util-visit';
import type { Root, Text, Parent, RootContent } from 'mdast';
import type { Plugin } from 'unified';

/**
 * Replaces `#tag` markers inside text nodes with a custom `tag` mdast
 * node (hName = `tag`) the renderer picks up as a styled chip. Only
 * matches tags at word boundaries so URLs / headings stay untouched.
 */
export interface TagNode {
    type: 'tag';
    label: string;
    data: {
        hName: string;
        hProperties: Record<string, string>;
    };
}

const TAG_PATTERN = /(^|\s)#([a-z0-9][a-z0-9_-]{0,48})\b/gi;

export const remarkObsidianTag: Plugin<[], Root> = () => {
    return (tree: Root) => {
        visit(tree, 'text', (node: Text, index: number | undefined, parent: Parent | undefined) => {
            if (!parent || typeof index !== 'number') {
                return;
            }
            if (!node.value.includes('#')) {
                return;
            }
            const segments = splitByTag(node.value);
            if (segments.length === 1 && segments[0].type === 'text') {
                return;
            }
            const replacements: RootContent[] = segments.map((seg) =>
                seg.type === 'text'
                    ? ({ type: 'text', value: seg.value } as Text)
                    : (buildTagNode(seg.label) as unknown as RootContent),
            );
            parent.children.splice(index, 1, ...replacements);
        });
    };
};

type Segment =
    | { type: 'text'; value: string }
    | { type: 'tag'; label: string };

function splitByTag(value: string): Segment[] {
    const out: Segment[] = [];
    let last = 0;
    const matches = Array.from(value.matchAll(TAG_PATTERN));
    for (const hit of matches) {
        const [full, leading = '', label = ''] = hit;
        const idx = hit.index ?? 0;
        const tagStart = idx + leading.length;
        if (tagStart > last) {
            out.push({ type: 'text', value: value.slice(last, tagStart) });
        }
        out.push({ type: 'tag', label });
        last = idx + full.length;
    }
    if (last < value.length) {
        out.push({ type: 'text', value: value.slice(last) });
    }
    return out.length > 0 ? out : [{ type: 'text', value }];
}

function buildTagNode(label: string): TagNode {
    return {
        type: 'tag',
        label,
        data: {
            hName: 'tag',
            hProperties: { 'data-label': label },
        },
    };
}
