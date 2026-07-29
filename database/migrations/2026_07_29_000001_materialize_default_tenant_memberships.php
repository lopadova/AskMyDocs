<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materialise the legacy implicit access to tenant `default`.
 *
 * Before v8.30 every authenticated identity could operate in `default`
 * without a membership. The new authorization boundary accepts only explicit
 * project_memberships, so this one-time compatibility migration records the
 * same access as data before the implicit fallback disappears.
 *
 * insertOrIgnore + the tenant-scoped unique make the migration idempotent.
 * A single immutable audit record captures how many memberships were added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('project_memberships')) {
            return;
        }

        $projectKeys = $this->defaultProjectKeys();
        $inserted = 0;
        $now = now();

        DB::table('users')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($projectKeys, $now, &$inserted): void {
                foreach ($users as $user) {
                    foreach ($projectKeys as $projectKey) {
                        $inserted += DB::table('project_memberships')->insertOrIgnore([
                            'tenant_id' => 'default',
                            'user_id' => $user->id,
                            'project_key' => $projectKey,
                            'role' => 'member',
                            'scope_allowlist' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });

        $this->audit($inserted, count($projectKeys), $now);
    }

    public function down(): void
    {
        // Intentionally non-destructive. Once materialised, memberships are
        // indistinguishable from explicit grants and must not be revoked by a
        // rollback. Operators can remove them through the normal access flow.
    }

    /**
     * @return list<string>
     */
    private function defaultProjectKeys(): array
    {
        $keys = [];

        if (Schema::hasTable('projects')) {
            $keys = array_merge(
                $keys,
                DB::table('projects')
                    ->where('tenant_id', 'default')
                    ->whereNotNull('project_key')
                    ->distinct()
                    ->pluck('project_key')
                    ->all(),
            );
        }

        if (Schema::hasTable('knowledge_documents')) {
            $keys = array_merge(
                $keys,
                DB::table('knowledge_documents')
                    ->where('tenant_id', 'default')
                    ->whereNotNull('project_key')
                    ->distinct()
                    ->pluck('project_key')
                    ->all(),
            );
        }

        $keys = array_values(array_unique(array_filter(
            $keys,
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        )));

        // Membership is the tenant-level proof. Preserve the former default
        // access even on an empty installation with no project registry yet.
        return $keys === [] ? ['default'] : $keys;
    }

    private function audit(int $inserted, int $projectCount, mixed $now): void
    {
        if ($inserted === 0 || ! Schema::hasTable('admin_command_audit')) {
            return;
        }

        $columns = [
            'user_id' => null,
            'command' => 'tenant:materialize-default-memberships',
            'args_json' => json_encode([
                'tenant_id' => 'default',
                'inserted_memberships' => $inserted,
                'project_count' => $projectCount,
                'reason' => 'legacy_implicit_default_access',
            ], JSON_THROW_ON_ERROR),
            'status' => 'completed',
            'exit_code' => 0,
            'stdout_head' => "Materialized {$inserted} legacy default-tenant memberships.",
            'error_message' => null,
            'started_at' => $now,
            'completed_at' => $now,
            'client_ip' => null,
            'user_agent' => 'database-migration',
        ];

        if (Schema::hasColumn('admin_command_audit', 'tenant_id')) {
            $columns['tenant_id'] = 'default';
        }

        DB::table('admin_command_audit')->insert($columns);
    }
};
