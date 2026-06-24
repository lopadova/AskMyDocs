<?php

declare(strict_types=1);

namespace Tests\Unit\Kb\Pii;

use App\Models\KbPiiSetting;
use App\Services\Kb\Pii\KbPiiPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the per-(tenant, project) PII ingestion-policy resolver.
 *
 * Asserts the layered inheritance (config → tenant '*' → exact project) in BOTH
 * states (R43) and cross-tenant isolation (R30).
 */
final class KbPiiPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    private KbPiiPolicyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new KbPiiPolicyResolver();
        // Pin the config defaults so the assertions are independent of the
        // ambient .env values.
        config([
            'kb.pii_redactor.redact_inline_ingest' => false,
            'kb.pii_redactor.ingest_strategy' => 'mask',
        ]);
    }

    public function test_with_no_rows_it_returns_the_config_defaults(): void
    {
        $this->assertSame(
            ['redact_enabled' => false, 'strategy' => 'mask'],
            $this->resolver->resolve('acme', '*'),
        );
        $this->assertSame(
            ['redact_enabled' => false, 'strategy' => 'mask'],
            $this->resolver->resolve('acme', 'support'),
        );
    }

    public function test_tenant_wildcard_row_overrides_config_for_every_project(): void
    {
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => KbPiiSetting::WILDCARD,
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);

        $this->assertSame(
            ['redact_enabled' => true, 'strategy' => 'tokenise'],
            $this->resolver->resolve('acme', KbPiiSetting::WILDCARD),
        );
        // A project with no row of its own inherits the tenant-wide override.
        $this->assertSame(
            ['redact_enabled' => true, 'strategy' => 'tokenise'],
            $this->resolver->resolve('acme', 'support'),
        );
    }

    public function test_exact_project_row_wins_over_tenant_wildcard_field_by_field(): void
    {
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => KbPiiSetting::WILDCARD,
            'redact_enabled' => true,
            'strategy' => 'mask',
        ]);
        // Project overrides ONLY the strategy; redact_enabled is null → inherits
        // the wildcard's true.
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => 'support',
            'redact_enabled' => null,
            'strategy' => 'tokenise',
        ]);

        $this->assertSame(
            ['redact_enabled' => true, 'strategy' => 'tokenise'],
            $this->resolver->resolve('acme', 'support'),
        );
        // Another project still only sees the wildcard.
        $this->assertSame(
            ['redact_enabled' => true, 'strategy' => 'mask'],
            $this->resolver->resolve('acme', 'sales'),
        );
    }

    public function test_project_can_disable_redaction_the_tenant_enabled(): void
    {
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => KbPiiSetting::WILDCARD,
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => 'public-faq',
            'redact_enabled' => false,
            'strategy' => null,
        ]);

        $this->assertSame(
            ['redact_enabled' => false, 'strategy' => 'tokenise'],
            $this->resolver->resolve('acme', 'public-faq'),
        );
    }

    public function test_blank_strategy_override_is_treated_as_inherit(): void
    {
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => KbPiiSetting::WILDCARD,
            'redact_enabled' => true,
            'strategy' => '   ',
        ]);

        $this->assertSame(
            ['redact_enabled' => true, 'strategy' => 'mask'],
            $this->resolver->resolve('acme', KbPiiSetting::WILDCARD),
        );
    }

    public function test_unrecognised_strategy_in_a_row_falls_back_to_mask(): void
    {
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => KbPiiSetting::WILDCARD,
            'redact_enabled' => true,
            'strategy' => 'bogus',
        ]);

        $this->assertSame('mask', $this->resolver->resolve('acme', KbPiiSetting::WILDCARD)['strategy']);
    }

    public function test_unrecognised_config_strategy_is_returned_raw_for_a_loud_failure(): void
    {
        // The operator env knob is authoritative: a typo is NOT coerced to mask
        // here — it is returned raw so IngestStrategyResolver throws loudly at
        // ingest (R14), matching the connector boundary. (Contrast: a DB ROW with
        // garbage IS coerced to mask above, since rows are write-validated.)
        config(['kb.pii_redactor.ingest_strategy' => 'tokenize']); // typo, missing trailing 's'

        $this->assertSame('tokenize', $this->resolver->resolve('acme', KbPiiSetting::WILDCARD)['strategy']);
    }

    public function test_one_tenant_policy_never_leaks_into_another(): void
    {
        KbPiiSetting::create([
            'tenant_id' => 'acme',
            'project_key' => KbPiiSetting::WILDCARD,
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);

        // Tenant 'globex' has no rows → it still resolves to the config defaults,
        // NOT acme's enabled+tokenise policy (R30).
        $this->assertSame(
            ['redact_enabled' => false, 'strategy' => 'mask'],
            $this->resolver->resolve('globex', KbPiiSetting::WILDCARD),
        );
    }
}
