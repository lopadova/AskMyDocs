<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use App\Support\KbPath;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes the figures an OCR run extracted next to the source document on
 * the kb disk (ADR 0029 §4):
 *
 *   {prefix}/{dir of source_path}/{basename}.ocr/{run}/images/fig-{page}-{n}.png
 *
 * `{run}` is content-addressed — the first 16 hex chars of the SHA-256 of
 * the bytes that were OCR'd — so two versions of the same source path never
 * overwrite each other's pixels (a re-run on identical bytes lands on the
 * same directory, which is exactly the idempotency the ingest has), and a
 * W2 artifact can point at the run that produced it. Tenant separation is
 * the source file's own: the assets live beside the file whose namespace
 * (disk + prefix + path) they inherit, so a deployment that isolates tenants
 * by disk/prefix isolates the figures with them.
 *
 * The Markdown references the files as `![Figure p.n](images/fig-p-n.png)`,
 * relative to the run directory — the shape the W4 export copies verbatim.
 * Every write checks its return value (R4).
 */
final class OcrFigureStore
{
    public const DIR_SUFFIX = '.ocr';

    /**
     * Disk-relative directory that holds a document's OCR assets.
     */
    public function assetsDirFor(string $sourcePath, string $prefix = ''): string
    {
        $normalized = KbPath::normalize($sourcePath);
        $dir = ltrim(trim($prefix, '/').'/'.ltrim($normalized, '/'), '/');

        return $dir.self::DIR_SUFFIX;
    }

    /**
     * Content-addressed run key over the bytes an OCR pass consumed AND the
     * engine that consumed them (driver name + fingerprint): the same bytes
     * through another driver or model land in another, immutable run.
     */
    public static function runKeyFor(string $bytes, string $driver = '', string $fingerprint = ''): string
    {
        return substr(hash('sha256', $bytes."\0".$driver."\0".$fingerprint), 0, 16);
    }

    /**
     * Disk-relative directory of one OCR run's assets (`{assets dir}/{run}`).
     */
    public function runDirFor(string $sourcePath, string $prefix, string $runKey): string
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $runKey)) {
            throw new RuntimeException('OcrFigureStore: run key must be 16 lowercase hex chars.');
        }

        return $this->assetsDirFor($sourcePath, $prefix).'/'.$runKey;
    }

    /**
     * @param  list<OcrFigure>  $figures
     * @return list<array{path: string, page: int, index: int, bytes: int, relative: string}>
     */
    public function store(string $disk, string $sourcePath, string $prefix, string $runKey, array $figures): array
    {
        if ($figures === []) {
            return [];
        }

        $storage = Storage::disk($disk);
        $base = $this->runDirFor($sourcePath, $prefix, $runKey).'/images';
        $written = [];

        foreach ($figures as $figure) {
            $path = $base.'/'.$figure->fileName();
            if ($storage->put($path, $figure->bytes) === false) {
                throw new RuntimeException("OcrFigureStore: failed to write figure {$path} on disk [{$disk}].");
            }
            $written[] = [
                'path' => $path,
                'relative' => 'images/'.$figure->fileName(),
                'page' => $figure->page,
                'index' => $figure->index,
                'bytes' => strlen($figure->bytes),
            ];
        }

        return $written;
    }

    /**
     * The recorded result of one run — `{run dir}/result.json`. Written after
     * the figures, so a present result implies present figures; read by
     * OcrService::convert() to skip the driver (and the spend) when the same
     * bytes arrive again with the same driver.
     */
    public function resultPath(string $sourcePath, string $prefix, string $runKey): string
    {
        return $this->runDirFor($sourcePath, $prefix, $runKey).'/result.json';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function storeResult(string $disk, string $sourcePath, string $prefix, string $runKey, array $result): void
    {
        $path = $this->resultPath($sourcePath, $prefix, $runKey);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || Storage::disk($disk)->put($path, $json) === false) {
            throw new RuntimeException("OcrFigureStore: failed to write OCR result {$path} on disk [{$disk}].");
        }
    }

    /**
     * @return array<string, mixed>|null null when absent or unreadable
     */
    public function loadResult(string $disk, string $sourcePath, string $prefix, string $runKey): ?array
    {
        $storage = Storage::disk($disk);
        $path = $this->resultPath($sourcePath, $prefix, $runKey);
        if (! $storage->exists($path)) {
            return null;
        }
        $decoded = json_decode((string) $storage->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Remove every OCR asset of a document (used by hard delete / prune).
     * Returns true when a directory existed and was removed.
     */
    public function purge(string $disk, string $sourcePath, string $prefix = ''): bool
    {
        return $this->purgeAt($disk, $this->assetsDirFor($sourcePath, $prefix));
    }

    /**
     * Remove the assets directory that sits beside an already-resolved
     * source key (`{fullPath}.ocr`) — the deleter's entry point, which has
     * the resolved key in hand and not the prefix.
     */
    public function purgeBeside(string $disk, string $fullPath): bool
    {
        return $this->purgeAt($disk, $fullPath.self::DIR_SUFFIX);
    }

    private function purgeAt(string $disk, string $dir): bool
    {
        $storage = Storage::disk($disk);
        if (! $storage->directoryExists($dir)) {
            return false;
        }

        return (bool) $storage->deleteDirectory($dir);
    }
}
