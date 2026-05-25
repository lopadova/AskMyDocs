# Regola: Logging e Sicurezza

- Mai passare segreti (API key, token, password) nella query-string di un URL.
  Le query-string finiscono in access log, log dei proxy, header Referer e
  trace APM. Usare sempre un header dedicato (es. `x-goog-api-key` per Gemini,
  `Authorization: Bearer` per gli altri). Riferimento: H6 deep-review v8.0.3,
  `app/Ai/Providers/GeminiProvider.php`.
- Non loggare token, password, cookie, bearer, carta o PII non necessaria.
- Mascherare identificativi sensibili quando il dettaglio non serve.
- Ogni log deve avere utilita' operativa chiara.
- Se un errore va in log, aggiungi abbastanza contesto per fare debug senza rileggere tutto il codice.
- Definire retention e cleanup per log voluminosi.
