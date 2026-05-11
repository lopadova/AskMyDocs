<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Confluence;

/**
 * v4.5/W5 — Convert Confluence storage-format XHTML to markdown.
 *
 * Confluence storage format is a flavour of XHTML 1.0 augmented with
 * `<ac:*>` (Atlassian Confluence) and `<ri:*>` (resource identifier)
 * macro elements. See
 * https://confluence.atlassian.com/doc/confluence-storage-format-790796544.html
 *
 * Block types currently supported:
 *   - <h1>..<h6>                    (heading_1..6)
 *   - <p>, <div>                    (paragraph)
 *   - <ul>/<ol>/<li>                (bulleted / numbered list — nesting OK)
 *   - <blockquote>                  (markdown quote)
 *   - <pre>, <code>                 (fenced code block)
 *   - <hr>, <br>                    (divider / newline)
 *   - <a href="...">                (inline link)
 *   - <b>/<strong>, <i>/<em>,
 *     <s>/<strike>/<del>            (bold / italic / strike)
 *   - <table>/<tr>/<td>/<th>        (markdown pipe table)
 *
 * Confluence-specific tags handled:
 *   - <ac:structured-macro ac:name="X">  → `[macro: X]` placeholder for
 *     unknown macros; known macros (code, info, warning, note) handled
 *     specifically below.
 *   - <ac:task-list>/<ac:task>      → GitHub-flavoured task list
 *     (`- [x]` / `- [ ]`) — Confluence task lists are first-class.
 *   - <ac:link>                     → inline link to another page;
 *     emitted as a markdown link with the page-title (or fallback).
 *   - <ac:image>/<ri:attachment>    → `<!-- confluence: attachment X
 *     skipped -->` placeholder; AskMyDocs does not yet ingest binary
 *     attachments from Confluence.
 *
 * Unknown macros and `<ri:*>` references that aren't wrapped in a
 * known macro emit the visible-but-explicit placeholder
 * `[macro: <name>]` so the operator notices the gap rather than
 * silently dropping content.
 *
 * Tested in isolation in
 * tests/Feature/Connectors/Confluence/ConfluenceStorageToMarkdownTest.php.
 */
final class ConfluenceStorageToMarkdown
{
    /**
     * Convert one storage-format document (or fragment) to markdown.
     *
     * Returns an empty string when the input is empty / unparseable —
     * the caller (ConfluenceConnector) treats empty markdown as "skip
     * this page" rather than writing a 0-byte ingest file.
     */
    public function convert(string $storage): string
    {
        $storage = trim($storage);
        if ($storage === '') {
            return '';
        }

        // Confluence storage format declares the Atlassian namespaces
        // — DOMDocument's HTML loader handles namespace-prefixed tags
        // (e.g. `<ac:link>`) but warns about them. Suppress the
        // warnings; the tag walker below handles `<ac:*>` + `<ri:*>`
        // explicitly.
        $wrapped = '<?xml encoding="UTF-8"?><root xmlns:ac="http://atlassian.com/content" xmlns:ri="http://atlassian.com/resource/identifier">'
            .$storage
            .'</root>';

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            $wrapped,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return '';
        }

        // The `<root>` wrapper might have been mangled by the HTML
        // parser into a generic element under <body>; walk the body's
        // children to be safe.
        $root = $dom->getElementsByTagName('body')->item(0)
            ?? $dom->documentElement;

        if ($root === null) {
            return '';
        }

        $markdown = $this->renderChildren($root, 0);

        // Collapse 3+ consecutive newlines.
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
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
            $text = (string) $node->nodeValue;
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;

            return $text;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        /** @var \DOMElement $node */
        $tag = strtolower($node->nodeName);

