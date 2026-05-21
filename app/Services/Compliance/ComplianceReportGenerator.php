<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\ComplianceReport;
use App\Models\KnowledgeDocument;
use JsonException;
use RuntimeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ComplianceReportGenerator
{
    public function generate(string $tenantId, string $periodStart, string $periodEnd, ?int $generatedBy = null): ComplianceReport
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $delta = [
            'added' => $this->addedDocs($tenantId, $start, $end),
            'removed' => $this->removedDocs($tenantId, $start, $end),
            'superseded' => $this->supersededDocs($tenantId, $start, $end),
            'promoted' => $this->promotedDocs($tenantId, $start, $end),
            'canonical_diff_snippets' => $this->canonicalDiffSnippets($tenantId, $start, $end),
        ];

        $kbAuditRows = DB::table('kb_canonical_audit')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        $adminAuditRows = DB::table('admin_command_audit')
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$start, $end])
            ->orderBy('started_at')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        $audit = [
            'kb_canonical_audit' => $kbAuditRows,
            'admin_command_audits' => $adminAuditRows,
            'event_type_counts' => $this->eventTypeCounts($kbAuditRows, $adminAuditRows),
            'top_actors' => $this->topActors($kbAuditRows, $adminAuditRows),
        ];

        $payload = [
            'delta' => $delta,
            'audit' => $audit,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
        ];

        $payloadJson = $this->encodePayload($payload);
        $hashSha256 = hash('sha256', $payloadJson);
        $hashHmac = hash_hmac('sha256', $payloadJson.$tenantId.$start->toDateString().$end->toDateString(), $this->hmacSecret());

        return ComplianceReport::create([
            'tenant_id' => $tenantId,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'payload_json' => $payload,
            'hash_sha256' => $hashSha256,
            'hash_hmac' => $hashHmac,
            'generated_at' => now(),
            'generated_by' => $generatedBy,
        ]);
    }

    private function addedDocs(string $tenantId, Carbon $start, Carbon $end): array
    {
        return KnowledgeDocument::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get(['id', 'doc_id', 'slug', 'project_key'])
            ->map(fn ($row): array => $row->toArray())
            ->all();
    }

    private function removedDocs(string $tenantId, Carbon $start, Carbon $end): array
    {
        return KnowledgeDocument::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->onlyTrashed()
            ->whereBetween('deleted_at', [$start, $end])
            ->orderBy('id')
            ->get(['id', 'doc_id', 'slug', 'project_key'])
            ->map(fn ($row): array => $row->toArray())
            ->all();
    }

    private function supersededDocs(string $tenantId, Carbon $start, Carbon $end): array
    {
        return KnowledgeDocument::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('canonical_status', 'superseded')
            ->whereBetween('updated_at', [$start, $end])
            ->orderBy('id')
            ->get(['id', 'doc_id', 'slug', 'project_key'])
            ->map(fn ($row): array => $row->toArray())
            ->all();
    }

    private function promotedDocs(string $tenantId, Carbon $start, Carbon $end): array
    {
        return KnowledgeDocument::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_canonical', true)
            ->whereBetween('updated_at', [$start, $end])
            ->orderBy('id')
            ->get(['id', 'doc_id', 'slug', 'project_key'])
            ->map(fn ($row): array => $row->toArray())
            ->all();
    }

    private function canonicalDiffSnippets(string $tenantId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('kb_canonical_audit')
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', ['updated', 'promoted', 'superseded', 'deprecated'])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get(['doc_id', 'slug', 'before_json', 'after_json']);

        return $rows->map(function ($row): array {
            $before = json_decode((string) ($row->before_json ?? 'null'), true);
            $after = json_decode((string) ($row->after_json ?? 'null'), true);

            return [
                'doc_id' => $row->doc_id,
                'slug' => $row->slug,
                'before_excerpt' => mb_substr((string) ($before['markdown'] ?? ''), 0, 500),
                'after_excerpt' => mb_substr((string) ($after['markdown'] ?? ''), 0, 500),
            ];
        })->all();
    }

    private function eventTypeCounts(array $kbAuditRows, array $adminAuditRows): array
    {
        $counts = [];

        foreach ($kbAuditRows as $row) {
            $eventType = (string) ($row['event_type'] ?? 'unknown');
            $counts[$eventType] = ($counts[$eventType] ?? 0) + 1;
        }

        foreach ($adminAuditRows as $row) {
            $eventType = 'admin_command:'.(string) ($row['command'] ?? 'unknown');
            $counts[$eventType] = ($counts[$eventType] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function topActors(array $kbAuditRows, array $adminAuditRows): array
    {
        $actors = [];

        foreach ($kbAuditRows as $row) {
            $actor = (string) ($row['actor'] ?? '');
            if ($actor !== '') {
                $actors[$actor] = ($actors[$actor] ?? 0) + 1;
            }
        }

        foreach ($adminAuditRows as $row) {
            $actor = (string) ($row['user_id'] ?? 'system');
            $actors[$actor] = ($actors[$actor] ?? 0) + 1;
        }

        arsort($actors);

        return collect($actors)
            ->take(20)
            ->map(fn (int $count, string $actor): array => ['actor' => $actor, 'count' => $count])
            ->values()
            ->all();
    }

    private function hmacSecret(): string
    {
        $secret = (string) config('askmydocs.compliance.hmac_secret', '');
        if ($secret === '') {
            throw new RuntimeException('askmydocs.compliance.hmac_secret is required');
        }

        return $secret;
    }

    private function encodePayload(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode compliance report payload', 0, $e);
        }
    }
}
