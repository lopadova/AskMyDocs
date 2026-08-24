<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WidgetKey;
use App\Services\Widget\WidgetIntroService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

final class WidgetIntroCommand extends Command
{
    protected $signature = 'widget:intro
                            {key : Widget key numeric id}
                            {--tenant=default : Tenant that owns the widget key}
                            {--json= : Complete or partial intro JSON object}
                            {--file= : Read the intro JSON object from a file}
                            {--disable : Disable the introduction without deleting its content}';

    protected $description = 'Inspect or configure a widget introduction card.';

    public function handle(WidgetIntroService $intro, TenantContext $tenants): int
    {
        $keyId = filter_var($this->argument('key'), FILTER_VALIDATE_INT);
        $tenantId = trim((string) $this->option('tenant'));
        if (! is_int($keyId) || $keyId < 1 || $tenantId === '') {
            $this->error('A positive numeric key id and a non-empty tenant are required.');
            return self::INVALID;
        }
        if ($this->option('json') !== null && $this->option('file') !== null) {
            $this->error('Use either --json or --file, not both.');
            return self::INVALID;
        }

        $previousTenant = $tenants->current();
        $tenants->set($tenantId);
        try {
            $key = WidgetKey::query()->forTenant($tenantId)->whereKey($keyId)->first();
            if (! $key instanceof WidgetKey) {
                $this->error("Widget key {$keyId} was not found for tenant {$tenantId}.");
                return self::FAILURE;
            }

            $patch = null;
            if ($this->option('disable')) {
                $patch = ['enabled' => false];
            } elseif ($this->option('json') !== null || $this->option('file') !== null) {
                $source = $this->option('json');
                if ($this->option('file') !== null) {
                    $path = (string) $this->option('file');
                    $source = is_file($path) ? file_get_contents($path) : false;
                    if ($source === false) {
                        $this->error("Unable to read intro JSON file: {$path}");
                        return self::FAILURE;
                    }
                }
                $decoded = json_decode((string) $source, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || array_is_list($decoded)) {
                    $this->error('Intro JSON must be an object.');
                    return self::INVALID;
                }
                $patch = $decoded;
            }

            if (is_array($patch)) {
                $resolved = $intro->patch($key->intro_config, $patch);
                $intro->assertUsable($resolved);
                $key->forceFill(['intro_config' => $resolved])->save();
            }

            $this->line(json_encode(
                $intro->resolve($key->fresh()->intro_config),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
            return self::SUCCESS;
        } catch (JsonException $e) {
            $this->error('Invalid JSON: '.$e->getMessage());
            return self::INVALID;
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first() ?? 'Invalid intro configuration.');
            return self::INVALID;
        } catch (Throwable $e) {
            report($e);
            $this->error('Widget intro operation failed.');
            return self::FAILURE;
        } finally {
            $tenants->set($previousTenant);
        }
    }
}
