<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Evidence\AiManagerEvidenceReviewer;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Padosoft\EvidenceRiskReview\Contracts\EvidenceReviewerLlmContract;
use Padosoft\EvidenceRiskReview\Data\LlmRequest;
use Padosoft\EvidenceRiskReview\EvidenceRiskReview;
use Tests\TestCase;

/**
 * v8.13/P11 — end-to-end integration of padosoft/laravel-evidence-risk-review
 * into AskMyDocs. Verifies the three things the host wiring is responsible for:
 *
 *   1. Tenant scoping (R30) — a review submitted under one tenant is stamped
 *      with that tenant and is invisible to every other tenant, on both the
 *      list and the detail read path. The host-bound TenantResolver forces the
 *      scope; a client cannot widen it via the `tenant` filter.
 *   2. The host LLM adapter (App\Evidence\AiManagerEvidenceReviewer) maps the
 *      package's LlmRequest onto AiManager::chat() and back into an LlmResponse,
 *      decoding a JSON verdict body when present.
 *   3. The optional LLM pass is default-OFF (R43) — with the flag off the
 *      package never calls a model (proved with Mockery shouldNotReceive,
 *      R26); with the flag on the host adapter is invoked exactly once.
 *
 * The admin surface runs under TestCase's forced `api.enabled => true`
 * (production-with-flag-on parity). The clean OFF degrade is covered by
 * EvidenceRiskReviewAdminFlagTest.
 */
