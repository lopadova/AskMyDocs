<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$environment = (string) config('app.env', '');
if ($environment === '' || preg_match('/[\t\r\n]/', $environment) === 1) {
    fwrite(STDERR, "Unable to resolve a safe Laravel application environment.\n");
    exit(1);
}

$enabled = (bool) config('develop-deploy.enabled', false);
$passwordLength = mb_strlen((string) config('develop-deploy.seed.password', ''));

printf(
    "%s\t%s\t%d\n",
    $enabled ? 'true' : 'false',
    $environment,
    $passwordLength,
);
