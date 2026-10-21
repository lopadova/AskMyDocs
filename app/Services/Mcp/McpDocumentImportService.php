<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Models\McpDocumentImport;
use App\Models\McpProjectConnection;
use App\Models\OAuthAccessToken;
use App\Services\Kb\Upload\KbUploadStagingService;
use App\Support\Kb\FileTypeSniffer;
use App\Support\Kb\SourceType;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Server-owned preview and replay-safe commit over the existing ingestion pipeline. */
final class McpDocumentImportService
{
    public function __construct(private ConnectedProjectWorkspace $workspace, private KbUploadStagingService $uploads) {}

    public function prepare(array $arguments): array
    {
        $connection = $this->workspace->connection();
        $grant = request()->attributes->get('mcp.grant');
        $user = $this->workspace->authorize($connection, $grant, true);
        $data = Validator::make($arguments, [
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*' => ['required', 'array:filename,content,encoding'],
            'documents.*.filename' => ['required', 'string', 'max:180'],
            'documents.*.content' => ['required', 'string', 'max:7000000'],
            'documents.*.encoding' => ['sometimes', 'in:text,base64'],
        ])->validate();
        $files = $paths = $manifest = $seenNames = [];
        $total = 0;
        try {
            foreach ($data['documents'] as $doc) {
                $name = $doc['filename'];
                if (KbPath::normalize($name) !== $name || basename($name) !== $name
                    || str_contains($name, '\\') || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                    throw ValidationException::withMessages(['documents' => 'Use a filename without directories or control characters.']);
                }
                if (isset($seenNames[strtolower($name)])) {
                    throw ValidationException::withMessages(['documents' => 'Each filename must be unique.']);
                }
                $seenNames[strtolower($name)] = true;
                $type = SourceType::fromExtension(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($type, [SourceType::MARKDOWN, SourceType::TEXT, SourceType::PDF, SourceType::DOCX], true)) {
                    throw ValidationException::withMessages(['documents' => 'Supported files: Markdown, text, PDF and DOCX.']);
                }
                $encoding = $doc['encoding'] ?? 'text';
                $bytes = $encoding === 'base64' ? base64_decode($doc['content'], true) : $doc['content'];
                if ($bytes === false || $bytes === '' || ($type->isBinary() && $encoding !== 'base64')) {
                    throw ValidationException::withMessages(['documents' => 'Binary documents require valid base64 content.']);
                }
                $total += strlen($bytes);
                if ($total > 10000000 || strlen($bytes) > 5000000) {
                    throw ValidationException::withMessages(['documents' => 'Limit: 5 MB per file and 10 MB per import.']);
                }
                $path = tempnam(sys_get_temp_dir(), 'askmd-mcp-');
                if ($path === false) {
                    throw new \RuntimeException('Unable to stage import.');
                }
                $paths[] = $path;
                if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('Unable to stage import.');
                }
                if (FileTypeSniffer::mismatchReason($path, $type) !== null) {
                    throw ValidationException::withMessages(['documents' => 'File content does not match its extension.']);
                }
                $files[] = new UploadedFile($path, $name, $type->toMime(), null, true);
                $manifest[$name] = ['filename' => $name, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                    'preview' => $type->isBinary() ? null : mb_substr($bytes, 0, 600)];
            }
            ksort($manifest);
            $fingerprint = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
            $import = Cache::lock('mcp-import-'.$connection->id, 120)->block(10, fn () => DB::transaction(function () use ($connection, $grant, $user, $files, $manifest, $fingerprint): McpDocumentImport {
                // All retries for this connection serialize before reserving or staging.
                McpProjectConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                $existing = McpDocumentImport::where('mcp_connection_id', $connection->id)->where('fingerprint', $fingerprint)->first();
                if ($existing) {
                    $this->workspace->audit('mcp.import.replay', 'completed', ['import_id' => $existing->id]);
                    return $existing;
                }
                $this->workspace->audit('mcp.import.prepare', 'started', ['files' => count($files), 'fingerprint' => $fingerprint]);
                $batch = $this->uploads->stage($connection->project_key,
                    'mcp/'.hash('sha256', $connection->tenant_id.'/'.$connection->project_key).'/'.$fingerprint,
                    $files, $user->id);
                return McpDocumentImport::create(['mcp_connection_id' => $connection->id,
                    'oauth_access_token_id' => $grant->id, 'user_id' => $user->id,
                    'tenant_id' => $connection->tenant_id, 'project_key' => $connection->project_key,
                    'fingerprint' => $fingerprint, 'batch_id' => $batch->id,
                    'manifest' => array_values($manifest), 'expires_at' => now()->addHour()]);
            }));
            return $this->present($import);
        } finally {
            foreach ($paths as $path) {
                if (is_file($path) && ! unlink($path)) {
                    report(new \RuntimeException('Unable to remove MCP temporary upload.'));
                }
            }
        }
    }

    public function status(string $id): array
    {
        $connection = $this->workspace->connection();
        $import = McpDocumentImport::where('mcp_connection_id', $connection->id)->where('user_id', Auth::id())->findOrFail($id);
        return $this->present($import);
    }

    /** Browser and queued jobs both reload the immutable initiating grant. */
    public function authorizeImport(McpDocumentImport $import): void
    {
        $connection = McpProjectConnection::findOrFail($import->mcp_connection_id);
        $grant = OAuthAccessToken::findOrFail($import->oauth_access_token_id);
        abort_unless($import->tenant_id === $connection->tenant_id && $import->project_key === $connection->project_key
            && (int) $import->user_id === (int) $grant->user_id, 403);
        $this->workspace->authorize($connection, $grant, true);
    }

    public function review(string $id): array
    {
        $import = McpDocumentImport::where('user_id', Auth::id())->findOrFail($id);
        return $this->inTenant($import, function () use ($import): array {
            $this->authorizeImport($import);
            return $this->present($import);
        });
    }

    public function confirm(string $id): array
    {
        $import = McpDocumentImport::where('user_id', Auth::id())->findOrFail($id);
        return $this->inTenant($import, function () use ($import): array {
            return DB::transaction(function () use ($import): array {
                $import = McpDocumentImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                $this->authorizeImport($import);
                if ($import->approved_at !== null) {
                    $this->workspace->audit('mcp.import.confirm.replay', 'completed', ['import_id' => $import->id]);
                    return $this->present($import);
                }
                abort_if($import->expires_at->lte(now()), 409, 'This import approval expired.');
                $batch = KbIngestBatch::forTenant($import->tenant_id)->findOrFail($import->batch_id);
                abort_unless($batch->status === KbIngestBatch::STATUS_STAGED
                    && ! $batch->items()->where('status', '!=', KbIngestBatchItem::STATUS_STAGED)->exists(), 409);
                // The browser approves server-recorded bytes, not a mutable file name.
                $manifest = collect($import->manifest)->keyBy('filename');
                foreach ($batch->items as $item) {
                    $stream = Storage::disk($this->uploads->stagingDiskName())->readStream($item->staging_path);
                    abort_unless(is_resource($stream), 409, 'A staged document is unavailable.');
                    try {
                        $hash = hash_init('sha256');
                        hash_update_stream($hash, $stream);
                        abort_unless(hash_equals($manifest[$item->original_filename]['sha256'], hash_final($hash)), 409, 'The staged documents changed.');
                    } finally { fclose($stream); }
                }
                $this->workspace->audit('mcp.import.confirm', 'completed', ['import_id' => $import->id, 'fingerprint' => $import->fingerprint]);
                $import->update(['approved_at' => now()]);
                $this->uploads->commit($batch, $batch->items()->pluck('id')->all(),
                    ['mcp_document_import_id' => $import->id, 'version_actor' => 'user:'.$import->user_id]);
                return $this->present($import);
            });
        });
    }

    public function present(McpDocumentImport $import): array
    {
        $batch = KbIngestBatch::forTenant($import->tenant_id)->with('items')->findOrFail($import->batch_id);
        $ready = $batch->items->isNotEmpty() && $batch->items->every(fn ($item) => $item->status === KbIngestBatchItem::STATUS_SUCCEEDED);
        $status = $import->approved_at === null ? ($import->expires_at->lte(now()) ? 'expired' : 'awaiting_approval') : $batch->status;
        return ['import_id' => $import->id, 'tenant_id' => $import->tenant_id, 'project_key' => $import->project_key,
            'status' => $ready ? 'ready' : $status, 'ready' => $ready,
            'approval_url' => rtrim((string) config('app.url'), '/').'/mcp/import-review/'.$import->id,
            'expires_at' => $import->expires_at->toIso8601String(), 'documents' => $import->manifest,
            'items' => $batch->items->map(fn ($item) => ['filename' => $item->original_filename,
                'status' => $item->status, 'document_id' => $item->knowledge_document_id,
                'error' => $item->error !== null ? 'Document processing failed. Check the import in AskMyDocs.' : null])->all()];
    }

    private function inTenant(McpDocumentImport $import, callable $callback): mixed
    {
        $tenant = app(TenantContext::class);
        $previous = $tenant->current();
        try {
            $tenant->set($import->tenant_id);
            return $callback();
        } finally {
            $tenant->set($previous);
        }
    }
}
