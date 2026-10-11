<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use Illuminate\Support\Facades\Storage;
use App\Support\KbPath;
use Symfony\Component\Process\Process;

/** A bounded local preview, deliberately independent of the paid OCR pipeline. */
final class AttachmentExcerpt
{
    public function cached(string $disk, string $path, string $expectedHash): array
    {
        try {
            app(\App\Services\Kb\Versioning\ConversionArtifactStore::class)->assertContainedOnDisk($disk, $path);
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return ['text' => '', 'reason' => 'missing'];
            }
            if ($storage->size($path) > (int) config('email-triage.extraction_max_bytes', 5242880)) {
                return ['text' => '', 'reason' => 'extraction_size_limit'];
            }
            $stream = $storage->readStream($path);
            if (! is_resource($stream)) {
                return ['text' => '', 'reason' => 'unreadable'];
            }
            try {
                $hash = hash_init('sha256');
                $intro = '';
                while (! feof($stream)) {
                    $chunk = fread($stream, 16384);
                    if ($chunk === false) {
                        throw new \RuntimeException('unreadable');
                    }
                    hash_update($hash, $chunk);
                    $intro .= substr($chunk, 0, max(0, 16384 - strlen($intro)));
                }
                if (hash_final($hash) !== $expectedHash) {
                    return ['text' => '', 'reason' => 'snapshot_integrity_failed'];
                }
                $intro = mb_substr(mb_convert_encoding($intro, 'UTF-8', 'UTF-8'), 0, (int) config('email-triage.snippet_characters', 1500));

                return ['text' => $intro, 'reason' => $intro === '' ? 'no_readable_text' : 'cached_introductory_excerpt'];
            } finally {
                fclose($stream);
            }
        } catch (\Throwable) {
            return ['text' => '', 'reason' => 'unreadable'];
        }
    }

    public function read(string $disk, array $file): array
    {
        try {
            return $this->readBounded($disk, $file);
        } catch (\Throwable) {
            return ['text' => '', 'reason' => 'unreadable'];
        }
    }

    private function readBounded(string $disk, array $file): array
    {
        if (($file['status'] ?? '') !== 'saved') {
            return ['text' => '', 'reason' => $file['status'] ?? 'unavailable'];
        }
        $storage = Storage::disk($disk);
        $path = (string) $file['snapshot'];
        if (KbPath::normalize($path) !== $path) {
            throw new \RuntimeException('noncanonical_attachment_path');
        }
        if ((string) config("filesystems.disks.{$disk}.driver") === 'local') {
            $root = realpath($storage->path(''));
            $resolved = realpath($storage->path($path));
            if ($root !== false && $resolved !== false && ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('attachment_outside_disk');
            }
        }
        if (! $storage->exists($path)) {
            return ['text' => '', 'reason' => 'missing'];
        }
        if ($storage->size($path) > (int) config('email-triage.extraction_max_bytes', 5242880)) {
            return ['text' => '', 'reason' => 'extraction_size_limit'];
        }
        if (isset($file['hash'])) {
            $stream = $storage->readStream($path);
            if (! is_resource($stream)) {
                return ['text' => '', 'reason' => 'unreadable'];
            }
            try {
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                if (hash_final($hash) !== $file['hash']) {
                    return ['text' => '', 'reason' => 'snapshot_integrity_failed'];
                }
            } finally {
                fclose($stream);
            }
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $text = '';
        try {
            if (in_array($extension, ['txt', 'md', 'csv', 'html', 'htm', 'xml', 'json', 'log'], true)) {
                $stream = $storage->readStream($path);
                if (! is_resource($stream)) {
                    return ['text' => '', 'reason' => 'unreadable'];
                }
                try {
                    $text = stream_get_contents($stream, 16384) ?: '';
                } finally {
                    fclose($stream);
                }
                $text = strip_tags($text);
            } elseif (in_array($extension, ['docx', 'odt', 'pdf'], true)) {
                $local = tempnam(sys_get_temp_dir(), 'email-triage-');
                if ($local === false) {
                    return ['text' => '', 'reason' => 'unavailable'];
                }
                try {
                    file_put_contents($local, $storage->get($path));
                    if ($extension === 'pdf') {
                        // Restrict output as well as execution time; never collect a huge page in RAM.
                        $process = new Process(['pdftotext', '-f', '1', '-l', '1', '-nopgbrk', $local, '-']);
                        $process->setTimeout(3);
                        $process->run(function ($type, $chunk) use (&$text, $process): void {
                            if ($type === Process::OUT) {
                                $text .= substr($chunk, 0, max(0, 16384 - strlen($text)));
                            }
                            $process->clearOutput();
                            $process->clearErrorOutput();
                            if (strlen($text) >= 16384) {
                                $process->stop(0);
                            }
                        });
                    } else {
                        $zip = new \ZipArchive;
                        if ($zip->open($local) === true) {
                            try {
                                $stream = $zip->getStream($extension === 'docx' ? 'word/document.xml' : 'content.xml');
                                if (is_resource($stream)) {
                                    try {
                                        $text = strip_tags(str_replace(['</w:p>', '</text:p>'], "\n", stream_get_contents($stream, 65536) ?: ''));
                                    } finally {
                                        fclose($stream);
                                    }
                                }
                            } finally {
                                $zip->close();
                            }
                        }
                    }
                } finally {
                    @unlink($local);
                }
            } else {
                return ['text' => '', 'reason' => 'unsupported_preview'];
            }
        } catch (\Throwable) {
            return ['text' => '', 'reason' => 'unreadable_or_tool_unavailable'];
        }
        $text = trim(mb_convert_encoding($text, 'UTF-8', 'UTF-8'));

        return ['text' => mb_substr($text, 0, (int) config('email-triage.snippet_characters', 1500)),
            'reason' => $text === '' ? 'no_readable_text' : 'introductory_excerpt'];
    }
}
