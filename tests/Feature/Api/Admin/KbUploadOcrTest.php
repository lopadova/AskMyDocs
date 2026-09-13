<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbIngestBatchItem;
use App\Models\User;
use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

/**
 * v8.36 / ADR 0029 — the upload modal path: images accepted only with OCR on
 * (R43), magic bytes verified (SEC-UPLOAD-001), and the cost estimate before
 * commit (§8) in both states.
 */
final class KbUploadOcrTest extends TestCase
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
        config(['kb.ocr.driver' => 'fake', 'kb.ocr.rate_per_page' => 0.004, 'ai-finops.currency.base' => 'USD']);
    }

    private function makeAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function png(string $name = 'scan.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(FakeOcrDriver::PNG_1X1, true));
    }

    public function test_off_an_image_upload_is_refused_like_any_unsupported_type(): void
    {
        config(['kb.ocr.enabled' => false]);

        $resp = $this->actingAs($this->makeAdmin())->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [$this->png()],
        ])->assertStatus(422)->assertJsonValidationErrors('files.0');

        $this->assertStringNotContainsString('png', (string) $resp->json('errors.files.0.0'));
    }

    public function test_on_an_image_upload_is_staged_with_the_image_source_type(): void
    {
        config(['kb.ocr.enabled' => true]);

        $resp = $this->actingAs($this->makeAdmin())->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [$this->png()],
        ])->assertStatus(201);

        $resp->assertJsonPath('items.0.source_type', 'image')
            ->assertJsonPath('items.0.mime_type', 'image/png')
            ->assertJsonPath('items.0.status', KbIngestBatchItem::STATUS_STAGED);
        $item = KbIngestBatchItem::query()->findOrFail($resp->json('items.0.id'));
        $this->assertTrue(Storage::disk('kb-staging')->exists($item->staging_path));
        $this->assertStringEndsWith('.png', $item->staging_path);
    }

    public function test_on_a_file_named_png_that_is_not_an_image_is_rejected_by_the_sniffer(): void
    {
        config(['kb.ocr.enabled' => true]);

        $this->actingAs($this->makeAdmin())->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [UploadedFile::fake()->createWithContent('fake.png', 'just text')],
        ])->assertStatus(422)->assertJsonValidationErrors('files.0');
    }

    public function test_estimate_off_reports_disabled_and_zero(): void
    {
        config(['kb.ocr.enabled' => false]);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [
                UploadedFile::fake()->createWithContent('scan.pdf', PdfFixtureBuilder::build(['  '])),
                UploadedFile::fake()->createWithContent('notes.md', "# Notes\n\nplain text never needs OCR\n"),
            ],
        ])->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.total_pages', 0)
            ->assertJsonPath('data.total_cost', 0)
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'ocr_disabled')
            // A text file never needed OCR: the modal must not count it.
            ->assertJsonPath('data.items.1.reason', 'not_ocr_able');
    }

    public function test_estimate_reports_when_the_configured_driver_cannot_run_here(): void
    {
        // R14 — a remote driver with the egress knob off: the modal must warn,
        // not promise a run the registry will refuse at commit.
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => false]);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [$this->png('a.png')],
        ])->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.driver', 'mistral-ocr')
            ->assertJsonPath('data.driver_available', false)
            ->assertJsonPath('data.items.0.would_ocr', true);
        $this->assertStringContainsString('KB_OCR_ALLOW_REMOTE', (string) $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")->json('data.driver_error'));
    }

    /**
     * ADR 0029 §10 — an Sdk-metered driver (vision-llm) has no page rate: the
     * estimate must not price it as `pages × rate`; it says `metering: sdk`
     * and reports no cost, and the modal says the provider meters tokens.
     */
    public function test_estimate_reports_no_page_price_for_an_sdk_metered_driver(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'vision-llm', 'kb.ocr.allow_remote' => true]);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [$this->png('scan.png')],
        ])->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.driver', 'vision-llm')
            ->assertJsonPath('data.metering', 'sdk')
            ->assertJsonPath('data.rate_per_page', 0)
            ->assertJsonPath('data.total_cost', 0)
            ->assertJsonPath('data.items.0.would_ocr', true)
            ->assertJsonPath('data.items.0.cost', 0);

        // The per-page driver keeps its price (the additive contract, R27).
        config(['kb.ocr.driver' => 'fake']);
        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.metering', 'per_page')
            ->assertJsonPath('data.total_cost', 0.004);
    }

    /**
     * ADR 0029 §6 — the converters' own output is never a source: a sub_path
     * inside `{source}.ocr/` or `.artifacts/` is refused at staging, so commit
     * can never dispatch converter output as a source (the generated-asset
     * guard the HTTP ingest entry point already applies).
     */
    public function test_staging_refuses_a_sub_path_inside_a_generated_asset_directory(): void
    {
        config(['kb.ocr.enabled' => true]);
        $admin = $this->makeAdmin();

        foreach (['.artifacts/evil', 'scans/letter.png.ocr/0123456789abcdef'] as $subPath) {
            $this->actingAs($admin)->postJson('/api/admin/kb/uploads', ['project_key' => 'legal', 'sub_path' => $subPath, 'files' => [$this->png()]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['sub_path']);
        }
        $this->assertSame(0, \App\Models\KbIngestBatchItem::query()->count());
    }

    public function test_estimate_flags_an_image_over_the_byte_cap(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.max_bytes' => 10]);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [$this->png('a.png')],
        ])->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'too_many_bytes');
    }

    /**
     * The preflight is input-aware: a batch that holds a PDF needs the
     * rasteriser's Poppler binaries and is told so before commit, while an
     * image-only batch is not refused for a dependency it never uses.
     */
    public function test_estimate_names_a_missing_poppler_binary_only_when_a_pdf_is_staged(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'tesseract', 'kb.ocr.tesseract.binary' => '/bin/sh', 'kb.ocr.tesseract.pdftoppm' => '/nonexistent/pdftoppm', 'kb.ocr.tesseract.pdfinfo' => '/bin/sh']);
        $admin = $this->makeAdmin();

        $images = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [$this->png('a.png')]])
            ->assertStatus(201)->json('batch.id');
        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$images}/estimate")
            ->assertOk()
            ->assertJsonPath('data.driver_available', true);

        $withPdf = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [
            $this->png('b.png'),
            UploadedFile::fake()->createWithContent('scan.pdf', PdfFixtureBuilder::build(['  '])),
        ]])->assertStatus(201)->json('batch.id');
        $response = $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$withPdf}/estimate")
            ->assertOk()
            ->assertJsonPath('data.driver_available', false)
            // Per item: the image the driver can still take, the PDF it cannot.
            ->assertJsonPath('data.items.0.driver_available', true)
            ->assertJsonPath('data.items.1.driver_available', false);
        $this->assertStringContainsString('pdftoppm', (string) $response->json('data.driver_error'));
    }

    /**
     * The PDF branch applies the SAME signature check the service applies
     * before a driver runs: a staged object that is no PDF any more is
     * refused with the reason commit would give, never probed as a scan.
     */
    public function test_estimate_refuses_a_staged_pdf_whose_bytes_are_no_longer_a_pdf(): void
    {
        config(['kb.ocr.enabled' => true]);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [
            UploadedFile::fake()->createWithContent('scan.pdf', PdfFixtureBuilder::build(['  '])),
        ]])->assertStatus(201)->json('batch.id');
        $item = KbIngestBatchItem::query()->where('batch_id', $batchId)->firstOrFail();
        Storage::disk('kb-staging')->put((string) $item->staging_path, (string) base64_decode(FakeOcrDriver::PNG_1X1, true));

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'unrecognised_bytes')
            ->assertJsonPath('data.total_pages', 0);
    }

    /**
     * The estimate applies the SAME magic-byte check the service applies
     * before a driver runs: a staged object replaced since upload by bytes
     * that are no raster is refused with the reason commit would give,
     * never priced as a run that cannot start.
     */
    public function test_estimate_refuses_a_staged_image_whose_bytes_are_no_longer_a_raster(): void
    {
        config(['kb.ocr.enabled' => true]);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [$this->png('a.png')]])
            ->assertStatus(201)->json('batch.id');
        $item = KbIngestBatchItem::query()->where('batch_id', $batchId)->firstOrFail();
        Storage::disk('kb-staging')->put((string) $item->staging_path, '%PDF-1.4 not an image any more');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.pages', 0)
            ->assertJsonPath('data.items.0.reason', 'unrecognised_bytes')
            ->assertJsonPath('data.total_pages', 0);
    }

    public function test_a_staged_jpeg_keeps_its_real_extension_on_the_staging_disk(): void
    {
        config(['kb.ocr.enabled' => true]);
        $admin = $this->makeAdmin();
        $jpeg = UploadedFile::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\x00", 64));
        $resp = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [$jpeg]])->assertStatus(201);

        $item = \App\Models\KbIngestBatchItem::query()->findOrFail((string) $resp->json('items.0.id'));
        $this->assertStringEndsWith('.jpg', (string) $item->staging_path);
        Storage::disk('kb-staging')->assertExists((string) $item->staging_path);
        // ADR 0029 §2 — the exact raster MIME, never the family label, is what
        // the commit dispatches and the document row records.
        $this->assertSame('image/jpeg', (string) $item->mime_type);
    }

    /**
     * ADR 0029 §2 — the client filename decides nothing: a JPEG uploaded as
     * `scan.png` is staged as `.jpg` and recorded as `image/jpeg`, from the
     * magic bytes the request already verified.
     */
    public function test_a_jpeg_uploaded_under_a_png_name_is_staged_as_what_its_bytes_are(): void
    {
        config(['kb.ocr.enabled' => true]);
        $admin = $this->makeAdmin();
        $disguised = UploadedFile::fake()->createWithContent('scan.png', "\xFF\xD8\xFF\xE0".str_repeat("\x00", 64));
        $resp = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [$disguised]])->assertStatus(201);

        $item = \App\Models\KbIngestBatchItem::query()->findOrFail((string) $resp->json('items.0.id'));
        $this->assertSame('image/jpeg', (string) $item->mime_type);
        $this->assertStringEndsWith('.jpg', (string) $item->staging_path);
        Storage::disk('kb-staging')->assertExists((string) $item->staging_path);
    }

    /**
     * A multi-frame TIFF within the page cap is still refused for a driver
     * that transcribes one frame per image — the modal says so before commit
     * instead of quoting N pages the driver would never read (R14).
     */
    public function test_estimate_refuses_a_multi_frame_tiff_for_a_one_frame_driver(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.max_pages' => 200, 'kb.ocr.driver' => 'tesseract']);
        $admin = $this->makeAdmin();
        $header = 'II'.pack('v', 42).pack('V', 8);
        $ifds = '';
        for ($i = 0; $i < 3; $i++) {
            $offset = 8 + strlen($ifds);
            $next = $i === 2 ? 0 : $offset + 2 + 12 + 4;
            $ifds .= pack('v', 1).pack('v', 256).pack('v', 3).pack('V', 1).pack('V', 1).pack('V', $next);
        }
        $tiff = UploadedFile::fake()->createWithContent('multi.tiff', $header.$ifds);
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [$tiff]])
            ->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.pages', 3)
            ->assertJsonPath('data.items.0.reason', 'multi_frame_image');

        // The fake driver (like docling) reads every frame: quoted as 3 pages.
        config(['kb.ocr.driver' => 'fake']);
        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', true)
            ->assertJsonPath('data.items.0.pages', 3);
    }

    /**
     * The estimate takes the SAME decision the converter takes for a PDF
     * the parser cannot read: when the `pdftotext` fallback finds text the
     * document is ingested as text, so no OCR page or spend is quoted.
     */
    public function test_estimate_does_not_quote_ocr_for_an_unreadable_pdf_the_pdftotext_fallback_can_read(): void
    {
        $stub = tempnam(sys_get_temp_dir(), 'pdftotext_stub_');
        file_put_contents($stub, "#!/bin/sh\nprintf 'Plenty of real text on this page\\f'\n");
        chmod($stub, 0755);
        try {
            config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'fake', 'kb.pdf.pdftotext_bin' => $stub]);
            $admin = $this->makeAdmin();
            $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
                'project_key' => 'legal',
                'files' => [UploadedFile::fake()->createWithContent('odd.pdf', '%PDF-1.4 not parseable by the pure-php parser')],
            ])->assertStatus(201)->json('batch.id');

            $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
                ->assertOk()
                ->assertJsonPath('data.items.0.would_ocr', false)
                ->assertJsonPath('data.items.0.reason', 'text_layer_present')
                ->assertJsonPath('data.total_pages', 0);

            // Without the fallback's text the same file IS quoted (fake driver bounds its own work).
            config(['kb.pdf.pdftotext_bin' => '/nonexistent/pdftotext']);
            $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
                ->assertOk()
                ->assertJsonPath('data.items.0.would_ocr', true);
        } finally {
            unlink($stub);
        }
    }

    public function test_estimate_counts_tiff_frames_and_applies_the_page_cap(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.max_pages' => 2]);
        $admin = $this->makeAdmin();
        $header = 'II'.pack('v', 42).pack('V', 8);
        $ifds = '';
        for ($i = 0; $i < 3; $i++) {
            $offset = 8 + strlen($ifds);
            $next = $i === 2 ? 0 : $offset + 2 + 12 + 4;
            $ifds .= pack('v', 1).pack('v', 256).pack('v', 3).pack('V', 1).pack('V', 1).pack('V', $next);
        }
        $tiff = UploadedFile::fake()->createWithContent('multi.tiff', $header.$ifds);
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [$tiff]])
            ->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.pages', 3)
            ->assertJsonPath('data.items.0.reason', 'too_many_pages');
    }

    public function test_estimate_counts_tiff_frames_even_when_uploaded_under_another_image_extension(): void
    {
        // The sniffer accepts any raster and the batch row stores the family
        // MIME, so a 3-frame TIFF named `.png` must still be quoted as 3 pages
        // — the same number `OcrService::pageCountForBytes()` enforces.
        config(['kb.ocr.enabled' => true, 'kb.ocr.max_pages' => 2]);
        $admin = $this->makeAdmin();
        $header = 'II'.pack('v', 42).pack('V', 8);
        $ifds = '';
        for ($i = 0; $i < 3; $i++) {
            $offset = 8 + strlen($ifds);
            $next = $i === 2 ? 0 : $offset + 2 + 12 + 4;
            $ifds .= pack('v', 1).pack('v', 256).pack('v', 3).pack('V', 1).pack('V', 1).pack('V', $next);
        }
        $disguised = UploadedFile::fake()->createWithContent('multi.png', $header.$ifds);
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', ['project_key' => 'legal', 'files' => [$disguised]])
            ->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.pages', 3)
            ->assertJsonPath('data.items.0.reason', 'too_many_pages');
    }

    public function test_estimate_flags_a_pdf_over_the_page_cap(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.max_pages' => 2]);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [UploadedFile::fake()->createWithContent('long.pdf', PdfFixtureBuilder::build(['  ', ' ', '   ']))],
        ])->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'too_many_pages')
            ->assertJsonPath('data.items.0.pages', 3)
            ->assertJsonPath('data.total_cost', 0);
    }

    public function test_estimate_refuses_an_unparseable_pdf_for_a_remote_driver_but_prices_it_as_a_floor_for_a_local_one(): void
    {
        // ADR 0029 §4 — the estimate and the service share the refusal: a
        // floor is not a cap input, so a remote driver never gets the file.
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k']);
        $admin = $this->makeAdmin();
        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-1.4 not really a pdf')],
        ])->assertStatus(201)->json('batch.id');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'pages_uncountable')
            ->assertJsonPath('data.items.0.pages_exact', false)
            ->assertJsonPath('data.total_cost', 0);

        // Same batch, local driver: it runs, and the price is marked inexact.
        config(['kb.ocr.driver' => 'fake']);
        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', true)
            ->assertJsonPath('data.items.0.reason', 'scanned_pdf')
            ->assertJsonPath('data.items.0.pages_exact', false)
            ->assertJsonPath('data.items.0.pages', 1)
            ->assertJsonPath('data.items.0.cost', 0.004)
            ->assertJsonPath('data.total_pages', 1)
            ->assertJsonPath('data.total_cost', 0.004);

        // A whole-file local engine cannot be told the size of what it gets:
        // refused too. Only page-by-page local drivers run on a floor.
        config(['kb.ocr.driver' => 'docling']);
        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'pages_uncountable');

        // Remote driver with the egress knob OFF (R43): both refusals hold —
        // the driver cannot run here AND the count is unverifiable — and the
        // estimate reports both so the modal shows the disabled-driver warning.
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => false]);
        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")
            ->assertOk()
            ->assertJsonPath('data.driver_available', false)
            ->assertJsonPath('data.items.0.would_ocr', false)
            ->assertJsonPath('data.items.0.reason', 'pages_uncountable');
    }

    public function test_estimate_on_prices_images_and_scanned_pdfs_but_not_text_pdfs(): void
    {
        config(['kb.ocr.enabled' => true]);
        $admin = $this->makeAdmin();
        $resp = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'legal',
            'files' => [
                $this->png('a.png'),
                UploadedFile::fake()->createWithContent('scanned.pdf', PdfFixtureBuilder::build(['  ', ' ', '   '])),
                UploadedFile::fake()->createWithContent('mixed.pdf', PdfFixtureBuilder::build(['Cover page with plenty of typed text on it.', '   '], [2])),
                UploadedFile::fake()->createWithContent('text.pdf', PdfFixtureBuilder::buildThreePageSample()),
                UploadedFile::fake()->createWithContent('notes.md', "# Notes\n\nbody"),
            ],
        ])->assertStatus(201);
        $batchId = $resp->json('batch.id');
        $byName = collect($resp->json('items'))->keyBy('original_filename');

        $estimate = $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/estimate")->assertOk()->json('data');
        $items = collect($estimate['items'])->keyBy('id');

        $this->assertTrue($estimate['enabled']);
        $this->assertSame('fake', $estimate['driver']);
        $this->assertSame('USD', $estimate['currency']);
        $this->assertEqualsWithDelta(0.004, $estimate['rate_per_page'], 0.000001);

        $image = $items[$byName['a.png']['id']];
        $this->assertTrue($image['would_ocr']);
        $this->assertSame(1, $image['pages']);
        $this->assertSame('image', $image['reason']);

        $scanned = $items[$byName['scanned.pdf']['id']];
        $this->assertTrue($scanned['would_ocr']);
        $this->assertSame(3, $scanned['pages']);
        $this->assertSame('scanned_pdf', $scanned['reason']);
        $this->assertEqualsWithDelta(0.012, $scanned['cost'], 0.000001);

        // ADR 0029 §1 — a typed cover over a scanned page is priced for the
        // whole document (both pages are OCR'd) and says why.
        $mixed = $items[$byName['mixed.pdf']['id']];
        $this->assertTrue($mixed['would_ocr']);
        $this->assertSame(2, $mixed['pages']);
        $this->assertSame('mixed_pdf', $mixed['reason']);

        $this->assertFalse($items[$byName['text.pdf']['id']]['would_ocr']);
        $this->assertSame('text_layer_present', $items[$byName['text.pdf']['id']]['reason']);
        $this->assertFalse($items[$byName['notes.md']['id']]['would_ocr']);
        $this->assertSame('not_ocr_able', $items[$byName['notes.md']['id']]['reason']);

        $this->assertSame(6, $estimate['total_pages']); // 1 image + 3 scanned + 2 mixed
        $this->assertEqualsWithDelta(0.024, $estimate['total_cost'], 0.000001);
    }
}
