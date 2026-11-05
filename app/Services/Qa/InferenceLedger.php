<?php

declare(strict_types=1);

namespace App\Services\Qa;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Http;

/** Opt-in local QA accounting and pre-send model/budget fence, inherited by research subprocesses. */
final class InferenceLedger
{
    private array $manifest;

    private string $statePath;

    private ?array $pending = null;

    public function register(string $manifestPath): void
    {
        $root = realpath(storage_path('app/private/qa'));
        $path = realpath($manifestPath);
        if (! app()->environment('local', 'testing') || ! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('QA ledger must use a private local manifest.');
        }
        $this->manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->statePath = dirname($path).'/inference-ledger.json';
        if (! is_array($this->manifest['allowed_models'] ?? null) || ($this->manifest['budget_usd'] ?? 0) <= 0) {
            throw new \RuntimeException('QA accounting manifest is incomplete.');
        }
        app('events')->listen(RequestSending::class, $this->sending(...));
        app('events')->listen(ResponseReceived::class, $this->received(...));
        app('events')->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            if ($this->pending !== null) {
                $this->finish(['http_status' => null, 'billing_status' => 'unknown', 'error' => 'connection_failed']);
            }
        });
    }

    public function sending(RequestSending $event): void
    {
        $url = $event->request->url();
        $path = parse_url($url, PHP_URL_PATH);
        if ($event->request->method() !== 'POST' || ! preg_match('#/(chat/completions|responses|embeddings|alpha/decisions)$#', $path ?? '')) {
            return;
        }
        $body = $event->request->data();
        $model = $body['model'] ?? null;
        $spec = is_string($model) ? ($this->manifest['allowed_models'][$model] ?? null) : null;
        $case = getenv('ASKMYDOCS_QA_CASE') ?: 'unknown';
        if (parse_url($url, PHP_URL_HOST) !== 'openrouter.ai' || ! is_array($spec)
            || ! in_array($path, $spec['paths'] ?? [], true)
            || (($spec['enforce_provider_prices'] ?? false) && (
                data_get($body, 'provider.sort') !== 'price'
                || data_get($body, 'provider.require_parameters') !== true
                || ! is_numeric(data_get($body, 'provider.max_price.prompt'))
                || ! is_numeric(data_get($body, 'provider.max_price.completion'))
                || data_get($body, 'provider.max_price.prompt') > $spec['input_per_million']
                || data_get($body, 'provider.max_price.completion') > $spec['output_per_million']))) {
            $this->locked(function (array &$state) use ($model): void {
                $state['stop_reason'] = 'Unapproved inference endpoint/model: '.(string) $model;
            });
            throw new \RuntimeException('qa_unapproved_inference_model');
        }
        $bytes = strlen($event->request->body());
        $maxOutput = (int) ($body['max_tokens'] ?? $body['max_output_tokens'] ?? $spec['output_reserve_tokens']);
        $reserve = ($bytes * $spec['input_per_million'] + $maxOutput * $spec['output_per_million']) / 1_000_000 + .0001;
        $callers = array_values(array_unique(array_filter(array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 100), 'class'),
            fn ($class) => str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Ai\\') && $class !== self::class)));
        $call = ['id' => bin2hex(random_bytes(10)), 'case' => $case, 'pid' => getmypid(),
            'stage' => getenv('ASKMYDOCS_QA_STAGE') ?: 'pipeline', 'path' => $path, 'requested_model' => $model,
            'caller_classes' => array_slice($callers, 0, 8),
            'request_sha256' => hash('sha256', $event->request->body()), 'request_bytes' => $bytes,
            'reserved_usd' => $reserve, 'started_at' => microtime(true)];
        $this->locked(function (array &$state) use ($call): void {
            $reserved = array_sum(array_column($state['pending'], 'reserved_usd'));
            if ($state['stop_reason'] !== null || $state['cost_usd'] + $reserved + $call['reserved_usd'] > $this->manifest['budget_usd']
                || count($state['calls']) + count($state['pending']) >= $this->manifest['max_calls']) {
                $state['stop_reason'] ??= 'QA inference budget or call limit reached before dispatch.';
                return;
            }
            $state['pending'][$call['id']] = $call;
        });
        $state = $this->locked(fn (array &$state) => $state);
        if (! isset($state['pending'][$call['id']])) {
            throw new \RuntimeException('qa_inference_stopped_before_dispatch');
        }
        $this->pending = $call;
    }

    public function received(ResponseReceived $event): void
    {
        if ($this->pending === null || parse_url($event->request->url(), PHP_URL_PATH) !== $this->pending['path']) {
            return;
        }
        $data = $event->response->json();
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $cost = $usage['cost'] ?? null;
        // The generation endpoint is read-only and is never an inference retry.
        if (! is_numeric($cost) && is_string($data['id'] ?? null) && $event->response->successful()) {
            try {
                $lookup = Http::withToken(config('ai.providers.openrouter.key'))->timeout(12)
                    ->get('https://openrouter.ai/api/v1/generation', ['id' => $data['id']]);
                $cost = $lookup->successful() ? $lookup->json('data.total_cost') : null;
            } catch (\Throwable) {
                $cost = null;
            }
        }
        $known = is_numeric($cost) && is_finite((float) $cost) && (float) $cost >= 0;
        $this->finish(['http_status' => $event->response->status(), 'generation_id' => $data['id'] ?? null,
            'actual_model' => $data['model'] ?? null, 'usage' => $usage, 'cost_usd' => $known ? (float) $cost : null,
            'billing_status' => $known ? 'provider_reported' : 'unknown',
            'error' => is_array($data['error'] ?? null) ? array_intersect_key($data['error'], array_flip(['code', 'message'])) : null]);
    }

    private function finish(array $response): void
    {
        $call = $this->pending;
        $this->pending = null;
        if ($call === null) {
            return;
        }
        $call = [...$call, ...$response, 'elapsed_seconds' => round(microtime(true) - $call['started_at'], 3)];
        $this->locked(function (array &$state) use ($call): void {
            unset($state['pending'][$call['id']]);
            $state['calls'][] = $call;
            $state['cost_usd'] += $call['cost_usd'] ?? 0;
            if ($call['billing_status'] === 'unknown' || ! ($call['http_status'] >= 200 && $call['http_status'] < 300)) {
                $state['stop_reason'] ??= 'Inference error or unconfirmed billed cost; further paid calls stopped.';
            }
            if (in_array($call['path'], ['/api/v1/chat/completions', '/api/v1/responses'], true)
                && ! in_array($call['actual_model'], $this->manifest['allowed_models'][$call['requested_model']]['response_models'] ?? [$call['requested_model']], true)) {
                $state['stop_reason'] ??= 'Actual response model differs from the requested DeepSeek snapshot.';
            }
            if (($call['cost_usd'] ?? 0) > $call['reserved_usd'] + .00001) {
                $state['stop_reason'] ??= 'Provider billed more than the conservative per-call reservation.';
            }
        });
    }

    private function locked(callable $callback): mixed
    {
        $file = fopen($this->statePath, 'c+');
        if ($file === false || ! flock($file, LOCK_EX)) {
            throw new \RuntimeException('QA accounting lock unavailable.');
        }
        chmod($this->statePath, 0600);
        try {
            $raw = stream_get_contents($file);
            $state = $raw === '' ? ['cost_usd' => 0.0, 'pending' => [], 'calls' => [], 'stop_reason' => null] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $result = $callback($state);
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fflush($file);

            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
