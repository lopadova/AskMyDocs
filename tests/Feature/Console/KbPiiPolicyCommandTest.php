<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KbPiiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the `kb:pii-policy` CLI read surface (R44 PHP surface).
 */
final class KbPiiPolicyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.pii_redactor.redact_inline_ingest', false);
        config()->set('kb.pii_redactor.ingest_strategy', 'mask');
    }

    public function test_it_reports_config_defaults_when_no_policy_row_exists(): void
    {
        $this->artisan('kb:pii-policy', ['--tenant' => 'acme', '--project' => 'support'])
            ->expectsTable(
                ['Setting', 'Effective'],
                [
                    ['redact_enabled', 'false'],
                    ['strategy', 'mask'],
                ],
            )
            ->assertSuccessful();
    }

    public function test_it_reflects_a_policy_row_override(): void
    {
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => KbPiiSetting::WILDCARD,
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);

        $this->artisan('kb:pii-policy', ['--tenant' => 'acme'])
            ->expectsTable(
                ['Setting', 'Effective'],
                [
                    ['redact_enabled', 'true'],
                    ['strategy', 'tokenise'],
                ],
            )
            ->assertSuccessful();
    }
}
