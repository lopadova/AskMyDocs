<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreChatFolderRequest;
use App\Http\Requests\Api\UpdateChatFolderRequest;
use App\Models\ChatFolder;
use App\Services\Chat\ChatFolderService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RESTful CRUD for the folders that group chat sessions.
 *
 * Thin adapter over {@see ChatFolderService} (R44): the service owns the
 * tenant + owner scoping, this class only maps request → core → JSON.
 *
 * Another user's folder surfaces as 404, never 403, so the API does not
 * leak the existence of folders owned by anyone else (SEC-IDOR-001) —
 * the same posture as {@see ChatFilterPresetController}.
 *
 * Envelope is `{data: …}`, matching the sibling chat-filter-presets
 * resource. (The conversations endpoints answer a bare array instead;
 * that is a legacy shape which R27 forbids re-wrapping, not a
 * convention to copy.)
 */
final class ChatFolderController extends Controller
{
    public function __construct(private readonly ChatFolderService $folders)
    {
    }

    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $folders = $this->folders->listFor((int) $request->user()->id, $tenant->current());

        return response()->json(['data' => $this->present($folders->all())]);
    }

    public function store(StoreChatFolderRequest $request, TenantContext $tenant): JsonResponse
    {
        $folder = $this->folders->create(
            (int) $request->user()->id,
            $tenant->current(),
            $request->string('name')->toString(),
            (int) $request->input('position', 0),
        );

        return response()->json(['data' => $this->presentOne($folder)], 201);
    }

    public function update(UpdateChatFolderRequest $request, int $id, TenantContext $tenant): JsonResponse
    {
        $folder = $this->findOwnedOr404($request, $id, $tenant);
        $renamed = $this->folders->rename($folder, $request->string('name')->toString());

        return response()->json(['data' => $this->presentOne($renamed)]);
    }

    /**
     * Delete a folder. Its conversations are UNFILED, not deleted — see
     * the nullOnDelete FK. The UI must say so before confirming.
     */
    public function destroy(Request $request, int $id, TenantContext $tenant): JsonResponse
    {
        $this->folders->delete($this->findOwnedOr404($request, $id, $tenant));

        return response()->json(null, 204);
    }

    /**
     * @param  list<ChatFolder>  $folders
     * @return list<array<string, mixed>>
     */
    private function present(array $folders): array
    {
        return array_map(fn (ChatFolder $folder): array => $this->presentOne($folder), $folders);
    }

    /** @return array<string, mixed> */
    private function presentOne(ChatFolder $folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'position' => $folder->position,
            'created_at' => $folder->created_at,
            'updated_at' => $folder->updated_at,
        ];
    }

    private function findOwnedOr404(Request $request, int $id, TenantContext $tenant): ChatFolder
    {
        $folder = $this->folders->find($id, (int) $request->user()->id, $tenant->current());

        if ($folder === null) {
            throw new NotFoundHttpException('Folder not found.');
        }

        return $folder;
    }
}
