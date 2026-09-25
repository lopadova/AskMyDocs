<?php

namespace App\Services;

use Illuminate\Http\Request;
use Ui4\Workbench\Contracts\WorkbenchIdentityResolver;

final class Ui4WorkbenchIdentityResolver implements WorkbenchIdentityResolver
{
    public function __construct(private readonly Ui4WorkbenchScope $scopes) {}

    public function resolve(Request $request): string
    {
        return $this->scopes->current($request)['scope'];
    }
}
