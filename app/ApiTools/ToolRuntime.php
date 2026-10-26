<?php

declare(strict_types=1);
namespace App\ApiTools;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\ApiToolCollector;
use App\ApiTools\Models\{ApiTool,ApiToolRun,ApiToolStep};
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Padosoft\LaravelFlow\Executor\QueueGraphCoordinator;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\FlowExecutionOptions;

final class ToolRuntime
{
    public function __construct(
        private DefinitionValidator $validator,
        private ToolAccess $access,
        private ContentHasher $hasher,
        private BindingResolver $bindings,
        private DeterministicOperations $operations,
        private ApiToolCollector $collector,
        private ToolHttpBudget $httpBudget,
        private ConnectorSecretGuard $secrets,
    ) {}

    public function start(ApiTool $tool, array $inputs, AgentExecutionContext $context, string $mode = 'production', array $continuation = []): ApiToolRun
    {
        if (! config('api-tools.enabled', false)) { throw new \DomainException('api_tools_disabled'); }
        $context->activate();
        $user = $this->access->assertActor($context);
        if ($tool->tenant_id !== $context->tenantId || ! in_array($tool->project_key, ['', $context->projectKey ?? ''], true)) { throw new \DomainException('api_tool_scope_mismatch'); }
        if ($mode !== 'production' && ($mode !== 'verification' || ! $user->can('manageConnectors'))) { throw new \DomainException('api_tool_verification_forbidden'); }
        if ($mode === 'production' && ! $tool->available()) { throw new \DomainException('api_tool_unavailable'); }
        $revision = $tool->revision()->firstOrFail();
        $definition = $this->validator->validate($revision->definition, $context->tenantId, $context->projectKey ?? '');
        $this->validator->assertValue($inputs, $definition['input_schema']);
        $hash = $this->hasher->tool($definition, $context->tenantId, $context->projectKey ?? '');
        if ($mode === 'production' && $hash !== $tool->content_hash) { throw new \DomainException('api_tool_configuration_changed'); }
        $dependencies = [];
        foreach ($definition['steps'] as $step) {
            if ($step['operation'] !== 'api') { continue; }
            $route = $this->access->route((int) $step['route_id'], $context);
            $dependencies[$route->id] = $this->hasher->endpoint($route);
        }
        $id = (string) Str::uuid();
        $key = is_string($continuation['_idempotency_key'] ?? null) ? hash('sha256', $continuation['_idempotency_key']) : null;
        return DB::transaction(function () use ($tool,$revision,$definition,$context,$mode,$continuation,$hash,$dependencies,$inputs,$id,$key): ApiToolRun {
            $attributes = [
                'id'=>$id, 'tenant_id'=>$context->tenantId, 'project_key'=>$context->projectKey ?? '', 'api_tool_id'=>$tool->id,
                'api_tool_revision_id'=>$revision->id, 'mode'=>$mode, 'content_hash'=>$hash,
                'snapshot'=>['definition'=>$definition,'dependencies'=>$dependencies], 'context'=>$context->jsonSerialize(),
                'inputs'=>$inputs,'continuation'=>$continuation, 'request_limit'=>min($definition['limits']['physical_requests'],(int) ($continuation['request_limit'] ?? PHP_INT_MAX)),
                'deadline_at'=>now()->addSeconds($definition['limits']['timeout_seconds']),
                'idempotency_key'=>$key,
            ];
            // createOrFirst's unique constraint plus current read handles concurrent/retried starts.
            $run = $key === null ? ApiToolRun::query()->create($attributes)
                : ApiToolRun::query()->lockForUpdate()->createOrFirst(['tenant_id'=>$context->tenantId,'idempotency_key'=>$key], $attributes);
            if (! $run->wasRecentlyCreated) {
                $previous = AgentExecutionContext::fromArray($run->context)->jsonSerialize();
                $current = $context->jsonSerialize();
                unset($previous['run_id'], $current['run_id']);
                if ((int) $run->api_tool_id !== (int) $tool->id || (int) $run->api_tool_revision_id !== (int) $revision->id
                    || $run->mode !== $mode || $run->content_hash !== $hash || $previous !== $current
                    || $this->hasher->hash($run->inputs) !== $this->hasher->hash($inputs)) {
                    throw new \DomainException('api_tool_idempotency_mismatch');
                }
                return $run;
            }
            foreach ([...array_column($definition['steps'],'id'),'tool_output'] as $stepId) {
                ApiToolStep::query()->create(['api_tool_run_id'=>$id,'tenant_id'=>$context->tenantId,'step_id'=>$stepId]);
            }
            $graph = app(ToolGraphCompiler::class)->compile($definition,$id);
            // QueueGraphCoordinator is the same engine used by FlowEngine::dispatchGraph.
            // Calling its scoped entry point avoids enabling persistence for unrelated host flows.
            $flowId = app(QueueGraphCoordinator::class)->start($graph, ['api_tool_run_id'=>$id], new FlowExecutionOptions(correlationId:$id,subject:'user:'.$context->actorId), 'askmydocs.api_tool');
            $run->forceFill(['flow_run_id'=>$flowId,'status'=>'running'])->save();
            $job = new CoordinatorJob($flowId,$graph,'askmydocs.api_tool',['api_tool_run_id'=>$id],queue:config('api-tools.queue','api-tools'),lockStore:config('laravel-flow.executor.lock_store'),lockSeconds:max((int) config('api-tools.node_lock_seconds',60),(int) $definition['limits']['timeout_seconds']+30),lockRetrySeconds:5);
            if ($connection = config('api-tools.connection')) { $job->onConnection($connection); }
            app(Dispatcher::class)->dispatch($job);
            return $run;
        });
    }

