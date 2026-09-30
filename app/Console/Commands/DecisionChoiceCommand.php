<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsDecisionInput;
use App\Decisions\Decisions;
use Illuminate\Console\Command;
use Throwable;

final class DecisionChoiceCommand extends Command
{
    use ReadsDecisionInput;

    protected $signature = 'decision:choice {text : Text to evaluate} {question : Classification question}
        {--option=* : Named criterion as key:description; repeat for each option}
        {--state= : Additional JSON object state} {--model= : Decision adapter alias}
        {--threshold= : Optional minimum confidence to accept the choice} {--json : Print JSON}';

    protected $description = 'Test a typed multi-option decision without changing application data.';

    public function handle(): int
    {
        try {
            $criteria = [];
            foreach ($this->option('option') as $item) {
                [$key, $description] = array_pad(explode(':', $item, 2), 2, '');
                if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || trim($description) === '' || isset($criteria[$key])) {
                    throw new \InvalidArgumentException('Each --option needs a unique key:description.');
                }
                $criteria[$key] = $description;
            }
            $result = Decisions::using($this->option('model'))
                ->withState($this->state((string) $this->argument('text')))
                ->choice('answer', (string) $this->argument('question'), $criteria)
                ->decide();
            $threshold = $this->threshold();
            if ($threshold !== null && $result->answers['answer']['confidence'] < $threshold) {
                throw new \App\Decisions\DecisionException('Choice is inconclusive at the requested threshold.');
            }
            $this->outputResult($result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
