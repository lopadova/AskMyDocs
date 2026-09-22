<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * R43 — the OCR boolean flags parse every spelling operators use
 * (`0`, `false`, `off`, `no`) as OFF, exactly like `KB_OCR_ALLOW_REMOTE`.
 * `(bool) env(...)` would read `"off"` / `"no"` as ON.
 */
final class KbOcrConfigFlagsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previous = [];

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->previous = [];
        parent::tearDown();
    }

    public function test_every_off_spelling_disables_the_ocr_flags(): void
    {
        foreach (['0', 'false', 'off', 'no', 'FALSE'] as $spelling) {
            $this->setEnv('KB_OCR_ENABLED', $spelling);
            $this->setEnv('KB_OCR_REUSE_ENABLED', $spelling);
            $this->setEnv('KB_OCR_FIGURES_ENABLED', $spelling);
            $config = require __DIR__.'/../../../config/kb.php';

            $this->assertFalse($config['ocr']['enabled'], "KB_OCR_ENABLED={$spelling}");
            $this->assertFalse($config['ocr']['reuse']['enabled'], "KB_OCR_REUSE_ENABLED={$spelling}");
            $this->assertFalse($config['ocr']['figures']['enabled'], "KB_OCR_FIGURES_ENABLED={$spelling}");
        }

        foreach (['1', 'true', 'on', 'yes'] as $spelling) {
            $this->setEnv('KB_OCR_ENABLED', $spelling);
            $config = require __DIR__.'/../../../config/kb.php';
            $this->assertTrue($config['ocr']['enabled'], "KB_OCR_ENABLED={$spelling}");
        }
    }

    private function setEnv(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->previous)) {
            $this->previous[$name] = getenv($name);
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
