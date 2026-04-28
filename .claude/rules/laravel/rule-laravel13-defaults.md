# Regola: Laravel 13 Defaults

Baseline consigliata per nuovi progetti o nuovi moduli:

- Laravel 13.x
- PHP 8.3+
- PHPUnit come default se il repo non ha gia' scelto Pest
- Pint per formatting
- Larastan/PHPStan per analisi statica

## Pattern applicativi preferiti

- `FormRequest -> DTO -> Service -> Resource/Response`
- `Job` sottile per asincrono
- `JsonResource` per API shaping

## Laravel 13

- valutare attributi PHP first-party dove migliorano chiarezza
- non mischiare pero' approcci attributes e config legacy in modo incoerente
- adottare le feature nuove solo se il team le mantiene davvero
