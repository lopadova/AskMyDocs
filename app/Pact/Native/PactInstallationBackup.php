<?php

declare(strict_types=1);

namespace App\Pact\Native;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use RuntimeException;
use SensitiveParameter;

final class PactInstallationBackup
{
    private const INSTALLATION = 'app/pact/installation.json';

    private ?S3ClientInterface $client = null;

    /** @param Closure(): S3ClientInterface $connect */
    public function __construct(
        private readonly Closure $connect,
        private readonly Encrypter $encrypter,
        private readonly string $bucket,
        private readonly string $object,
        private readonly string $storage,
        private readonly string $origin,
        private readonly bool $passportUsesFiles,
    ) {}

    public static function forApplication(Application $app): self
    {
        $config = $app['config'];
        $disk = $config->get('filesystems.disks.'.$config->get('pact.state.disk'), []);
        if (($disk['driver'] ?? null) !== 's3' || empty($disk['bucket'])
            || ! empty($disk['url']) || ($disk['visibility'] ?? null) === 'public') {
            throw new RuntimeException('Configure a dedicated private S3 disk for PACT state.');
        }
        $object = trim(trim((string) ($disk['root'] ?? ''), '/').'/'.trim((string) $config->get('pact.state.object'), '/'), '/');
        if ($object === '' || ! $config->get('pact.state.object')) {
            throw new RuntimeException('Configure the S3 object key for PACT state.');
        }
        $key = (string) $config->get('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }
        // Passport's package config may not have loaded yet during bootstrap.
        $passportPrivate = $config->get('passport.private_key', env('PASSPORT_PRIVATE_KEY'));
        $passportPublic = $config->get('passport.public_key', env('PASSPORT_PUBLIC_KEY'));
        if (filled($passportPrivate) !== filled($passportPublic)) {
            throw new RuntimeException('Configure both Passport environment keys or keep both Passport key files.');
        }
        $connect = static fn () => (new FilesystemManager($app))->build(array_replace($disk, [
            'http' => ['connect_timeout' => 2, 'timeout' => 5],
            'retries' => 1,
        ]))->getClient();

        return new self(
            $connect,
            new Encrypter($key, $config->get('app.cipher')),
            $disk['bucket'],
            $object,
            $app->storagePath(),
            rtrim($config->get('pact.url') ?: $config->get('app.url'), '/'),
            blank($passportPrivate),
        );
    }

    public function backup(): void
    {
        $files = [];
        foreach ($this->paths() as $path) {
            $files[$path] = @file_get_contents($this->storage.'/'.$path);
        }
        $this->validate($files);
        $encrypted = $this->encrypter->encryptString(json_encode(['version' => 1, 'files' => $files], JSON_THROW_ON_ERROR));
        try {
            $this->client()->putObject([
                'Bucket' => $this->bucket, 'Key' => $this->object, 'Body' => $encrypted,
                'ContentType' => 'application/octet-stream', 'IfNoneMatch' => '*',
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() !== 412) {
                throw new RuntimeException('The PACT backup could not be written to S3.');
            }
        }

        if ($this->read() !== $files) {
            throw new RuntimeException('The existing S3 backup belongs to different PACT state.');
        }
    }

    public function restore(): void
    {
        $directory = $this->storage.'/app/pact';
        $marker = $directory.'/restored';
        $source = hash('sha256', json_encode([$this->bucket, $this->object, $this->origin, $this->passportUsesFiles], JSON_THROW_ON_ERROR));
        if ($this->isRestored($marker, $source)) {
            return;
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the PACT installation directory.');
        }
        // The package installer uses the same lock. Publish the installation after its keys.
        $lock = @fopen($directory.'/install.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the PACT installation lock.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the PACT installation.');
            }
            if ($this->isRestored($marker, $source)) {
                return;
            }
            $files = $this->read();
            foreach ($files as $path => $contents) {
                $destination = $this->storage.'/'.$path;
                if (is_file($destination) && @file_get_contents($destination) !== $contents) {
                    throw new RuntimeException('Local PACT state differs from its S3 backup.');
                }
            }
            foreach ($this->paths() as $path) {
                $destination = $this->storage.'/'.$path;
                if (! is_file($destination)) {
                    $this->write($destination, $files[$path]);
                }
            }
            $this->write($marker, $source);
        } finally {
            fclose($lock);
        }
    }

    /** @return list<string> */
    private function paths(): array
    {
        return [
            'app/pact/private.pem',
            'app/pact/public.pem',
            ...($this->passportUsesFiles ? ['oauth-private.key', 'oauth-public.key'] : []),
            self::INSTALLATION,
        ];
    }

    private function isRestored(string $marker, string $source): bool
    {
        clearstatcache();
        if (! is_file($marker) || @file_get_contents($marker) !== $source) {
            return false;
        }
        foreach ($this->paths() as $path) {
            if (! is_readable($this->storage.'/'.$path)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function read(): array
    {
        try {
            $response = $this->client()->getObject(['Bucket' => $this->bucket, 'Key' => $this->object]);
            $payload = json_decode($this->encrypter->decryptString((string) $response['Body']), true);
        } catch (S3Exception|DecryptException) {
            throw new RuntimeException('The PACT backup could not be read or decrypted. Verify the private disk and APP_KEY.');
        }
        if (($payload['version'] ?? null) !== 1 || ! is_array($payload['files'] ?? null)) {
            throw new RuntimeException('The PACT backup format is invalid.');
        }
        $this->validate($payload['files']);

        return $payload['files'];
    }

    private function client(): S3ClientInterface
    {
        return $this->client ??= ($this->connect)();
    }

    private function validate(#[SensitiveParameter] array $files): void
    {
        $paths = $this->paths();
        if (array_diff(array_keys($files), $paths) || array_diff($paths, array_keys($files))) {
            throw new RuntimeException('The PACT backup contains an unexpected set of files.');
        }
        foreach ($files as $contents) {
            if (! is_string($contents) || $contents === '') {
                throw new RuntimeException('The existing PACT installation or key files are missing.');
            }
        }
        $installation = json_decode($files[self::INSTALLATION], true);
        if (($installation['role'] ?? null) !== 'client' || ($installation['url'] ?? null) !== $this->origin
            || ! is_string($installation['product_id'] ?? null) || $installation['product_id'] === '') {
            throw new RuntimeException('The PACT installation does not identify this client origin.');
        }
        $this->validateKeyPair($files['app/pact/private.pem'], $files['app/pact/public.pem']);
        if ($this->passportUsesFiles) {
            $this->validateKeyPair($files['oauth-private.key'], $files['oauth-public.key']);
        }
    }

    private function validateKeyPair(#[SensitiveParameter] string $private, string $public): void
    {
        $privateKey = openssl_pkey_get_private($private);
        $publicKey = openssl_pkey_get_public($public);
        if ($privateKey === false || $publicKey === false
            || openssl_pkey_get_details($privateKey)['key'] !== openssl_pkey_get_details($publicKey)['key']) {
            throw new RuntimeException('The existing PACT or Passport signing key pair is invalid.');
        }
    }

    private function write(string $path, #[SensitiveParameter] string $contents): void
    {
        $temporary = tempnam(dirname($path), '.pact-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot stage a PACT state file.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Cannot restore a PACT state file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
