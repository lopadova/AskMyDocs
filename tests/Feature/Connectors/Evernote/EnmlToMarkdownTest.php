<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors\Evernote;

use App\Connectors\BuiltIn\Evernote\EnmlToMarkdown;
use Tests\TestCase;

/**
 * v4.5/W4 — EnmlToMarkdown converter tests.
 *
 * Unit-style (no DB, no Http). Each test feeds a minimal ENML payload
 * and asserts the rendered markdown. ENML shapes mirror Evernote's
 * publicly documented DTD (https://dev.evernote.com/doc/articles/enml.php).
 */
final class EnmlToMarkdownTest extends TestCase
{
    private function wrap(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd">'."\n"
            ."<en-note>{$body}</en-note>";
    }

    public function test_paragraph_renders_plain_text(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<p>Hello world</p>'));
        $this->assertSame('Hello world', $md);
    }

    public function test_heading_1_renders_with_hash(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<h1>Title</h1>'));
        $this->assertSame('# Title', $md);
    }

    public function test_heading_3_renders_with_three_hashes(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<h3>Subtitle</h3>'));
        $this->assertSame('### Subtitle', $md);
    }

    public function test_bullet_list_renders_with_dash_markers(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<ul><li>One</li><li>Two</li></ul>'));
        $this->assertStringContainsString('- One', $md);
        $this->assertStringContainsString('- Two', $md);
    }

    public function test_ordered_list_renders_with_numeric_markers(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<ol><li>One</li><li>Two</li></ol>'));
        $this->assertStringContainsString('1. One', $md);
        $this->assertStringContainsString('2. Two', $md);
    }

    public function test_en_todo_renders_as_task_list_checkbox(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap(
            '<div><en-todo checked="true"/>Done thing</div>'
            .'<div><en-todo checked="false"/>Pending thing</div>'
        ));

        $this->assertStringContainsString('[x]', $md);
        $this->assertStringContainsString('[ ]', $md);
        $this->assertStringContainsString('Done thing', $md);
        $this->assertStringContainsString('Pending thing', $md);
    }

    public function test_blockquote_renders_with_gt_prefix(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<blockquote>Quoted line</blockquote>'));
        $this->assertStringContainsString('> Quoted line', $md);
    }

    public function test_inline_link_renders_with_markdown_syntax(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap(
            '<p>Visit <a href="https://example.com">my site</a> today</p>'
        ));
        $this->assertStringContainsString('[my site](https://example.com)', $md);
    }

    public function test_bold_italic_strikethrough_inline_code(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap(
            '<p><strong>bold</strong> <em>italic</em> <s>strike</s> <code>x</code></p>'
        ));
        $this->assertStringContainsString('**bold**', $md);
        $this->assertStringContainsString('*italic*', $md);
        $this->assertStringContainsString('~~strike~~', $md);
        $this->assertStringContainsString('`x`', $md);
    }

    public function test_pre_code_renders_as_fenced_code_block(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap(
            '<pre><code class="language-php">echo &quot;hi&quot;;</code></pre>'
        ));

        $this->assertStringContainsString('```php', $md);
        $this->assertStringContainsString('echo', $md);
        $this->assertStringContainsString('```', $md);
    }

    public function test_horizontal_rule(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<p>before</p><hr/><p>after</p>'));
        $this->assertStringContainsString('---', $md);
    }

    public function test_table_renders_as_pipe_table(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap(
            '<table>'
            .'<tr><th>H1</th><th>H2</th></tr>'
            .'<tr><td>a</td><td>b</td></tr>'
            .'<tr><td>c</td><td>d</td></tr>'
            .'</table>'
        ));

        $this->assertStringContainsString('| H1 | H2 |', $md);
        $this->assertStringContainsString('| a | b |', $md);
        $this->assertStringContainsString('| c | d |', $md);
        // separator
        $this->assertMatchesRegularExpression('/\|\s*---\s*\|\s*---\s*\|/', $md);
    }

    public function test_en_media_renders_as_skipped_comment(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap(
            '<p>before</p>'
            .'<en-media hash="abc123" type="image/png"/>'
            .'<p>after</p>'
        ));

        $this->assertStringContainsString('evernote: attachment abc123 skipped', $md);
        $this->assertStringContainsString('type=image/png', $md);
        $this->assertStringContainsString('before', $md);
        $this->assertStringContainsString('after', $md);
    }

    public function test_en_crypt_renders_as_skipped_comment(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<en-crypt>obfuscated</en-crypt>'));
        $this->assertStringContainsString('evernote: encrypted block skipped', $md);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', (new EnmlToMarkdown)->convert(''));
        $this->assertSame('', (new EnmlToMarkdown)->convert('   '));
    }

    public function test_strips_xml_prolog_and_doctype(): void
    {
        // Loading should NOT explode on the standard ENML prolog +
        // remote DTD reference.
        $md = (new EnmlToMarkdown)->convert($this->wrap('<p>OK</p>'));
        $this->assertSame('OK', $md);
    }

    public function test_collapses_excessive_blank_lines(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap(
            '<p>a</p><p></p><p></p><p></p><p>b</p>'
        ));

        // Two consecutive blank lines OK, three+ collapsed.
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $md);
        $this->assertStringContainsString('a', $md);
        $this->assertStringContainsString('b', $md);
    }

    public function test_handles_unknown_tag_by_rendering_children(): void
    {
        $md = (new EnmlToMarkdown)->convert($this->wrap('<custom-tag>visible body</custom-tag>'));
        $this->assertStringContainsString('visible body', $md);
    }
}
