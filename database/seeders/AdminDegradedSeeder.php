<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeder for the health-degraded Playwright scenario.
 *
 * Runs DemoSeeder first (for users + baseline rows), then stuffs
 * `failed_jobs` with a row count that exceeds HealthCheckService's
 * degraded threshold (>= 10). The dashboard should render the queue
 * chip with `data-state="degraded"` and the rolled-up health container
 * with the same state.
 */
class AdminDegradedSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoSeeder::class);

        // Match the threshold in HealthCheckService::queueOk() — 10 is
        // the tipping point, we insert 15 to give headroom if the check
        // is ever tuned upward without notifying this seeder.
        for ($i = 0; $i < 15; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'sync',
                'queue' => 'default',
                'payload' => '{"job":"test"}',
                'exception' => 'seeded failure #'.$i,
            ]);
        }
    }
}
