<?php

declare(strict_types=1);

// Prepares an ignored Composer override; dependency installation remains explicit.
$root = dirname(__DIR__);
$package = realpath($argv[1] ?? dirname($root, 2).'/packages/askmydocs-connector-freshdesk');
if ($package === false || ! is_file($package.'/composer.json')) {
    throw new RuntimeException('Freshdesk package directory was not found.');
}
$name = 'padosoft/askmydocs-connector-freshdesk';
$packageManifest = json_decode(file_get_contents($package.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
if (($packageManifest['name'] ?? null) !== $name) {
    throw new RuntimeException('The directory does not contain the Freshdesk connector package.');
}
$local = $root.'/composer.local.json';
$manifest = json_decode(file_get_contents(is_file($local) ? $local : $root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest['require'][$name] = 'dev-main';
$repositories = $manifest['repositories'] ?? [];
$repository = ['type' => 'path', 'url' => $package, 'options' => ['symlink' => true, 'versions' => [$name => 'dev-main']]];
$repositories = array_values(array_filter($repositories, static fn (array $candidate): bool => ($candidate['type'] ?? null) !== 'path' ||
    realpath($root.'/'.($candidate['url'] ?? '')) !== $package && realpath($candidate['url'] ?? '') !== $package
));
array_unshift($repositories, $repository);
$manifest['repositories'] = $repositories;
file_put_contents($local, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
if (! is_file($root.'/composer.local.lock')) {
    copy($root.'/composer.lock', $root.'/composer.local.lock');
}
$exclude = $root.'/.git/info/exclude';
if (is_file($exclude)) {
    $existing = file_get_contents($exclude);
    foreach (['/composer.local.json', '/composer.local.lock', '/public/connectors/freshdesk.svg'] as $pattern) {
        if (! in_array($pattern, explode("\n", $existing), true)) {
            file_put_contents($exclude, "\n".$pattern."\n", FILE_APPEND);
        }
    }
}
echo "Prepared composer.local.json. Install with:\nCOMPOSER=composer.local.json composer update {$name} --no-interaction\n";
