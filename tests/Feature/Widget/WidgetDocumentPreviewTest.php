<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\WidgetIdentity;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Widget\WidgetUserTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WidgetDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private int $keySequence = 0;

    public function test_returns_ordered_sections_grouped_by_adjacent_heading(): void
    {
        $key = $this->makeKey();
        $session = $this->makeSession($key);
        $document = $this->makeDocument($key);
        $document->forceFill(['source_updated_at' => '2026-07-09 10:11:12'])->save();

        $this->makeChunk($document, 2, 'Usage', 'Third paragraph.');
        $this->makeChunk($document, 0, 'Intro', 'First paragraph.');
        $this->makeChunk($document, 1, 'Intro', 'Second paragraph.');
        $this->makeChunk($document, 3, null, 'Closing paragraph.');
        $this->cite($session, $document);

        $response = $this->preview($key, $session, $document);

        $response->assertOk()
            ->assertExactJson([
                'document_id' => $document->id,
                'title' => $document->title,
                'source_path' => $document->source_path,
                'source_type' => 'markdown',
                'language' => 'it',
                'source_updated_at' => '2026-07-09T10:11:12+00:00',
                'sections' => [
                    ['heading_path' => 'Intro', 'content' => "First paragraph.\n\nSecond paragraph."],
                    ['heading_path' => 'Usage', 'content' => 'Third paragraph.'],
                    ['heading_path' => null, 'content' => 'Closing paragraph.'],
                ],
            ]);
        $this->assertNoStore($response);
    }

    public function test_returns_empty_sections_for_a_cited_document_without_chunks(): void
    {
        $key = $this->makeKey();
        $session = $this->makeSession($key);
        $document = $this->makeDocument($key, ['title' => 'Empty source']);
        $this->cite($session, $document);

        $response = $this->preview($key, $session, $document);
        $response->assertOk()->assertJsonPath('sections', []);
        $this->assertNoStore($response);
    }

    public function test_returns_the_same_404_for_missing_or_uncited_documents(): void
    {
        $key = $this->makeKey();
        $session = $this->makeSession($key);
        $uncited = $this->makeDocument($key);

        $uncitedResponse = $this->preview($key, $session, $uncited);
        $missingResponse = $this->withHeaders($this->headers($key))->getJson(
            "/api/widget/sessions/{$session->public_session_id}/documents/999999/preview",
        );

        $uncitedResponse->assertNotFound();
        $missingResponse->assertNotFound();
        $this->assertNoStore($uncitedResponse);
        $this->assertNoStore($missingResponse);
        $this->assertSame(['message' => 'Document not found.'], $uncitedResponse->json());
        $this->assertSame($uncitedResponse->json(), $missingResponse->json());
    }

    public function test_different_widget_key_cannot_open_a_cited_document(): void
    {
        $ownerKey = $this->makeKey();
        $otherKey = $this->makeKey();
        $session = $this->makeSession($ownerKey);
        $document = $this->makeDocument($ownerKey);
        $this->cite($session, $document);

        $this->preview($otherKey, $session, $document)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Document not found.']);
    }

    public function test_document_from_another_project_is_invisible_even_when_its_id_is_cited(): void
    {
        $key = $this->makeKey(['project_key' => 'project-a']);
        $session = $this->makeSession($key);
        $foreignProject = $this->makeDocument($key, ['project_key' => 'project-b']);
        $this->cite($session, $foreignProject);

        $this->preview($key, $session, $foreignProject)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Document not found.']);
    }

    public function test_document_from_another_tenant_is_invisible_even_when_its_id_is_cited(): void
    {
        $key = $this->makeKey(['tenant_id' => 'tenant-a']);
        $session = $this->makeSession($key);
        $foreignTenant = $this->makeDocument($key, ['tenant_id' => 'tenant-b']);
        $this->cite($session, $foreignTenant);

        $this->preview($key, $session, $foreignTenant)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Document not found.']);
    }

    public function test_a_different_widget_identity_cannot_open_the_sessions_document(): void
    {
        $key = $this->makeKey(['user_auth_enabled' => true]);
        $owner = app(WidgetUserTokenService::class)->issue($key, 'owner', 'https://allowed.test');
        $intruder = app(WidgetUserTokenService::class)->issue($key, 'intruder', 'https://allowed.test');
        $session = $this->makeSession($key, $owner['identity']);
        $document = $this->makeDocument($key);
        $this->cite($session, $document);

        $this->withHeaders([
            'Origin' => 'https://allowed.test',
            'Authorization' => 'Bearer '.$intruder['token'],
        ])->getJson($this->previewUrl($session, $document))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Document not found.']);

        $this->withHeaders([
            'Origin' => 'https://allowed.test',
            'Authorization' => 'Bearer '.$owner['token'],
        ])->getJson($this->previewUrl($session, $document))
            ->assertOk();
    }

    public function test_soft_deleted_cited_document_is_invisible(): void
    {
        $key = $this->makeKey();
        $session = $this->makeSession($key);
        $document = $this->makeDocument($key);
        $this->makeChunk($document, 0, 'Intro', 'Content that must remain hidden.');
        $this->cite($session, $document);
        $document->delete();

        $response = $this->preview($key, $session, $document);
        $response->assertNotFound()->assertExactJson(['message' => 'Document not found.']);
        $this->assertNoStore($response);
    }

    private function makeKey(array $overrides = []): WidgetKey
    {
        $this->keySequence++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_preview_'.$this->keySequence,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'preview-'.$this->keySequence,
        ], $overrides));
    }

    private function makeSession(WidgetKey $key, ?WidgetIdentity $identity = null): WidgetSession
    {
        return WidgetSession::create([
            'tenant_id' => $key->tenant_id,
            'widget_key_id' => $key->id,
            'widget_identity_id' => $identity?->id,
            'project_key' => $key->project_key,
            'public_session_id' => Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => 'askmydocs-assistant@1',
            'origin' => 'https://allowed.test',
        ]);
    }

    private function makeDocument(WidgetKey $key, array $overrides = []): KnowledgeDocument
    {
        $path = 'docs/'.Str::uuid()->toString().'.md';

        return KnowledgeDocument::create(array_merge([
            'tenant_id' => $key->tenant_id,
            'project_key' => $key->project_key,
            'source_type' => 'markdown',
            'title' => 'Widget source',
            'source_path' => $path,
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $path),
            'version_hash' => hash('sha256', $path.'-v1'),
        ], $overrides));
    }

    private function makeChunk(KnowledgeDocument $document, int $order, ?string $heading, string $text): void
    {
        KnowledgeChunk::create([
            'tenant_id' => $document->tenant_id,
            'knowledge_document_id' => $document->id,
            'project_key' => $document->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $text.$order),
            'heading_path' => $heading,
            'chunk_text' => $text,
            'metadata' => [],
        ]);
    }

    private function cite(WidgetSession $session, KnowledgeDocument $document): void
    {
        $session->steps()->create([
            'tenant_id' => $session->tenant_id,
            'step_index' => (int) ($session->steps()->max('step_index') ?? -1) + 1,
            'kind' => WidgetSessionStep::KIND_BOT_MESSAGE,
            'args_json' => [
                'content' => 'Grounded answer.',
                'citations' => [[
                    'document_id' => $document->id,
                    'title' => $document->title,
                    'source_path' => $document->source_path,
                ]],
            ],
        ]);
    }

    private function preview(WidgetKey $key, WidgetSession $session, KnowledgeDocument $document): TestResponse
    {
        return $this->withHeaders($this->headers($key))->getJson($this->previewUrl($session, $document));
    }

    private function previewUrl(WidgetSession $session, KnowledgeDocument $document): string
    {
        return "/api/widget/sessions/{$session->public_session_id}/documents/{$document->id}/preview";
    }

    /** @return array<string, string> */
    private function headers(WidgetKey $key): array
    {
        return [
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ];
    }

    private function assertNoStore(TestResponse $response): void
    {
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }
}
