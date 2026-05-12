<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Evernote;

/**
 * v4.5/W4 — Convert an Evernote ENML (Evernote Markup Language) document
 * to markdown.
 *
 * ENML is a strict subset of XHTML 1.0 plus a handful of Evernote-specific
 * tags (`<en-note>`, `<en-todo>`, `<en-media>`, `<en-crypt>`). See
 * https://dev.evernote.com/doc/articles/enml.php for the published DTD.
 *
 * Block types currently supported:
 *   - <en-note>                     (root — content unwrapped)
 *   - <h1>, <h2>, <h3>             (heading_1/2/3 markdown)
 *   - <p>, <div>                   (paragraph)
 *   - <ul>/<ol>/<li>               (bulleted / numbered list — nesting OK)
 *   - <en-todo checked="true|false"> (rendered as GitHub-flavoured task
 *                                     list `- [x]` / `- [ ]`)
 *   - <blockquote>                 (markdown quote)
 *   - <pre>, <code>                (fenced code block)
 *   - <hr>, <br>                    (divider / newline)
 *   - <a href="...">               (inline link)
 *   - <b>/<strong>, <i>/<em>,
 *     <s>/<strike>/<del>, <code>   (bold / italic / strike / inline code)
 *   - <table>/<tr>/<td>/<th>        (markdown pipe table)
 *
 * Unsupported / stripped:
 *   - <en-media>                   (attachment placeholder — emitted as
 *                                   `<!-- evernote: attachment <hash>
 *                                   skipped (type=<mime>) -->` so the
 *                                   operator notices the gap; AskMyDocs
 *                                   does not yet ingest binary blobs from
 *                                   Evernote notes).
 *   - <en-crypt>                   (encrypted block — emitted as
 *                                   `<!-- evernote: encrypted block skipped -->`).
 *   - <font>, <style>, inline      (whitespace-only formatting — body
 *     style attributes               text is kept, formatting dropped).
 *
 * Tested in isolation in tests/Feature/Connectors/Evernote/EnmlToMarkdownTest.php.
 */
final class EnmlToMarkdown
{
    /**
     * Convert one ENML document (or a fragment) to markdown.
     *
     * Returns an empty string when the input is empty / unparseable —
     * the caller (EvernoteConnector / EnexImporter) treats empty
     * markdown as "skip this note" and reports it in the per-note error
     * list rather than silently writing a 0-byte ingest file.
     */
    public function convert(string $enml): string
    {
        $enml = trim($enml);
        if ($enml === '') {
            return '';
        }

        // ENML allows an XML prolog + DOCTYPE; both confuse the
        // forgiving HTML loader (esp. DOCTYPE that points at a remote
        // DTD). Strip them — DOMDocument::loadHTML re-injects its own
        // wrapper anyway.
        $enml = $this->stripXmlProlog($enml);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NOERROR + LIBXML_NOWARNING — ENML often contains
        // unknown HTML5 elements which libxml's HTML4 parser warns
        // about. We don't care; the tag walker below handles unknowns
        // explicitly.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"?>'.$enml,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return '';
        }

