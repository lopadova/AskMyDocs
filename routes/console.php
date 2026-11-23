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

Artisan::command('pact-state:backup', function (): void {
    PactInstallationBackup::forApplication(app())->backup();
    $this->info('The encrypted PACT installation was verified in S3.');
})->purpose('Save the client installation and signing keys to its private S3 disk');

Artisan::command('pact-client:deploy', function (): int {
    if (! config('pact.state.restore')) {
        return Command::SUCCESS;
    }

    $installed = $this->call('pact:install', ['--role' => 'client', '--no-interaction' => true]);
    if ($installed !== 0) {
        return $installed;
    }

    return $this->call('migrate', [
        '--path' => ['vendor/laravel/passport/database/migrations'],
        '--force' => true,
        '--no-interaction' => true,
    ]);
})->purpose('Update the restored PACT client and its isolated Passport schema');
