<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\AgentConsoleContext;
use App\Agent\AgentExecutionContext;
use App\Support\SupportedLocale;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateAgentContextCommand extends Command
{
    protected $signature = 'agent:context {question? : Original user question}
        {--tenant=default : Tenant ID} {--project= : Project key} {--user= : User ID}
        {--history= : JSON file of prior user and assistant messages}
        {--depth=3 : Research depth from 1 to 5} {--locale= : Answer locale}
        {--timezone= : User timezone} {--resume= : Read or resume a prior console context run}';

    protected $description = 'Generate and export authorized document and connector context without composing an answer';

    public function handle(AgentConsoleContext $stages): int
    {
        try {
            $actor = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $tenant = (string) $this->option('tenant');
            $question = trim((string) $this->argument('question'));
            $project = trim((string) $this->option('project'));
            $resume = $this->option('resume');
            if ($actor === false || ! preg_match('/^[a-z0-9_-]{1,50}$/D', $tenant)) {
                throw new \InvalidArgumentException('Provide a valid --tenant and a positive --user ID.');
            }
            if (is_string($resume) && $resume !== '') {
                if ($question !== '' || $project !== '' || $this->option('history') !== null) {
                    throw new \InvalidArgumentException('Resume with the run ID, tenant and user from the previous output.');
                }
                $run = $stages->resume($tenant, $actor, $resume);
            } else {
                $depth = filter_var($this->option('depth'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
                if ($depth === false || $question === '' || $project === '') {
                    throw new \InvalidArgumentException('Provide the original question, --project and a depth between 1 and 5.');
                }
                $context = new AgentExecutionContext((string) Str::uuid(), $tenant, $project, 'chat', 'user', (string) $actor,
                    SupportedLocale::normalize($this->option('locale') ?? config('app.locale')),
                    $this->option('timezone') ?? (string) config('app.timezone', 'UTC'));
                $run = $stages->collect($context, $question, $depth, $this->history());
            }
            $snapshot = $stages->export($run);
            $this->output->writeln(AgentConsoleContext::json($snapshot), OutputInterface::OUTPUT_RAW);

            return $snapshot['ready'] ? self::SUCCESS : self::FAILURE;
        } catch (\InvalidArgumentException|\JsonException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
    }

    private function history(): array
    {
        $file = $this->option('history');
        if ($file === null) {
            return [];
        }
        $messages = AgentConsoleContext::readJson($file, 524288);
        if (! array_is_list($messages)) {
            throw new \InvalidArgumentException('The history must be a JSON array of messages in chronological order.');
        }

        return array_map(static function (mixed $message): array {
            if (! is_array($message) || ! in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || ! is_string($message['content'] ?? null)) {
                throw new \InvalidArgumentException('Each history entry needs a user or assistant role and text content.');
            }

            return ['role' => $message['role'], 'text' => $message['content']];
        }, $messages);
    }
}
