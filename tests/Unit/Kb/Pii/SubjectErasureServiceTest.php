<?php

declare(strict_types=1);

namespace Tests\Unit\Kb\Pii;

use App\Services\Kb\Pii\SubjectErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — GDPR Art.17 crypto-shred of the per-tenant token vault.
 * Tenant-scoped (R30); idempotent on empty input.
 */
final class SubjectErasureServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubjectErasureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubjectErasureService();
    }

    private function vault(string $tenant, string $token, string $original, string $detector = 'email'): void
    {
        PiiTokenMap::create([
            'tenant_id' => $tenant,
            'token' => $token,
            'original' => $original,
            'detector' => $detector,
        ]);
    }

    public function test_erase_values_shreds_only_the_matching_rows_in_the_tenant(): void
    {
        $this->vault('acme', '[tok:email:aaaa1111]', 'mario@example.com');
        $this->vault('acme', '[tok:email:bbbb2222]', 'luigi@example.com');

        $erased = $this->service->eraseValues('acme', ['mario@example.com']);

        $this->assertSame(1, $erased);
        $this->assertDatabaseMissing('pii_token_maps', ['tenant_id' => 'acme', 'original' => 'mario@example.com']);
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'acme', 'original' => 'luigi@example.com']);
    }

    public function test_erase_values_never_crosses_tenants(): void
    {
        $this->vault('acme', '[tok:email:aaaa1111]', 'mario@example.com');
        $this->vault('globex', '[tok:email:cccc3333]', 'mario@example.com');

        $erased = $this->service->eraseValues('acme', ['mario@example.com']);

        $this->assertSame(1, $erased);
        // Globex's identical-value mapping survives (R30 — tenant isolation).
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'globex', 'original' => 'mario@example.com']);
    }

    public function test_erase_values_is_a_noop_for_an_empty_value_set(): void
    {
        $this->vault('acme', '[tok:email:aaaa1111]', 'mario@example.com');

        $this->assertSame(0, $this->service->eraseValues('acme', []));
        $this->assertSame(0, $this->service->eraseValues('acme', ['', '   ']));
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'acme', 'original' => 'mario@example.com']);
    }

    public function test_snapshot_values_returns_the_tenant_scoped_vault_entries(): void
    {
        $this->vault('acme', '[tok:email:aaaa1111]', 'mario@example.com');
        $this->vault('globex', '[tok:email:cccc3333]', 'mario@example.com');

        $snapshot = $this->service->snapshotValues('acme', ['mario@example.com']);

        $this->assertCount(1, $snapshot);
        $this->assertSame('[tok:email:aaaa1111]', $snapshot[0]['token']);
        $this->assertSame('mario@example.com', $snapshot[0]['original']);
        $this->assertSame('email', $snapshot[0]['detector']);
    }
}
