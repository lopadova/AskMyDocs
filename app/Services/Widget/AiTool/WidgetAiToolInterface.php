<?php

declare(strict_types=1);

namespace App\Services\Widget\AiTool;

use App\Models\WidgetSession;

/**
 * WidgetAiToolInterface — contratto che ogni tool BE (AiTool) deve implementare (R23).
 *
 * Ogni tool BE registrato come FQCN nel registry DEVE:
 * 1. Implementare questa interfaccia.
 * 2. Dichiarare il proprio nome univoco tramite `toolName()` — usato come
 *    key nel registry e come nome presentato all'LLM nel catalogo.
 * 3. Dichiare una description leggibile dal modello tramite `description()`.
 * 4. Supportare una funzione `supports()` che determina se il tool è
 *    abilitato per una data combinazione di ai_tools + tools_enabled.
 *    Due tool NON possono entrambi restituire `supports()=true` per lo
 *    stesso nome (mutex R23).
 * 5. Eseguire la business logic tramite `execute()` e ritornare un
 *    artifact renderizzabile nella chat.
 */
interface WidgetAiToolInterface
{
    /**
     * Nome univoco del tool (snake_case, presentato all'LLM).
     * Es: "search_knowledge_base", "articoli.cerca".
     */
    public function toolName(): string;

    /**
     * Descrizione del tool per il modello (presentata nel catalogo OpenAI).
     */
    public function description(): string;

    /**
     * Schema JSON dei parametri in formato OpenAI function-calling.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * Verifica se il tool è abilitato per la skill corrente.
     *
     * I tool built-in (nome presente in $builtins) sono abilitati se
     * appaiono in $toolsEnabled. I tool custom FQCN sono abilitati se
     * il proprio toolName() appare in $aiTools.
     *
     * @param  list<string>  $aiTools  lista `ai_tools` dal manifest
     * @param  list<string>  $toolsEnabled  lista `tools_enabled` dal manifest
     * @param  bool  $isBuiltin  true se il tool è nella lista built-in del registry
     */
    public function supports(array $aiTools, array $toolsEnabled, bool $isBuiltin): bool;

    /**
     * Esegue la business logic e ritorna il payload artifact.
     *
     * @param  array<string, mixed>  $args
     * @return array{artifact: array<string, mixed>, has_results: bool, interaction_mode: string}
     */
    public function execute(array $args, WidgetSession $session): array;
}
