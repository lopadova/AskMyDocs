<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OAuthAuthorizationCode;
use Illuminate\Console\Command;

final class OAuthPruneCommand extends Command
{
    protected $signature = 'oauth:prune';
    protected $description = 'Delete expired or consumed OAuth authorization codes';

    public function handle(): int
    {
        $count = OAuthAuthorizationCode::where('expires_at', '<=', now())->orWhereNotNull('consumed_at')->delete();
        $this->info("Deleted {$count} authorization codes.");
        return self::SUCCESS;
    }
}
