import type { ReactNode } from 'react';

/*
 * A deliberately tiny tokenizer for the embed snippets — HTML plus a
 * little JS, not arbitrary source — so we get readable colour without a
 * highlighting dependency. Four classes: comments, strings, opening tag
 * names, and JS object keys. Standalone `>` is left uncoloured on
 * purpose so `=>` in the proxy example never gets a stray tag colour
 * (and we avoid regex look-behind for older-engine safety).
 */
const TOKEN_RE =
    /(<!--[\s\S]*?-->|\/\/[^\n]*)|('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")|(<\/?[a-zA-Z][\w-]*)|([A-Za-z_$][\w$]*(?=\s*:))/g;

const COMMENT_CLASS = 'text-muted-foreground italic';
const STRING_CLASS = 'text-[var(--ok)]';
const TAG_CLASS = 'text-[var(--accent-b)]';
const KEY_CLASS = 'text-[var(--accent-a)]';

/**
 * Split `code` into coloured token spans for read-only display.
 *
 * Invariant (verified by test): concatenating the text of every returned
 * node reproduces the input byte-for-byte. Highlighting never mutates the
 * snippet, so copy-to-clipboard keeps using the raw string.
 */
export function highlightSnippet(code: string): ReactNode[] {
    const nodes: ReactNode[] = [];
    let last = 0;
    let i = 0;

    TOKEN_RE.lastIndex = 0;
    let match: RegExpExecArray | null;
    while ((match = TOKEN_RE.exec(code)) !== null) {
        const [full, comment, str, tag] = match;
        if (full === '') {
            TOKEN_RE.lastIndex++;
            continue;
        }
        if (match.index > last) {
            nodes.push(code.slice(last, match.index));
        }

        const cls = comment
            ? COMMENT_CLASS
            : str
              ? STRING_CLASS
              : tag
                ? TAG_CLASS
                : KEY_CLASS;

        nodes.push(
            <span key={i} className={cls}>
                {full}
            </span>,
        );
        last = match.index + full.length;
        i++;
    }
    if (last < code.length) {
        nodes.push(code.slice(last));
    }

    return nodes;
}
