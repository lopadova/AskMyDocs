<?php

declare(strict_types=1);

namespace App\Services\Widget;

/**
 * WidgetToolCatalog — il catalogo completo dei tool che il modello può invocare
 * (function-calling). Port del catalogo KITT (spec §5).
 *
 * Ogni tool dichiara: lato d'esecuzione (`fe` = eseguito dall'Executor del
 * widget sul DOM; `be` = eseguito server-side via /exec-tool, da M4), lo
 * schema JSON dei parametri (presentato all'LLM), e i flag che guidano la
 * validazione (`field`/`target` devono esistere nello snapshot; `confirm`
 * richiede conferma utente prima dell'esecuzione).
 *
 * Catalogo FE completo (21 tool DOM + 3 conversazionali = 24):
 * click, type, select, scroll_to, navigate_to, submit_form, read_page,
 * ask_user, report_done, report_blocked (M2), combobox_search, combobox_set,
 * toggle, radio, set_locale, goto_step, wait_for, tour_step, move_cursor,
 * show_recap (M4). Tool BE: search_knowledge_base (M4).
 */
final class WidgetToolCatalog
{
    public const SIDE_FE = 'fe';
    public const SIDE_BE = 'be';

    /**
     * Definizioni complete dei tool, keyed per nome.
     *
     * @return array<string, array{side: string, confirm: bool, needs: list<string>, description: string, parameters: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return [
            'click' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['target'],
                'description' => 'Clicca un elemento azionabile della pagina, identificato dal verb di una action annotata o da id/testid/testo di un bottone.',
                'parameters' => $this->object([
                    'target' => $this->string('Verb dell\'action, oppure id/testid/testo del bottone da cliccare.'),
                ], ['target']),
            ],
            'type' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['field'],
                'description' => 'Scrive testo in un input o textarea identificato dal nome del field.',
                'parameters' => $this->object([
                    'field' => $this->string('Nome del field (data-kitt-field) o name/testid dell\'input.'),
                    'value' => $this->string('Testo da scrivere.'),
                    'append' => ['type' => 'boolean', 'description' => 'Se true concatena invece di sovrascrivere.'],
                ], ['field', 'value']),
            ],
            'select' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['field'],
                'description' => 'Imposta il valore di un <select> (singolo o multiplo) usando SOLO le option presenti nello snapshot.',
                'parameters' => $this->object([
                    'field' => $this->string('Nome del field del select.'),
                    'value' => [
                        'description' => 'Valore o label dell\'option (o array per select multipli).',
                        'anyOf' => [
                            ['type' => 'string'],
                            ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ], ['field', 'value']),
            ],
            'scroll_to' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],  // il target può essere 'top'/'bottom', non sempre in snapshot
                'description' => 'Scrolla il viewport verso un target (verb di action), oppure "top"/"bottom".',
                'parameters' => $this->object([
                    'target' => $this->string('Verb dell\'action, "top" o "bottom".'),
                ], ['target']),
            ],
            'navigate_to' => [
                'side' => self::SIDE_FE,
                'confirm' => true,
                'needs' => [],
                'description' => 'Naviga la pagina ospite a un altro URL. Consentito solo entro l\'allowlist di navigazione (di norma same-origin).',
                'parameters' => $this->object([
                    'url' => $this->string('URL di destinazione (assoluto o path relativo).'),
                ], ['url']),
            ],
            'submit_form' => [
                'side' => self::SIDE_FE,
                'confirm' => true,
                'needs' => [],
                'description' => 'Invia il form principale della pagina. Richiede conferma esplicita dell\'utente.',
                'parameters' => $this->object([], []),
            ],
            'read_page' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Richiede uno snapshot aggiornato della pagina senza modificarla.',
                'parameters' => $this->object([], []),
            ],
            'ask_user' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Sospende l\'azione e pone una domanda all\'utente in chat (eventualmente con opzioni).',
                'parameters' => $this->object([
                    'question' => $this->string('Domanda da porre all\'utente.'),
                    'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Opzioni suggerite (facoltative).'],
                ], ['question']),
            ],
            'report_done' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Termina il compito con successo, con un breve riassunto.',
                'parameters' => $this->object([
                    'summary' => $this->string('Riassunto di ciò che è stato fatto.'),
                ], ['summary']),
            ],
            'report_blocked' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Termina il compito come bloccato, spiegando il motivo.',
                'parameters' => $this->object([
                    'reason' => $this->string('Motivo del blocco.'),
                ], ['reason']),
            ],

            // --- M4: tool DOM aggiuntivi (spec §5.1) ---

            'combobox_search' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['field'],
                'description' => 'Apre il dropdown di un combobox, digita la query e restituisce le opzioni trovate (max 20). Cap timeout 8s.',
                'parameters' => $this->object([
                    'field' => $this->string('Nome del field del combobox.'),
                    'query' => $this->string('Testo da cercare nel dropdown.'),
                ], ['field', 'query']),
            ],
            'combobox_set' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['field'],
                'description' => 'Seleziona atomica: apre dropdown, digita query, sceglie l\'option che matcha value esattamente. Su fail ritorna le opzioni disponibili.',
                'parameters' => $this->object([
                    'field' => $this->string('Nome del field del combobox.'),
                    'value' => $this->string('Valore o label dell\'option da selezionare.'),
                    'query' => ['type' => 'string', 'description' => 'Query di ricerca (default = value).'],
                ], ['field', 'value']),
            ],
            'toggle' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['field'],
                'description' => 'Imposta o inverte lo stato di un checkbox/switch. Se on è omesso, inverte lo stato attuale.',
                'parameters' => $this->object([
                    'field' => $this->string('Nome del field del checkbox/switch.'),
                    'on' => ['type' => 'boolean', 'description' => 'true = attiva, false = disattiva. Se omesso, inverte.'],
                ], ['field']),
            ],
            'radio' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['field'],
                'description' => 'Seleziona un radio button per value.',
                'parameters' => $this->object([
                    'field' => $this->string('Nome del field del radio group.'),
                    'value' => $this->string('Value dell\'opzione da selezionare.'),
                ], ['field', 'value']),
            ],
            'set_locale' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Cambia la lingua attiva del form. Il locale deve essere in locales_available dello snapshot.',
                'parameters' => $this->object([
                    'locale' => $this->string('Codice locale (es. "it", "en"). Deve essere in locales_available.'),
                ], ['locale']),
            ],
            'goto_step' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Naviga in un wizard multi-step. Lo step deve esistere nello snapshot.',
                'parameters' => $this->object([
                    'step' => $this->string('Identificativo dello step di destinazione.'),
                    'mode' => ['type' => 'string', 'description' => '"next", "prev" o "jump" (default "jump").', 'enum' => ['next', 'prev', 'jump']],
                ], ['step']),
            ],
            'wait_for' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Attende che una condizione DOM si verifichi (es. comparsa elemento, testo). Timeout default 5s.',
                'parameters' => $this->object([
                    'condition' => $this->string('Descrizione della condizione da attendere (es. "elemento con testid submit-btn visibile").'),
                    'timeout_ms' => ['type' => 'integer', 'description' => 'Timeout in millisecondi (default 5000).'],
                ], ['condition']),
            ],
            'tour_step' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],  // highlight_target può essere un selettore CSS, non necessariamente in snapshot
                'description' => 'Mostra un overlay tour sopra un elemento, con messaggio e navigazione.',
                'parameters' => $this->object([
                    'step_index' => ['type' => 'integer', 'description' => 'Indice dello step corrente (0-based).'],
                    'step_total' => ['type' => 'integer', 'description' => 'Numero totale di step del tour.'],
                    'highlight_target' => $this->string('Selettore o verb dell\'elemento da evidenziare.'),
                    'message' => $this->string('Testo del messaggio da mostrare.'),
                    'scroll' => ['type' => 'boolean', 'description' => 'Se true, scrolla verso l\'elemento (default true).'],
                ], ['step_index', 'step_total', 'highlight_target', 'message']),
            ],
            'move_cursor' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => ['target'],
                'description' => 'Sposta il cursore virtuale su un elemento (effetto visivo, accessibilità).',
                'parameters' => $this->object([
                    'target' => $this->string('Verb dell\'action oppure id/testid dell\'elemento.'),
                ], ['target']),
            ],
            'show_recap' => [
                'side' => self::SIDE_FE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Mostra un recap riassuntivo nella chat prima di un submit.',
                'parameters' => $this->object([
                    'summary' => $this->string('Riassunto della compilazione.'),
                    'rows' => [
                        'type' => 'array',
                        'description' => 'Righe del recap: name = valore chiave, label = etichetta, value = valore.',
                        'items' => [
                            'type' => 'object',
                            'properties' => (object) [
                                'name' => ['type' => 'string', 'description' => 'Nome chiave della riga.'],
                                'label' => ['type' => 'string', 'description' => 'Etichetta human-readable.'],
                                'value' => ['type' => 'string', 'description' => 'Valore compilato.'],
                            ],
                            'additionalProperties' => false,
                            'required' => ['name', 'label', 'value'],
                        ],
                    ],
                ], ['summary']),
            ],

            // --- M4: Tool BE (AiTool registry-driven, spec §5.3) ---

            'search_knowledge_base' => [
                'side' => self::SIDE_BE,
                'confirm' => false,
                'needs' => [],
                'description' => 'Cerca nella knowledge base del progetto e ritorna i documenti rilevanti come artifact. Usa questo tool quando l\'utente chiede informazioni che potrebbero essere nella documentazione o nel knowledge base.',
                'parameters' => $this->object([
                    'query' => $this->string('Termine o frase da cercare nella knowledge base.'),
                ], ['query']),
            ],
        ];
    }

    /**
     * L'array `tools` in formato OpenAI per i tool abilitati dalla skill,
     * intersecato con quelli effettivamente definiti nel catalogo.
     *
     * @param  list<string>  $enabled
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function openAiTools(array $enabled): array
    {
        $defs = $this->definitions();
        $tools = [];

        foreach ($enabled as $name) {
            if (! isset($defs[$name])) {
                continue;
            }
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $defs[$name]['description'],
                    'parameters' => $defs[$name]['parameters'],
                ],
            ];
        }

        return $tools;
    }

    public function isDefined(string $tool): bool
    {
        return isset($this->definitions()[$tool]);
    }

    /**
     * @return array{side: string, confirm: bool, needs: list<string>, description: string, parameters: array<string, mixed>}|null
     */
    public function definition(string $tool): ?array
    {
        return $this->definitions()[$tool] ?? null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function object(array $properties, array $required): array
    {
        $schema = [
            'type' => 'object',
            'properties' => (object) $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, string>
     */
    private function string(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }
}
