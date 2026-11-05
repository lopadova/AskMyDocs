<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Code;

use App\Ai\EmbeddingsResponse;
use App\Jobs\IngestDocumentJob;
use App\Services\Admin\HealthCheckService;
use App\Services\Admin\IngestionObservabilityService;
use App\Services\Kb\Code\TreeSitterBreakPointDriver;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * ADR 0035 D7 §Observability: helper/grammar availability on the health check
 * and per-document chunker + redaction counts on the ingestion status — in
 * both flag states (R43), additive only (R27), counts never values.
 */
final class CodeObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            static fn (array $texts) => new EmbeddingsResponse(array_map(static fn () => array_fill(0, 768, 0.0), $texts), 'fake', 'fake-768'),
        );
        $cache->shouldReceive('activeTarget')->andReturn(['provider' => 'fake', 'model' => 'fake-768']);
        $this->app->instance(EmbeddingCacheService::class, $cache);
        config([
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.code.chunker_driver' => 'heuristic',
            'kb.code.probe_ttl' => 0,
        ]);
        Storage::fake('kb');
        TreeSitterBreakPointDriver::forgetProbe();
    }

    protected function tearDown(): void
    {
        TreeSitterBreakPointDriver::forgetProbe();
        parent::tearDown();
        Mockery::close();
    }

    public function test_off_the_health_check_reports_disabled_and_never_probes(): void
    {
        config(['kb.code.ingestion_enabled' => false, 'kb.code.chunker_driver' => 'auto', 'kb.code.helper_path' => '/nonexistent/helper.mjs']);

        $health = app(HealthCheckService::class)->run();

        $this->assertSame('disabled', $health['code_chunker_ok']);
        $this->assertFalse($health['code_chunker']['enabled']);
        $this->assertNull($health['code_chunker']['helper_ok'], 'no probe while the feature is off');
        // Additive: every pre-existing key is still there.
        foreach (['db_ok', 'pgvector_ok', 'queue_ok', 'kb_disk_ok', 'embedding_provider_ok', 'chat_provider_ok', 'pii_redactor', 'checked_at'] as $key) {
            $this->assertArrayHasKey($key, $health);
        }
    }

    public function test_on_the_health_check_distinguishes_ok_degraded_and_down(): void
    {
        config(['kb.code.ingestion_enabled' => true]);
        $service = app(HealthCheckService::class);

        config(['kb.code.chunker_driver' => 'heuristic']);
        $this->assertSame('ok', $service->codeChunkerOk(), 'heuristic mode needs no helper');

        config(['kb.code.chunker_driver' => 'auto', 'kb.code.helper_path' => '/nonexistent/helper.mjs']);
        TreeSitterBreakPointDriver::forgetProbe();
        $this->assertSame('degraded', $service->codeChunkerOk(), 'auto without a helper falls back: degraded, not down');
        $this->assertSame('helper_missing', $service->codeChunkerReport()['reason']);

        config(['kb.code.chunker_driver' => 'tree_sitter']);
        TreeSitterBreakPointDriver::forgetProbe();
        $this->assertSame('down', $service->codeChunkerOk(), 'forced tree_sitter without a helper fails every ingest');
    }

    public function test_ingestion_status_counts_code_documents_chunkers_and_redactions_without_values(): void
    {
        config(['kb.code.ingestion_enabled' => true]);
        $secret = 'AKIAIOSFODNN7EXAMPLE';
        Storage::disk('kb')->put('src/A.php', "<?php\n\$k = '{$secret}';\nclass A { public function f() {} }\n");
        Storage::disk('kb')->put('src/B.py', "class B:\n    def f(self):\n        return 1\n");
        foreach (['src/A.php' => 'text/x-php', 'src/B.py' => 'text/x-python'] as $path => $mime) {
            (new IngestDocumentJob('repo', $path, 'kb', null, [], $mime))->handle(app(TenantContext::class));
        }

        // The job ingests under its own captured tenant ('default'); read the
        // status as that tenant.
        app(TenantContext::class)->set('default');
        $status = app(IngestionObservabilityService::class)->codeIngestion();

        $this->assertNotNull($status);
        $this->assertTrue($status['enabled']);
        $this->assertSame('heuristic', $status['chunker_driver']);
        $this->assertSame(2, $status['documents']);
        $this->assertGreaterThanOrEqual(2, $status['chunks_by_chunker']['heuristic']);
        $this->assertSame(0, $status['chunks_by_chunker']['tree_sitter']);
        $this->assertSame(1, $status['documents_with_secrets_redacted']);
        $this->assertSame(1, $status['secrets_redacted']);
        $this->assertStringNotContainsString($secret, json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function test_ingestion_status_is_tenant_scoped(): void
    {
        config(['kb.code.ingestion_enabled' => true]);
        Storage::disk('kb')->put('src/A.php', "<?php\nclass A {}\n");
        (new IngestDocumentJob('repo', 'src/A.php', 'kb', null, [], 'text/x-php'))->handle(app(TenantContext::class));

        $tenants = app(TenantContext::class);
        $tenants->set('other-tenant');
        $this->assertSame(0, app(IngestionObservabilityService::class)->codeIngestion()['documents']);
        $tenants->set('default');
        $this->assertSame(1, app(IngestionObservabilityService::class)->codeIngestion()['documents']);
    }

    public function test_the_status_command_prints_the_code_line_only_when_relevant(): void
    {
        config(['kb.code.ingestion_enabled' => false]);
        Artisan::call('ingestion:status');
        $this->assertStringNotContainsString('Code ingestion:', Artisan::output(), 'OFF with no code documents: the output is unchanged');

        config(['kb.code.ingestion_enabled' => true]);
        Artisan::call('ingestion:status');
        $this->assertStringContainsString('Code ingestion: ON', Artisan::output());
    }
}
