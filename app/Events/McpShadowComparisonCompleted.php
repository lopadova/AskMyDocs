<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\McpConnectorShadowReport;
use Illuminate\Foundation\Events\Dispatchable;

final class McpShadowComparisonCompleted
{
    use Dispatchable;

    public function __construct(public readonly McpConnectorShadowReport $report) {}
}
