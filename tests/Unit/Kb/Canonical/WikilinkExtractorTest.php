<?php

namespace Tests\Unit\Kb\Canonical;

use App\Services\Kb\Canonical\WikilinkExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WikilinkExtractor::class)]
class WikilinkExtractorTest extends TestCase
{
    private WikilinkExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new WikilinkExtractor();
    }

    public function test_extracts_simple_wikilinks_from_prose(): void
    {
        $md = 'See [[dec-cache-v2]] and [[module-checkout]] for details.';
        $this->assertSame(['dec-cache-v2', 'module-checkout'], $this->extractor->extract($md));
    }

    public function test_returns_empty_array_when_no_wikilinks(): void
    {
        $this->assertSame([], $this->extractor->extract('Just prose without brackets.'));
        $this->assertSame([], $this->extractor->extract(''));
    }

    public function test_deduplicates_repeated_wikilinks_preserving_order(): void
    {
        $md = '[[a]] and [[a]] and [[b]] and [[a]]';
        $this->assertSame(['a', 'b'], $this->extractor->extract($md));
    }

    public function test_ignores_wikilinks_inside_fenced_code_blocks(): void
    {
        $md = <<<'MD'
Prose [[keep-me]].

```
// in a code block
[[ignore-me]]
```

After code [[keep-me-too]].
MD;
        $this->assertSame(['keep-me', 'keep-me-too'], $this->extractor->extract($md));
    }

    public function test_ignores_wikilinks_inside_inline_code_spans(): void
    {
        $md = "Prose [[keep]] and `[[ignore-me]]` and more [[keep2]].";
        $this->assertSame(['keep', 'keep2'], $this->extractor->extract($md));
    }

    public function test_rejects_slugs_with_uppercase_letters(): void
    {
        $this->assertSame([], $this->extractor->extract('[[UPPER]]'));
        $this->assertSame(['valid-slug'], $this->extractor->extract('[[MixedCase]] [[valid-slug]]'));
    }

    public function test_rejects_slugs_with_whitespace(): void
    {
        $this->assertSame([], $this->extractor->extract('[[two words]]'));
    }

    public function test_rejects_slugs_starting_with_non_alphanumeric(): void
    {
        $this->assertSame([], $this->extractor->extract('[[-starts-with-hyphen]]'));
        $this->assertSame([], $this->extractor->extract('[[_underscore]]'));
    }

    public function test_accepts_slugs_with_digits_and_hyphens(): void
    {
        $out = $this->extractor->extract('[[dec-2026-0001]] [[module-v2]] [[123abc]]');
        $this->assertSame(['dec-2026-0001', 'module-v2', '123abc'], $out);
    }

    public function test_ignores_triple_and_quadruple_brackets(): void
    {
        // [[[x]]] is weird markup we don't want to treat as a wikilink;
        // the regex should match the inner [[x]] portion.
        $out = $this->extractor->extract('[[[x]]]');
        $this->assertSame(['x'], $out);
    }

    public function test_multiple_wikilinks_on_same_line(): void
    {
        $out = $this->extractor->extract('[[a]][[b]][[c]]');
        $this->assertSame(['a', 'b', 'c'], $out);
    }

    public function test_does_not_match_single_bracket_pairs(): void
    {
        $this->assertSame([], $this->extractor->extract('[single] [a]'));
    }
}