final class EvidenceRiskReviewIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mount routes/api.php under the api stack so Sanctum stateful + the
     * Spatie `role:` alias resolve (mirrors the admin controller tests). The
     * package's own /api/admin/evidence-risk-review/* routes are registered
     * separately by EvidenceRiskReviewServiceProvider at boot.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_review_is_stamped_with_active_tenant_and_isolated_across_tenants(): void
    {
        $alice = $this->operatorFor('tenant-a');
        $bob = $this->operatorFor('tenant-b');

        // Alice submits a review while operating inside tenant-a.
        $created = $this->actingAs($alice)
            ->withHeader('X-Tenant-Id', 'tenant-a')
            ->postJson('/api/admin/evidence-risk-review/reviews', $this->reviewPayload('artifact-a'))
            ->assertCreated();

        $reviewId = $created->json('review_id');
        self::assertIsString($reviewId);

        // The persisted row is stamped with the active tenant (R30) — never the
        // client-declared tenant_id.
        $this->assertDatabaseHas('evidence_risk_review_logs', [
            'review_id' => $reviewId,
            'artifact_id' => 'artifact-a',
            'tenant_id' => 'tenant-a',
        ]);

        // Alice (tenant-a) sees her review on the list + detail paths.
        $aliceList = $this->actingAs($alice)
            ->withHeader('X-Tenant-Id', 'tenant-a')
            ->getJson('/api/admin/evidence-risk-review/reviews')
            ->assertOk();
        self::assertSame(1, $aliceList->json('total'));
        self::assertSame($reviewId, $aliceList->json('data.0.review_id'));
        self::assertSame('tenant-a', $aliceList->json('data.0.tenant_id'));

        $this->actingAs($alice)
            ->withHeader('X-Tenant-Id', 'tenant-a')
            ->getJson('/api/admin/evidence-risk-review/reviews/'.$reviewId)
            ->assertOk()
            ->assertJsonPath('review_id', $reviewId);

        // Bob (tenant-b) must NOT see tenant-a's review on the list…
        $bobList = $this->actingAs($bob)
            ->withHeader('X-Tenant-Id', 'tenant-b')
            ->getJson('/api/admin/evidence-risk-review/reviews')
            ->assertOk();
        self::assertSame(0, $bobList->json('total'));
        self::assertSame([], $bobList->json('data'));

        // …nor on the detail path — a cross-tenant id resolves to 404, not a leak.
        $this->actingAs($bob)
            ->withHeader('X-Tenant-Id', 'tenant-b')
            ->getJson('/api/admin/evidence-risk-review/reviews/'.$reviewId)
            ->assertNotFound();
    }

    public function test_a_client_supplied_tenant_filter_cannot_widen_the_scope(): void
    {
        $alice = $this->operatorFor('tenant-a');
        $bob = $this->operatorFor('tenant-b');

        $this->actingAs($alice)
            ->withHeader('X-Tenant-Id', 'tenant-a')
            ->postJson('/api/admin/evidence-risk-review/reviews', $this->reviewPayload('artifact-a'))
            ->assertCreated();

        // Bob asks for tenant-a's rows explicitly — the bound resolver forces
        // his own tenant, so the filter is ignored and he sees nothing.
        $bobList = $this->actingAs($bob)
            ->withHeader('X-Tenant-Id', 'tenant-b')
            ->getJson('/api/admin/evidence-risk-review/reviews?tenant=tenant-a')
            ->assertOk();
        self::assertSame(0, $bobList->json('total'));
    }

    public function test_host_llm_adapter_maps_ai_manager_response_into_llm_response(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')
            ->once()
            ->withArgs(function (string $system, string $prompt, array $options): bool {
                return str_contains($system, 'evidence-and-risk reviewer')
                    && str_contains($system, 'tier_refinement')
                    && $prompt === 'Refine the tiers.'
                    && $options === ['max_tokens' => 512];
            })
            ->andReturn(new AiResponse(
                content: '{"source_tiers":{"s1":"peer_reviewed"}}',
                provider: 'test',
                model: 'test-model',
                totalTokens: 42,
            ));

        $adapter = new AiManagerEvidenceReviewer($ai);
        $response = $adapter->complete(new LlmRequest('tier_refinement', 'Refine the tiers.', [], 512));

        self::assertSame('{"source_tiers":{"s1":"peer_reviewed"}}', $response->text);
        self::assertSame(['source_tiers' => ['s1' => 'peer_reviewed']], $response->data);
        self::assertSame(42, $response->tokensUsed);
    }

    public function test_host_llm_adapter_degrades_non_json_content_to_empty_data(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andReturn(new AiResponse(
            content: 'I could not produce a verdict.',
            provider: 'test',
            model: 'test-model',
            totalTokens: 3,
        ));

        $adapter = new AiManagerEvidenceReviewer($ai);
        $response = $adapter->complete(new LlmRequest('risk_check.evidence_strength', 'Prompt'));

        self::assertSame('I could not produce a verdict.', $response->text);
        self::assertSame([], $response->data);
        self::assertSame(3, $response->tokensUsed);
    }

    public function test_llm_pass_is_off_by_default_and_calls_no_model(): void
    {
        // R43 OFF + R26 — the default-OFF llm flag means the package never
        // reaches the host adapter, so AiManager::chat() is never called even
        // when the artifact explicitly requests label_via_llm.
        config(['evidence-risk-review.llm.enabled' => false]);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');
        $this->rebuildReviewChainWith($ai);

        $result = $this->app->make(EvidenceRiskReview::class)->reviewArray(
            $this->reviewPayload('artifact-off', ['label_via_llm' => true]),
        );

        self::assertFalse($result['metadata']['llm_enabled']);
        self::assertFalse($result['metadata']['heavy_checks_run']);
    }

    public function test_llm_pass_invokes_the_host_adapter_when_enabled(): void
    {
        // R43 ON — with the flag on and label_via_llm requested, the package
        // reaches the host adapter (AiManagerEvidenceReviewer), which calls
        // AiManager::chat(). A definitive claim can also trigger the heavy
        // evidence-strength check, so the adapter may be called more than once;
        // the contract under test is simply "enabled → the host AI stack is
        // invoked" (paired with the OFF test's shouldNotReceive).
        config(['evidence-risk-review.llm.enabled' => true]);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')
            ->atLeast()
            ->once()
            ->andReturn(new AiResponse(
                content: '{"source_tiers":{}}',
                provider: 'test',
                model: 'test-model',
                totalTokens: 11,
            ));
        $this->rebuildReviewChainWith($ai);

        // The contract resolves to the host adapter, not the package Null.
        self::assertInstanceOf(
            AiManagerEvidenceReviewer::class,
            $this->app->make(EvidenceReviewerLlmContract::class),
        );

        $result = $this->app->make(EvidenceRiskReview::class)->reviewArray(
            $this->reviewPayload('artifact-on', [
                'label_via_llm' => true,
                'budget' => ['max_llm_calls' => 2, 'max_tokens' => 2000, 'max_heavy_checks' => 2],
            ]),
        );

        self::assertTrue($result['metadata']['llm_enabled']);
    }

    /**
     * Bind the mocked AiManager and forget the package review-chain singletons
     * so they rebuild against it. RiskSweepEngine / ReviewEngine / the LLM
     * contract / the facade are container singletons that may have been built
     * during boot with the real provider; forgetting them guarantees the
     * mocked AiManager is the one the host adapter wraps for THIS review.
     */
    private function rebuildReviewChainWith(AiManager $ai): void
    {
        $this->instance(AiManager::class, $ai);

        foreach ([
            EvidenceReviewerLlmContract::class,
            \Padosoft\EvidenceRiskReview\Support\RiskSweepEngine::class,
            \Padosoft\EvidenceRiskReview\Support\ReviewEngine::class,
            EvidenceRiskReview::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    /**
     * A super-admin operator. super-admin syncs Permission::all(), which
     * includes `tenant.cross-access`, so AuthorizeTenantHeader lets it act
     * under any `X-Tenant-Id` header (R30). The active tenant for each request
     * is therefore driven entirely by the header, and the bound TenantResolver
     * scopes the review log to it — which is exactly the isolation under test.
     */
    private function operatorFor(string $tenant): User
    {
        $user = User::create([
            'name' => 'Operator '.$tenant,
            'email' => 'super-admin-'.$tenant.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    /**
     * A minimal valid review artifact payload carrying one claim + one source,
     * so the optional tier-refinement LLM pass has something to label.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function reviewPayload(string $artifactId, array $options = []): array
    {
        return [
            'artifact_id' => $artifactId,
            'answer_text' => 'The treatment reduces symptoms in most patients.',
            'claims' => [[
                'id' => 'c1',
                'text' => 'The treatment reduces symptoms in most patients.',
                'assertiveness' => 'definitive',
                'source_ids' => ['s1'],
            ]],
            'sources' => [[
                'id' => 's1',
                'title' => 'Internal clinical note',
            ]],
            'options' => $options,
        ];
    }
}
