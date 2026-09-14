<?php

declare(strict_types=1);

namespace Tests\Fixtures\Storage;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

/**
 * A Flysystem adapter that reads, lists and deletes like the one it wraps
 * but REFUSES every write, move or copy whose DESTINATION matches a
 * predicate — the way a
 * full disk or a lost mount refuses a temp file while the files already
 * there stay readable. Lets a test drive a publish failure on a real local
 * disk without mocking a final class. An optional second predicate makes
 * the adapter REFUSE THE PROBE of a path (`fileExists()` / `read()` throw)
 * — a mount that is gone, a bucket that answers 5xx — so a command can be
 * shown to report the disk, not the row. Only those two operations are
 * refused: `readStream()`, `fileSize()`, `lastModified()` and
 * `listContents()` still answer, so a test that needs a whole mount gone
 * must refuse the walk itself.
 */
final class WriteRefusingAdapter implements FilesystemAdapter
{
    /** @var callable(string): bool */
    private $refuses;

    /** @var callable(string, string): bool */
    private $refusesProbe;

    /**
     * @param  callable(string): bool  $refuses  true for a path whose write must fail
     * @param  (callable(string, string): bool)|null  $refusesProbe  true for a path whose existence check / read must throw; receives the path and the operation (`fileExists` | `read`) so a test can refuse the second read of a path but not the first
     */
    public function __construct(private readonly FilesystemAdapter $inner, callable $refuses, ?callable $refusesProbe = null)
    {
        $this->refuses = $refuses;
        $this->refusesProbe = $refusesProbe ?? static fn (string $path, string $operation): bool => false;
    }

    public function fileExists(string $path): bool
    {
        if (($this->refusesProbe)($path, 'fileExists')) {
            throw \League\Flysystem\UnableToCheckFileExistence::forLocation($path, new \RuntimeException('probe refused by the test adapter'));
        }

        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        if (($this->refuses)($path)) {
            throw UnableToWriteFile::atLocation($path, 'write refused by the test adapter');
        }
        $this->inner->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (($this->refuses)($path)) {
            throw UnableToWriteFile::atLocation($path, 'write refused by the test adapter');
        }
        $this->inner->writeStream($path, $contents, $config);
    }

    public function read(string $path): string
    {
        if (($this->refusesProbe)($path, 'read')) {
            throw \League\Flysystem\UnableToReadFile::fromLocation($path, 'read refused by the test adapter');
        }

        return $this->inner->read($path);
    }

    public function readStream(string $path)
    {
        return $this->inner->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->inner->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if (($this->refuses)($destination)) {
            throw \League\Flysystem\UnableToMoveFile::because('write refused by the test adapter', $source, $destination);
        }
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if (($this->refuses)($destination)) {
            throw \League\Flysystem\UnableToCopyFile::because('write refused by the test adapter', $source, $destination);
        }
        $this->inner->copy($source, $destination, $config);
    }
}
