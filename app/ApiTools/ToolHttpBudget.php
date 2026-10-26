<?php

declare(strict_types=1);
namespace App\ApiTools;

use App\ApiTools\Models\ApiToolRun;
use Illuminate\Support\Facades\DB;

/** Active only around connector execution; HTTP middleware runs once per wire attempt, never for a cache hit. */
final class ToolHttpBudget
{
    private ?string $runId = null;
    private array $meters = [];
    private ?int $routeId = null;
    public function within(string $runId, callable $call, ?int $routeId = null): mixed
    {
        $previous = [$this->runId,$this->routeId];
        $this->runId = $runId; $this->routeId = $routeId;
        try { return $call(); } finally { [$this->runId,$this->routeId] = $previous; }
    }
    public function measure(callable $call, int $limit = PHP_INT_MAX): array
    {
        $this->meters[] = ['count'=>0,'limit'=>$limit];
        $index = array_key_last($this->meters);
        try { $body = $call(); return ['body'=>$body,'physical_requests'=>$this->meters[$index]['count']]; }
        finally { array_pop($this->meters); }
    }
    public function activeRunId(): ?string { return $this->runId; }
    public function beforeRequest(): void
    {
        foreach ($this->meters as $meter) { if ($meter['count'] >= $meter['limit']) { throw new \DomainException('physical_hard_limit'); } }
        if ($this->runId !== null) {
            $run=ApiToolRun::query()->whereKey($this->runId)->firstOrFail();
            $this->assertAvailable($run,$this->routeId);
        }
        foreach ($this->meters as &$meter) { $meter['count']++; }
        unset($meter);
        if ($this->runId === null) { return; }
        $debited = ApiToolRun::query()->whereKey($this->runId)
            ->whereNull('cancelled_at')->whereIn('status',['queued','running'])
            ->where('deadline_at','>',now())->whereColumn('physical_requests','<','request_limit')
            ->increment('physical_requests');
        if ($debited === 1) { return; }
        $run = ApiToolRun::query()->whereKey($this->runId)->firstOrFail();
        if ($run->terminal() || $run->cancelled_at) { throw new \DomainException('api_tool_cancelled'); }
        if ($run->deadline_at->isPast()) { throw new \DomainException('api_tool_timeout'); }
        throw new \DomainException('api_tool_budget_exhausted');
    }
    public function assertAvailable(ApiToolRun $run, ?int $routeId = null): void
    {
        $context=\App\Agent\AgentExecutionContext::fromArray($run->context);
        $actor=app(ToolAccess::class)->assertActor($context);
        if ($run->terminal() || $run->cancelled_at) { throw new \DomainException('api_tool_cancelled'); }
        if ($run->deadline_at->isPast()) { throw new \DomainException('api_tool_timeout'); }
        if ($run->mode === 'verification' && ! $actor->can('manageConnectors')) { throw new \DomainException('api_tool_authorization_revoked'); }
        if (\App\ApiTools\Models\ApiTool::query()->forTenant($run->tenant_id)->whereKey($run->api_tool_id)->where('manual_disabled',true)->exists()) { throw new \DomainException('api_tool_disabled'); }
        if ($routeId !== null) {
            $route=app(ToolAccess::class)->route($routeId,$context);
            if (app(ContentHasher::class)->endpoint($route) !== ($run->snapshot['dependencies'][$routeId] ?? null)) { throw new \DomainException('api_tool_configuration_changed'); }
        }
    }
}
