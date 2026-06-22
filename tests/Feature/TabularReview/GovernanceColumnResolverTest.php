<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Services\TabularReview\GovernanceColumnResolver;
use App\Support\Canonical\EdgeType;
use App\Support\TabularReview\CellFlag;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v8.19/W4 — the deterministic `agent: graph` governance resolver. Each metric
 * is computed from the document's canonical columns + the kb_edges graph, with
 * NO LLM call, and produces a colour-flagged cell.
 */
final class GovernanceColumnResolverTest extends TestCase
{
    use RefreshDatabase;

    private GovernanceColumnResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        $this->resolver = app(GovernanceColumnResolver::class);
    }

    private function doc(array $attrs = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'eng',
            'source_type' => 'markdown',
            'title' => 'Doc '.uniqid(),
            'source_path' => 'kb/'.uniqid().'.md',
            'document_hash' => hash('sha256', uniqid('', true)),
            'version_hash' => hash('sha256', uniqid('', true)),
            'status' => 'active',
            'is_canonical' => true,
            'canonical_status' => 'accepted',
            'canonical_type' => 'decision',
            'doc_id' => 'dec-'.uniqid(),
            'slug' => 'dec-'.Str::random(6),
            'evidence_tier' => 'guideline',
            'retrieval_priority' => 60,
            'frontmatter_json' => ['slug' => 'x', 'type' => 'decision', 'title' => 'T'],
            'source_updated_at' => Carbon::now(),
        ], $attrs));
    }

    private function node(string $uid): void
    {
        KbNode::create([
            'tenant_id' => 'default', 'node_uid' => $uid, 'node_type' => 'decision',
            'label' => $uid, 'project_key' => 'eng', 'source_doc_id' => null, 'payload_json' => null,
        ]);
    }

    private function edge(string $from, string $to, EdgeType $type): void
    {
        KbEdge::create([
            'tenant_id' => 'default', 'edge_uid' => 'e-'.uniqid(),
            'from_node_uid' => $from, 'to_node_uid' => $to, 'edge_type' => $type->value,
            'project_key' => 'eng', 'source_doc_id' => null, 'weight' => 1.0, 'provenance' => 'inferred',
        ]);
    }

    public function test_evidence_tier_strong_is_green_low_confidence_is_red(): void
    {
        // Real EvidenceTier taxonomy: guideline/peer_reviewed/official are strong
        // (GREEN); blog/search_hint/unverified are low-confidence (RED).
        $strong = $this->resolver->resolve($this->doc(['evidence_tier' => 'guideline']), 'evidence_tier');
        $this->assertSame('guideline', $strong['summary']);
        $this->assertSame(CellFlag::GREEN->value, $strong['flag']);

        $weak = $this->resolver->resolve($this->doc(['evidence_tier' => 'blog']), 'evidence_tier');
        $this->assertSame('blog', $weak['summary']);
        $this->assertSame(CellFlag::RED->value, $weak['flag']);

        $mid = $this->resolver->resolve($this->doc(['evidence_tier' => 'news']), 'evidence_tier');
        $this->assertSame(CellFlag::YELLOW->value, $mid['flag']);
    }

    public function test_canonical_status_deprecated_is_red(): void
    {
        $cell = $this->resolver->resolve($this->doc(['canonical_status' => 'deprecated']), 'canonical_status');
        $this->assertSame('deprecated', $cell['summary']);
        $this->assertSame(CellFlag::RED->value, $cell['flag']);
    }

    public function test_frontmatter_completeness_full_is_green(): void
    {
        $cell = $this->resolver->resolve($this->doc(['frontmatter_json' => ['slug' => 's', 'type' => 't', 'title' => 'ti']]), 'frontmatter_completeness');
        $this->assertSame('100%', $cell['summary']);
        $this->assertSame(CellFlag::GREEN->value, $cell['flag']);
    }

    public function test_frontmatter_completeness_partial_is_yellow_or_red(): void
    {
        $cell = $this->resolver->resolve($this->doc(['frontmatter_json' => ['slug' => 's']]), 'frontmatter_completeness');
        $this->assertSame('33%', $cell['summary']);
        $this->assertSame(CellFlag::RED->value, $cell['flag']);
    }

    public function test_graph_connectivity_and_orphan(): void
    {
        $doc = $this->doc();
        $slug = (string) $doc->slug;
        $this->node($slug);
        $this->node('other-1');
        $this->node('other-2');
        $this->edge($slug, 'other-1', EdgeType::DependsOn);     // outgoing
        $this->edge('other-2', $slug, EdgeType::RelatedTo);     // incoming

        $conn = $this->resolver->resolve($doc, 'graph_connectivity');
        $this->assertSame('2', $conn['summary']);
        $this->assertSame(CellFlag::GREEN->value, $conn['flag']);

        $orphan = $this->resolver->resolve($doc, 'is_orphan');
        $this->assertSame('No', $orphan['summary']);
        $this->assertSame(CellFlag::GREEN->value, $orphan['flag']);
    }

    public function test_orphan_document_with_no_edges_is_red(): void
    {
        $doc = $this->doc();
        $this->node((string) $doc->slug);

        $orphan = $this->resolver->resolve($doc, 'is_orphan');
        $this->assertSame('Yes', $orphan['summary']);
        $this->assertSame(CellFlag::RED->value, $orphan['flag']);
    }

    public function test_supersession_superseded_is_red(): void
    {
        $doc = $this->doc();
        $slug = (string) $doc->slug;
        $this->node($slug);
        $this->node('newer');
        // Incoming `supersedes` → a newer doc supersedes THIS one.
        $this->edge('newer', $slug, EdgeType::Supersedes);

        $cell = $this->resolver->resolve($doc, 'supersession_status');
        $this->assertSame('superseded', $cell['summary']);
        $this->assertSame(CellFlag::RED->value, $cell['flag']);
    }

    public function test_supersession_self_declared_superseded_by_is_red(): void
    {
        // A doc's own `superseded_by:` frontmatter is emitted by the canonical
        // pipeline as an OUTGOING invalidated_by edge (from this doc → the newer
        // one), so the doc must read as invalidated/red — not "current".
        $doc = $this->doc();
        $slug = (string) $doc->slug;
        $this->node($slug);
        $this->node('replacement');
        $this->edge($slug, 'replacement', EdgeType::InvalidatedBy); // outgoing

        $cell = $this->resolver->resolve($doc, 'supersession_status');
        $this->assertSame('invalidated', $cell['summary']);
        $this->assertSame(CellFlag::RED->value, $cell['flag']);
    }

    public function test_staleness_old_document_is_red(): void
    {
        $cell = $this->resolver->resolve($this->doc(['source_updated_at' => Carbon::now()->subDays(400)]), 'staleness_days');
        $this->assertGreaterThan(180, (int) $cell['summary']);
        $this->assertSame(CellFlag::RED->value, $cell['flag']);
    }

    public function test_non_canonical_doc_graph_metric_is_grey_not_throwing(): void
    {
        $doc = $this->doc(['is_canonical' => false, 'slug' => null, 'canonical_status' => null]);
        $cell = $this->resolver->resolve($doc, 'graph_connectivity');
        $this->assertSame('n/a', $cell['summary']);
        $this->assertSame(CellFlag::GREY->value, $cell['flag']);
    }

    public function test_unknown_metric_returns_null(): void
    {
        $this->assertNull($this->resolver->resolve($this->doc(), 'not_a_metric'));
        $this->assertNull($this->resolver->resolve($this->doc(), null));
    }
}
