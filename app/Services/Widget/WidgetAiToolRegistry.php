<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Models\WidgetSession;
use App\Services\Widget\AiTool\WidgetAiToolInterface;

/**
 * WidgetAiToolRegistry — registry per i tool BE (AiTool) del widget KITT.
 *
 * R23: ogni tool BE è registrato con FQCN valido. Il registry:
 *   1. Valida che ogni FQCN esista e implementi WidgetAiToolInterface.
 *   2. Garantisce il mutex `supports()`: nessun overlap sui nomi tool
 *      (due tool non possono avere lo stesso toolName()).
 *   3. Risolve il tool, esegue la business logic e ritorna un artifact.
 *
 * I tool built-in sono passati via costruttore (default: SearchKnowledgeBaseTool).
 * I tool custom (per-mission tramite manifest `ai_tools`) saranno aggiunti
 * in M5+ tramite configurazione dinamica.
 */
final class WidgetAiToolRegistry
{
    /** Whitelist di componentType ammessi per gli artifact (spec §5.3). */
    private const ALLOWED_COMPONENT_TYPES = [
        'ui-kpi', 'ui-kpi-grid', 'ui-data-table', 'ui-alert',
        'ui-card', 'ui-badge', 'ui-toast', 'ui-list',
        'ui-chart', 'markdown', 'code-block',
    ];

    /**
     * Istanze dei tool registrati, keyed per toolName().
     *
     * @var array<string, WidgetAiToolInterface>
     */
    private array $tools = [];

    /**
     * Lista dei nomi built-in (per distinguerli dai custom FQCN in supports()).
     *
     * @var list<string>
     */
    private array $builtinNames = [];

    /**
     * @param  list<string>  $builtinFqcns  FQCN dei tool built-in da registrare
     *
     * @throws \InvalidArgumentException se un FQCN non esiste, non implementa
     *         l'interfaccia, o ha un toolName() in conflitto (mutex R23).
     */
    public function registerBuiltin(string ...$builtinFqcns): void
    {
        foreach ($builtinFqcns as $fqcn) {
            $this->register($fqcn, isBuiltin: true);
        }
    }

    /**
     * Registra un tool FQCN nel registry (R23).
     *
     * Valida che la classe esista, implementi WidgetAiToolInterface,
     * e che il toolName() non collida con un tool già registrato (mutex).
     *
     * @throws \InvalidArgumentException se la validazione fallisce
     */
    public function register(string $fqcn, bool $isBuiltin = false): void
    {
        if (! class_exists($fqcn)) {
            throw new \InvalidArgumentException("AiTool FQCN '{$fqcn}' does not exist.");
        }

        $instance = app($fqcn);

        if (! ($instance instanceof WidgetAiToolInterface)) {
            throw new \InvalidArgumentException("AiTool FQCN '{$fqcn}' must implement WidgetAiToolInterface.");
        }

        $name = $instance->toolName();

        // Mutex R23: nessun overlap sui nomi tool
        if (isset($this->tools[$name])) {
            $existingClass = get_class($this->tools[$name]);
            throw new \InvalidArgumentException(
                "AiTool name conflict: '{$name}' is already registered by {$existingClass}, cannot also register {$fqcn}."
            );
        }

        $this->tools[$name] = $instance;

        if ($isBuiltin) {
            $this->builtinNames[] = $name;
        }
    }

    /**
     * Verifica se un tool BE è abilitato per la skill corrente.
     * Delega al tool's supports() rispettando il contratto R23.
     *
     * @param  list<string>  $aiTools  lista `ai_tools` dal manifest (può essere vuota)
     * @param  list<string>  $toolsEnabled  lista `tools_enabled` dal manifest
     */
    public function supports(string $tool, array $aiTools, array $toolsEnabled): bool
    {
        if (! isset($this->tools[$tool])) {
            // Tool non registrato — potrebbe essere un nome custom nel manifest
            // che non abbiamo ancora mappato (M5+). Per ora, controlla ai_tools.
            return in_array($tool, $aiTools, true);
        }

        $isBuiltin = in_array($tool, $this->builtinNames, true);

        return $this->tools[$tool]->supports($aiTools, $toolsEnabled, $isBuiltin);
    }

    /**
     * Esegue un tool BE e ritorna il payload artifact.
     *
     * @param  array<string, mixed>  $args
     * @return array{artifact: array<string, mixed>, has_results: bool, interaction_mode: string}
     *
     * @throws \InvalidArgumentException se il tool non è registrato
     */
    public function execute(string $tool, array $args, WidgetSession $session): array
    {
        if (! isset($this->tools[$tool])) {
            throw new \InvalidArgumentException("AiTool BE '{$tool}' is not a registered tool.");
        }

        $result = $this->tools[$tool]->execute($args, $session);

        // Valida il componentType dell'artifact contro la whitelist
        $componentType = $result['artifact']['componentType'] ?? '';
        if ($componentType !== '' && ! in_array($componentType, self::ALLOWED_COMPONENT_TYPES, true)) {
            $result['artifact']['componentType'] = 'ui-alert';
            $result['artifact']['componentProps'] = [
                'level' => 'error',
                'title' => 'Unsupported component',
                'message' => "Component type '{$componentType}' is not in the allowed list.",
            ];
        }

        return $result;
    }

    /**
     * Ritorna la lista di tutti i nomi tool registrati.
     *
     * @return list<string>
     */
    public function registeredNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Ritorna la lista di tutti i nomi tool built-in.
     *
     * @return list<string>
     */
    public function builtinNames(): array
    {
        return $this->builtinNames;
    }

    /**
     * Ritorna tutti i tool registrati in formato OpenAI function-calling.
     * Usato dall'orchestratore per includere i tool BE nel prompt del modello.
     *
     * @param  list<string>  $enabled  lista `tools_enabled` dal manifest
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function openAiTools(array $enabled): array
    {
        $tools = [];

        foreach ($this->tools as $name => $tool) {
            $isBuiltin = in_array($name, $this->builtinNames, true);
            if (! $tool->supports([], $enabled, $isBuiltin)) {
                continue;
            }

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool->description(),
                    'parameters' => $tool->parametersSchema(),
                ],
            ];
        }

        return $tools;
    }
}
