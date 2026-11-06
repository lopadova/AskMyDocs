<?php

declare(strict_types=1);

namespace App\Services\Kb\PathContext;

use App\Models\AdminCommandAudit;
use App\Models\KbPathContext;
use App\Support\KbPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * v8.44/W4 (ADR 0035 §D6.4) — path contexts: admin-authored descriptions of a
 * part of the corpus, bound to a `source_path` prefix within a (tenant,
 * project), returned with citations and rendered in the RAG prompt.
 *
 * The ONE core behind the tri-surface (R44): `kb:path-context`,
 * `/api/admin/kb/path-contexts`, and the read-only `KbPathContextsTool`
 * (writing prompt-visible text is deliberately kept off the agent surface —
 * documented R44 exception). Every method takes the tenant EXPLICITLY (R30);
 * the HTTP/CLI adapters pass the active {@see \App\Support\TenantContext}.
 *
 * Prefix matching is SEGMENT-aware and case-sensitive: the prefix `policies`
 * (or `policies/`) applies to `policies/leave.md` and `policies/hr/x.md` but
 * NOT to `policies-old/x.md`. The matching contexts are returned general →
 * specific (shortest prefix first), so the most specific description reads last.
 */
final class PathContextService
{
    public const MAX_DESCRIPTION = 500;

    public function enabled(): bool
    {
        return (bool) config('kb.path_contexts.enabled', false);
    }

    /**
     * Normalise through the repo's one path normaliser (R1). Throws
     * {@see InvalidArgumentException} for an empty prefix or a `.`/`..` segment.
     */
    public function normalizePrefix(string $prefix): string
    {
        return KbPath::normalize(trim($prefix));
    }

    /** @return Collection<int, KbPathContext> */
    public function list(string $tenantId, ?string $projectKey = null): Collection
    {
        return KbPathContext::query()
            ->forTenant($tenantId)
            ->when($projectKey !== null && $projectKey !== '', fn ($q) => $q->where('project_key', $projectKey))
            ->orderBy('project_key')
            ->orderBy('path_prefix')
            ->get();
    }

    public function upsert(string $tenantId, string $projectKey, string $pathPrefix, string $description, ?int $actorId = null): KbPathContext
    {
        $prefix = $this->normalizePrefix($pathPrefix);
        $description = trim($description);

        if ($description === '' || mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new InvalidArgumentException('The description must be 1-'.self::MAX_DESCRIPTION.' characters.');
        }

        // The write and its audit row commit together: a mutation of text that
        // reaches the prompt must never exist without its audit trail.
        $row = DB::transaction(function () use ($tenantId, $projectKey, $prefix, $description, $actorId): KbPathContext {
            $row = KbPathContext::query()->forTenant($tenantId)->updateOrCreate(
                ['tenant_id' => $tenantId, 'project_key' => $projectKey, 'path_prefix' => $prefix],
                ['description' => $description, 'updated_by' => $actorId],
            );

            $this->audit('kb:path-context:upsert', $tenantId, $actorId, [
                'project_key' => $projectKey,
                'path_prefix' => $prefix,
                // The text is admin prose that reaches the prompt: record its
                // identity and size, never the prose itself, in the audit trail.
                'description_sha256' => hash('sha256', $description),
                'description_length' => mb_strlen($description),
            ]);

            return $row;
        });

        return $row;
    }

    public function delete(string $tenantId, int $id, ?int $actorId = null): bool
    {
        $row = KbPathContext::query()->forTenant($tenantId)->find($id);
        if ($row === null) {
            return false;
        }

        DB::transaction(function () use ($row, $tenantId, $actorId): void {
            $row->delete();
            $this->audit('kb:path-context:delete', $tenantId, $actorId, [
                'project_key' => $row->project_key,
                'path_prefix' => $row->path_prefix,
            ]);
        });

        return true;
    }

    /**
     * The descriptions that apply to ONE document path, general → specific.
     *
     * @return list<string>
     */
    public function chainFor(string $tenantId, string $projectKey, string $sourcePath): array
    {
        return $this->chainsFor($tenantId, [['project_key' => $projectKey, 'source_path' => $sourcePath]])[$this->key($projectKey, $sourcePath)] ?? [];
    }

    /**
     * Batch form (one query per distinct project): resolve the chain for many
     * (project, path) pairs, keyed `"{project}|{normalised path}"`. A pair with
     * no matching context is simply absent. Returns `[]` when disabled.
     *
     * @param  list<array{project_key: string, source_path: string}>  $documents
     * @return array<string, list<string>>
     */
    public function chainsFor(string $tenantId, array $documents): array
    {
        if (! $this->enabled() || $documents === []) {
            return [];
        }

        $projects = array_values(array_unique(array_filter(array_column($documents, 'project_key'))));
        if ($projects === []) {
            return [];
        }

        /** @var array<string, list<array{prefix: string, description: string}>> $byProject */
        $byProject = [];
        KbPathContext::query()
            ->forTenant($tenantId)
            ->whereIn('project_key', $projects)
            ->get(['project_key', 'path_prefix', 'description'])
            ->each(function (KbPathContext $c) use (&$byProject): void {
                $byProject[$c->project_key][] = ['prefix' => $c->path_prefix, 'description' => $c->description];
            });

        $out = [];
        foreach ($documents as $doc) {
            $project = (string) ($doc['project_key'] ?? '');
            $path = $this->safePath((string) ($doc['source_path'] ?? ''));
            if ($project === '' || $path === null || ! isset($byProject[$project])) {
                continue;
            }

            $matching = array_filter(
                $byProject[$project],
                static fn (array $c): bool => $path === $c['prefix'] || str_starts_with($path, $c['prefix'].'/'),
            );
            if ($matching === []) {
                continue;
            }

            usort($matching, static fn (array $a, array $b): int => strlen($a['prefix']) <=> strlen($b['prefix']));
            $out[$this->key($project, $path)] = array_values(array_map(static fn (array $c): string => $c['description'], $matching));
        }

        return $out;
    }

    public function key(string $projectKey, string $sourcePath): string
    {
        return $projectKey.'|'.($this->safePath($sourcePath) ?? $sourcePath);
    }

    private function safePath(string $path): ?string
    {
        try {
            return KbPath::normalize($path);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param  array<string,mixed>  $args */
    private function audit(string $command, string $tenantId, ?int $actorId, array $args): void
    {
        AdminCommandAudit::create([
            'tenant_id' => $tenantId,
            'user_id' => $actorId,
            'command' => $command,
            'args_json' => $args,
            'status' => AdminCommandAudit::STATUS_COMPLETED,
            'exit_code' => 0,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
