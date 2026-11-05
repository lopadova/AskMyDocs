<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\McpProjectConnection;
use App\Models\User;
use App\Services\Mcp\ConnectedProjectWorkspace;
use App\Services\Mcp\McpDocumentImportService;
use App\Services\Mcp\McpPluginPackage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ZipArchive;

final class McpConnectionController extends Controller
{
    public function metadata(string $connection): \Illuminate\Http\JsonResponse
    {
        $entry = McpProjectConnection::findOrFail($connection);
        return response()->json(['resource' => $entry->resource(),
            'authorization_servers' => [rtrim((string) config('app.url'), '/').'/oauth/mcp'],
            'scopes_supported' => ['mcp:read', 'mcp:ingest'], 'bearer_methods_supported' => ['header']]);
    }

    public function create(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user() instanceof User && $request->user()->is_active, 403);
        abort_if(McpProjectConnection::where('user_id', $request->user()->id)->count() >= 50, 409, 'Connection limit reached.');
        $entry = McpProjectConnection::create(['user_id' => $request->user()->id]);
        return response()->json(['id' => $entry->id, 'mcp_url' => $entry->resource(),
            'download_url' => '/api/oauth/mcp-connections/'.$entry->id.'/plugin.zip'], 201);
    }

    public function package(Request $request, string $connection, McpPluginPackage $packages): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $entry = McpProjectConnection::where('user_id', $request->user()->id)->findOrFail($connection);
        $path = tempnam(sys_get_temp_dir(), 'askmd-plugin-');
        abort_if($path === false, 503);
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::OVERWRITE) === true, 503);
        // This is a single-plugin upload, not a marketplace bundle. Codex looks
        // for plugin.json / .codex-plugin/plugin.json at the archive root.
        foreach ($packages->files($entry) as $name => $content) {
            if (! $zip->addFromString($name, $content)) {
                $zip->close();
                abort(503, 'Unable to package plugin.');
            }
        }
        abort_unless($zip->close(), 503);
        return response()->download($path, 'askmydocs-'.$entry->id.'.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function review(string $import, McpDocumentImportService $imports): \Illuminate\Http\JsonResponse
    {
        return response()->json($imports->review($import));
    }

    public function confirm(string $import, McpDocumentImportService $imports): \Illuminate\Http\JsonResponse
    {
        return response()->json($imports->confirm($import), 202);
    }

    public function workspace(Request $request, ConnectedProjectWorkspace $workspace, McpDocumentImportService $imports): \Illuminate\Http\JsonResponse
    {
        return response()->json(match ($request->route('action')) {
            'search' => $workspace->search($request->all()),
            'document' => $workspace->document($request->all()),
            'imports' => $imports->prepare($request->all()),
            default => $workspace->info(),
        });
    }
}
