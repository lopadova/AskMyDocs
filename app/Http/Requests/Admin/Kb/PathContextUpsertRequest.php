<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use App\Services\Kb\PathContext\PathContextService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * v8.44/W4 — validates `PUT /api/admin/kb/path-contexts`. Authorization is the
 * route group's (`auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`).
 */
final class PathContextUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120'],
            // `bail`: a non-string value (array, object) stops at `string` with a
            // 422 instead of reaching the closure, where casting it would raise
            // "Array to string conversion" and answer 500 (R14).
            'path_prefix' => ['bail', 'required', 'string', 'max:300', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }
                $v = $value;
                if (str_starts_with($v, '/') || str_starts_with($v, '\\')) {
                    $fail('The path prefix must be relative (no leading slash).');

                    return;
                }
                try {
                    app(PathContextService::class)->normalizePrefix($v);
                } catch (\InvalidArgumentException) {
                    $fail('The path prefix must be a non-empty relative path without "." or ".." segments.');
                }
            }],
            'description' => ['required', 'string', 'min:1', 'max:'.PathContextService::MAX_DESCRIPTION],
        ];
    }
}
