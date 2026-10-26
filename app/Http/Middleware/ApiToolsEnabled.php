<?php

declare(strict_types=1);
namespace App\Http\Middleware;

final class ApiToolsEnabled
{
    public function handle($request, \Closure $next): mixed
    {
        abort_unless(config('api-tools.enabled',false),404);
        return $next($request);
    }
}
