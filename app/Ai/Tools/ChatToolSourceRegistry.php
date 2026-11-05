<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Support\Str;

final class ChatToolSourceRegistry
{
    /** @var list<ChatToolSourceContract> */
    private array $sources;

    /** @param iterable<ChatToolSourceContract> $sources */
    public function __construct(iterable $sources)
    {
        $this->sources = array_values(iterator_to_array((static function () use ($sources): \Generator {
            foreach ($sources as $source) {
                yield $source;
            }
        })()));
    }

    /**
     * @return array<string, array{source:ChatToolSourceContract,source_key:string,source_tool:array<string,mixed>,schema:array<string,mixed>,provenance:array<string,mixed>}>
     */
    public function toolIndex(User $user, ?string $projectKey = null): array
    {
        $index = [];

        foreach ($this->sources as $source) {
            try {
                $catalog = $source->catalog($user, $projectKey);
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($catalog as $sourceTool) {
                $remoteName = $sourceTool['name'] ?? null;
                if (! is_string($remoteName) || $remoteName === '') {
                    continue;
                }

                $localName = array_key_exists($remoteName, $index)
                    ? $this->collisionSafeName($source->key(), $remoteName, $index)
                    : $remoteName;
                $inputSchema = $sourceTool['inputSchema']
                    ?? $sourceTool['input_schema']
                    ?? $sourceTool['parameters']
                    ?? [];
                if (! is_array($inputSchema)) {
                    $inputSchema = [];
                }
                $inputSchema['type'] ??= 'object';
                $inputSchema['properties'] ??= [];
                $description = $sourceTool['description'] ?? '';

                $index[$localName] = [
                    'source' => $source,
                    'source_key' => $source->key(),
                    'source_tool' => $sourceTool,
                    'schema' => [
                        'type' => 'function',
                        'function' => [
                            'name' => $localName,
                            'description' => is_string($description) ? $description : '',
                            'parameters' => $inputSchema,
                        ],
                    ],
                    'provenance' => is_array($sourceTool['provenance'] ?? null)
                        ? $sourceTool['provenance']
                        : [],
                ];
            }
        }

        return $index;
    }

    /** @param array<string, mixed> $existing */
    private function collisionSafeName(string $source, string $remoteName, array $existing): string
    {
        $slug = Str::of($source.'_'.$remoteName)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->limit(48, '')
            ->toString();
        $slug = $slug !== '' ? $slug : 'tool';
        $candidate = $slug.'_'.substr(hash('sha256', $source."\0".$remoteName), 0, 8);
        $suffix = 1;

        while (array_key_exists($candidate, $existing)) {
            $candidate = substr($slug, 0, 45).'_'.$suffix.'_'.substr(hash('sha256', $source."\0".$remoteName), 0, 8);
            $suffix++;
        }

        return $candidate;
    }
}
