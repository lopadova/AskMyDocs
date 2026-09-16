<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Support\Chat\ConversationImportance;
use App\Support\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /conversations/{conversation} — rename and/or organise.
 *
 * Every field is `sometimes`, so the historical rename-only payload is
 * unchanged (`title` moved from `required` to `sometimes`, a strict
 * widening). An EMPTY body is rejected with 422 rather than answered
 * 200-no-op: a caller must be able to tell "nothing applied" from
 * "applied nothing" (R14).
 *
 * `chat_folder_id` is constrained to folders the ACTING USER owns in the
 * ACTIVE TENANT. Without that predicate the id is attacker-chosen and
 * user A could file a thread into user B's folder — the textbook IDOR
 * (SEC-IDOR-001). The service re-verifies it as well (R21).
 */
final class UpdateConversationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'chat_folder_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('chat_folders', 'id')
                    ->where('tenant_id', app(TenantContext::class)->current())
                    ->where('user_id', $this->user()?->id),
            ],
            'pinned' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            'importance' => ['sometimes', Rule::enum(ConversationImportance::class)],
        ];
    }

    /** Fields this request knows how to apply. */
    public const ORGANISATION_FIELDS = ['title', 'chat_folder_id', 'pinned', 'archived', 'importance'];

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAny(self::ORGANISATION_FIELDS)) {
                $validator->errors()->add(
                    'title',
                    'Provide at least one of: title, chat_folder_id, pinned, archived, importance.',
                );
            }
        });
    }
}
