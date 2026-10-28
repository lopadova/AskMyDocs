<?php

declare(strict_types=1);

namespace App\Agent\Waves;

use App\Services\Chat\Reasoning\ResearchFailure;

/** Private, local side channel between a wave process and its parent. */
final class AgentWaveResultTransport
{
    public const MAX_BYTES = 64 * 1024 * 1024;

    public static function write(string $path, \Closure $task): void
    {
        try {
            $serialized = serialize($task());
            if (strlen($serialized) > intdiv(self::MAX_BYTES - 128, 4) * 3) return;
            $envelope = ['version' => 1, 'status' => 'success', 'result' => base64_encode($serialized)];
        } catch (\Throwable $exception) {
            $envelope = ['version' => 1, 'status' => 'failure', 'exception' => preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $exception::class) ? $exception::class : 'Throwable'];
        }

        $temporary = tempnam(dirname($path), 'result-');
        if ($temporary === false) return;
        try {
            if (! chmod($temporary, 0600)) return;
            $contents = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($contents) > self::MAX_BYTES || file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) return;
            rename($temporary, $path);
        } catch (\Throwable) {
            // Missing transport data is reported by the parent without exposing child output.
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    public static function read(string $path): mixed
    {
        if (! is_file($path)) return new ResearchFailure('research_transport_invalid');
        try {
            $file = @fopen($path, 'rb');
            if ($file === false) throw new \RuntimeException();
            try {
                $stat = fstat($file);
                if ($stat === false || $stat['size'] < 1 || $stat['size'] > self::MAX_BYTES) throw new \RuntimeException();
                $contents = stream_get_contents($file, self::MAX_BYTES + 1);
                if ($contents === false || strlen($contents) !== $stat['size']) throw new \RuntimeException();
            } finally {
                fclose($file);
            }
            $envelope = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ($envelope['version'] ?? null) !== 1) throw new \RuntimeException();
            if (($envelope['status'] ?? null) === 'failure') {
                if (! is_string($envelope['exception'] ?? null) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $envelope['exception'])) throw new \RuntimeException();
                return new ResearchFailure('research_branch_failed');
            }
            if (($envelope['status'] ?? null) !== 'success' || ! is_string($envelope['result'] ?? null) || $envelope['result'] === '') throw new \RuntimeException();
            $serialized = base64_decode($envelope['result'], true);
            if ($serialized === false || $serialized === '' || base64_encode($serialized) !== $envelope['result']) throw new \RuntimeException();
            set_error_handler(static function () { throw new \RuntimeException(); });
            try {
                return unserialize($serialized);
            } finally {
                restore_error_handler();
            }
        } catch (\Throwable) {
            return new ResearchFailure('research_transport_invalid');
        }
    }
}
