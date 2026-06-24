<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Dati condivisi per il testing dell'ingest email via IMAP.
 *
 * Definisce:
 *   1. Le credenziali IMAP di ogni casella di posta di test (una per azienda).
 *   2. Le email fittizie da inviare (APPEND) in ogni casella.
 *
 * I project_key coincidono con quelli di CaseStudyUsersSeeder così,
 * dopo l'ingest, le email sono isolabili per progetto esattamente
 * come i documenti markdown.
 *
 * CONFIGURARE le credenziali reali nel .env prima di eseguire il seeder:
 *
 *   CONNECTOR_TEST_IMAP_HOST=imap.gmail.com
 *   CONNECTOR_TEST_IMAP_PORT=993
 *   CONNECTOR_TEST_IMAP_ENCRYPTION=ssl
 *   CONNECTOR_TEST_ROTTA_PASSWORD=xxxx
 *   CONNECTOR_TEST_PROMETEO_PASSWORD=xxxx
 *   CONNECTOR_TEST_PASSOLIBERO_PASSWORD=xxxx
 *
 * Le email devono essere create su Gmail con App Password (non password normale).
 */
final class TestEmailFixtures
{
    /**
     * Header custom marcato su ogni email iniettata da `mail:seed-imap`, così
     * il `--purge` può ritrovare ed eliminare SOLO i messaggi di test (mai la
     * posta reale) e i re-run restano idempotenti a livello di casella.
     */
    public const SEED_HEADER = 'X-AskMyDocs-Seed';

    /**
     * Configurazione IMAP per ogni azienda di test.
     *
     * @var array<string, array{
     *     project_key: string,
     *     company_name: string,
     *     email: string,
     *     password_env: string,
     * }>
     */
    public const ACCOUNTS = [
        'rotta-logistics' => [
            'project_key' => 'rotta-logistics',
            'company_name' => 'Rotta Sicura Logistics',
            'email' => 'rotta.test.askmydocs@gmail.com',
            'password_env' => 'CONNECTOR_TEST_ROTTA_PASSWORD',
        ],
        'prometeo-antincendio' => [
            'project_key' => 'prometeo-antincendio',
            'company_name' => 'Prometeo Sicurezza Antincendio',
            'email' => 'prometeo.test.askmydocs@gmail.com',
            'password_env' => 'CONNECTOR_TEST_PROMETEO_PASSWORD',
        ],
        'passolibero-calzature' => [
            'project_key' => 'passolibero-calzature',
            'company_name' => 'PassoLibero Calzature',
            'email' => 'passolibero.test.askmydocs@gmail.com',
            'password_env' => 'CONNECTOR_TEST_PASSOLIBERO_PASSWORD',
        ],
    ];

