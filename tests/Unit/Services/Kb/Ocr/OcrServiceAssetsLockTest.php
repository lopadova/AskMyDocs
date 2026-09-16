<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PR #492 Copilot round-2 (`OcrService.php:228`) — `underAssetsLock()` guards
 * the ASSETS directory lock (figure writes), a DIFFERENT lock from the RUN
 * reservation `convert()` / `touchRunBeforeCommit()` / `rerun()` each guard
 * with their own `cacheStoreCanLock()` check before ever reaching this
 * method. Because every one of those outer guards reads the identical
 * static `ConversionArtifactStore::cacheStoreCanLock()` against the SAME
 * configured cache store, a test that only exercises `convert()` end-to-end
 * on a no-lock store never actually reaches this method's own guard — the
 * outer one always refuses first. This test calls the private method
 * directly (reflection) to pin `underAssetsLock()`'s own refusal
 * independently of any future change to its callers' outer guards.
 */
final class OcrServiceAssetsLockTest extends TestCase
{
    public function test_under_assets_lock_refuses_on_a_store_without_locks(): void
    {
        \Illuminate\Support\Facades\Cache::extend('nolock', static fn ($app) => \Illuminate\Support\Facades\Cache::repository(new \Tests\Fixtures\Cache\NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);

        $service = app(OcrService::class);
        $method = new \ReflectionMethod(OcrService::class, 'underAssetsLock');

        $called = false;
        try {
            $method->invoke($service, 'kb', 'docs/x.md', '', function () use (&$called) {
                $called = true;

                return null;
            });
            $this->fail('Expected a RuntimeException.');
        } catch (\ReflectionException) {
            $this->fail('Reflection call failed unexpectedly.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be locked', $e->getMessage());
        }

        $this->assertFalse($called, 'the write closure must never run once the store refuses to lock the assets directory');
    }
}
