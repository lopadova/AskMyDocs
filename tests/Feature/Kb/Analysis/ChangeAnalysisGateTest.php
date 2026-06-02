<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Analysis;

use App\Models\KbAnalysisSetting;
use App\Services\Kb\Analysis\ChangeAnalysisGate;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.8/W3 — `ChangeAnalysisGate` layered resolution:
 * config default → tenant-wide ('*') override → exact-project override,
 * each NULL field inheriting the next level up. Cross-tenant isolation (R30).
 */
final class ChangeAnalysisGateTest extends TestCase
{
    use RefreshDatabase;

    private ChangeAnalysisGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        $this->gate = app(ChangeAnalysisGate::class);
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.change_analysis.canonical_default', true);
        config()->set('kb.change_analysis.non_canonical_default', false);
        config()->set('kb.change_analysis.delete_enabled', true);
    }

    public function test_falls_back_to_config_when_no_override_exists(): void
    {
        $this->assertTrue($this->gate->allows('default', 'eng', isCanonical: true));
        $this->assertFalse($this->gate->allows('default', 'eng', isCanonical: false));
    }

    public function test_exact_project_override_wins_over_config(): void
    {
        KbAnalysisSetting::create([
            'tenant_id' => 'default',
            'project_key' => 'eng',
            'enabled' => false,
        ]);

        $this->assertFalse($this->gate->allows('default', 'eng', isCanonical: true));
        // A different project in the same tenant still follows config.
        $this->assertTrue($this->gate->allows('default', 'hr', isCanonical: true));
    }

    public function test_per_project_split_on_off_in_the_same_tenant(): void
    {
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'eng', 'non_canonical' => true]);
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'hr', 'enabled' => false]);

        // eng: non-canonical opted IN.
        $this->assertTrue($this->gate->allows('default', 'eng', isCanonical: false));
        // hr: disabled entirely.
        $this->assertFalse($this->gate->allows('default', 'hr', isCanonical: true));
    }

    public function test_wildcard_tenant_default_applies_then_exact_project_overrides(): void
    {
        // Tenant-wide: turn everything off.
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => '*', 'enabled' => false]);
        // But re-enable one project.
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'eng', 'enabled' => true]);

        $this->assertFalse($this->gate->allows('default', 'hr', isCanonical: true), 'hr inherits the tenant-wide off');
        $this->assertTrue($this->gate->allows('default', 'eng', isCanonical: true), 'eng re-enabled by its exact override');
    }

    public function test_null_fields_inherit_rather_than_force_false(): void
    {
        // Only flips non_canonical; enabled/canonical/delete stay null = inherit.
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'eng', 'non_canonical' => true]);

        $this->assertTrue($this->gate->allows('default', 'eng', isCanonical: true), 'canonical still inherits config true');
        $this->assertTrue($this->gate->allows('default', 'eng', isCanonical: false), 'non_canonical override true');
    }

    public function test_delete_knob_is_independent_of_change(): void
    {
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'eng', 'delete_enabled' => false]);

        // Change path still allowed; delete path gated off.
        $this->assertTrue($this->gate->allows('default', 'eng', isCanonical: true, isDelete: false));
        $this->assertFalse($this->gate->allows('default', 'eng', isCanonical: true, isDelete: true));
    }

    public function test_overrides_are_tenant_scoped(): void
    {
        // tenant-a disables eng; tenant-b must be unaffected.
        KbAnalysisSetting::create(['tenant_id' => 'tenant-a', 'project_key' => 'eng', 'enabled' => false]);

        $this->assertFalse($this->gate->allows('tenant-a', 'eng', isCanonical: true));
        $this->assertTrue($this->gate->allows('tenant-b', 'eng', isCanonical: true));
    }
}
