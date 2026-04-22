<?php

namespace Tests\Unit\Kb\Canonical;

use App\Services\Kb\Canonical\CanonicalParser;
use App\Support\Canonical\CanonicalStatus;
use App\Support\Canonical\CanonicalType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalParser::class)]
class CanonicalParserTest extends TestCase
{
    private CanonicalParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalParser();
    }

    // -------------------------------------------------------------
    // parse() — structural recognition
    // -------------------------------------------------------------

    public function test_returns_null_when_no_frontmatter_at_all(): void
    {
        $md = "# Just a heading\n\nSome prose, no frontmatter here.";
        $this->assertNull($this->parser->parse($md));
    }

    public function test_returns_null_when_opening_dashes_missing(): void
    {
        // Must start with --- on the very first line.
        $md = "\n---\ntype: decision\n---\nbody";
        $this->assertNull($this->parser->parse($md));
    }

    public function test_returns_null_when_closing_dashes_missing(): void
    {
        $md = "---\ntype: decision\nbody without closing fence";
        $this->assertNull($this->parser->parse($md));
    }

    public function test_parses_valid_frontmatter_and_body(): void
    {
        $md = <<<'MD'
---
id: DEC-2026-0001
slug: dec-cache-v2
type: decision
project: ecommerce-core
status: accepted
owners:
  - platform-team
tags:
  - cache
retrieval_priority: 90
related:
  - "[[module-cache-layer]]"
supersedes: []
summary: Official cache invalidation strategy.
---

# Decision: Cache invalidation v2

Body prose.
MD;
        $doc = $this->parser->parse($md);

        $this->assertNotNull($doc);
        $this->assertSame('dec-cache-v2', $doc->slug);
        $this->assertSame('DEC-2026-0001', $doc->docId);
        $this->assertSame(CanonicalType::Decision, $doc->type);
        $this->assertSame(CanonicalStatus::Accepted, $doc->status);
        $this->assertSame(90, $doc->retrievalPriority);
        $this->assertSame(['platform-team'], $doc->owners);
        $this->assertSame(['cache'], $doc->tags);
        $this->assertSame(['module-cache-layer'], $doc->relatedSlugs);
        $this->assertSame('Official cache invalidation strategy.', $doc->summary);
        $this->assertStringContainsString('# Decision: Cache invalidation v2', $doc->body);
        $this->assertStringNotContainsString('---', $doc->body);
    }

    public function test_parses_plain_wikilink_values_without_quotes(): void
    {
        // YAML quirk: [[foo]] unquoted becomes nested array. Parser must
        // extract the slug anyway. Users are free to quote or not.
        $md = <<<'MD'
---
id: X
slug: a
type: decision
status: draft
related:
  - "[[plain-one]]"
  - "[[plain-two]]"
---

body
MD;
        $doc = $this->parser->parse($md);
        $this->assertNotNull($doc);
        $this->assertSame(['plain-one', 'plain-two'], $doc->relatedSlugs);
    }

    public function test_extracts_raw_frontmatter_as_associative_array(): void
    {
        $md = <<<'MD'
---
slug: a
type: decision
status: draft
custom_field: arbitrary
---

body
MD;
        $doc = $this->parser->parse($md);
        $this->assertNotNull($doc);
        $this->assertSame('arbitrary', $doc->frontmatter['custom_field']);
    }

    public function test_malformed_yaml_returns_doc_with_parse_error(): void
    {
        // Unclosed double-quote — symfony/yaml must raise ParseException here.
        $md = <<<'MD'
---
slug: "unclosed value
type: decision
status: accepted
---

body
MD;
        $doc = $this->parser->parse($md);
        $this->assertNotNull($doc, 'should return DTO even with broken YAML so callers can log');
        $this->assertNotEmpty($doc->parseErrors);
    }

    public function test_body_is_trimmed_of_leading_blank_lines(): void
    {
        $md = "---\nslug: a\ntype: decision\nstatus: draft\n---\n\n\n\n# Heading\n\nprose.";
        $doc = $this->parser->parse($md);
        $this->assertNotNull($doc);
        $this->assertStringStartsWith('# Heading', $doc->body);
    }

    public function test_default_retrieval_priority_is_fifty_when_absent(): void
    {
        $md = "---\nslug: a\ntype: decision\nstatus: draft\n---\n\nbody";
        $doc = $this->parser->parse($md);
        $this->assertNotNull($doc);
        $this->assertSame(50, $doc->retrievalPriority);
    }

    // -------------------------------------------------------------
    // validate() — schema checks
    // -------------------------------------------------------------

    public function test_validate_accepts_complete_document(): void
    {
        $md = $this->minimalValidFrontmatter(['slug' => 'dec-x', 'type' => 'decision', 'status' => 'accepted']);
        $doc = $this->parser->parse($md);
        $result = $this->parser->validate($doc);
        $this->assertTrue($result->valid, 'errors: ' . json_encode($result->errors));
    }

    public function test_validate_rejects_missing_required_slug(): void
    {
        $md = $this->minimalValidFrontmatter(['type' => 'decision', 'status' => 'accepted'], includeSlug: false);
        $doc = $this->parser->parse($md);
        $result = $this->parser->validate($doc);
        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('slug', $result->errors);
    }

    public function test_validate_rejects_unknown_type_value(): void
    {
        $md = "---\nslug: a\ntype: not-a-real-type\nstatus: accepted\n---\n\nbody";
        $doc = $this->parser->parse($md);
        $result = $this->parser->validate($doc);
        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('type', $result->errors);
    }

    public function test_validate_rejects_unknown_status_value(): void
    {
        $md = "---\nslug: a\ntype: decision\nstatus: not-a-status\n---\n\nbody";
        $doc = $this->parser->parse($md);
        $result = $this->parser->validate($doc);
        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('status', $result->errors);
    }

    public function test_validate_rejects_malformed_slug(): void
    {
        $md = "---\nslug: Bad Slug With Spaces\ntype: decision\nstatus: draft\n---\n\nbody";
        $doc = $this->parser->parse($md);
        $result = $this->parser->validate($doc);
        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('slug', $result->errors);
    }

    public function test_validate_rejects_retrieval_priority_out_of_range(): void
    {
        $md = "---\nslug: a\ntype: decision\nstatus: draft\nretrieval_priority: 150\n---\n\nbody";
        $doc = $this->parser->parse($md);
        $result = $this->parser->validate($doc);
        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('retrieval_priority', $result->errors);
    }

    public function test_validate_fails_when_parser_reported_yaml_errors(): void
    {
        // Same unclosed-quote fixture as the parseError test — guarantees
        // a real ParseException rather than relying on lenient-YAML ambiguity.
        $md = <<<'MD'
---
slug: a
type: decision
status: "unclosed
---

body
MD;
        $doc = $this->parser->parse($md);
        $result = $this->parser->validate($doc);
        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('frontmatter', $result->errors);
    }

    public function test_validate_accepts_all_nine_canonical_types(): void
    {
        foreach (CanonicalType::cases() as $type) {
            $md = "---\nslug: slug-{$type->value}\ntype: {$type->value}\nstatus: accepted\n---\n\nbody";
            $doc = $this->parser->parse($md);
            $this->assertNotNull($doc);
            $result = $this->parser->validate($doc);
            $this->assertTrue($result->valid, "type {$type->value} must validate; errors: " . json_encode($result->errors));
        }
    }

    private function minimalValidFrontmatter(array $overrides, bool $includeSlug = true): string
    {
        $defaults = ['slug' => 'valid-slug', 'type' => 'decision', 'status' => 'accepted'];
        $data = array_merge($defaults, $overrides);
        if (! $includeSlug) {
            unset($data['slug']);
        }
        $lines = ['---'];
        foreach ($data as $k => $v) {
            $lines[] = "$k: $v";
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'body';
        return implode("\n", $lines);
    }
}
