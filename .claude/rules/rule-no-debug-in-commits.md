# Regola: No Debug Statements in Commit

Prima di chiudere il lavoro:

- rimuovi `dd()`, `dump()`, `var_dump()`, `console.log()`, breakpoint e stub di test
- elimina flag temporanei usati solo per ispezione locale
- non lasciare commenti tipo TODO non contestualizzati come surrogato del fix

Eccezione:

- logging strutturato utile, protetto e coerente con il sistema applicativo
