<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\ModelPrice;

/** Read-only current-price estimates. Never fetch a feed on the dashboard path. */
final class AiUsagePriceCatalog
{
    private array $feeds = [];
    private array $overrides = [];
    private array $sourcesUsed = [];

    public function load(array $models): void
    {
        $this->feeds = $this->overrides = $this->sourcesUsed = [];
        foreach ((array) config('ai-finops.pricing.sources', []) as $name) {
            if (! in_array($name, ['litellm', 'openrouter'], true) || ! config('ai-finops.pricing.'.$name.'.enabled', false)) {
                continue;
            }
            try {
                $prices = Cache::get('ai-finops:pricing:'.$name);
                $syncedAt = Cache::get('ai-finops:pricing:'.$name.':synced_at');
                $timestamp = is_string($syncedAt) ? \Carbon\CarbonImmutable::parse($syncedAt)->getTimestamp() : null;
            } catch (\Throwable) {
                continue;
            }
            if (is_array($prices) && is_string($syncedAt)) {
                $this->feeds[$name] = ['prices' => $prices, 'synced_at' => $syncedAt, 'timestamp' => $timestamp];
            }
        }
        // Manual prices are a global catalog, not tenant activity. Load only the
        // bounded set of model identities from this tenant's aggregated report.
        try {
            foreach (array_chunk(array_unique($models), 400) as $chunk) {
                $rows = PricingOverride::query()->whereIn('model', $chunk)
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                    ->orderByDesc('updated_at')->get();
                foreach ($rows as $row) {
                    $this->overrides[$row->provider ?? ''][$row->model] ??= $row;
                }
            }
        } catch (\Illuminate\Database\QueryException) {
            // A missing optional override table does not hide cached feed prices.
        }
    }

    public function estimate(string $provider, string $model, TokenUsage $tokens, string $currency): ?float
    {
        $override = $this->overrides[$provider][$model] ?? $this->overrides[''][$model] ?? null;
        $mapped = config('ai-finops.pricing.provider_source_map.'.$provider);
        $feed = null;
        if ($mapped !== null && $mapped !== 'manual') {
            $feed = isset($this->feeds[$mapped]['prices'][$model]) ? $mapped : null;
        } elseif ($mapped === null) {
            // Same freshest-source, then configured precedence rule as FinOps.
            $order = array_flip((array) config('ai-finops.pricing.default_winner', []));
            $names = array_keys(array_filter($this->feeds, fn ($f) => isset($f['prices'][$model])));
            usort($names, fn ($a, $b) => ($this->feeds[$b]['timestamp'] <=> $this->feeds[$a]['timestamp'])
                ?: (($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX)));
            $feed = $names[0] ?? null;
        }
        if ($override !== null && (config('ai-finops.pricing.overrides_win', true) || $feed === null || $mapped === 'manual')) {
            if (($tokens->input > 0 && $override->input_cost_per_token === null)
                || ($tokens->output > 0 && $override->output_cost_per_token === null)) {
                return null;
            }
            $price = $override->toModelPrice();
            $source = 'manual';
            $updatedAt = $override->updated_at?->toIso8601String();
        } elseif ($feed !== null) {
            $rates = $this->feeds[$feed]['prices'][$model];
            foreach (['input_cost_per_token' => $tokens->input, 'output_cost_per_token' => $tokens->output] as $key => $quantity) {
                if ($quantity > 0 && (! isset($rates[$key]) || ! is_numeric($rates[$key]) || ! is_finite((float) $rates[$key]) || (float) $rates[$key] < 0)) {
                    return null;
                }
            }
            $price = ModelPrice::fromLiteLLM($model, $rates, $feed);
            $source = $feed;
            $updatedAt = $this->feeds[$feed]['synced_at'];
        } else {
            return null;
        }
        if ($price->currency !== $currency || ! is_finite($price->inputPerToken) || ! is_finite($price->outputPerToken)
            || $price->inputPerToken < 0 || $price->outputPerToken < 0 || ($price->cacheReadPerToken !== null && (! is_finite($price->cacheReadPerToken) || $price->cacheReadPerToken < 0))) {
            return null;
        }
        $cost = (new CostCalculator)->cost($tokens, $price, $currency)->total;
        if (! is_finite($cost)) {
            return null;
        }
        $this->sourcesUsed[$source] = $updatedAt;

        return $cost;
    }

    public function sourcesUsed(): array
    {
        return $this->sourcesUsed;
    }
}
