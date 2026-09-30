<?php

declare(strict_types=1);

namespace App\Agent\Grounding;

use App\Decisions\DecisionException;
use App\Decisions\Decisions;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One bounded semantic decision for independently source-bound agent claims. */
final readonly class AgentClaimBatchEvaluator
{
    public function __construct(private AgentClaimGroundingValidator $validator) {}

    /**
     * @param array<string,mixed> $evidence
     * @param list<string> $mentions
     * @return array{valid:bool,reason:?string,terms:list<string>,claims:list<array<string,mixed>>,semantic_validation:list<array<string,mixed>>,partial:bool}
     */
    public function evaluate(string $question, array $evidence, mixed $claims, array $mentions = [], string $projectKey = '', string $tenantId = ''): array
    {
        $accepted = [];
        $pairs = [];
        $checks = [];
        $excluded = 0;
        $claims = is_array($claims) ? array_values($claims) : [];
        $threshold = (float) config('agent.grounding.semantic.threshold', 0.8);
        $maxBytes = (int) config('decisions.max_state_bytes', 16000);
        $state = ['question' => mb_substr($question, 0, 1500), 'pairs' => []];

        foreach ($claims as $index => $claim) {
            if (! is_array($claim) || count($pairs) >= 10) {
                $checks[] = ['claim_index' => $index, 'status' => 'excluded', 'reason' => 'claim_limit'];
                $excluded++;
                continue;
            }
            $requiredMentions = $this->relevantMentions($question, (string) ($claim['text'] ?? ''), $mentions);
            $source = $this->source($evidence, $claim, $question, $requiredMentions, $projectKey, $tenantId);
            if ($source === null || trim((string) ($claim['text'] ?? '')) === '') {
                $checks[] = ['claim_index' => $index, 'status' => 'excluded', 'reason' => 'invalid_source'];
                $excluded++;
                continue;
            }
            $bound = [...$claim, 'quote' => $source];
            $verified = $this->validator->validate($question, $evidence, [$bound]);
            if (! $verified['valid'] || ! $this->sourceMatchesMentions($question, $source, $requiredMentions)) {
                $checks[] = ['claim_index' => $index, 'status' => 'excluded', 'reason' => $verified['reason'] ?? 'entity_mismatch'];
                $excluded++;
                continue;
            }
            $excerpt = $this->excerpt($source, $question, $requiredMentions);
            if ($excerpt === null) {
                $checks[] = ['claim_index' => $index, 'status' => 'excluded', 'reason' => 'source_too_large'];
                $excluded++;
                continue;
            }
            $pair = ['claim' => mb_substr((string) $claim['text'], 0, 1000), 'source' => $excerpt];
            $candidate = $state;
            $candidate['pairs'][] = $pair;
            if (strlen(json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') > $maxBytes - 1000) {
                $checks[] = ['claim_index' => $index, 'status' => 'excluded', 'reason' => 'state_limit'];
                $excluded++;
                continue;
            }
            $state = $candidate;
            $original = $this->validator->validate($question, $evidence, [$claim]);
            $pairs[] = ['index' => $index, 'claim' => $verified['claims'][0],
                'fallback_claim' => $this->structuredFallback($verified['claims'][0], $source, $requiredMentions)
                    ?? ($original['valid'] && $this->sourceMatchesMentions($question, $original['claims'][0]['quote'], $requiredMentions)
                        ? $verified['claims'][0] : null)];
        }

        $metadata = ['stage' => 'claim_batch', 'attempted' => false, 'used' => false,
            'status' => 'not_used', 'checks' => $checks, 'model' => null, 'usage' => [], 'latency_ms' => null];
        if ($pairs !== []) {
            try {
                $decision = Decisions::using()->withState($state);
                foreach ($pairs as $position => $pair) {
                    $decision = $decision->yesNo('claim_'.($position + 1),
                        'For state.pairs['.$position.'], does its source directly support every factual part of its claim and address the user question?', [
                            'true' => 'The source explicitly supports the claim about the requested entity and topic.',
                            'false' => 'The source concerns another entity/topic, contradicts the claim, or omits a material asserted detail.',
                        ]);
                }
                $metadata['attempted'] = true;
                $result = $decision->decide();
                $metadata['used'] = true;
                $metadata['status'] = 'completed';
                $metadata['model'] = $result->model;
                $metadata['usage'] = $result->usage;
                $metadata['latency_ms'] = $result->latencyMs;
                foreach ($pairs as $position => $pair) {
                    $key = 'claim_'.($position + 1);
                    $probability = $result->answers[$key]['probability_true'];
                    try {
                        $approved = $result->toBool($key, $threshold);
                        $status = $approved ? 'accepted' : 'rejected';
                    } catch (DecisionException) {
                        $approved = $pair['fallback_claim'] !== null;
                        $status = 'inconclusive';
                    }
                    $metadata['checks'][] = ['claim_index' => $pair['index'], 'status' => $status,
                        'probability_true' => $probability, 'threshold' => $threshold,
                        'fallback' => $status === 'inconclusive' && $approved ? 'structured_or_literal' : null];
                    if ($approved) {
                        $chosen = $status === 'inconclusive' ? $pair['fallback_claim'] : $pair['claim'];
                        if (! in_array($chosen, $accepted, true)) {
                            $accepted[] = $chosen;
                        }
                    } else {
                        $excluded++;
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Agent claim batch decision unavailable.', ['exception_class' => $exception::class]);
                $metadata['status'] = 'error';
                $metadata['reason'] = 'provider_or_response_error';
                foreach ($pairs as $pair) {
                    if ($pair['fallback_claim'] !== null) {
                        if (! in_array($pair['fallback_claim'], $accepted, true)) {
                            $accepted[] = $pair['fallback_claim'];
                        }
                    } else {
                        $excluded++;
                    }
                    $metadata['checks'][] = ['claim_index' => $pair['index'],
                        'status' => $pair['fallback_claim'] !== null ? 'fallback_deterministic' : 'excluded',
                        'reason' => $pair['fallback_claim'] !== null ? null : 'unverified_original_quote'];
                }
            }
        }
        $combined = $accepted === []
            ? ['valid' => false, 'reason' => 'missing_claims', 'terms' => [], 'claims' => []]
            : $this->validator->validate($question, $evidence, $accepted, $mentions);
        if (! $combined['valid']) {
            $accepted = [];
        }
        $partial = $excluded > 0 || in_array($metadata['status'], ['error'], true)
            || in_array('inconclusive', array_column($metadata['checks'], 'status'), true);

        return [...$combined, 'claims' => $accepted, 'semantic_validation' => [$metadata], 'partial' => $partial];
    }

    /** @param array<string,mixed> $claim @param list<string> $mentions @return array<string,mixed>|null */
    private function structuredFallback(array $claim, string $source, array $mentions): ?array
    {
        if (($claim['tool_execution_id'] ?? null) === null) {
            return null;
        }
        $record = json_decode($source, true);
        if (! is_array($record) || array_is_list($record) || ! is_string($record['id'] ?? null)) {
            return null;
        }
        $identities = array_filter([$record['id'], $record['trackingCode'] ?? null,
            $record['orderId'] ?? null, $record['customerId'] ?? null], 'is_string');
        $identities = array_map('mb_strtolower', $identities);
        foreach ($mentions as $mention) {
            if (is_string($mention) && $mention !== '' && ! in_array(mb_strtolower($mention), $identities, true)) {
                return null;
            }
        }
        // Render only fields from the authorized MCP record, never the
        // model's unverified paraphrase.
        $rows = [];
        foreach ($record as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $display = is_scalar($value) || $value === null
                ? (string) $value
                : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $rows[] = '| '.str_replace('|', '\\|', $key).' | '.str_replace(['|', "\n"], ['\\|', ' '], $display).' |';
        }
        return $rows === [] ? null
            : [...$claim, 'text' => "| Campo | Valore |\n|---|---|\n".implode("\n", $rows)];
    }

    /** @param array<string,mixed> $evidence @param array<string,mixed> $claim @param list<string> $mentions */
    private function source(array $evidence, array $claim, string $question, array $mentions, string $projectKey, string $tenantId): ?string
    {
        $documentId = $claim['document_id'] ?? null;
        $executionId = $claim['tool_execution_id'] ?? null;
        $hash = $claim['evidence_hash'] ?? null;
        if (($documentId === null) === ($executionId === null) || ! is_string($hash) || $hash === '') {
            return null;
        }
        if ($documentId !== null) {
            foreach ($evidence['documents'] ?? [] as $document) {
                if (($document['document_id'] ?? null) != $documentId) {
                    continue;
                }
                if ($projectKey !== '' && isset($document['project_key']) && $document['project_key'] !== $projectKey) {
                    return null;
                }
                if ($tenantId !== '' && isset($document['tenant_id']) && $document['tenant_id'] !== $tenantId) {
                    return null;
                }
                foreach ($document['evidence'] ?? [] as $chunk) {
                    if (($chunk['evidence_hash'] ?? null) === $hash && is_string($chunk['content'] ?? null)) {
                        return $chunk['content'];
                    }
                }
            }
            return null;
        }
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            if (($tool['execution_id'] ?? null) === $executionId
                && is_string($tool['evidence_hash'] ?? null)
                && hash_equals($tool['evidence_hash'], $hash)) {
                if ($tenantId !== '' && isset($tool['tenant_id']) && $tool['tenant_id'] !== $tenantId) {
                    return null;
                }
                $result = $tool['result'] ?? [];
                if ($projectKey !== '' && is_array($result) && isset($result['companyKey'])
                    && $result['companyKey'] !== $projectKey) {
                    return null;
                }
                $records = is_array($result) ? ($result['records'] ?? null) : null;
                if (is_array($records) && $mentions !== []) {
                    foreach ($records as $record) {
                        $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if (is_string($encoded) && $this->recordMatchesMentions($encoded, $mentions)) {
                            return $encoded;
                        }
                    }
                    return null;
                }
                return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
            }
        }
        return null;
    }

    /** @param list<string> $mentions */
    private function relevantMentions(string $question, string $claim, array $mentions): array
    {
        $mentioned = array_values(array_filter($mentions, static fn (mixed $mention): bool =>
            is_string($mention) && $mention !== ''
            && str_contains(mb_strtolower($question), mb_strtolower($mention))));
        $inClaim = array_values(array_filter($mentioned, static fn (string $mention): bool =>
            str_contains(mb_strtolower($claim), mb_strtolower($mention))));
        return $inClaim !== [] ? $inClaim : $mentioned;
    }

    /** @param list<string> $mentions */
    private function recordMatchesMentions(string $record, array $mentions): bool
    {
        foreach ($mentions as $mention) {
            if (preg_match('/(?<![\\pL\\pN])'.preg_quote($mention, '/').'(?![\\pL\\pN])/iu', $record) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $mentions */
    private function sourceMatchesMentions(string $question, string $source, array $mentions): bool
    {
        foreach ($mentions as $mention) {
            if (is_string($mention) && $mention !== ''
                && preg_match('/[0-9]/', $mention) !== 1
                && preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)+$/i', $mention) !== 1) {
                continue;
            }
            if (is_string($mention) && $mention !== ''
                && str_contains(mb_strtolower($question), mb_strtolower($mention))
                && preg_match('/(?<![\\pL\\pN])'.preg_quote($mention, '/').'(?![\\pL\\pN])/iu', $source) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $mentions */
    private function excerpt(string $source, string $question, array $mentions): ?string
    {
        // Short knowledge-base chunks are already bounded by retrieval. Keep
        // them whole for broad questions with no identifier to anchor a window;
        // the aggregate Decisions state still has its separate byte limit.
        if (strlen($source) <= 4000) {
            return $source;
        }
        foreach ($mentions as $mention) {
            if (! is_string($mention) || ! str_contains(mb_strtolower($question), mb_strtolower($mention))) {
                continue;
            }
            $position = mb_stripos($source, $mention);
            if ($position !== false) {
                return mb_substr($source, max(0, $position - 200), 1200);
            }
        }
        return null; // Never silently truncate a source without an entity anchor.
    }
}
