<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentExecutionContext;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Models\AgentRun;

/** Literal identifiers may come from the original request or authorized observations, never a generated plan. */
final class AgentIdentifierSources
{
    public function required(array $tools): bool
    {
        foreach ($tools as $tool) {
            foreach ($tool->inputSchema['properties'] ?? [] as $property) {
                if ($tool->kind === 'api' && ($property['x-require-observed-identifier'] ?? false) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    public function forContext(AgentExecutionContext $context, AgentEvidenceEnvelope $evidence): array
    {
        $run = AgentRun::query()->forTenant($context->tenantId)->where('run_id', $context->runId)->firstOrFail();
        $context->assertMatches($run);

        return $this->forRun($run, $evidence);
    }

    public function forRun(AgentRun $run, AgentEvidenceEnvelope $evidence): array
    {
        $original = $run;
        $parentId = data_get($run->input_json, 'research_parent_id');
        if ($parentId !== null) {
            $original = AgentRun::query()->forTenant($run->tenant_id)->where('project_key', $run->project_key)
                ->where('conversation_id', $run->conversation_id)->where('actor_type', $run->actor_type)
                ->where('actor_id', $run->actor_id)->findOrFail($parentId);
            $flow = data_get($run->input_json, 'research_flow_id');
            if (data_get($original->result_json, 'research_runs.'.$flow) !== $run->id
                || $original->user_id !== $run->user_id || $original->widget_identity_id !== $run->widget_identity_id) {
                throw new \DomainException('unregistered_identifier_source_parent');
            }
        }

        return $this->texts((string) data_get($original->input_json, 'question', ''), $evidence);
    }

    public function texts(string $originalQuestion, AgentEvidenceEnvelope $evidence): array
    {
        $texts = [$originalQuestion];
        foreach ($evidence->documents() as $document) {
            foreach ($document['evidence'] ?? [] as $chunk) {
                if (is_string($chunk['content'] ?? null)) {
                    $texts[] = $chunk['content'];
                }
            }
        }
        foreach ($evidence->apiTools() as $tool) {
            $result = $tool['result'] ?? null;
            if (! is_array($result) || ! isset($tool['execution_id']) || ! is_string($tool['evidence_hash'] ?? null)
                || ! hash_equals($tool['evidence_hash'], hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE)))) {
                continue;
            }
            array_walk_recursive($result, static function ($value) use (&$texts): void {
                if (is_string($value)) {
                    $texts[] = $value;
                }
            });
        }

        return $texts;
    }

    public static function contains(string $value, array $sources): bool
    {
        $pattern = '/(?<![\p{L}\p{N}_-])'.preg_quote($value, '/').'(?![\p{L}\p{N}_-])/u';
        foreach ($sources as $source) {
            if (is_string($source) && preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }
}
