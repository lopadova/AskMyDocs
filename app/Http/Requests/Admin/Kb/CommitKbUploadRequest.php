<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/kb/uploads/{batch}/commit — commit a staged batch.
 *
 * Optional `expected_item_ids` is an optimistic-concurrency guard: when
 * present, the service 409s if the staged set changed since the operator
 * reviewed it.
 */
class CommitKbUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'expected_item_ids' => ['nullable', 'array'],
            'expected_item_ids.*' => ['string'],
        ];
    }
}
