<?php

declare(strict_types=1);

namespace App\FinOps;

use Padosoft\LaravelAiFinOps\Console\CapturePricesCommand as PackageCommand;
use Padosoft\LaravelAiFinOps\PriceWatch\PriceWatchService;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

/** Refresh the billing catalogs in the existing daily price-maintenance slot. */
final class CapturePricesCommand extends PackageCommand
{
    public function handle(PriceWatchService $service): int
    {
        if (! config('ai-finops.enabled', true)) {
            return self::SUCCESS;
        }
        app(PricingRegistry::class)->sync();

        return parent::handle($service);
    }
}
