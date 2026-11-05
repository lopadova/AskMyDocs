<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Exceptions\Chat\ChatFolderNameTakenException;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH/PUT /api/chat-folders/{id} — rename.
 *
 * Same per-(tenant, user) uniqueness as the create request, ignoring the
 * row being renamed so saving a folder under its own name is not a
 * conflict.
 */
final class UpdateChatFolderRequest extends FormRequest
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
                    ->where('user_id', $this->user()?->id)
                    ->ignore($this->route('id')),
            ],
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
