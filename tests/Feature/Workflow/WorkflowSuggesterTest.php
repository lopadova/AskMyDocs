<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Workflow\WorkflowSuggester;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v4.7/W2 — WorkflowSuggester behaviour tests.
 *
 * Drives the suggester directly with a faked HTTP transport so the
 * LLM contract stays predictable. Covers the happy path, the cache
 * round-trip, force-refresh, the empty-KB refusal, and tenant
 * isolation.
 */
final class WorkflowSuggesterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config()->set('ai.default', 'openai');
        config()->set('ai.providers.openai.api_key', 'test-key');
        // Copilot iter 6: real config key is `chat_model` (underscore),
        // not `chat.model` (dot) — see config/ai.php. The previous
        // spelling was inert (Http::fake intercepts before the config
        // is read), but matching the real key keeps the test honest
        // for any future provider branch that does read it.
        config()->set('ai.providers.openai.chat_model', 'gpt-4o-mini');
        // Reset suggester cache between tests so cache_hit assertions
        // are deterministic.
        Cache::flush();
    }

    public function test_suggest_returns_proposals_on_happy_path(): void
    {
        $user = $this->makeUser();
        $this->makeDoc('hr');
        $this->makeDoc('hr');

        Http::fake([
            '*' => Http::response($this->llmEnvelope([
                [
                    'title' => 'HR Status',
                    'type' => 'tabular',
                    'prompt_md' => 'Extract HR status.',
                    'columns_config' => [
                        ['name' => 'Status', 'prompt' => 'Status.', 'format' => 'text'],
                    ],
                    'practice' => 'generic',
                    'reasoning' => 'HR docs cluster strongly.',
                ],
                [
                    'title' => 'Meeting Notes',
                    'type' => 'assistant',
                    'prompt_md' => 'Summarise meetings.',
                    'practice' => 'generic',
                    'reasoning' => 'Common workflow.',
                ],
            ]), 200),
        ]);

        $suggester = app(WorkflowSuggester::class);
        $payload = $suggester->suggest($user, 2);

        $this->assertCount(2, $payload['proposals']);
        $this->assertSame('HR Status', $payload['proposals'][0]['title']);
        $this->assertSame('tabular', $payload['proposals'][0]['type']);
        $this->assertFalse($payload['meta']['cache_hit']);
        $this->assertSame(2, $payload['meta']['documents_analysed']);
    }

    public function test_suggest_reuses_cache_on_second_call(): void
    {
        $user = $this->makeUser();
        $this->makeDoc('hr');

        Http::fake([
            '*' => Http::response($this->llmEnvelope([
                [
                    'title' => 'WF',
                    'type' => 'assistant',
                    'prompt_md' => 'p',
                    'practice' => 'generic',
                    'reasoning' => 'r',
                ],
            ]), 200),
        ]);

        $suggester = app(WorkflowSuggester::class);
        $first = $suggester->suggest($user, 1);
        $second = $suggester->suggest($user, 1);

        $this->assertFalse($first['meta']['cache_hit']);
        $this->assertTrue($second['meta']['cache_hit']);
        Http::assertSentCount(1);
    }

    public function test_force_refresh_bypasses_cache(): void
    {
        $user = $this->makeUser();
        $this->makeDoc('hr');

        Http::fake([
            '*' => Http::response($this->llmEnvelope([
                [
                    'title' => 'X',
                    'type' => 'assistant',
                    'prompt_md' => 'p',
                    'practice' => 'generic',
                    'reasoning' => 'r',
                ],
            ]), 200),
        ]);

        $suggester = app(WorkflowSuggester::class);
        $suggester->suggest($user, 1);
        $second = $suggester->suggest($user, 1, true);

        $this->assertFalse($second['meta']['cache_hit']);
        Http::assertSentCount(2);
    }

    public function test_empty_kb_returns_refusal(): void
    {
        $user = $this->makeUser();
        // No documents seeded.

        $suggester = app(WorkflowSuggester::class);
        $payload = $suggester->suggest($user, 3);

        $this->assertSame([], $payload['proposals']);
        $this->assertArrayHasKey('reason', $payload['meta']);
        $this->assertSame(0, $payload['meta']['documents_analysed']);
        Http::assertNothingSent();
    }

    public function test_tenant_isolation(): void
    {
        $user = $this->makeUser();

        // Document in 'default' tenant
        app(TenantContext::class)->set('default');
        $this->makeDoc('hr', 'default');

        // Switch tenant, no documents → refusal
        app(TenantContext::class)->set('acme');

        $suggester = app(WorkflowSuggester::class);
        $payload = $suggester->suggest($user, 1);

        $this->assertSame([], $payload['proposals']);
        $this->assertSame(0, $payload['meta']['documents_analysed']);
        $this->assertSame('acme', $payload['meta']['tenant_id']);
    }

    private function makeUser(): User
    {
        $u = User::create([
            'name' => 'U',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $u->assignRole('admin');
        return $u;
    }

    private function makeDoc(string $project, string $tenantId = 'default'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => 'D-'.uniqid(),
            'source_path' => 'p-'.uniqid().'.md',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [],
            'status' => 'indexed',
        ]);
    }

    /**
     * Wrap a JSON-array completion in the OpenAI chat envelope.
     *
     * @param list<array<string, mixed>> $proposals
     * @return array<string, mixed>
     */
    private function llmEnvelope(array $proposals): array
    {
        return [
            'choices' => [[
                'message' => ['content' => json_encode($proposals)],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }
}
