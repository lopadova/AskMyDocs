<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape every producer of a conversation returns.
 *
 * `$wrap = null` is load-bearing, not style: `GET /conversations` has
 * always answered a BARE JSON array, and wrapping it in `{data: …}`
 * would break every existing client in one step — the one-way door R27
 * exists to keep shut. New keys are ADDITIVE; `created_at` /
 * `updated_at` pass through untouched, because changing an existing
 * key's FORMAT breaks callers exactly as removing it would.
 *
 * `user_id` and `tenant_id` are deliberately absent. The raw model used
 * to leak both from `store()` and from the branch endpoint; no FE reader
 * exists for either, and echoing a tenant id to the client is worth
 * dropping while the shape is being formalised.
 *
 * The pin/archive contract is asymmetric on purpose: requests carry
 * booleans (`pinned: true`), responses carry the TIMESTAMP, so a client
 * never invents a date.
 *
 * @mixin Conversation
 */
final class ConversationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_key' => $this->project_key,
            'chat_folder_id' => $this->chat_folder_id,
            'pinned_at' => $this->pinned_at,
            'archived_at' => $this->archived_at,
            // The machine-readable identifier, never localized (R24).
            'importance' => $this->importance->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
