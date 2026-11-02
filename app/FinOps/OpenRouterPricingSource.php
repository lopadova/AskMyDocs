<?php

declare(strict_types=1);

namespace App\FinOps;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Padosoft\LaravelAiFinOps\Pricing\OpenRouterPricingSource as PackageSource;

/** The billing catalog includes embeddings, which have a separate public feed. */
final class OpenRouterPricingSource extends PackageSource
{
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {
        parent::__construct($http, $cache, $config);
    }

    public function sync(): int
    {
        if (! $this->config->get('ai-finops.pricing.openrouter.enabled', true)) {
            return 0;
        }
        if (! $this->config->get('ai-finops.pricing.openrouter.allow_keyless', true)
            && ! $this->config->get('ai-finops.pricing.openrouter.key')) {
            return 0;
        }

        // Commit both catalogs together; a failed refresh keeps the last good one.
        try {
            $map = [];
            foreach (['url' => 'chat', 'embeddings_url' => 'embedding'] as $key => $mode) {
                $url = $this->config->get('ai-finops.pricing.openrouter.'.$key);
                if (! is_string($url) || $url === '') {
                    return 0;
                }
                $rows = $this->http->acceptJson()->connectTimeout(3)->timeout(12)
                    ->withoutRedirecting()->get($url)->throw()->json('data');
                if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
                    return 0;
                }
                $validPrices = 0;
                foreach ($rows as $row) {
                    if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                        continue;
                    }
                    $pricing = $row['pricing'] ?? [];
                    $attributes = ['mode' => $mode];
                    foreach (['prompt' => 'input_cost_per_token', 'completion' => 'output_cost_per_token',
                        'input_cache_read' => 'cache_read_input_token_cost', 'input_cache_write' => 'cache_creation_input_token_cost'] as $field => $attribute) {
                        if (isset($pricing[$field]) && is_numeric($pricing[$field]) && is_finite((float) $pricing[$field]) && (float) $pricing[$field] >= 0) {
                            $attributes[$attribute] = (float) $pricing[$field];
                        }
                    }
                    // Never turn a missing tariff (or an automatic router's -1) into free usage.
                    if (! isset($attributes['input_cost_per_token']) || ($mode === 'chat' && ! isset($attributes['output_cost_per_token']))) {
                        continue;
                    }
                    $attributes['output_cost_per_token'] ??= 0.0;
                    $map[$row['id']] = $attributes;
                    $validPrices++;
                }
                if ($validPrices === 0) {
                    return 0;
                }
            }
            if ($map === []) {
                return 0;
            }
            $this->cache->forever('ai-finops:pricing:openrouter', $map);
            $this->cache->forever('ai-finops:pricing:openrouter:synced_at', now()->toIso8601String());

            return count($map);
        } catch (\Throwable) {
            return 0;
        }
    }
}