    public function executeStep(string $runId, string $stepId, ?string $flowId = null): void
    {
        $run = ApiToolRun::query()->findOrFail($runId);
        $context = AgentExecutionContext::fromArray($run->context);
        $context->activate();
        $this->access->assertActor($context);
        if ($flowId !== null && $run->flow_run_id !== $flowId) { throw new \DomainException('api_tool_run_mismatch'); }
        $stepRecord = ApiToolStep::query()->forTenant($run->tenant_id)->where('api_tool_run_id',$runId)->where('step_id',$stepId)->firstOrFail();
        if (in_array($stepRecord->status,['completed','partial'],true)) { return; }
        if ($run->terminal() || $run->cancelled_at) { throw new \DomainException('api_tool_cancelled'); }
        if ($run->deadline_at->isPast()) { $this->finish($runId,'timed_out','api_tool_timeout'); throw new \DomainException('api_tool_timeout'); }
        $tool = ApiTool::query()->forTenant($run->tenant_id)->findOrFail($run->api_tool_id);
        if ($run->mode === 'production' && $tool->manual_disabled) { throw new \DomainException('api_tool_disabled'); }
        $definition = $run->snapshot['definition'];
        $outputs = $this->stepOutputs($run);
        $stepRecord->forceFill(['status'=>'running','started_at'=>now()])->save();
        try {
            if ($stepId === 'tool_output') {
                $result = $this->bindings->map($definition['output'],$run->inputs,$outputs);
                $args = [];
                $run->forceFill(['outputs'=>$result])->save();
            } else {
                $step = collect($definition['steps'])->firstWhere('id',$stepId) ?? throw new \DomainException('api_tool_step_unknown');
                foreach ($this->validator->dependencies($step) as $dependency) {
                    if (! array_key_exists($dependency,$outputs)) { throw new \DomainException('api_tool_dependency_incomplete'); }
                }
                $args = $this->resolvedInputs($step,$run->inputs,$outputs);
                $stepRecord->forceFill(['inputs'=>['values'=>$args,'origins'=>$step['bindings'] ?? []]])->save();
                $result = $this->evaluate($step,$run->inputs,$outputs,fn ($call,$mapped) => $this->api($call,$mapped,$run,$context));
            }
            $partial = is_array($result) && ($result['_partial'] ?? false) === true;
            $stepRecord->forceFill(['status'=>$partial ? 'partial':'completed','inputs'=>['values'=>$args,'origins'=>$definition['output'] ?? []], 'output'=>['value'=>$result], 'completed_at'=>now(),
                'diagnostics'=>['inputs'=>$this->secrets->mask($args),'origins'=>$step['bindings'] ?? $definition['output'],'output'=>$this->secrets->mask($result)]])->save();
        } catch (\Throwable $exception) {
            $code = str_starts_with($exception->getMessage(),'api_tool_') ? $exception->getMessage() : 'api_tool_step_failed';
            $stepRecord->forceFill(['status'=>'failed','completed_at'=>now(),'diagnostics'=>['error'=>$code]])->save();
            $run->forceFill(['error_code'=>$code])->save();
            throw new \DomainException($code);
        }
    }

