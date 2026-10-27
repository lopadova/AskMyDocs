# Connessioni ai server MCP

Da **Sistema → Strumenti MCP → Collega server** si crea una connessione
condivisa per l’organizzazione selezionata. Questa azione usa il connettore
MCP e il suo archivio credenziali, tramite `/api/admin/connectors/mcp`.
Le registrazioni del vecchio catalogo restano nella sezione espandibile
**Server manuali e locali** e non vengono duplicate fra i server remoti.

## OAuth

1. Inserire nome e URL MCP completo del servizio.
2. Mantenere **OAuth**, preselezionato, oppure scegliere esplicitamente
   Bearer token o accesso pubblico.
3. Scegliere eventualmente il progetto per l’indicizzazione delle risorse.
4. Usare **Continua con OAuth** e completare il consenso sul servizio.
5. Il callback torna alla stessa pagina Strumenti MCP. Esaminare strumenti,
   risorse e relative abilitazioni prima di usarli nell’agente.

Il backend esistente gestisce discovery dei metadati, PKCE S256, resource
indicator, controllo issuer, state monouso, cifratura e rinnovo delle
credenziali. La UI riceve l’URL di autorizzazione e l’esito del callback;
access token e refresh token non entrano nella lista delle connessioni o
nell’URL di ritorno. Un cambio di organizzazione chiude il form e impedisce
che una risposta OAuth tardiva avvii un reindirizzamento per l’organizzazione
precedente.

Il servizio deve supportare l’autorizzazione MCP. La libreria usa i meccanismi
di registrazione supportati dal provider. Per provider con un client già
registrato, mantenere la configurazione dell’installazione in
`MCP_CONNECTOR_OAUTH_CLIENTS_JSON`; i relativi segreti non appartengono al
form o al registro delle chiamate. Il callback configurato è
`/api/connectors/mcp/oauth/callback`.

Se il consenso viene annullato, la pagina mostra l’annullamento senza
salvare nuove credenziali. Se il consenso riesce ma il catalogo non viene
recuperato, è possibile riprovare il rilevamento. Gli errori mantengono i
dati del form e offrono la diagnostica correlata del backend.

## Configurazione e compatibilità

`GET /api/admin/mcp-servers/capabilities` espone solo disponibilità del
connettore, disponibilità OAuth e modalità runtime dell’organizzazione,
dietro lo stesso permesso `manageMcpTools` del registro amministrativo.

- `MCP_CONNECTOR_ENABLED` abilita le connessioni gestite.
- `MCP_CONNECTOR_OAUTH_ENABLED` abilita il flusso OAuth.
- Il runtime deve essere `active` in **Configurazione** per utilizzare gli
  strumenti delle connessioni nell’agente. La pagina distingue questa
  modalità dalla semplice possibilità di configurare una connessione.
- Le registrazioni manuali mantengono il percorso `/api/admin/mcp-servers`.
  Nel runtime nuovo la loro importazione dipende da
  `MCP_CONNECTOR_LEGACY_ADAPTER_ENABLED`.

La registrazione manuale offre stdio, HTTP e SSE, impostazioni cifrate
facoltative e selezione degli strumenti. I comandi stdio sono eseguiti sul
server AskMyDocs. Per i server remoti con OAuth usare **Collega server**.
I controlli di autenticazione, appartenenza all’organizzazione e permessi
degli strumenti rimangono quelli del connettore e del runtime.

Riferimento: [autorizzazione MCP](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).
