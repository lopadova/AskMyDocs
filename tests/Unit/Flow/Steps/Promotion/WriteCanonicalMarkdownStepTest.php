<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Promotion;

use App\Flow\Steps\Promotion\WriteCanonicalMarkdownStep;
use App\Models\KbCanonicalAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class WriteCanonicalMarkdownStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_markdown_to_disk_and_records_promoted_audit(): void
    {
        Storage::fake('kb');
        $step = $this->app->make(WriteCanonicalMarkdownStep::class);

        $result = $step->execute($this->context($this->parsedFor('dec-x')));

        $this->assertTrue($result->success);
        $this->assertSame('decisions/dec-x.md', $result->output['relative_path']);
        Storage::disk('kb')->assertExists('decisions/dec-x.md');

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'slug' => 'dec-x',
            'event_type' => 'promoted',
        ]);
    }

    public function test_dry_run_does_not_write_file(): void
    {
        Storage::fake('kb');
        $step = $this->app->make(WriteCanonicalMarkdownStep::class);

        $result = $step->execute($this->context($this->parsedFor('dec-x'), dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        Storage::disk('kb')->assertMissing('decisions/dec-x.md');
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_audit_insert_failure_deletes_orphaned_file_and_rethrows(): void
    {
        // Iter5 (PR #116) — atomicity. If KbCanonicalAudit::create fails
        // after the markdown is on disk, the step's own throw means no
        // compensator runs (compensators only fire for DOWNSTREAM
        // failures). Without this guard we'd leave a promoted file on
        // disk with no audit row, no ingest dispatched. The fix: catch
        // the audit error, delete the just-written file, then rethrow.
        Storage::fake('kb');
        $step = $this->app->make(WriteCanonicalMarkdownStep::class);

        // Force the audit insert to fail by dropping the
        // kb_canonical_audit table — the next INSERT will throw and the
        // step catch-block must clean up.
        Schema::drop('kb_canonical_audit');

        $context = $this->context($this->parsedFor('dec-orphan'));

        $caught = null;
        try {
            $step->execute($context);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        // The original audit error MUST propagate (so the engine sees
        // the step as failed and downstream compensators get a chance).
        $this->assertNotNull($caught, 'Expected the step to rethrow the audit-insert error.');

        // The file must NOT remain on disk — atomic-or-absent.
        Storage::disk('kb')->assertMissing('decisions/dec-orphan.md');
    }

    public function test_throws_when_validate_step_output_missing(): void
    {
        $step = $this->app->make(WriteCanonicalMarkdownStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.promote',
            input: ['tenant_id' => 'default', 'project_key' => 'acme'],
            stepOutputs: [],
            dryRun: false,
        );

        $this->expectException(RuntimeException::class);
        $step->execute($context);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function context(array $parsed, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'write-test',
            definitionName: 'kb.promote',
            input: ['tenant_id' => 'default', 'project_key' => 'acme'],
            stepOutputs: [
                'validate-frontmatter' => [
                    'parsed' => $parsed,
                    'markdown' => $parsed['_raw_markdown'],
                ],
            ],
            dryRun: $dryRun,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedFor(string $slug): array
    {
        $markdown = "---\nid: DEC-0001\nslug: {$slug}\ntype: decision\nstatus: accepted\n---\n\n# Body\n";
        return [
            '_raw_markdown' => $markdown,
            'frontmatter' => ['id' => 'DEC-0001', 'slug' => $slug, 'type' => 'decision', 'status' => 'accepted'],
            'body' => "# Body\n",
            'type' => 'decision',
            'status' => 'accepted',
            'slug' => $slug,
            'docId' => 'DEC-0001',
            'retrievalPriority' => 50,
            'relatedSlugs' => [],
            'supersedesSlugs' => [],
            'supersededBySlugs' => [],
            'tags' => [],
            'owners' => [],
            'summary' => null,
            'parseErrors' => [],
        ];
    }
}
