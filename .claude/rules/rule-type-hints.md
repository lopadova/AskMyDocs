# Regola: Type Hints e Return Types

Per il codice PHP:

- tipizzare i parametri quando il contesto lo consente
- dichiarare il return type in modo esplicito
- documentare gli array omogenei con PHPDoc o generics del tool statico
- evitare `mixed` salvo reale impossibilita'
- preferire cast espliciti a coercioni implicite

## Esempio

```php
public function findById(int $id): ?User
{
    return User::find($id);
}
```
