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
 * auto-annotazione + policy. La skill è quella configurata sulla key
 * (`?skill=` può richiederne un'altra, ma deve esistere). Gira dietro
 * `widget.key`, quindi tenant/project/key sono già risolti dal middleware (R30).
 */
final class WidgetSetupController extends Controller
{
    public function __invoke(Request $request, WidgetSkillRegistry $skills, WidgetThemeService $theme): JsonResponse
    {
        /** @var WidgetKey $key */
        $key = $request->attributes->get(ResolveWidgetKey::ATTR_KEY);

        $skillId = (string) $request->query('skill', $key->skill);
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
