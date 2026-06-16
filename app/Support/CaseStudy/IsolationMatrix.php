<?php

declare(strict_types=1);

namespace App\Support\CaseStudy;

use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Support\Collection;

/**
 * The documentation-isolation test matrix, as data.
 *
 * This is the single, executable source of truth for the manual checklist in
 * `docs/case-studies/README.md` §6 / §6.5. Both the automated live test
 * ({@see \Tests\Live\Rag\LiveRagIsolationTest}) and the operator CLI
 * ({@see \App\Console\Commands\CaseStudyVerifyIsolationCommand}) consume
 * `cases()` and `evaluate()` so the two surfaces can never drift from the
 * README — change the README's matrix, change THIS file, and both surfaces
 * follow (R9: every value below is copied verbatim from the README tables and
 * verified present in the named source file under
 * `docs/case-studies/data/<project>/`).
 *
 * The isolation guarantee being asserted: when a chat turn is scoped to
 * project A (the chat filter `filters.project_keys`, or the legacy
 * `project_key`), retrieval applies a HARD chunk-level
 * `where knowledge_chunks.project_key in (A)` (and the graph expander /
 * rejected-approach injector are scoped to A too), so a document belonging to
 * company B or C can NEVER surface in A's answer or citations — even though all
 * three companies live in the same tenant. The "canaries" (esche) are unique
 * facts/passwords planted in exactly one company; if one ever appears in
 * another company's retrieved context, isolation is broken.
 */
final class IsolationMatrix
{
    /** @var list<string> the three case-study projects (== data/<key>/ dirs) */
    public const PROJECTS = [
        'rotta-logistics',
        'prometeo-antincendio',
        'passolibero-calzature',
    ];

    /**
     * Every isolation case. Each entry:
     *  - id            stable identifier (README row label).
     *  - kind          positive | negative | disambiguation | shared_password.
     *  - project       the SELECTED project_key for the chat turn.
     *  - question      the user question (verbatim from the README table).
     *  - expected      strings that MUST appear in the selected project's
     *                  retrieved context (proves the value is reachable from
     *                  the right company). Empty for pure refusal cases.
     *  - forbidden     foreign canaries that MUST NOT appear in the retrieved
     *                  context (the contamination detector).
     *  - expect_refusal whether the README promises a deterministic refusal.
     *  - note          short rationale (which company really owns the fact).
     *
     * @return list<array{id: string, kind: string, project: string, question: string, expected: list<string>, forbidden: list<string>, expect_refusal: bool, note: string}>
     */
    public static function cases(): array
    {
        return array_merge(
            self::positives(),
            self::negatives(),
            self::disambiguation(),
            self::sharedPasswords(),
        );
    }

    /**
     * README §6.2 — ask the OWNING company; the exact value must be reachable
     * and the turn must NOT refuse.
     *
     * @return list<array{id: string, kind: string, project: string, question: string, expected: list<string>, forbidden: list<string>, expect_refusal: bool, note: string}>
     */
    private static function positives(): array
    {
        return [
            self::make('P1', 'positive', 'rotta-logistics',
                'Entro che ora devo ordinare per la spedizione in giornata?',
                expected: ['17:30'], note: 'cut-off ordini in servizi-spedizione.md'),
            self::make('P2', 'positive', 'prometeo-antincendio',
                'Quante ore dura il Corso Salamandra per il rischio alto?',
                expected: ['16 ore'], note: 'formazione-corso-salamandra.md'),
            self::make('P3', 'positive', 'passolibero-calzature',
                'Entro quanti giorni posso effettuare un reso?',
                expected: ['30 giorni'], note: 'politica-resi-30-giorni.md'),
            self::make('P4', 'positive', 'prometeo-antincendio',
                'Ogni quanti anni va rinnovato il CPI?',
                expected: ['5 anni'], note: 'normativa-cpi.md (controprova di N1)'),
            self::make('P5', 'positive', 'passolibero-calzature',
                'Quanto costa la Zefiro Run 2.0?',
                expected: ['129,90'], note: 'catalogo-modelli.md (controprova di N3)'),
        ];
    }

