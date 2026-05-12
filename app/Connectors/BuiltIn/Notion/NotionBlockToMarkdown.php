<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Notion;

/**
 * v4.5/W2 — Convert a Notion block tree into markdown.
 *
 * Notion's block API returns a recursive tree where each node has a
 * `type` field and a per-type payload (`paragraph.rich_text`,
 * `heading_1.rich_text`, ...). Block trees are flattened to markdown
 * by depth-first walk; numbered lists use `1.` consistently because
 * markdown renderers re-number client-side.
 *
 * Block types currently supported:
 *   - paragraph
 *   - heading_1 / heading_2 / heading_3
 *   - bulleted_list_item / numbered_list_item
 *   - to_do (rendered as GitHub-flavoured task list `- [ ]` / `- [x]`)
 *   - quote
 *   - code (with language hint)
 *   - divider
 *   - table (block + table_row children)
 *   - link_to_page (rendered as `[Notion page]({url})`)
 *   - bookmark / embed / link_preview (URL fallback)
 *   - callout (rendered as quote)
 *
 * Unsupported / unknown types are rendered as an HTML-comment marker
 * `<!-- notion: <type> -->` so the operator can spot gaps in coverage
 * without losing the document. Per CLAUDE.md §6 a missing renderer is
 * surfaced rather than swallowed silently.
 *
 * Tested in isolation in tests/Feature/Connectors/NotionBlockToMarkdownTest.php.
 */
final class NotionBlockToMarkdown
{
    /**
     * Render a list of Notion blocks into markdown.
     *
     * @param  list<array<string,mixed>>  $blocks
     */
    public function render(array $blocks, int $depth = 0): string
    {
        $lines = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $line = $this->renderBlock($block, $depth);
            if ($line === null) {
                continue;
            }

            $lines[] = $line;
        }

