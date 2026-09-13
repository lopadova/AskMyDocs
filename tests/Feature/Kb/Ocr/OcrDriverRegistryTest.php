<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use App\Services\Kb\Ocr\OcrDriver;
use App\Services\Kb\Ocr\OcrDriverRegistry;
use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * R23 — the OCR driver registry validates every FQCN at boot and resolves by
 * name; the `fake` driver is refused in production (SEC-ENV-001).
 */
final class OcrDriverRegistryTest extends TestCase
{
    public function test_every_configured_driver_implements_the_contract(): void
    {
        $registry = $this->app->make(OcrDriverRegistry::class);

        $this->assertSame(['docling', 'mistral-ocr', 'vision-llm', 'tesseract', 'fake'], $registry->names());
        // Remote drivers are part of the roster but only resolvable once the
        // egress knob is on — see the remote-policy tests below.
        config(['kb.ocr.allow_remote' => true]);
        foreach ($registry->names() as $name) {
            $this->assertInstanceOf(OcrDriver::class, $registry->resolve($name));
            $this->assertSame($name, $registry->resolve($name)->name());
        }
    }

    /**
     * ADR 0029 §7 / SEC-LLM-001 — a driver that posts the scan to a third
     * party never runs by accident: the default install fails closed.
     */
    #[DataProvider('remoteDriverProvider')]
    public function test_remote_driver_is_refused_by_default(string $name): void
    {
        $registry = $this->app->make(OcrDriverRegistry::class);

        $this->assertFalse(config('kb.ocr.allow_remote'), 'The test config must not pre-authorise remote egress.');

        $this->expectException(OcrDriverUnavailableException::class);
        $this->expectExceptionMessage('KB_OCR_ALLOW_REMOTE=true');

        $registry->resolve($name);
    }

    #[DataProvider('remoteDriverProvider')]
    public function test_remote_driver_is_refused_when_the_knob_is_not_exactly_true(string $name): void
    {
        // A truthy-but-wrong value (string, int) keeps the gate closed: the
        // config layer casts the env with FILTER_VALIDATE_BOOLEAN, so only a
        // real `true` reaches here — anything else is a misconfiguration.
        config(['kb.ocr.allow_remote' => '1']);

        $this->expectException(OcrDriverUnavailableException::class);

        $this->app->make(OcrDriverRegistry::class)->resolve($name);
    }

    #[DataProvider('remoteDriverProvider')]
    public function test_remote_driver_resolves_when_egress_is_allowed(string $name): void
    {
        config(['kb.ocr.allow_remote' => true]);

        $driver = $this->app->make(OcrDriverRegistry::class)->resolve($name);

        $this->assertTrue($driver->isRemote());
        $this->assertSame($name, $driver->name());
    }

    public function test_local_drivers_do_not_need_the_egress_knob(): void
    {
        $registry = $this->app->make(OcrDriverRegistry::class);

        foreach (['docling', 'tesseract', 'fake'] as $name) {
            $driver = $registry->resolve($name);
            $this->assertFalse($driver->isRemote(), "{$name} must be a local driver");
        }
    }

    /** @return array<string, array{string}> */
    public static function productionLikeEnvironments(): array
    {
        return ['production' => ['production'], 'prod' => ['prod'], 'Production' => ['Production'], 'staging' => ['staging'], 'unknown' => ['whatever']];
    }

    /** @return array<string, array{string}> */
    public static function remoteDriverProvider(): array
    {
        return [
            'mistral-ocr' => ['mistral-ocr'],
            'vision-llm' => ['vision-llm'],
        ];
    }

    public function test_a_class_that_is_not_a_driver_fails_at_boot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not implement');

        new OcrDriverRegistry($this->app, ['bogus' => \stdClass::class]);
    }

    public function test_unknown_driver_name_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown OCR driver "nope"');

        $this->app->make(OcrDriverRegistry::class)->resolve('nope');
    }

    public function test_configured_driver_follows_kb_ocr_driver(): void
    {
        config(['kb.ocr.driver' => 'fake']);

        $this->assertInstanceOf(FakeOcrDriver::class, $this->app->make(OcrDriverRegistry::class)->configured());
    }

    /**
     * SEC-ENV-001 — an unknown or misspelt environment name is production.
     */
    #[DataProvider('productionLikeEnvironments')]
    public function test_fake_driver_is_refused_in_production(string $environment): void
    {
        $previous = $this->app->environment();
        $this->app->detectEnvironment(static fn (): string => $environment);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not available in production');
            $this->app->make(OcrDriverRegistry::class)->resolve('fake');
        } finally {
            // R16 — restore the mutated global.
            $this->app->detectEnvironment(static fn (): string => $previous);
        }
    }
}