    public function simulate(ApiTool $tool, array $inputs, array $fixtures, AgentExecutionContext $context): array
    {
        $this->access->assertActor($context);
        $definition = $this->validator->validate($tool->revision->definition,$context->tenantId,$context->projectKey ?? '');
        $this->validator->assertValue($inputs,$definition['input_schema']);
        $outputs = [];
        $steps = [];
        $pending = array_column($definition['steps'],null,'id');
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $step) {
                if (array_diff($this->validator->dependencies($step),array_keys($outputs)) !== []) { continue; }
                $result = $this->evaluate($step,$inputs,$outputs,function ($call,$mapped) use ($fixtures) {
                    if (! array_key_exists($call['id'],$fixtures)) { throw new \DomainException('api_tool_fixture_missing'); }
                    return $fixtures[$call['id']];
                });
                $outputs[$id] = $result;
                $args = $this->resolvedInputs($step,$inputs,$outputs);
                $steps[$id] = ['step_id'=>$id,'status'=>is_array($result) && ($result['_partial'] ?? false) ? 'partial':'completed','inputs'=>$args,'output'=>$result,'diagnostics'=>['inputs'=>$this->secrets->mask($args),'origins'=>$step['bindings'] ?? [],'output'=>$this->secrets->mask($result)]];
                unset($pending[$id]);
                $progress = true;
            }
            if (! $progress) { throw new \DomainException('api_tool_cycle'); }
        }
        $publicOutput=$this->bindings->map($definition['output'],$inputs,$outputs);
        $steps['tool_output']=['step_id'=>'tool_output','status'=>'completed','diagnostics'=>['inputs'=>[],'origins'=>$definition['output'],'output'=>$this->secrets->mask($publicOutput)]];
        return ['output'=>$publicOutput,'steps'=>$steps];
    }

    private function resolvedInputs(array $step,array $inputs,array $outputs): array
    {
        if (! isset($step['iterate'])) { return $this->bindings->map($step['bindings'] ?? [],$inputs,$outputs); }
        $items=$this->bindings->resolve($step['iterate']['source'],$inputs,$outputs);
        if (! is_array($items) || ! array_is_list($items)) { throw new \DomainException('api_tool_iteration_requires_list'); }
        if (count($items) > $step['iterate']['max_items']) { throw new \DomainException('api_tool_iteration_limit'); }
        return ['items'=>array_map(fn ($item) => $this->bindings->map($step['bindings'] ?? [],$inputs,$outputs,$item),$items)];
    }

    private function evaluate(array $step, array $inputs, array $outputs, callable $api): mixed
    {
        if (isset($step['iterate'])) {
            $items = $this->bindings->resolve($step['iterate']['source'],$inputs,$outputs);
            if (! is_array($items) || ! array_is_list($items)) { throw new \DomainException('api_tool_iteration_requires_list'); }
            if (count($items) > $step['iterate']['max_items']) { throw new \DomainException('api_tool_iteration_limit'); }
            $results = []; $errors = [];
            foreach ($items as $index => $item) {
                try { $results[] = $this->operation($step,$this->bindings->map($step['bindings'] ?? [],$inputs,$outputs,$item),$api); }
                catch (\Throwable $e) {
                    if (! ($step['iterate']['allow_partial'] ?? false) || in_array($e->getMessage(),['api_tool_budget_exhausted','api_tool_timeout','api_tool_cancelled','api_tool_authorization_revoked','api_tool_dependency_unavailable','api_tool_configuration_changed'],true)) { throw $e; }
                    $errors[] = ['index'=>$index,'error'=>'api_tool_item_failed'];
                    $results[] = null;
                }
            }
            return ($step['iterate']['allow_partial'] ?? false) ? ['items'=>$results,'errors'=>$errors,'_partial'=>$errors !== []] : $results;
        }
        return $this->operation($step,$this->bindings->map($step['bindings'] ?? [],$inputs,$outputs),$api);
    }

    private function operation(array $step,array $args,callable $api): mixed
    {
        return $step['operation'] === 'api' ? $api($step,$args) : $this->operations->execute($step['operation'],$args,$step['config'] ?? []);
    }

    private function api(array $step,array $arguments,ApiToolRun $run,AgentExecutionContext $context): mixed
    {
        $route = $this->access->route((int) $step['route_id'],$context);
        if ($this->hasher->endpoint($route) !== ($run->snapshot['dependencies'][$route->id] ?? null)) { throw new \DomainException('api_tool_configuration_changed'); }
        $this->validator->assertValue($arguments,$this->validator->publicInputSchema($route),'endpoint_inputs');
        $pagination = $route->pagination ?? [];
        $collection = $step['collection'] ?? ['mode'=>'single'];
        $maxPages = $collection['mode'] === 'bounded' ? $collection['max_pages'] : 1;
        $all = []; $cursor = null; $seen = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $this->httpBudget->assertAvailable($run->fresh(),(int) $route->id);
            $query = [];
            if ($collection['mode'] === 'bounded') {
                if (($pagination['type'] ?? '') === 'page') { $query[$pagination['page_param'] ?? 'page'] = ($pagination['start_page'] ?? 1)+$page; }
                elseif ($cursor !== null) { $query[$pagination['cursor_param'] ?? 'cursor'] = $cursor; }
            }
            // Include fingerprint and actor in the package cache key without exposing them as request parameters.
            $args = [...$arguments,'_api_tool_content'=>$run->snapshot['dependencies'][$route->id],'_api_tool_actor'=>$context->actorId, '_api_tool_verification'=>$run->mode === 'verification' ? $run->id : null];
            $ttl = (int) $route->cache_ttl_s;
            $requestRoute = clone $route;
            $requestRoute->cache_ttl_s = 0;
            $requestRoute->timeout_ms = min((int) ($route->timeout_ms ?? config('connector-api.defaults.timeout_ms',10000)),max(1,(int) abs(now()->diffInMilliseconds($run->deadline_at))));
            $cacheKey = $this->hasher->hash(['tenant'=>$run->tenant_id,'project'=>$run->project_key,'route'=>$route->id,'arguments'=>$args,'query'=>$query,'locale'=>$context->locale,'credential_scope'=>hash_hmac('sha256',json_encode([$route->effectiveAuthProfile()?->credentials,$route->connector?->headers,$route->parameters->map(fn ($p)=>$p->only(['name','source','value','secret_ref']))],JSON_THROW_ON_ERROR),(string) config('app.key'))]);
            $result = app(EncryptedEndpointCache::class)->remember($cacheKey,$ttl,fn () => $this->httpBudget->within($run->id,fn () => $this->collector->executeRequest($requestRoute,$args,$context,$query),(int) $route->id));
            if (isset($result['error'])) {
                $error = (string) $result['error'];
                throw new \DomainException(str_starts_with($error,'api_tool_') ? $error : 'api_tool_endpoint_failed');
            }
            if ($collection['mode'] === 'single') { return $result; }
            $items = $this->bindings->path($result,$pagination['items_path'] ?? $route->items_path ?? '');
            if (! is_array($items) || ! array_is_list($items)) { throw new \DomainException('api_tool_pagination_requires_list'); }
            $all = array_slice([...$all,...$items],0,$collection['max_items']);
            if ($items === [] || count($all) >= $collection['max_items']) { break; }
            if (($pagination['type'] ?? '') === 'cursor') {
                // Next URLs never become credentials-bearing requests; only configured cursor values are accepted.
                $cursor = $this->bindings->path($result,$pagination['next_cursor_path'] ?? 'next_cursor');
                if ($cursor === null || $cursor === '') { break; }
                if (! is_scalar($cursor) || isset($seen[(string) $cursor])) { throw new \DomainException('api_tool_cursor_invalid'); }
                $seen[(string) $cursor] = true;
            }
        }
        return $all;
    }

    public function stepOutputs(ApiToolRun $run): array
    {
        $outputs = [];
        foreach (ApiToolStep::query()->forTenant($run->tenant_id)->where('api_tool_run_id',$run->id)->whereIn('status',['completed','partial'])->orderBy('id')->get() as $step) { $outputs[$step->step_id] = $step->output['value']; }
        return $outputs;
    }

    public function finish(string $runId,string $status,?string $errorCode = null): void
    {
        DB::transaction(function () use ($runId,$status,$errorCode): void {
            $run = ApiToolRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if ($run->terminal()) { return; }
            $context = AgentExecutionContext::fromArray($run->context);
            $context->activate();
            try { $this->access->assertActor($context); }
            catch (\Throwable) { $status = 'failed'; $errorCode = 'api_tool_authorization_revoked'; }
            if ($run->cancelled_at) { $status='cancelled'; }
            elseif ($run->deadline_at->isPast()) { $status='timed_out'; $errorCode='api_tool_timeout'; }
            if (ApiToolStep::query()->where('api_tool_run_id',$runId)->where('status','failed')->exists() && ! in_array($status,['cancelled','timed_out'],true)) { $status = 'failed'; }
            if ($status === 'completed' && ApiToolStep::query()->where('api_tool_run_id',$runId)->where('status','partial')->exists()) { $status = 'partial'; }
            $run->forceFill(['status'=>$status,'error_code'=>$errorCode === 'api_tool_authorization_revoked' ? $errorCode : ($run->error_code ?? $errorCode),'completed_at'=>now()])->save();
            app(ToolLifecycle::class)->recordOutcome($run);
            if (($run->continuation['type'] ?? '') === 'verification') { app(ToolVerificationService::class)->consumeRun($run); }
            app(ToolContinuation::class)->dispatchIfReady($run);
        });
    }

    /** Recover delivery gaps and completed domain steps after an interrupted worker. */
    public function recover(ApiToolRun $run): void
    {
        if ($run->terminal() || ! $run->flow_run_id) { return; }
        $context = AgentExecutionContext::fromArray($run->context);
        $context->activate();
        $graph = app(ToolGraphCompiler::class)->compile($run->snapshot['definition'],$run->id);
        DB::transaction(function () use ($run,$graph): void {
            DB::table('flow_runs')->where('id',$run->flow_run_id)->lockForUpdate()->first();
            foreach (ApiToolStep::query()->forTenant($run->tenant_id)->where('api_tool_run_id',$run->id)->whereIn('status',['completed','partial','failed'])->get() as $step) {
                $current = DB::table('flow_run_nodes')->where('run_id',$run->flow_run_id)->where('node_id',$step->step_id)->value('status');
                if (! in_array($current,['pending','running'],true)) { continue; }
                app(\Padosoft\LaravelFlow\Contracts\FlowStore::class)->runNodes()->createOrUpdate($run->flow_run_id,$step->step_id,[
                    'node_type'=>'askmydocs.api_tool.operation',
                    'status'=>$step->status === 'failed' ? 'failed':'succeeded',
                    'outputs'=>$step->status === 'failed' ? []:['ref'=>['run'=>$run->id,'step'=>$step->step_id]],
                    'finished_at'=>now(),
                    'error_message'=>$step->status === 'failed' ? 'api_tool_step_failed':null,
                ]);
            }
            $job = new CoordinatorJob($run->flow_run_id,$graph,'askmydocs.api_tool',['api_tool_run_id'=>$run->id],queue:config('api-tools.queue','api-tools'),lockStore:config('laravel-flow.executor.lock_store'),lockSeconds:max((int) config('api-tools.node_lock_seconds',60),(int) $run->snapshot['definition']['limits']['timeout_seconds']+30),lockRetrySeconds:5);
            if ($connection=config('api-tools.connection')) { $job->onConnection($connection); }
            app(Dispatcher::class)->dispatch($job);
        });
    }

    public function cancel(ApiToolRun $run): void
    {
        $run->forceFill(['cancelled_at'=>now()])->save();
        $this->finish($run->id,'cancelled','api_tool_cancelled');
    }
}
