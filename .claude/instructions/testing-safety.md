# Testing Safety

Prima di eseguire test automatici o script di verifica:

- usa un database, bucket o ambiente dedicato ai test
- evita test distruttivi contro servizi reali
- rendi espliciti seed, fixture e dati di input
- separa le credenziali di test da quelle di sviluppo o produzione
- non riutilizzare code, cache o storage condivisi se il test puo' sporcarli

## Guardrail minimi

- Preferisci `.env.testing` o equivalente.
- Se il test scrive dati, verifica dove scrive prima di eseguirlo.
- Se i test fanno chiamate HTTP esterne, usa sandbox, mock o allowlist.
- Se i test possono inviare email, SMS o pagamenti, disattiva il trasporto reale.

## Prima di lanciare la suite

- conferma DB host, DB name e queue driver
- controlla che i job asincroni non puntino a worker condivisi
- verifica cleanup di file temporanei, bucket e artifact
