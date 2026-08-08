<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;

/**
 * Deterministic, offline AI provider for end-to-end tests (and local
 * demos). Makes NO external HTTP calls — chat answers are canned and
 * embeddings are a constant unit vector.
 *
 * Why it exists: the Playwright browser E2E (`chat-stream-browser.spec.ts`)
 * must drive the REAL `/messages/stream` SSE through the REAL `@ai-sdk`
 * transport in the browser — that is the only layer that validates each
 * UIMessageChunk against the SDK zod schema (the layer where the v8.4
 * source-url / finish wire-format crashes actually fired). To do that
 * deterministically in CI — without a live LLM, without an API key, and with
 * GUARANTEED citations so the `source-url` frame is exercised — the back-end
 * needs a provider that:
 *
 *   - streams a fixed, non-empty answer (so text-* + finish frames flow), and
 *   - returns a CONSTANT embedding vector for every input, so every ingested
 *     chunk and every query map to the same vector → cosine 1.0 → retrieval
 *     always returns the seeded chunk → the controller always emits a
 *     `source-url` citation frame.
 *
 * Selected by pointing `ai.default` / `ai.embeddings_provider` at 'fake'
 * — the E2E/local path does that via `AI_PROVIDER=fake` +
 * `AI_EMBEDDINGS_PROVIDER=fake` (see playwright.config.ts webServer env).
 * NEVER usable in production: AiManager::resolveFakeProvider() throws unless
 * the app is in the testing or local environment, regardless of config.
 */
final class FakeProvider implements AiProviderInterface
{
    use FallbackStreaming;

