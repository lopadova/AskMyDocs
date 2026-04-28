# Regola: Exception Handling

- Non usare eccezioni per flussi attesi di validazione utente.
- Tradurre errori infrastrutturali in messaggi applicativi chiari.
- Loggare il contesto utile senza esporre segreti o dati sensibili.
- Nei controller HTTP, restituire status code coerenti.
- Nei job, distinguere errori retryable da errori permanenti.
