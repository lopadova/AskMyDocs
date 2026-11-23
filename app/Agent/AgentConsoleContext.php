<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Ai\AgentChatModelScope;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Models\User;
use App\Support\SensitivePayloadRedactor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;

/** Console transport for the same stages as a chat, without a chat message destination. */
final readonly class AgentConsoleContext
{
    public function __construct(
        private AgentRunDispatcher $runs,
        private AgentRunHandler $handler,
        private AgentTurnContextBuilder $turnContext,
        private AgentSessionAgent $session,
        private AgentEvidenceAccess $access,
        private AgentEvidenceFactory $evidence,
        private SensitivePayloadRedactor $redactor,
    ) {}

    public function collect(AgentExecutionContext $context, string $question, int $depth, array $history): AgentRun
    {
        return $this->within($context, function (User $user) use ($context, $question, $depth, $history): AgentRun {
            $run = $this->runs->create($context, ['question' => $question, 'depth' => $depth, 'session_evidence' => true,
                'console_context' => true, 'console_dialogue' => $this->redactor->redact($history)], ['user_id' => $user->id]);
            $this->handler->handle($run);

            return $run->refresh();
        });
    }

    public function resume(string $tenant, int $actor, string $runId): AgentRun
    {
        $run = $this->find($tenant, $actor, $runId);

        return $this->within(AgentExecutionContext::fromArray($run->toArray()), function () use ($run): AgentRun {
            if (! $run->isTerminal()) {
                $this->handler->handle($run);
            }

            return $run->refresh();
        });
    }

    public function export(AgentRun $run): array
    {
        $state = $run->result_json ?? [];
        $header = ['schema_version' => 1, 'run_id' => $run->run_id, 'tenant' => $run->tenant_id,
            'user_id' => $run->user_id, 'project' => $run->project_key, 'status' => $run->status,
            'ready' => ($state['phase'] ?? null) === 'context'];
        if (! $header['ready']) {
            return [...$header, 'error_code' => $run->error_code];
        }

        return [...$header, 'question' => $run->input_json['question'], 'history' => $run->input_json['console_dialogue'],
            'question_understanding' => $state['question_understanding'] ?? null, 'decision' => $state['decision'],
            'stop_reason' => $state['stop_reason'], 'queries' => $run->toolExecutions()->orderBy('id')->get()
                ->map(fn ($action): array => ['tool' => $action->tool_name, 'kind' => $action->tool_kind,
                    'arguments' => $this->redactor->redact((array) $action->arguments_json), 'status' => $action->status, 'error_code' => $action->error_code])
                ->all(),
            'evidence' => $state['evidence']];
    }

    public function answer(array $snapshot): AgentAnswer
    {
        if (($snapshot['schema_version'] ?? null) !== 1 || ($snapshot['ready'] ?? null) !== true
            || ! is_string($snapshot['run_id'] ?? null) || ! is_string($snapshot['tenant'] ?? null)
            || ! is_int($snapshot['user_id'] ?? null)) {
            throw new \InvalidArgumentException('Pass the JSON output of a completed agent:context run.');
        }
        $run = $this->find($snapshot['tenant'], $snapshot['user_id'], $snapshot['run_id']);
        $context = AgentExecutionContext::fromArray($run->toArray());

        return $this->within($context, function () use ($snapshot, $run, $context): AgentAnswer {
            // Evidence coordinates, dialogue and live observations are server-owned.
            // This also rejects a fabricated record or a modified original question.
            if ($snapshot !== $this->export($run)) {
                throw new \InvalidArgumentException('The context file does not match the collected run.');
            }
            $evidence = $this->evidence->empty();
            $evidence->import($this->access->current($run, $context, $snapshot['evidence'], allowDocumentExpansion: false));
            $completed = array_map(static fn (array $action): array => array_diff_key($action, ['result' => true]),
                $run->result_json['completed_actions'] ?? []);
            $sources = new AgentLoopOutcome($snapshot['decision'], $evidence, $completed, $snapshot['stop_reason']);
            $history = $this->turnContext->build($run);

            return app(AgentRunDeadline::class)->within($run, now(), (float) config('agent.job_timeout_seconds', 120),
                fn () => $this->session->compose($run, $context, $sources, $history === [] ? null : self::json($history), collected: true)->answer);
        });
    }

    public static function readJson(string $path, int $maxBytes = 8388608): array
    {
        $content = is_file($path) && is_readable($path) ? @file_get_contents($path, length: $maxBytes + 1) : false;
        if ($content === false || strlen($content) > $maxBytes) {
            throw new \InvalidArgumentException('The JSON file cannot be read or exceeds the allowed size.');
        }
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Expected a JSON object or array.');
        }

        return $decoded;
    }

    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function find(string $tenant, int $actor, string $runId): AgentRun
    {
        $run = AgentRun::query()->forTenant($tenant)->where('run_id', $runId)
            ->where('actor_type', 'user')->where('actor_id', (string) $actor)->where('user_id', $actor)
            ->where('channel', 'chat')->where('input_json->console_context', true)->first();
        if (! $run?->isConsoleContext()) {
            throw new \InvalidArgumentException('Console context run not found for this tenant and user.');
        }

        return $run;
    }

    private function within(AgentExecutionContext $context, \Closure $action): mixed
    {
        $tenants = app(TenantContext::class);
        $packageTenants = app(PackageTenantContext::class);
        $tenant = $tenants->current();
        $packageTenant = $packageTenants->current();
        $locale = App::currentLocale();
        $timezone = date_default_timezone_get();
        $previous = Auth::user();
        try {
            $context->activate();
            $user = User::query()->find($context->actorId);
            if (! $user?->is_active || (! $user->canReadAllProjects() && ! in_array($context->projectKey, $user->allowedProjects(), true))) {
                throw new \InvalidArgumentException('The user is inactive or has no access to the project.');
            }
            Auth::setUser($user);

            return app(AgentChatModelScope::class)->within($context, fn () => $action($user));
        } finally {
            Auth::forgetGuards();
            if ($previous !== null) {
                Auth::setUser($previous);
            }
            $tenants->set($tenant);
            $packageTenants->set($packageTenant);
            App::setLocale($locale);
            date_default_timezone_set($timezone);
        }
    }
}
