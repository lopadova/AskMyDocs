<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\User;
use App\Models\WidgetKey;
use App\Models\WidgetProjectSetup;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WidgetProjectSetupTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/widget-setups';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_setup_creates_a_dedicated_project_and_remains_available_to_the_team(): void
    {
        $this->signIn('super-admin');
        $project = $this->createSetup();

        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.0.id', $project->id);
        $this->getJson(self::BASE.'/'.$project->id)->assertOk()
            ->assertJsonPath('data.language', 'Italiano')
            ->assertJsonPath('data.topics', 'Returns and warranty')
            ->assertJsonPath('data.indexed_documents', 0);
        $this->postJson(self::BASE, $this->input())->assertUnprocessable()->assertJsonValidationErrors('project_key');
        $this->assertDatabaseCount('widget_project_setups', 1);

        $other = Project::query()->create(['tenant_id' => 'another-team', 'name' => 'Other', 'project_key' => 'other']);
        WidgetProjectSetup::query()->create([
            'project_id' => $other->id, 'user_auth_enabled' => false, 'language' => 'English', 'audience' => 'Staff', 'topics' => 'Other',
        ]);
        $this->getJson(self::BASE)->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'/'.$other->id)->assertNotFound();

        $this->signIn('admin');
        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE.'/'.$project->id.'/key', $this->keyInput())->assertForbidden();
    }

    public function test_public_key_is_issued_once_after_indexing_and_does_not_enable_the_data_agent(): void
    {
        $this->signIn('super-admin');
        $project = $this->createSetup();
        $url = self::BASE.'/'.$project->id.'/key';
        $this->postJson($url, $this->keyInput())->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->postJson($url, ['allowed_origins' => ['https://help.example.test/path']] + $this->keyInput())
            ->assertUnprocessable()->assertJsonValidationErrors('allowed_origins');
        $this->indexDocument($project);

        $batch = $this->batch($project, KbIngestBatch::STATUS_PROCESSING);
        $this->getJson(self::BASE.'/'.$project->id)->assertJsonPath('data.is_indexing', true);
        $this->postJson($url, $this->keyInput())->assertUnprocessable()->assertJsonValidationErrors('documents');
        $batch->update(['status' => KbIngestBatch::STATUS_COMPLETED_WITH_ERRORS]);
        $this->batchItem($batch, KbIngestBatchItem::STATUS_FAILED);
        $this->getJson(self::BASE.'/'.$project->id)->assertJsonPath('data.failures.0.path', 'warranty.md');
        $this->postJson($url, $this->keyInput())->assertUnprocessable()->assertJsonValidationErrors('accept_partial');

        $this->batchItem($this->batch($project, KbIngestBatch::STATUS_COMPLETED, 1), KbIngestBatchItem::STATUS_SUCCEEDED);
        $this->getJson(self::BASE.'/'.$project->id)->assertJsonCount(0, 'data.failures');
        $issued = $this->postJson($url, $this->keyInput())->assertCreated()
            ->assertJsonPath('data.user_auth_enabled', false)
            ->assertJsonPath('data.allowed_origins', ['https://help.example.test'])
            ->assertJsonPath('data.theme.mode', 'inline');
        $key = WidgetKey::query()->findOrFail($issued->json('data.id'));
        $this->assertFalse($key->data_agent_enabled);
        $this->postJson($url, $this->keyInput())->assertOk()->assertJsonPath('data.id', $key->id);
        $this->assertDatabaseCount('widget_keys', 1);

        $headers = ['Origin' => 'https://help.example.test', 'X-Widget-Key' => $key->public_key];
        $this->getJson('/api/widget/setup', $headers)->assertOk()->assertJsonPath('data_agent.enabled', false);
        $this->postJson('/api/widget/sessions/agent/start', ['snapshot' => [], 'message' => 'List orders'], $headers)->assertForbidden();

        KbIngestBatch::query()->forTenant($project->tenant_id)->where('project_key', $project->project_key)->delete();
        $this->getJson(self::BASE.'/'.$project->id)->assertJsonPath('data.indexed_documents', 1)
            ->assertJsonPath('data.key.id', $key->id);
    }

    public function test_authenticated_setup_issues_the_host_credential_only_on_creation(): void
    {
        $this->signIn('super-admin');
        $project = $this->createSetup(true);
        $this->indexDocument($project);
        $url = self::BASE.'/'.$project->id.'/key';
        $response = $this->postJson($url, $this->keyInput())->assertCreated()->assertJsonPath('data.user_auth_enabled', true);
        $this->assertStringStartsWith('ik_', $response->json('identity_plain_secret'));
        $this->postJson($url, $this->keyInput())->assertOk()->assertJsonMissingPath('identity_plain_secret');
        $this->getJson(self::BASE.'/'.$project->id)->assertJsonMissingPath('identity_plain_secret');

        $token = $this->postJson('/api/widget/user-token', [
            'subject' => 'internal-user-uuid',
            'origin' => 'https://help.example.test',
        ], [
            'Authorization' => 'Bearer '.$response->json('identity_plain_secret'),
            'X-Widget-Key' => $response->json('data.public_key'),
        ])->assertCreated()->json('token');
        $this->getJson('/api/widget/setup', ['Authorization' => 'Bearer '.$token, 'Origin' => 'https://help.example.test'])
            ->assertOk()->assertJsonPath('project', $project->project_key);
    }

    private function signIn(string $role): void
    {
        $user = User::query()->create([
            'name' => 'Widget operator', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password'),
        ]);
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function createSetup(bool $authenticated = false): Project
    {
        $id = $this->postJson(self::BASE, ['user_auth_enabled' => $authenticated] + $this->input())->assertCreated()->json('data.id');

        return Project::query()->findOrFail($id);
    }

    private function input(): array
    {
        return ['name' => 'Customer Help', 'language' => 'Italiano', 'audience' => 'Customers', 'topics' => 'Returns and warranty', 'user_auth_enabled' => false];
    }

    private function keyInput(): array
    {
        return ['label' => 'Help', 'mode' => 'inline', 'allowed_origins' => ['https://Help.example.test:443/']];
    }

    private function indexDocument(Project $project): void
    {
        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => $project->tenant_id, 'project_key' => $project->project_key, 'source_type' => 'markdown',
            'title' => 'Return policy', 'source_path' => 'returns.md', 'mime_type' => 'text/markdown', 'status' => 'active', 'indexed_at' => now(),
        ]);
        KnowledgeChunk::query()->create([
            'tenant_id' => $project->tenant_id, 'project_key' => $project->project_key, 'knowledge_document_id' => $doc->id,
            'chunk_order' => 1, 'chunk_hash' => 'abc', 'chunk_text' => 'Returns are accepted within 30 days.', 'embedding' => [], 'metadata' => [],
        ]);
    }

    private function batch(Project $project, string $status, int $offset = 0): KbIngestBatch
    {
        return KbIngestBatch::query()->create([
            'tenant_id' => $project->tenant_id, 'project_key' => $project->project_key, 'status' => $status, 'committed_at' => now()->addSeconds($offset),
        ]);
    }

    private function batchItem(KbIngestBatch $batch, string $status): void
    {
        $batch->items()->create([
            'tenant_id' => $batch->tenant_id, 'status' => $status, 'original_filename' => 'warranty.md',
            'staging_path' => Str::uuid().'.md', 'destination_path' => 'warranty.md', 'mime_type' => 'text/markdown',
            'source_type' => 'markdown', 'size_bytes' => 20,
        ]);
    }
}
