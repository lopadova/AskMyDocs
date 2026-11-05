<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KbWikiExportRequest;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Export\KbWikiExportService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * v8.38/W4b (ADR 0032 §5) — executes one async wiki export.
 *
 * Constructor takes only scalar ids — never a serialized `User`/`Auth`
 * object — and reloads everything from the DB inside {@see handle()},
 * mirroring {@see ExecuteAgentRunJob}'s exact principal-restoration
 * discipline: `Auth::forgetGuards()` before AND after (in `finally`), so a
 * reused queue worker never leaks this export's principal into the next
 * job. Unlike `ExecuteAgentRunJob`, this job ALSO re-checks the requesting
 * user still holds the export permission before running — the permission
 * could have been revoked in the time between the HTTP request and the
 * worker picking up the job, and that is not a retryable condition, so a
 * failed re-check marks the request `failed` rather than throwing.
 */
final class ExecuteKbWikiExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Mirrors KbWikiExportService::LOCK_FILENAME (private there) — the
    // reservation marker has no value once the export is complete and
    // zipped, so it is excluded rather than shipped to whoever downloads
    // the bundle.
    private const RESERVATION_LOCK_FILENAME = '.export.lock';

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly string $exportRequestId,
        public readonly int $userId,
        public readonly string $tenantId,
    ) {
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(KbWikiExportService $exportService, TenantContext $tenants): void
    {
        $request = KbWikiExportRequest::query()
            ->forTenant($this->tenantId)
            ->find($this->exportRequestId);
        if (! $request instanceof KbWikiExportRequest) {
            // The row is gone (race with a manual delete, or the retention
            // sweep beat a very slow queue to it) — nothing left to do.
            return;
        }

        $user = User::query()->find($this->userId);
        if (! $user instanceof User || ! $user->hasAnyRole(['admin', 'super-admin'])) {
            $this->fail($request, 'requesting user no longer holds export permission');

            return;
        }

        $tempDir = sys_get_temp_dir().'/kb-wiki-export-'.Str::uuid()->toString();
        $zipPath = null;

        try {
            $tenants->set($this->tenantId);
            Auth::forgetGuards();
            Auth::setUser($user);

            $request->forceFill(['status' => KbWikiExportRequest::STATUS_PROCESSING])->save();

            $visibleIds = KnowledgeDocument::query()
                ->forTenant($this->tenantId)
                ->where('project_key', $request->project_key)
                ->where('status', 'active')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $result = $exportService->export($this->tenantId, $request->project_key, $user, $tempDir);

            $zipPath = $this->zipDirectory($tempDir);
            $disk = (string) config('kb.staging.disk', 'kb-staging');
            $relativePath = "wiki-exports/{$this->tenantId}/{$request->id}.zip";
            $bytes = file_get_contents($zipPath);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to read the built export zip.');
            }
            if (! Storage::disk($disk)->put($relativePath, $bytes)) {
                throw new \RuntimeException("Failed to stage the export zip on disk '{$disk}'.");
            }

            $retentionHours = (int) config('kb.wiki_export.retention_hours', 24);

            $request->forceFill([
                'status' => KbWikiExportRequest::STATUS_COMPLETED,
                'document_ids_json' => $visibleIds,
                'storage_disk' => $disk,
                'storage_path' => $relativePath,
                'document_count' => $result['document_count'],
                'partial' => $result['status'] === 'partial',
                'expires_at' => $retentionHours > 0 ? now()->addHours($retentionHours) : null,
                'completed_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            Log::error('kb_wiki_export.job_failed', [
                'export_request_id' => $request->id,
                'tenant_id' => $this->tenantId,
                'exception' => $exception::class,
            ]);
            $this->fail($request, $exception::class.': export failed — see application log');

            throw $exception;
        } finally {
            Auth::forgetGuards();
            $tenants->reset();
            $this->cleanup($tempDir, $zipPath);
        }
    }

    private function fail(KbWikiExportRequest $request, string $reason): void
    {
        $request->forceFill([
            'status' => KbWikiExportRequest::STATUS_FAILED,
            'error_message' => $reason,
            'completed_at' => now(),
        ])->save();
    }

    private function zipDirectory(string $dir): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'kb-wiki-export-').'.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Failed to open zip archive: {$zipPath}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->getFilename() === self::RESERVATION_LOCK_FILENAME) {
                continue;
            }
            $relativePath = substr((string) $item->getPathname(), strlen($dir) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);

                continue;
            }
            $zip->addFile($item->getPathname(), $relativePath);
        }

        $zip->close();

        return $zipPath;
    }

    private function cleanup(string $tempDir, ?string $zipPath): void
    {
        if ($zipPath !== null && is_file($zipPath)) {
            unlink($zipPath);
        }
        if (! is_dir($tempDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
        }
        rmdir($tempDir);
    }
}
