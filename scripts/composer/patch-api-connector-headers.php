<?php

declare(strict_types=1);

// connector-api beta.5 persists shared headers but omits them from its request
// planner. Keep this narrow patch reproducible until the package ships the fix.
$packagePath = $argv[1] ?? __DIR__.'/../../vendor/padosoft/askmydocs-connector-api';
$changes = [
    'src/Support/RequestPlanner.php' => [
        '        $headers = [];' => '        $headers = array_change_key_case($route->connector?->sharedHeaders() ?? [], CASE_LOWER);',
        'ParamLocation::Header => $headers[$name] = (string) $coerced,' => 'ParamLocation::Header => $headers[strtolower($name)] = (string) $coerced,',
    ],
    'src/Support/HttpDispatcher.php' => [
        '        $headers = array_merge($plan->headers, $material->headers);' => <<<'PHP'
        $headers = array_merge(
            array_change_key_case($plan->headers, CASE_LOWER),
            array_change_key_case($material->headers, CASE_LOWER),
        );
PHP,
    ],
];

try {
    $patched = [];
    foreach ($changes as $relativePath => $replacements) {
        $path = $packagePath.'/'.$relativePath;
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Cannot read API connector source: '.$relativePath);
        }

        foreach ($replacements as $before => $after) {
            if (str_contains($source, $after)) {
                continue;
            }
            if (substr_count($source, $before) !== 1) {
                throw new RuntimeException('API connector source changed; review the shared-header patch: '.$relativePath);
            }
            $source = str_replace($before, $after, $source);
        }
        $patched[$path] = $source;
    }

    // Validate every replacement before changing either file.
    foreach ($patched as $path => $source) {
        if (file_get_contents($path) !== $source && file_put_contents($path, $source) === false) {
            throw new RuntimeException('Cannot write API connector shared-header patch.');
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
