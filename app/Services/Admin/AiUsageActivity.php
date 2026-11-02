<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

/** Read the existing call ledger. No prompts, credentials or cross-tenant totals. */
final readonly class AiUsageActivity
{
    private const METRICS = ['cost', 'token_cost', 'token_cost_tokens', 'tokens_input', 'tokens_output', 'tokens_cached',
        'tokens_reasoning', 'entries', 'unpriced_entries', 'unpriced_token_entries', 'estimated_entries',
        'latency_ms', 'latency_entries'];

    public function __construct(private TenantContext $tenant) {}

    public function report(CarbonImmutable $from, CarbonImmutable $to, string $timezone, array $filters): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $bucketDays = (int) ceil($days / 60);
        $buckets = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDays($bucketDays)) {
            $end = $date->addDays($bucketDays)->min($to->addDay());
            $buckets[] = ['from' => $date->toDateString(), 'to' => $end->subDay()->toDateString(),
                'start' => $date->utc()->format('Y-m-d H:i:s'), 'end' => $end->utc()->format('Y-m-d H:i:s')];
        }
        $base = ['status' => 'unavailable', 'reason' => 'ledger_unavailable', 'from' => $from->toDateString(),
            'to' => $to->toDateString(), 'timezone' => $timezone, 'bucket_days' => $bucketDays,
            'metering_enabled' => (bool) config('ai-finops.enabled', true) && (bool) config('ai-finops.metering', true),
            'currency' => $filters['currency'] ?? config('ai-finops.currency.base', 'USD'),
            'filters' => array_intersect_key($filters, array_flip(['provider', 'purpose', 'status'])),
            'options' => ['providers' => [], 'purposes' => [], 'statuses' => [], 'currencies' => []],
            'summary' => null, 'ranking_metric' => 'cost', 'buckets' => [], 'series' => [], 'models' => [], 'top_models' => [], 'top_providers' => []];
        try {
            $model = new UsageRecord;
            $connection = $model->getConnection();
            if (! $connection->getSchemaBuilder()->hasTable($model->getTable())) {
                return $base;
            }
            $query = $connection->table($model->getTable())
                ->where('tenant_id', $this->tenant->current())
                ->where('created_at', '>=', $from->utc()->format('Y-m-d H:i:s'))
                ->where('created_at', '<', $to->addDay()->utc()->format('Y-m-d H:i:s'));
            $options = [];
            foreach (['providers' => 'provider', 'purposes' => 'purpose_tag', 'statuses' => 'status', 'currencies' => 'currency'] as $key => $column) {
                $options[$key] = (clone $query)->whereNotNull($column)->distinct()->orderBy($column)->limit(200)->pluck($column)->all();
            }
            $currencies = $options['currencies'];
            $currency = $filters['currency'] ?? (in_array($base['currency'], $currencies, true) ? $base['currency'] : ($currencies[0] ?? $base['currency']));
            $base = [...$base, 'currency' => $currency, 'options' => $options];
            $query->where('currency', $currency);
            foreach (['provider' => 'provider', 'purpose' => 'purpose_tag'] as $filter => $column) {
                if (isset($filters[$filter]) && $filters[$filter] !== '') {
                    $query->where($column, $filters[$filter]);
                }
            }
            // Pre-flight estimates and blocked attempts are not incurred usage.
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            } else {
                $query->whereIn('status', ['recorded', 'covered', 'failed']);
            }

            // Boundaries are calculated in the report timezone, including DST.
            // A portable CASE avoids SQL UTC dates being mixed with local dates.
            $case = 'CASE ';
            $bindings = [];
            foreach ($buckets as $index => $bucket) {
                $case .= 'WHEN created_at < ? THEN '.$index.' ';
                $bindings[] = $bucket['end'];
            }
            $case .= 'END';
            $priceSource = match ($connection->getDriverName()) {
                'pgsql' => "metadata->>'price_source'",
                'sqlite' => "json_extract(metadata, '$.price_source')",
                'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.price_source'))",
                default => 'NULL',
            };
            // A zero computed without a known tariff cannot attest a free call.
            $unknown = "(cost_total IS NULL OR (cost_total = 0 AND tokens_input + tokens_output > 0
                AND cost_method IN ('computed', 'estimated') AND {$priceSource} IS NULL))";
            $tokenRow = "tokens_input + tokens_output > 0 AND modality IN ('text', 'embedding')";
            $rows = $query->select(['provider', 'model'])
                ->selectRaw($case.' AS bucket', $bindings)
                ->selectRaw('COUNT(*) AS entries, SUM(COALESCE(cost_total, 0)) AS cost')
                ->selectRaw("SUM(CASE WHEN {$tokenRow} AND NOT {$unknown} THEN cost_total ELSE 0 END) AS token_cost")
                ->selectRaw("SUM(CASE WHEN {$tokenRow} AND NOT {$unknown} THEN tokens_input + tokens_output ELSE 0 END) AS token_cost_tokens")
                ->selectRaw("SUM(CASE WHEN {$unknown} THEN 1 ELSE 0 END) AS unpriced_entries")
                ->selectRaw("SUM(CASE WHEN {$unknown} AND {$tokenRow} THEN 1 ELSE 0 END) AS unpriced_token_entries")
                ->selectRaw("SUM(CASE WHEN tokens_estimated = true OR cost_method = 'estimated' THEN 1 ELSE 0 END) AS estimated_entries")
                ->selectRaw('SUM(tokens_input) AS tokens_input, SUM(tokens_output) AS tokens_output, SUM(tokens_cached) AS tokens_cached, SUM(tokens_reasoning) AS tokens_reasoning')
                ->selectRaw('SUM(COALESCE(latency_ms, 0)) AS latency_ms, COUNT(latency_ms) AS latency_entries')
                // Group by the selected alias: repeating bound CASE parameters
                // produces distinct expressions to PostgreSQL's GROUP BY parser.
                ->groupBy(['provider', 'model', 'bucket'])
                // Limit aggregate groups, never silently trim ledger events.
                ->limit(6001)->get();
            if ($rows->count() > 6000) {
                return [...$base, 'reason' => 'interval_too_broad'];
            }

            $summary = $this->zero();
            $models = $providers = $calendar = [];
            foreach ($buckets as $index => $bucket) {
                $calendar[$index] = ['from' => $bucket['from'], 'to' => $bucket['to'], ...$this->zero()];
            }
            foreach ($rows as $row) {
                $key = hash('sha256', $row->provider."\0".$row->model);
                $models[$key] ??= ['id' => $key, 'provider' => $row->provider, 'model' => $row->model, ...$this->zero()];
                $providers[$row->provider] ??= ['provider' => $row->provider, ...$this->zero()];
                foreach (self::METRICS as $metric) {
                    $value = in_array($metric, ['cost', 'token_cost'], true) ? (float) $row->$metric : (int) $row->$metric;
                    $summary[$metric] += $value;
                    $models[$key][$metric] += $value;
                    $providers[$row->provider][$metric] += $value;
                    $calendar[(int) $row->bucket][$metric] += $value;
                }
            }
            $rankingMetric = $summary['entries'] > 0 && $summary['unpriced_entries'] === $summary['entries'] ? 'tokens' : 'cost';
            $value = static fn ($row) => $rankingMetric === 'tokens' ? $row['tokens_input'] + $row['tokens_output'] : $row['cost'];
            $rank = static fn ($a, $b) => $value($b) <=> $value($a) ?: strcmp($a['id'] ?? $a['provider'], $b['id'] ?? $b['provider']);
            uasort($models, $rank);
            uasort($providers, $rank);
            $top = array_slice($models, 0, count($models) > 6 ? 5 : 6, true);
            $series = [];
            foreach ($top as $key => $entry) {
                $series[$key] = ['id' => $key, 'name' => $entry['model'], 'provider' => $entry['provider'], 'other' => false,
                    'values' => array_fill(0, count($buckets), $this->zero())];
            }
            if (count($models) > 6) {
                $series['other'] = ['id' => 'other', 'name' => 'Other models', 'provider' => null, 'other' => true,
                    'values' => array_fill(0, count($buckets), $this->zero())];
            }
            foreach ($rows as $row) {
                $key = hash('sha256', $row->provider."\0".$row->model);
                $key = isset($top[$key]) ? $key : 'other';
                foreach (self::METRICS as $metric) {
                    $series[$key]['values'][(int) $row->bucket][$metric] += in_array($metric, ['cost', 'token_cost'], true) ? (float) $row->$metric : (int) $row->$metric;
                }
            }
            foreach ($series as &$line) {
                $line['values'] = array_map($this->decorate(...), $line['values']);
            }
            unset($line);
            return [...$base, 'status' => 'available', 'reason' => null, 'ranking_metric' => $rankingMetric,
                'summary' => $this->decorate($summary), 'buckets' => array_map($this->decorate(...), array_values($calendar)),
                'models' => array_map($this->decorate(...), array_values($models)), 'series' => array_values($series),
                'top_models' => array_map($this->decorate(...), array_slice(array_values($models), 0, 5)),
                'top_providers' => array_map($this->decorate(...), array_slice(array_values($providers), 0, 5))];
        } catch (QueryException $exception) {
            Log::warning('Tenant AI activity ledger is unavailable.', ['exception_class' => $exception::class]);

            return $base;
        }
    }

    private function zero(): array
    {
        return array_fill_keys(self::METRICS, 0);
    }

    private function decorate(array $metrics): array
    {
        $tokens = $metrics['tokens_input'] + $metrics['tokens_output'];

        return [...$metrics, 'tokens' => $tokens,
            'cost_per_million' => $metrics['token_cost_tokens'] > 0 && $metrics['unpriced_token_entries'] === 0 ? $metrics['token_cost'] / $metrics['token_cost_tokens'] * 1_000_000 : null,
            'average_latency_ms' => $metrics['latency_entries'] > 0 ? $metrics['latency_ms'] / $metrics['latency_entries'] : null];
    }
}
