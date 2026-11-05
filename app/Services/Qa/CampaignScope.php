<?php

declare(strict_types=1);

namespace App\Services\Qa;

use App\Models\AgentRun;
use App\Models\QaCampaign;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Process-local scope, backed by durable campaign reservations in the shared database. */
final class CampaignScope
{
    private ?array $active = null;

    public function withinRun(AgentRun $run, callable $callback): mixed
    {
        $id = data_get($run->input_json, 'qa.campaign_id');
        if ($id === null) {
            if ($this->active !== null) throw new \DomainException('qa_scope_cannot_be_dropped');
            return $callback();
        }
        $ledger = app(CampaignLedger::class);
        $ledger->assertEnabled();
        $campaign = $ledger->owned($id, $run->tenant_id, (string) $run->project_key, (int) $run->user_id);
        if ($campaign->status !== 'active') throw new \DomainException('qa_campaign_stopped');
        $release = data_get($run->input_json, 'qa.release');
        if (! is_string($release) || ! preg_match('/^[a-f0-9]{7,40}$/D', $release)
            || $release !== config('agent.diagnostics.release')) {
            throw new \DomainException('qa_worker_release_mismatch');
        }
        if ($this->active !== null && $this->active['campaign']->id !== $campaign->id) {
            throw new \DomainException('qa_campaign_scope_mismatch');
        }
        $actor = $run->user;
        if ($actor === null || ! $actor->is_active || ! $actor->hasAnyRole(['admin', 'super-admin'])
            || ! \App\Models\ProjectMembership::query()->forTenant($run->tenant_id)->where('user_id', $actor->id)->exists()
            || (! $actor->canReadAllProjects() && ! in_array($run->project_key, $actor->allowedProjects(), true))) {
            throw new \DomainException('qa_campaign_actor_not_authorized');
        }
        if (getenv('ASKMYDOCS_QA_MANIFEST')) throw new \DomainException('qa_duplicate_accounting_guard');
        $previous = $this->active;
        $scope = (string) Str::uuid();
        $this->active = ['campaign' => $campaign, 'run' => $run, 'scope' => $scope];
        try {
            return $callback();
        } finally {
            $this->active = $previous;
            $ledger->interruptScope($campaign->tenant_id, $campaign->id, $scope);
        }
    }

    public function assertProvider(string $provider): void
    {
        if ($this->active !== null && $provider !== 'openrouter') {
            $this->reject('qa_unapproved_provider');
        }
    }