    /**
     * README §6.1 (N1–N6) + §6.5 Test F (F1–F4) — ask company A a fact that
     * lives ONLY in B/C. The foreign canary must never leak, and the README
     * promises a deterministic refusal (no grounded context in A).
     *
     * @return list<array{id: string, kind: string, project: string, question: string, expected: list<string>, forbidden: list<string>, expect_refusal: bool, note: string}>
     */
    private static function negatives(): array
    {
        return [
            self::make('N1', 'negative', 'rotta-logistics',
                'Ogni quanti anni va rinnovato il CPI?',
                forbidden: ['5 anni', 'D.M. 03/08/2015'], expectRefusal: true,
                note: 'CPI vive in prometeo-antincendio'),
            self::make('N2', 'negative', 'rotta-logistics',
                "Cos'è il programma fedeltà ClubPasso e come si accumulano i punti?",
                forbidden: ['ClubPasso', 'STELLA NOVE'], expectRefusal: true,
                note: 'ClubPasso vive in passolibero-calzature'),
            self::make('N3', 'negative', 'prometeo-antincendio',
                'Quanto costa il modello Zefiro Run 2.0?',
                forbidden: ['129,90'], expectRefusal: true,
                note: 'Zefiro Run vive in passolibero-calzature'),
            self::make('N4', 'negative', 'prometeo-antincendio',
                'Qual è il prefisso dei codici di tracking delle spedizioni?',
                forbidden: ['RL-'], expectRefusal: true,
                note: 'prefisso RL- vive in rotta-logistics'),
            self::make('N5', 'negative', 'passolibero-calzature',
                "Cos'è il Protocollo Fenice-7?",
                forbidden: ['Fenice-7', 'FALCO 12'], expectRefusal: true,
                note: 'Protocollo Fenice-7 vive in prometeo-antincendio'),
            self::make('N6', 'negative', 'passolibero-calzature',
                'Quale hub gestisce le merci pericolose ADR?',
                forbidden: ['HUB-MI-07'], expectRefusal: true,
                note: 'gli hub ADR vivono in rotta-logistics'),
            self::make('F1', 'negative', 'rotta-logistics',
                "Qual è la parola d'ordine dell'Attivazione Squadra Antincendio?",
                forbidden: ['FALCO 12'], expectRefusal: true,
                note: 'procedura di prometeo-antincendio'),
            self::make('F2', 'negative', 'passolibero-calzature',
                "Qual è la parola d'ordine per l'Isolamento Quadro Elettrico?",
                forbidden: ['CENERE SILENTE'], expectRefusal: true,
                note: 'procedura di prometeo-antincendio'),
            self::make('F3', 'negative', 'prometeo-antincendio',
                "Qual è la parola d'ordine della Chiusura Cassa di Emergenza?",
                forbidden: ['STELLA NOVE'], expectRefusal: true,
                note: 'procedura di passolibero-calzature'),
            self::make('F4', 'negative', 'passolibero-calzature',
                "Cosa attiva la parola d'ordine «NEBBIA GIALLA»?",
                forbidden: ['NEBBIA GIALLA', 'Allerta Versamento ADR'], expectRefusal: true,
                note: 'NEBBIA GIALLA è la parola di rotta-logistics'),
        ];
    }

    /**
     * README §6.3 — overlapping topics with DIFFERENT values per company. The
     * owner answers with its own value; the other company must never emit the
     * owner's value (it has its own, different policy — so it answers without
     * refusing, but never with the foreign figure).
     *
     * @return list<array{id: string, kind: string, project: string, question: string, expected: list<string>, forbidden: list<string>, expect_refusal: bool, note: string}>
     */
    private static function disambiguation(): array
    {
        return [
            self::make('D1-passolibero', 'disambiguation', 'passolibero-calzature',
                'Sopra quale importo la spedizione è gratuita?',
                expected: ['€60'], note: 'spedizione gratuita sopra €60 (BrioExpress)'),
            self::make('D1-rotta', 'disambiguation', 'rotta-logistics',
                'Sopra quale importo la spedizione è gratuita?',
                forbidden: ['€60'], note: 'rotta non ha spedizione gratuita: mai €60'),
            self::make('D2-passolibero', 'disambiguation', 'passolibero-calzature',
                'Qual è la politica di reso?',
                expected: ['30 giorni'], note: 'resi 30 giorni, rimborso 5 gg'),
            self::make('D2-rotta', 'disambiguation', 'rotta-logistics',
                'Qual è la politica di reso?',
                forbidden: ['30 giorni'], note: 'rotta: politica giacenze/rimborsi logistici, diversa'),
        ];
    }

