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
            'path_prefix' => ['required', 'string', 'max:300', function (string $attribute, mixed $value, \Closure $fail): void {
                $v = (string) $value;
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