    /**
     * Email fittizie da iniettare in ogni casella.
     *
     * Ogni email ha un "fatto-esca" unico (codice, nome, numero) che
     * NON deve comparire nelle risposte delle altre aziende — è il
     * rilevatore di contaminazione del test di isolamento.
     *
     * @var array<string, list<array{
     *     subject: string,
     *     from_name: string,
     *     from_email: string,
     *     body_text: string,
     *     date: string,
     * }>>
     */
    public const EMAILS = [
        'rotta-logistics' => [
            [
                'subject' => 'Conferma spedizione RL-2024-0815 — Consegna Lampo 24h',
                'from_name' => 'Sistema OrbitaWMS',
                'from_email' => 'noreply@orbitawms.example.com',
                'date' => '2024-03-15 09:30:00',
                'body_text' => "Spedizione RL-2024-0815 registrata nel sistema.\n\n"
                    . "Dettaglio:\n"
                    . "- Mittente: Magazzino HUB-MI-07 (Milano)\n"
                    . "- Destinatario: HUB-NA-03 (Napoli)\n"
                    . "- Corriere: VeloxCorriere\n"
                    . "- Servizio: Consegna Lampo 24h\n"
                    . "- Cut-off ordini: 17:30\n"
                    . "- SLA: 98% entro 24h, penale 2%/giorno\n"
                    . "- Tracking: RL-TRACK-8842\n\n"
                    . "Il pacco è stato preso in carico dal corriere VeloxCorriere.\n"
                    . "Per assistenza chiamare il verde 800-ROTTA1.",
            ],
            [
                'subject' => 'Avviso ritardo HUB-RM-05 — TurboPony',
                'from_name' => 'Monitoraggio TurboPony',
                'from_email' => 'alert@turbopony.example.com',
                'date' => '2024-03-16 14:15:00',
                'body_text' => "Si segnala un ritardo sulla tratta HUB-RM-05 (Roma).\n\n"
                    . "Il corriere TurboPony ha comunicato un ritardo stimato di 3 ore\n"
                    . "causa traffico sulla A1. La spedizione RL-2024-0820 risulta\n"
                    . "in transito con consegna prevista entro le 22:00.\n\n"
                    . "SLA attuale: ancora nei limiti del 98%/24h.\n"
                    . "Penale applicabile: non ancora maturata.",
            ],
            [
                'subject' => 'Report settimanale volumi — Settimana 11',
                'from_name' => 'Business Intelligence',
                'from_email' => 'bi@rotta-logistics.example.com',
                'date' => '2024-03-18 08:00:00',
                'body_text' => "Report volumi settimanali — Rotta Sicura Logistics.\n\n"
                    . "Settimana 11 (11-17 Marzo 2024):\n"
                    . "- Spedizioni totali: 1.847\n"
                    . "- Consegne Lampo 24h: 1.203 (65%)\n"
                    . "- Corrieri attivi: VeloxCorriere (62%), TurboPony (38%)\n"
                    . "- Hub più trafficato: HUB-MI-07 (712 spedizioni)\n"
                    . "- SLA medio: 97,8%\n"
                    . "- Penali maturate: € 1.240\n\n"
                    . "Note: il sistema OrbitaWMS ha processato 3.214 ordini\n"
                    . "con un cut-off medio rispettato alle 17:28.",
            ],
        ],
        'prometeo-antincendio' => [
            [
                'subject' => 'Rinnovo Certificazione CPI — Protocollo Fenice-7',
                'from_name' => 'Ufficio Tecnico',
                'from_email' => 'tecnico@prometeo-antincendio.example.com',
                'date' => '2024-03-14 10:00:00',
                'body_text' => "Comunicazione importante — Rinnovo CPI.\n\n"
                    . "Il Certificato di Prevenzione Incendi (CPI) dell'edificio\n"
                    . "in Via dei Vigili 12, Milano, scade il 15 Marzo 2029.\n\n"
                    . "Il rinnovo ha validità di 5 anni (scadenza: 15/03/2029).\n"
                    . "Protocollo assegnato: Protocollo Fenice-7.\n\n"
                    . "Estintori verificati: 24 unità (estintori a polvere).\n"
                    . "Rilevatori di fumo: 48 (tutti funzionanti).\n"
                    . "Porta tagliafuoco B-MI-07: verificata e conforme.\n\n"
                    . "Prossima ispezione VV.F.: 15 Settembre 2024.",
            ],
            [
                'subject' => 'Manutenzione impianto sprinkler — Edificio B-MI-07',
                'from_name' => 'Servizio Manutenzione',
                'from_email' => 'manutenzione@prometeo-antincendio.example.com',
                'date' => '2024-03-17 11:30:00',
                'body_text' => "Rapporto di manutenzione impianto sprinkler.\n\n"
                    . "Edificio: B-MI-07 (Milano, Via dei Vigili 12)\n"
                    . "Data intervento: 17 Marzo 2024\n"
                    . "Tecnico: Ing. Marco Brando\n\n"
                    . "Verifica effettuata:\n"
                    . "- Umidità rilevatori: OK (tutti sotto soglia)\n"
                    . "- Pressione rete sprinkler: 6,2 bar (nominale 6,0)\n"
                    . "- Test attivazione zona 3: superato\n"
                    . "- Sostituzione 2 ugelle usurate\n\n"
                    . "Protocollo Fenice-7 aggiornato con esito positivo.\n"
                    . "Il CPI rimane valido fino al 15/03/2029.",
            ],
            [
                'subject' => 'Corso formazione antincendio — Staff nuovo turno',
                'from_name' => 'Formazione Prometeo',
                'from_email' => 'formazione@prometeo-antincendio.example.com',
                'date' => '2024-03-19 15:00:00',
                'body_text' => "Convocazione corso formazione antincendio.\n\n"
                    . "Data: 25 Marzo 2024, ore 9:00\n"
                    . "Luogo: Sala riunioni Edificio B-MI-07\n"
                    . "Durata: 4 ore\n\n"
                    . "Programma:\n"
                    . "1. Uso estintori a polvere (pratica)\n"
                    . "2. Evacuazione tramite porta tagliafuoco\n"
                    . "3. Lettura planimetria emergenza\n"
                    . "4. Procedura Protocollo Fenice-7\n\n"
                    . "Il corso è obbligatorio per il mantenimento del CPI.\n"
                    . "Rilevatori di fumo: dimostrazione pratica di test.",
            ],
        ],
        'passolibero-calzature' => [
            [
                'subject' => 'Ordine #CLB-5521 — Conferma spedizione scarpe',
                'from_name' => 'E-commerce PassoLibero',
                'from_email' => 'ordini@passolibero-calzature.example.com',
                'date' => '2024-03-15 16:45:00',
                'body_text' => "Conferma ordine #CLB-5521.\n\n"
                    . "Gentile cliente,\n"
                    . "il tuo ordine è stato spedito.\n\n"
                    . "Articoli:\n"
                    . "- Scarpa running ClubPasso modello Aero (taglia 42): 1 paio\n"
                    . "- Scarpa elegante ClubPasso modello Eleganza (taglia 40): 1 paio\n\n"
                    . "Totale: € 189,00 (spedizione gratuita con ClubPasso Premium)\n"
                    . "Corriere: GLS\n"
                    . "Tracking: GLS-PL-99812\n"
                    . "Consegna prevista: 18-19 Marzo 2024\n\n"
                    . "Reso gratuito entro 30 giorni.",
            ],
            [
                'subject' => 'Rientro merce — Collezione Primavera Estate 2024',
                'from_name' => 'Magazzino Centrale',
                'from_email' => 'magazzino@passolibero-calzature.example.com',
                'date' => '2024-03-17 09:00:00',
                'body_text' => "Comunicazione rientro merce collezione PE2024.\n\n"
                    . "Arrivati in magazzino:\n"
                    . "- 500 paia scarpe ClubPasso modello Aero (colori: bianco, nero, blu)\n"
                    . "- 300 paia scarpe ClubPasso modello Eleganza (colori: nero, marrone)\n"
                    . "- 200 paia sandali ClubPasso modello Brezza\n\n"
                    . "Tutti i prodotti sono già caricati a catalogo sul sito.\n"
                    . "Prezzo di listino: da € 79,00 a € 149,00.\n"
                    . "Soci ClubPasso Premium: sconto 15% fino al 31 Marzo.\n\n"
                    . "Ordine #CLB-5521 incluso nel lotto.",
            ],
            [
                'subject' => 'Recensione cliente 5 stelle — ClubPasso Aero',
                'from_name' => 'Sistema Recensioni',
                'from_email' => 'reviews@passolibero-calzature.example.com',
                'date' => '2024-03-18 13:20:00',
                'body_text' => "Nuova recensione verificata — 5 stelle.\n\n"
                    . "Prodotto: ClubPasso modello Aero (taglia 42)\n"
                    . "Ordine: #CLB-5521\n"
                    . "Cliente: Giulia R.\n\n"
                    . "\"Scarpe comodissime, le uso per correre 3 volte a settimana.\n"
                    . "Ammortizzazione perfetta e peso leggero. Il modello ClubPasso\n"
                    . "Aero è diventato il mio preferito. Consigliatissime!\"\n\n"
                    . "Valutazione: 5/5 stelle.\n"
                    . "Pubblicata sul sito e visibile nella scheda prodotto.",
            ],
        ],
    ];

