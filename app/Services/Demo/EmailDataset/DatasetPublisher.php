<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use RuntimeException;

final class DatasetPublisher
{
    public function createTemporaryDirectory(string $outputDirectory, string $datasetVersion): string
    {
        $this->assertSafeDatasetVersion($datasetVersion);
        $this->makeDirectory($outputDirectory);

        $suffix = getmypid().'-'.bin2hex(random_bytes(6));
        $temporary = rtrim($outputDirectory, DIRECTORY_SEPARATOR)
            .'/.tmp-'.$datasetVersion.'-'.$suffix;
        if (! mkdir($temporary, 0755, true)) {
            throw new RuntimeException("Unable to create temporary dataset directory {$temporary}.");
        }

        return $temporary;
    }

    public function destination(string $outputDirectory, string $datasetVersion): string
    {
        $this->assertSafeDatasetVersion($datasetVersion);

        return rtrim($outputDirectory, DIRECTORY_SEPARATOR).'/'.$datasetVersion;
    }

    public function assertCanGenerate(string $destination, bool $force, bool $check): void
    {
        if (is_link($destination)) {
            throw new RuntimeException(
                "Refusing to use a symbolic link as dataset destination: {$destination}",
            );
        }

        if (file_exists($destination) && ! is_dir($destination)) {
            throw new RuntimeException("Dataset destination exists and is not a directory: {$destination}");
        }

        if (is_dir($destination) && ! $force && ! $check) {
            throw new RuntimeException("Dataset already exists: {$destination}. Use --force to replace it.");
        }

        if ($check && ! is_dir($destination)) {
            throw new RuntimeException("Cannot check dataset drift because destination does not exist: {$destination}");
        }
    }

    public function publish(string $temporary, string $destination, bool $force): void
    {
        $this->assertPublishPaths($temporary, $destination);

        if (is_dir($destination)) {
            if (! $force) {
                throw new RuntimeException("Dataset already exists: {$destination}.");
            }

            if ($this->fileHashes($destination) !== $this->fileHashes($temporary)) {
                $this->deleteDirectory($temporary);

                throw new RuntimeException(
                    'Refusing to replace an immutable dataset version with different bytes. '
                    .'Bump the catalog or generator revision.',
                );
            }

            $this->deleteDirectory($temporary);

            return;
        }

        if (! rename($temporary, $destination)) {
            throw new RuntimeException("Unable to publish generated dataset to {$destination}.");
        }
    }

    public function assertIdenticalAndDiscard(string $temporary, string $destination): void
    {
        $expected = $this->fileHashes($destination);
        $actual = $this->fileHashes($temporary);
        $this->deleteDirectory($temporary);

        if ($expected !== $actual) {
            throw new RuntimeException('Generated dataset differs from the published dataset.');
        }
    }

    public function discard(string $directory): void
    {
        if (is_dir($directory)) {
            $this->deleteDirectory($directory);
        }
    }

    /**
     * @return array<string, string>
     */
    private function fileHashes(string $directory): array
    {
        $base = realpath($directory);
        if ($base === false) {
            throw new RuntimeException("Dataset directory is unavailable: {$directory}");
        }

        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($base) + 1);
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new RuntimeException("Unable to checksum dataset file {$file->getPathname()}.");
            }
            $hashes[$relative] = $hash;
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    private function deleteDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new RuntimeException("Refusing to remove symbolic-link dataset directory {$directory}.");
        }

        $real = realpath($directory);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("Refusing to remove invalid dataset directory {$directory}.");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $ok = $entry->isLink() || ! $entry->isDir()
                ? unlink($entry->getPathname())
                : rmdir($entry->getPathname());
            if (! $ok) {
                throw new RuntimeException("Unable to remove temporary dataset entry {$entry->getPathname()}.");
            }
        }

        if (! rmdir($real)) {
            throw new RuntimeException("Unable to remove temporary dataset directory {$real}.");
        }
    }

    private function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create dataset output directory {$path}.");
        }
    }

    private function assertSafeDatasetVersion(string $datasetVersion): void
    {
        if (! preg_match('/^[a-z0-9-]+$/', $datasetVersion)) {
            throw new RuntimeException("Unsafe dataset version: {$datasetVersion}");
        }
    }

    private function assertPublishPaths(string $temporary, string $destination): void
    {
        if (is_link($temporary) || is_link($destination)) {
            throw new RuntimeException('Dataset publish paths cannot be symbolic links.');
        }

        $temporaryReal = realpath($temporary);
        $temporaryParent = realpath(dirname($temporary));
        $destinationParent = realpath(dirname($destination));
        if ($temporaryReal === false
            || ! is_dir($temporaryReal)
            || $temporaryParent === false
            || $destinationParent === false
            || ! hash_equals($temporaryParent, $destinationParent)) {
            throw new RuntimeException(
                'Dataset temporary and destination directories must share the same resolved output root.',
            );
        }

        if (! str_starts_with(basename($temporary), '.tmp-')) {
            throw new RuntimeException('Refusing to publish an unrecognised temporary dataset directory.');
        }
    }
}
