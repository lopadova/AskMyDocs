<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KbWikiExportRequest;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v8.38/W4b (ADR 0032 §12) — `kb:prune-wiki-exports` sweeps by `expires_at`,
 * a distinct knob/sweep from `kb:prune-staging-batches` (which sweeps by
 * `created_at` + a terminal-status list).
 */
final class PruneWikiExportsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        Storage::fake((string) config('kb.staging.disk', 'kb-staging'));
    }

    private function completedExport(?\Illuminate\Support\Carbon $expiresAt, bool $withFile = true): KbWikiExportRequest
    {
        $id = Str::uuid()->toString();
        $path = "wiki-exports/{$this->tenantId}/{$id}.zip";
        if ($withFile) {
            Storage::disk((string) config('kb.staging.disk'))->put($path, 'zip-bytes');
        }

        return KbWikiExportRequest::create([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'status' => KbWikiExportRequest::STATUS_COMPLETED,
            'options_json' => ['format' => 'llm-wiki', 'include_images' => false],
            'idempotency_key' => Str::random(64),
            'storage_disk' => (string) config('kb.staging.disk'),
            'storage_path' => $withFile ? $path : null,
            'expires_at' => $expiresAt,
            'completed_at' => now(),
        ]);
    }

    public function test_an_expired_export_is_deleted_along_with_its_file(): void
    {
        $expired = $this->completedExport(now()->subHour());

        $this->artisan('kb:prune-wiki-exports')->assertSuccessful();

        $this->assertDatabaseMissing('kb_wiki_export_requests', ['id' => $expired->id]);
        Storage::disk((string) config('kb.staging.disk'))->assertMissing($expired->storage_path);
    }

    public function test_a_not_yet_expired_export_is_left_alone(): void
    {
        $fresh = $this->completedExport(now()->addHour());

        $this->artisan('kb:prune-wiki-exports')->assertSuccessful();

        $this->assertDatabaseHas('kb_wiki_export_requests', ['id' => $fresh->id]);
        Storage::disk((string) config('kb.staging.disk'))->assertExists($fresh->storage_path);
    }

    public function test_a_queued_export_with_no_expires_at_is_never_swept_regardless_of_age(): void
    {
        $queued = KbWikiExportRequest::create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'status' => KbWikiExportRequest::STATUS_QUEUED,
            'options_json' => ['format' => 'llm-wiki', 'include_images' => false],
            'idempotency_key' => Str::random(64),
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('kb:prune-wiki-exports')->assertSuccessful();

        $this->assertDatabaseHas('kb_wiki_export_requests', ['id' => $queued->id]);
    }

    public function test_dry_run_reports_without_deleting_anything(): void
    {
        $expired = $this->completedExport(now()->subHour());

        $this->artisan('kb:prune-wiki-exports --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('kb_wiki_export_requests', ['id' => $expired->id]);
        Storage::disk((string) config('kb.staging.disk'))->assertExists($expired->storage_path);
    }
}
