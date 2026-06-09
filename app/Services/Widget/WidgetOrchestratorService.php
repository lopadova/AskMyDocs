<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Grounding\ConfidenceCalculator;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Support\Str;

/**
 * WidgetOrchestratorService — il motore del loop ReAct del widget KITT
 * (port di KittOrchestratorService, fuso col RAG di AskMyDocs).
 *
 * A ogni turno:
 *   1. persiste gli step in entrata (messaggio utente, risultato del tool);
 *   2. esegue la retrieval RAG quando c'è una nuova domanda (scope = project
 *      della key; tenant già su TenantContext via ResolveWidgetKey, R30);
 *   3. compone il system prompt (regole agentiche KITT + grounding KB +
 *      snapshot della pagina) e la cronologia;
 *   4. chiama l'LLM in function-calling (tool_choice=auto): l'agente o
 *      RISPONDE groundato con citazioni, o emette UNA tool_call DOM;
 *   5. valida la tool_call contro lo snapshot (sorgente di verità); se non
 *      valida, ripropone con diagnostica fino a max_consecutive_errors → blocked;
 *   6. persiste lo step in uscita e ritorna il payload al FE.
 *
 * ADR fusione: tool_choice=auto (non `required` come KITT puro) per preservare
 * le risposte RAG con citazioni accanto alle azioni sul DOM.
 */
final class WidgetOrchestratorService
{
    /** Tentativi massimi di rigenerare una tool_call valida nello stesso turno. */
    private const MAX_TOOL_RETRIES = 4;

    /** Cronologia massima passata al modello (turni recenti). */
    private const HISTORY_LIMIT = 24;

