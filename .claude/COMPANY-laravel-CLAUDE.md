# Laravel Stack Overlay

Queste istruzioni si applicano a progetti Laravel o Laravel-like.

Baseline consigliata di questo pacchetto:

- Laravel 13.x
- PHP 8.3+

## Principi

- Mantieni controller sottili.
- Sposta logica di business in service/action object chiari.
- Usa Form Request o validator dedicati per validazione HTTP.
- Evita query pesanti nei controller e nelle view.
- Se l'operazione e' lunga o asincrona, valuta Job + queue.
- Tratta migrations, seed e config come parte del design, non post-produzione.

## Pattern consigliati

- Pattern primario: `Request -> DTO -> Service/Action -> Response`
- Per i flussi HTTP non banali, preferire `FormRequest` + DTO invece di leggere `$request->input()` ovunque
- Per logica lunga o con side effects multipli, preferire `Service`
- Per operazioni molto piccole e isolate, `Action` va bene ma non deve proliferare senza criterio
- Per query complesse o molto riusate, estrarre query object o repository leggibili
- Per task asincroni, tenere il `Job` sottile e spostare la logica nel service

## Testing

- Default consigliato: PHPUnit o il framework gia' dominante nel repo
- Pest e' opzionale: usalo solo se il repo lo adotta gia' o se stai avviando un nuovo progetto con quella scelta
- Non imporre Pest in codebase gia' strutturati su PHPUnit

## Layout architetturale preferito

- `app/Domain/` o moduli equivalenti per bounded context
- DTO/Action/Service espliciti per workflow rilevanti
- Request e Resource come contratto HTTP
- test separati in unit, feature e browser/E2E
