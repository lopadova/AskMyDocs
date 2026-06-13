<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbAnalysisSetting;
use App\Services\Kb\AutoWiki\AutoWikiGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.11 — AutoWikiGate layered resolution (config → tenant '*' → project) and
 * the R43 both-states contract (default-ON; an override flips it cleanly).
 */
final class AutoWikiGateTest extends TestCase
{
    use RefreshDatabase;

    private function gate(): AutoWikiGate
    {
        return new AutoWikiGate;
    }

    public function test_default_on_for_canonical_and_non_canonical(): void
    {
        config(['kb.autowiki.enabled' => true, 'kb.autowiki.canonical_default' => true, 'kb.autowiki.non_canonical_default' => true]);

        $this->assertTrue($this->gate()->allows('default', 'docs-v3', isCanonical: true));
        $this->assertTrue($this->gate()->allows('default', 'docs-v3', isCanonical: false));
    }

    public function test_master_switch_off_disables_everything(): void
    {
        config(['kb.autowiki.enabled' => false]);

        $resolved = $this->gate()->resolve('default', 'docs-v3');
        $this->assertFalse($resolved['enabled']);
        $this->assertFalse($resolved['canonical']);
        $this->assertFalse($resolved['non_canonical']);
        $this->assertFalse($this->gate()->allows('default', 'docs-v3', isCanonical: true));
    }

    public function test_non_canonical_default_off_is_honoured(): void
    {
        config(['kb.autowiki.enabled' => true, 'kb.autowiki.canonical_default' => true, 'kb.autowiki.non_canonical_default' => false]);

        $this->assertTrue($this->gate()->allows('default', 'docs-v3', isCanonical: true));
        $this->assertFalse($this->gate()->allows('default', 'docs-v3', isCanonical: false));
    }

    public function test_tenant_wildcard_override_turns_it_off(): void
    {
        config(['kb.autowiki.enabled' => true]);
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => '*', 'autowiki_enabled' => false]);

        $this->assertFalse($this->gate()->allows('default', 'docs-v3', isCanonical: true));
    }

    public function test_exact_project_override_wins_over_tenant_wildcard(): void
    {
        config(['kb.autowiki.enabled' => true]);
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => '*', 'autowiki_enabled' => false]);
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'docs-v3', 'autowiki_enabled' => true]);

        // tenant-wide OFF, but the exact project re-enables it.
        $this->assertTrue($this->gate()->allows('default', 'docs-v3', isCanonical: true));
        // a different project still inherits the tenant-wide OFF.
        $this->assertFalse($this->gate()->allows('default', 'other', isCanonical: true));
    }

    public function test_null_override_field_inherits_config(): void
    {
        config(['kb.autowiki.enabled' => true, 'kb.autowiki.non_canonical_default' => false]);
        // Override only the canonical knob; non_canonical stays null → inherits config (false).
        KbAnalysisSetting::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3',
            'autowiki_enabled' => true, 'autowiki_canonical' => false,
        ]);

        $resolved = $this->gate()->resolve('default', 'docs-v3');
        $this->assertTrue($resolved['enabled']);
        $this->assertFalse($resolved['canonical']);       // explicit override
        $this->assertFalse($resolved['non_canonical']);   // inherited from config
    }
}