    public function __construct(
        private readonly WidgetSkillRegistry $skills,
        private readonly WidgetToolCatalog $catalog,
        private readonly WidgetToolValidator $toolValidator,
        private readonly ChatRetrievalService $retrieval,
        private readonly ConfidenceCalculator $confidence,
        private readonly AiManager $ai,
        private readonly WidgetPiiMasker $piiMasker,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function start(WidgetKey $key, array $snapshot, ?string $userMessage, ?string $pageUrl, ?string $origin): array
    {
        $session = WidgetSession::create([
            // tenant_id auto-fill via BelongsToTenant (= tenant della key, R30).
            'widget_key_id' => $key->id,
            'project_key' => $key->project_key,
            'public_session_id' => (string) Str::uuid(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $key->skill,
            'page_url' => $pageUrl,
            'origin' => $origin,
            'meta' => ['consecutive_errors' => 0],
        ]);

        return $this->runTurn($session, $snapshot, $userMessage, null);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $toolResult
     * @return array<string, mixed>
     */
    public function step(WidgetSession $session, array $snapshot, ?string $userMessage, ?array $toolResult): array
    {
        return $this->runTurn($session, $snapshot, $userMessage, $toolResult);
    }

    /**
     * Ritorna il manifest della skill associato alla sessione.
     * Usato dal controller /exec-tool per verificare i tool abilitati.
     *
     * @return array<string, mixed>
     */
    public function getSkillManifest(WidgetSession $session): array
    {
        return $this->skills->get((string) $session->skill) ?? [];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $toolResult
     * @return array<string, mixed>
     */
    private function runTurn(WidgetSession $session, array $snapshot, ?string $userMessage, ?array $toolResult): array
    {
        if (is_string($userMessage) && $userMessage !== '') {
            $this->addStep($session, WidgetSessionStep::KIND_USER_MESSAGE, args: ['content' => $userMessage]);
        }
        if (is_array($toolResult)) {
            $this->addStep(
                $session,
                WidgetSessionStep::KIND_TOOL_RESULT,
                tool: (string) ($toolResult['tool'] ?? ''),
                args: $toolResult,
                diagnostic: is_array($toolResult['diagnostic'] ?? null) ? $toolResult['diagnostic'] : null,
            );
        }

        $manifest = $this->skills->get((string) $session->skill) ?? [];
        $enabled = array_values(array_filter((array) ($manifest['tools_enabled'] ?? []), 'is_string'));

        // F1.4 — host tools (HTP): definizioni fornite inline dalla pagina ospite
        // dentro snapshot.host_tools. Doppio gate: ammessi all'LLM SOLO se la
        // skill ha host_tools_enabled === true (capability) E la widget key della
        // sessione ha host_tools_enabled === true (interruttore operativo del
        // cliente, gestito da UI admin); altrimenti il ramo è ignorato del tutto.
        // Filtrati per host_tools_allowlist (prefisso/nome) quando presente.
        $hostTools = $this->resolveHostTools($manifest, $snapshot, $session);
        $hostToolNames = array_map(static fn (array $t): string => (string) $t['name'], $hostTools);

        $tools = array_merge(
            $this->catalog->openAiTools($enabled),
            $this->wrapHostTools($hostTools),
        );

        $result = null;
        if (is_string($userMessage) && $userMessage !== '') {
            $result = $this->retrieval->retrieve($userMessage, (string) $session->project_key, null);
        }

        $systemPrompt = $this->buildSystemPrompt($snapshot, $result, $hostTools);
        $baseMessages = $this->buildMessages($session);
        $navigateAllowlist = $this->navigateAllowlist($session);

        $start = microtime(true);
        $errors = (int) data_get($session->meta, 'consecutive_errors', 0);
        $extra = [];

        for ($attempt = 0; $attempt < self::MAX_TOOL_RETRIES; $attempt++) {
            $response = $this->ai->chatWithHistory($systemPrompt, array_merge($baseMessages, $extra), [
                'tools' => $tools,
                'tool_choice' => $tools === [] ? 'none' : 'auto',
                'temperature' => 0,
            ]);

            if ($response->toolCalls === []) {
                return $this->finishWithAnswer($session, $snapshot, $response, $result, $start);
            }

            $call = $response->toolCalls[0];
            $name = (string) ($call['name'] ?? '');
            $args = $this->decodeArgs((string) ($call['arguments'] ?? '{}'));

            // F1.4 — host tool: NON validato contro lo snapshot (lo schema vive
            // sulla pagina ospite, non nel catalogo/snapshot DOM) e NON eseguito
            // server-side: si ritorna al FE marcato execution:"host" perché lo
            // esegua FE-proxied verso l'app ospite (spec §3.3).
            if (in_array($name, $hostToolNames, true)) {
                return $this->finishWithToolCall($session, $snapshot, $name, $args, $response, $start, isHost: true);
            }

            $verdict = $this->toolValidator->validate($name, $args, $snapshot, $enabled, $navigateAllowlist);
            if ($verdict['ok']) {
                return $this->finishWithToolCall($session, $snapshot, $name, $args, $response, $start);
            }

            $errors++;
            $extra[] = ['role' => 'assistant', 'content' => '[azione] '.$name.' '.$this->json($args)];
            $extra[] = ['role' => 'user', 'content' => '[risultato] '.$name.' ok=false error='.(string) $verdict['error']];

            if ($errors >= $this->maxConsecutiveErrors($manifest)) {
                return $this->finishBlocked($session, (string) ($verdict['error'] ?? 'Too many invalid actions.'), $errors);
            }
        }

        return $this->finishBlocked($session, 'The assistant could not produce a valid action.', $errors);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function finishWithAnswer(WidgetSession $session, array $snapshot, AiResponse $response, ?SearchResult $result, float $start): array
    {
        $latency = (int) ((microtime(true) - $start) * 1000);
        $citations = $result !== null ? $this->retrieval->buildCitations($result) : [];
        $confidence = $result !== null
            ? $this->confidence->compute(
                primaryChunks: $result->primary,
                minThreshold: (float) config('kb.refusal.min_chunk_similarity', 0.45),
                answerWords: str_word_count($response->content),
                citationsCount: count($citations),
            )
            : 0;

        $this->addStep(
            $session,
            WidgetSessionStep::KIND_BOT_MESSAGE,
            args: ['content' => $response->content],
            snapshotIn: $snapshot,
            tokensIn: $response->promptTokens,
            tokensOut: $response->completionTokens,
            latency: $latency,
        );
        $this->resetErrors($session, WidgetSession::STATUS_ACTIVE);

        return [
            'session' => $this->sessionPayload($session),
            'type' => 'message',
            'answer' => $response->content,
            'citations' => $citations,
            'confidence' => $confidence,
            'meta' => $this->turnMeta($response, $latency),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function finishWithToolCall(WidgetSession $session, array $snapshot, string $name, array $args, AiResponse $response, float $start, bool $isHost = false): array
    {
        $latency = (int) ((microtime(true) - $start) * 1000);
        $def = $isHost ? [] : ($this->catalog->definition($name) ?? []);

        $this->addStep(
            $session,
            WidgetSessionStep::KIND_TOOL_CALL,
            tool: $name,
            args: $args,
            snapshotIn: $snapshot,
            tokensIn: $response->promptTokens,
            tokensOut: $response->completionTokens,
            latency: $latency,
        );

        // F1.4 — un host tool lascia la sessione in attesa dell'esecuzione
        // FE-proxied verso l'app ospite (come un tool BE: WAITING_TOOL).
        $status = match (true) {
            $isHost => WidgetSession::STATUS_WAITING_TOOL,
            $name === 'ask_user' => WidgetSession::STATUS_WAITING_USER,
            $name === 'report_done' => WidgetSession::STATUS_COMPLETED,
            $name === 'report_blocked' => WidgetSession::STATUS_BLOCKED,
            default => WidgetSession::STATUS_WAITING_TOOL,
        };
        $this->resetErrors($session, $status);

        // F1.4 — `execution` è il marcatore canonico verso il FE (spec §3.3):
        // "host" = FE-proxied all'app ospite, "be" = /exec-tool AskMyDocs,
        // "fe" = executor DOM del widget. `is_be_tool` resta per retro-compat
        // (lo legge il widget JS attuale per instradare i tool BE).
        $isBeTool = ! $isHost && ($def['side'] ?? WidgetToolCatalog::SIDE_FE) === WidgetToolCatalog::SIDE_BE;
        $execution = match (true) {
            $isHost => 'host',
            $isBeTool => 'be',
            default => 'fe',
        };

        return [
            'session' => $this->sessionPayload($session),
            'type' => 'tool_call',
            'tool_call' => [
                'tool' => $name,
                'args' => $args,
                'confirmation_required' => (bool) ($def['confirm'] ?? false),
                'is_be_tool' => $isBeTool,
                'is_host_tool' => $isHost,
                'execution' => $execution,
            ],
            'bot_message' => $response->content !== '' ? $response->content : null,
            'meta' => $this->turnMeta($response, $latency),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finishBlocked(WidgetSession $session, string $reason, int $errors): array
    {
        $session->forceFill([
            'status' => WidgetSession::STATUS_BLOCKED,
            'blocked_reason' => $reason,
            'meta' => array_merge((array) $session->meta, ['consecutive_errors' => $errors]),
        ])->save();

        return [
            'session' => $this->sessionPayload($session),
            'type' => 'blocked',
            'reason' => $reason,
        ];
    }

    /**
     * Compone il system prompt del turno. Riceve la STESSA lista di host tool
     * già risolta in runTurn e passata all'LLM ($hostTools), così prompt e
     * tool list concordano: il template elenca esattamente i tool che il
     * modello può chiamare (niente ri-risoluzione divergente). Quando non ci
     * sono host tool il blocco di guida dominio non viene reso (R43: degrado
     * pulito, comportamento KITT/DOM invariato).
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $hostTools  host tool risolti per il turno
     */
    private function buildSystemPrompt(array $snapshot, ?SearchResult $result, array $hostTools = []): string
    {
        $hasKb = $result !== null && ! $result->primary->isEmpty();

        // Solo name + description: è ciò che serve al modello per riconoscere
        // il tool nel prompt (gli stessi campi passati a wrapHostTools per l'LLM).
        $promptHostTools = array_map(static fn (array $t): array => [
            'name' => (string) $t['name'],
            'description' => is_string($t['description'] ?? null) ? $t['description'] : '',
        ], $hostTools);

        return view('prompts.widget_kitt', [
            'hasKb' => $hasKb,
            'chunks' => $result?->primary ?? collect(),
            'expanded' => $result?->expanded ?? collect(),
            'rejected' => $result?->rejected ?? collect(),
            'snapshotJson' => $this->json($snapshot),
            'hasHostTools' => $promptHostTools !== [],
            'hostTools' => $promptHostTools,
        ])->render();
    }

    /**
     * Cronologia OpenAI-style dagli step persistiti (spec §10). Le tool_call /
     * tool_result sono rese come testo in messaggi assistant/user per la
     * massima compatibilità tra provider (niente pairing tool_call_id).
     *
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(WidgetSession $session): array
    {
        $messages = [];

        foreach ($session->steps()->orderBy('step_index')->get() as $step) {
            [$role, $content] = $this->stepToMessage($step);
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        return array_slice($messages, -self::HISTORY_LIMIT);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function stepToMessage(WidgetSessionStep $step): array
    {
        return match ($step->kind) {
            WidgetSessionStep::KIND_USER_MESSAGE => ['user', (string) data_get($step->args_json, 'content', '')],
            WidgetSessionStep::KIND_BOT_MESSAGE => ['assistant', (string) data_get($step->args_json, 'content', '')],
            WidgetSessionStep::KIND_TOOL_CALL => ['assistant', '[azione] '.(string) $step->tool.' '.$this->json($step->args_json ?? [])],
            WidgetSessionStep::KIND_TOOL_RESULT => ['user', $this->toolResultLine($step)],
            default => ['user', ''],
        };
    }

    private function toolResultLine(WidgetSessionStep $step): string
    {
        $ok = data_get($step->args_json, 'ok');
        $okText = $ok === false ? 'false' : 'true';

        // F1.5 — reinietta l'artifact come risultato del tool nel contesto LLM.
        // È la STESSA pipeline dei tool FE: lo step KIND_TOOL_RESULT è persistito
        // in runTurn da execTool/host/FE; qui viene reso come messaggio user per
        // l'LLM. I tool host (e BE) ritornano un `artifact` con i dati reali —
        // è quello che il modello deve vedere per continuare il ragionamento; i
        // tool FE DOM ritornano un `diagnostic` (esito dell'azione sul DOM).
        $payload = data_get($step->args_json, 'artifact');
        if ($payload === null) {
            $payload = data_get($step->args_json, 'diagnostic', []);
        }

        return '[risultato] '.(string) $step->tool.' ok='.$okText.' '.$this->json($payload);
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>|null  $diagnostic
     * @param  array<string, mixed>|null  $snapshotIn
     */
    private function addStep(
        WidgetSession $session,
        string $kind,
        string $tool = '',
        array $args = [],
        ?array $diagnostic = null,
        ?array $snapshotIn = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?int $latency = null,
    ): void {
        $nextIndex = (int) ($session->steps()->max('step_index') ?? -1) + 1;

        // M5.6 — PII mascherata PRIMA del salvataggio (difesa in profondità).
        $maskedArgs = $args !== [] ? $this->piiMasker->maskArray($args) : null;
        $maskedDiagnostic = $this->piiMasker->maskArray($diagnostic);
        $maskedSnapshot = $snapshotIn !== null
            ? $this->piiMasker->maskJsonString($this->json($snapshotIn))
            : null;

        $session->steps()->create([
            'step_index' => $nextIndex,
            'kind' => $kind,
            'tool' => $tool !== '' ? $tool : null,
            'args_json' => $maskedArgs,
            'diagnostic_json' => $maskedDiagnostic,
            'snapshot_in_json' => $maskedSnapshot,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'latency_ms' => $latency,
        ]);
    }

    private function resetErrors(WidgetSession $session, string $status): void
    {
        $session->forceFill([
            'status' => $status,
            'meta' => array_merge((array) $session->meta, ['consecutive_errors' => 0]),
        ])->save();
    }

    /**
     * @return list<string>
     */
    private function navigateAllowlist(WidgetSession $session): array
    {
        $origins = [];
        if (is_string($session->origin) && $session->origin !== '') {
            $origins[] = $session->origin;
        }
        $key = $session->widgetKey;
        if ($key !== null && is_array($key->allowed_origins)) {
            $origins = array_merge($origins, $key->allowed_origins);
        }

        return array_values(array_unique(array_map('strval', $origins)));
    }

    /**
     * F1.4 — Host tools ammessi per questo turno.
     *
     * Doppio gate (capability skill AND interruttore key):
     *  - se la skill non ha host_tools_enabled === true, il ramo
     *    snapshot.host_tools è ignorato del tutto (nessun host tool all'LLM);
     *  - se la widget key della sessione non ha host_tools_enabled === true,
     *    idem: il cliente ha l'interruttore operativo spento (gestito da UI
     *    admin) e gli host tools NON vanno passati all'LLM (degrada a
     *    solo-RAG/FE tools, nessuna eccezione).
     * La key è recuperata via la relazione tenant-aware $session->widgetKey
     * (BelongsToTenant su WidgetKey, stesso accesso usato in navigateAllowlist).
     * Lo snapshot è già stato validato/sanitizzato dal WidgetSnapshotValidator
     * (F1.3): ogni voce ha name valido ed execution === "host". Qui filtriamo
     * ancora per host_tools_allowlist (se presente): un host tool è ammesso se
     * il suo name inizia con uno dei prefissi/uguaglia un nome in allowlist.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function resolveHostTools(array $manifest, array $snapshot, WidgetSession $session): array
    {
        if (($manifest['host_tools_enabled'] ?? null) !== true) {
            return [];
        }

        // Interruttore operativo per-cliente: la widget key deve avere il flag ON.
        $key = $session->widgetKey;
        if ($key === null || $key->host_tools_enabled !== true) {
            return [];
        }

        $hostTools = $snapshot['host_tools'] ?? null;
        if (! is_array($hostTools)) {
            return [];
        }

        $allowlist = array_values(array_filter((array) ($manifest['host_tools_allowlist'] ?? []), 'is_string'));

        $resolved = [];
        foreach ($hostTools as $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $name = $tool['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            if (! $this->hostToolAllowed($name, $allowlist)) {
                continue;
            }
            $resolved[] = $tool;
        }

        return $resolved;
    }

    /**
     * Un host tool è ammesso se l'allowlist è vuota (nessun filtro) oppure se
     * il suo name inizia con uno dei prefissi/uguaglia un nome dell'allowlist.
     *
     * @param  list<string>  $allowlist
     */
    private function hostToolAllowed(string $name, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }

        foreach ($allowlist as $prefix) {
            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrappa gli host tools nel formato function-calling OpenAI, identico a
     * WidgetToolCatalog::openAiTools(). Le definizioni host arrivano già con
     * name/description/parameters (contratto HTP); i campi extra (returns,
     * execution) non sono passati all'LLM.
     *
     * @param  list<array<string, mixed>>  $hostTools
     * @return list<array{type: string, function: array<string, mixed>}>
     */
    private function wrapHostTools(array $hostTools): array
    {
        $wrapped = [];
        foreach ($hostTools as $tool) {
            $parameters = is_array($tool['parameters'] ?? null)
                ? $tool['parameters']
                : ['type' => 'object', 'properties' => (object) []];

            $wrapped[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) $tool['name'],
                    'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
                    'parameters' => $parameters,
                ],
            ];
        }

        return $wrapped;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function maxConsecutiveErrors(array $manifest): int
    {
        $v = data_get($manifest, 'default_policies.max_consecutive_errors', 3);

        return is_int($v) && $v > 0 ? $v : 3;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArgs(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{id: string, status: string}
     */
    private function sessionPayload(WidgetSession $session): array
    {
        return ['id' => (string) $session->public_session_id, 'status' => (string) $session->status];
    }

    /**
     * @return array{provider: string, model: string, latency_ms: int}
     */
    private function turnMeta(AiResponse $response, int $latency): array
    {
        return ['provider' => $response->provider, 'model' => $response->model, 'latency_ms' => $latency];
    }

    private function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '{}';
    }
}
