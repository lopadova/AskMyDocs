<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Dati condivisi per il testing dell'ingest email via IMAP.
 *
 * Modello: ogni AZIENDA (project_key) ha DUE caselle di posta (`MAILBOXES`),
 * entrambe mappate sullo stesso project_key — così, dopo l'ingest, la knowledge
 * base dell'azienda raccoglie le e-mail di ENTRAMBE le inbox. Il `mailbox_key`
 * (es. `rotta-logistics-1`, `rotta-logistics-2`) è l'unità con cui lavorano i
 * comandi; il `project_key` è la destinazione KB (coincide con CaseStudyUsersSeeder).
 *
 * Le E-MAIL di ogni casella (≥100, vario tipo + thread domanda/risposta) vivono
 * in `database/seeders/emails/<mailbox_key>.json` — generate via multi-agente,
 * versionate nel repo. Sono caricate da {@see emailsForMailbox()}. Ogni e-mail
 * porta i "fatti-esca" dell'azienda (codici/nomi/numeri) che NON devono comparire
 * nelle risposte di un'altra azienda: è il rilevatore del test di isolamento.
 *
 * I PARAMETRI DI CONNESSIONE (host/port/encryption/validate_cert + indirizzo)
 * di ogni casella stanno QUI nel fixture — "ognuna c'ha la sua". In .env restano
 * SOLO le PASSWORD (segreti, mai committate), UNA PER CASELLA:
 *
 *   CONNECTOR_TEST_ROTTA_1_PASSWORD / CONNECTOR_TEST_ROTTA_2_PASSWORD
 *   CONNECTOR_TEST_PROMETEO_1_PASSWORD / CONNECTOR_TEST_PROMETEO_2_PASSWORD
 *   CONNECTOR_TEST_PASSOLIBERO_1_PASSWORD / CONNECTOR_TEST_PASSOLIBERO_2_PASSWORD
 *
 * Le caselle vanno create su Gmail con App Password (non la password normale).
 * Gli host/port/encryption sono sovrascrivibili da env
 * (CONNECTOR_TEST_IMAP_HOST/PORT/ENCRYPTION) per un override globale al volo.
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
     * Caselle IMAP di test — DUE per azienda — con i parametri di connessione
     * inclusi (la password resta in env per non committare segreti). La chiave
     * dell'array è il `mailbox_key`.
     *
     * @var array<string, array{
     *     mailbox_key: string,
     *     project_key: string,
     *     company_name: string,
     *     email: string,
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     validate_cert: bool,
     *     password_env: string,
     * }>
     */
    public const MAILBOXES = [
        'rotta-logistics-1' => [
            'mailbox_key' => 'rotta-logistics-1',
            'project_key' => 'rotta-logistics',
            'company_name' => 'Rotta Sicura Logistics',
            'email' => 'rotta.test1.askmydocs@gmail.com',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => 'CONNECTOR_TEST_ROTTA_1_PASSWORD',
        ],
        'rotta-logistics-2' => [
            'mailbox_key' => 'rotta-logistics-2',
            'project_key' => 'rotta-logistics',
            'company_name' => 'Rotta Sicura Logistics',
            'email' => 'rotta.test2.askmydocs@gmail.com',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => 'CONNECTOR_TEST_ROTTA_2_PASSWORD',
        ],
        'prometeo-antincendio-1' => [
            'mailbox_key' => 'prometeo-antincendio-1',
            'project_key' => 'prometeo-antincendio',
            'company_name' => 'Prometeo Sicurezza Antincendio',
            'email' => 'prometeo.test1.askmydocs@gmail.com',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => 'CONNECTOR_TEST_PROMETEO_1_PASSWORD',
        ],
        'prometeo-antincendio-2' => [
            'mailbox_key' => 'prometeo-antincendio-2',
            'project_key' => 'prometeo-antincendio',
            'company_name' => 'Prometeo Sicurezza Antincendio',
            'email' => 'prometeo.test2.askmydocs@gmail.com',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => 'CONNECTOR_TEST_PROMETEO_2_PASSWORD',
        ],
        'passolibero-calzature-1' => [
            'mailbox_key' => 'passolibero-calzature-1',
            'project_key' => 'passolibero-calzature',
            'company_name' => 'PassoLibero Calzature',
            'email' => 'passolibero.test1.askmydocs@gmail.com',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => 'CONNECTOR_TEST_PASSOLIBERO_1_PASSWORD',
        ],
        'passolibero-calzature-2' => [
            'mailbox_key' => 'passolibero-calzature-2',
            'project_key' => 'passolibero-calzature',
            'company_name' => 'PassoLibero Calzature',
            'email' => 'passolibero.test2.askmydocs@gmail.com',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => 'CONNECTOR_TEST_PASSOLIBERO_2_PASSWORD',
        ],
    ];

    /** @var array<string, list<array{subject: string, from_name: string, from_email: string, body_text: string, date: string}>> */
    private static array $emailCache = [];

    /**
     * Restituisce la password di una casella leggendo la sua env var.
     * Lancia un'eccezione se la env var non è impostata.
     */
    public static function passwordFor(string $mailboxKey): string
    {
        $mailbox = self::mailbox($mailboxKey);

        $password = env($mailbox['password_env']);
        if ($password === null || $password === '') {
            throw new \RuntimeException(
                "Env var {$mailbox['password_env']} non impostata. "
                . "Configura l'App Password Gmail per {$mailbox['email']} nel file .env"
            );
        }

        return $password;
    }

    /**
     * Costruisce la config_json per una ConnectorInstallation IMAP da una casella.
     * Il project_key è quello dell'azienda proprietaria della casella, così
     * entrambe le caselle confluiscono nello stesso progetto KB.
     *
     * @return array<string, mixed>
     */
    public static function configJson(string $mailboxKey): array
    {
        $mailbox = self::mailbox($mailboxKey);

        return [
            'auth_mode' => 'basic',
            'connection' => [
                // Parametri di connessione della casella (dal fixture); env
                // opzionale per un override globale al volo.
                'host' => (string) env('CONNECTOR_TEST_IMAP_HOST', $mailbox['host'] ?? 'imap.gmail.com'),
                'port' => (int) env('CONNECTOR_TEST_IMAP_PORT', $mailbox['port'] ?? 993),
                'encryption' => (string) env('CONNECTOR_TEST_IMAP_ENCRYPTION', $mailbox['encryption'] ?? 'ssl'),
                'validate_cert' => (bool) ($mailbox['validate_cert'] ?? true),
                'username' => $mailbox['email'],
            ],
            'project_key' => $mailbox['project_key'],
            // Solo INBOX: su Gmail le altre cartelle virtuali ([Gmail]/Tutti i
            // messaggi, Inviati, ...) duplicherebbero gli stessi messaggi.
            'folders' => ['include' => ['INBOX']],
            'date_window_days' => (int) env('CONNECTOR_TEST_IMAP_DATE_WINDOW_DAYS', 365),
        ];
    }

    /**
     * Elenco dei mailbox_key di test (2 per azienda).
     *
     * @return list<string>
     */
    public static function mailboxKeys(): array
    {
        return array_keys(self::MAILBOXES);
    }

    /**
     * Elenco dei project_key (aziende) distinti.
     *
     * @return list<string>
     */
    public static function projectKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $m): string => $m['project_key'],
            self::MAILBOXES,
        )));
    }

    /**
     * Le caselle che appartengono a un'azienda (project_key).
     *
     * @return list<string>  i mailbox_key
     */
    public static function mailboxKeysForProject(string $projectKey): array
    {
        return array_values(array_keys(array_filter(
            self::MAILBOXES,
            static fn (array $m): bool => $m['project_key'] === $projectKey,
        )));
    }

    /**
     * Configurazione di una casella di test.
     *
     * @return array{mailbox_key: string, project_key: string, company_name: string, email: string, host: string, port: int, encryption: string, validate_cert: bool, password_env: string}
     */
    public static function mailbox(string $mailboxKey): array
    {
        $mailbox = self::MAILBOXES[$mailboxKey] ?? null;
        if ($mailbox === null) {
            throw new \InvalidArgumentException("Unknown mailbox_key: {$mailboxKey}");
        }

        return $mailbox;
    }

    /**
     * Percorso del file JSON con le e-mail di una casella. Ancorato alla cartella
     * del fixture (non a `database_path()`), così risolve correttamente sia in
     * app sia nei test (dove la base path è lo skeleton di Testbench).
     */
    public static function emailsPath(string $mailboxKey): string
    {
        return __DIR__.'/emails/'.$mailboxKey.'.json';
    }

    /**
     * Email fittizie di una casella (caricate da JSON, con cache di processo).
     *
     * @return list<array{subject: string, from_name: string, from_email: string, body_text: string, date: string}>
     */
    public static function emailsForMailbox(string $mailboxKey): array
    {
        if (array_key_exists($mailboxKey, self::$emailCache)) {
            return self::$emailCache[$mailboxKey];
        }

        $path = self::emailsPath($mailboxKey);
        if (! is_file($path)) {
            // R14 — una casella REGISTRATA senza il suo file fa inviare 0 e-mail
            // riportando comunque successo: fallisci forte (coerente con il ramo
            // JSON-corrotto sotto). Per chiavi non registrate resta [].
            if (array_key_exists($mailboxKey, self::MAILBOXES)) {
                throw new \RuntimeException(
                    "Fixture e-mail mancante per la casella registrata '{$mailboxKey}': {$path}",
                );
            }

            return self::$emailCache[$mailboxKey] = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            // R14 — fixture corrotta: meglio fallire forte che inviare 0 e-mail
            // in silenzio.
            throw new \RuntimeException("Fixture e-mail non valida (JSON non decodificabile): {$path}");
        }

        /** @var list<array{subject: string, from_name: string, from_email: string, body_text: string, date: string}> $emails */
        $emails = array_values($decoded);

        return self::$emailCache[$mailboxKey] = $emails;
    }
}
