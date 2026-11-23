<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\AgentConsoleContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class AnswerAgentContextCommand extends Command
{
    protected $signature = 'agent:answer {context : JSON file exported by agent:context}';

    protected $description = 'Compose and verify a session answer from a collected context without searching again';

    public function handle(AgentConsoleContext $stages): int
    {
        try {
            $snapshot = AgentConsoleContext::readJson($this->argument('context'));
            $answer = $stages->answer($snapshot);
            $this->output->writeln(AgentConsoleContext::json(['run_id' => $snapshot['run_id'], ...$answer->jsonSerialize()]),
                OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (\InvalidArgumentException|\JsonException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
    }
}
