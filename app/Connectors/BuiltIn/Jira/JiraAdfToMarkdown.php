<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Jira;

/**
 * v4.5/W6 — Atlassian Document Format (ADF) → markdown converter.
 *
 * Jira returns issue descriptions + comments as ADF: a node-tree JSON
 * shape with `{ type, content, attrs, marks, text }`. ADF is shared
 * across Atlassian properties (Confluence's new editor, Trello, Jira)
 * so future connectors can reuse this converter.
 *
 * Supported node types (covers ~95% of real-world issue content):
 *
 *   - doc, paragraph, text (with marks: strong, em, code, link, strike)
 *   - heading (level 1-6)
 *   - bulletList, orderedList, listItem
 *   - codeBlock
 *   - blockquote
 *   - rule
 *   - panel (info / note / warning / success / error)
 *   - mention (rendered as `@displayName`)
 *   - inlineCard (rendered as the bare URL)
 *   - hardBreak
 *   - mediaSingle / mediaGroup / media (rendered as `![alt](src)` when
 *     the attrs include an external URL, otherwise dropped — Jira's
 *     internal media references are not URL-resolvable without an
 *     extra signed-URL fetch)
 *   - table, tableRow, tableHeader, tableCell (rendered as a GitHub-
 *     Flavored Markdown table; nested-block content inside a cell
 *     flattens to a single text line so the table stays renderable)
 *
 * R14 (surface-failures-loudly): unknown node types do NOT silently
 * return empty content — they emit a `[adf-node: <type>]` placeholder
 * so the operator can grep the ingested markdown for unhandled cases.
 * Better a visible audit-trail than a silent data drop.
 *
 * The converter is stateless and deterministic; same ADF input yields
 * identical markdown output every call.
 */
final class JiraAdfToMarkdown
{
    /**
     * Convert an ADF document (top-level `{ type: "doc", content: [...] }`)
     * to markdown. Returns empty string when the input is null, an empty
     * array, or shaped non-doc — never throws on a malformed top-level
     * node; the caller can detect "no body" via the empty string.
     */
    public function convert(mixed $adf): string
    {
        if (! is_array($adf)) {
            return '';
        }

        // Some ADF payloads from Jira's `comment.body` are already the
        // top-level `doc` node; others wrap one extra level. Normalise.
        $type = $adf['type'] ?? null;
        if ($type === 'doc') {
            return $this->renderBlocks($adf['content'] ?? []);
        }

        // Tolerate "bare content array" shape — useful for embedded
        // sub-trees that callers might want to convert in isolation.
        if (isset($adf[0]) && is_array($adf[0])) {
            return $this->renderBlocks($adf);
        }

        return '';
    }

    /**
     * @param  mixed  $nodes
     */
    private function renderBlocks($nodes): string
    {
        if (! is_array($nodes)) {
            return '';
        }

        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $rendered = $this->renderBlock($node);
            if ($rendered !== '') {
                $out[] = $rendered;
            }
        }