    public function middleware(): \Closure
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            if ($this->active === null) return $handler($request, $options);
            $path = $request->getUri()->getPath();
            $host = $request->getUri()->getHost();
            // Connector GETs/POSTs are not inference. Other known AI transports
            // fail closed too, including direct SDK calls outside AiManager.
            $providerHosts = array_filter(array_map(fn ($provider) => parse_url($provider['url'] ?? '', PHP_URL_HOST),
                (array) config('ai.providers', [])));
            $inference = in_array($host, $providerHosts, true) || in_array($path, CampaignManifest::PATHS, true)
                || preg_match('~/(?:messages|chat/completions|responses|embeddings|rerank)$|:(?:generateContent|streamGenerateContent|embedContent|batchEmbedContents)$~', $path);
            if ($request->getMethod() !== 'POST' || (! $inference && $host !== 'openrouter.ai')) {
                return $handler($request, $options);
            }
            if ($host !== 'openrouter.ai' || $request->getUri()->getScheme() !== 'https'
                || ($request->getUri()->getPort() !== null && $request->getUri()->getPort() !== 443)
                || $request->getUri()->getUserInfo() !== '' || $request->getUri()->getQuery() !== '') {
                $this->reject('qa_unapproved_inference_endpoint');
            }
            $body = json_decode((string) $request->getBody(), true);
            $campaign = $this->active['campaign'];
            $model = $body['model'] ?? null;
            $spec = is_string($model) ? ($campaign->manifest_json['allowed_models'][$model] ?? null) : null;
            if (! is_array($spec) || ! in_array($path, $spec['paths'], true)) $this->reject('qa_unapproved_model');
            if (str_ends_with($model, ':online') || in_array($model, ['openrouter/auto', 'openrouter/free'], true)) {
                $this->reject('qa_unsupported_inference_shape');
            }
            if (($body['stream'] ?? false) || isset($body['models']) || isset($body['route']) || isset($body['plugins'])
                || isset($body['preset']) || isset($body['transforms'])
                || isset($body['audio']) || isset($body['image_config']) || isset($body['web_search_options'])
                || array_diff($body['modalities'] ?? ['text'], ['text']) !== []
                || (isset($body['n']) && $body['n'] !== 1) || $this->multimodal($body)
                || array_filter($body['tools'] ?? [], fn ($tool) => ($tool['type'] ?? null) !== 'function')) {
                $this->reject('qa_unsupported_inference_shape');
            }
            if ($path === '/api/alpha/decisions' && (float) $spec['output_per_million'] !== 0.0) {
                $this->reject('qa_unbounded_output');
            }
            if (in_array($path, ['/api/v1/chat/completions', '/api/v1/responses'], true)) {
                $outputKey = $path === '/api/v1/responses' ? 'max_output_tokens' : 'max_tokens';
                if (! is_int($body[$outputKey] ?? null) || $body[$outputKey] < 1
                    || $body[$outputKey] > $spec['output_reserve_tokens']) $this->reject('qa_unbounded_output');
                $preferences = is_array($body['provider'] ?? null) ? $body['provider'] : [];
                $ceiling = is_array($preferences['max_price'] ?? null) ? $preferences['max_price'] : [];
                $body['provider'] = [...$preferences, 'require_parameters' => true, 'max_price' => [
                    'prompt' => min((float) ($ceiling['prompt'] ?? $spec['input_per_million']), (float) $spec['input_per_million']),
                    'completion' => min((float) ($ceiling['completion'] ?? $spec['output_per_million']), (float) $spec['output_per_million']),
                ]];
                // Omission inherits account defaults. Explicitly turn off the
                // paid web plugin as well as rejecting request-level search.
                $body['plugins'] = [['id' => 'web', 'enabled' => false]];
            }
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // UTF-8 bytes are a conservative input-token upper bound for this
            // text-only campaign. All output, including reasoning, is reserved.
            $output = $path === '/api/v1/embeddings' ? 0
                : ($body['max_tokens'] ?? $body['max_output_tokens'] ?? $spec['output_reserve_tokens']);
            $reserve = CampaignManifest::nanos((strlen($encoded) * $spec['input_per_million']
                + $output * $spec['output_per_million']) / 1_000_000 + .0001);
            $attempt = app(CampaignLedger::class)->reserve($campaign, $this->active['run'], $this->active['scope'],
                ['path' => $path, 'requested_model' => $model, 'request_sha256' => hash('sha256', $encoded),
                    'caller_classes' => array_slice(array_values(array_unique(array_filter(
                        array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 100), 'class'),
                        fn ($class) => str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Ai\\')
                            && ! str_starts_with($class, 'App\\Services\\Qa\\')))), 0, 8)], $reserve);
            $request = $request->withBody(Utils::streamFor($encoded))->withoutHeader('Content-Length');
            $options['laravel_data'] = $body;
            $started = hrtime(true);
            try {
                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($attempt, $started): ResponseInterface {
                        $durationMs = max(0, (int) ((hrtime(true) - $started) / 1_000_000));
                        $data = json_decode((string) $response->getBody(), true);
                        $cost = $data['usage']['cost'] ?? null;
                        $costSource = 'provider_usage';
                        $generation = is_string($data['id'] ?? null) && preg_match('~^[a-zA-Z0-9_.:-]{1,160}$~D', $data['id']) ? $data['id'] : null;
                        if (! is_numeric($cost) && is_string($data['id'] ?? null) && $response->getStatusCode() === 200) {
                            try {
                                $lookup = Http::withToken(config('ai.providers.openrouter.key'))->timeout(10)
                                    ->get('https://openrouter.ai/api/v1/generation', ['id' => $data['id']]);
                                $cost = $lookup->successful() ? $lookup->json('data.total_cost') : null;
                                $costSource = 'provider_generation';
                            } catch (\Throwable) { $cost = null; }
                        }
                        try { $nanos = CampaignManifest::nanos($cost); } catch (\DomainException) { $nanos = null; }
                        $model = is_string($data['model'] ?? null) && strlen($data['model']) <= 160
                            && preg_match('~^[a-zA-Z0-9_./:-]+$~D', $data['model']) ? $data['model'] : null;
                        app(CampaignLedger::class)->finish($attempt, $nanos, $response->getStatusCode(), $model,
                            $response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || isset($data['error']) ? 'qa_inference_error' : null,
                            $generation, $costSource, $durationMs);
                        return $response;
                    },
                    function ($error) use ($attempt, $started) {
                        app(CampaignLedger::class)->finish($attempt, null, null, null, 'qa_connection_failed', null, null,
                            max(0, (int) ((hrtime(true) - $started) / 1_000_000)));
                        return Create::rejectionFor($error);
                    },
                );
            } catch (\Throwable $exception) {
                app(CampaignLedger::class)->finish($attempt, null, null, null, 'qa_interrupted_request', null, null,
                    max(0, (int) ((hrtime(true) - $started) / 1_000_000)));
                throw $exception;
            }
        };
    }

    private function reject(string $reason): never
    {
        app(CampaignLedger::class)->stop($this->active['campaign'], $reason);
        throw new \DomainException($reason);
    }

    private function multimodal(mixed $value): bool
    {
        if (! is_array($value)) return false;
        if (isset($value['type']) && in_array($value['type'], ['image_url', 'input_image', 'input_audio', 'audio', 'video_url',
            'input_video', 'file', 'input_file'], true)) return true;
        foreach ($value as $part) if ($this->multimodal($part)) return true;
        return false;
    }
}
