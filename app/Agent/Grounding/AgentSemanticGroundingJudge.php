<?php

declare(strict_types=1);

namespace App\Agent\Grounding;

use App\Decisions\DecisionException;
use App\Decisions\Decisions;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Optional semantic gate on claims already bound to authorized evidence. */
final readonly class AgentSemanticGroundingJudge
{
    public function __construct(private WidgetPiiMasker $masker) {}

    /** @param list<array<string,mixed>> $claims */
    public function supported(string $question, array $claims): ?bool
    {
        return $this->evaluate($question, $claims)['answer'];
    }

    /**
     * @param list<array<string,mixed>> $claims
     * @return array{attempted:bool,used:bool,status:string,answer:?bool,probability_true:?float,threshold:?float,model:?string,usage:array<string,mixed>,latency_ms:?float,reason:?string}
     */
    public function evaluate(string $question, array $claims): array
    {
        $trace = [
            'attempted' => false, 'used' => false, 'status' => 'not_used', 'answer' => null,
            'probability_true' => null, 'threshold' => null, 'model' => null,
            'usage' => [], 'latency_ms' => null, 'reason' => null,
        ];
        if (! config('agent.grounding.semantic.enabled', false) || $claims === []) {
            $trace['reason'] = $claims === [] ? 'no_claims' : 'disabled';

            return $trace;
        }
        $threshold = config('agent.grounding.semantic.threshold');
        if (! is_numeric($threshold) || (float) $threshold <= 0.5 || (float) $threshold > 1) {
            Log::warning('Semantic grounding enabled without a calibrated threshold.');
            $trace['reason'] = 'invalid_threshold';

            return $trace;
        }
        $trace['threshold'] = (float) $threshold;
        try {
            $facts = array_map(static fn (array $claim): array => [
                'claim' => (string) ($claim['text'] ?? ''),
                'verified_quote' => (string) ($claim['quote'] ?? ''),
            ], $claims);
            $state = $this->masker->maskArray([
                'question' => mb_substr($question, 0, 2000),
                'facts' => array_slice($facts, 0, 12),
            ]);
            if (! is_array($state)) {
                $trace['reason'] = 'state_unavailable';

                return $trace;
            }

            $trace['attempted'] = true;
            $result = Decisions::using()->withState($state)
                ->yesNo('supported', 'Do these already source-bound claims answer the question without adding unsupported facts?', [
                    'true' => 'Every material factual assertion is supported by its verified quote and the claims address the question.',
                    'false' => 'A material assertion goes beyond its quote or the claims do not address the question.',
                ])->decide();
            $trace['used'] = true;
            $trace['model'] = $result->model;
            $trace['probability_true'] = $result->answers['supported']['probability_true'];
            $trace['usage'] = $result->usage;
            $trace['latency_ms'] = $result->latencyMs;
            try {
                $trace['answer'] = $result->toBool('supported', (float) $threshold);
                $trace['status'] = $trace['answer'] ? 'accepted' : 'rejected';
            } catch (DecisionException) {
                $trace['status'] = 'inconclusive';
                $trace['reason'] = 'below_threshold';
            }

            return $trace;
        } catch (DecisionException $exception) {
            Log::warning('Semantic grounding inconclusive or unavailable.', ['reason' => $exception->getMessage()]);
            $trace['status'] = 'error';
            $trace['reason'] = 'provider_or_response_error';

            return $trace;
        } catch (Throwable $exception) {
            Log::warning('Semantic grounding failed.', ['exception_class' => $exception::class]);
            $trace['status'] = 'error';
            $trace['reason'] = 'unexpected_error';

            return $trace;
        }
    }
}