        return implode("\n\n", $out);
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderBlock(array $node): string
    {
        $type = (string) ($node['type'] ?? '');

        return match ($type) {
            'paragraph'    => $this->renderInline($node['content'] ?? []),
            'heading'      => $this->renderHeading($node),
            'bulletList'   => $this->renderBulletList($node['content'] ?? [], 0),
            'orderedList'  => $this->renderOrderedList($node['content'] ?? [], 0),
            'codeBlock'    => $this->renderCodeBlock($node),
            'blockquote'   => $this->renderBlockquote($node['content'] ?? []),
            'rule'         => '---',
            'panel'        => $this->renderPanel($node),
            'mediaSingle', 'mediaGroup' => $this->renderMediaContainer($node['content'] ?? []),
            'media'        => $this->renderMedia($node),
            'table'        => $this->renderTable($node['content'] ?? []),
            'hardBreak'    => '',
            default        => sprintf('[adf-node: %s]', $type === '' ? 'unknown' : $type),
        };
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderHeading(array $node): string
    {
        $level = (int) ($node['attrs']['level'] ?? 1);
        $level = max(1, min(6, $level));
        $text = $this->renderInline($node['content'] ?? []);

        return str_repeat('#', $level).' '.$text;
    }

    /**
     * @param  mixed  $nodes
     */
    private function renderInline($nodes): string
    {
        if (! is_array($nodes)) {
            return '';
        }

        $out = '';
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $type = $n['type'] ?? '';
            $out .= match ($type) {
                'text'       => $this->renderText($n),
                'mention'    => $this->renderMention($n),
                'inlineCard' => $this->renderInlineCard($n),
                'hardBreak'  => "  \n",
                'emoji'      => $this->renderEmoji($n),
                default      => '',
            };
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderText(array $node): string
    {
        $text = (string) ($node['text'] ?? '');
        if ($text === '') {
            return '';
        }

        $marks = $node['marks'] ?? [];
        if (! is_array($marks) || $marks === []) {
            return $text;
        }

        // Apply marks in a stable order so the rendered output is
        // deterministic regardless of how the upstream emitted them.
        $hasCode = false;
        $hasStrong = false;
        $hasEm = false;
        $hasStrike = false;
        $linkHref = null;

        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            switch ($mark['type'] ?? '') {
                case 'code':   $hasCode = true; break;
                case 'strong': $hasStrong = true; break;
                case 'em':     $hasEm = true; break;
                case 'strike': $hasStrike = true; break;
                case 'link':
                    $href = $mark['attrs']['href'] ?? null;
                    if (is_string($href) && $href !== '') {
                        $linkHref = $href;
                    }
                    break;
            }
        }

        $rendered = $text;
        if ($hasCode) {
            $rendered = '`'.$rendered.'`';
        }
        if ($hasStrong) {
            $rendered = '**'.$rendered.'**';
        }
        if ($hasEm) {
            $rendered = '*'.$rendered.'*';
        }
        if ($hasStrike) {
            $rendered = '~~'.$rendered.'~~';
        }
        if ($linkHref !== null) {
            $rendered = '['.$rendered.']('.$linkHref.')';
        }

        return $rendered;
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderMention(array $node): string
    {
        $name = $node['attrs']['text'] ?? $node['attrs']['displayName'] ?? null;
        if (is_string($name) && $name !== '') {
            // Strip a leading `@` if Jira already included it so we
            // never render `@@displayName`.
            $name = ltrim($name, '@');

            return '@'.$name;
        }

        $id = $node['attrs']['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return '@'.$id;
        }

        return '@unknown';
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderInlineCard(array $node): string
    {
        $url = $node['attrs']['url'] ?? null;
        if (is_string($url) && $url !== '') {
            return $url;
        }

        return '[adf-node: inlineCard]';
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderEmoji(array $node): string
    {
        $shortName = $node['attrs']['shortName'] ?? null;
        if (is_string($shortName) && $shortName !== '') {
            return $shortName;
        }

        return '';
    }

    /**
     * @param  mixed  $nodes
     */
    private function renderBulletList($nodes, int $depth): string
    {
        if (! is_array($nodes)) {
            return '';
        }
        $lines = [];
        foreach ($nodes as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'listItem') {
                continue;
            }
            $lines[] = $this->renderListItem($item['content'] ?? [], '- ', $depth);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  mixed  $nodes
     */
    private function renderOrderedList($nodes, int $depth): string
    {
        if (! is_array($nodes)) {
            return '';
        }
        $lines = [];
        $i = 1;
        foreach ($nodes as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'listItem') {
                continue;
            }
            $lines[] = $this->renderListItem($item['content'] ?? [], $i.'. ', $depth);
            $i++;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  mixed  $children
     */
    private function renderListItem($children, string $bullet, int $depth): string
    {
        if (! is_array($children)) {
            return $bullet;
        }

        $indent = str_repeat('  ', $depth);
        $parts = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }
            $type = $child['type'] ?? '';
            if ($type === 'paragraph') {
                $parts[] = $indent.$bullet.$this->renderInline($child['content'] ?? []);
                continue;
            }
            if ($type === 'bulletList') {
                $parts[] = $this->renderBulletList($child['content'] ?? [], $depth + 1);
                continue;
            }
            if ($type === 'orderedList') {
                $parts[] = $this->renderOrderedList($child['content'] ?? [], $depth + 1);
                continue;
            }
            // Other block kinds inside a list item — flatten to text.
            $parts[] = $indent.$bullet.$this->renderBlock($child);
        }

        return implode("\n", array_filter($parts, static fn ($p) => $p !== ''));
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderCodeBlock(array $node): string
    {
        $lang = $node['attrs']['language'] ?? '';
        $lang = is_string($lang) ? $lang : '';

        $content = $node['content'] ?? [];
        $text = '';
        if (is_array($content)) {
            foreach ($content as $piece) {
                if (is_array($piece) && ($piece['type'] ?? '') === 'text') {
                    $text .= (string) ($piece['text'] ?? '');
                }
            }
        }

        return "```{$lang}\n".$text."\n```";
    }

    /**
     * @param  mixed  $nodes
     */
    private function renderBlockquote($nodes): string
    {
        $rendered = $this->renderBlocks($nodes);
        if ($rendered === '') {
            return '';
        }
        $lines = preg_split('/\r?\n/', $rendered) ?: [];

        return implode("\n", array_map(static fn (string $l): string => '> '.$l, $lines));
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderPanel(array $node): string
    {
        $type = (string) ($node['attrs']['panelType'] ?? 'info');
        $emoji = match ($type) {
            'info' => 'INFO',
            'note' => 'NOTE',
            'warning' => 'WARNING',
            'success' => 'SUCCESS',
            'error' => 'ERROR',
            default => strtoupper($type),
        };
        $body = $this->renderBlocks($node['content'] ?? []);
        if ($body === '') {
            return '> **'.$emoji.'**';
        }
        $lines = preg_split('/\r?\n/', $body) ?: [];

        $out = ['> **'.$emoji.'**'];
        foreach ($lines as $line) {
            $out[] = '> '.$line;
        }

        return implode("\n", $out);
    }

    /**
     * @param  mixed  $nodes
     */
    private function renderMediaContainer($nodes): string
    {
        if (! is_array($nodes)) {
            return '';
        }
        $parts = [];
        foreach ($nodes as $n) {
            if (is_array($n)) {
                $rendered = $this->renderBlock($n);
                if ($rendered !== '') {
                    $parts[] = $rendered;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function renderMedia(array $node): string
    {
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs)) {
            return '';
        }
        $url = $attrs['url'] ?? null;
        $alt = $attrs['alt'] ?? '';

        if (is_string($url) && $url !== '') {
            return sprintf('![%s](%s)', is_string($alt) ? $alt : '', $url);
        }

        // Internal Jira media without a resolvable URL — emit a stable
        // placeholder so the operator can audit ingested issues for
        // attachment references.
        $id = $attrs['id'] ?? '';
        if (is_string($id) && $id !== '') {
            return sprintf('[adf-media: %s]', $id);
        }

        return '[adf-node: media]';
    }

    /**
     * @param  mixed  $rows
     */
    private function renderTable($rows): string
    {
        if (! is_array($rows) || $rows === []) {
            return '';
        }

        $renderedRows = [];
        $headerSeen = false;
        $maxCols = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['type'] ?? '') !== 'tableRow') {
                continue;
            }
            $cells = [];
            $rowIsHeader = false;
            foreach ($row['content'] ?? [] as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $cellType = $cell['type'] ?? '';
                if ($cellType === 'tableHeader') {
                    $rowIsHeader = true;
                }
                $cellText = $this->flattenInline($this->renderBlocks($cell['content'] ?? []));
                $cells[] = $cellText;
            }
            $maxCols = max($maxCols, count($cells));
            $renderedRows[] = ['cells' => $cells, 'header' => $rowIsHeader];
            if ($rowIsHeader) {
                $headerSeen = true;
            }
        }

        if ($renderedRows === []) {
            return '';
        }

        $lines = [];
        $headerRendered = false;
        foreach ($renderedRows as $row) {
            $padded = $row['cells'];
            while (count($padded) < $maxCols) {
                $padded[] = '';
            }
            $lines[] = '| '.implode(' | ', $padded).' |';
            if ($row['header'] && ! $headerRendered) {
                $lines[] = '|'.str_repeat(' --- |', $maxCols);
                $headerRendered = true;
            }
        }

        // If no header row was found, inject a divider after row 1 so
        // GFM renders it as a table at all.
        if (! $headerSeen && count($lines) >= 1) {
            array_splice($lines, 1, 0, ['|'.str_repeat(' --- |', $maxCols)]);
        }

        return implode("\n", $lines);
    }

    /**
     * Cells must render on a single line — collapse newlines + extra
     * whitespace so the GFM table stays parseable.
     */
    private function flattenInline(string $text): string
    {
        $oneLine = preg_replace('/\r?\n+/', ' ', $text);
        if (! is_string($oneLine)) {
            return $text;
        }

        // Escape pipe chars so they don't break the cell delimiter.
        $oneLine = str_replace('|', '\\|', $oneLine);

        return trim((string) preg_replace('/\s{2,}/', ' ', $oneLine));
    }
}
