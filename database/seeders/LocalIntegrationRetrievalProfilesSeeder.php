<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KbRetrievalProfile;
use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Installs the trusted, project-scoped retrieval vocabulary for the local
 * case studies. These profiles are fixture configuration, not information
 * extracted from the seeded documents or fixture e-mails.
 */
final class LocalIntegrationRetrievalProfilesSeeder extends Seeder
{
    /**
     * The vocabulary deliberately reuses the identifiers exposed by the local
     * e-mail, document, API and MCP fixtures. This lets a single test question
     * use one coherent business domain while the recursive KB stage remains
     * scoped to its own trusted profile.
     *
     * @var array<string, array{company_context:string,glossary:list<array{term:string,meaning:string,aliases:list<string>}>,relevant_entities:list<string>,expected_facts:list<string>,preferred_source_types:list<string>}>
     */
    private const PROFILES = [
        'rotta-logistics' => [
            'company_context' => 'Rotta Sicura Logistics è un operatore di logistica e spedizioni. Le richieste riguardano spedizioni, tracking, hub, corrieri, documenti di trasporto, dogana, SLA, giacenze e resi.',
            'glossary' => [
                ['term' => 'spedizione', 'meaning' => 'Pratica di trasporto identificata da un riferimento RL e da un tracking.', 'aliases' => ['consegna', 'collo', 'tracking']],
                ['term' => 'DDT', 'meaning' => 'Documento di trasporto collegato a mittente, destinatario, colli e spedizione.', 'aliases' => ['documento di trasporto']],
                ['term' => 'SLA', 'meaning' => 'Livello di servizio che misura le consegne entro il tempo concordato.', 'aliases' => ['livello di servizio', 'penali']],
                ['term' => 'giacenza', 'meaning' => 'Spedizione ferma presso un hub o la dogana in attesa di un’azione.', 'aliases' => ['fermo merce', 'dogana']],
            ],
            'relevant_entities' => ['RL-2024-0815', 'RL-2024-9015', 'RL-TRACK-8842', 'RL-TRACK-9015', 'DDT-2024-1182', 'SLA-W03-2024', 'HUB-MI-07', 'VeloxCorriere', 'OrbitaWMS'],
            'expected_facts' => ['stato e tracking della spedizione', 'hub e corriere coinvolti', 'consegna o blocco doganale', 'riferimento DDT', 'rispetto dello SLA e penali', 'azioni per una giacenza'],
            'preferred_source_types' => ['markdown', 'email'],
        ],
        'prometeo-antincendio' => [
            'company_context' => 'Prometeo Sicurezza Antincendio segue consulenza, conformità e manutenzione antincendio. Le richieste riguardano pratiche CPI e SCIA, sopralluoghi, estintori, scadenze, formazione, forniture e protocolli di emergenza.',
            'glossary' => [
                ['term' => 'CPI', 'meaning' => 'Pratica di prevenzione incendi con requisiti, documenti e verifiche tracciate.', 'aliases' => ['certificato prevenzione incendi', 'rinnovo CPI']],
                ['term' => 'sopralluogo', 'meaning' => 'Verifica tecnica svolta con il Modulo PA-12 e relative azioni.', 'aliases' => ['PA-12', 'verifica tecnica']],
                ['term' => 'scadenza', 'meaning' => 'Adempimento operativo gestito nello scadenzario condiviso.', 'aliases' => ['rinnovo', 'adempimento']],
                ['term' => 'Fenice-7', 'meaning' => 'Protocollo interno per allarmi, escalation e gestione dell’incidente.', 'aliases' => ['protocollo emergenza', 'escalation']],
            ],
            'relevant_entities' => ['CPI-ACME-2025', 'VERBALE-ESTINTORI-2024-10', 'ORD-2024-3471', 'Acme S.r.l.', 'Modulo PA-12', 'Protocollo Fenice-7', 'Corso Salamandra', 'ScadenzarioPRO'],
            'expected_facts' => ['stato e prossima data della pratica CPI', 'esito del sopralluogo', 'scadenze e azioni richieste', 'dotazioni e compatibilità dei ricambi', 'stato della fornitura', 'protocollo di escalation applicabile'],
            'preferred_source_types' => ['markdown', 'email'],
        ],
        'passolibero-calzature' => [
            'company_context' => 'PassoLibero Calzature è un e-commerce di calzature. Le richieste riguardano ordini fornitori e clienti, modelli, taglie, materiali, scorte, qualità, spedizioni, resi, rimborsi e programma ClubPasso.',
            'glossary' => [
                ['term' => 'ordine fornitore', 'meaning' => 'Acquisto di materiali o componenti collegato a fornitore, consegna e controllo qualità.', 'aliases' => ['ordine acquisti', 'FRN']],
                ['term' => 'RMA', 'meaning' => 'Pratica di reso del cliente con verifica, rimborso o sostituzione.', 'aliases' => ['reso', 'sostituzione']],
                ['term' => 'bolla in entrata', 'meaning' => 'Documento di ricevimento della merce controllata dal magazzino.', 'aliases' => ['BE', 'ricevimento merce']],
                ['term' => 'ClubPasso', 'meaning' => 'Linea e programma fedeltà correlati a modelli, scorte e comunicazioni cliente.', 'aliases' => ['Brezza', 'Aero', 'Eleganza']],
            ],
            'relevant_entities' => ['FRN-2024-241', 'PO-FRN-2024-241', 'BE-2024-0067', 'QC-BE-2024-0067', 'RMA-7801', 'GLS-PL-99845', 'ConceriaToscana', 'ClubPasso Brezza', 'ClubPasso Aero', '#CLB-5521'],
            'expected_facts' => ['stato e consegna dell’ordine fornitore', 'esito del controllo qualità', 'scorte e riassortimento per modello', 'stato della pratica RMA', 'tracking della sostituzione', 'regole per resi e rimborsi'],
            'preferred_source_types' => ['markdown', 'email'],
        ],
    ];

    public function run(): void
    {
        if (! LocalIntegrationFixtureEnvironment::enabled()) {
            throw new RuntimeException(
                'Set LOCAL_INTEGRATION_FIXTURES_ENABLED=true through the local fixture command before seeding local retrieval profiles.',
            );
        }

        $configuredCompanies = array_keys(self::PROFILES);
        $caseStudyCompanies = CaseStudyUsersSeeder::companyKeys();
        if (array_diff($caseStudyCompanies, $configuredCompanies) !== [] || array_diff($configuredCompanies, $caseStudyCompanies) !== []) {
            throw new RuntimeException('The local retrieval profiles must match the case-study tenant allowlist exactly.');
        }

        $tenants = app(TenantContext::class);
        $previousTenant = $tenants->current();

        try {
            foreach (self::PROFILES as $companyKey => $profile) {
                $tenants->set($companyKey);

                KbRetrievalProfile::query()->updateOrCreate(
                    ['tenant_id' => $companyKey, 'project_key' => $companyKey],
                    $profile,
                );
            }
        } finally {
            $tenants->set($previousTenant);
        }
    }
}
