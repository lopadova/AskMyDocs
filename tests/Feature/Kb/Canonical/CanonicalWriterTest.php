<?php

namespace Tests\Feature\Kb\Canonical;

use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Canonical\CanonicalWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CanonicalWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    protected function tearDown(): void
    {
        // One test uses `Storage::shouldReceive(...)` — flush Mockery's
        // expectations here so they don't leak to subsequent tests.
        Mockery::close();
        parent::tearDown();
    }

    public function test_writes_decision_under_decisions_folder(): void
    {
        $markdown = $this->validDecisionMarkdown('dec-cache-v2');
        $parsed = (new CanonicalParser())->parse($markdown);

        $path = (new CanonicalWriter())->write($parsed, $markdown);

        $this->assertSame('decisions/dec-cache-v2.md', $path);
        Storage::disk('kb')->assertExists('decisions/dec-cache-v2.md');
        $this->assertSame($markdown, Storage::disk('kb')->get('decisions/dec-cache-v2.md'));
    }

    public function test_writes_runbook_under_runbooks_folder(): void
    {
        $markdown = "---\nslug: runbook-x\ntype: runbook\nstatus: accepted\n---\n\n# Runbook X";
        $parsed = (new CanonicalParser())->parse($markdown);

        $path = (new CanonicalWriter())->write($parsed, $markdown);

        $this->assertSame('runbooks/runbook-x.md', $path);
    }

    public function test_rejected_approaches_go_to_rejected_folder(): void
    {
        $markdown = "---\nslug: rej-full-purge\ntype: rejected-approach\nstatus: accepted\n---\n\n# Rejected";
        $parsed = (new CanonicalParser())->parse($markdown);

        $path = (new CanonicalWriter())->write($parsed, $markdown);

        $this->assertSame('rejected/rej-full-purge.md', $path);
    }

    public function test_respects_kb_path_prefix_configuration(): void
    {
        config()->set('kb.sources.path_prefix', 'tenants/acme');
        $markdown = $this->validDecisionMarkdown('dec-x');
        $parsed = (new CanonicalParser())->parse($markdown);

        $path = (new CanonicalWriter())->write($parsed, $markdown);

        Storage::disk('kb')->assertExists('tenants/acme/decisions/dec-x.md');
        $this->assertSame('decisions/dec-x.md', $path, 'Returned path is always relative to the prefix');
    }

    public function test_throws_invalid_argument_when_type_has_no_path_convention(): void
    {
        // Craft a DTO with a type missing from kb.promotion.path_conventions.
        config()->set('kb.promotion.path_conventions.decision', null);
        unset(config()['kb.promotion.path_conventions.decision']);
        config()->set('kb.promotion.path_conventions', []);

        $markdown = $this->validDecisionMarkdown('dec-x');
        $parsed = (new CanonicalParser())->parse($markdown);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no path convention');

        (new CanonicalWriter())->write($parsed, $markdown);
    }

    public function test_throws_runtime_exception_when_disk_put_returns_false(): void
    {
        // Swap the 'kb' disk for a stub that reports put() = false.
        Storage::fake('kb-broken');
        config()->set('filesystems.disks.kb-broken', config('filesystems.disks.kb'));
        config()->set('kb.sources.disk', 'kb-broken');

        // Use a Storage manager spy so put() returns false.
        Storage::shouldReceive('disk')->with('kb-broken')->andReturn(new class {
            public function put(string $path, string $contents): bool
            {
                return false;
            }
            public function exists(string $path): bool
            {
                return false;
            }
        });

        $markdown = $this->validDecisionMarkdown('dec-x');
        $parsed = (new CanonicalParser())->parse($markdown);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write');

        (new CanonicalWriter())->write($parsed, $markdown);
    }

    public function test_throws_when_doc_has_no_slug(): void
    {
        // CanonicalParsedDocument built directly with null slug (parser would
        // normally reject but we simulate a bypass).
        $parsed = new \App\Services\Kb\Canonical\CanonicalParsedDocument(
            frontmatter: ['type' => 'decision', 'status' => 'accepted'],
            body: '# x',
            type: \App\Support\Canonical\CanonicalType::Decision,
            status: \App\Support\Canonical\CanonicalStatus::Accepted,
            slug: null,
            docId: null,
            retrievalPriority: 50,
            relatedSlugs: [],
            supersedesSlugs: [],
            supersededBySlugs: [],
            tags: [],
            owners: [],
            summary: null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('slug');

        (new CanonicalWriter())->write($parsed, '# x');
    }

    public function test_throws_when_doc_has_no_type(): void
    {
        $parsed = new \App\Services\Kb\Canonical\CanonicalParsedDocument(
            frontmatter: ['slug' => 'x'],
            body: '# x',
            type: null,
            status: null,
            slug: 'x',
            docId: null,
            retrievalPriority: 50,
            relatedSlugs: [],
            supersedesSlugs: [],
            supersededBySlugs: [],
            tags: [],
            owners: [],
            summary: null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type');

        (new CanonicalWriter())->write($parsed, '# x');
    }

    private function validDecisionMarkdown(string $slug): string
    {
        return <<<MD
---
id: DEC-2026-0001
slug: {$slug}
type: decision
status: accepted
---

# Decision {$slug}

Body.
MD;
    }
}