        // `<ac:*>` and `<ri:*>` tags arrive lower-cased AND namespace-
        // prefixed (e.g. `ac:link`, `ri:page`). DOMDocument's HTML
        // loader normalises the case but preserves the `<ns>:` prefix.
        return match ($tag) {
            'body', 'html', 'div', 'root' => $this->renderChildren($node, $listDepth),
            'p' => $this->wrapBlock($this->renderInline($node)),
            'h1' => $this->wrapBlock('# '.trim($this->renderInline($node))),
            'h2' => $this->wrapBlock('## '.trim($this->renderInline($node))),
            'h3' => $this->wrapBlock('### '.trim($this->renderInline($node))),
            'h4' => $this->wrapBlock('#### '.trim($this->renderInline($node))),
            'h5' => $this->wrapBlock('##### '.trim($this->renderInline($node))),
            'h6' => $this->wrapBlock('###### '.trim($this->renderInline($node))),
            'ul' => $this->renderList($node, $listDepth, ordered: false),
            'ol' => $this->renderList($node, $listDepth, ordered: true),
            'li' => $this->renderInline($node),
            'blockquote' => $this->renderBlockquote($node, $listDepth),
            'pre' => $this->renderPre($node),
            'code' => '`'.$this->renderInline($node).'`',
            'hr' => "\n\n---\n\n",
            'br' => "  \n",
            'a' => $this->renderLink($node),
            'b', 'strong' => '**'.$this->renderInline($node).'**',
            'i', 'em' => '*'.$this->renderInline($node).'*',
            's', 'strike', 'del' => '~~'.$this->renderInline($node).'~~',
            'u' => $this->renderInline($node),
            'span', 'font' => $this->renderInline($node),
            'table' => $this->renderTable($node),
            'ac:structured-macro' => $this->renderStructuredMacro($node, $listDepth),
            'ac:task-list' => $this->renderTaskList($node),
            'ac:task' => $this->renderInline($node), // handled by parent
            'ac:link' => $this->renderAcLink($node),
            'ac:image' => $this->renderAcImage($node),
            'ac:placeholder' => '', // editor-only placeholder; drop
            'ac:emoticon' => $this->renderEmoticon($node),
            'ac:plain-text-body', 'ac:rich-text-body' => $this->renderChildren($node, $listDepth),
            'ac:parameter' => '', // already consumed by parent macro handler
            default => $this->renderUnknown($node, $tag, $listDepth),
        };
    }

    private function renderUnknown(\DOMNode $node, string $tag, int $listDepth): string
    {
        // Anything matching `<ns>:<name>` we haven't explicitly handled
        // is an unknown Atlassian macro / resource — emit a visible
        // placeholder so the gap is auditable.
        if (str_contains($tag, ':')) {
            return '[macro: '.$tag.']';
        }

        // Unknown plain HTML tag — render children so we don't drop
        // user content.
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

        if ($separatorRow === null) {
            $firstCellCount = substr_count($rows[0], '|') - 1;
            $separatorRow = '|'.str_repeat(' --- |', max(1, $firstCellCount));
        }
        array_splice($rows, 1, 0, [$separatorRow]);

        return "\n\n".implode("\n", $rows)."\n\n";
    }

    /**
     * `<ac:structured-macro ac:name="X">` — Confluence's first-class
     * macro envelope. Several macros are well-known + worth rendering
     * (code blocks, info/warning panels); everything else falls back
     * to a `[macro: X]` placeholder.
     */
    private function renderStructuredMacro(\DOMElement $node, int $listDepth): string
    {
        $name = strtolower((string) $node->getAttribute('ac:name'));

        switch ($name) {
            case 'code':
                return $this->renderCodeMacro($node);
            case 'info':
            case 'note':
            case 'tip':
            case 'warning':
                return $this->renderPanelMacro($node, $name);
            case 'expand':
                return $this->renderExpandMacro($node);
            default:
                // Unknown macro — emit a visible placeholder.
                $bodyText = trim($this->renderChildren($node, $listDepth));
                if ($bodyText !== '') {
                    return "\n\n[macro: {$name}]\n\n".$bodyText."\n\n";
                }

                return "\n\n[macro: {$name}]\n\n";
        }
    }

    private function renderCodeMacro(\DOMElement $node): string
    {
        // <ac:structured-macro ac:name="code">
        //   <ac:parameter ac:name="language">php</ac:parameter>
        //   <ac:plain-text-body><![CDATA[<?php echo "x";]]></ac:plain-text-body>
        // </ac:structured-macro>
        $language = '';
        $body = '';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'ac:parameter') {
                $paramName = strtolower((string) $child->getAttribute('ac:name'));
                if ($paramName === 'language') {
                    $language = trim($child->textContent);
                }
                continue;
            }
            if ($tag === 'ac:plain-text-body' || $tag === 'ac:rich-text-body') {
                $body .= $child->textContent;
            }
        }

        return "\n\n```{$language}\n".rtrim($body)."\n```\n\n";
    }

    private function renderPanelMacro(\DOMElement $node, string $variant): string
    {
        $body = '';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'ac:rich-text-body' || $tag === 'ac:plain-text-body') {
                $body .= $this->renderChildren($child, 0);
            }
        }
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $label = strtoupper($variant);
        $quoted = preg_replace('/^/m', '> ', $body);

        return "\n\n> **{$label}**\n>\n".$quoted."\n\n";
    }

    private function renderExpandMacro(\DOMElement $node): string
    {
        $title = 'Expand';
        $body = '';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'ac:parameter' && strtolower((string) $child->getAttribute('ac:name')) === 'title') {
                $title = trim($child->textContent);
                continue;
            }
            if ($tag === 'ac:rich-text-body' || $tag === 'ac:plain-text-body') {
                $body .= $this->renderChildren($child, 0);
            }
        }
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        // GitHub-flavoured collapsible details block.
        return "\n\n<details><summary>{$title}</summary>\n\n".$body."\n\n</details>\n\n";
    }

    private function renderTaskList(\DOMElement $node): string
    {
        $lines = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            if (strtolower($child->nodeName) !== 'ac:task') {
                continue;
            }
            $status = 'incomplete';
            $body = '';
            foreach ($child->childNodes as $sub) {
                if (! $sub instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($sub->nodeName);
                if ($tag === 'ac:task-status') {
                    $status = trim($sub->textContent);
                    continue;
                }
                if ($tag === 'ac:task-body') {
                    $body = trim($this->renderInline($sub));
                }
            }
            $marker = strtolower($status) === 'complete' ? '[x]' : '[ ]';
            $lines[] = '- '.$marker.' '.$body;
        }
        if ($lines === []) {
            return '';
        }

        return "\n\n".implode("\n", $lines)."\n\n";
    }

    private function renderAcLink(\DOMElement $node): string
    {
        // <ac:link><ri:page ri:content-title="Page Title" /><ac:plain-text-link-body><![CDATA[label]]></ac:plain-text-link-body></ac:link>
        $title = '';
        $label = '';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'ri:page') {
                $title = (string) $child->getAttribute('ri:content-title');
                continue;
            }
            if ($tag === 'ac:plain-text-link-body' || $tag === 'ac:link-body') {
                $label = trim($child->textContent);
            }
        }

        $text = $label !== '' ? $label : ($title !== '' ? $title : 'link');

        // Confluence doesn't surface the destination URL inline — emit
        // a wikilink-style reference; downstream graph extraction can
        // surface the cross-page edge if/when we model it.
        if ($title !== '') {
            return '[['.$title.'|'.$text.']]';
        }

        return $text;
    }

    private function renderAcImage(\DOMElement $node): string
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'ri:attachment') {
                $name = (string) $child->getAttribute('ri:filename');

                return '<!-- confluence: attachment '.($name !== '' ? $name : 'unknown').' skipped -->';
            }
            if ($tag === 'ri:url') {
                $url = (string) $child->getAttribute('ri:value');
                if ($url !== '') {
                    return '![]('.$url.')';
                }
            }
        }

        return '<!-- confluence: image skipped -->';
    }

    private function renderEmoticon(\DOMElement $node): string
    {
        $name = (string) $node->getAttribute('ac:name');

        return $name !== '' ? ':'.$name.':' : '';
    }
}
