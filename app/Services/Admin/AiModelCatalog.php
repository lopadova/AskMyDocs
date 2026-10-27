<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;

/** Provider availability plus attributed prices; never guesses a missing price. */
class AiModelCatalog
{
    public const PROVIDERS = ['openai', 'anthropic', 'gemini', 'openrouter', 'regolo'];

    public function __construct(private readonly PricingSourceManager $prices) {}

    public function get(string $provider, string $tenant, bool $refresh = false): array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new \InvalidArgumentException('Unknown model provider.');
        }
        $config = config('ai.providers.'.$provider, []);
        $base = rtrim((string) ($config['url'] ?? ''), '/');
        $credential = (string) ($config['key'] ?? '');
        $key = 'settings:model-catalog:v1:'.hash_hmac('sha256', $tenant.'|'.$provider.'|'.$base.'|'.$credential, (string) config('app.key'));
        $cached = Cache::get($key);
        $header = ['provider' => $provider, 'configured' => $credential !== '',
            'default_model' => data_get($config, 'models.text.default'), 'models' => [], 'updated_at' => null, 'stale' => false, 'error' => null];
        if (! $refresh && is_array($cached) && strtotime($cached['updated_at'] ?? '') > now()->getTimestamp() - 3600) {
            return $cached;
        }
        if ($credential === '' && ! in_array($provider, ['openrouter', 'regolo'], true)) {
            return [...$header, 'error' => 'missing_credentials'];
        }
        try {
            $rows = $this->fetch($provider, $base, $credential);
            $feed = $provider === 'openrouter' || $provider === 'regolo' ? null : $this->prices->source('litellm');
            try {
                if ($feed !== null && ($refresh || $feed->syncedAt() === null || $feed->syncedAt()->getTimestamp() < now()->getTimestamp() - 21600)) {
                    $feed->sync();
                }
            } catch (\Throwable) { /* Retain the last known prices and their original timestamp. */ }
            try {
                $rates = $feed?->all() ?? [];
                $rateAt = $feed?->syncedAt()?->format(DATE_ATOM);
            } catch (\Throwable) {
                $rates = [];
                $rateAt = null;
            }
            $overrides = [];
            try {
                foreach (PricingOverride::query()->where(fn ($query) => $query->where('provider', $provider)->orWhereNull('provider'))
                    ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))->get() as $override) {
                    if (! isset($overrides[$override->model]) || $override->provider === $provider) {
                        $overrides[$override->model] = ['price' => $override->toModelPrice(), 'updated_at' => $override->updated_at?->toIso8601String()];
                    }
                }
            } catch (\Throwable) { /* Catalog availability does not require a migrated FinOps override table. */ }
            $models = [];
            foreach ($rows as $row) {
                $model = $this->normalize($provider, $row, $rates, $rateAt);
                if ($model === null) {
                    continue;
                }
                if (isset($overrides[$model['id']]) && config('ai-finops.pricing.overrides_win', true)) {
                    $price = $overrides[$model['id']]['price'];
                    $model = [...$model, 'input_per_million' => $this->price($price->inputPerToken),
                        'output_per_million' => $this->price($price->outputPerToken), 'currency' => $price->currency,
                        'price_source' => 'manual', 'price_updated_at' => $overrides[$model['id']]['updated_at']];
                }
                $models[$model['id']] = $model;
            }
            if ($models === []) {
                throw new \RuntimeException('Empty text model catalog.');
            }
            uasort($models, fn ($a, $b) => ($b['created'] <=> $a['created']) ?: strnatcasecmp($a['name'], $b['name']));
            $result = [...$header, 'models' => array_values($models), 'updated_at' => now()->toIso8601String()];
            Cache::put($key, $result, now()->addDays(7));
            return $result;
        } catch (\Throwable $exception) {
            // Do not expose provider exceptions, headers, account data or credentials.
            $error = $exception instanceof \Illuminate\Http\Client\RequestException && in_array($exception->response->status(), [401, 403], true)
                ? 'credentials_rejected' : 'catalog_unavailable';
            return [...(is_array($cached) ? $cached : $header), 'stale' => true, 'error' => $error];
        }
    }

    private function fetch(string $provider, string $base, string $credential): array
    {
        $url = $provider === 'regolo'
            ? preg_replace('~/v\d+/?$~', '', $base).'/model_group/info'
            : $base.'/models';
        $all = [];
        $cursor = null;
        for ($page = 0; $page < 5; $page++) {
            $request = Http::acceptJson()->connectTimeout(3)->timeout(12)->withoutRedirecting();
            $query = [];
            if ($provider === 'anthropic') {
                $request = $request->withHeaders(['x-api-key' => $credential, 'anthropic-version' => config('ai.providers.anthropic.api_version', '2023-06-01')]);
                $query = array_filter(['limit' => 1000, 'after_id' => $cursor]);
            } elseif ($provider === 'gemini') {
                $request = $request->withHeaders(['x-goog-api-key' => $credential]);
                $query = array_filter(['pageSize' => 1000, 'pageToken' => $cursor]);
            } elseif ($credential !== '') {
                $request = $request->withToken($credential);
            }
            $response = $request->get($url, $query)->throw();
            $rows = $response->json($provider === 'gemini' ? 'models' : 'data');
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new \RuntimeException('Invalid catalog response.');
            }
            array_push($all, ...array_filter($rows, 'is_array'));
            $next = $provider === 'gemini' ? $response->json('nextPageToken')
                : ($provider === 'anthropic' && $response->json('has_more') ? $response->json('last_id') : null);
            if (! is_string($next) || $next === '') {
                return $all;
            }
            if ($next === $cursor) {
                throw new \RuntimeException('Repeated catalog cursor.');
            }
            $cursor = $next;
        }
        throw new \RuntimeException('Catalog page limit reached.');
    }

    private function normalize(string $provider, array $row, array $rates, ?string $rateAt): ?array
    {
        $id = $row[$provider === 'regolo' ? 'model_group' : ($provider === 'gemini' ? 'name' : 'id')] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 200) {
            return null;
        }
        $id = $provider === 'gemini' ? preg_replace('~^models/~', '', $id) : $id;
        $rate = $rates[$provider.'/'.$id] ?? $rates[$id] ?? [];
        if ($provider === 'openrouter' && ! in_array('text', data_get($row, 'architecture.output_modalities', ['text']), true)) {
            return null;
        }
        if ($provider === 'regolo' && ($row['mode'] ?? 'chat') !== 'chat') {
            return null;
        }
        if ($provider === 'gemini' && ! in_array('generateContent', $row['supportedGenerationMethods'] ?? [], true)) {
            return null;
        }
        if ($provider === 'openai' && (($rate['mode'] ?? '') !== 'chat'
            && (! preg_match('/^(gpt-|chatgpt-|o\d)/', $id) || preg_match('/(audio|realtime|transcrib|tts|image|embedding)/i', $id)))) {
            return null;
        }
        if (($row['lifecycle'] ?? '') === 'retired'
            || (is_string($row['shutdown_date'] ?? null) && $row['shutdown_date'] <= now()->toDateString())) {
            return null;
        }
        $pricing = $provider === 'openrouter' ? ($row['pricing'] ?? []) : ($provider === 'regolo' ? $row : $rate);
        $input = $this->price($pricing[$provider === 'openrouter' ? 'prompt' : 'input_cost_per_token'] ?? null);
        $output = $this->price($pricing[$provider === 'openrouter' ? 'completion' : 'output_cost_per_token'] ?? null);
        $tools = $provider === 'openrouter' && isset($row['supported_parameters'])
            ? in_array('tools', $row['supported_parameters'], true)
            : ($provider === 'regolo' ? ($row['supports_function_calling'] ?? null) : ($rate['supports_function_calling'] ?? null));
        $deprecated = ($row['lifecycle'] ?? '') === 'deprecated';
        return ['id' => $id, 'name' => mb_substr((string) ($row['display_name'] ?? $row['displayName'] ?? $row['name'] ?? $id), 0, 240),
            'input_per_million' => $input, 'output_per_million' => $output,
            'currency' => $provider === 'regolo' ? 'EUR' : 'USD',
            'price_source' => $provider === 'openrouter' || $provider === 'regolo' ? $provider : ($rate !== [] ? 'litellm' : null),
            'price_updated_at' => $provider === 'openrouter' || $provider === 'regolo' ? now()->toIso8601String() : $rateAt,
            'context_length' => $row['context_length'] ?? $row['inputTokenLimit'] ?? $row['max_input_tokens'] ?? $rate['max_input_tokens'] ?? null,
            'tools_supported' => is_bool($tools) ? $tools : null, 'selectable' => ! $deprecated && $tools !== false,
            'deprecated' => $deprecated, 'created' => is_numeric($row['created'] ?? null) ? (int) $row['created'] : (strtotime($row['created_at'] ?? '') ?: 0)];
    }

    private function price(mixed $perToken): ?float
    {
        return is_numeric($perToken) && is_finite((float) $perToken) && (float) $perToken >= 0 ? (float) $perToken * 1_000_000 : null;
    }
}
