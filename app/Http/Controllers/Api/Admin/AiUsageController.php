<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\AiUsageActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AiUsageController extends Controller
{
    public function __invoke(Request $request, AiUsageActivity $activity): JsonResponse
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone:all'],
            'provider' => ['nullable', 'string', 'max:64'],
            'purpose' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', Rule::in(['recorded', 'covered', 'failed', 'estimated', 'blocked'])],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
        ]);
        $timezone = $input['timezone'] ?? config('app.timezone', 'UTC');
        $to = CarbonImmutable::parse($input['to'] ?? CarbonImmutable::now($timezone)->toDateString(), $timezone)->startOfDay();
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from'], $timezone)->startOfDay() : $to->subDays(29);
        if ($from->greaterThan($to) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['from' => 'Choose an interval of one to 366 days.']);
        }

        return response()->json($activity->report($from, $to, $timezone, $input))
            ->header('Cache-Control', 'private, no-store');
    }
}
