<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\JiraConnector;
use App\Jobs\IngestDocumentJob;
use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v4.5/W6 — Asserts the rich frontmatter envelope the JiraConnector
 * passes to IngestDocumentJob carries every documented field plus the
 * `_derived` reranker signal map.
 */
final class JiraFrontmatterCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function setupInstallation(): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'jira',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $user->id,
        ]);
        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => Crypt::encryptString('AT'),
            'encrypted_refresh_token' => Crypt::encryptString('RT'),
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => ['cloud_id' => 'c1'],
        ]);

        return $installation;
    }

    public function test_full_frontmatter_envelope_captured_on_ingest(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->setupInstallation();

        $issue = [
            'id' => '10001',
            'key' => 'PROJ-42',
            'fields' => [
                'summary' => 'Investigate flaky CI',
                'description' => [
                    'type' => 'doc',
                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
                ],
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'In Progress'],
                'priority' => ['name' => 'P1'],
                'project' => [
                    'key' => 'PROJ',
                    'name' => 'Sample Project',
                    'self' => 'https://acme.atlassian.net/rest/api/3/project/10000',
                ],
                'assignee' => ['emailAddress' => 'alice@example.com'],
                'reporter' => ['emailAddress' => 'bob@example.com'],
                'labels' => ['backend', 'urgent'],
                'components' => [['name' => 'api'], ['name' => 'auth']],
                'fixVersions' => [['name' => 'v2.5']],
                'created' => '2026-05-01T10:00:00.000+0000',
                'updated' => '2026-05-11T12:00:00.000+0000',
                'customfield_10020' => [[
                    'name' => 'Sprint 42',
                    'state' => 'active',
                    'id' => 1,
                ]],
                'comment' => ['comments' => []],
            ],
        ];

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ', 'self' => 'https://acme.atlassian.net/rest/api/3/project/10000']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$issue],
                'isLast' => true,
            ], 200),
        ]);

        $this->app->make(JiraConnector::class)->syncFull($installation->id);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            $meta = $job->metadata;

            // Top-level identifiers.
            $this->assertSame('jira', $meta['connector']);
            $this->assertSame('PROJ-42', $meta['jira_issue_key']);
            $this->assertSame('PROJ', $meta['jira_project_key']);
            $this->assertSame('c1', $meta['jira_cloud_id']);
            $this->assertSame('jira', $meta['source']);
            $this->assertSame('PROJ-42', $meta['source_id']);
            $this->assertSame(
                'https://acme.atlassian.net/browse/PROJ-42',
                $meta['source_url'],
                'browse URL must reconstruct from the project self URL',
            );

            // Namespaced jira fields.
            $jira = $meta['converter_hints']['jira'] ?? [];
            $this->assertSame('PROJ', $jira['project_key']);
            $this->assertSame('Sample Project', $jira['project_name']);
            $this->assertSame('PROJ-42', $jira['issue_key']);
            $this->assertSame('Bug', $jira['issue_type']);
            $this->assertSame('In Progress', $jira['status']);
            $this->assertSame('P1', $jira['priority']);
            $this->assertSame('alice@example.com', $jira['assignee']);
            $this->assertSame('bob@example.com', $jira['reporter']);
            $this->assertSame(['backend', 'urgent'], $jira['labels']);
            $this->assertSame(['api', 'auth'], $jira['components']);
            $this->assertSame(['v2.5'], $jira['fix_versions']);
            $this->assertSame('2026-05-01T10:00:00.000+0000', $jira['created']);
            $this->assertSame('2026-05-11T12:00:00.000+0000', $jira['updated']);
            $this->assertSame('Sprint 42', $jira['sprint']);
            $this->assertSame('c1', $jira['cloud_id']);
            $this->assertSame(
                'https://acme.atlassian.net/browse/PROJ-42',
                $jira['source_url'],
            );

            // _derived reranker signals.
            $derived = $meta['converter_hints']['_derived'] ?? [];
            $this->assertIsArray($derived['search_tags']);
            $this->assertContains('backend', $derived['search_tags']);
            $this->assertContains('urgent', $derived['search_tags']);
            $this->assertContains('api', $derived['search_tags']);
            $this->assertTrue($derived['status_active'], 'In Progress is active');
            $this->assertSame('alice@example.com', $derived['owner']);

            return true;
        });
    }

    public function test_done_status_marks_derived_inactive(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->setupInstallation();

        $issue = [
            'id' => '10002',
            'key' => 'PROJ-CLOSED',
            'fields' => [
                'summary' => 'Done issue',
                'description' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]]],
                'status' => ['name' => 'Done'],
                'project' => ['key' => 'PROJ', 'name' => 'P', 'self' => 'https://acme.atlassian.net/rest/api/3/project/10000'],
                'comment' => ['comments' => []],
            ],
        ];

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$issue],
                'isLast' => true,
            ], 200),
        ]);

        $this->app->make(JiraConnector::class)->syncFull($installation->id);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            $derived = $job->metadata['converter_hints']['_derived'] ?? [];
            $this->assertFalse($derived['status_active'], 'Done must mark status_active=false');

            return true;
        });
    }

    public function test_displayname_falls_back_when_email_redacted_by_jira(): void
    {
        // Jira hides emails by default in GDPR strict mode — the
        // connector falls back to displayName so retrieval still gets
        // an owner signal.
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->setupInstallation();

        $issue = [
            'id' => '10003',
            'key' => 'PROJ-GDPR',
            'fields' => [
                'summary' => 'GDPR-strict issue',
                'description' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]]],
                'status' => ['name' => 'Open'],
                'project' => ['key' => 'PROJ', 'name' => 'P', 'self' => 'https://acme.atlassian.net/rest/api/3/project/10000'],
                'assignee' => ['displayName' => 'Alice Anonymised', 'accountId' => 'acc-1'],
                'comment' => ['comments' => []],
            ],
        ];

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$issue],
                'isLast' => true,
            ], 200),
        ]);

        $this->app->make(JiraConnector::class)->syncFull($installation->id);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            $jira = $job->metadata['converter_hints']['jira'] ?? [];
            $this->assertSame('Alice Anonymised', $jira['assignee']);

            return true;
        });
    }
}
