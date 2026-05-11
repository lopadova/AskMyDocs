<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors\Confluence;

use App\Connectors\BuiltIn\Confluence\ConfluenceStorageToMarkdown;
use Tests\TestCase;

/**
 * v4.5/W5 — ConfluenceStorageToMarkdown tests.
 *
 * Exercises the storage-format-XHTML → markdown converter against the
 * tag inventory documented at
 * https://confluence.atlassian.com/doc/confluence-storage-format-790796544.html
 */
final class ConfluenceStorageToMarkdownTest extends TestCase
{
    private function convert(string $storage): string
    {
        return (new ConfluenceStorageToMarkdown)->convert($storage);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->convert(''));
        $this->assertSame('', $this->convert("   \n  "));
    }

    public function test_renders_headings(): void
    {
        $md = $this->convert('<h1>One</h1><h2>Two</h2><h3>Three</h3>');
        $this->assertStringContainsString('# One', $md);
        $this->assertStringContainsString('## Two', $md);
        $this->assertStringContainsString('### Three', $md);
    }

    public function test_renders_paragraph_and_inline_formatting(): void
    {
        $md = $this->convert('<p>This is <strong>bold</strong> and <em>italic</em> and <del>strike</del>.</p>');
        $this->assertStringContainsString('**bold**', $md);
        $this->assertStringContainsString('*italic*', $md);
        $this->assertStringContainsString('~~strike~~', $md);
    }

    public function test_renders_link(): void
    {
        $md = $this->convert('<p>visit <a href="https://example.test">our site</a></p>');
        $this->assertStringContainsString('[our site](https://example.test)', $md);
    }

    public function test_renders_unordered_list(): void
    {
        $md = $this->convert('<ul><li>alpha</li><li>beta</li></ul>');
        $this->assertStringContainsString('- alpha', $md);
        $this->assertStringContainsString('- beta', $md);
    }

    public function test_renders_ordered_list(): void
    {
        $md = $this->convert('<ol><li>first</li><li>second</li></ol>');
        $this->assertStringContainsString('1. first', $md);
        $this->assertStringContainsString('2. second', $md);
    }

    public function test_renders_blockquote(): void
    {
        $md = $this->convert('<blockquote><p>quoted line</p></blockquote>');
        $this->assertStringContainsString('> quoted line', $md);
    }

    public function test_renders_code_block_with_language_from_pre_code(): void
    {
        $md = $this->convert('<pre><code class="language-php">echo 1;</code></pre>');
        $this->assertStringContainsString('```php', $md);
        $this->assertStringContainsString('echo 1;', $md);
        $this->assertStringContainsString('```', $md);
    }

    public function test_renders_table_with_header_row(): void
    {
        $md = $this->convert('<table><tr><th>name</th><th>age</th></tr><tr><td>alice</td><td>30</td></tr></table>');
        $this->assertStringContainsString('| name | age |', $md);
        $this->assertStringContainsString('| --- |', $md);
        $this->assertStringContainsString('| alice | 30 |', $md);
    }

    public function test_renders_horizontal_rule_and_line_break(): void
    {
        $md = $this->convert('<p>before</p><hr/><p>after<br/>continued</p>');
        $this->assertStringContainsString('---', $md);
        $this->assertStringContainsString('continued', $md);
    }

    public function test_renders_confluence_code_macro(): void
    {
        $storage = '<ac:structured-macro ac:name="code">'
            .'<ac:parameter ac:name="language">javascript</ac:parameter>'
            .'<ac:plain-text-body>console.log("hi");</ac:plain-text-body>'
            .'</ac:structured-macro>';

        $md = $this->convert($storage);
        $this->assertStringContainsString('```javascript', $md);
        $this->assertStringContainsString('console.log("hi");', $md);
    }

    public function test_renders_info_panel_macro(): void
    {
        $storage = '<ac:structured-macro ac:name="info">'
            .'<ac:rich-text-body><p>this is informational</p></ac:rich-text-body>'
            .'</ac:structured-macro>';

        $md = $this->convert($storage);
        $this->assertStringContainsString('**INFO**', $md);
        $this->assertStringContainsString('> this is informational', $md);
    }

    public function test_renders_warning_panel_macro(): void
    {
        $storage = '<ac:structured-macro ac:name="warning">'
            .'<ac:rich-text-body><p>be careful</p></ac:rich-text-body>'
            .'</ac:structured-macro>';

        $md = $this->convert($storage);
        $this->assertStringContainsString('**WARNING**', $md);
        $this->assertStringContainsString('> be careful', $md);
    }

    public function test_unknown_macro_emits_placeholder(): void
    {
        $storage = '<ac:structured-macro ac:name="weather"><ac:rich-text-body><p>rainy</p></ac:rich-text-body></ac:structured-macro>';
        $md = $this->convert($storage);
        $this->assertStringContainsString('[macro: weather]', $md);
        $this->assertStringContainsString('rainy', $md);
    }

    public function test_ac_task_list_renders_as_gfm_task_list(): void
    {
        $storage = '<ac:task-list>'
            .'<ac:task><ac:task-status>complete</ac:task-status><ac:task-body>ship feature</ac:task-body></ac:task>'
            .'<ac:task><ac:task-status>incomplete</ac:task-status><ac:task-body>write docs</ac:task-body></ac:task>'
            .'</ac:task-list>';

        $md = $this->convert($storage);
        $this->assertStringContainsString('- [x] ship feature', $md);
        $this->assertStringContainsString('- [ ] write docs', $md);
    }

    public function test_ac_link_to_page_emits_wikilink(): void
    {
        $storage = '<p>See <ac:link><ri:page ri:content-title="Architecture" /><ac:plain-text-link-body>arch</ac:plain-text-link-body></ac:link> for details.</p>';
        $md = $this->convert($storage);
        $this->assertStringContainsString('[[Architecture|arch]]', $md);
    }

    public function test_ac_image_attachment_emits_skip_placeholder(): void
    {
        $storage = '<p>cover: <ac:image><ri:attachment ri:filename="diagram.png" /></ac:image></p>';
        $md = $this->convert($storage);
        $this->assertStringContainsString('confluence: attachment diagram.png skipped', $md);
    }

    public function test_handles_malformed_input_gracefully(): void
    {
        // Truncated tag mid-document — the parser should not crash.
        $md = $this->convert('<p>good</p><h1>almost');
        $this->assertNotSame('', $md);
        $this->assertStringContainsString('good', $md);
    }

    public function test_collapses_excessive_blank_lines(): void
    {
        $storage = '<p>a</p>'."\n\n\n\n\n".'<p>b</p>';
        $md = $this->convert($storage);
        // No run of 3+ consecutive newlines in the final output.
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $md);
    }
}
