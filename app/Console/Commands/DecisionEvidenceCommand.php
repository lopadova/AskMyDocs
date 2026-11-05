<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsDecisionInput;
use App\Decisions\Decisions;
use Illuminate\Console\Command;
use Throwable;

final class DecisionEvidenceCommand extends Command
{
    use ReadsDecisionInput;

    protected $signature = 'decision:evidence {question : User question}
        {--evidence= : Authorized excerpt or structured MCP result} {--claim= : Proposed answer or claim}
        {--model= : Decision adapter alias} {--threshold= : Confidence threshold for boolean answers}
        {--json : Print JSON}';

    protected $description = 'Test relevance and sufficiency of supplied evidence without loading database sources.';

    public function handle(): int
    {
        try {
            $evidence = trim((string) $this->option('evidence'));
            if ($evidence === '') {
                throw new \InvalidArgumentException('--evidence is required.');
            }
            $result = Decisions::using($this->option('model'))
                ->withState(['question' => (string) $this->argument('question'),
                    'evidence' => $evidence, 'claim' => (string) $this->option('claim')])
                ->relevance()->sufficiency()->decide();
            $threshold = $this->threshold();
            if ($this->option('json') && $threshold !== null) {
                $data = $result->toArray();
                $data['boolean'] = [
                    'relevant' => $result->toBool('relevant', $threshold),
                    'sufficient' => $result->toBool('sufficient', $threshold),
                ];
                $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->outputResult($result);
            }
            if (! $this->option('json') && $threshold !== null) {
                $this->line('Relevant: '.($result->toBool('relevant', $threshold) ? 'yes' : 'no'));
                $this->line('Sufficient: '.($result->toBool('sufficient', $threshold) ? 'yes' : 'no'));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
