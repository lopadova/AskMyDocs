<?php

namespace App\Jobs;

use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\Kb\SourceType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Ingests a single document of any supported format into the knowledge base.
 *
 * Dispatched by KbIngestFolderCommand (folder walker) and KbIngestController
 * (remote HTTP ingestion). Reads the bytes from the configured Laravel disk
 * — text-decoded for markdown/text, raw binary for PDF/DOCX — and routes
 * through DocumentIngestor::ingest() which is idempotent via a SHA-256
 * version hash so retries and duplicate dispatches never create duplicate
 * chunks.
 *
 * Back-compat: when `$mimeType` is null (legacy callers from before T1.8)
 * defaults to `text/markdown` and the call goes through the same `ingest()`
 * path that `ingestMarkdown()` now wraps.
 */
class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int,int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $projectKey,
        public readonly string $relativePath,
        public readonly string $disk,
        public readonly ?string $title = null,
        public readonly array $metadata = [],
        // T1.8 — optional MIME override. When omitted, defaults to
        // `text/markdown` (legacy back-compat for jobs queued before T1.8).
        public readonly ?string $mimeType = null,
    ) {
        $this->onQueue(config('kb.ingest.queue', 'kb-ingest'));
    }

    public function handle(DocumentIngestor $ingestor): void
    {
        $prefix = (string) config('kb.sources.path_prefix', '');
        $fullPath = ltrim($prefix.'/'.ltrim($this->relativePath, '/'), '/');

        $storage = Storage::disk($this->disk);

        if (! $storage->exists($fullPath)) {
            throw new RuntimeException(
                "IngestDocumentJob: file not found on disk [{$this->disk}]: {$fullPath}"
            );
        }

        // Storage::get() returns the raw bytes regardless of MIME — text
        // and binary formats both flow through the same read; the converter
        // (resolved by PipelineRegistry from the mime type) decides how to
        // interpret them.
        $bytes = (string) $storage->get($fullPath);
        $title = $this->title ?: pathinfo($this->relativePath, PATHINFO_FILENAME);
        $mimeType = $this->mimeType ?? 'text/markdown';

        $metadata = array_merge($this->metadata, [
            'disk' => $this->disk,
            'prefix' => $prefix,
        ]);

        $document = $ingestor->ingest(
            projectKey: $this->projectKey,
            source: new SourceDocument(
                sourcePath: $this->relativePath,
                mimeType: $mimeType,
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: $metadata,
            ),
            title: $title,
        );

        Log::info('IngestDocumentJob completed', [
            'document_id' => $document->id,
            'project_key' => $this->projectKey,
            'source_path' => $this->relativePath,
            'source_type' => SourceType::fromMime($mimeType)->value,
            'disk' => $this->disk,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('IngestDocumentJob failed after retries', [
            'project_key' => $this->projectKey,
            'source_path' => $this->relativePath,
            'disk' => $this->disk,
            'error' => $exception->getMessage(),
        ]);
    }
}
