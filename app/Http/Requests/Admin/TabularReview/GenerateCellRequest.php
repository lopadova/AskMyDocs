<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\TabularReview;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v4.7/W1 — POST /api/admin/tabular-reviews/{id}/regenerate-cell.
 *
 * Body: { document_id, column_index } — re-runs the extractor against
 * one cell only. The controller verifies the document is reachable
 * from the current tenant + project before invoking the extractor.
 */
class GenerateCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_id' => ['required', 'integer'],
            'column_index' => ['required', 'integer', 'min:0', 'max:49'],
        ];
    }
}
