<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use JsonException;
use ZipArchive;

/** ZIP is only a transport envelope. Entries are never extracted or executed. */
final class ConnectorPackageService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_README_BYTES = 64 * 1024;

    public function read(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            $this->invalid('Choose a JSON or ZIP file no larger than 5 MiB.');
        }

        return match (strtolower($file->getClientOriginalExtension())) {
            'json' => $this->decode(file_get_contents($file->getPathname())),
            'zip' => $this->readZip($file->getPathname()),
            default => $this->invalid('Only .json and .zip API connector packages are supported.'),
        };
    }

    public function encode(array $config): string
    {
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_BYTES) {
            $this->invalid('The connector configuration exceeds the 5 MiB limit.');
        }

        return $json;
    }

    public function zip(array $config): string
    {
        $json = $this->encode($config);
        $this->requireZip();
        $path = tempnam(sys_get_temp_dir(), 'amd-api-');
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate the API package.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create the API package.');
            }
            // Stored entries guarantee that our own exports satisfy the ratio limit.
            $zip->addFromString('connector.json', $json);
            $zip->setCompressionName('connector.json', ZipArchive::CM_STORE);
            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finish the API package.');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
                $this->invalid('The ZIP exceeds the 5 MiB limit. Use the JSON export.');
            }

            return $bytes;
        } finally {
            unlink($path);
        }
    }

    private function readZip(string $path): array
    {
        $this->requireZip();
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            $this->invalid('The ZIP is invalid or damaged.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 2) {
                $this->invalid('A package must contain connector.json and optionally README.md at its root.');
            }
            $seen = [];
            $json = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                if (! in_array($name, ['connector.json', 'README.md'], true) || isset($seen[$name])) {
                    $this->invalid('Unexpected or duplicate ZIP entry. Only connector.json and README.md are allowed at the root.');
                }
                $seen[$name] = true;
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                $kind = ($attributes >> 16) & 0170000;
                $limit = $name === 'connector.json' ? self::MAX_BYTES : self::MAX_README_BYTES;
                if (! in_array($kind, [0, 0100000], true) || ($attributes & 0x10) !== 0
                    || ($entry['encryption_method'] ?? 0) !== ZipArchive::EM_NONE
                    || ! in_array($entry['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)
                    || $entry['size'] > $limit
                    || $entry['size'] > max(1, $entry['comp_size']) * 100) {
                    $this->invalid('Unsafe ZIP entry: check entry type, encryption, compression, size and compression ratio (maximum 100:1).');
                }
                $stream = $zip->getStreamIndex($i);
                if ($stream === false) {
                    $this->invalid('The ZIP entry cannot be read.');
                }
                try {
                    $bytes = stream_get_contents($stream, $limit + 1);
                } finally {
                    fclose($stream);
                }
                if ($bytes === false || strlen($bytes) !== $entry['size'] || strlen($bytes) > $limit
                    || hash('crc32b', $bytes) !== sprintf('%08x', $entry['crc'])) {
                    $this->invalid('The ZIP entry is damaged or exceeds its declared size.');
                }
                if ($name === 'connector.json') {
                    $json = $bytes;
                }
            }
            if ($json === null) {
                $this->invalid('The ZIP is missing connector.json.');
            }

            return $this->decode($json);
        } catch (\ErrorException|\ValueError) {
            $this->invalid('The ZIP entry is damaged or cannot be read safely.');
        } finally {
            $zip->close();
        }
    }

    private function decode(string $json): array
    {
        try {
            $object = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass) {
                $this->invalid('connector.json must contain a JSON object.');
            }

            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid('connector.json must be valid UTF-8 JSON with a maximum nesting depth of 64.');
        }
    }

    private function requireZip(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->invalid('ZIP support is unavailable on this server. Use a JSON configuration file.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
