<?php

declare(strict_types=1);

namespace App\Flow\Compensators;

use App\Flow\Steps\StepTenantBinder;
use App\Support\KbPath;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Compensator for the `write-markdown` step of {@see \App\Flow\Definitions\PromotionFlow}.
 *
 * Triggered when the post-write step (`dispatch-ingest`) fails. Removes
 * the canonical markdown file from disk so the operator can retry the
 * promotion without leaving an orphan file the next ingest run might
 * pick up out-of-band.
 *
 * R4 + R14 — never silently swallow a removal failure. If `Storage::delete`
 * returns false on an existing file we throw so the engine marks the
 * compensation as failed and the operator is alerted.
 *
 * Idempotent: if the file is already gone (operator manually cleaned up,
 * race with a previous compensation pass) we return cleanly.
 */
final class DeleteCanonicalMarkdownCompensator implements FlowCompensator
{
    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        StepTenantBinder::bindFromContext($context);

        $output = $stepResult->output;
        $relativePath = (string) ($output['relative_path'] ?? '');
        $disk = (string) ($output['disk'] ?? config('kb.sources.disk', 'kb'));

        if ($relativePath === '') {
            // Nothing to roll back — the write step failed before producing
            // a path, or wasn't reached.
            return;
        }

        $fullPath = $this->applyPathPrefix($relativePath);
        $storage = Storage::disk($disk);

        if (! $storage->exists($fullPath)) {
            // Already cleaned up by an out-of-band actor.
            return;
        }

        $deleted = $storage->delete($fullPath);
        if ($deleted === false) {
            throw new RuntimeException(
                "DeleteCanonicalMarkdownCompensator: failed to remove canonical markdown from disk [{$disk}]: {$fullPath}"
            );
        }
    }

    private function applyPathPrefix(string $relativePath): string
    {
        $prefix = (string) config('kb.sources.path_prefix', '');
        if ($prefix === '') {
            return KbPath::normalize($relativePath);
        }
        return KbPath::normalize($prefix.'/'.$relativePath);
    }
}
