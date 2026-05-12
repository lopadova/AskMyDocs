<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * v4.5/W6 — Opt-in live test suite for Atlassian Jira Cloud.
 *
 * Gated behind `CONNECTOR_JIRA_LIVE=1`; skipped in CI by default.
 * Operators follow `docs/v4-platform/RUNBOOK-live-fixture-recording.md`
 * → section "Atlassian Jira Cloud" to provision credentials. When
 * recording mode is on, `HttpResponseRecorder` persists scrubbed
 * responses under `tests/Fixtures/connectors/jira/recorded/`.
 */
final class JiraLiveTest extends LiveConnectorTestCase
{
    protected static function gateEnvVar(): string
    {
        return 'CONNECTOR_JIRA_LIVE';
    }

    protected static function requiredCredentialEnvVars(): array
    {
        return ['CONNECTOR_JIRA_TOKEN', 'CONNECTOR_JIRA_CLOUD_ID'];
    }

    protected static function providerSlug(): string
    {
        return 'jira';
    }

    private function apiBase(): string
    {
        $cloudId = (string) getenv('CONNECTOR_JIRA_CLOUD_ID');

        return "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3";
    }

    private function token(): string
    {
        return (string) getenv('CONNECTOR_JIRA_TOKEN');
    }

    #[Test]
    public function myself_endpoint_responds_with_account_payload(): void
    {
        $response = Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get($this->apiBase().'/myself');

        $this->assertTrue($response->successful(), 'Jira /myself returned: '.$response->status());
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('accountId', $body);
    }

    #[Test]
    public function project_search_returns_pageable_response(): void
    {
        $response = Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get($this->apiBase().'/project/search', [
                'startAt' => 0,
                'maxResults' => 5,
            ]);

        $this->assertTrue($response->successful(), 'Jira /project/search returned: '.$response->status());
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('values', $body);
    }

    #[Test]
    public function search_endpoint_accepts_jql(): void
    {
        $response = Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get($this->apiBase().'/search', [
                'jql' => 'order by updated DESC',
                'startAt' => 0,
                'maxResults' => 5,
                'fields' => 'summary,status',
            ]);

        $this->assertTrue($response->successful(), 'Jira /search returned: '.$response->status());
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('issues', $body);
    }

    #[Test]
    public function incremental_jql_with_updated_date_returns_results(): void
    {
        // JQL's date format is Jira-specific ("YYYY-MM-DD HH:mm") —
        // ISO-8601 with `Z` is rejected by the search endpoint. Use
        // `gmdate()` so the timestamp is UTC regardless of the
        // operator's local timezone (Copilot iter1 finding #6 —
        // `date()` would otherwise format in the server's local
        // timezone and false-negative for operators outside UTC).
        $since = gmdate('Y-m-d H:i', strtotime('-30 days'));
        $response = Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get($this->apiBase().'/search', [
                'jql' => 'updated >= "'.$since.'" ORDER BY updated DESC',
                'startAt' => 0,
                'maxResults' => 5,
                'fields' => 'summary,status,updated',
            ]);

        $this->assertTrue($response->successful(), 'Jira /search incremental returned: '.$response->status());
        $this->assertArrayHasKey('issues', $response->json() ?? []);
    }

    #[Test]
    public function accessible_resources_returns_jira_capable_site(): void
    {
        $response = Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get('https://api.atlassian.com/oauth/token/accessible-resources');

        $this->assertTrue($response->successful(), 'Atlassian /accessible-resources returned: '.$response->status());
        $body = $response->json();
        $this->assertIsArray($body);
        // At least one site with a `read:jira-*` scope.
        $found = false;
        foreach ($body as $resource) {
            $scopes = $resource['scopes'] ?? [];
            if (! is_array($scopes)) {
                continue;
            }
            foreach ($scopes as $scope) {
                if (is_string($scope) && str_starts_with($scope, 'read:jira')) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($found, 'No Jira-capable resource in accessible-resources response.');
    }
}
