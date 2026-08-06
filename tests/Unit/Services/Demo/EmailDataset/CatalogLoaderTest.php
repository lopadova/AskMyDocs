<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo\EmailDataset;

use App\Services\Demo\EmailDataset\CatalogLoader;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Tests\TestCase;

final class CatalogLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        $files = new Filesystem;
        foreach ($this->temporaryDirectories as $directory) {
            if (is_dir($directory)) {
                $this->assertTrue($files->deleteDirectory($directory));
            }
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    public function test_v1_is_loaded_from_its_immutable_snapshot_directory(): void
    {
        $loader = new CatalogLoader;

        $this->assertSame([
            'schema_version' => '2.0',
            'catalog_version' => 'v1',
            'companies' => [
                'passolibero-calzature',
                'prometeo-antincendio',
                'rotta-logistics',
            ],
        ], $loader->loadIndex('v1'));
        $this->assertSame(
            'Rotta Sicura Logistics',
            $loader->loadCompany('v1', 'rotta-logistics')['company_name'],
        );
        $projectRoot = dirname(__DIR__, 5);
        $this->assertFileExists($projectRoot.'/database/seeders/email-dataset/catalogs/v1/catalog.json');
        $this->assertFileDoesNotExist($projectRoot.'/database/seeders/email-dataset/catalog.json');
    }

    public function test_catalog_versions_are_resolved_as_independent_snapshots(): void
    {
        $root = $this->temporaryDirectory();
        $this->writeIndex($root, 'v1', 'v1');
        $this->writeIndex($root, 'v2', 'v2');
        $loader = new CatalogLoader($root);

        $this->assertSame('v1', $loader->loadIndex('v1')['catalog_version']);
        $this->assertSame('v2', $loader->loadIndex('v2')['catalog_version']);
    }

    public function test_legacy_unversioned_index_is_not_used_as_a_fallback(): void
    {
        $root = $this->temporaryDirectory();
        $this->writeJson($root.'/catalog.json', [
            'schema_version' => '2.0',
            'catalog_version' => 'v1',
            'companies' => ['example-company'],
        ]);
        $loader = new CatalogLoader($root);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Catalog version v1 is unavailable.');
        $loader->loadIndex('v1');
    }

    public function test_snapshot_rejects_a_mismatched_declared_version(): void
    {
        $root = $this->temporaryDirectory();
        $this->writeIndex($root, 'v2', 'v1');
        $loader = new CatalogLoader($root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Catalog snapshot v2 declares version v1.');
        $loader->loadIndex('v2');
    }

    public function test_snapshot_rejects_an_unsupported_schema_version(): void
    {
        $root = $this->temporaryDirectory();
        $this->writeIndex($root, 'v1', 'v1', '3.0');
        $loader = new CatalogLoader($root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Catalog snapshot v1 must use schema version 2.0.');
        $loader->loadIndex('v1');
    }

    public function test_catalog_version_cannot_escape_the_catalog_root(): void
    {
        $loader = new CatalogLoader($this->temporaryDirectory());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid catalog version key');
        $loader->loadIndex('../v1');
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/askmydocs-email-catalog-test-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $this->assertTrue($files->makeDirectory($directory, 0755, true));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function writeIndex(
        string $root,
        string $directoryVersion,
        string $declaredVersion,
        string $schemaVersion = '2.0',
    ): void {
        $this->writeJson($root.'/catalogs/'.$directoryVersion.'/catalog.json', [
            'schema_version' => $schemaVersion,
            'catalog_version' => $declaredVersion,
            'companies' => ['example-company'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function writeJson(string $path, array $payload): void
    {
        $files = new Filesystem;
        $directory = dirname($path);
        if (! is_dir($directory)) {
            $this->assertTrue($files->makeDirectory($directory, 0755, true));
        }

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
        $this->assertNotFalse($files->put($path, $encoded));
    }
}
