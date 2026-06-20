<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Http\Controllers\Api\KbDocumentPreviewController;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\KbCitationDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guards the `KbCitationDocumentSeeder` the "open source" modal E2E depends on
 * (R22: a broken seeder surfaces as an opaque Playwright timeout). Also proves
 * the cited doc carries chunks AND that the preview endpoint reconstructs them
 * into the modal body — the whole point of the dedicated seeder.
 */
final class KbCitationDocumentSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@demo.local', 'password' => Hash::make('x')]);

        config()->set('rbac.enforced', false);
    }

    public function test_it_seeds_a_conversation_citing_a_doc_whose_chunks_back_the_preview_endpoint(): void
    {
        (new KbCitationDocumentSeeder())->run();

        $conversation = Conversation::query()->forTenant('default')
            ->where('title', 'Source modal demo')->sole();
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $citation = $assistant->metadata['citations'][0];
        $this->assertSame('dec-source-modal', $citation['slug']);

        $doc = KnowledgeDocument::query()->forTenant('default')->where('slug', 'dec-source-modal')->sole();
        $this->assertSame($doc->id, $citation['document_id']);
        $this->assertSame(2, $doc->chunks()->count());

        // The preview endpoint reconstructs the body from the seeded chunks, so
        // the modal has REAL content to render (R13). Authenticate so the
        // auth:sanctum contract is exercised (not bypassed by RBAC=false).
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/kb/documents/{$doc->id}/preview")
            ->assertOk()
            ->assertJsonPath('document_id', $doc->id)
            ->assertJsonPath('content', "We chose Redis as the cache backend with a 1 hour TTL for hot read endpoints.\n\nCache keys are derived from the request signature; invalidation is event-driven.");
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        (new KbCitationDocumentSeeder())->run();
        (new KbCitationDocumentSeeder())->run();

        $this->assertSame(1, Conversation::query()->forTenant('default')->where('title', 'Source modal demo')->count());
        $doc = KnowledgeDocument::query()->forTenant('default')->where('slug', 'dec-source-modal')->sole();
        // Chunks are reset each run — exactly the two source chunks, no dupes.
        $this->assertSame(2, $doc->chunks()->count());
        $conversation = Conversation::query()->forTenant('default')->where('title', 'Source modal demo')->sole();
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_preview_endpoint_rejects_unauthenticated_request(): void
    {
        // R16: the failure path actually fires. Without auth the preview must
        // 401, never leak the cited document body to an anonymous caller.
        (new KbCitationDocumentSeeder())->run();
        $doc = KnowledgeDocument::query()->forTenant('default')->where('slug', 'dec-source-modal')->sole();

        $this->getJson("/api/kb/documents/{$doc->id}/preview")
            ->assertUnauthorized();
    }
}
