<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Widget;

use App\Http\Middleware\ResolveWidgetKey;
use App\Models\WidgetKey;
use App\Services\Widget\WidgetSkillRegistry;
use App\Services\Widget\WidgetThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GET /api/widget/setup — manifest della skill per il widget FE.
 *
 * Ritorna il sottoinsieme che serve al client: tool abilitati + regole di
 * auto-annotazione + policy. La skill è SEMPRE quella configurata sulla key:
 * `?skill=` è ammesso solo se combacia (vedi #21 sotto). Gira dietro
 * `widget.key`, quindi tenant/project/key sono già risolti dal middleware (R30).
 */
final class WidgetSetupController extends Controller
{
    public function __invoke(Request $request, WidgetSkillRegistry $skills, WidgetThemeService $theme): JsonResponse
    {
        /** @var WidgetKey $key */
        $key = $request->attributes->get(ResolveWidgetKey::ATTR_KEY);

        $skillId = (string) $request->query('skill', $key->skill);

        // #21 — il widget pubblico usa SOLO lo skill della sua key: start/step
        // caricano sempre $key->skill, quindi un ?skill diverso farebbe divergere
        // l'annotazione/preview (da /setup) dal ragionamento della sessione.
        // Un ?skill che combacia è ammesso; uno diverso → 403 (non un override).
        if ($skillId !== (string) $key->skill) {
            return response()->json([
                'error' => 'skill_not_allowed',
                'message' => 'This widget key is not configured for the requested skill.',
            ], 403);
        }

        $manifest = $skills->get($skillId);

        if ($manifest === null) {
            return response()->json([
                'error' => 'skill_not_found',
                'message' => "Unknown widget skill: {$skillId}.",
            ], 404);
        }

        return response()->json([
            'skill' => $skillId,
            'project' => $key->project_key,
            'tools_enabled' => $manifest['tools_enabled'] ?? [],
            'auto_annotation_rules' => $manifest['auto_annotation_rules'] ?? [],
            'default_policies' => $manifest['default_policies'] ?? [],
            'default_locale' => $manifest['default_locale'] ?? 'en',
            // R27 additivo: identità grafica risolta (stored sui default) per
            // l'applicazione dinamica lato widget. `null` → default.
            'theme' => $theme->resolve($key->theme_config),
        ]);
    }
}
