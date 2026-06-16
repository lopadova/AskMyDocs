<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Digest\AiDigestNarrator;
use App\Services\Digest\DigestComposer;
use App\Services\Digest\Renderers\DigestRendererRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.15/W2 — admin digest preview (R44 HTTP surface).
 *
 * Composes the current tenant's digest + (optionally) the AI narrative and
 * returns the channel-agnostic payload plus the rendered Discord/Slack/Teams
 * cards — WITHOUT sending anything. Gated by `role:admin|super-admin` (R32 matrix
 * row for `/api/admin/digest/preview`). Tenant-scoped (R30) via the composer.
 */
final class DigestController extends Controller
{
    public function __construct(
        private readonly DigestComposer $composer,
        private readonly AiDigestNarrator $narrator,
        private readonly DigestRendererRegistry $renderers,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $frequency = $request->query('frequency') === 'monthly' ? 'monthly' : 'weekly';
        $withNarrative = $request->boolean('narrative', false);

        $payload = $this->composer->composeForTenant($frequency);
        if ($withNarrative) {
            $payload->narrative = $this->narrator->narrate($payload);
        }

        $cards = [];
        foreach ($this->renderers->channels() as $channel) {
            $cards[$channel] = $this->renderers->for($channel)->render($payload);
        }

        return response()->json([
            'frequency' => $frequency,
            'payload' => $payload->toArray(),
            'cards' => $cards,
        ]);
    }
}
