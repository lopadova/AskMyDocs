# Pattern Adoption Notes

Questo overlay Laravel e' stato rifinito dopo aver verificato il progetto sorgente.

## Pattern realmente molto usati nel sorgente

- Service: dominante
- DTO: dominante
- Request/FormRequest: dominante
- Job: molto usato
- Query object: molto usato
- ViewModel/Resource: usati in varie aree

## Pattern usati ma secondari

- Action: presenti, ma non al centro dell'architettura

## Testing osservato

- PHPUnit e' il framework realmente usato nel progetto sorgente
- Pest non risulta il default operativo del repo

## Baseline framework

- Laravel 13.x
- PHP 8.3+

## Conseguenza sulla generalizzazione

Per questo motivo il materiale in `laravel/` e':

- service-oriented
- DTO/FormRequest-friendly
- allineato a Laravel 13
- PHPUnit-first, Pest-optional
