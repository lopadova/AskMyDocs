<?php

declare(strict_types=1);

namespace App\Support\Kb;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Lazily yield every file path under a directory (deep), one at a time —
 * Flysystem's directory listing is a generator, unlike
 * `FilesystemAdapter::allFiles()`, which materialises (and sorts) the whole
 * tree before the first path is judged (R3). Shared by the artifact sweeps
 * and the orphan scan so both walk a disk the same way.
 *
 * Throws what the adapter throws: the local adapter refuses to walk through
 * a symbolic link (`SymbolicLinkEncountered`) — a caller reports that as a
 * refused walk, never as a clean empty listing (R14).
 */
final class LazyDiskListing
{
    /**
     * @return \Generator<int, string>
     */
    public static function files(FilesystemAdapter $storage, string $root): \Generator
    {
        /** @var \League\Flysystem\FilesystemOperator $driver */
        $driver = $storage->getDriver();
        foreach ($driver->listContents($root, true) as $attributes) {
            if ($attributes->isFile()) {
                yield $attributes->path();
            }
        }
    }
}
