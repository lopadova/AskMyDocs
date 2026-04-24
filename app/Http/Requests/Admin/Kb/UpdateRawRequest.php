<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PR10 / Phase G3 — validate the raw markdown body submitted from the
 * CodeMirror source editor.
 *
 * The 2 MiB cap mirrors Storage::put()'s practical limit on local disks
 * and keeps request bodies well below any default nginx/php `post_max_size`.
 * Frontmatter semantics are validated by {@see \App\Services\Kb\Canonical\CanonicalParser}
 * downstream (when the body starts with `---`) so invalid YAML does not
 * fall through to disk (R4).
 *
 * Authorization is delegated to the route middleware
 * (`auth:sanctum` + `role:admin|super-admin`) — this form is a data
 * validator only.
 */
class UpdateRawRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:2097152'], // 2 MiB
        ];
    }
}
