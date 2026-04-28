# Regola: Early Return

Usare early return, continue e guard clauses per mantenere il codice piatto.

## Preferire

```php
if (!$user) {
    return null;
}

if (!$user->isActive()) {
    return null;
}

return $this->buildProfile($user);
```

## Evitare

```php
if ($user) {
    if ($user->isActive()) {
        return $this->buildProfile($user);
    }
}

return null;
```

Vale per PHP, JavaScript e codice applicativo in generale.
