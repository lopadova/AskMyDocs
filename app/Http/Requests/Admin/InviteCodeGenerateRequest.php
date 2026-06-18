<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk code-generation payload. `count` is capped to keep a single request
 * memory-bounded (R3); larger drops go through a queued job (future).
 */
class InviteCodeGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'campaign_id' => ['nullable', 'integer'],
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'length' => ['nullable', 'integer', 'min:4', 'max:32'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
