# Decision models

`App\Decisions\Decisions` is an internal, fluent API for typed judgments. The
first adapter uses JEV through OpenRouter's **Decisions** endpoint, not chat
completions. Set `OPENROUTER_API_KEY` and optionally `DECISION_MODEL`, `JEV_MODEL`
and `JEV_TIMEOUT`. The default model is pinned to `typesafe/jev-1.13`.

```php
use App\Decisions\Decisions;

$result = Decisions::using('jev')
    ->withState(['question' => 'Qual è lo stato di SPD-51230?', 'candidate' => $authorizedResult])
    ->relevance()
    ->sufficiency()
    ->conflicts()
    ->decide(); // One remote request for all three questions.

$json = $result->toJson();
$yes = $result->toBool('relevant', 0.85); // Throws when inconclusive.
```

For custom questions, use `yesNo($key, $instructions, ['true' => ..., 'false' => ...])`,
`choice($key, $instructions, ['name' => 'criterion', ...])`, or
`score($key, $instructions, ['low', 'medium', 'high'])`. A different adapter can
implement `DecisionModel` and be registered in `config/decisions.php`.

## Request size

`DECISION_MAX_STATE_BYTES=32768` is an application guardrail (32 KiB), not a
provider token limit. `DecisionState` applies the existing secret/PII masking
and measures UTF-8 JSON consistently in both the agent's candidate packer and
`Decisions::decide()`. An exactly-at-limit state is accepted; an oversized one
is rejected before the network call. There is no hidden 1,000-byte reserve.
The separate 12,000-byte questions guardrail and ten-question maximum remain.

The [OpenRouter JEV guide](https://openrouter.ai/docs/guides/community/jev)
documents a 32,000-token context window for **state plus questions**. Bytes and
tokens are not interchangeable: 32 KiB is a bounded local default, not a claim
that every payload below it fits the model context. Before raising it again,
measure total input tokens, cost and latency on representative multilingual
payloads, including question instructions. Provider failures still abstain;
they never approve claims implicitly.

Existing deployments with an explicit `DECISION_MAX_STATE_BYTES=16000` must
update that override. Clear/rebuild configuration and gracefully restart queue
workers after rollout. No migration, seeding or frontend build is required.

Try the service without database changes:

```bash
php artisan decision:yes-no 'Ordine 88512: consegna ritardata' 'Il testo parla di un ordine?' --true='Indica un ordine' --false='Non indica un ordine' --threshold=0.85 --json
php artisan decision:choice 'SPD-51230 consegnata a Messina' 'Che tipo di record è?' --option=shipment:'Una spedizione' --option=order:'Un ordine' --json
php artisan decision:evidence 'Dove è stata consegnata SPD-51230?' --evidence='SPD-51230 consegnata a Messina' --claim='La spedizione è arrivata a Messina' --threshold=0.85 --json
```

These commands call OpenRouter and incur usage charges. They do not establish
tenant authorization or source validity. Supply only already authorized excerpts.
The agent's semantic gate is off by default; enable it only after calibration
with `AGENT_SEMANTIC_GROUNDING_ENABLED=true` and a measured
`AGENT_SEMANTIC_GROUNDING_THRESHOLD`. Exact source, hash and quote validation
remains mandatory and precedes the semantic judgment. Unavailable or uncertain
decisions preserve the deterministic path; they are never interpreted as "no".
