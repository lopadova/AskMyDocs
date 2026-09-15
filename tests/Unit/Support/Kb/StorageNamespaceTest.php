<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Kb;

use App\Support\Kb\StorageNamespace;
use Tests\TestCase;

/**
 * ONE reading of a row's recorded storage namespace for every consumer.
 * `metadata` is persisted JSON, so a legacy or directly-ingested row can
 * carry anything under `disk` / `prefix`; every reader must agree on what
 * counts as recorded and what is malformed.
 */
final class StorageNamespaceTest extends TestCase
{
    public function test_only_a_non_empty_string_disk_is_a_recorded_disk(): void
    {
        $this->assertSame('kb', StorageNamespace::recordedDisk(['disk' => 'kb']));
        foreach ([['disk' => null], ['disk' => ''], ['disk' => ['kb']], ['disk' => 7], [], 'not-an-array', null] as $metadata) {
            $this->assertNull(StorageNamespace::recordedDisk($metadata), json_encode($metadata));
        }
    }

    /**
     * A bare `(string)` cast turns an array into the literal `"Array"` (with a
     * PHP warning), and a path composed from that silently names a namespace
     * nobody recorded — the reader would look for the file in the wrong place
     * and conclude it is missing.
     */
    public function test_a_malformed_prefix_falls_back_to_the_configured_one_instead_of_coercing(): void
    {
        config(['kb.sources.path_prefix' => 'configured']);

        foreach ([['prefix' => ['a']], ['prefix' => null], ['prefix' => 7], ['prefix' => new \stdClass], [], 'not-an-array', null] as $metadata) {
            $this->assertSame('configured', StorageNamespace::recordedPrefix($metadata), json_encode(is_object($metadata) ? 'object' : $metadata));
        }
    }

    /**
     * The contract is only worth anything if every consumer uses it. A raw
     * `(string)` cast anywhere in the artifact lifecycle reintroduces the
     * `"Array"` namespace this helper exists to prevent, and the reader then
     * looks in a place nobody recorded — so the absence of those casts is
     * asserted, not assumed (they were found in ten files, then four more).
     */
    public function test_no_consumer_casts_a_recorded_prefix_instead_of_reading_it(): void
    {
        $root = dirname(__DIR__, 4);
        $offenders = [];
        foreach (['app/Services/Kb', 'app/Console/Commands', 'app/Jobs', 'app/Http/Controllers/Api'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/\(string\)\s*\$[A-Za-z_>\-\[\]\x27]*\[\x27prefix\x27\]/', $source) === 1) {
                    $offenders[] = str_replace($root.'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'every consumer must read the recorded prefix through StorageNamespace::recordedPrefix()');
    }

    /** An explicit empty string is a row that recorded "no prefix" — it keeps it, never the configured default. */
    public function test_an_explicit_empty_prefix_is_recorded_not_replaced(): void
    {
        config(['kb.sources.path_prefix' => 'configured']);

        $this->assertSame('', StorageNamespace::recordedPrefix(['prefix' => '']));
        $this->assertSame('docs', StorageNamespace::recordedPrefix(['prefix' => 'docs']));
    }
}
