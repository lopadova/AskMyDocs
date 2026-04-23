import { visit } from 'unist-util-visit';
import type { Root, Blockquote, Paragraph, Text } from 'mdast';
import type { Plugin } from 'unified';

/**
 * Obsidian-style callouts: `> [!note] optional title` followed by more
 * `>` lines. Transforms the leading blockquote into a `callout` node
 * with `kind` ∈ {note, warning, tip, info, important, caution}. The
 * first line's post-marker remainder becomes the title; subsequent
 * lines become the body paragraph.
 *
 * Unknown kinds degrade to `note` so the renderer always has a palette
 * entry to look up.
 */
export interface CalloutNode {
    type: 'callout';
    kind: string;
    title: string;
    data: {
        hName: string;
        hProperties: Record<string, string>;
    };
    children: Paragraph[];
}

const CALLOUT_HEADER = /^\[!(\w+)\](?:\s+(.*))?$/;

const KNOWN_KINDS = new Set(['note', 'warning', 'tip', 'info', 'important', 'caution']);

export const remarkCallout: Plugin<[], Root> = () => {
    return (tree: Root) => {
        visit(tree, 'blockquote', (node: Blockquote, index: number | undefined, parent) => {
            if (!parent || typeof index !== 'number') {
                return;
            }
            const firstChild = node.children[0];
            if (!firstChild || firstChild.type !== 'paragraph') {
                return;
            }
            const firstTextNode = firstChild.children[0];
            if (!firstTextNode || firstTextNode.type !== 'text') {
                return;
            }

            const firstLine = firstTextNode.value.split('\n', 1)[0] ?? '';
            const header = firstLine.match(CALLOUT_HEADER);
            if (!header) {
                return;
            }

            const kindRaw = header[1].toLowerCase();
            const kind = KNOWN_KINDS.has(kindRaw) ? kindRaw : 'note';
            const title = (header[2] ?? '').trim();

            // Strip the header marker from the first text node, keep the rest.
            const remainder = firstTextNode.value.slice(firstLine.length).replace(/^\n/, '');
            const remainderChildren: Text[] = remainder.length > 0 ? [{ type: 'text', value: remainder }] : [];
            const remainingInline = firstChild.children.slice(1);

            const firstParagraph: Paragraph = {
                type: 'paragraph',
                children: [...remainderChildren, ...remainingInline],
            };

            const children: Paragraph[] = [firstParagraph, ...node.children.slice(1).filter((c): c is Paragraph => c.type === 'paragraph')];

            const calloutNode: CalloutNode = {
                type: 'callout',
                kind,
                title,
                data: {
                    hName: 'callout',
                    hProperties: { 'data-kind': kind, 'data-title': title },
                },
                children,
            };

            parent.children.splice(index, 1, calloutNode as never);
        });
    };
};
