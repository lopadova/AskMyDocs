<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class KnowledgeContract
{
    public const BLOCKS = ['types', 'identity', 'admission', 'contributions'];

    public function template(): array
    {
        return [
            'types' => [
                'entities' => array_map(fn ($key) => ['key' => $key, 'properties' => ['event', 'problem', 'intervention', 'outcome', 'instructions']], ['customer', 'order', 'ticket', 'procedure']),
                'relations' => [['key' => 'related_to', 'from' => '*', 'to' => '*']],
            ],
            'identity' => [
                'namespaces' => array_map(fn ($key) => ['key' => $key, 'type' => $key, 'strategy' => $key === 'procedure' ? 'source' : 'explicit', 'pattern' => '[A-Za-z0-9][A-Za-z0-9._/-]{0,99}'], ['customer', 'order', 'ticket', 'procedure']),
                'instructions' => 'Usa soltanto codici espliciti nelle fonti. Non unire omonimi o nomi simili. Cliente è la persona citata, non un account applicativo. Mantieni gli zeri iniziali.',
            ],
            'admission' => [
                'rules' => [
                    ['key' => 'history', 'instructions' => 'Aggiornamento esplicito di ordine, cliente o ticket: associa ogni fatto soltanto al suo soggetto.'],
                    ['key' => 'procedure', 'instructions' => 'Istruzioni operative complete riutilizzabili, anche in una sola risposta. Problema, intervento ed esito devono essere documentati se dichiarati.'],
                ],
                // Activation can be used for previews before a measured threshold is supplied.
                // Publication abstains until an administrator supplies a calibration reference.
                'calibration' => null,
            ],
            'contributions' => [
                'destinations' => [
                    ['rule' => 'history', 'types' => ['customer', 'order', 'ticket']],
                    ['rule' => 'procedure', 'types' => ['procedure']],
                ],
                'instructions' => 'Conserva contraddizioni e tentativi. Un ticket chiuso non dimostra la soluzione. Non usare schede automatiche o testo citato come conferme indipendenti.',
            ],
        ];
    }

    public function validateBlocks(array $blocks, bool $partial = false): array
    {
        if (array_diff(array_keys($blocks), self::BLOCKS) !== []) {
            throw ValidationException::withMessages(['blocks' => 'Blocchi sconosciuti.']);
        }
        foreach (self::BLOCKS as $key) {
            if (! array_key_exists($key, $blocks) || $blocks[$key] === null) {
                if (! $partial) {
                    throw ValidationException::withMessages(["blocks.$key" => 'Il blocco effettivo è obbligatorio.']);
                }
                $blocks[$key] = null;
            } elseif (! is_array($blocks[$key]) || array_is_list($blocks[$key])) {
                throw ValidationException::withMessages(["blocks.$key" => 'Usa un oggetto oppure null per ereditare.']);
            }
        }
        if ($partial) {
            return $blocks;
        }
        Validator::make($blocks, [
            'types.entities' => 'required|array|min:1|max:30',
            'types.entities.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/|distinct',
            'types.entities.*.properties' => 'required|array|min:1|max:30',
            'types.entities.*.properties.*' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/',
            'types.relations' => 'present|array|max:30',
            'types.relations.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/|distinct',
            'types.relations.*.from' => 'required|string', 'types.relations.*.to' => 'required|string',
            'identity.namespaces' => 'required|array|min:1|max:60',
            'identity.namespaces.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/|distinct',
            'identity.namespaces.*.type' => 'required|string',
            'identity.namespaces.*.strategy' => 'sometimes|in:explicit,source',
            'identity.namespaces.*.pattern' => 'required|string|max:500',
            'admission.rules' => 'required|array|min:1|max:30',
            'admission.rules.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,63}$/|distinct',
            'admission.rules.*.instructions' => 'required|string|max:3000',
            'admission.calibration' => 'nullable|array',
            'admission.calibration.reference' => 'required_with:admission.calibration|string|max:1000',
            'admission.calibration.threshold' => 'required_with:admission.calibration|numeric|gt:0.5|lte:1',
            'contributions.destinations' => 'required|array|min:1|max:60',
            'contributions.destinations.*.rule' => 'required|string',
            'contributions.destinations.*.types' => 'required|array|min:1',
        ])->validate();
        $types = array_column($blocks['types']['entities'], 'key');
        $rules = array_column($blocks['admission']['rules'], 'key');
        foreach ($blocks['identity']['namespaces'] as $ns) {
            if (! in_array($ns['type'], $types, true) || @preg_match($this->pattern($ns['pattern']), '') === false) {
                throw ValidationException::withMessages(['blocks.identity' => 'Tipo o espressione di riconoscimento non valido.']);
            }
        }
        foreach ($blocks['contributions']['destinations'] as $destination) {
            if (! in_array($destination['rule'], $rules, true) || array_diff($destination['types'], $types) !== []) {
                throw ValidationException::withMessages(['blocks.contributions' => 'Destinazione o regola sconosciuta.']);
            }
        }
        foreach ($blocks['types']['relations'] as $relation) {
            if (array_diff([$relation['from'], $relation['to']], [...$types, '*']) !== []) {
                throw ValidationException::withMessages(['blocks.types.relations' => 'Estremità della relazione sconosciuta.']);
            }
        }
        return $blocks;
    }

    public function pattern(string $expression): string
    {
        // Bound administrator-provided PCRE work and require the whole identifier.
        return '~(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=100)\A(?:'.str_replace('~', '\\~', $expression).')\z~u';
    }
}
