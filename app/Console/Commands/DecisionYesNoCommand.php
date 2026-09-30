<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsDecisionInput;
use App\Decisions\Decisions;
use Illuminate\Console\Command;
use Throwable;

final class DecisionYesNoCommand extends Command
{
    use ReadsDecisionInput;

    protected $signature = 'decision:yes-no {text : Text to evaluate} {question : Yes/no question}
        {--true= : Criterion for yes} {--false= : Criterion for no}
        {--state= : Additional JSON object state} {--model= : Decision adapter alias}
        {--threshold= : Confidence threshold for a boolean answer} {--json : Print JSON}';

    protected $description = 'Test a typed yes/no decision without changing application data.';

    public function handle(): int
    {
        try {
            $yes = trim((string) $this->option('true'));
            $no = trim((string) $this->option('false'));
            if ($yes === '' || $no === '') {
                throw new \InvalidArgumentException('Both --true and --false criteria are required.');
            }
            $result = Decisions::using($this->option('model'))
                ->withState($this->state((string) $this->argument('text')))
                ->yesNo('answer', (string) $this->argument('question'), ['true' => $yes, 'false' => $no])
                ->decide();
            $this->outputResult($result, 'answer');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
