<?php

declare(strict_types=1);

// Installs the sibling checkout without changing the application's production lock.
$root = dirname(__DIR__);
$manifest = $root.'/composer.pact.json';
$lock = $root.'/composer.pact.lock';
if (file_exists($manifest) || file_exists($lock)) throw new RuntimeException('Temporary Composer files already exist.');
$data = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$data['require']['padosoft/laravel-pact-agent'] = 'dev-main';
$paths = [
    ['padosoft/laravel-pact-agent', $root.'/../laravel-pact-agent', 'dev-main'],
    ['pact-protocol/core', $root.'/../Pact/sdk/php', '0.2.0'],
    ['pact-protocol/laravel', $root.'/../Pact/sdk/laravel', '0.2.0'],
];
$data['repositories'] = array_map(static function (array $path): array {
    if (! is_file($path[1].'/composer.json')) throw new RuntimeException('Missing local package: '.$path[1]);
    return ['type' => 'path', 'url' => $path[1], 'options' => ['symlink' => true, 'versions' => [$path[0] => $path[2]]]];
}, $paths);
$data['repositories'][] = ['packagist.org' => false];
file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
copy($root.'/composer.lock', $lock);
try {
    $command = ['composer', 'update', 'padosoft/laravel-pact-agent', 'pact-protocol/core', 'pact-protocol/laravel', '--no-scripts', '--no-interaction', '--no-progress'];
    $env = array_merge($_ENV, ['PATH' => getenv('PATH'), 'COMPOSER' => 'composer.pact.json', 'COMPOSER_DISABLE_NETWORK' => '1']);
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, $env);
    if (! is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Local Composer update failed.');
} finally {
    @unlink($manifest); @unlink($lock);
}
