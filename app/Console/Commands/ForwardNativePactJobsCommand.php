<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory;

final class ForwardNativePactJobsCommand extends Command
{
    private const DATABASE_CONNECTION = 'pact_client';

    private const QUEUE = 'pact-client';

    protected $signature = 'pact-client:forward-queue';

    protected $description = 'Forward native PACT jobs to the configured Laravel Cloud managed queue';

    public static function enabled(): bool
    {
        $cloud = config('queue.connections.cloud', []);
        $queues = $cloud['queues'] ?? [];

        return config('pact.enabled') && config('pact.role') === 'client'
            && ($cloud['driver'] ?? null) === 'cloud'
            && in_array(self::QUEUE, array_is_list($queues) ? $queues : array_keys($queues), true);
    }

    public static function hasJobs(Factory $queues): bool
    {
        return $queues->connection(self::DATABASE_CONNECTION)->size(self::QUEUE) > 0;
    }

    public function handle(Factory $queues): int
    {
        if (! self::enabled()) {
            return self::SUCCESS;
        }

        $database = $queues->connection(self::DATABASE_CONNECTION);
        $cloud = $queues->connection('cloud');
        for ($count = 0; $count < 10; $count++) {
            $forwarded = $database->getDatabase()->transaction(function () use ($database, $cloud): bool {
                $job = $database->pop(self::QUEUE);
                if ($job === null) {
                    return false;
                }

                // Keep the committed source job available if the send fails.
                $cloud->pushRaw($job->getRawBody(), self::QUEUE);
                $job->delete();

                return true;
            });
            if (! $forwarded) {
                break;
            }
        }

        return self::SUCCESS;
    }
}
