<?php

namespace App\Jobs;

use App\Services\Kb\DocumentIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Ingests a single markdown document into the knowledge base.
 *
 * Dispatched by KbIngestFolderCommand (folder walker) and KbIngestController
 * (remote HTTP ingestion). Reads the markdown from the configured Laravel
 * disk, then delegates to DocumentIngestor::ingestMarkdown which is
 * idempotent via a SHA-256 version hash — retries and duplicate dispatches
 * never create duplicate chunks.
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

        $markdown = (string) $storage->get($fullPath);
        $title = $this->title ?: pathinfo($this->relativePath, PATHINFO_FILENAME);

        $metadata = array_merge($this->metadata, [
            'disk' => $this->disk,
            'prefix' => $prefix,
        ]);

        $document = $ingestor->ingestMarkdown(
            projectKey: $this->projectKey,
            sourcePath: $this->relativePath,
            title: $title,
            markdown: $markdown,
            metadata: $metadata,
        );

        Log::info('IngestDocumentJob completed', [
            'document_id' => $document->id,
            'project_key' => $this->projectKey,
            'source_path' => $this->relativePath,
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
