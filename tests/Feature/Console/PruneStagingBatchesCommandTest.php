<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.9 — kb:prune-staging-batches retention sweep.
 */
final class PruneStagingBatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb-staging');
    }

    public function test_prunes_stale_batch_and_keeps_fresh_one(): void
    {
        $stale = $this->makeBatch('stale.md', staleHours: 48);
        $fresh = $this->makeBatch('fresh.md', staleHours: 0);

        $this->artisan('kb:prune-staging-batches')->assertSuccessful();

        // Stale batch + its items + its staged dir are gone.
        $this->assertDatabaseMissing('kb_ingest_batches', ['id' => $stale->id]);
        $this->assertDatabaseMissing('kb_ingest_batch_items', ['batch_id' => $stale->id]);
        $this->assertFalse(Storage::disk('kb-staging')->exists("default/{$stale->id}"));

        // Fresh batch survives.
        $this->assertDatabaseHas('kb_ingest_batches', ['id' => $fresh->id]);
        $this->assertTrue(Storage::disk('kb-staging')->exists("default/{$fresh->id}"));
    }

    public function test_hours_override_widens_the_window(): void
    {
        $batch = $this->makeBatch('a.md', staleHours: 2);

        // Default 24h would keep a 2h-old batch; --hours=1 prunes it.
        $this->artisan('kb:prune-staging-batches', ['--hours' => 1])->assertSuccessful();

        $this->assertDatabaseMissing('kb_ingest_batches', ['id' => $batch->id]);
    }

    private function makeBatch(string $filename, int $staleHours): KbIngestBatch
    {
        $batch = KbIngestBatch::create([
            'project_key' => 'engineering',
            'status' => KbIngestBatch::STATUS_STAGED,
        ]);

        $stagingPath = "default/{$batch->id}/{$filename}";
        Storage::disk('kb-staging')->put($stagingPath, '# x');

        $item = new KbIngestBatchItem([
            'tenant_id' => 'default',
            'batch_id' => $batch->id,
            'original_filename' => $filename,
            'staging_path' => $stagingPath,
            'destination_path' => $filename,
            'mime_type' => 'text/markdown',
            'source_type' => 'markdown',
            'size_bytes' => 3,
            'status' => KbIngestBatchItem::STATUS_STAGED,
        ]);
        $item->save();

        if ($staleHours > 0) {
            DB::table('kb_ingest_batches')->where('id', $batch->id)
                ->update(['created_at' => now()->subHours($staleHours)]);
        }

        return $batch;
    }
}
