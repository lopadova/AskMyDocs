<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Models\KbIngestBatchItem;
use App\Models\User;
use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Storage\WriteRefusingAdapter;
use Tests\TestCase;

/**
 * PR #492 Copilot round-2 (suppressed comments) — `OcrCostEstimator::forItem()`
 * used to call `Storage::disk($stagingDisk)->exists($stagingPath)` directly,
 * three times over: a Flysystem adapter can THROW on `exists()` as readily as
 * on `get()` (a lost mount, a bucket answering 5xx, an object deleted between
 * the batch listing and the estimate) — and an uncaught throw from ONE staged
 * item used to 500 the WHOLE batch estimate instead of reporting that item's
 * own `staged_file_missing` state, exactly as `readStaged()` already handled
 * the equivalent failure on `get()`. `existsSafely()` closes the same gap on
 * `exists()`.
 */
final class OcrCostEstimatorDiskFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        Storage::fake('kb-staging');
        Storage::fake('kb');
        $this->withHeaders(['Accept' => 'application/json']);
        config(['kb.ocr.driver' => 'fake', 'kb.ocr.rate_per_page' => 0.004, 'kb.ocr.enabled' => true, 'ai-finops.currency.base' => 'USD']);
    }

    private function makeAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $admin->assignRole('admin');

        return $admin;
    }

    /** Swaps the `kb-staging` disk for one whose `exists()`/`fileExists()` throws for the given path. */
    private function makeExistsThrowForPath(string $path): void
    {
        $healthy = Storage::disk('kb-staging');
        $root = $healthy->path('');
        $adapter = new WriteRefusingAdapter(
            new \League\Flysystem\Local\LocalFilesystemAdapter($root),
            static fn (string $p): bool => false,
            static fn (string $p, string $operation): bool => $p === $path && $operation === 'fileExists',
        );
        Storage::set('kb-staging', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));
    }

    public function test_a_staging_disk_that_throws_on_exists_reports_that_item_as_missing_instead_of_500ing_the_batch(): void
    {
        $admin = $this->makeAdmin();
        $upload = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [UploadedFile::fake()->createWithContent('scan.png', (string) base64_decode(FakeOcrDriver::PNG_1X1, true))],
        ])->assertStatus(201);
        $batchId = $upload->json('batch.id');
        $item = KbIngestBatchItem::query()->where('batch_id', $batchId)->firstOrFail();

        $this->makeExistsThrowForPath((string) $item->staging_path);

        try {
            $resp = $this->actingAs($admin)
                ->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
                ->assertStatus(200);
        } finally {
            Storage::fake('kb-staging');
        }

        $resp->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'staged_file_missing');
    }
}
