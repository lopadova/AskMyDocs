# Create CRUD Backend

Per un CRUD admin standard:

1. model e migration coerenti
2. request di create/update
3. controller resource o equivalente
4. view index/create/edit oppure API endpoints
5. test di create, update, delete e validazione

## Laravel 13

- usare `FormRequest` per create/update
- usare `JsonResource` se il CRUD espone API
- mantenere il controller sottile e delegare i casi non banali a service/action

## Regole

- evitare CRUD generator se produce codice che il team non mantiene
- proteggere delete e bulk actions
- gestire empty, validation error e success feedback
