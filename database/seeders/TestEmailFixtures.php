<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Dati condivisi per il testing dell'ingest email via IMAP.
 *
 * Modello: UN SOLO account Gmail di test, con 6 ETICHETTE (label) — 2 per azienda.
 * Ogni casella logica (`mailbox_key`, es. `rotta-logistics-1`) corrisponde a una
 * label Gmail (campo `folder`), non a un account separato: Google limita la
 * creazione di account per numero di telefono, quindi si riusa un account unico e
 * si separano le aziende per label. Le 2 caselle di un'azienda confluiscono nello
 * stesso `project_key` (destinazione KB, coincide con CaseStudyUsersSeeder).
 *
 * `mail:seed-imap` crea la label e ci fa APPEND; `connector:imap:install` punta il
 * connettore su quella label (`folders.include=[<folder>]`). Su Gmail un messaggio
 * appeso a una label NON entra in INBOX (sta solo nella label + "Tutti i messaggi"),
 * e il connettore sincronizza SOLO la label inclusa → niente doppioni.
 *
 * Le E-MAIL di ogni casella (≥100, vario tipo + thread domanda/risposta) vivono in
 * `database/seeders/emails/<mailbox_key>.json` (generate via multi-agente,
 * versionate), caricate da {@see emailsForMailbox()}. Ogni e-mail porta i
 * "fatti-esca" dell'azienda — assenti nelle altre — per il test di isolamento.
 *
 * I PARAMETRI DI CONNESSIONE stanno nel fixture; in .env c'è SOLO la password
 * (segreto) dell'account condiviso: `CONNECTOR_TEST_GMAIL_PASSWORD` (App Password
 * Gmail). Host/port/encryption sovrascrivibili da env
 * (CONNECTOR_TEST_IMAP_HOST/PORT/ENCRYPTION) per puntare a un altro server IMAP.
 */
final class TestEmailFixtures
{
    /**
     * Header custom marcato su ogni email iniettata da `mail:seed-imap`, così
     * il `--purge` può ritrovare ed eliminare SOLO i messaggi di test (mai la
     * posta reale) e i re-run restano idempotenti a livello di casella.
     */
    public const SEED_HEADER = 'X-AskMyDocs-Seed';

    /** Account Gmail unico condiviso da tutte le caselle (separate per label). */
    public const ACCOUNT_EMAIL = 'rotta.test1.askmydocs@gmail.com';

    /** Env var con la App Password dell'account condiviso. */
    public const ACCOUNT_PASSWORD_ENV = 'CONNECTOR_TEST_GMAIL_PASSWORD';

    /**
     * Caselle IMAP di test — DUE per azienda — mappate su label dell'unico account.
     * La chiave dell'array è il `mailbox_key`; `folder` è la label Gmail.
     *
     * @var array<string, array{
     *     mailbox_key: string,
     *     project_key: string,
     *     tenant_id: string,
     *     company_name: string,
     *     email: string,
     *     folder: string,
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
            'tenant_id' => 'rotta-logistics',
            'company_name' => 'Rotta Sicura Logistics',
            'email' => self::ACCOUNT_EMAIL,
            'folder' => 'rotta-logistics-1',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => self::ACCOUNT_PASSWORD_ENV,
        ],
        'rotta-logistics-2' => [
            'mailbox_key' => 'rotta-logistics-2',
            'project_key' => 'rotta-logistics',
            'tenant_id' => 'rotta-logistics',
            'company_name' => 'Rotta Sicura Logistics',
            'email' => self::ACCOUNT_EMAIL,
            'folder' => 'rotta-logistics-2',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => self::ACCOUNT_PASSWORD_ENV,
        ],
        'prometeo-antincendio-1' => [
            'mailbox_key' => 'prometeo-antincendio-1',
            'project_key' => 'prometeo-antincendio',
            'tenant_id' => 'prometeo-antincendio',
            'company_name' => 'Prometeo Sicurezza Antincendio',
            'email' => self::ACCOUNT_EMAIL,
            'folder' => 'prometeo-antincendio-1',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => self::ACCOUNT_PASSWORD_ENV,
        ],
        'prometeo-antincendio-2' => [
            'mailbox_key' => 'prometeo-antincendio-2',
            'project_key' => 'prometeo-antincendio',
            'tenant_id' => 'prometeo-antincendio',
            'company_name' => 'Prometeo Sicurezza Antincendio',
            'email' => self::ACCOUNT_EMAIL,
            'folder' => 'prometeo-antincendio-2',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => self::ACCOUNT_PASSWORD_ENV,
        ],
        'passolibero-calzature-1' => [
            'mailbox_key' => 'passolibero-calzature-1',
            'project_key' => 'passolibero-calzature',
            'tenant_id' => 'passolibero-calzature',
            'company_name' => 'PassoLibero Calzature',
            'email' => self::ACCOUNT_EMAIL,
            'folder' => 'passolibero-calzature-1',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => self::ACCOUNT_PASSWORD_ENV,
        ],
        'passolibero-calzature-2' => [
            'mailbox_key' => 'passolibero-calzature-2',
            'project_key' => 'passolibero-calzature',
            'tenant_id' => 'passolibero-calzature',
            'company_name' => 'PassoLibero Calzature',
            'email' => self::ACCOUNT_EMAIL,
            'folder' => 'passolibero-calzature-2',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'password_env' => self::ACCOUNT_PASSWORD_ENV,
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
     * Il project_key è quello dell'azienda; `folders.include` è la label della
     * casella, così entrambe le label di un'azienda confluiscono nello stesso
     * progetto KB.
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
            // Solo la label di questa casella: su Gmail "Tutti i messaggi"/INBOX
            // duplicherebbero gli stessi messaggi.
            'folders' => ['include' => [$mailbox['folder']]],
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
     * @return array{mailbox_key: string, project_key: string, tenant_id: string, company_name: string, email: string, folder: string, host: string, port: int, encryption: string, validate_cert: bool, password_env: string}
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
     * Tenant di una casella (= l'azienda: un tenant per azienda).
     */
    public static function tenantFor(string $mailboxKey): string
    {
        return (string) self::mailbox($mailboxKey)['tenant_id'];
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

        // R14 — distingui il fallimento I/O dal JSON malformato: `is_file()` sopra
        // non garantisce la leggibilità (permessi, race/TOCTOU), e un errore di
        // lettura non è un "JSON non decodificabile". Diagnostica fuorviante = bug.
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Impossibile leggere il file fixture e-mail (errore I/O): {$path}");
        }

        $decoded = json_decode($raw, true);
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
