<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Review;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Review\KbReviewService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.37/W3 (ADR 0031 §2/§4, R44 HTTP surface) — the admin HTTP endpoints
 * (review-summary / mark-page-reviewed / review-approve) delegating to the
 * shared {@see KbReviewService} (mocked here so the thin HTTP adapter is
 * tested in isolation, mirroring WikiExplorerTriSurfaceTest's pattern; the
 * service logic itself is covered by KbReviewServiceTest, the PHP/CLI
 * surface by KbReviewCommandTest).
 */
final class KbReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('test-tenant');
    }

    private function bind(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(KbReviewService::class);
        $this->app->instance(KbReviewService::class, $mock);

        return $mock;
    }

    private function doc(): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => 'test-tenant',
            'project_key' => 'eng',
            'source_type' => 'image',
            'source_path' => 'scans/contract.pdf',
            'title' => 'Scanned contract',
            'mime_type' => 'application/pdf',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => bin2hex(random_bytes(16)),
            'is_canonical' => false,
            'generation_source' => GenerationSource::Auto->value,
        ]);
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('admin');

        return $u;
    }

    private function viewer(): User
    {
        $u = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('viewer');

        return $u;
    }

    public function test_api_summary_returns_counts(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();
        $mock = $this->bind();
        $mock->shouldReceive('documentReviewSummary')->once()
            ->andReturn(['total' => 3, 'reviewed' => 1, 'unreviewed' => 2]);

        $this->actingAs($this->admin())
            ->getJson("/api/admin/kb/documents/{$doc->id}/review-summary")
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.reviewed', 1)
            ->assertJsonPath('data.unreviewed', 2);
    }

    public function test_api_mark_page_reviewed_returns_the_row(): void
    {
        $doc = $this->doc();
        $mock = $this->bind();
        $mock->shouldReceive('markPageReviewed')->once()
            ->andReturnUsing(fn () => new \App\Models\KbDocumentPageReview([
                'page_number' => 2,
                'status' => 'reviewed',
                'reviewed_by' => null,
                'reviewed_at' => now(),
            ]));

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/kb/documents/{$doc->id}/pages/2/review-status")
            ->assertOk()
            ->assertJsonPath('data.page_number', 2)
            ->assertJsonPath('data.status', 'reviewed');
    }

    public function test_api_mark_page_reviewed_maps_invalid_page_number_to_422(): void
    {
        $doc = $this->doc();
        $mock = $this->bind();
        $mock->shouldReceive('markPageReviewed')->once()
            ->andThrow(new \InvalidArgumentException('page_number must be >= 1, got 0.'));

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/kb/documents/{$doc->id}/pages/0/review-status")
            ->assertStatus(422);
    }

    public function test_api_approve_returns_result(): void
    {
        $doc = $this->doc();
        $mock = $this->bind();
        $mock->shouldReceive('approve')->once()
            ->andReturn(['approved' => true]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/kb/documents/{$doc->id}/review-approve")
            ->assertOk()
            ->assertJsonPath('data.approved', true);
    }

    public function test_api_summary_404_for_unknown_doc(): void
    {
        config(['kb.review.enabled' => true]);
        $this->bind();

        $this->actingAs($this->admin())
            ->getJson('/api/admin/kb/documents/999999/review-summary')
            ->assertNotFound();
    }

    /**
     * R43 — ADR 0031 §1: the HTTP surface gates EVERY review endpoint,
     * including reads, with a clean 404 when kb.review.enabled is off, even
     * though KbReviewService::documentReviewSummary() is intentionally
     * ungated at the service layer.
     */
    public function test_api_summary_returns_404_when_the_feature_is_disabled(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();
        $mock = $this->bind();
        $mock->shouldNotReceive('documentReviewSummary');

        $this->actingAs($this->admin())
            ->getJson("/api/admin/kb/documents/{$doc->id}/review-summary")
            ->assertNotFound();
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $doc = $this->doc();
        $this->bind();

        $this->actingAs($this->viewer())
            ->postJson("/api/admin/kb/documents/{$doc->id}/review-approve")
            ->assertForbidden();
    }
}
