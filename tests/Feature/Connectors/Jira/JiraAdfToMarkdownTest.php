<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors\Jira;

use App\Connectors\BuiltIn\Jira\JiraAdfToMarkdown;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v4.5/W6 — JiraAdfToMarkdown behaviour: per-node-type coverage,
 * unknown-node placeholder (R14), nested-block rendering, malformed
 * input tolerance.
 */
final class JiraAdfToMarkdownTest extends TestCase
{
    private function convert(array $adf): string
    {
        return (new JiraAdfToMarkdown())->convert($adf);
    }

    #[Test]
    public function empty_input_returns_empty_string(): void
    {
        $converter = new JiraAdfToMarkdown();
        $this->assertSame('', $converter->convert(null));
        $this->assertSame('', $converter->convert([]));
        $this->assertSame('', $converter->convert(['type' => 'paragraph']));
    }

    #[Test]
    public function renders_plain_paragraph(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Hello world.']],
            ]],
        ]);

        $this->assertSame('Hello world.', $md);
    }

    #[Test]
    public function renders_text_marks_strong_em_code_strike(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'strong']]],
                    ['type' => 'text', 'text' => ' '],
                    ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'em']]],
                    ['type' => 'text', 'text' => ' '],
                    ['type' => 'text', 'text' => 'mono', 'marks' => [['type' => 'code']]],
                    ['type' => 'text', 'text' => ' '],
                    ['type' => 'text', 'text' => 'gone', 'marks' => [['type' => 'strike']]],
                ],
            ]],
        ]);

        $this->assertStringContainsString('**bold**', $md);
        $this->assertStringContainsString('*italic*', $md);
        $this->assertStringContainsString('`mono`', $md);
        $this->assertStringContainsString('~~gone~~', $md);
    }

    #[Test]
    public function renders_link_mark(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'visit '],
                    [
                        'type' => 'text',
                        'text' => 'our site',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test']]],
                    ],
                ],
            ]],
        ]);

        $this->assertStringContainsString('[our site](https://example.test)', $md);
    }

    #[Test]
    public function renders_heading_levels_1_to_6(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $level) {
            $md = $this->convert([
                'type' => 'doc',
                'content' => [[
                    'type' => 'heading',
                    'attrs' => ['level' => $level],
                    'content' => [['type' => 'text', 'text' => "H{$level}"]],
                ]],
            ]);

            $this->assertSame(str_repeat('#', $level)." H{$level}", $md, "Heading level {$level} mismatch");
        }
    }

    #[Test]
    public function clamps_heading_level_out_of_range(): void
    {
        $high = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 99],
                'content' => [['type' => 'text', 'text' => 'x']],
            ]],
        ]);
        $this->assertStringStartsWith('######', $high);

        $low = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 0],
                'content' => [['type' => 'text', 'text' => 'x']],
            ]],
        ]);
        $this->assertStringStartsWith('# ', $low);
    }

    #[Test]
    public function renders_bullet_list(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => [
                    [
                        'type' => 'listItem',
                        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]],
                    ],
                    [
                        'type' => 'listItem',
                        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]]],
                    ],
                ],
            ]],
        ]);

        $this->assertStringContainsString('- one', $md);
        $this->assertStringContainsString('- two', $md);
    }

    #[Test]
    public function renders_ordered_list_with_numbers(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'orderedList',
                'content' => [
                    [
                        'type' => 'listItem',
                        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first']]]],
                    ],
                    [
                        'type' => 'listItem',
                        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'second']]]],
                    ],
                ],
            ]],
        ]);

        $this->assertStringContainsString('1. first', $md);
        $this->assertStringContainsString('2. second', $md);
    }

    #[Test]
    public function renders_nested_bullet_list_with_indent(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => [[
                    'type' => 'listItem',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'outer']]],
                        [
                            'type' => 'bulletList',
                            'content' => [[
                                'type' => 'listItem',
                                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'inner']]]],
                            ]],
                        ],
                    ],
                ]],
            ]],
        ]);

        $this->assertStringContainsString('- outer', $md);
        $this->assertStringContainsString('  - inner', $md);
    }

    #[Test]
    public function renders_code_block_with_language(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'codeBlock',
                'attrs' => ['language' => 'php'],
                'content' => [['type' => 'text', 'text' => "echo 'hi';"]],
            ]],
        ]);

        $this->assertStringContainsString("```php", $md);
        $this->assertStringContainsString("echo 'hi';", $md);
        $this->assertStringContainsString("```", $md);
    }

    #[Test]
    public function renders_blockquote(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'blockquote',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A quoted line.']]]],
            ]],
        ]);

        $this->assertStringContainsString('> A quoted line.', $md);
    }

    #[Test]
    public function renders_rule(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [['type' => 'rule']],
        ]);

        $this->assertSame('---', $md);
    }

    #[Test]
    public function renders_panel_with_typed_label(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'panel',
                'attrs' => ['panelType' => 'warning'],
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'careful']]]],
            ]],
        ]);

        $this->assertStringContainsString('> **WARNING**', $md);
        $this->assertStringContainsString('> careful', $md);
    }

    #[Test]
    public function renders_mention(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'cc '],
                    ['type' => 'mention', 'attrs' => ['text' => 'Lorenzo', 'id' => 'acc-1']],
                ],
            ]],
        ]);

        $this->assertStringContainsString('@Lorenzo', $md);
    }

    #[Test]
    public function mention_strips_leading_at(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'mention', 'attrs' => ['text' => '@Lorenzo']],
                ],
            ]],
        ]);

        $this->assertStringContainsString('@Lorenzo', $md);
        $this->assertStringNotContainsString('@@Lorenzo', $md);
    }

    #[Test]
    public function renders_inline_card_as_bare_url(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'inlineCard', 'attrs' => ['url' => 'https://example.test/issue/1']],
                ],
            ]],
        ]);

        $this->assertStringContainsString('https://example.test/issue/1', $md);
    }

    #[Test]
    public function renders_external_media(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'mediaSingle',
                'content' => [[
                    'type' => 'media',
                    'attrs' => [
                        'url' => 'https://example.test/image.png',
                        'alt' => 'an image',
                    ],
                ]],
            ]],
        ]);

        $this->assertStringContainsString('![an image](https://example.test/image.png)', $md);
    }

    #[Test]
    public function renders_internal_media_as_placeholder(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'media',
                'attrs' => ['id' => 'attachment-xyz', 'type' => 'file'],
            ]],
        ]);

        $this->assertStringContainsString('[adf-media: attachment-xyz]', $md);
    }

    #[Test]
    public function renders_table_with_header_row(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'table',
                'content' => [
                    [
                        'type' => 'tableRow',
                        'content' => [
                            ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Name']]]]],
                            ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Role']]]]],
                        ],
                    ],
                    [
                        'type' => 'tableRow',
                        'content' => [
                            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lorenzo']]]]],
                            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'PM']]]]],
                        ],
                    ],
                ],
            ]],
        ]);

        $this->assertStringContainsString('| Name | Role |', $md);
        $this->assertStringContainsString('| --- | --- |', $md);
        $this->assertStringContainsString('| Lorenzo | PM |', $md);
    }

    #[Test]
    public function table_cells_collapse_newlines_and_escape_pipe(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'table',
                'content' => [[
                    'type' => 'tableRow',
                    'content' => [[
                        'type' => 'tableCell',
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "line one\nline | two"]]],
                        ],
                    ]],
                ]],
            ]],
        ]);

        // Newline collapsed into a single space; pipe escaped so it
        // doesn't break the cell delimiter.
        $this->assertStringContainsString('| line one line \\| two |', $md);
    }

    #[Test]
    public function unknown_node_renders_placeholder_marker_R14(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'extension', // Unknown to the converter.
                'attrs' => ['extensionKey' => 'jiraissues'],
            ]],
        ]);

        // R14 — unknown nodes MUST render an audit-trail placeholder,
        // not silent empty.
        $this->assertStringContainsString('[adf-node: extension]', $md);
    }

    #[Test]
    public function unknown_node_inside_paragraph_does_not_break_render(): void
    {
        // Inline-position unknown subtypes degrade to empty; block-
        // position unknown subtypes emit the placeholder. Both paths
        // must keep the surrounding markdown valid.
        $md = $this->convert([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'before']]],
                ['type' => 'foo'],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'after']]],
            ],
        ]);

        $this->assertStringContainsString('before', $md);
        $this->assertStringContainsString('[adf-node: foo]', $md);
        $this->assertStringContainsString('after', $md);
    }

    #[Test]
    public function malformed_node_with_missing_content_does_not_throw(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph'], // No content array.
                ['type' => 'heading', 'attrs' => ['level' => 2]], // No content.
            ],
        ]);

        // Headings still render the prefix; paragraphs render empty.
        $this->assertStringContainsString('##', $md);
    }
}
