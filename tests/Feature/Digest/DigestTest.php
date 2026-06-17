<?php

declare(strict_types=1);

namespace Tests\Feature\Digest;

use App\Jobs\SendDigestWebhookJob;
use App\Mail\DigestMail;
use App\Models\KbContributionEvent;
use App\Models\KbSearchFailure;
use App\Models\KnowledgeDocument;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Digest\AiDigestNarrator;
use App\Services\Digest\DigestComposer;
use App\Services\Digest\Renderers\DigestRendererRegistry;
use App\Services\Digest\Renderers\DiscordDigestRenderer;
use App\Services\Digest\Renderers\SlackDigestRenderer;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * v8.15/W2 — rich multi-channel engagement digest.
 *
 * Coverage: composer sections (R30 scoped), AI narrator OFF/ON (R43),
 * renderer-registry mutex (R23), channel card shapes, digest:send dry-run
 * sends nothing + real send queues email + webhook jobs, admin preview API.
 */
final class DigestTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
    }

    private function seedDoc(string $sourcePath = 'docs/d1.md', string $title = 'Doc One'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => 'eng',
            'source_path' => $sourcePath,
            'source_type' => 'markdown',
            'title' => $title,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'slug' => null,
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $sourcePath),
            'metadata' => [],
            'indexed_at' => now(),
        ]);
    }

    private function seedActivity(): void
    {
        $doc = $this->seedDoc();
        KbContributionEvent::create([
            'tenant_id' => 'default', 'user_id' => 7, 'document_id' => $doc->id,
            'project_key' => 'eng', 'event' => 'created', 'weight' => 5, 'created_at' => now(),
        ]);
        KbContributionEvent::create([
            'tenant_id' => 'default', 'user_id' => 7, 'document_id' => $doc->id,
            'project_key' => 'eng', 'event' => 'promoted', 'weight' => 8, 'created_at' => now(),
        ]);
        KbSearchFailure::create([
            'tenant_id' => 'default', 'project_key' => 'eng',
            'query_hash' => hash('sha256', 'how to deploy'), 'normalized_query' => 'how to deploy',
            'query_text' => 'how to deploy', 'reason' => KbSearchFailure::REASON_NO_CONTEXT,
            'occurrences' => 9, 'last_seen_at' => now(),
        ]);
    }

    public function test_composer_builds_tenant_scoped_sections(): void
    {
        $this->seedActivity();

        $payload = app(DigestComposer::class)->composeForTenant('weekly');

        $this->assertSame('default', $payload->tenantId);
        $this->assertSame('weekly', $payload->frequency);
        $this->assertGreaterThanOrEqual(1, count($payload->newDocs));
        $this->assertSame('how to deploy', $payload->topGaps[0]['question']);
        $this->assertArrayHasKey('contributors', $payload->metrics);
    }

    public function test_narrator_returns_null_when_disabled(): void
    {
        config()->set('kb.digest.ai_narrative_enabled', false);
        $this->seedActivity();
        $payload = app(DigestComposer::class)->composeForTenant('weekly');

        $this->assertNull(app(AiDigestNarrator::class)->narrate($payload));
    }

    public function test_narrator_produces_text_when_enabled(): void
    {
        config()->set('kb.digest.ai_narrative_enabled', true);
        config()->set('kb.digest.ai_provider', 'fake');
        config()->set('kb.digest.ai_model', null);
        $this->seedActivity();
        $payload = app(DigestComposer::class)->composeForTenant('weekly');

        $narrative = app(AiDigestNarrator::class)->narrate($payload);
        $this->assertIsString($narrative);
        $this->assertNotSame('', $narrative);
    }

    public function test_renderer_registry_rejects_duplicate_channel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DigestRendererRegistry([new DiscordDigestRenderer(), new DiscordDigestRenderer()]);
    }

    public function test_channel_renderers_emit_expected_shapes(): void
    {
        $this->seedActivity();
        $payload = app(DigestComposer::class)->composeForTenant('weekly');

        $discord = (new DiscordDigestRenderer())->render($payload);
        $this->assertArrayHasKey('embeds', $discord);

        $slack = (new SlackDigestRenderer())->render($payload);
        $this->assertArrayHasKey('blocks', $slack);
    }

    public function test_digest_send_dry_run_sends_nothing(): void
    {
        Mail::fake();
        Bus::fake();
        $this->seedActivity();
        config()->set('askmydocs.notifications.channels.discord.url', 'https://discord.test/webhook');

        $this->artisan('digest:send', ['--tenant' => 'default', '--dry-run' => true])->assertExitCode(0);

        Mail::assertNothingQueued();
        Bus::assertNotDispatched(SendDigestWebhookJob::class);
    }

    public function test_digest_send_queues_email_and_channel_card(): void
    {
        Mail::fake();
        Bus::fake();
        $this->seedActivity();

        // An email-enabled recipient.
        $user = User::create(['name' => 'Eng', 'email' => 'eng-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        NotificationPreference::create([
            'tenant_id' => 'default', 'user_id' => $user->id,
            'event_type' => 'kb_doc_created', 'channel' => NotificationPreference::CHANNEL_EMAIL, 'enabled' => true,
        ]);
        config()->set('askmydocs.notifications.channels.discord.url', 'https://discord.test/webhook');
        config()->set('kb.digest.ai_narrative_enabled', false);

        $this->artisan('digest:send', ['--tenant' => 'default'])->assertExitCode(0);

        Mail::assertQueued(DigestMail::class);
        Bus::assertDispatched(SendDigestWebhookJob::class, fn ($job) => $job->channelName === 'discord');
    }

    public function test_preview_endpoint_returns_payload_and_cards(): void
    {
        $this->seedActivity();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $admin->assignRole('admin');

        $res = $this->actingAs($admin)->getJson('/api/admin/digest/preview?frequency=weekly');
        $res->assertOk()
            ->assertJsonPath('frequency', 'weekly')
            ->assertJsonStructure(['payload' => ['metrics', 'new_docs', 'top_gaps'], 'cards' => ['discord', 'slack', 'teams']]);
    }
}
