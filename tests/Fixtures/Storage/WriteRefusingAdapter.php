<?php

declare(strict_types=1);

namespace Tests\Fixtures\Storage;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

/**
 * A Flysystem adapter that reads, lists, deletes and moves like the one it
 * wraps but REFUSES every write whose path matches a predicate — the way a
 * full disk or a lost mount refuses a temp file while the files already
 * there stay readable. Lets a test drive a publish failure on a real local
 * disk without mocking a final class.
 */
final class WriteRefusingAdapter implements FilesystemAdapter
{
    /** @var callable(string): bool */
    private $refuses;

    /**
     * @param  callable(string): bool  $refuses  true for a path whose write must fail
     */
    public function __construct(private readonly FilesystemAdapter $inner, callable $refuses)
    {
        $this->refuses = $refuses;
    }

    public function fileExists(string $path): bool
    {
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
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
