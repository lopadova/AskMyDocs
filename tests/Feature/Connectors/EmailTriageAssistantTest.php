<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Jobs\IngestDocumentJob;
use App\Models\EmailTriageProfile;
use App\Models\KbCanonicalAudit;
use App\Models\User;
use App\Services\EmailTriage\TriageExamples;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmailTriageAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'assistant-admin@example.test', 'password' => 'test']);
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.providers.openrouter.key' => 'test-only-key', 'email-triage.assistant_model' => 'openai/gpt-4o-mini']);
    }

    private function installation(string $tenant = 'test-tenant', string $provider = 'imap'): ConnectorInstallation
    {
        return ConnectorInstallation::create(['tenant_id' => $tenant, 'connector_name' => $provider,
            'label' => 'Autry', 'project_key' => 'mail', 'status' => 'active',
            'config_json' => ['host' => 'private-server.example.test', 'username' => 'private-account@example.test']]);
    }

    private function proposal(): array
    {
        return ['summary' => 'Lamentele, tipo di richiesta e urgenza.', 'rules' => TriageExamples::rules(),
            'examples' => [['email' => 'Il ritardo è inaccettabile, voglio restituire il prodotto.', 'expected_tags' => ['lamentela', 'reso']]]];
    }

    private function fakeResponse(array|string $content, string $finish = 'stop'): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models/*/endpoints' => Http::response(['data' => ['endpoints' => [
                ['supported_parameters' => ['response_format', 'temperature', 'max_tokens']],
            ]]]),
            'openrouter.ai/api/v1/chat/completions' => Http::response(['model' => 'openai/gpt-4o-mini',
            'choices' => [['message' => ['content' => is_array($content) ? json_encode($content) : $content], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 600]])]);
    }

    private function assertCompletionCount(int $expected): void
    {
        $this->assertCount($expected, Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/chat/completions')), 'Count billable assistant calls independently of the read-only model capability lookup.');
    }

    public static function emailProviders(): array
    {
        return [['imap'], ['gmail'], ['microsoft-mail']];
    }

    #[DataProvider('emailProviders')]
    public function test_suggestion_is_structured_sanitized_and_never_saves_or_activates_an_account(string $provider): void
    {
        $installation = $this->installation('test-tenant', $provider);
        $profile = EmailTriageProfile::create(['tenant_id' => 'test-tenant', 'connector_installation_id' => $installation->id,
            'revision' => 1, 'enabled' => false, 'rules' => [], 'model' => config('decisions.models.jev.model')]);
        $installation->forceFill(['config_json' => $installation->config_json + ['email_triage_profile_id' => $profile->id]])->save();
        $before = $installation->config_json;
        $this->fakeResponse($this->proposal());
        $this->postJson('/api/admin/connectors/'.$installation->id.'/email-triage/suggest', [
            'prompt' => 'Riconosci lamentele per client@example.test e le urgenze.', 'profile' => ['enabled' => true, 'rules' => []],
        ])->assertOk()->assertJsonPath('data.rules.0.key', 'complaint')->assertJsonCount(3, 'data.rules');
        $this->assertSame($before, $installation->fresh()->config_json);
        $this->assertSame(1, EmailTriageProfile::count());
        $this->assertFalse($profile->fresh()->enabled);
        Queue::assertNotPushed(IngestDocumentJob::class);
        Queue::assertNothingPushed();
        $audit = KbCanonicalAudit::where('event_type', 'email_triage_configuration_suggested')->firstOrFail();
        $this->assertStringNotContainsString('client@example.test', json_encode($audit->metadata_json));
        $this->assertCompletionCount(1);
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/chat/completions')) {
                return false;
            }
            $content = $request['messages'][1]['content'];
            return str_ends_with($request->url(), '/chat/completions')
                && $request['response_format']['json_schema']['strict'] === true
                && $request['provider']['require_parameters'] === true
                && ! str_contains($content, 'client@example.test')
                && ! str_contains($content, 'private-server') && ! str_contains($content, 'private-account');
        });
    }

    public function test_unfinished_manual_draft_can_be_used_to_prepare_valid_rules(): void
    {
        $installation = $this->installation();
        $this->fakeResponse($this->proposal());
        $draft = TriageExamples::rules()[0];
        $draft['key'] = 'not a valid key';
        $draft['options'][0]['criterion'] = '';
        $this->postJson('/api/admin/connectors/'.$installation->id.'/email-triage/suggest', [
            'prompt' => 'Completa la regola sulle lamentele.', 'profile' => ['enabled' => false, 'rules' => [$draft]],
        ])->assertOk();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/chat/completions')
            && json_decode($request['messages'][1]['content'], true)['current_rules'][0]['key'] === 'not a valid key');
    }

    public function test_numeric_score_positions_are_canonicalized_without_changing_labels_or_existing_identifiers(): void
    {
        $installation = $this->installation();
        $data = $this->proposal();
        foreach ($data['rules'][2]['options'] as $index => &$option) {
            $option['key'] = (string) $index;
        }
        unset($option);
        $this->fakeResponse($data);
        $url = '/api/admin/connectors/'.$installation->id.'/email-triage/suggest';
        $this->postJson($url, ['prompt' => 'Riconosci lamentele e urgenza.'])->assertOk()
            ->assertJsonPath('data.rules.2.options.0.key', 'level_0')
            ->assertJsonPath('data.rules.2.options.0.label', 'Ordinaria');
        $this->postJson($url, ['prompt' => 'Riconosci lamentele e urgenza.', 'profile' => ['enabled' => false, 'rules' => TriageExamples::rules()]])
            ->assertOk()->assertJsonPath('data.rules.2.options.0.key', 'ordinary');
        $this->assertSame(0, EmailTriageProfile::count());
    }

    #[DataProvider('invalidCompletions')]
    public function test_invalid_completions_leave_the_existing_configuration_untouched(string $variant): void
    {
        $installation = $this->installation();
        $data = $this->proposal();
        match ($variant) {
            'json' => $data = '{broken',
            'key' => $data['rules'][0]['key'] = 'INVALID KEY',
            'noul' => $data['rules'][0]['options'] = array_reverse($data['rules'][0]['options']),
            'duplicate' => $data['rules'][1]['key'] = $data['rules'][0]['key'],
            'tag' => $data['examples'][0]['expected_tags'] = ['invented_tag'],
            'empty' => $data['rules'] = [],
            'extra' => $data['enabled'] = true,
            'threshold' => $data['rules'][0]['threshold'] = 0.4,
        };
        $this->fakeResponse($data);
        $this->postJson('/api/admin/connectors/'.$installation->id.'/email-triage/suggest', ['prompt' => 'Riconosci lamentele.'])
            ->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->assertSame(0, EmailTriageProfile::count());
        $this->assertArrayNotHasKey('email_triage_profile_id', $installation->fresh()->config_json);
        Queue::assertNothingPushed();
    }

    public static function invalidCompletions(): array
    {
        return array_map(fn ($variant) => [$variant], ['json', 'key', 'noul', 'duplicate', 'tag', 'empty', 'extra', 'threshold']);
    }

    public function test_missing_credentials_invalid_input_and_large_context_never_call_the_provider(): void
    {
        $installation = $this->installation();
        Http::fake();
        $url = '/api/admin/connectors/'.$installation->id.'/email-triage/suggest';
        $this->postJson($url, ['prompt' => '   '])->assertUnprocessable();
        $this->postJson($url, ['prompt' => str_repeat('a', 3001)])->assertUnprocessable();
        config(['ai.providers.openrouter.key' => '']);
        $this->postJson($url, ['prompt' => 'Riconosci lamentele.'])->assertUnprocessable();
        config(['ai.providers.openrouter.key' => 'test-only-key', 'decisions.max_state_bytes' => 20]);
        $this->postJson($url, ['prompt' => 'Riconosci lamentele.'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_provider_errors_and_truncated_answers_return_a_safe_retryable_error(): void
    {
        $installation = $this->installation();
        $url = '/api/admin/connectors/'.$installation->id.'/email-triage/suggest';
        Http::fake(['openrouter.ai/*' => Http::response(['error' => 'sensitive-provider-detail'], 503)]);
        $this->postJson($url, ['prompt' => 'Riconosci lamentele.'])->assertUnprocessable()->assertDontSee('sensitive-provider-detail');
        $this->fakeResponse($this->proposal(), 'length');
        $this->postJson($url, ['prompt' => 'Riconosci lamentele.'])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_connector_authorization_and_tenant_isolation_apply_to_the_assistant(): void
    {
        Http::fake();
        $installation = $this->installation('other-tenant');
        $this->postJson('/api/admin/connectors/'.$installation->id.'/email-triage/suggest', ['prompt' => 'Riconosci lamentele.'])->assertNotFound();
        $local = $this->installation();
        $user = User::create(['name' => 'Reader', 'email' => 'reader-assistant@example.test', 'password' => 'test']);
        $this->actingAs($user)->postJson('/api/admin/connectors/'.$local->id.'/email-triage/suggest', ['prompt' => 'Riconosci lamentele.'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_assistant_budget_is_independent_of_other_admin_requests(): void
    {
        Route::post('/test-admin-budget', fn () => response()->json(['ok' => true]))->middleware(['auth:sanctum', 'throttle:6,1']);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/test-admin-budget')->assertOk();
        }
        $installation = $this->installation();
        $this->fakeResponse($this->proposal());
        $this->postJson('/api/admin/connectors/'.$installation->id.'/email-triage/suggest', ['prompt' => 'Riconosci lamentele.'])->assertOk();
        $this->assertCompletionCount(1);
    }

    public function test_assistant_limits_paid_requests_per_user_without_blocking_another_admin(): void
    {
        $installation = $this->installation();
        $this->fakeResponse($this->proposal());
        $url = '/api/admin/connectors/'.$installation->id.'/email-triage/suggest';
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($url, ['prompt' => 'Riconosci lamentele.'])->assertOk();
        }
        $this->postJson($url, ['prompt' => 'Riconosci lamentele.'])->assertTooManyRequests();
        $this->assertCompletionCount(6);
        $other = User::create(['name' => 'Other admin', 'email' => 'other-assistant-admin@example.test', 'password' => 'test']);
        $other->assignRole('super-admin');
        $this->actingAs($other)->postJson($url, ['prompt' => 'Riconosci lamentele.'])->assertOk();
        $this->assertCompletionCount(7);
    }
}