    /** Canned grounded answer streamed for every chat turn. */
    public const ANSWER = 'Based on the knowledge base, employees may work remotely up to 3 days per week with manager approval.';

    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config = []) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->chatWithHistory($systemPrompt, [['role' => 'user', 'content' => $userMessage]], $options);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        // The durable retrieval agent uses forced structured-output tools for
        // planning and synthesis. Supporting those two contracts here lets the
        // browser E2E exercise the real queue, HTTP tools and SSE transport
        // without an external model. FakeProvider remains hard-gated to
        // local/testing by AiManager, so this deterministic scenario can never
        // influence a production answer.
        $forcedFunction = $this->forcedFunction($options);
        if ($forcedFunction === 'submit_agent_plan') {
            return $this->structuredResponse($forcedFunction, $this->agentPlan($systemPrompt, $messages));
        }
        if ($forcedFunction === 'submit_agent_answer') {
            return $this->structuredResponse($forcedFunction, $this->agentAnswer($systemPrompt, $messages));
        }

        // Function-calling deterministico per il widget KITT (R13 / M4.14): quando
        // l'orchestratore offre dei tool, il FakeProvider emette una sequenza di
        // tool_call SCRIPTATA (per parola-chiave del messaggio utente + numero di
        // azioni già in cronologia), così l'E2E agentico gira contro il VERO
        // orchestratore/executor/bridge invece di stubbare le rotte interne.
        // Nessun tool offerto (o nessun trigger) ⇒ risposta testuale canned.
        $toolCalls = $this->scriptToolCalls($messages, $options);
        if ($toolCalls !== []) {
            return new AiResponse(
                content: '',
                provider: 'fake',
                model: $this->modelName('chat_model'),
                promptTokens: 11,
                completionTokens: 7,
                totalTokens: 18,
                finishReason: 'tool_calls',
                toolCalls: $toolCalls,
            );
        }

        return new AiResponse(
            content: self::ANSWER,
            provider: 'fake',
            model: $this->modelName('chat_model'),
            promptTokens: 11,
            completionTokens: 17,
            totalTokens: 28,
            finishReason: 'stop',
        );
    }

    /** @param array<string,mixed> $options */
    private function forcedFunction(array $options): ?string
    {
        $choice = $options['tool_choice'] ?? null;
        if (! is_array($choice)) {
            return null;
        }
        $function = $choice['function'] ?? null;

        return is_array($function) && is_string($function['name'] ?? null)
            ? $function['name']
            : null;
    }

    /** @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    private function agentPlan(string $systemPrompt, array $messages): array
    {
        $payload = $this->lastJsonPayload($messages);
        $question = mb_strtolower((string) ($payload['question'] ?? ''));
        $completed = is_array($payload['completed_actions'] ?? null)
            ? $payload['completed_actions']
            : [];
        $toolNames = [];
        foreach (is_array($payload['available_tools'] ?? null) ? $payload['available_tools'] : [] as $tool) {
            if (is_array($tool) && is_string($tool['name'] ?? null)) {
                $toolNames[] = $tool['name'];
            }
        }

        if ($completed !== []) {
            return ['decision' => 'answer', 'actions' => []];
        }
        if ((str_contains($question, 'ordin') || str_contains($question, 'order'))
            && in_array('find_customer', $toolNames, true)
            && in_array('get_orders', $toolNames, true)) {
            $italian = $this->expectsItalian($systemPrompt);
            $customer = str_contains($question, '503') ? '503' : 'Tizio';

            return [
                'decision' => 'tools',
                'actions' => [
                    [
                        'id' => 'find_customer',
                        'tool' => 'find_customer',
                        'arguments' => ['name' => $customer],
                        'depends_on' => [],
                        'purpose' => $italian ? 'Cerco il cliente richiesto' : 'Find the requested customer',
                    ],
                    [
                        'id' => 'load_orders',
                        'tool' => 'get_orders',
                        'arguments' => [
                            'customer_id' => ['$from' => 'find_customer', 'path' => 'items.0.id'],
                        ],
                        'depends_on' => ['find_customer'],
                        'purpose' => $italian ? 'Recupero tutti gli ordini del cliente' : 'Load all customer orders',
                    ],
                ],
            ];
        }

        return ['decision' => 'answer', 'actions' => []];
    }

    /** @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    private function agentAnswer(string $systemPrompt, array $messages): array
    {
        $payload = $this->lastJsonPayload($messages);
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $tools = is_array($evidence['api_tools'] ?? null) ? $evidence['api_tools'] : [];
        $documents = is_array($evidence['documents'] ?? null) ? $evidence['documents'] : [];
        $italian = $this->expectsItalian($systemPrompt);
        $orderNumbers = [];
        $hasError = false;
        $toolExecutionIds = [];
        $customer = 'Tizio';

        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $executionId = (int) ($tool['execution_id'] ?? 0);
            if ($executionId > 0) {
                $toolExecutionIds[] = $executionId;
            }
            $arguments = is_array($tool['arguments'] ?? null) ? $tool['arguments'] : [];
            if (is_string($arguments['name'] ?? null) && $arguments['name'] !== '503') {
                $customer = $arguments['name'];
            }
            $result = is_array($tool['result'] ?? null) ? $tool['result'] : [];
            $hasError = $hasError || $this->containsKey($result, 'error');
            foreach ($this->valuesForKeys($result, ['number', 'order_number']) as $number) {
                if (is_scalar($number) && (string) $number !== '') {
                    $orderNumbers[] = (string) $number;
                }
            }
        }
        $orderNumbers = array_values(array_unique($orderNumbers));
        $documentIds = array_values(array_filter(array_map(
            static fn (mixed $document): int => is_array($document) ? (int) ($document['document_id'] ?? 0) : 0,
            $documents,
        )));

        if ($orderNumbers !== []) {
            $answer = $italian
                ? sprintf('Ho trovato %d ordini per %s: %s.', count($orderNumbers), $customer, implode(', ', $orderNumbers))
                : sprintf('I found %d orders for %s: %s.', count($orderNumbers), $customer, implode(', ', $orderNumbers));
            $completeness = $hasError ? 'partial' : 'complete';
            $limitations = $hasError
                ? [$italian ? 'Alcune richieste API non sono state completate.' : 'Some API requests did not complete.']
                : [];
        } elseif ($hasError) {
            $answer = $italian
                ? 'Non ho potuto recuperare gli ordini perché il servizio esterno non è temporaneamente disponibile.'
                : 'I could not retrieve the orders because the external service is temporarily unavailable.';
            $completeness = 'partial';
            $limitations = [$italian ? 'Il servizio ordini ha restituito un errore temporaneo.' : 'The orders service returned a temporary error.'];
        } else {
            $answer = $italian
                ? 'Ho completato la ricerca con le informazioni disponibili.'
                : 'I completed the search with the available information.';
            $completeness = ($tools === [] && $documents === []) ? 'insufficient' : 'complete';
            $limitations = $completeness === 'insufficient'
                ? [$italian ? 'Non sono state trovate evidenze pertinenti.' : 'No relevant evidence was found.']
                : [];
        }

        return [
            'answer' => $answer,
            'completeness' => $completeness,
            'document_ids' => $documentIds,
            'tool_execution_ids' => array_values(array_unique($toolExecutionIds)),
            'limitations' => $limitations,
        ];
    }

    /** @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    private function lastJsonPayload(array $messages): array
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $content = $messages[$index]['content'] ?? null;
            if (! is_string($content)) {
                continue;
            }
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** @param array<string,mixed> $arguments */
    private function structuredResponse(string $name, array $arguments): AiResponse
    {
        return new AiResponse(
            content: '',
            provider: 'fake',
            model: $this->modelName('chat_model'),
            promptTokens: 11,
            completionTokens: 7,
            totalTokens: 18,
            finishReason: 'tool_calls',
            toolCalls: [['name' => $name, 'arguments' => $arguments]],
        );
    }

    private function expectsItalian(string $systemPrompt): bool
    {
        return preg_match('/\\bin\\s+it(?:-[a-z0-9]{2,8})*(?=[\\s.,;])/i', $systemPrompt) === 1;
    }

    /** @param array<mixed> $value */
    private function containsKey(array $value, string $needle): bool
    {
        foreach ($value as $key => $item) {
            if ($key === $needle) {
                return true;
            }
            if (is_array($item) && $this->containsKey($item, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $value @param list<string> $keys @return list<mixed> */
    private function valuesForKeys(array $value, array $keys): array
    {
        $found = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $keys, true)) {
                $found[] = $item;
            }
            if (is_array($item)) {
                array_push($found, ...$this->valuesForKeys($item, $keys));
            }
        }

        return $found;
    }

    /**
     * Sequenza scriptata di tool_call deterministica per gli scenari agentici.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return list<array{name: string, arguments: string}>
     */
    private function scriptToolCalls(array $messages, array $options): array
    {
        // Attivo solo in modalità function-calling (tool offerti dall'orchestratore).
        $tools = $options['tools'] ?? null;
        if (! is_array($tools) || $tools === []) {
            return [];
        }

        $intent = $this->latestUserIntent($messages);
        if ($intent === '') {
            return [];
        }

        // Quante tool_call sono già state emesse in cronologia ([azione] …).
        $emitted = $this->countEmittedActions($messages);

        // Scenario DOM multi-step: compila il profilo (type → click → report_done).
        if ($this->intentMatches($intent, ['compila', 'profilo', 'fill the profile', 'profile'])) {
            return match (true) {
                $emitted === 0 => [$this->toolCall('type', ['field' => 'full-name', 'value' => 'Mario Rossi'])],
                $emitted === 1 => [$this->toolCall('click', ['target' => 'submit'])],
                default => [$this->toolCall('report_done', ['summary' => 'Profilo compilato e salvato con successo.'])],
            };
        }

        // Scenario BE-tool: ricerca nella KB (search_knowledge_base → risposta testo).
        if ($this->intentMatches($intent, ['policy', 'remote work', 'cerca', 'search'])) {
            if ($emitted === 0) {
                return [$this->toolCall('search_knowledge_base', ['query' => $intent])];
            }

            return [];
        }

        return [];
    }

    /**
     * Ultimo messaggio utente "umano" (esclude le righe [risultato]/[azione]
     * reiniettate dall'orchestratore nella cronologia).
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function latestUserIntent(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            $content = (string) ($message['content'] ?? '');
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            if (str_starts_with($content, '[risultato]') || str_starts_with($content, '[azione]')) {
                continue;
            }

            return mb_strtolower(trim($content));
        }

        return '';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function countEmittedActions(array $messages): int
    {
        $count = 0;
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'assistant' && str_starts_with((string) ($message['content'] ?? ''), '[azione]')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<string>  $needles
     */
    private function intentMatches(string $intent, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($intent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{name: string, arguments: string}
     */
    private function toolCall(string $name, array $args): array
    {
        return [
            'name' => $name,
            'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        yield from $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        $dimensions = (int) ($this->config['dimensions'] ?? config('kb.embeddings_dimensions', 1536));

        // Fail loudly on a misconfigured dimension rather than silently
        // clamping with max(1, …) — a 0/negative value means a broken
        // KB_EMBEDDINGS_DIMENSIONS, which would otherwise surface much later
        // as a confusing pgvector dimension-mismatch on the first write.
        if ($dimensions < 1) {
            throw new \InvalidArgumentException(
                "FakeProvider requires a positive embedding dimension; got {$dimensions}. "
                . 'Check KB_EMBEDDINGS_DIMENSIONS / ai.providers.fake.dimensions.'
            );
        }

        // Constant unit vector — [1, 0, 0, …]. Every text (corpus chunk OR
        // query) maps to the same vector, so cosine similarity is always 1.0
        // and retrieval deterministically returns whatever was ingested. No
        // external call; no randomness.
        $vector = array_fill(0, $dimensions, 0.0);
        $vector[0] = 1.0;

        $embeddings = array_map(static fn () => $vector, $texts);

        return new EmbeddingsResponse(
            embeddings: $embeddings,
            provider: 'fake',
            model: $this->modelName('embeddings_model'),
            totalTokens: 0,
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Single source of truth for the model string stamped on responses:
     * the injected config (ai.providers.fake.{chat_model,embeddings_model}),
     * falling back to the canonical default when constructed bare (e.g. a
     * unit test that `new FakeProvider()`s without config). Keeps the
     * chat-log model column + EmbeddingCacheService::resolveModelName() lookup
     * key aligned with what is actually persisted.
     */
    private function modelName(string $key): string
    {
        $model = $this->config[$key] ?? null;

        return is_string($model) && $model !== '' ? $model : 'fake-deterministic';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }
}
