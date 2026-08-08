<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\AgentExecutionContext;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteRelation;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RelationMapper;
use Throwable;

/**
 * Expands a single logical API action into bounded page/cursor requests or a
 * read-only list-to-detail fan-out. The package executor remains responsible
 * for authentication, SSRF protection, rate limits, output caps and auditing.
 */
final class ApiToolCollector
{
    public function __construct(
        private readonly ApiToolExecutor $executor,
        private readonly ApiToolRequestContext $requestContext,
        private readonly RelationMapper $relationMapper,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @param  null|callable(array<string,mixed>):void  $progress
     */
    public function collect(
        ApiRoute $route,
        array $arguments,
        AgentExecutionContext $context,
        AgentBudgetTracker $budget,
        ?callable $progress = null,
    ): ApiToolExecutionResult {
        $route->loadMissing('parameters');
        $pagination = is_array($route->pagination) ? $route->pagination : [];
        $type = strtolower((string) ($pagination['type'] ?? ''));

        return match ($type) {
            'page' => $this->collectPages($route, $arguments, $context, $budget, $pagination, $progress),
            'cursor' => $this->collectCursors($route, $arguments, $context, $budget, $pagination, $progress),
            default => $this->collectOnce($route, $arguments, $context, $budget, $progress),
        };
    }

    /**
     * Follow one configured list-to-detail relation. The caller deliberately
     * chooses this operation: list routes never download every detail silently.
     *
     * @param  list<mixed>  $items
     * @param  null|callable(array<string,mixed>):void  $progress
     */
    public function collectRelatedDetails(
        ApiRouteRelation $relation,
        array $items,
        AgentExecutionContext $context,
        AgentBudgetTracker $budget,
        ?callable $progress = null,
    ): ApiToolExecutionResult {
        $relation->loadMissing(['listRoute', 'detailRoute.parameters']);
        $listRoute = $relation->listRoute;
        $detailRoute = $relation->detailRoute;

        if (! $listRoute instanceof ApiRoute || ! $detailRoute instanceof ApiRoute) {
            return $this->failure('relation_unavailable');
        }
        if ($relation->tenant_id !== $context->tenantId
            || $listRoute->tenant_id !== $context->tenantId
            || $detailRoute->tenant_id !== $context->tenantId
            || $relation->api_connector_id !== $listRoute->api_connector_id
            || $relation->api_connector_id !== $detailRoute->api_connector_id
            || $this->projectKey($listRoute) !== $context->projectKey
            || $this->projectKey($detailRoute) !== $context->projectKey) {
            return $this->failure('relation_scope_mismatch');
        }
        if ($detailRoute->http_method !== HttpMethod::GET) {
            return $this->failure('fanout_requires_read_only_route');
        }

        $batchSize = max(1, min(5, (int) config('agent.tools.fanout_concurrency', 5)));
        $maximum = max(1, (int) config('agent.tools.fanout_max_items', 100));
        $selected = array_slice($items, 0, $maximum);
        $details = [];
        $errors = [];
        $physical = 0;
        $complete = count($selected) === count($items);
        $stopReason = $complete ? null : 'fanout_item_limit';

        foreach (array_chunk($selected, $batchSize, true) as $batch) {
            $capacity = $budget->canIssuePhysical(count($batch));
            if (! $capacity->allowed()) {
                $complete = false;
                $stopReason = $capacity->reason;
                break;
            }

            $tasks = [];
            $indices = [];
            foreach ($batch as $index => $item) {
                if (! is_array($item)) {
                    $errors[] = ['index' => $index, 'error' => 'relation_item_must_be_an_object'];
                    continue;
                }

                try {
                    $mapped = $this->relationMapper->mapArguments($item, $relation->field_map ?? []);
                } catch (Throwable $exception) {
                    $errors[] = ['index' => $index, 'error' => $exception->getMessage()];
                    continue;
                }

                $indices[] = $index;
                $tasks[] = static fn (): array => app(self::class)->executeRequest(
                    $detailRoute,
                    $mapped,
                    $context,
                );
            }

            $results = $tasks === [] ? [] : Concurrency::driver($this->fanoutDriver())->run(
                $tasks,
                max(1, (int) config('agent.tools.fanout_timeout_seconds', 90)),
            );

            foreach ($results as $position => $result) {
                $physical++;
                $valid = is_array($result) ? $result : ['error' => 'invalid_tool_response'];
                $success = ! isset($valid['error']);
                $budget->recordResult(1, $this->bytes($valid), $success);
                if ($success) {
                    $details[] = [
                        'source_index' => $indices[$position] ?? $position,
                        'content' => $valid,
                    ];
                } else {
                    $errors[] = [
                        'index' => $indices[$position] ?? $position,
                        'error' => (string) ($valid['error'] ?? 'tool_failed'),
                        'status' => $valid['status'] ?? null,
                    ];
                }
            }

            if ($progress !== null) {
                $progress([
                    'phase' => 'fanout',
                    'completed' => $physical,
                    'estimated_total' => count($selected),
                    'remaining' => max(0, count($selected) - $physical),
                ]);
            }

            if ((int) ($budget->snapshot()['consecutive_errors'] ?? 0)
                >= (int) config('agent.limits.consecutive_errors', 3)) {
                $complete = false;
                $stopReason = 'consecutive_error_limit';
                break;
            }
        }

        return new ApiToolExecutionResult(
            body: ['items' => $details, 'errors' => $errors],
            physicalRequests: $physical,
            complete: $complete,
            stopReason: $stopReason,
            stats: [
                'mode' => 'fanout',
                'requested_items' => count($items),
                'selected_items' => count($selected),
                'successful_items' => count($details),
                'failed_items' => count($errors),
                'concurrency' => $batchSize,
            ],
        );
    }

    /**
     * Public so Laravel's isolated concurrency worker can resolve a fresh
     * collector. It performs one package-executor call and does not mutate a
     * budget; the parent process accounts for the completed physical request.
     *
     * @param  array<string,mixed>  $arguments
     * @param  array<string,string|int|float|bool|null>  $fixedQuery
     * @return array<string,mixed>
     */
    public function executeRequest(
        ApiRoute $route,
        array $arguments,
        AgentExecutionContext $context,
        array $fixedQuery = [],
        ?string $url = null,
    ): array {
        $requestRoute = clone $route;
        $requestRoute->url = $url ?? $route->url;
        $requestRoute->loadMissing('parameters');

        $parameters = $requestRoute->parameters
            ->reject(fn (ApiRouteParameter $parameter): bool => $parameter->location === ParamLocation::Query
                && array_key_exists($parameter->name, $fixedQuery));

        foreach ($fixedQuery as $name => $value) {
            $parameters->push(new ApiRouteParameter([
                'tenant_id' => $route->tenant_id,
                'api_route_id' => $route->id,
                'name' => $name,
                'location' => ParamLocation::Query->value,
                'source' => ParamSource::Fixed->value,
                'type' => is_int($value) ? ParamType::Integer->value : ParamType::String->value,
                'required' => false,
                'value' => (string) $value,
                'description' => 'Bounded collection cursor.',
                'sort_order' => PHP_INT_MAX - 1,
            ]));
            $arguments['_agent_collection_'.$name] = $value;
        }
        $requestRoute->setRelation('parameters', $parameters->values());

        $prepared = $this->requestContext->apply($requestRoute, $arguments, $context->jsonSerialize());

        return $this->executor->execute(
            $prepared['route'],
            $prepared['arguments'],
            $prepared['context'],
        );
    }

    /** @param null|callable(array<string,mixed>):void $progress */
    private function collectOnce(
        ApiRoute $route,
        array $arguments,
        AgentExecutionContext $context,
        AgentBudgetTracker $budget,
        ?callable $progress,
    ): ApiToolExecutionResult {
        $capacity = $budget->canIssuePhysical();
        if (! $capacity->allowed()) {
            return $this->failure($capacity->reason);
        }

        $body = $this->executeRequest($route, $arguments, $context);
        $budget->recordResult(1, $this->bytes($body), ! isset($body['error']));
        if ($progress !== null) {
            $progress(['phase' => 'request', 'completed' => 1, 'estimated_total' => 1, 'remaining' => 0]);
        }

        return new ApiToolExecutionResult($body, 1, true, null, ['mode' => 'single']);
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $pagination
     * @param null|callable(array<string,mixed>):void $progress
     */
    private function collectPages(
        ApiRoute $route,
        array $arguments,
        AgentExecutionContext $context,
        AgentBudgetTracker $budget,
        array $pagination,
        ?callable $progress,
    ): ApiToolExecutionResult {
        $pageParam = trim((string) ($pagination['page_param'] ?? ''));
        if ($pageParam === '') {
            return $this->failure('invalid_page_configuration');
        }

        $page = max(0, (int) ($pagination['start_page'] ?? 1));
        $itemsPath = $this->itemsPath($route, $pagination);
        $maximum = max(1, (int) config('agent.tools.pagination_max_pages', 100));
        $all = [];
        $physical = 0;
        $complete = false;
        $stopReason = 'pagination_page_limit';

        while ($physical < $maximum) {
            $capacity = $budget->canIssuePhysical();
            if (! $capacity->allowed()) {
                $stopReason = $capacity->reason;
                break;
            }

            $body = $this->executeRequest($route, $arguments, $context, [$pageParam => $page]);
            $physical++;
            $success = ! isset($body['error']);
            $budget->recordResult(1, $this->bytes($body), $success);
            if (! $success) {
                $stopReason = 'tool_error';
                break;
            }

            $items = $this->relationMapper->itemsAt($body, $itemsPath);
            array_push($all, ...$items);
            if ($progress !== null) {
                $progress([
                    'phase' => 'pagination',
                    'completed' => $physical,
                    'estimated_total' => null,
                    'items_collected' => count($all),
                ]);
            }

            if ($items === [] || $this->isShortPage($route, $arguments, $pagination, count($items))) {
                $complete = true;
                $stopReason = null;
                break;
            }
            $page++;
        }

        return new ApiToolExecutionResult(
            body: ['items' => $all],
            physicalRequests: $physical,
            complete: $complete,
            stopReason: $stopReason,
            stats: ['mode' => 'page', 'pages' => $physical, 'items' => count($all)],
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $pagination
     * @param null|callable(array<string,mixed>):void $progress
     */
    private function collectCursors(
        ApiRoute $route,
        array $arguments,
        AgentExecutionContext $context,
        AgentBudgetTracker $budget,
        array $pagination,
        ?callable $progress,
    ): ApiToolExecutionResult {
        $itemsPath = $this->itemsPath($route, $pagination);
        $cursorParam = trim((string) ($pagination['cursor_param'] ?? ''));
        $cursorPath = trim((string) ($pagination['next_cursor_path'] ?? ''));
        $urlPath = trim((string) ($pagination['next_url_path'] ?? ''));
        if (($cursorParam === '' || $cursorPath === '') && $urlPath === '') {
            return $this->failure('invalid_cursor_configuration');
        }

        $maximum = max(1, (int) config('agent.tools.pagination_max_pages', 100));
        $all = [];
        $seen = [];
        $cursor = null;
        $nextUrl = null;
        $physical = 0;
        $complete = false;
        $stopReason = 'pagination_page_limit';

        while ($physical < $maximum) {
            $capacity = $budget->canIssuePhysical();
            if (! $capacity->allowed()) {
                $stopReason = $capacity->reason;
                break;
            }

            $query = $cursor === null || $cursorParam === '' ? [] : [$cursorParam => $cursor];
            $body = $this->executeRequest($route, $arguments, $context, $query, $nextUrl);
            $physical++;
            $success = ! isset($body['error']);
            $budget->recordResult(1, $this->bytes($body), $success);
            if (! $success) {
                $stopReason = 'tool_error';
                break;
            }

            $items = $this->relationMapper->itemsAt($body, $itemsPath);
            array_push($all, ...$items);
            if ($progress !== null) {
                $progress([
                    'phase' => 'pagination',
                    'completed' => $physical,
                    'estimated_total' => null,
                    'items_collected' => count($all),
                ]);
            }

            $candidateUrl = $urlPath === '' ? null : data_get($body, $urlPath);
            $candidateCursor = $cursorPath === '' ? null : data_get($body, $cursorPath);
            if (is_string($candidateUrl) && $candidateUrl !== '') {
                $resolved = $this->safeNextUrl($route->url, $candidateUrl);
                if ($resolved === null) {
                    $stopReason = 'unsafe_next_url';
                    break;
                }
                $nextUrl = $resolved;
                $cursor = null;
                $identity = 'url:'.$nextUrl;
            } elseif (is_scalar($candidateCursor) && (string) $candidateCursor !== '') {
                $cursor = $candidateCursor;
                $nextUrl = null;
                $identity = 'cursor:'.(string) $cursor;
            } else {
                $complete = true;
                $stopReason = null;
                break;
            }

            if (isset($seen[$identity])) {
                $stopReason = 'repeated_cursor';
                break;
            }
            $seen[$identity] = true;
        }

        return new ApiToolExecutionResult(
            body: ['items' => $all],
            physicalRequests: $physical,
            complete: $complete,
            stopReason: $stopReason,
            stats: ['mode' => 'cursor', 'pages' => $physical, 'items' => count($all)],
        );
    }

    /** @param array<string,mixed> $pagination */
    private function itemsPath(ApiRoute $route, array $pagination): ?string
    {
        $path = $pagination['items_path'] ?? $route->items_path;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** @param array<string,mixed> $arguments @param array<string,mixed> $pagination */
    private function isShortPage(ApiRoute $route, array $arguments, array $pagination, int $count): bool
    {
        $sizeParam = trim((string) ($pagination['size_param'] ?? ''));
        if ($sizeParam === '') {
            return false;
        }

        $size = $arguments[$sizeParam] ?? $route->parameters
            ->first(fn (ApiRouteParameter $parameter): bool => $parameter->name === $sizeParam)?->value;

        return is_numeric($size) && (int) $size > 0 && $count < (int) $size;
    }

    private function safeNextUrl(string $base, string $candidate): ?string
    {
        if (Str::startsWith($candidate, '/')) {
            $parts = parse_url($base);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $candidate = $parts['scheme'].'://'.$parts['host'].$port.$candidate;
        }

        $baseParts = parse_url($base);
        $nextParts = parse_url($candidate);
        if (! is_array($baseParts) || ! is_array($nextParts)) {
            return null;
        }

        $baseOrigin = strtolower((string) ($baseParts['scheme'] ?? '')).'://'.strtolower((string) ($baseParts['host'] ?? '')).':'.($baseParts['port'] ?? '');
        $nextOrigin = strtolower((string) ($nextParts['scheme'] ?? '')).'://'.strtolower((string) ($nextParts['host'] ?? '')).':'.($nextParts['port'] ?? '');

        return $baseOrigin === $nextOrigin ? $candidate : null;
    }

    /** @param array<string,mixed> $body */
    private function bytes(array $body): int
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? strlen($json) : 0;
    }

    private function fanoutDriver(): string
    {
        if (app()->environment('testing')) {
            return 'sync';
        }

        return (string) config('agent.tools.fanout_driver', 'process');
    }

    private function projectKey(ApiRoute $route): ?string
    {
        return is_string($route->project_key) && $route->project_key !== ''
            ? $route->project_key
            : null;
    }

    private function failure(?string $reason): ApiToolExecutionResult
    {
        $reason ??= 'tool_collection_stopped';

        return new ApiToolExecutionResult(
            body: ['error' => $reason],
            physicalRequests: 0,
            complete: false,
            stopReason: $reason,
        );
    }
}