        return implode("\n\n", array_filter($lines, static fn ($l) => $l !== ''));
    }

    /**
     * @param  array<string,mixed>  $block
     */
    private function renderBlock(array $block, int $depth): ?string
    {
        $type = (string) ($block['type'] ?? '');
        $indent = str_repeat('  ', max(0, $depth));

        $rendered = match ($type) {
            'paragraph' => $this->renderRichText($block['paragraph']['rich_text'] ?? []),
            'heading_1' => '# '.$this->renderRichText($block['heading_1']['rich_text'] ?? []),
            'heading_2' => '## '.$this->renderRichText($block['heading_2']['rich_text'] ?? []),
            'heading_3' => '### '.$this->renderRichText($block['heading_3']['rich_text'] ?? []),
            'bulleted_list_item' => $indent.'- '.$this->renderRichText(
                $block['bulleted_list_item']['rich_text'] ?? []
            ),
            'numbered_list_item' => $indent.'1. '.$this->renderRichText(
                $block['numbered_list_item']['rich_text'] ?? []
            ),
            'to_do' => $this->renderToDo($block, $indent),
            'quote' => '> '.$this->renderRichText($block['quote']['rich_text'] ?? []),
            'callout' => '> '.$this->renderRichText($block['callout']['rich_text'] ?? []),
            'code' => $this->renderCode($block),
            'divider' => '---',
            'table' => $this->renderTable($block),
            'link_to_page' => $this->renderLinkToPage($block),
            'bookmark', 'embed', 'link_preview' => $this->renderUrl($block, $type),
            default => '<!-- notion: '.$type.' -->',
        };

        // Recurse into children (Notion's `has_children` + `children`
        // shape — only present when the caller has hydrated it via
        // `/v1/blocks/{id}/children`).
        $children = $block['children'] ?? null;
        if (is_array($children) && $children !== []) {
            $childMd = $this->render($children, $depth + 1);
            if ($childMd !== '') {
                $rendered = $rendered === ''
                    ? $childMd
                    : $rendered."\n\n".$childMd;
            }
        }

        return $rendered;
    }

    /**
     * Notion rich-text is a list of segments, each carrying plain_text
     * + annotations + an optional link. We honour bold / italic /
     * code / strikethrough annotations and inline links.
     *
     * @param  list<array<string,mixed>>  $segments
     */
    private function renderRichText(array $segments): string
    {
        $out = '';
        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $text = (string) ($segment['plain_text'] ?? '');
            if ($text === '') {
                continue;
            }

            $ann = $segment['annotations'] ?? [];
            if (is_array($ann)) {
                if (($ann['code'] ?? false) === true) {
                    $text = '`'.$text.'`';
                }
                if (($ann['bold'] ?? false) === true) {
                    $text = '**'.$text.'**';
                }
                if (($ann['italic'] ?? false) === true) {
                    $text = '*'.$text.'*';
                }
                if (($ann['strikethrough'] ?? false) === true) {
                    $text = '~~'.$text.'~~';
                }
            }

            $href = $segment['href'] ?? null;
            if (is_string($href) && $href !== '') {
                $text = '['.$text.']('.$href.')';
            }

            $out .= $text;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $block
     */
    private function renderToDo(array $block, string $indent): string
    {
        $checked = (bool) ($block['to_do']['checked'] ?? false);
        $marker = $checked ? '[x]' : '[ ]';
        $body = $this->renderRichText($block['to_do']['rich_text'] ?? []);

        return $indent.'- '.$marker.' '.$body;
    }

    /**
     * @param  array<string,mixed>  $block
     */
    private function renderCode(array $block): string
    {
        $language = (string) ($block['code']['language'] ?? '');
        $body = $this->renderRichText($block['code']['rich_text'] ?? []);

        return "```{$language}\n{$body}\n```";
    }

    /**
     * Notion table blocks declare metadata (`table.has_column_header`,
     * width) and the actual rows live in the children array as
     * `table_row` blocks. The caller MUST have hydrated children
     * before passing the block in — partial hydration produces an
     * empty table.
     *
     * @param  array<string,mixed>  $block
     */
    private function renderTable(array $block): string
    {
        $children = $block['children'] ?? [];
        if (! is_array($children) || $children === []) {
            return '<!-- notion: table (no rows) -->';
        }

        $rows = [];
        foreach ($children as $row) {
            if (! is_array($row) || ($row['type'] ?? '') !== 'table_row') {
                continue;
            }

            $cells = $row['table_row']['cells'] ?? [];
            if (! is_array($cells)) {
                continue;
            }

            $renderedCells = [];
            foreach ($cells as $cell) {
                $renderedCells[] = is_array($cell) ? $this->renderRichText($cell) : '';
            }

            $rows[] = '| '.implode(' | ', $renderedCells).' |';
        }

        if ($rows === []) {
            return '<!-- notion: table (no rows) -->';
        }

        // Insert a markdown header separator after the first row.
        $cellCount = substr_count($rows[0], '|') - 1;
        $separator = '|'.str_repeat(' --- |', max(1, $cellCount));
        array_splice($rows, 1, 0, [$separator]);

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $block
     */
    private function renderLinkToPage(array $block): string
    {
        $payload = $block['link_to_page'] ?? [];
        if (! is_array($payload)) {
            return '<!-- notion: link_to_page (unparseable) -->';
        }

        $pageId = (string) ($payload['page_id'] ?? $payload['database_id'] ?? '');
        if ($pageId === '') {
            return '<!-- notion: link_to_page (no target) -->';
        }

        return '[Notion page](https://www.notion.so/'.str_replace('-', '', $pageId).')';
    }

    /**
     * @param  array<string,mixed>  $block
     */
    private function renderUrl(array $block, string $type): string
    {
        $payload = $block[$type] ?? [];
        if (! is_array($payload)) {
            return '<!-- notion: '.$type.' (unparseable) -->';
        }

        $url = (string) ($payload['url'] ?? '');
        if ($url === '') {
            return '<!-- notion: '.$type.' (no url) -->';
        }

        $caption = isset($payload['caption']) && is_array($payload['caption'])
            ? $this->renderRichText($payload['caption'])
            : '';

        return '['.($caption !== '' ? $caption : $url).']('.$url.')';
    }
}
