<?php

namespace Tests\Feature\Admin;

use App\Services\Admin\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_ok_when_connection_is_alive(): void
    {
        $svc = new HealthCheckService;
        $this->assertSame('ok', $svc->dbOk());
    }

    public function test_pgvector_ok_is_ok_on_non_pgsql_driver(): void
    {
        // Test suite runs on sqlite — pgvector check degrades to `ok`
        // because the extension check is meaningless there.
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $svc = new HealthCheckService;
        $this->assertSame('ok', $svc->pgvectorOk());
    }

    public function test_queue_ok_when_failed_jobs_is_below_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'sync',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'test',
            ]);
        }

        $svc = new HealthCheckService;
        $this->assertSame('ok', $svc->queueOk());
    }

    public function test_queue_degraded_when_failed_jobs_reaches_threshold(): void
    {
        for ($i = 0; $i < 11; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'sync',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'test',
            ]);
        }

        $svc = new HealthCheckService;
        $this->assertSame('degraded', $svc->queueOk());
    }

    public function test_kb_disk_ok_uses_configured_disk(): void
    {
        Storage::fake('kb');

        $svc = new HealthCheckService;
        // `exists('.')` returns true on the faked disk root — `ok`.
        $this->assertSame('ok', $svc->kbDiskOk());
    }

    public function test_embedding_provider_ok_when_default_provider_is_configured(): void
    {
        config([
            'ai.embeddings_provider' => 'openai',
            'ai.providers' => ['openai' => ['api_key' => 'sk-test']],
        ]);

        $svc = new HealthCheckService;
        $this->assertSame('ok', $svc->embeddingProviderOk());
    }

    public function test_embedding_provider_degraded_when_missing_from_providers(): void
    {
        config([
            'ai.embeddings_provider' => 'mystery',
            'ai.providers' => ['openai' => ['api_key' => 'sk-test']],
        ]);

        $svc = new HealthCheckService;
        $this->assertSame('degraded', $svc->embeddingProviderOk());
    }

    public function test_embedding_provider_degraded_when_default_is_empty(): void
    {
        config([
            'ai.embeddings_provider' => null,
            'ai.providers' => ['openai' => ['api_key' => 'sk-test']],
        ]);

        $svc = new HealthCheckService;
        $this->assertSame('degraded', $svc->embeddingProviderOk());
    }

    public function test_chat_provider_ok_when_default_provider_is_configured(): void
    {
        config([
            'ai.default_provider' => 'anthropic',
            'ai.providers' => ['anthropic' => ['api_key' => 'sk-ant']],
        ]);

        $svc = new HealthCheckService;
        $this->assertSame('ok', $svc->chatProviderOk());
    }

    public function test_run_returns_every_concern_and_timestamp(): void
    {
        $svc = new HealthCheckService;
        $out = $svc->run();

        $this->assertArrayHasKey('db_ok', $out);
        $this->assertArrayHasKey('pgvector_ok', $out);
        $this->assertArrayHasKey('queue_ok', $out);
        $this->assertArrayHasKey('kb_disk_ok', $out);
        $this->assertArrayHasKey('embedding_provider_ok', $out);
        $this->assertArrayHasKey('chat_provider_ok', $out);
        $this->assertArrayHasKey('checked_at', $out);
    }
}
