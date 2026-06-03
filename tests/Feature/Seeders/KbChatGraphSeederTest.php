<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Conversation;
use App\Models\KbEdge;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\KbChatGraphSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.8/W6 — guards the `KbChatGraphSeeder` the Related-panel E2E depends on
 * (R22: a broken seeder surfaces as an opaque Playwright timeout). Idempotent.
 */
final class KbChatGraphSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        // The seeder attaches its conversation to DemoSeeder's admin user.
        User::create(['name' => 'Admin', 'email' => 'admin@demo.local', 'password' => Hash::make('x')]);
    }

    public function test_it_seeds_a_conversation_citing_a_canonical_doc_with_a_graph_neighbour(): void
    {
        (new KbChatGraphSeeder())->run();

        $conversation = Conversation::query()->forTenant('default')
            ->where('title', 'Cache architecture (graph demo)')->sole();
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $citations = $assistant->metadata['citations'];
        $this->assertSame('dec-cache-graph', $citations[0]['slug']);
        $this->assertSame('hr-portal', $citations[0]['project_key']);

        // The cited doc's 1-hop neighbour exists in the graph + as a doc.
        $this->assertTrue(
            KbEdge::query()->forTenant('default')->where('from_node_uid', 'dec-cache-graph')
                ->where('to_node_uid', 'dec-redis-graph')->exists(),
        );
        $this->assertNotNull(
            KnowledgeDocument::query()->forTenant('default')->where('slug', 'dec-redis-graph')->first(),
        );
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        (new KbChatGraphSeeder())->run();
        (new KbChatGraphSeeder())->run();

        $this->assertSame(1, Conversation::query()->forTenant('default')->where('title', 'Cache architecture (graph demo)')->count());
        $conversation = Conversation::query()->forTenant('default')->where('title', 'Cache architecture (graph demo)')->sole();
        // Messages are reset each run — exactly one user + one assistant.
        $this->assertSame(2, $conversation->messages()->count());
    }
}
