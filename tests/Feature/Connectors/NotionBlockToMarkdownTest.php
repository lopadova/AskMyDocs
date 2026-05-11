<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\Notion\NotionBlockToMarkdown;
use Tests\TestCase;

/**
 * v4.5/W2 — NotionBlockToMarkdown converter tests.
 *
 * The converter is unit-style (no DB, no Http) so each test feeds it
 * a minimal Notion block payload and asserts the rendered markdown.
 * Block shapes mirror Notion API v2022-06-28.
 */
final class NotionBlockToMarkdownTest extends TestCase
{
    private function richText(string $text, array $annotations = [], ?string $href = null): array
    {
        return [
            'plain_text' => $text,
            'annotations' => array_merge([
                'bold' => false,
                'italic' => false,
                'code' => false,
                'strikethrough' => false,
            ], $annotations),
            'href' => $href,
        ];
    }

    public function test_paragraph_block_renders_plain_text(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'paragraph',
            'paragraph' => [
                'rich_text' => [$this->richText('Hello world')],
            ],
        ]]);

        $this->assertSame('Hello world', $md);
    }

    public function test_heading_1_renders_with_hash(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'heading_1',
            'heading_1' => [
                'rich_text' => [$this->richText('Title')],
            ],
        ]]);

        $this->assertSame('# Title', $md);
    }

    public function test_heading_2_renders_with_two_hashes(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'heading_2',
            'heading_2' => ['rich_text' => [$this->richText('Section')]],
        ]]);

        $this->assertSame('## Section', $md);
    }

    public function test_heading_3_renders_with_three_hashes(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'heading_3',
            'heading_3' => ['rich_text' => [$this->richText('Subsection')]],
        ]]);

        $this->assertSame('### Subsection', $md);
    }

    public function test_bulleted_list_item_renders_as_dash(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'bulleted_list_item',
            'bulleted_list_item' => ['rich_text' => [$this->richText('one')]],
        ]]);

        $this->assertSame('- one', $md);
    }

    public function test_numbered_list_item_renders_as_one_dot(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'numbered_list_item',
            'numbered_list_item' => ['rich_text' => [$this->richText('first')]],
        ]]);

        // Markdown renderers re-number client-side; `1.` repeated
        // produces correctly numbered output. Convention follows
        // GitHub-flavoured markdown.
        $this->assertSame('1. first', $md);
    }

    public function test_to_do_unchecked_renders_with_empty_brackets(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'to_do',
            'to_do' => [
                'checked' => false,
                'rich_text' => [$this->richText('buy milk')],
            ],
        ]]);

        $this->assertSame('- [ ] buy milk', $md);
    }

    public function test_to_do_checked_renders_with_x(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'to_do',
            'to_do' => [
                'checked' => true,
                'rich_text' => [$this->richText('shipped')],
            ],
        ]]);

        $this->assertSame('- [x] shipped', $md);
    }

    public function test_quote_renders_with_greater_than(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'quote',
            'quote' => ['rich_text' => [$this->richText('be water')]],
        ]]);

        $this->assertSame('> be water', $md);
    }

    public function test_code_block_renders_with_fence_and_language(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'code',
            'code' => [
                'language' => 'php',
                'rich_text' => [$this->richText('echo "hi";')],
            ],
        ]]);

        $this->assertSame("```php\necho \"hi\";\n```", $md);
    }

    public function test_divider_renders_as_three_dashes(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'divider',
            'divider' => new \stdClass,
        ]]);

        $this->assertSame('---', $md);
    }

    public function test_table_renders_pipe_format_with_header_separator(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'table',
            'table' => ['has_column_header' => true, 'table_width' => 2],
            'children' => [
                [
                    'type' => 'table_row',
                    'table_row' => [
                        'cells' => [
                            [$this->richText('Header A')],
                            [$this->richText('Header B')],
                        ],
                    ],
                ],
                [
                    'type' => 'table_row',
                    'table_row' => [
                        'cells' => [
                            [$this->richText('Cell 1')],
                            [$this->richText('Cell 2')],
                        ],
                    ],
                ],
            ],
        ]]);

        $this->assertStringContainsString('| Header A | Header B |', $md);
        $this->assertStringContainsString('| --- | --- |', $md);
        $this->assertStringContainsString('| Cell 1 | Cell 2 |', $md);
    }

    public function test_link_to_page_renders_as_markdown_link(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'link_to_page',
            'link_to_page' => ['page_id' => 'abc-def-123'],
        ]]);

        $this->assertStringContainsString('[Notion page](https://www.notion.so/', $md);
        $this->assertStringContainsString('abcdef123', $md);
    }

    public function test_bold_italic_code_annotations_apply_in_order(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'paragraph',
            'paragraph' => [
                'rich_text' => [
                    $this->richText('plain '),
                    $this->richText('bold', ['bold' => true]),
                    $this->richText(' '),
                    $this->richText('code', ['code' => true]),
                ],
            ],
        ]]);

        // Order: code wraps first, then bold/italic wrap on top.
        $this->assertSame('plain **bold** `code`', $md);
    }

    public function test_unknown_block_type_emits_html_comment_marker(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'synced_block_super_weird',
        ]]);

        $this->assertSame('<!-- notion: synced_block_super_weird -->', $md);
    }

    public function test_inline_link_renders_as_markdown_anchor(): void
    {
        $md = (new NotionBlockToMarkdown)->render([[
            'type' => 'paragraph',
            'paragraph' => [
                'rich_text' => [
                    $this->richText('See ', []),
                    $this->richText('docs', [], 'https://example.test/x'),
                    $this->richText(' for details', []),
                ],
            ],
        ]]);

        $this->assertSame('See [docs](https://example.test/x) for details', $md);
    }
}
