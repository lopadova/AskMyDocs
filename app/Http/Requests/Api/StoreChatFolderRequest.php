<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Exceptions\Chat\ChatFolderNameTakenException;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/chat-folders.
 *
 * The name is unique per (tenant, user) — matching the composite unique
 * on the table (R31) — so two tenants, and two users in one tenant, may
 * each keep an "Issue 42". Validating it here turns the DB constraint
 * into a 422 with a field error the UI can show, instead of a 500 from
 * the driver (R14).
 */
final class StoreChatFolderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:120',
                Rule::unique('chat_folders', 'name')
                    ->where('tenant_id', app(TenantContext::class)->current())
                    ->where('user_id', $this->user()?->id),
            ],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => ChatFolderNameTakenException::MESSAGE,
        ];
    }
}