    /**
     * Restituisce la password per un'azienda leggendo la env var.
     * Lancia un'eccezione se la env var non è impostata.
     */
    public static function passwordFor(string $projectKey): string
    {
        $account = self::ACCOUNTS[$projectKey] ?? null;
        if ($account === null) {
            throw new \InvalidArgumentException("Unknown project_key: {$projectKey}");
        }

        $password = env($account['password_env']);
        if ($password === null || $password === '') {
            throw new \RuntimeException(
                "Env var {$account['password_env']} non impostata. "
                . "Configura l'App Password Gmail per {$account['email']} nel file .env"
            );
        }

        return $password;
    }

    /**
     * Costruisce la config_json per una ConnectorInstallation IMAP.
     *
     * @return array<string, mixed>
     */
    public static function configJson(string $projectKey): array
    {
        $account = self::ACCOUNTS[$projectKey];

        return [
            'auth_mode' => 'basic',
            'connection' => [
                'host' => (string) env('CONNECTOR_TEST_IMAP_HOST', 'imap.gmail.com'),
                'port' => (int) env('CONNECTOR_TEST_IMAP_PORT', 993),
                'encryption' => (string) env('CONNECTOR_TEST_IMAP_ENCRYPTION', 'ssl'),
                'validate_cert' => true,
                'username' => $account['email'],
            ],
            'project_key' => $projectKey,
            // Solo INBOX: su Gmail le altre cartelle virtuali ([Gmail]/Tutti i
            // messaggi, Inviati, ...) duplicherebbero gli stessi messaggi.
            'folders' => ['include' => ['INBOX']],
            'date_window_days' => (int) env('CONNECTOR_TEST_IMAP_DATE_WINDOW_DAYS', 365),
        ];
    }

    /**
     * Elenco dei project_key di test definiti nel fixture.
     *
     * @return list<string>
     */
    public static function projectKeys(): array
    {
        return array_keys(self::ACCOUNTS);
    }

    /**
     * Account IMAP di un'azienda di test.
     *
     * @return array{project_key: string, company_name: string, email: string, password_env: string}
     */
    public static function account(string $projectKey): array
    {
        $account = self::ACCOUNTS[$projectKey] ?? null;
        if ($account === null) {
            throw new \InvalidArgumentException("Unknown project_key: {$projectKey}");
        }

        return $account;
    }

    /**
     * Email fittizie di un'azienda.
     *
     * @return list<array{subject: string, from_name: string, from_email: string, body_text: string, date: string}>
     */
    public static function emailsFor(string $projectKey): array
    {
        return self::EMAILS[$projectKey] ?? [];
    }
}
