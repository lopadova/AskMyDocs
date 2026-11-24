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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\CloudBootstrapper;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PactLaravel\Models\Connection;
use PactLaravel\Support\DatabaseInstallation;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

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

    public function test_only_a_missing_object_allows_deploy_to_initialize_state(): void
    {
        $s3 = Mockery::mock(S3ClientInterface::class);
        $s3->shouldReceive('getObject')->once()->andThrow($this->missingObject());
        $state = $this->backup($s3, $this->storage);
        $this->assertFalse($state->restoreIfPresent());
        $this->assertFileDoesNotExist($this->storage.'/app/pact/installation.json');

        $s3->shouldReceive('getObject')->once()
            ->andThrow(new S3Exception('Bucket is missing', new Command('GetObject'), [
                'code' => 'NoSuchBucket', 'response' => new Response(404),
            ]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be read or decrypted');
        $state->restoreIfPresent();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cloud_deploy_creates_the_bundle_and_cold_redeploys_reuse_it_automatically(): void
    {
        $ciphertext = null;
        $existingBundle = static function (Command $command) use (&$ciphertext): Result {
            self::assertSame('GetObject', $command->getName());

            return new Result(['Body' => $ciphertext]);
        };
        $firstDeploy = new MockHandler([
            $this->missingObject(),
            static function (Command $command) use (&$ciphertext): Result {
                self::assertSame('PutObject', $command->getName());
                self::assertSame('*', $command['IfNoneMatch']);
                self::assertSame('cloud-private', $command['Bucket']);
                self::assertSame('askmydocs/pact/askmydocs.example.test/installation.v1.enc', $command['Key']);
                $ciphertext = (string) $command['Body'];

                return new Result;
            },
            $existingBundle,
            $existingBundle,
        ]);
        $app = $this->bootCloudApplication('first-deploy', 'pact-client:deploy', $firstDeploy);
        $kernel = $app->make(Kernel::class);
        $this->assertSame(0, $kernel->call('pact-client:deploy', ['--prepare-only' => true]), $kernel->output());
        $this->assertStringNotContainsString('PRIVATE KEY', $ciphertext);

        $app['db']->connection()->getSchemaBuilder()->create('oauth_clients', static fn (Blueprint $table) => $table->id());
        $this->assertSame(0, $kernel->call('pact-client:deploy'), $kernel->output());
        $this->assertTrue($app['db']->connection()->getSchemaBuilder()->hasTable('pact_connections'));
        $this->assertTrue($app['db']->connection('pact_passport')->getSchemaBuilder()->hasTable('oauth_clients'));

        $newDeploy = $this->bootCloudApplication('next-deploy', 'pact-client:deploy', new MockHandler([$existingBundle, $existingBundle]));
        $nextKernel = $newDeploy->make(Kernel::class);
        $this->assertSame(0, $nextKernel->call('pact-client:deploy', ['--prepare-only' => true]), $nextKernel->output());
        $this->assertSame(0, $nextKernel->call('pact-client:deploy'), $nextKernel->output());

        $worker = $this->bootCloudApplication('new-worker', 'pact:work', new MockHandler([$existingBundle]));
        $build = $this->bootCloudApplication('build', 'route:cache', new MockHandler);
        $this->assertSame('pact/oauth/authorize', $build['router']->getRoutes()->getByName('pact.oauth.authorize')?->uri());
        $this->assertFileDoesNotExist($build->storagePath('app/pact/private.pem'));
        $legacyPipeline = $this->bootCloudApplication('migrate-first', 'migrate', new MockHandler([$this->missingObject()]));
        $this->assertFileDoesNotExist($legacyPipeline->storagePath('app/pact/installation.json'));

        foreach (['app/pact/installation.json', 'app/pact/private.pem', 'oauth-private.key'] as $path) {
            $initial = file_get_contents($app->storagePath($path));
            $this->assertSame($initial, file_get_contents($newDeploy->storagePath($path)));
            $this->assertSame($initial, file_get_contents($worker->storagePath($path)));
        }

        $lost = $this->bootCloudApplication('lost-state', 'pact-client:deploy', new MockHandler([$this->missingObject()]));
        $lostKernel = $lost->make(Kernel::class);
        $this->assertSame(1, $lostKernel->call('pact-client:deploy', ['--prepare-only' => true]));
        $this->assertStringContainsString('The PACT database already exists', $lostKernel->output());
        $this->assertFileDoesNotExist($lost->storagePath('app/pact/installation.json'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_database_store_adopts_the_cloud_bundle_and_restores_a_cold_replica(): void
    {
        if (! class_exists(DatabaseInstallation::class)) {
            $this->markTestSkipped('Requires PACT Laravel 0.3.2 or later.');
        }

        $files = $this->installationFiles();
        $ciphertext = $this->encrypter->encryptString(json_encode(['version' => 1, 'files' => $files], JSON_THROW_ON_ERROR));
        $bundle = static fn (Command $command): Result => new Result(['Body' => $ciphertext]);
        $existing = $this->bootCloudApplication('cloud-existing', 'pact-client:deploy', new MockHandler([$bundle]));
        $existing['db']->connection()->getSchemaBuilder()->create('oauth_clients', static fn (Blueprint $table) => $table->id());
        $existing['db']->connection()->getSchemaBuilder()->create('users', static fn (Blueprint $table) => $table->id());
        $existing['db']->table('users')->insert(['id' => 1]);
        $kernel = $existing->make(Kernel::class);
        $this->assertSame(0, $kernel->call('migrate', ['--path' => [
            'database/migrations/2026_11_21_000001_create_pact_client_subjects.php',
            'database/migrations/2026_11_23_000001_create_pact_client_consents.php',
        ], '--force' => true]), $kernel->output());
        $this->assertSame(0, $kernel->call('pact-client:deploy'), $kernel->output());
        $existing['db']->table('pact_client_subjects')->insert(['id' => 42, 'user_id' => 1, 'tenant_id' => 'test', 'project_key' => 'manuals']);
        $connection = Connection::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => '42', 'federation_id' => (string) Str::uuid(),
            'member_id' => 'fixture-agent', 'remote_url' => 'https://master.example.test', 'remote_user_id' => 'fixture-user', 'status' => 'active',
            'credentials' => ['roster' => 'synthetic-roster-credential'],
        ]);
        $existing['db']->connection('pact_passport')->table('oauth_access_tokens')->insert([
            'id' => 'fixture-passport-token', 'user_id' => 42, 'client_id' => (string) Str::uuid(), 'revoked' => false,
        ]);

        $upgrade = $this->bootCloudApplication('cloud-upgrade', 'pact:install', new MockHandler([$bundle]), 'database');
        $installer = $upgrade->make(Kernel::class);
        $output = new BufferedOutput;
        $status = $installer->call('pact:install', ['--no-interaction' => true], $output);
        $message = $output->fetch();
        $this->assertSame(0, $status, $message);
        $this->assertStringContainsString('encrypted in the application database', $message);
        $payload = $upgrade['db']->table('pact_installation_state')->where('id', 1)->value('payload');
        $this->assertStringNotContainsString('PRIVATE KEY', $payload);

        $redeploy = $this->bootCloudApplication('cloud-redeploy', 'pact-client:deploy', new MockHandler([$bundle, $bundle]), 'database');
        $redeployKernel = $redeploy->make(Kernel::class);
        $this->assertSame(0, $redeployKernel->call('pact-client:deploy'), $redeployKernel->output());
        $this->assertSame($payload, $redeploy['db']->table('pact_installation_state')->where('id', 1)->value('payload'));

        $build = $this->bootCloudApplication('database-build', 'route:cache', new MockHandler, 'database');
        $this->assertSame('database', $build['config']->get('pact.state_store'));
        $this->assertSame('pact/oauth/select-project', $build['router']->getRoutes()->getByName('pact.oauth.select-project')?->uri());
        $this->assertFileDoesNotExist($build->storagePath('app/pact/private.pem'));
        $this->assertFileDoesNotExist($build->storagePath('oauth-private.key'));

        $worker = $this->bootCloudApplication('database-only-worker', 'pact:work', new MockHandler, 'database', false);
        $this->assertSame('pact/oauth/authorize', $worker['router']->getRoutes()->getByName('pact.oauth.authorize')?->uri());
        $this->assertSame('pact/oauth/select-project', $worker['router']->getRoutes()->getByName('pact.oauth.select-project')?->uri());
        foreach ($files as $path => $bytes) {
            $this->assertSame($bytes, file_get_contents($upgrade->storagePath($path)));
            if ($path === 'app/pact/installation.json') {
                $this->assertSame(json_decode($bytes, true), json_decode(file_get_contents($worker->storagePath($path)), true));
            } else {
                $this->assertSame($bytes, file_get_contents($worker->storagePath($path)));
            }
        }
        $this->assertSame('manuals', $worker['db']->table('pact_client_subjects')->where('id', 42)->value('project_key'));
        $this->assertSame(42, (int) $worker['db']->connection('pact_passport')->table('oauth_access_tokens')->where('id', 'fixture-passport-token')->value('user_id'));
        $sameConnection = Connection::query()->findOrFail($connection->id);
        $this->assertSame('active', $sameConnection->status);
        $this->assertSame($connection->credentials, $sameConnection->credentials);
    }

    private function bootCloudApplication(string $replica, string $command, MockHandler $s3, string $stateStore = 'files', ?bool $restore = null): Application
    {
        $storage = $this->storage.'/'.$replica;
        (new Filesystem)->ensureDirectoryExists($storage.'/framework/views', 0700);
        $database = $this->storage.'/deploy.sqlite';
        if (! is_file($database)) {
            touch($database);
        }
        $_ENV['APP_CONFIG_CACHE'] = $storage.'/config.php';
        $_ENV['APP_ROUTES_CACHE'] = $storage.'/routes.php';
        $_SERVER['argv'] = ['artisan', $command];
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode([[
            'disk' => 'cloud-private', 'bucket' => 'cloud-private', 'url' => null,
            'access_key_id' => 'fixture', 'access_key_secret' => 'fixture',
            'endpoint' => 'https://storage.example.test', 'is_default' => true,
        ]], JSON_THROW_ON_ERROR);

        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->useStoragePath($storage);
        $app->afterBootstrapping(HandleExceptions::class, static function (): void {
            restore_error_handler();
            restore_exception_handler();
        });
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($s3, $database, $storage, $stateStore, $restore): void {
            CloudBootstrapper::configureDisks($app);
            $app['config']->set([
                'app.key' => 'base64:'.base64_encode($this->encrypter->getKey()),
                'app.url' => self::ORIGIN,
                'view.compiled' => $storage.'/framework/views',
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $database,
                'pact.state' => ['restore' => $restore],
                'pact.state_store' => $stateStore,
                'passport.connection' => 'pact_passport',
                'passport.private_key' => null,
                'passport.public_key' => null,
                'filesystems.default' => 'cloud-private',
                'filesystems.disks.cloud-private.handler' => $s3,
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function missingObject(): S3Exception
    {
        return new S3Exception('Object is missing', new Command('GetObject'), [
            'code' => 'NoSuchKey', 'response' => new Response(404),
        ]);
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