    /**
     * README §6.5 Test E — the single most diagnostic case. The SAME question
     * about the shared-name "Procedura di Evacuazione Totale" must yield each
     * company's OWN password and never another company's. All three passwords
     * are distinct.
     *
     * @return list<array{id: string, kind: string, project: string, question: string, expected: list<string>, forbidden: list<string>, expect_refusal: bool, note: string}>
     */
    private static function sharedPasswords(): array
    {
        $question = "Qual è la parola d'ordine della Procedura di Evacuazione Totale?";

        return [
            self::make('E-rotta', 'shared_password', 'rotta-logistics', $question,
                expected: ['ORIZZONTE BLU'], forbidden: ['VENTO DEL NORD', 'MARE CALMO'],
                note: 'parola condivisa: rotta = ORIZZONTE BLU'),
            self::make('E-prometeo', 'shared_password', 'prometeo-antincendio', $question,
                expected: ['VENTO DEL NORD'], forbidden: ['ORIZZONTE BLU', 'MARE CALMO'],
                note: 'parola condivisa: prometeo = VENTO DEL NORD'),
            self::make('E-passolibero', 'shared_password', 'passolibero-calzature', $question,
                expected: ['MARE CALMO'], forbidden: ['ORIZZONTE BLU', 'VENTO DEL NORD'],
                note: 'parola condivisa: passolibero = MARE CALMO'),
        ];
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $forbidden
     * @return array{id: string, kind: string, project: string, question: string, expected: list<string>, forbidden: list<string>, expect_refusal: bool, note: string}
     */
    private static function make(
        string $id,
        string $kind,
        string $project,
        string $question,
        array $expected = [],
        array $forbidden = [],
        bool $expectRefusal = false,
        string $note = '',
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'project' => $project,
            'question' => $question,
            'expected' => $expected,
            'forbidden' => $forbidden,
            'expect_refusal' => $expectRefusal,
            'note' => $note,
        ];
    }

    /**
     * Pure verdict for one case against a real retrieval result. Returns the
     * list of failure messages — an EMPTY list means the case passed. Kept
     * free of any service/IO dependency so it is identical whether driven by
     * the PHPUnit live test or the operator CLI.
     *
     * @param  array{id: string, kind: string, project: string, question: string, expected: list<string>, forbidden: list<string>, expect_refusal: bool, note: string}  $case
     * @param  list<array<string, mixed>>  $citations  output of ChatRetrievalService::buildCitations()
     * @return list<string> failure messages (empty == pass)
     */
    public static function evaluate(array $case, SearchResult $result, array $citations, bool $refused): array
    {
        $failures = [];
        $project = $case['project'];

        $chunks = $result->primary
            ->concat($result->expanded)
            ->concat($result->rejected);

        // INVARIANT 1 — no chunk from a foreign project may appear. The hard
        // project filter makes this structurally impossible; asserting it
        // catches any future regression that relaxes the scope to a boost.
        $foreignChunkProjects = $chunks
            ->map(static fn ($c): ?string => data_get($c, 'project_key') ?? data_get($c, 'document.project_key'))
            ->filter()
            ->unique()
            ->reject(static fn (string $p): bool => $p === $project)
            ->values();
        foreach ($foreignChunkProjects as $foreign) {
            $failures[] = "chunk from foreign project '{$foreign}' present (expected only '{$project}')";
        }

        // INVARIANT 2 — every citation must point at the selected project
        // (project_key is read from the chunk, not the document relation —
        // guards the v8.8 citation-provenance regression too).
        foreach ($citations as $citation) {
            $cp = $citation['project_key'] ?? null;
            if ($cp !== null && $cp !== $project) {
                $failures[] = "citation points at foreign project '{$cp}' (expected '{$project}')";
            }
        }

        // CONTAMINATION — no foreign canary may appear in the retrieved text.
        $haystack = $chunks
            ->map(static fn ($c): string => (string) data_get($c, 'chunk_text', ''))
            ->implode("\n");
        foreach ($case['forbidden'] as $canary) {
            if (stripos($haystack, $canary) !== false) {
                $failures[] = "foreign canary '{$canary}' leaked into '{$project}' context";
            }
        }

        // REFUSAL — the README promises a deterministic refusal for the
        // cross-company questions (no grounded context in the selected project).
        if ($case['expect_refusal'] && ! $refused) {
            $failures[] = "expected a refusal (no grounded context) but the turn was grounded";
        }

        // GROUNDING — a positive / shared-password / owning-disambiguation case
        // must reach its value AND must not refuse.
        if (! $case['expect_refusal'] && $case['expected'] !== []) {
            if ($refused) {
                $failures[] = 'expected a grounded answer but the turn was refused';
            }
            foreach ($case['expected'] as $value) {
                if (stripos($haystack, $value) === false) {
                    $failures[] = "expected value '{$value}' was not retrieved from '{$project}'";
                }
            }
        }

        return $failures;
    }

    /**
     * Absolute path to the versioned dataset directory. Resolved from the
     * repo root so the test and CLI agree on one location.
     */
    public static function dataDir(): string
    {
        return base_path('docs/case-studies/data');
    }

    /**
     * Markdown files (.md + .markdown, R18) for one project, sorted for
     * deterministic ingest order.
     *
     * @return list<string> absolute paths
     */
    public static function documentsFor(string $project): array
    {
        $files = array_merge(
            glob(self::dataDir() . "/{$project}/*.md") ?: [],
            glob(self::dataDir() . "/{$project}/*.markdown") ?: [],
        );
        sort($files);

        return array_values($files);
    }
}
