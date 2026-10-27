<?php

declare(strict_types=1);

// connector-api beta.5 accepts JSON only. Keep XML normalization explicit per
// route and identical in connection tests and tool execution until upstreamed.
$packagePath = $argv[1] ?? __DIR__.'/../../vendor/padosoft/askmydocs-connector-api';
$changes = [
    'src/Services/ApiToolExecutor.php' => [
        <<<'BEFORE'
            $decoded = json_decode($response->body(), true);
            $isJson = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
BEFORE
        => <<<'AFTER'
            $decoded = app(\App\ApiConnectors\StructuredApiResponseDecoder::class)->decode($response->body(), $route->output_transform);
            $isJson = is_array($decoded);
AFTER,
        <<<'BEFORE'
            if (! $isJson) {
                $error = 'Endpoint returned a non-JSON response (unsupported in Fase 1).';
                $this->log($route, $context, $plan, $response->status(), null, $latencyMs, $error);

                return ['error' => $error, 'status' => $response->status()];
            }
BEFORE
        => <<<'AFTER'
            if (! $isJson) {
                $failure = app(\App\ApiConnectors\ApiResponseDiagnostics::class)->formatMismatch(
                    $response->status(), $response->body(), $response->header('Content-Type'), $route->output_transform,
                );
                $this->log($route, $context, $plan, $response->status(), $failure, $latencyMs, $failure['error']);

                return $failure;
            }
AFTER,
    ],
    'src/Services/ApiRouteTester.php' => [
        'return $this->execute($plan, $material, $timeoutMs);' => 'return $this->execute($plan, $material, $timeoutMs, $route->output_transform);',
        'private function execute(RequestPlan $plan, AuthMaterial $material, int $timeoutMs): TestResult' => 'private function execute(RequestPlan $plan, AuthMaterial $material, int $timeoutMs, ?array $outputTransform = null): TestResult',
        <<<'BEFORE'
            $decoded = json_decode($raw, true);
            $isJson = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
BEFORE
        => <<<'AFTER'
            $decoded = app(\App\ApiConnectors\StructuredApiResponseDecoder::class)->decode($raw, $outputTransform);
            $isJson = is_array($decoded);
AFTER,
    ],
];
try {
    $patched = [];
    foreach ($changes as $relative => $replacements) {
        $path = $packagePath.'/'.$relative;
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Cannot read connector source: '.$relative);
        }
        foreach ($replacements as $before => $after) {
            if (str_contains($source, $after)) {
                continue;
            }
            if (substr_count($source, $before) !== 1) {
                throw new RuntimeException('Connector source changed; review structured-response patch: '.$relative);
            }
            $source = str_replace($before, $after, $source);
        }
        $patched[$path] = $source;
    }
    foreach ($patched as $path => $source) {
        if (file_get_contents($path) !== $source && file_put_contents($path, $source) === false) {
            throw new RuntimeException('Cannot write structured-response patch.');
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
