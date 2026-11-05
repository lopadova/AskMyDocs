<?php

declare(strict_types=1);
namespace App\ApiTools;

use Illuminate\Validation\ValidationException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

final class ConnectorSecretGuard
{
    public static function sensitive(string $name): bool
    {
        return preg_match('/authorization|cookie|password|secret|token|api.?key|credential/i', $name) === 1;
    }

    /** Reject credential material copied into definitions, fixtures or portable prose. */
    public function assertClean(array $data, iterable $routes): void
    {
        $secrets = [];
        foreach ($routes as $route) {
            if (! $route instanceof ApiRoute) { continue; }
            $credentials = $route->effectiveAuthProfile()?->credentials ?? [];
            array_walk_recursive($credentials, function ($v) use (&$secrets): void { if (is_string($v) && $v !== '') { $secrets[] = $v; } });
            foreach ($route->connector?->headers ?? [] as $key => $value) {
                if (self::sensitive($key) && is_string($value) && $value !== '') { $secrets[] = $value; }
            }
            foreach ($route->parameters as $parameter) {
                if (($parameter->source->value === 'secret' || self::sensitive($parameter->name)) && is_string($parameter->value) && $parameter->value !== '') { $secrets[] = $parameter->value; }
            }
        }
        array_walk_recursive($data, function ($v, $key) use ($secrets): void {
            if (! is_string($v)) { return; }
            foreach (array_unique($secrets) as $secret) {
                if ($v === $secret || (strlen($secret) >= 4 && str_contains($v, $secret))) {
                    throw ValidationException::withMessages(['definition' => 'Credential material must remain in its connector.']);
                }
            }
        });
    }

    public function mask(mixed $value): mixed
    {
        if (! is_array($value)) { return is_string($value) && strlen($value) > 1000 ? substr($value, 0, 1000).'…' : $value; }
        $out = [];
        foreach ($value as $key => $item) { $out[$key] = self::sensitive((string) $key) ? '[redacted]' : $this->mask($item); }
        return $out;
    }
}
