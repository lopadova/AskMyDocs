<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Resolves the configured OCR driver from `kb.ocr.drivers` (key → FQCN).
 *
 * R23 (pluggable-pipeline-registry): every FQCN in the map is validated to
 * implement {@see OcrDriver} when the registry boots, so a typo in config
 * fails at boot rather than on the first scanned upload. The map is
 * explicit — no filesystem discovery.
 *
 * The `fake` driver is refused in production (SEC-ENV-001): it exists for
 * tests and the E2E harness, and a deployment that names it by mistake
 * must fail loudly rather than index synthetic text.
 */
final class OcrDriverRegistry
{
    /** @var array<string, OcrDriver> */
    private array $drivers = [];

    /**
     * @param  array<string, class-string<OcrDriver>>  $map
     */
    public function __construct(Container $app, array $map)
    {
        foreach ($map as $key => $class) {
            if (! is_string($key) || $key === '') {
                throw new RuntimeException('kb.ocr.drivers keys must be non-empty strings.');
            }
            $instance = $app->make($class);
            if (! $instance instanceof OcrDriver) {
                throw new RuntimeException(sprintf(
                    'OCR driver "%s" (%s) does not implement %s — check config/kb.php kb.ocr.drivers.',
                    $key,
                    is_string($class) ? $class : get_debug_type($class),
                    OcrDriver::class,
                ));
            }
            $this->drivers[$key] = $instance;
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->drivers);
    }

    /**
     * @throws RuntimeException when the name is not registered or is `fake` in production
     */
    public function resolve(string $name): OcrDriver
    {
        if (! $this->has($name)) {
            throw new RuntimeException(sprintf(
                'Unknown OCR driver "%s". Registered: %s.',
                $name,
                implode(', ', $this->names()),
            ));
        }

        // SEC-ENV-001 — an allow-list, not an exact match on "production":
        // `prod`, `Production` or any unknown environment name is production.
        if ($name === 'fake' && ! app()->environment(['local', 'testing', 'development'])) {
            throw new RuntimeException('The "fake" OCR driver is not available in production.');
        }

        $driver = $this->drivers[$name];

        // ADR 0029 §7 — final-egress policy: a driver that sends the scan out
        // of the tenant's infrastructure runs only when the operator said so.
        // Fail closed: the default install never posts a document to an API
        // by accident, and a typo in the knob keeps it closed.
        if ($driver->isRemote() && ! self::remoteAllowed()) {
            throw new OcrDriverUnavailableException(sprintf(
                'OCR driver "%s" sends documents to a remote service and is disabled; set KB_OCR_ALLOW_REMOTE=true to allow it.',
                $name,
            ));
        }

        return $driver;
    }

    /**
     * Whether the named driver posts document bytes outside the tenant —
     * answered from the registered instance WITHOUT the egress gate, so the
     * estimate can describe a driver the registry would refuse to run.
     * Unknown name → false (nothing leaves through a driver that does not exist).
     */
    public function isRemote(string $name): bool
    {
        return $this->has($name) && $this->drivers[$name]->isRemote();
    }

    /**
     * Whether the named driver refuses a PDF whose page count could not be
     * verified (ADR 0029 §4): remote, or unable to bound its own work.
     * Answered WITHOUT the egress gate so the estimate can describe a driver
     * the registry would refuse to run. Unknown name → true (refuse).
     */
    public function refusesUnverifiedPageCount(string $name): bool
    {
        if (! $this->has($name)) {
            return true;
        }
        $driver = $this->drivers[$name];

        return $driver->isRemote() || ! $driver->boundsWorkWithoutPageCount();
    }

    public static function remoteAllowed(): bool
    {
        return config('kb.ocr.allow_remote', false) === true;
    }

    /** The driver named by `kb.ocr.driver`. */
    public function configured(): OcrDriver
    {
        return $this->resolve((string) config('kb.ocr.driver', 'tesseract'));
    }
}
