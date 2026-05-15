---
name: create-filesystem-helpers
description: Introdurre un nuovo disco o flusso file in un progetto Laravel — definire il disk in config/filesystems.php, centralizzare naming/path, usare stream per file grandi, chiarire visibilità/retention/cleanup, evitare path string sparsi. Trigger quando l'utente chiede di aggiungere un nuovo disco (es. nuovo bucket S3 o storage path), di gestire upload/download di file grandi, o quando si introduce un helper di filesystem in app/Support/.
---

# Create Filesystem Helpers

Quando un progetto Laravel introduce un nuovo disco o flusso file:

- definire il disk in `config/filesystems.php`
- centralizzare naming path e regole di storage
- usare stream per file grandi
- evitare path string sparsi nel codice
- chiarire visibilita', retention e cleanup
