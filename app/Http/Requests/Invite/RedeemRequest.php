<?php

declare(strict_types=1);

namespace App\Http\Requests\Invite;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a redemption request. Authorization is the route's auth:sanctum —
 * any authenticated account may attempt to redeem a code.
 */
class RedeemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128'],
        ];
    }
}
