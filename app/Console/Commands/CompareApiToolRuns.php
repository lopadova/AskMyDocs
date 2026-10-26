<?php

declare(strict_types=1);
namespace App\Console\Commands;

use App\ApiTools\ToolMeasurements;
use App\Models\AgentRun;
use Illuminate\Console\Command;

final class CompareApiToolRuns extends Command
{
    protected $signature='api-tools:compare-runs {before} {after} {--tenant= : Required tenant slug}';
    protected $description='Compare measured tokens, latency and HTTP calls for equivalent baseline and composed-tool agent runs';
    public function handle(ToolMeasurements $measurements): int
    {
        if (! is_string($this->option('tenant')) || $this->option('tenant') === '') { $this->error('--tenant is required.'); return self::FAILURE; }
        $runs=[];
        foreach (['before','after'] as $name) { $runs[$name]=AgentRun::query()->forTenant($this->option('tenant'))->where('run_id',$this->argument($name))->first(); }
        if (! $runs['before'] || ! $runs['after']) { $this->error('Both runs must exist in this tenant.'); return self::FAILURE; }
        try { $this->line(json_encode($measurements->compare($runs['before'],$runs['after']),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)); return self::SUCCESS; }
        catch (\DomainException $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
