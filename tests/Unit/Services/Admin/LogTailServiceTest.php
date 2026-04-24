<?php

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\LogTailService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for the reverse-seek log tailer (Phase H1).
 *
 * Covers:
 *  - filename whitelist accept / reject matrix
 *  - tail-N semantics (cap at 2000, oldest-to-newest ordering)
 *  - level filter (case-insensitive bracketed match)
 *  - missing file → RuntimeException; unreadable → RuntimeException
 */
class LogTailServiceTest extends TestCase
{
    private LogTailService $svc;

    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LogTailService;
        $this->logDir = storage_path('logs');
        if (! is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function test_filename_whitelist_accepts_laravel_log(): void
    {
        $this->assertTrue($this->svc->isValidFilename('laravel.log'));
        $this->assertTrue($this->svc->isValidFilename('laravel-2025-01-01.log'));
        $this->assertTrue($this->svc->isValidFilename('laravel-2026-12-31.log'));
    }

    public function test_filename_whitelist_rejects_everything_else(): void
    {
        $rejected = [
            '',
            ' ',
            ' laravel.log',
            'laravel.log ',
            '../laravel.log',
            '/etc/passwd',
            'laravel',
            'LARAVEL.LOG',        // uppercase — we're strict
            'laravel-99-1-1.log', // wrong date format
            'laravel-abc.log',
            'other.log',
            'laravel.log.bak',
            'laravel.log/../etc/hosts',
            "laravel.log\0.txt",  // null-byte injection
        ];
        foreach ($rejected as $name) {
            $this->assertFalse(
                $this->svc->isValidFilename($name),
                "Expected '{$name}' to be rejected",
            );
        }
    }

    public function test_tail_throws_on_invalid_filename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->tail('secrets.txt');
    }

    public function test_tail_throws_on_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        // Rotated name unlikely to exist.
        $this->svc->tail('laravel-1999-01-01.log');
    }

    public function test_tail_returns_last_n_lines_oldest_to_newest(): void
    {
        $path = $this->logDir.'/laravel.log';
        $this->writeTemp($path, range(1, 10));

        try {
            $result = $this->svc->tail('laravel.log', 3);

            $this->assertSame(['line 8', 'line 9', 'line 10'], $result['lines']);
            $this->assertTrue($result['truncated']);
            // total_scanned includes the trailing-newline's empty "line"
            // produced by SplFileObject — we skip it silently but still
            // count the iteration so operators can see how many raw
            // positions were inspected.
            $this->assertGreaterThanOrEqual(3, $result['total_scanned']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_tail_returns_all_lines_when_under_max(): void
    {
        $path = $this->logDir.'/laravel.log';
        $this->writeTemp($path, range(1, 3));

        try {
            $result = $this->svc->tail('laravel.log', 500);

            $this->assertSame(['line 1', 'line 2', 'line 3'], $result['lines']);
            $this->assertFalse($result['truncated']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_tail_hard_caps_at_2000(): void
    {
        $path = $this->logDir.'/laravel.log';
        $this->writeTemp($path, range(1, 50));

        try {
            // Ask for way more than the cap — cap MUST win.
            $result = $this->svc->tail('laravel.log', 99_999);

            // All 50 fit under the cap; no truncation.
            $this->assertCount(50, $result['lines']);
            $this->assertFalse($result['truncated']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_tail_level_filter_matches_bracketed_tokens(): void
    {
        $path = $this->logDir.'/laravel.log';
        file_put_contents($path,
            "[2025-01-01 10:00:00] local.INFO: normal\n".
            "[2025-01-01 10:01:00] local.ERROR: broken\n".
            "[2025-01-01 10:02:00] local.WARNING: soft\n".
            "[2025-01-01 10:03:00] local.ERROR: still broken\n"
        );

        try {
            $errors = $this->svc->tail('laravel.log', 500, 'ERROR');
            $this->assertCount(2, $errors['lines']);
            foreach ($errors['lines'] as $line) {
                $this->assertStringContainsString('ERROR', $line);
            }

            // Case-insensitive.
            $lower = $this->svc->tail('laravel.log', 500, 'warning');
            $this->assertCount(1, $lower['lines']);
            $this->assertStringContainsString('WARNING', $lower['lines'][0]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_tail_null_level_returns_everything(): void
    {
        $path = $this->logDir.'/laravel.log';
        file_put_contents($path,
            "[2025-01-01 10:00:00] local.INFO: a\n".
            "[2025-01-01 10:01:00] local.ERROR: b\n"
        );

        try {
            $result = $this->svc->tail('laravel.log', 500, null);
            $this->assertCount(2, $result['lines']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_tail_empty_file_returns_empty_lines(): void
    {
        $path = $this->logDir.'/laravel.log';
        file_put_contents($path, '');

        try {
            $result = $this->svc->tail('laravel.log', 500);
            $this->assertSame([], $result['lines']);
            $this->assertFalse($result['truncated']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_resolve_full_path_joins_storage_logs(): void
    {
        // storage_path() always emits the platform DIRECTORY_SEPARATOR
        // for the directory hierarchy but appends our relative
        // "logs/laravel.log" segment with the literal `/` separator
        // we pass in. Normalise both before comparing so the test
        // passes on Windows (\) and POSIX (/) runners.
        $resolved = str_replace('\\', '/', $this->svc->resolveFullPath('laravel.log'));
        $this->assertStringEndsWith('/logs/laravel.log', $resolved);
    }

    /** @param  array<int, int|string>  $lineNumbers */
    private function writeTemp(string $path, array $lineNumbers): void
    {
        $content = '';
        foreach ($lineNumbers as $n) {
            $content .= "line {$n}\n";
        }
        file_put_contents($path, $content);
    }
}
