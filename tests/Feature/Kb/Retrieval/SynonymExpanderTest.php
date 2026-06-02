<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Models\KbSynonym;
use App\Services\Kb\Retrieval\SynonymExpander;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * v8.7/W1 — SynonymExpander bidirectional query expansion.
 *
 * The expander treats each `kb_synonyms` row as an equivalence GROUP
 * (anchor term + its synonyms). A query mentioning ANY group member is
 * expanded with every OTHER member, so industry jargon connects to its
 * plain-language equivalents at retrieval time. Scoped per (tenant,
 * project); no-op when disabled or when no groups exist.
 */
final class SynonymExpanderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable the per-(tenant, project) cache so each test sees its
        // own freshly-seeded rows without TTL interference.
        config()->set('kb.synonyms.cache_ttl_seconds', 0);
        config()->set('kb.synonyms.enabled', true);
        Cache::flush();
    }

    private function expander(): SynonymExpander
    {
        return new SynonymExpander(app(TenantContext::class));
    }

    private function seedGroup(string $term, array $synonyms, string $project = 'eng', bool $enabled = true): void
    {
        KbSynonym::create([
            'project_key' => $project,
            'term' => $term,
            'synonyms' => $synonyms,
            'enabled' => $enabled,
        ]);
    }

    public function test_expands_forward_from_term_to_synonyms(): void
    {
        $this->seedGroup('k8s', ['kubernetes']);

        $phrases = $this->expander()->expansionPhrases('how to deploy k8s cluster', 'eng');

        $this->assertContains('kubernetes', $phrases);
    }

    public function test_expands_backward_from_synonym_to_term(): void
    {
        $this->seedGroup('k8s', ['kubernetes']);

        $phrases = $this->expander()->expansionPhrases('deploy kubernetes', 'eng');

        $this->assertContains('k8s', $phrases);
    }

    public function test_matches_multi_word_synonym_phrase(): void
    {
        $this->seedGroup('ci', ['continuous integration', 'build pipeline']);

        $phrases = $this->expander()->expansionPhrases('set up continuous integration today', 'eng');

        $this->assertContains('ci', $phrases);
        $this->assertContains('build pipeline', $phrases);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $this->seedGroup('k8s', ['kubernetes']);

        $phrases = $this->expander()->expansionPhrases('Deploy K8S Now', 'eng');

        $this->assertContains('kubernetes', $phrases);
    }

    public function test_does_not_add_members_already_present_in_query(): void
    {
        $this->seedGroup('k8s', ['kubernetes']);

        // Both members already in the query → nothing to add.
        $phrases = $this->expander()->expansionPhrases('k8s kubernetes', 'eng');

        $this->assertSame([], $phrases);
    }

    public function test_no_op_when_feature_disabled(): void
    {
        $this->seedGroup('k8s', ['kubernetes']);
        config()->set('kb.synonyms.enabled', false);

        $this->assertSame([], $this->expander()->expansionPhrases('deploy k8s', 'eng'));
    }

    public function test_no_op_when_no_groups_for_project(): void
    {
        $this->seedGroup('k8s', ['kubernetes'], project: 'eng');

        // Different project → no expansion.
        $this->assertSame([], $this->expander()->expansionPhrases('deploy k8s', 'hr'));
    }

    public function test_disabled_rows_are_ignored(): void
    {
        $this->seedGroup('k8s', ['kubernetes'], enabled: false);

        $this->assertSame([], $this->expander()->expansionPhrases('deploy k8s', 'eng'));
    }

    public function test_expand_query_text_appends_phrases(): void
    {
        $this->seedGroup('k8s', ['kubernetes']);

        $text = $this->expander()->expandQueryText('deploy k8s cluster', 'eng');

        $this->assertSame('deploy k8s cluster kubernetes', $text);
    }

    public function test_expand_query_text_is_verbatim_when_no_match(): void
    {
        $this->seedGroup('k8s', ['kubernetes']);

        $this->assertSame('deploy nginx', $this->expander()->expandQueryText('deploy nginx', 'eng'));
    }

    public function test_groups_are_tenant_scoped(): void
    {
        $tenant = app(TenantContext::class);

        // Group registered under tenant-b only.
        $tenant->set('tenant-b');
        $this->seedGroup('k8s', ['kubernetes']);

        // Active tenant 'default' must NOT see tenant-b's synonyms (R30).
        $tenant->reset();
        $this->assertSame([], $this->expander()->expansionPhrases('deploy k8s', 'eng'));
    }
}