        // Find <en-note> if it exists; otherwise walk the body.
        $root = $dom->getElementsByTagName('en-note')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0)
            ?? $dom->documentElement;

        if ($root === null) {
            return '';
        }

        $markdown = $this->renderChildren($root, 0);

        // Collapse 3+ consecutive newlines (the recursive walker
        // sometimes emits blank lines around list/table boundaries).
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function stripXmlProlog(string $enml): string
    {
        // Strip the XML prolog (literal <\?xml ... \?\>).
        // The pattern is anchored at the start of input; whitespace OK.
        $enml = preg_replace('/^\s*<\?xml[^?]*\?>/i', '', $enml) ?? $enml;
        // Strip the DOCTYPE declaration (single line OR multi-line).
        $enml = preg_replace('/<!DOCTYPE[^>]*>/is', '', $enml) ?? $enml;

        return $enml;
    }

    private function renderChildren(\DOMNode $node, int $listDepth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->renderNode($child, $listDepth);
        }

        return $out;
    }

    private function renderNode(\DOMNode $node, int $listDepth): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            // Preserve text but collapse intra-element whitespace.
            $text = (string) $node->nodeValue;
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;

            return $text;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        /** @var \DOMElement $node */
        $tag = strtolower($node->nodeName);

        return match ($tag) {
            'en-note', 'body', 'html', 'div' => $this->renderBlockLikeContainer($node, $listDepth),
            'p' => $this->wrapBlock($this->renderInline($node)),
            'h1' => $this->wrapBlock('# '.trim($this->renderInline($node))),
            'h2' => $this->wrapBlock('## '.trim($this->renderInline($node))),
            'h3' => $this->wrapBlock('### '.trim($this->renderInline($node))),
            'h4' => $this->wrapBlock('#### '.trim($this->renderInline($node))),
            'h5' => $this->wrapBlock('##### '.trim($this->renderInline($node))),
            'h6' => $this->wrapBlock('###### '.trim($this->renderInline($node))),
            'ul' => $this->renderList($node, $listDepth, ordered: false),
            'ol' => $this->renderList($node, $listDepth, ordered: true),
            'li' => $this->renderInline($node), // handled by parent
            'blockquote' => $this->renderBlockquote($node, $listDepth),
            'pre' => $this->renderPre($node),
            'code' => '`'.$this->renderInline($node).'`',
            'hr' => "\n\n---\n\n",
            'br' => "  \n", // markdown hard-break
            'a' => $this->renderLink($node),
            'b', 'strong' => '**'.$this->renderInline($node).'**',
            'i', 'em' => '*'.$this->renderInline($node).'*',
            's', 'strike', 'del' => '~~'.$this->renderInline($node).'~~',
            'u' => $this->renderInline($node), // markdown has no underline; emit plain
            'span', 'font' => $this->renderInline($node),
            'table' => $this->renderTable($node),
            'en-todo' => $this->renderEnTodo($node),
            'en-media' => $this->renderEnMedia($node),
            'en-crypt' => '<!-- evernote: encrypted block skipped -->',
            default => $this->renderInline($node), // unknown — render children only
        };
    }

    private function renderBlockLikeContainer(\DOMNode $node, int $listDepth): string
    {
        return $this->renderChildren($node, $listDepth);
    }

    private function wrapBlock(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        return "\n\n".$trimmed."\n\n";
    }

    private function renderInline(\DOMNode $node): string
    {
        return $this->renderChildren($node, 0);
    }

    private function renderList(\DOMElement $node, int $depth, bool $ordered): string
    {
        $indent = str_repeat('  ', max(0, $depth));
        $lines = [];
        $index = 1;
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            if (strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $marker = $ordered ? ($index.'. ') : '- ';
            // Render children of <li>, but pull nested ul/ol out as
            // separate indented child blocks.
            $body = '';
            $nestedBlocks = [];
            foreach ($child->childNodes as $sub) {
                if ($sub instanceof \DOMElement && in_array(strtolower($sub->nodeName), ['ul', 'ol'], true)) {
                    $nestedBlocks[] = $this->renderList($sub, $depth + 1, strtolower($sub->nodeName) === 'ol');
                    continue;
                }
                $body .= $this->renderNode($sub, $depth);
            }
            $body = trim(preg_replace("/\s+/", ' ', $body) ?? $body);

            $line = $indent.$marker.$body;
            $lines[] = $line;
            foreach ($nestedBlocks as $nested) {
                $lines[] = rtrim($nested);
            }
            $index++;
        }

        if ($lines === []) {
            return '';
        }

        return "\n\n".implode("\n", $lines)."\n\n";
    }

    private function renderBlockquote(\DOMElement $node, int $depth): string
    {
        $inner = trim($this->renderChildren($node, $depth));
        if ($inner === '') {
            return '';
        }
        $quoted = preg_replace('/^/m', '> ', $inner);

        return "\n\n".$quoted."\n\n";
    }

    private function renderPre(\DOMElement $node): string
    {
        // <pre><code class="language-php">...</code></pre> is the
        // canonical fenced-code shape. Extract language hint from the
        // inner <code class="language-*"> if present.
        $language = '';
        $body = '';
        $codeFound = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'code') {
                $codeFound = true;
                $class = (string) $child->getAttribute('class');
                if (preg_match('/language-([A-Za-z0-9+#-]+)/', $class, $m) === 1) {
                    $language = $m[1];
                }
                $body .= $child->textContent;
                continue;
            }
            $body .= $child->textContent;
        }
        if (! $codeFound) {
            $body = $node->textContent;
        }

        return "\n\n```{$language}\n".rtrim($body)."\n```\n\n";
    }

    private function renderLink(\DOMElement $node): string
    {
        $href = (string) $node->getAttribute('href');
        $text = trim($this->renderInline($node));
        if ($href === '') {
            return $text;
        }
        if ($text === '') {
            $text = $href;
        }

        return '['.$text.']('.$href.')';
    }

    private function renderTable(\DOMElement $node): string
    {
        $rows = [];
        $headerExtracted = false;
        $separatorRow = null;
        foreach ($node->getElementsByTagName('tr') as $tr) {
            $cells = [];
            $isHeader = false;
            foreach ($tr->childNodes as $cell) {
                if (! $cell instanceof \DOMElement) {
                    continue;
                }
                $name = strtolower($cell->nodeName);
                if ($name !== 'td' && $name !== 'th') {
                    continue;
                }
                if ($name === 'th') {
                    $isHeader = true;
                }
                $cells[] = trim(preg_replace("/\s+/", ' ', $this->renderInline($cell)) ?? '');
            }
            if ($cells === []) {
                continue;
            }
            $rows[] = '| '.implode(' | ', $cells).' |';
            if ($isHeader && ! $headerExtracted) {
                $headerExtracted = true;
                $separatorRow = '|'.str_repeat(' --- |', count($cells));
            }
        }

        if ($rows === []) {
            return '';
        }

        // Insert header separator if any <th> appeared; otherwise
        // synthesise one after the first row so the table still
        // renders as a markdown table.
        if ($separatorRow === null) {
            $firstCellCount = substr_count($rows[0], '|') - 1;
            $separatorRow = '|'.str_repeat(' --- |', max(1, $firstCellCount));
        }
        array_splice($rows, 1, 0, [$separatorRow]);

        return "\n\n".implode("\n", $rows)."\n\n";
    }

    private function renderEnTodo(\DOMElement $node): string
    {
        $checked = strtolower((string) $node->getAttribute('checked')) === 'true';

        // Evernote checkboxes are inline elements; the to-do "label" is
        // the text content that immediately follows them inside the
        // parent <p>/<div>. Render the checkbox + following text as a
        // task-list line. The PARENT <p> already wraps it in a block,
        // but to keep the markdown clean we emit the bullet inline.
        $marker = $checked ? '[x]' : '[ ]';

        return '- '.$marker.' ';
    }

    private function renderEnMedia(\DOMElement $node): string
    {
        $hash = (string) $node->getAttribute('hash');
        $type = (string) $node->getAttribute('type');
        $hashStr = $hash !== '' ? $hash : 'unknown';
        $typeStr = $type !== '' ? $type : 'unknown';

        return '<!-- evernote: attachment '.$hashStr.' skipped (type='.$typeStr.') -->';
    }
}
