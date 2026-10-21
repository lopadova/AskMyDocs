<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpProjectConnection;
use App\Services\Mcp\McpPluginPackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class McpPluginCommand extends Command
{
    protected $signature = 'askmydocs:mcp:plugin {--output= : New local marketplace directory}';
    protected $description = 'Prepare an installable Codex OAuth plugin with a distinct project connection URL';

    public function handle(McpPluginPackage $packages): int
    {
        $output = $this->option('output') ?: storage_path('app/codex-plugin-'.now()->format('Ymd-His'));
        if (File::exists($output)) {
            $this->error('Choose a new output directory; existing plugins are never overwritten.');
            return self::FAILURE;
        }
        $entry = McpProjectConnection::create();
        foreach ($packages->files($entry) as $name => $content) {
            $path = $output.'/plugin/'.$name;
            File::ensureDirectoryExists(dirname($path));
            if (File::put($path, $content) === false) throw new \RuntimeException('Unable to write plugin.');
        }
        File::ensureDirectoryExists($output.'/.agents/plugins');
        if (File::put($output.'/.agents/plugins/marketplace.json', json_encode($packages->marketplace($entry), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Unable to write marketplace.');
        }
        $this->info('Plugin prepared at '.$output);
        $this->line('MCP URL: '.$entry->resource());
        $this->line('Add this directory as a local Codex marketplace, then install the plugin and connect with OAuth.');
        return self::SUCCESS;
    }
}
