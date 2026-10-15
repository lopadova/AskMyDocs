<?php

declare(strict_types=1);

namespace App\Connectors;

use Composer\InstalledVersions;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use RuntimeException;

/** Supplement the normal lockfile discovery for isolated local Composer manifests. */
final class EmailApiPackageDiscovery
{
    public static function extend(ConnectorRegistry $registry, $app): ConnectorRegistry
    {
        $classes = [];
        foreach (['padosoft/askmydocs-connector-gmail' => 'gmail',
            'padosoft/askmydocs-connector-microsoft-mail' => 'microsoft-mail'] as $package => $key) {
            if (! InstalledVersions::isInstalled($package) || $registry->has($key)) {
                continue;
            }
            $path = InstalledVersions::getInstallPath($package);
            if (! is_string($path) || ! is_file($path.'/composer.json')) {
                throw new RuntimeException('Installed email package metadata is unavailable.');
            }
            $manifest = json_decode(file_get_contents($path.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['name'] ?? null) !== $package || ! is_array($manifest['extra']['askmydocs']['connectors'] ?? null)) {
                throw new RuntimeException('Installed email package has invalid discovery metadata.');
            }
            $classes = array_merge($classes, $manifest['extra']['askmydocs']['connectors']);
        }
        if ($classes === []) {
            return $registry;
        }
        $config = (array) config('connectors', []);
        $config['built_in'] = array_merge($config['built_in'] ?? [], $classes);

        // Registry validates every FQCN/contract and still discovers all normal packages.
        return new ConnectorRegistry($app, $config);
    }
}
