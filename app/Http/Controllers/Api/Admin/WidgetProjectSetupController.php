<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\User;
use App\Models\WidgetKey;
use App\Models\WidgetProjectSetup;
use App\Services\Admin\ProjectCatalogService;
use App\Services\Widget\WidgetIntroService;
use App\Services\Widget\WidgetKeyIssuer;
use App\Services\Widget\WidgetThemeService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Admin-only setup for a widget backed by a dedicated documentation project. */
final class WidgetProjectSetupController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectCatalogService $projects,
        private readonly WidgetKeyIssuer $issuer,
        private readonly WidgetThemeService $theme,
        private readonly WidgetIntroService $intro,
    ) {}

    public function index(): JsonResponse
    {
        $setups = WidgetProjectSetup::query()
            ->whereHas('project', fn ($query) => $query->forTenant($this->tenant->current()))
            ->with('project')
            ->orderByDesc('project_id')
            ->get();

        return response()->json(['data' => $setups->map(fn (WidgetProjectSetup $setup) => $this->summary($setup))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'project_key' => ['nullable', 'string', 'max:120'],
            'user_auth_enabled' => ['required', 'boolean'],
            'language' => ['required', 'string', 'max:80'],
            'audience' => ['required', 'string', 'max:200'],
            'topics' => ['required', 'string', 'max:4000'],
        ]);
        $key = Str::slug($data['project_key'] ?? $data['name']);
        if ($key === '' || strlen($key) > 120) {
            throw ValidationException::withMessages(['project_key' => [__('Enter a project key of up to 120 characters.')]]);
        }

        try {
            $setup = DB::transaction(function () use ($data, $key): WidgetProjectSetup {
                if ($this->projects->contains($key)) {
                    throw ValidationException::withMessages(['project_key' => [__('This project key is already in use.')]]);
                }

                $project = Project::query()->create(['name' => $data['name'], 'project_key' => $key]);
                $setup = WidgetProjectSetup::query()->create([
                    'project_id' => $project->id,
                    'user_auth_enabled' => $data['user_auth_enabled'],
                    'language' => $data['language'],
                    'audience' => $data['audience'],
                    'topics' => $data['topics'],
                ]);

                return $setup->setRelation('project', $project);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['project_key' => [__('This project key is already in use.')]]);
        }

        return response()->json(['data' => $this->summary($setup)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $project = Project::query()->forTenant($this->tenant->current())->findOrFail($id);
        $setup = WidgetProjectSetup::query()->findOrFail($project->id)->setRelation('project', $project);
        $key = $this->widgetKey($setup, $project);
        $uploads = $this->uploads($project);

        return response()->json(['data' => $this->summary($setup) + $this->ingestState($project) + [
            'batches' => $uploads->whereNotIn('status', [KbIngestBatch::STATUS_CANCELLED, KbIngestBatch::STATUS_EXPIRED])
                ->latest('created_at')->latest('id')->limit(10)->get(['id', 'status', 'sub_path']),
            'key' => $key === null ? null : $this->embedKey($key),
        ]]);
    }

    public function issueKey(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $project = Project::query()->forTenant($this->tenant->current())->lockForUpdate()->findOrFail($id);
            $setup = WidgetProjectSetup::query()->findOrFail($project->id);
            $existing = $this->widgetKey($setup, $project);
            if ($existing !== null) {
                return response()->json(['data' => $this->embedKey($existing)])->header('Cache-Control', 'no-store');
            }

            $settings = $request->validate([
                'label' => ['required', 'string', 'max:120'],
                'mode' => ['required', 'in:helper,inline,fullscreen'],
                'allowed_origins' => ['required', 'array', 'list', 'min:1', 'max:25'],
                'allowed_origins.*' => ['required', 'string', 'max:255'],
                'accept_partial' => ['sometimes', 'boolean'],
            ]);
            $origins = array_values(array_unique(array_map($this->normalizeOrigin(...), $settings['allowed_origins'])));
            $ingest = $this->ingestState($project);
            if ($ingest['indexed_documents'] === 0 || $ingest['is_indexing']) {
                throw ValidationException::withMessages(['documents' => [__('Wait for indexing to finish and index at least one document.')]]);
            }
            if ($ingest['failures'] !== [] && ! ($settings['accept_partial'] ?? false)) {
                throw ValidationException::withMessages(['accept_partial' => [__('Confirm that you want to continue with the indexed documents.')]]);
            }
            if (WidgetKey::query()->forTenant($project->tenant_id)->where('project_key', $project->project_key)
                ->where('label', $settings['label'])->exists()) {
                throw ValidationException::withMessages(['label' => [__('This key label is already in use for this project.')]]);
            }

            $user = $request->user();
            $issued = $this->issuer->issue($project->tenant_id, [
                'label' => $settings['label'],
                'project_key' => $project->project_key,
                'allowed_origins' => $origins,
                'theme' => $settings['mode'] === 'helper' ? null : ['mode' => $settings['mode']],
                'user_auth_enabled' => $setup->user_auth_enabled,
                'data_agent_enabled' => false,
            ], $user instanceof User ? $user : null);
            $setup->update(['widget_key_id' => $issued['key']->id]);

            return response()->json([
                'data' => $this->embedKey($issued['key']),
                'identity_plain_secret' => $issued['identity_plain_secret'],
            ], 201)->header('Cache-Control', 'no-store');
        });
    }

    private function summary(WidgetProjectSetup $setup): array
    {
        return [
            'id' => $setup->project_id,
            'name' => $setup->project->name,
            'project_key' => $setup->project->project_key,
            'user_auth_enabled' => $setup->user_auth_enabled,
            'language' => $setup->language,
            'audience' => $setup->audience,
            'topics' => $setup->topics,
            'widget_key_id' => $setup->widget_key_id,
        ];
    }

    private function widgetKey(WidgetProjectSetup $setup, Project $project): ?WidgetKey
    {
        if ($setup->widget_key_id === null) {
            return null;
        }

        return WidgetKey::query()->forTenant($project->tenant_id)->where('project_key', $project->project_key)
            ->find($setup->widget_key_id);
    }

    private function embedKey(WidgetKey $key): array
    {
        return [
            'id' => $key->id,
            'label' => $key->label,
            'public_key' => $key->public_key,
            'project_key' => $key->project_key,
            'allowed_origins' => $key->allowed_origins ?? [],
            'is_active' => $key->is_active,
            'user_auth_enabled' => $key->user_auth_enabled,
            'theme' => $this->theme->resolve($key->theme_config),
            'intro' => $this->intro->resolve($key->intro_config),
        ];
    }

    private function uploads(Project $project): Builder
    {
        return KbIngestBatch::query()->forTenant($project->tenant_id)->where('project_key', $project->project_key);
    }

    private function ingestState(Project $project): array
    {
        $indexing = $this->uploads($project)->whereIn('status', [
            KbIngestBatch::STATUS_COMMITTING, KbIngestBatch::STATUS_PROCESSING,
        ])->exists();
        $indexed = KnowledgeDocument::query()->forTenant($project->tenant_id)
            ->where('project_key', $project->project_key)->where('status', 'active')
            ->whereNotNull('indexed_at')->whereHas('chunks')->count();

        // The newest terminal outcome for each destination reflects retries.
        // Pruning old batches leaves the indexed document count intact.
        $items = KbIngestBatchItem::query()->forTenant($project->tenant_id)
            ->join('kb_ingest_batches', function ($join): void {
                $join->on('kb_ingest_batches.id', '=', 'kb_ingest_batch_items.batch_id')
                    ->on('kb_ingest_batches.tenant_id', '=', 'kb_ingest_batch_items.tenant_id');
            })
            ->where('kb_ingest_batches.project_key', $project->project_key)
            ->whereNotNull('kb_ingest_batches.committed_at')
            ->whereIn('kb_ingest_batch_items.status', KbIngestBatchItem::TERMINAL)
            ->orderBy('kb_ingest_batch_items.destination_path')
            ->orderByDesc('kb_ingest_batches.committed_at')
            ->orderByDesc('kb_ingest_batch_items.updated_at')
            ->orderByDesc('kb_ingest_batch_items.id')
            ->select('kb_ingest_batch_items.destination_path', 'kb_ingest_batch_items.status', 'kb_ingest_batch_items.error')
            ->cursor();

        $failures = [];
        $previousPath = null;
        foreach ($items as $item) {
            if ($item->destination_path === $previousPath) {
                continue;
            }
            $previousPath = $item->destination_path;
            if ($item->status === KbIngestBatchItem::STATUS_FAILED) {
                $failures[] = ['path' => $item->destination_path, 'error' => $item->error];
            }
        }

        return ['indexed_documents' => $indexed, 'is_indexing' => $indexing, 'failures' => $failures];
    }

    private function normalizeOrigin(string $value): string
    {
        $parts = parse_url($value);
        if (! filter_var($value, FILTER_VALIDATE_URL) || $parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            || ! isset($parts['host']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['allowed_origins' => [__('Enter HTTP or HTTPS origins with no path, query, or wildcard.')]]);
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $portSuffix = $port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '';

        return $scheme.'://'.strtolower($parts['host']).$portSuffix;
    }
}
