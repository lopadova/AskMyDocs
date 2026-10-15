# Creazione dei connettori Gmail e Microsoft Mail

I due pacchetti collegano mailbox via API e riusano il triage email AskMyDocs. L’analisi identifica standard, componenti email disponibili e adattamenti comuni; ogni chat riceve requisiti, confini di modifica e criteri di collaudo.

| Documento | Uso |
| --- | --- |
| [ANALYSIS.md](ANALYSIS.md) | Analisi pacchetti, riuso email e accoppiamenti host |
| [STANDARD.md](STANDARD.md) | Contratto condiviso, triage, isolamento, sync e test |
| [GMAIL.md](GMAIL.md) | Specifica Gmail e responsabilità unica dell’integrazione host |
| [MICROSOFT-MAIL.md](MICROSOFT-MAIL.md) | Specifica Graph Mail e responsabilità del pacchetto Microsoft |

Repository destinazione: `/Users/marco/packages/askmydocs-connector-gmail` e `/Users/marco/packages/askmydocs-connector-microsoft-mail`. Entrambi ricevono copia locale dello standard, dell’analisi e della specifica sotto `docs/specs/2026-10-14-email-api/`.

La chat Gmail modifica il proprio pacchetto e l’host in un worktree isolato; la chat Microsoft modifica soltanto il proprio pacchetto. Gli sviluppi package partono in parallelo. La verifica finale Microsoft attraverso il triage host richiede la consegna dell’adattatore comune assegnato alla chat Gmail.

Stato al 14 ottobre 2026: specifiche revisionate e committate; repository locali inizializzati; entrambe le chat di implementazione avviate e verificate attive. Tutti i push e le pubblicazioni remote restano all’utente. Account reali, OAuth/live e attivazione triage non sono dichiarati verificati dai test con fixture.

| Chat | Riferimento | Responsabilità |
| --- | --- | --- |
| Crea connettore Gmail API e integra triage | `01a13b4b-c544-7b00-ae8f-bfd96e7b29fa` | Pacchetto Gmail e unico adattatore host per le due API |
| Crea connettore Microsoft Mail API | `01a13b4c-67f3-7870-a5c0-89d9265aa183` | Pacchetto Microsoft e test del contratto comune |

Commit delle specifiche host: `b3e91bcbaa8ef484642d9b631da29f7bbbb01a07`. Bootstrap Gmail: `e07a75d9b42022ae418af75f436173d0c9045fe9`; bootstrap Microsoft Mail: `b868aafb14e91604ae1c903e45c6ac21a4c2eea7`. L’attivazione delle chat non certifica il completamento dei pacchetti.
