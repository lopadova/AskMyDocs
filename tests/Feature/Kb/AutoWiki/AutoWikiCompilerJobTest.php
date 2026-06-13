<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Jobs\AutoWikiCompilerJob;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.11/P1 — AutoWikiCompilerJob's job-specific decision logic: the
 * version-idempotency predicate + the gate composition that handle() uses to
 * decide whether to compile. (The enrichment itself is covered by
 * AutoWikiCompilerTest; the gate layering by AutoWikiGateTest. We test the
 * predicate + gate directly rather than invoking the ShouldQueue handler, whose
 * sync-worker error-handler management collides with PHPUnit's handler stack.)
 */
final class AutoWikiCompilerJobTest extends TestCase
{
    use RefreshDatabase;

    private function doc(array $overrides = []): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'source_type' => 'markdown',
            'title' => "Doc {$n}",
            'source_path' => "docs/d-{$n}.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => 'ver'.$n,
            'is_canonical' => false,
        ], $overrides));
    }

    // ── version-idempotency predicate ──────────────────────────────────

    public function test_not_compiled_when_no_autowiki_block(): void
    {
        $doc = $this->doc(['version_hash' => 'v1']);
        $this->assertFalse(AutoWikiCompilerJob::isVersionAlreadyCompiled($doc));
    }

    public function test_compiled_when_autowiki_hash_matches_current_version(): void
    {
        $doc = $this->doc([
            'version_hash' => 'v-stable',
            'frontmatter_json' => ['_autowiki' => ['source_version_hash' => 'v-stable']],
        ]);
        $this->assertTrue(AutoWikiCompilerJob::isVersionAlreadyCompiled($doc));
    }

    public function test_not_compiled_when_version_changed_since_last_compile(): void
    {
        $doc = $this->doc([
            'version_hash' => 'v-new',
            'frontmatter_json' => ['_autowiki' => ['source_version_hash' => 'v-old']],
        ]);
        $this->assertFalse(AutoWikiCompilerJob::isVersionAlreadyCompiled($doc));
    }

    // ── gate composition (the second handle() guard) — R43 both-states ──

    public function test_gate_off_path_blocks_compilation(): void
    {
        config(['kb.autowiki.enabled' => false]);
        $doc = $this->doc();
        $this->assertFalse(
            app(AutoWikiGate::class)->allows('default', (string) $doc->project_key, (bool) $doc->is_canonical),
        );
    }

    public function test_gate_on_path_allows_compilation(): void
    {
        config(['kb.autowiki.enabled' => true, 'kb.autowiki.non_canonical_default' => true]);
        $doc = $this->doc(['is_canonical' => false]);
        $this->assertTrue(
            app(AutoWikiGate::class)->allows('default', (string) $doc->project_key, (bool) $doc->is_canonical),
        );
    }

    public function test_gate_blocks_non_canonical_when_default_off(): void
    {
        config(['kb.autowiki.enabled' => true, 'kb.autowiki.non_canonical_default' => false]);
        $doc = $this->doc(['is_canonical' => false]);
        $this->assertFalse(
            app(AutoWikiGate::class)->allows('default', (string) $doc->project_key, (bool) $doc->is_canonical),
        );
    }
}
