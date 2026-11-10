<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\PathContext;

use App\Models\AdminCommandAudit;
use App\Models\KbPathContext;
use App\Services\Kb\PathContext\PathContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.44/W4 (ADR 0035 §D6.4) — the path-context core: prefix matching,
 * ordering, tenancy, audit, atomicity.
 */
final class PathContextServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kb.path_contexts.enabled' => true]);
    }

    protected function tearDown(): void
    {
        AdminCommandAudit::flushEventListeners();
        parent::tearDown();
    }

    private function svc(): PathContextService
    {
        return $this->app->make(PathContextService::class);
    }

    public function test_upsert_normalises_the_prefix_and_replaces_on_the_same_key(): void
    {
        $a = $this->svc()->upsert('t1', 'hr', '/policies//', 'first');
        $b = $this->svc()->upsert('t1', 'hr', 'policies', 'second');

        $this->assertSame('policies', $a->path_prefix);
        $this->assertSame($a->id, $b->id, 'one row per (tenant, project, prefix)');
        $this->assertSame('second', $b->description);
        $this->assertSame(1, KbPathContext::query()->count());
    }

    public function test_invalid_prefix_and_description_are_rejected(): void
    {
        foreach (['', '   ', '../etc', 'a/../b', '/'] as $bad) {
            try {
                $this->svc()->upsert('t1', 'hr', $bad, 'x');
                $this->fail("prefix [{$bad}] must be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->upsert('t1', 'hr', 'policies', str_repeat('x', 501));
    }

    public function test_matching_is_segment_aware_not_a_string_prefix(): void
    {
        $this->svc()->upsert('t1', 'hr', 'policies', 'HR policies');

        $this->assertSame(['HR policies'], $this->svc()->chainFor('t1', 'hr', 'policies/leave.md'));
        $this->assertSame(['HR policies'], $this->svc()->chainFor('t1', 'hr', 'policies/2026/x.md'));
        $this->assertSame([], $this->svc()->chainFor('t1', 'hr', 'policies-old/leave.md'), '`policies-old` is not under `policies`');
        $this->assertSame([], $this->svc()->chainFor('t1', 'hr', 'other/policies/x.md'));
        $this->assertSame([], $this->svc()->chainFor('t1', 'finance', 'policies/leave.md'), 'another project');
    }

    public function test_the_chain_reads_general_to_specific(): void
    {
        $this->svc()->upsert('t1', 'hr', 'policies/leave', 'Leave rules');
        $this->svc()->upsert('t1', 'hr', 'policies', 'HR policies');
        $this->svc()->upsert('t1', 'hr', 'policies/leave/2026', 'Leave 2026');

        $this->assertSame(
            ['HR policies', 'Leave rules', 'Leave 2026'],
            $this->svc()->chainFor('t1', 'hr', 'policies/leave/2026/x.md'),
            'shortest prefix first, so the most specific description reads last',
        );
    }

    public function test_it_never_crosses_the_tenant_boundary(): void
    {
        $this->svc()->upsert('t1', 'hr', 'policies', 'tenant one');
        $this->svc()->upsert('t2', 'hr', 'policies', 'tenant two');

        $this->assertSame(['tenant one'], $this->svc()->chainFor('t1', 'hr', 'policies/x.md'));
        $this->assertSame(['tenant two'], $this->svc()->chainFor('t2', 'hr', 'policies/x.md'));
        $this->assertCount(1, $this->svc()->list('t1'));
        $this->assertFalse($this->svc()->delete('t1', KbPathContext::query()->forTenant('t2')->firstOrFail()->id), 'a foreign id is indistinguishable from a missing one');
        $this->assertSame(2, KbPathContext::query()->count());
    }

    public function test_the_batch_form_resolves_many_documents_with_one_lookup_per_project(): void
    {
        $this->svc()->upsert('t1', 'hr', 'policies', 'HR');
        $this->svc()->upsert('t1', 'ops', 'runbooks', 'Ops');

        $out = $this->svc()->chainsFor('t1', [
            ['project_key' => 'hr', 'source_path' => 'policies/a.md'],
            ['project_key' => 'ops', 'source_path' => 'runbooks/b.md'],
            ['project_key' => 'ops', 'source_path' => 'misc/c.md'],
            ['project_key' => 'hr', 'source_path' => '../../escape'],
        ]);

        $this->assertSame(['HR'], $out[$this->svc()->key('hr', 'policies/a.md')]);
        $this->assertSame(['Ops'], $out[$this->svc()->key('ops', 'runbooks/b.md')]);
        $this->assertCount(2, $out, 'no-match and traversal-shaped paths are simply absent');
    }

    public function test_disabled_flag_yields_no_chain_but_the_data_is_still_manageable(): void
    {
        $this->svc()->upsert('t1', 'hr', 'policies', 'HR');
        config(['kb.path_contexts.enabled' => false]);

        $this->assertFalse($this->svc()->enabled());
        $this->assertSame([], $this->svc()->chainFor('t1', 'hr', 'policies/a.md'), 'OFF: nothing is injected');
        $this->assertCount(1, $this->svc()->list('t1'), 'staging while OFF is allowed');
    }

    public function test_every_mutation_is_audited_without_the_prose(): void
    {
        $row = $this->svc()->upsert('t1', 'hr', 'policies', 'Sensitive internal wording', actorId: null);
        $this->svc()->delete('t1', $row->id);

        $audits = AdminCommandAudit::query()->orderBy('id')->get();
        $this->assertSame(['kb:path-context:upsert', 'kb:path-context:delete'], $audits->pluck('command')->all());
        $this->assertSame(hash('sha256', 'Sensitive internal wording'), $audits[0]->args_json['description_sha256']);
        $this->assertStringNotContainsString('Sensitive internal wording', json_encode($audits->map->args_json->all()));
        $this->assertSame('t1', $audits[0]->tenant_id);
    }

    public function test_a_write_never_exists_without_its_audit_row(): void
    {
        AdminCommandAudit::creating(static function (): void {
            throw new \RuntimeException('audit store down');
        });

        try {
            $this->svc()->upsert('t1', 'hr', 'policies', 'x');
            $this->fail('expected the audit failure to abort the write');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, KbPathContext::query()->count(), 'rolled back with the audit');
    }
}
