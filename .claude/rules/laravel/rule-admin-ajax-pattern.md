# Regola: Pattern AJAX per Admin

- Caricare dati pesanti on-demand, non sempre al page load.
- Per create/update/delete asincroni, dare feedback visivo e gestire errori.
- Preferire reload coerente della lista o refetch del dato rispetto a patch DOM fragili.
- Conferma esplicita per azioni distruttive.
- Contratto JSON semplice e stabile: `success`, `message`, `data`.
