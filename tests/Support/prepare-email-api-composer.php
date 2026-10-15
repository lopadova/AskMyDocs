<?php

declare(strict_types=1);

// Generate an isolated Composer manifest; never commit machine-local paths.
$root = dirname(__DIR__, 2);
$options = getopt('', ['gmail:', 'microsoft:']);
$packages = ['padosoft/askmydocs-connector-gmail' => $options['gmail'] ?? null,
    'padosoft/askmydocs-connector-microsoft-mail' => $options['microsoft'] ?? null];
$manifest = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$repositories = [];
foreach ($packages as $name => $path) {
    if (! is_string($path) || ($path = realpath($path)) === false || ! is_file($path.'/composer.json')) {
        throw new RuntimeException('Supply --gmail and --microsoft with existing local package repositories.');
    }
    $package = json_decode(file_get_contents($path.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($package['name'] ?? null) !== $name) {
        throw new RuntimeException('Local email repository does not match the expected Composer package.');
    }
    $manifest['require'][$name] = '0.1.0';
    // This version is an isolated test alias, never a claimed remote release.
    $repositories[] = ['type' => 'path', 'url' => $path, 'options' => ['symlink' => false, 'versions' => [$name => '0.1.0']]];
}
$manifest['repositories'] = array_merge($repositories, $manifest['repositories'] ?? []);
if (file_put_contents($root.'/composer.email-local.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false
    || ! copy($root.'/composer.lock', $root.'/composer.email-local.lock')) {
    throw new RuntimeException('Unable to prepare the isolated email Composer manifest.');
}
echo "Prepared composer.email-local.json and lock. Run the targeted update in the isolated worktree.\n";
