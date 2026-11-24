<?php

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| Closure-based artisan commands go here. All formal commands live in
| app/Console/Commands and are registered by AppServiceProvider.
|
*/

use App\Pact\Native\PactInstallationBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PactLaravel\Support\Installation;

Artisan::command('pact-state:backup', function (): void {
    PactInstallationBackup::forApplication(app())->backup();
    $this->info('The encrypted PACT installation was verified in S3.');
})->purpose('Save the client installation and signing keys to its private S3 disk');

Artisan::command('pact-client:deploy {--prepare-only : Prepare the identity before host migrations}', function (): int {
    if (! PactInstallationBackup::enabled(app())) {
        return Command::SUCCESS;
    }

    $state = PactInstallationBackup::forApplication(app());
    if (! $state->restoreIfPresent()) {
        if (Installation::load() === []) {
            $schema = DB::connection(config('pact.database_connection'))->getSchemaBuilder();
            if ($schema->hasTable('pact_connections')) {
                $this->error('The PACT database already exists. Restore the original installation files or S3 bundle.');

                return Command::FAILURE;
            }
            app(Installation::class)->install('client', []);
        }
        $state->backup();
        $this->info('The encrypted PACT installation was verified in S3.');
    }

    if ($this->option('prepare-only')) {
        return Command::SUCCESS;
    }

    $installed = $this->call('pact:install', ['--no-interaction' => true]);
    if ($installed !== 0) {
        return $installed;
    }

    return $this->call('migrate', [
        '--path' => ['vendor/laravel/passport/database/migrations'],
        '--force' => true,
        '--no-interaction' => true,
    ]);
})->purpose('Persist the PACT installation and update its database schema');
