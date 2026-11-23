<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Pact\Native\PactInstallationBackup;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PactInstallationBackupTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORIGIN = 'https://askmydocs.example.test';

    private string $storage;

    private Encrypter $encrypter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/askmydocs-pact-'.Str::uuid();
        $this->encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_create_only_backup_replays_and_restores_exact_bytes_on_a_new_replica(): void
    {
        $files = $this->installationFiles();
        $this->writeInstallation($files);
        $s3 = Mockery::mock(S3ClientInterface::class);
        $encrypted = null;
        $s3->shouldReceive('putObject')->once()->withArgs(function (array $request) use (&$encrypted): bool {
            $this->assertSame('*', $request['IfNoneMatch']);
            $this->assertSame('askmydocs/develop.enc', $request['Key']);
            $encrypted = $request['Body'];

            return true;
        });
        $s3->shouldReceive('putObject')->once()->withAnyArgs()
            ->andThrow(new S3Exception('Object exists', new Command('PutObject'), ['response' => new Response(412)]));
        $s3->shouldReceive('getObject')->times(3)->andReturnUsing(static function () use (&$encrypted): array {
            return ['Body' => $encrypted];
        });

        $source = $this->backup($s3, $this->storage.'/source');
        $source->backup();
        $source->backup();
        $this->assertStringNotContainsString('PRIVATE KEY', $encrypted);

        $replica = $this->backup($s3, $this->storage.'/replica');
        $replica->restore();
        $replica->restore();
        foreach ($files as $path => $expected) {
            $destination = $this->storage.'/replica/'.$path;
            $this->assertSame($expected, file_get_contents($destination));
            $this->assertSame(0600, fileperms($destination) & 0777);
        }
    }

    public function test_restore_rejects_another_environment_before_writing_keys(): void
    {
        $files = $this->installationFiles('https://other.example.test');
        $encrypted = $this->encrypter->encryptString(json_encode(['version' => 1, 'files' => $files], JSON_THROW_ON_ERROR));
        $s3 = Mockery::mock(S3ClientInterface::class);
        $s3->shouldReceive('getObject')->once()->andReturn(['Body' => $encrypted]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not identify this client origin');
        try {
            $this->backup($s3, $this->storage)->restore();
        } finally {
            $this->assertFileDoesNotExist($this->storage.'/app/pact/private.pem');
            $this->assertFileDoesNotExist($this->storage.'/app/pact/installation.json');
        }
    }

    public function test_a_missing_bundle_never_creates_a_new_installation(): void
    {
        $s3 = Mockery::mock(S3ClientInterface::class);
        $s3->shouldReceive('getObject')->once()
            ->andThrow(new S3Exception('Object is missing', new Command('GetObject'), ['response' => new Response(404)]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be read or decrypted');
        try {
            $this->backup($s3, $this->storage)->restore();
        } finally {
            $this->assertFileDoesNotExist($this->storage.'/app/pact/installation.json');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_cold_application_restores_before_registering_client_routes(): void
    {
        $root = dirname(__DIR__, 3);
        $files = $this->installationFiles();
        $encrypted = $this->encrypter->encryptString(json_encode(['version' => 1, 'files' => $files], JSON_THROW_ON_ERROR));
        $s3 = new MockHandler([new Result(['Body' => $encrypted])]);
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $cachePaths = ['APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes.php'];
        $previousEnvironment = array_intersect_key($_ENV, $cachePaths);
        foreach ($cachePaths as $variable => $filename) {
            $_ENV[$variable] = $this->storage.'/'.$filename;
        }

        try {
            $app = require $root.'/bootstrap/app.php';
            $app->useStoragePath($this->storage);
            (new Filesystem)->ensureDirectoryExists($this->storage.'/framework/views', 0700);
            $app->afterBootstrapping(HandleExceptions::class, static function (): void {
                restore_error_handler();
                restore_exception_handler();
            });
            $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($s3): void {
                $app['config']->set([
                    'app.key' => 'base64:'.base64_encode($this->encrypter->getKey()),
                    'app.cipher' => 'AES-256-CBC',
                    'app.url' => self::ORIGIN,
                    'view.compiled' => $this->storage.'/framework/views',
                    'pact.state.restore' => true,
                    'pact.state.disk' => 'pact-state',
                    'pact.state.object' => 'askmydocs/develop.enc',
                    'passport.private_key' => null,
                    'passport.public_key' => null,
                    'filesystems.disks.pact-state' => [
                        'driver' => 's3', 'bucket' => 'pact-private', 'region' => 'us-east-1',
                        'key' => 'fixture', 'secret' => 'fixture', 'handler' => $s3,
                    ],
                ]);
            });
            $app->make(Kernel::class)->bootstrap();

            $this->assertSame('client', $app['config']->get('pact.role'));
            $this->assertSame('pact/oauth/authorize', $app['router']->getRoutes()->getByName('pact.oauth.authorize')?->uri());
            $this->assertSame($files['oauth-private.key'], file_get_contents($this->storage.'/oauth-private.key'));
        } finally {
            $_ENV = array_diff_key($_ENV, $cachePaths) + $previousEnvironment;
            Container::setInstance($previousContainer);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
        }
    }

    private function installationFiles(string $origin = self::ORIGIN): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];

        return [
            'app/pact/private.pem' => $private,
            'app/pact/public.pem' => $public,
            'oauth-private.key' => $private,
            'oauth-public.key' => $public,
            'app/pact/installation.json' => json_encode([
                'role' => 'client', 'product_id' => (string) Str::uuid(), 'url' => $origin,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function writeInstallation(array $files): void
    {
        $filesystem = new Filesystem;
        foreach ($files as $path => $value) {
            $path = $this->storage.'/source/'.$path;
            $filesystem->ensureDirectoryExists(dirname($path), 0700);
            $filesystem->put($path, $value);
        }
    }

    private function backup(S3ClientInterface $s3, string $storage): PactInstallationBackup
    {
        return new PactInstallationBackup(
            fn () => $s3, $this->encrypter, 'pact-private', 'askmydocs/develop.enc', $storage, self::ORIGIN, true,
        );
    }
}
