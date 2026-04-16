# Frontend React

## Stack suggerito

- React + TypeScript
- Vite
- TanStack Query
- Zustand o Redux Toolkit leggero
- streaming via fetch + ReadableStream o SSE
- Markdown renderer per citazioni / chunk highlight

## Schermate minime

1. Login
2. Chat KB
3. Ricerca documenti
4. Viewer documento con heading / chunk
5. Timeline documenti recenti
6. Admin upload / reindex

## API minime

- `POST /api/kb/chat`
- `GET /api/kb/documents`
- `GET /api/kb/documents/{id}`
- `GET /api/kb/search`
- `POST /api/kb/upload`
- `POST /api/kb/reindex`

## UX minima

- filtro progetto
- citazioni cliccabili
- pannello laterale sources
- badge per tipo documento
- risposta grounded
- fallback "contesto insufficiente"
