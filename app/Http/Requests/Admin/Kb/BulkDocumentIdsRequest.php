<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the id list submitted to the bulk KB document endpoints
 * (bulk-delete / bulk-restore).
 *
 * The 100-id cap is the memory bound for the whole request (R3): the
 * controller resolves the batch with a single `whereIn` and iterates the
 * hydrated collection, so the cap must not be lifted without switching
 * the resolution to a chunked walk.
 *
 * `force` is only consumed by bulk-delete (soft → hard promotion);
 * bulk-restore ignores it.
 *
 * Authorization is delegated to the route middleware
 * (`auth:sanctum` + `role:admin|super-admin`) — this form is a data
 * validator only.
 */
class BulkDocumentIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'min:1'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
