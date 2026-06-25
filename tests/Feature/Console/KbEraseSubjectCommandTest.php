<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AdminCommandAudit;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the `kb:erase-subject` CLI GDPR Art.17 surface.
 */
final class KbEraseSubjectCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
    }

    private function vault(string $tenant, string $original): void
    {
        PiiTokenMap::create([
            'tenant_id' => $tenant,
            'token' => '[tok:email:'.substr(md5($tenant.$original), 0, 12).']',
            'original' => $original,
            'detector' => 'email',
        ]);
    }

    public function test_it_crypto_shreds_the_subject_and_audits(): void
    {
        $this->vault('acme', 'mario@example.com');

        $this->artisan('kb:erase-subject', ['values' => ['mario@example.com'], '--tenant' => 'acme'])
            ->expectsOutputToContain('Crypto-shredded 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('pii_token_maps', ['tenant_id' => 'acme', 'original' => 'mario@example.com']);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.erase',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_it_fails_on_a_whitespace_only_value_set(): void
    {
        $this->artisan('kb:erase-subject', ['values' => ['   '], '--tenant' => 'acme'])
            ->assertFailed();
    }

    public function test_it_is_tenant_scoped(): void
    {
        $this->vault('acme', 'mario@example.com');
        $this->vault('globex', 'mario@example.com');

        $this->artisan('kb:erase-subject', ['values' => ['mario@example.com'], '--tenant' => 'acme'])
            ->assertSuccessful();

        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'globex', 'original' => 'mario@example.com']);
    }
}
