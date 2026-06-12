<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use App\Support\Kb\SourceType;
use App\Support\KbPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * POST /api/admin/kb/uploads — stage files for the drag-and-drop upload modal.
 *
 * multipart/form-data: `files[]` + `project_key` + optional `sub_path`.
 *
 * `project_key` is format-validated but NOT required to pre-exist in the
 * `projects` registry — consistent with the other ingest entry points
 * (KbIngestController / kb:ingest-folder accept any key) and the soft-registry
 * design. The FE picker constrains the choice to real projects (R18).
 *
 * File-type gating is done by EXTENSION in withValidator (markdown's MIME is
 * ambiguous — browsers send text/plain for .md), mirroring how
 * KbIngestFolderCommand resolves types when walking the disk. `sub_path` is
 * normalized through KbPath so a `..` traversal 422s (R1).
 */
class StageKbUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is enforced by the route middleware (role:admin|super-admin).
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxFiles = (int) config('kb.staging.max_files', 100);
        $maxKb = (int) ceil(((int) config('kb.staging.max_file_bytes', 26214400)) / 1024);

        return [
            'project_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'sub_path' => ['nullable', 'string', 'max:500'],
            'files' => ['required', 'array', 'min:1', "max:{$maxFiles}"],
            'files.*' => ['required', 'file', "max:{$maxKb}"],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateSubPath($validator);
            $this->validateFileTypes($validator);
        });
    }

    private function validateSubPath(Validator $validator): void
    {
        $subPath = $this->input('sub_path');
        if (! is_string($subPath) || $subPath === '') {
            return;
        }

        try {
            KbPath::normalize($subPath);
        } catch (InvalidArgumentException $e) {
            $validator->errors()->add('sub_path', $e->getMessage());
        }
    }

    private function validateFileTypes(Validator $validator): void
    {
        $files = $this->file('files');
        if (! is_array($files)) {
            return;
        }

        foreach ($files as $i => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $type = SourceType::fromExtension($file->getClientOriginalExtension());
            if ($type === SourceType::UNKNOWN) {
                $type = SourceType::fromMime((string) $file->getClientMimeType());
            }

            if ($type === SourceType::UNKNOWN) {
                $validator->errors()->add(
                    "files.{$i}",
                    'Unsupported file type. Allowed: md, markdown, txt, pdf, docx.',
                );
            }
        }
    }
}
