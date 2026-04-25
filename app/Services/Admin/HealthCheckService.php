<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Liveness / readiness-style checks for the admin dashboard.
 *
 * One method per concern. Each returns a status string in
 * `ok | degraded | down`. No network calls against AI providers — the
 * embedding/chat provider checks only verify that config is populated
 * so the dashboard never stalls waiting on a third-party endpoint.
 */
class HealthCheckService
{
    /**
     * @return array{
     *   db_ok: string,
     *   pgvector_ok: string,
     *   queue_ok: string,
     *   kb_disk_ok: string,
     *   embedding_provider_ok: string,
     *   chat_provider_ok: string,
     *   checked_at: string
     * }
     */
    public function run(): array
    {
        return [
            'db_ok' => $this->dbOk(),
            'pgvector_ok' => $this->pgvectorOk(),
            'queue_ok' => $this->queueOk(),
            'kb_disk_ok' => $this->kbDiskOk(),
            'embedding_provider_ok' => $this->embeddingProviderOk(),
            'chat_provider_ok' => $this->chatProviderOk(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function dbOk(): string
    {
        try {
            DB::select('SELECT 1 as ok');

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    /**
     * On Postgres, verify the `vector` extension is registered. On any
     * other driver (SQLite in tests, MySQL, etc.) we have no vector support
     * to verify — report `ok` because the rest of the stack runs without it.
     */
    public function pgvectorOk(): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return 'ok';
        }

        try {
            $row = DB::selectOne("SELECT 1 as ok FROM pg_extension WHERE extname = 'vector' LIMIT 1");

            return $row ? 'ok' : 'degraded';
        } catch (Throwable) {
            return 'down';
        }
    }

    /**
     * Queue is `degraded` once failed_jobs breaches the soft threshold; it
     * only escalates to `down` on an unreadable DB, which already shows up
     * in `db_ok`.
     */
    public function queueOk(): string
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 'ok';
            }

            $failed = (int) DB::table('failed_jobs')->count();

            return $failed >= 10 ? 'degraded' : 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    /**
     * KB disk health probe. Copilot #2 fix: only touch the disk when
     * it's a LOCAL driver. Remote drivers (`s3`, `gcs`, `r2`, `minio`
     * via aws-style S3) would otherwise issue a network request on
     * every dashboard poll (15s), which contradicts the "no network
     * calls" guarantee in the service docblock and racks up egress
     * cost for no real benefit — a misconfigured remote disk would
     * surface on the first ingest anyway.
     *
     * For remote drivers we validate the config block instead: if the
     * disk is declared in `config/filesystems.php`, report `ok`;
     * otherwise `degraded`. Same shape as `providerConfigured()`.
     */
    public function kbDiskOk(): string
    {
        try {
            $disk = (string) config('kb.sources.disk', 'kb');
            $driver = (string) config("filesystems.disks.{$disk}.driver", '');

            if ($driver === '') {
                return 'degraded';
            }

            if ($driver !== 'local') {
                // Remote driver — don't hit the network. If the disk is
                // declared with a driver, accept the config as healthy.
                return 'ok';
            }

            return Storage::disk($disk)->exists('.') ? 'ok' : 'degraded';
        } catch (Throwable) {
            return 'down';
        }
    }

    public function embeddingProviderOk(): string
    {
        return $this->providerConfigured('ai.embeddings_provider', 'ai.providers');
    }

    public function chatProviderOk(): string
    {
        // config/ai.php uses key `default` (not `default_provider`) — the
        // earlier name was a doc-drift artefact. R9: keep code matching
        // the canonical config keys to avoid silent 'degraded' status
        // on a configured provider.
        return $this->providerConfigured('ai.default', 'ai.providers');
    }

    /**
     * Returns `ok` when the named provider slot is populated with a config
     * block. We do not hit the network: a dashboard call should never
     * trigger a paid API round-trip, and credentials-validity is already
     * surfaced by the first chat of the day.
     */
    private function providerConfigured(string $defaultKey, string $providersKey): string
    {
        $provider = config($defaultKey);
        if ($provider === null || $provider === '') {
            return 'degraded';
        }

        $providers = (array) config($providersKey, []);
        if (! array_key_exists($provider, $providers)) {
            return 'degraded';
        }

        return 'ok';
    }
}
