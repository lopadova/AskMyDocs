<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class DevelopDeployScriptTest extends TestCase
{
    private string $temporaryDirectory;

    private string $commandLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/askmydocs-deploy-'.bin2hex(random_bytes(8));
        if (! mkdir($this->temporaryDirectory, 0700, true) && ! is_dir($this->temporaryDirectory)) {
            throw new RuntimeException('Unable to create the deploy-script test directory.');
        }

        $fakePhp = $this->temporaryDirectory.'/php';
        $written = file_put_contents(
            $fakePhp,
            <<<'BASH'
#!/usr/bin/env bash
set -eu

if [[ "${1:-}" == "scripts/deploy/resolve-laravel-environment.php" ]]; then
    printf '%s\t%s\t%s\n' \
        "${DEVELOP_DEPLOY_TEST_CONFIG_ENABLED:-false}" \
        "${DEVELOP_DEPLOY_TEST_CONFIG_APP_ENV:-production}" \
        "${DEVELOP_DEPLOY_TEST_CONFIG_PASSWORD_LENGTH:-0}"
    exit 0
fi

printf '%s\n' "$*" >> "$DEVELOP_DEPLOY_TEST_LOG"
BASH
            ."\n",
        );
        if ($written === false || ! chmod($fakePhp, 0700)) {
            throw new RuntimeException('Unable to create the fake PHP executable.');
        }

        $this->commandLog = $this->temporaryDirectory.'/commands.log';
    }

    protected function tearDown(): void
    {
        foreach ([$this->commandLog, $this->temporaryDirectory.'/php'] as $path) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException("Unable to remove test artifact [{$path}].");
            }
        }

        if (is_dir($this->temporaryDirectory) && ! rmdir($this->temporaryDirectory)) {
            throw new RuntimeException('Unable to remove the deploy-script test directory.');
        }

        parent::tearDown();
    }

    public function test_a_regular_commit_runs_only_pending_migrations(): void
    {
        $process = $this->runScript(
            'feat: ordinary develop change',
            appEnvironment: 'production',
            enabled: 'false',
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['artisan migrate --force --no-interaction'],
            $this->loggedCommands(),
        );
    }

    public function test_reset_and_seed_directives_run_in_the_required_order(): void
    {
        $process = $this->runScript(
            'chore: rebuild fixtures [RESET-DATABASE] [init-seed]',
            appEnvironment: 'staging',
            enabled: 'true',
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'artisan migrate:fresh --force --no-interaction',
            'artisan db:seed --class=Database\Seeders\DevelopSeeder --force --no-interaction',
        ], $this->loggedCommands());
    }

    public function test_init_seed_without_reset_migrates_before_seeding(): void
    {
        $process = $this->runScript(
            'test: refresh fixture accounts [init-seed]',
            appEnvironment: 'develop',
            enabled: 'yes',
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'artisan migrate --force --no-interaction',
            'artisan db:seed --class=Database\Seeders\DevelopSeeder --force --no-interaction',
        ], $this->loggedCommands());
    }

    public function test_destructive_directives_are_rejected_in_production(): void
    {
        $process = $this->runScript(
            'chore: never run here [reset-database] [init-seed]',
            appEnvironment: 'production',
            enabled: 'true',
        );

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString(
            'Refusing develop deployment directives for APP_ENV=production',
            $process->getErrorOutput(),
        );
        self::assertSame([], $this->loggedCommands());
    }

    public function test_directives_are_rejected_without_the_cloud_opt_in(): void
    {
        $process = $this->runScript(
            'chore: reset [reset-database]',
            appEnvironment: 'staging',
            enabled: 'false',
        );

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString(
            'DEVELOP_DEPLOY_ENABLED is not true',
            $process->getErrorOutput(),
        );
        self::assertSame([], $this->loggedCommands());
    }

    public function test_it_resolves_laravel_config_when_cloud_does_not_export_custom_variables(): void
    {
        $process = $this->runScript(
            'chore: rebuild fixtures [reset-database] [init-seed]',
            appEnvironment: false,
            enabled: false,
            password: false,
            configEnvironment: 'staging',
            configEnabled: 'true',
            configPasswordLength: '32',
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'artisan migrate:fresh --force --no-interaction',
            'artisan db:seed --class=Database\Seeders\DevelopSeeder --force --no-interaction',
        ], $this->loggedCommands());
    }

    private function runScript(
        string $commitMessage,
        string|false $appEnvironment,
        string|false $enabled,
        string|false $password = 'DevelopOnly!2026',
        string $configEnvironment = 'production',
        string $configEnabled = 'false',
        string $configPasswordLength = '0',
    ): Process {
        $root = dirname(__DIR__, 3);
        $process = new Process(
            ['bash', $root.'/scripts/deploy/laravel-cloud-develop.sh'],
            $root,
            [
                'APP_ENV' => $appEnvironment,
                'DEVELOP_DEPLOY_ENABLED' => $enabled,
                'DEVELOP_SEED_PASSWORD' => $password,
                'DEPLOY_COMMIT_MESSAGE' => $commitMessage,
                'DEVELOP_DEPLOY_TEST_LOG' => $this->commandLog,
                'DEVELOP_DEPLOY_TEST_CONFIG_APP_ENV' => $configEnvironment,
                'DEVELOP_DEPLOY_TEST_CONFIG_ENABLED' => $configEnabled,
                'DEVELOP_DEPLOY_TEST_CONFIG_PASSWORD_LENGTH' => $configPasswordLength,
                'PATH' => $this->temporaryDirectory.PATH_SEPARATOR.(string) getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    }

    /**
     * @return list<string>
     */
    private function loggedCommands(): array
    {
        if (! is_file($this->commandLog)) {
            return [];
        }

        $lines = file($this->commandLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read the fake PHP command log.');
        }

        return array_values($lines);
    }
}
