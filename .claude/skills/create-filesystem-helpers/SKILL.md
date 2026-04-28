# Create Filesystem Helpers

Quando un progetto Laravel introduce un nuovo disco o flusso file:

- definire il disk in `config/filesystems.php`
- centralizzare naming path e regole di storage
- usare stream per file grandi
- evitare path string sparsi nel codice
- chiarire visibilita', retention e cleanup
