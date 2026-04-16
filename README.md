# AskMyDocs - Enterprise AI Knowledge Base — Laravel

<p align="center">
  <img src="resources\cover-AskMyDocs.png" alt="AskMyDocs" width="100%" />
</p>

<p align="center">
  <a href="#installazione"><img src="https://img.shields.io/badge/Laravel-11+-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Claude-Compatible-cc785c?style=flat-square&logo=anthropic&logoColor=white" alt="Claude Compatible"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/OpenAI-Compatible-412991?style=flat-square&logo=openai&logoColor=white" alt="OpenAI Compatible"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Gemini-Compatible-4285F4?style=flat-square&logo=google&logoColor=white" alt="Gemini Compatible"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/OpenRouter-Multi--Model-6366f1?style=flat-square" alt="OpenRouter"></a>
  <a href="#mcp-server"><img src="https://img.shields.io/badge/MCP-Server-0ea5e9?style=flat-square" alt="MCP Server"></a>
  <a href="#requisiti"><img src="https://img.shields.io/badge/PostgreSQL-pgvector-336791?style=flat-square&logo=postgresql&logoColor=white" alt="PostgreSQL + pgvector"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="MIT License"></a>
  <a href="#requisiti"><img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+"></a>
</p>

<p align="center">
  <strong>Ask your docs. Get grounded answers. See the sources.</strong>
</p>

---

An enterprise-grade RAG system built on Laravel and PostgreSQL. Ingest your documents, ask questions in natural language, and get AI-powered answers grounded in your actual knowledge base — with full source citations, visual artifacts, and a ChatGPT-like interface.

### Key Features

| | Feature | Description |
|---|---|---|
| **Multi-Provider AI** | Swap between OpenAI, Anthropic Claude, Google Gemini, or OpenRouter with a single ENV change |
| **Hybrid Search** | Semantic vector search (pgvector) + full-text keyword search fused via Reciprocal Rank Fusion |
| **Smart Reranking** | Over-retrieval + keyword/heading scoring to surface the most relevant chunks |
| **Embedding Cache** | DB-backed cache eliminates redundant API calls on re-ingestion and repeated queries |
| **Citations** | Every answer shows exactly which documents and sections were used — verify at the source |
| **Visual Artifacts** | AI generates charts (Chart.js), enhanced tables, and action buttons (copy, download) when data warrants it |
| **Feedback Learning** | Thumbs up/down on answers; positive examples are injected as few-shot context to improve future responses |
| **Chat History** | Full conversation persistence with sidebar, rename, delete, auto-generated titles — like ChatGPT |
| **Speech-to-Text** | Browser-native microphone input via Web Speech API — zero external services |
| **Chat Logging** | Structured logging (DB, BigQuery, CloudWatch) of every interaction with token counts, latency, client info |
| **MCP Server** | 5 read-only tools that expose the KB to Claude Desktop, Claude Code, and other MCP-compatible agents |
| **Auth** | Laravel session auth with login, logout, password reset — no registration (admin-created users) |

---

## Table of Contents

- [Architecture](#architettura)
- [Requirements](#requisiti)
- [Installation](#installazione)
- [Configuration](#configurazione)
  - [Database](#database)
  - [AI Provider](#ai-provider)
  - [Chat Logging](#chat-logging)
  - [Knowledge Base](#knowledge-base)
- [Authentication](#autenticazione)
- [Chat Interface](#interfaccia-chat)
  - [Chat History](#chat-history)
  - [Speech-to-Text](#speech-to-text)
- [Smart Visualizations & Artifacts](#visualizzazioni-smart-e-artefatti)
- [Feedback & Auto-Learning](#feedback-e-auto-learning)
- [Reranking](#reranking)
- [Embedding Cache](#embedding-cache)
- [Hybrid Search](#hybrid-search)
- [Citations](#citations)
- [API](#api)
- [MCP Server](#mcp-server)
- [Document Ingestion](#document-ingestion)
- [Extending](#estensione)
- [License](#license)
- [Contributing](#contributing)
- [Changelog](#changelog)

---

## Architettura

```
┌─────────────────────────────────────────────────────────────┐
│                        Client                                │
│                  POST /api/kb/chat                           │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────┐
│  KbChatController (orchestratore)                            │
│                                                              │
│  1. Valida input                                             │
│  2. KbSearchService → genera embedding query → pgvector      │
│  3. Compone system prompt con chunk RAG                      │
│  4. AiManager::chat() → provider configurato                 │
│  5. ChatLogManager::log() → persiste interazione             │
│  6. Risponde al client                                       │
└──────────────────────────────────────────────────────────────┘
                       │
          ┌────────────┼────────────────┐
          ▼            ▼                ▼
   ┌────────────┐ ┌──────────┐ ┌──────────────┐
   │ AI Provider│ │ pgvector │ │  Chat Log    │
   │ (OpenAI,   │ │ search   │ │  (DB, BQ,    │
   │  Anthropic,│ │          │ │   CloudWatch)│
   │  Gemini,   │ │          │ │              │
   │  OpenRouter│ │          │ │              │
   └────────────┘ └──────────┘ └──────────────┘
```

### Componenti principali

| Componente | Path | Descrizione |
|---|---|---|
| **AiManager** | `app/Ai/AiManager.php` | Manager multi-provider AI (chat + embeddings) |
| **Provider** | `app/Ai/Providers/*.php` | Implementazioni: OpenAI, Anthropic, Gemini, OpenRouter |
| **KbSearchService** | `app/Services/Kb/KbSearchService.php` | Ricerca semantica via pgvector |
| **DocumentIngestor** | `app/Services/Kb/DocumentIngestor.php` | Pipeline di ingestion documenti |
| **ChatLogManager** | `app/Services/ChatLog/ChatLogManager.php` | Logging strutturato delle conversazioni |
| **MCP Server** | `app/Mcp/Servers/KnowledgeBaseServer.php` | Server MCP read-only per Claude e altri AI agent |

---

## Requisiti

- **PHP** >= 8.2
- **Laravel** >= 11.x
- **PostgreSQL** >= 15 con estensione **pgvector**
- **Composer** >= 2.x

### Estensione pgvector

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

La migration `create_knowledge_chunks_table` chiama `Schema::ensureVectorExtensionExists()` automaticamente.

---

## Installazione

```bash
# 1. Clona il repository e posizionati nella directory Laravel
cd 04_laravel

# 2. Installa le dipendenze PHP
composer install

# 3. Copia il file di configurazione ambiente
cp .env.example .env

# 4. Genera la chiave applicazione
php artisan key:generate

# 5. Configura il database PostgreSQL in .env (vedi sezione Database)

# 6. Esegui le migration
php artisan migrate

# 7. (Opzionale) Crea un utente per l'autenticazione API
php artisan tinker
# > User::factory()->create(['email' => 'admin@example.com']);

# 8. Genera un token Sanctum per le chiamate API
php artisan tinker
# > User::first()->createToken('api')->plainTextToken;

# 9. Avvia il server di sviluppo
php artisan serve
```

---

## Configurazione

### Database

In `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=enterprise_kb
DB_USERNAME=postgres
DB_PASSWORD=secret
```

### AI Provider

Il sistema supporta 4 provider AI. Ogni provider viene chiamato via HTTP diretto (nessun SDK esterno, pieno controllo).

File di configurazione: `config/ai.php`

#### Provider di default

```env
# Provider per le chat completion
# Valori: openai, anthropic, gemini, openrouter
AI_PROVIDER=openai

# Provider per la generazione embeddings (opzionale).
# Se non impostato, usa lo stesso di AI_PROVIDER.
# NOTA: Anthropic e OpenRouter NON supportano embeddings.
# Per usarli per la chat, configurare un provider embeddings separato.
AI_EMBEDDINGS_PROVIDER=openai
```

#### OpenAI

```env
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_CHAT_MODEL=gpt-4o
OPENAI_EMBEDDINGS_MODEL=text-embedding-3-small
OPENAI_TEMPERATURE=0.2
OPENAI_MAX_TOKENS=4096
OPENAI_TIMEOUT=120
```

#### Anthropic (Claude)

Anthropic non offre un endpoint embeddings, quindi serve un provider embeddings dedicato.

```env
AI_PROVIDER=anthropic
AI_EMBEDDINGS_PROVIDER=openai

ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_CHAT_MODEL=claude-sonnet-4-20250514
ANTHROPIC_MAX_TOKENS=4096
ANTHROPIC_TEMPERATURE=0.2

# Key per embeddings
OPENAI_API_KEY=sk-...
```

#### Google Gemini

Gemini supporta sia chat che embeddings.

```env
AI_PROVIDER=gemini
GEMINI_API_KEY=AIza...
GEMINI_CHAT_MODEL=gemini-2.0-flash
GEMINI_EMBEDDINGS_MODEL=text-embedding-004
GEMINI_TEMPERATURE=0.2
GEMINI_MAX_TOKENS=4096
```

#### OpenRouter (multi-modello)

OpenRouter funziona come gateway verso centinaia di modelli. Non supporta embeddings.

```env
AI_PROVIDER=openrouter
AI_EMBEDDINGS_PROVIDER=openai

OPENROUTER_API_KEY=sk-or-...
OPENROUTER_CHAT_MODEL=anthropic/claude-sonnet-4-20250514
OPENROUTER_APP_NAME="Enterprise KB"
OPENROUTER_SITE_URL=https://kb.example.com

# Key per embeddings
OPENAI_API_KEY=sk-...
```

#### Nota importante sulle dimensioni embedding

Se cambi provider di embeddings (es. da OpenAI 1536-dim a Gemini 768-dim):
1. Aggiorna `KB_EMBEDDINGS_DIMENSIONS` in `.env`
2. Crea una nuova migration per aggiornare la dimensione del campo `vector`
3. Re-indicizza tutti i documenti esistenti

### Chat Logging

Il logging delle conversazioni e' disabilitato per default. Abilitalo per tracciare tutte le interazioni Q&A.

File di configurazione: `config/chat-log.php`

```env
# Abilita il logging
CHAT_LOG_ENABLED=false

# Driver di storage (supportato: database)
CHAT_LOG_DRIVER=database

# Connection DB dedicata per i log (opzionale)
CHAT_LOG_DB_CONNECTION=
```

#### Dati registrati per ogni interazione

| Campo | Descrizione |
|---|---|
| `session_id` | UUID della sessione (header `X-Session-Id` o auto-generato) |
| `user_id` | ID utente autenticato (nullable) |
| `question` | Domanda dell'utente |
| `answer` | Risposta generata dall'agente |
| `project_key` | Chiave progetto usata come filtro RAG |
| `ai_provider` | Provider AI utilizzato (openai, anthropic, gemini, openrouter) |
| `ai_model` | Modello specifico (gpt-4o, claude-sonnet-4-20250514, ...) |
| `chunks_count` | Numero di chunk di contesto recuperati |
| `sources` | Path dei documenti sorgente che hanno contribuito contesto |
| `prompt_tokens` | Token consumati nel prompt |
| `completion_tokens` | Token consumati nella risposta |
| `total_tokens` | Token totali consumati |
| `latency_ms` | Latenza end-to-end in millisecondi |
| `client_ip` | IP del client |
| `user_agent` | User-Agent del client |
| `extra` | JSON estendibile per metadati custom |

Il logging e' protetto da try/catch: un errore del driver non interrompe mai la risposta al client.

### Knowledge Base

File di configurazione: `config/kb.php`

```env
# Dimensioni vettore embedding (match col modello)
KB_EMBEDDINGS_DIMENSIONS=1536

# Soglia minima di similarita' coseno (0.0 - 1.0)
KB_MIN_SIMILARITY=0.30

# Numero massimo di chunk per query
KB_DEFAULT_LIMIT=8

# Parametri chunking
KB_CHUNK_TARGET_TOKENS=512
KB_CHUNK_HARD_CAP_TOKENS=1024
KB_CHUNK_OVERLAP_TOKENS=64

# Root directory documenti markdown
KB_MARKDOWN_ROOT=/path/to/docs
```

---

## Autenticazione

Il sistema usa l'autenticazione standard di Laravel (session-based). Senza login non si accede a nessuna funzionalita'.

### Funzionalita'

| Funzione | Route | Descrizione |
|---|---|---|
| **Login** | `GET /login` | Form di accesso |
| **Login POST** | `POST /login` | Autentica con email + password |
| **Logout** | `POST /logout` | Termina la sessione |
| **Password dimenticata** | `GET /forgot-password` | Richiesta link di reset |
| **Invio reset** | `POST /forgot-password` | Invia email con token di reset |
| **Reset password** | `GET /reset-password/{token}` | Form per nuova password |
| **Salva password** | `POST /reset-password` | Aggiorna la password |

**Nota**: la registrazione di nuovi utenti NON e' implementata. Gli utenti vengono creati manualmente:

```bash
php artisan tinker --execute="
    \App\Models\User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario@example.com',
        'password' => bcrypt('password123'),
    ]);
"
```

Per il recupero password, configurare il mail driver in `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=secret
MAIL_FROM_ADDRESS=noreply@example.com
```

---

## Interfaccia Chat

L'applicazione include un'interfaccia chat completa in stile ChatGPT/Claude, accessibile a `/chat` dopo il login.

### Layout

```
┌──────────────────────────────────────────────────────────┐
│  Enterprise KB                                   Logout  │
├──────────┬───────────────────────────────────────────────┤
│          │                                               │
│ + Nuova  │    [Area messaggi scrollabile]                │
│   Chat   │                                               │
│ ──────── │    Utente: Come funziona OAuth?               │
│ Chat 1   │                                               │
│ Chat 2 ✎🗑│   Assistente: Secondo la documentazione...   │
│ Chat 3   │                                               │
│          ├───────────────────────────────────────────────┤
│ user@... │ [Messaggio...              ] [🎤] [Invio]    │
└──────────┴───────────────────────────────────────────────┘
```

### Funzionalita'

- **Nuova Chat**: crea una conversazione vuota
- **Lista chat**: sidebar con tutte le conversazioni, ordinate per ultima attivita'
- **Rinomina**: click sull'icona matita, edit inline, Enter per salvare
- **Elimina**: click sull'icona cestino con conferma
- **Salva titolo automatico**: dopo il primo messaggio, l'AI genera automaticamente un titolo descrittivo
- **Persistenza**: ogni conversazione mantiene lo storico completo; cliccando su una chat precedente si ricaricano tutti i messaggi e il contesto RAG
- **Multi-turn**: la cronologia completa viene inviata all'AI ad ogni messaggio, mantenendo il contesto della conversazione
- **Rendering markdown**: le risposte dell'assistente vengono renderizzate con formattazione markdown (codice, liste, grassetto, ecc.)
- **Metadati**: ogni risposta mostra il modello AI e la latenza

### Chat History

Le conversazioni sono salvate nel database in due tabelle:

- **`conversations`**: `id`, `user_id` (FK), `title`, `project_key`, timestamps
- **`messages`**: `id`, `conversation_id` (FK), `role` (user/assistant), `content`, `metadata` (JSON con provider, model, tokens, latency)

Ogni utente vede **solo le proprie conversazioni**. L'ownership e' verificata server-side su ogni operazione.

### Endpoint AJAX (session auth)

| Metodo | Route | Descrizione |
|---|---|---|
| `GET` | `/conversations` | Lista conversazioni utente |
| `POST` | `/conversations` | Crea nuova conversazione |
| `PATCH` | `/conversations/{id}` | Rinomina |
| `DELETE` | `/conversations/{id}` | Elimina (con tutti i messaggi) |
| `GET` | `/conversations/{id}/messages` | Carica messaggi |
| `POST` | `/conversations/{id}/messages` | Invia messaggio (trigger risposta AI) |
| `POST` | `/conversations/{id}/generate-title` | Genera titolo via AI |

### Speech-to-Text

Il microfono usa la **Web Speech API nativa del browser** — nessun servizio esterno, nessun costo.

**Browser supportati**: Chrome, Edge, Safari (parziale). Firefox NON supportato.

**Come funziona**:
1. Click sul pulsante microfono (diventa rosso e pulsa)
2. Parla — la trascrizione appare in tempo reale nel campo di input
3. Click di nuovo per fermare, oppure il riconoscimento si ferma automaticamente alla pausa
4. Il testo trascritto resta nel campo di input — puoi modificarlo prima di inviare
5. Click "Invio" per mandare il messaggio

**Lingua**: configurata su italiano (`it-IT`) di default. Per cambiarla, modificare `this.recognition.lang` in `chat.blade.php`.

Se il browser non supporta la Web Speech API, il pulsante microfono appare disabilitato.

---

## Visualizzazioni Smart e Artefatti

L'AI non si limita a rispondere con testo — quando i dati lo giustificano, genera **artefatti visivi interattivi** direttamente nella chat.

### Tipi di artefatti

| Artefatto | Quando si attiva | Tecnologia |
|---|---|---|
| **Tabelle** | Confronti, configurazioni, dati strutturati | Markdown table con styling enhanced |
| **Grafici** | Statistiche, distribuzioni, trend, confronti numerici | Chart.js (bar, line, pie, doughnut) |
| **Code blocks** | Snippet di codice, configurazioni, comandi | Syntax highlight + pulsante "Copia" |
| **Action buttons** | Contenuti copiabili, file scaricabili | Pulsanti interattivi (copy to clipboard, download file) |

### Come funzionano i grafici

Il system prompt istruisce l'AI a generare un blocco `~~~chart` con JSON strutturato quando la risposta contiene dati che beneficerebbero di una visualizzazione:

```
~~~chart
{
    "type": "bar",
    "title": "Ticket per categoria",
    "labels": ["Bug", "Feature", "Docs"],
    "datasets": [{"label": "Conteggio", "data": [42, 28, 15]}]
}
~~~
```

Il frontend:
1. Intercetta i blocchi `~~~chart` durante il rendering markdown
2. Li sostituisce con un `<canvas>` placeholder
3. Inizializza un grafico Chart.js con i dati e lo stile automatico
4. Supporta: `bar`, `line`, `pie`, `doughnut`

### Come funzionano gli action button

L'AI puo' generare pulsanti interattivi per contenuti che l'utente potrebbe voler copiare o scaricare:

```
~~~actions
[
    {"label": "Copia configurazione", "action": "copy", "data": "DATABASE_URL=postgresql://..."},
    {"label": "Scarica template YAML", "action": "download", "filename": "config.yml", "data": "server:\n  port: 8080"}
]
~~~
```

Tipi di azione:
- **copy**: copia il contenuto nella clipboard con feedback visivo "Copiato!"
- **download**: scarica il contenuto come file con il nome specificato

### Code blocks con copia

Ogni blocco di codice nelle risposte ha un pulsante **"Copia"** nell'angolo in alto a destra. Click per copiare l'intero contenuto del blocco nella clipboard.

---

## Feedback e Auto-Learning

Il sistema impara dalle preferenze dell'utente attraverso un ciclo di feedback che migliora progressivamente la qualita' delle risposte.

### Come funziona

```
Utente riceve risposta
    │
    ├── Click 👍 (positivo) → salva rating nel messaggio
    │       │
    │       └── Le prossime risposte includeranno questa Q&A
    │           come "esempio di buona risposta" nel prompt
    │           (few-shot learning)
    │
    └── Click 👎 (negativo) → salva rating nel messaggio
            │
            └── Segnale per analytics (non usato nel prompt)
```

### Few-Shot Learning

Quando l'utente valuta positivamente una risposta, il sistema:

1. Salva il rating (`positive`) sul messaggio nel database
2. Nelle richieste successive, il `FewShotService` recupera le ultime 3 Q&A positive dello stesso utente/progetto
3. Queste vengono iniettate nel system prompt come "Examples of Well-Rated Answers"
4. L'AI apprende tono, profondita' e formato che l'utente preferisce

Questo permette al sistema di **adattarsi** a ciascun utente senza fine-tuning del modello:
- Un utente che premia risposte dettagliate otterra' risposte piu' approfondite
- Un utente che premia risposte concise otterra' risposte piu' brevi
- Le preferenze di formato (tabelle vs. prose, tecnico vs. divulgativo) vengono apprese

### Toggle

Il feedback e' un toggle: premere di nuovo lo stesso pollice rimuove il rating.

### Feedback nei log

Se il chat logging e' abilitato, ogni risposta registra nel campo `extra`:
- `few_shot_count`: quanti esempi positivi sono stati iniettati nel prompt
- `citations_count`: quante citazioni ha prodotto la risposta

Questo permette di analizzare la correlazione tra few-shot examples e qualita' percepita.

---

## Reranking

Il sistema implementa **reranking ibrido** che migliora la qualita' dei risultati RAG combinando tre segnali di rilevanza:

### Come funziona

```
Query utente
    │
    ▼
1. Over-retrieval: pgvector restituisce 3x candidati (es. 24 invece di 8)
    │                con cosine similarity score
    ▼
2. Reranking: per ogni candidato si calcolano 3 score:
    ├── vector_score  (0-1): similarita' coseno originale da pgvector
    ├── keyword_score (0-1): copertura keyword della query nel testo
    └── heading_score (0-1): match keyword nel heading del chunk
    │
    ▼
3. Score fusion: combined = 0.6×vector + 0.3×keyword + 0.1×heading
    │
    ▼
4. Top-K: i migliori 8 chunk (configurabile) vengono restituiti
```

### Perche' il reranking migliora i risultati

La ricerca puramente vettoriale puo' perdere risultati che contengono le parole esatte della query. Il reranking keyword-based recupera questi chunk premiando la corrispondenza lessicale diretta.

**Esempio**: la query "configurazione OAuth 2.0" potrebbe avere un match vettoriale forte con un chunk generico sull'autenticazione, ma il reranker favorira' il chunk che contiene esattamente "OAuth 2.0" nel testo o nel heading.

### Configurazione

In `.env`:

```env
# Abilita/disabilita reranking (default: abilitato)
KB_RERANKING_ENABLED=true

# Quanti candidati recuperare prima del reranking (multiplier × limit)
KB_RERANK_CANDIDATE_MULTIPLIER=3

# Pesi dei segnali (devono sommare a 1.0)
KB_RERANK_VECTOR_WEIGHT=0.60
KB_RERANK_KEYWORD_WEIGHT=0.30
KB_RERANK_HEADING_WEIGHT=0.10
```

### Dettagli tecnici

- **Zero costi aggiuntivi**: il reranker gira interamente in-process, nessuna API esterna
- **Stop words**: filtra automaticamente stop words italiane e inglesi
- **Whole-word bonus**: match di parole intere ricevono un bonus rispetto a match parziali (substring)
- **Trasparente**: ogni chunk restituito include `rerank_detail` con i singoli score per debug

---

## Embedding Cache

Il sistema di caching embeddings evita chiamate API ridondanti quando si re-indicizzano documenti invariati o si ripetono query di ricerca identiche.

### Come funziona

```
Testo da embeddare
    │
    ▼
EmbeddingCacheService::generate([$text1, $text2, ...])
    │
    ├── Per ogni testo: SHA-256 hash
    │
    ├── Batch lookup su tabella embedding_cache
    │     (hash + provider + model)
    │
    ├── Cache HIT → embedding restituito da DB (zero API call)
    │
    ├── Cache MISS → solo i testi nuovi vanno all'API
    │     └── risultato salvato in cache per riuso futuro
    │
    └── Risultato finale: array di embeddings order-matched
```

### Quando e' utile

- **Re-ingestion**: re-indicizzare gli stessi documenti non consuma token API
- **Query ripetute**: la stessa domanda di ricerca non genera embedding duplicati
- **Sviluppo**: durante lo sviluppo si fanno molte query di test simili

### Tabella `embedding_cache`

```sql
id              BIGINT PK
text_hash       VARCHAR(64) UNIQUE    -- SHA-256 del testo input
provider        VARCHAR(64)           -- openai, gemini, etc.
model           VARCHAR(128)          -- text-embedding-3-small, etc.
embedding       VECTOR(1536)          -- vettore cached
created_at      TIMESTAMP
last_used_at    TIMESTAMP             -- per pulizia LRU
```

### Configurazione

```env
# Abilita/disabilita il caching embeddings (default: abilitato)
KB_EMBEDDING_CACHE_ENABLED=true
```

### Manutenzione

```php
use App\Services\Kb\EmbeddingCacheService;

$cache = app(EmbeddingCacheService::class);

// Statistiche cache
$cache->stats();
// → ['total_entries' => 1234, 'providers' => [...]]

// Pulisci entry non usate da 30+ giorni
$cache->prune(now()->subDays(30));

// Svuota tutta la cache
$cache->flush();

// Svuota solo cache di un provider
$cache->flush('openai');
```

> **Nota**: quando si cambia provider di embeddings (es. da OpenAI a Gemini), la cache contiene vettori del vecchio provider. Fare `flush()` e re-indicizzare.

---

## Hybrid Search

La hybrid search combina la ricerca semantica (pgvector) con la ricerca full-text tradizionale di PostgreSQL (tsvector/tsquery) per catturare casi in cui i termini esatti contano.

### Perche' serve

La ricerca puramente semantica eccelle nel trovare contenuti concettualmente simili, ma puo' perdere:

| Tipo di query | Semantica pura | Hybrid |
|---|---|---|
| "configurazione OAuth" | Trova bene | Trova bene |
| "codice prodotto XR-4521" | Puo' mancare | Match esatto via FTS |
| "art. 42 comma 3" | Puo' mancare | Match esatto via FTS |
| "errore ENOMEM" | Puo' confondere | Match esatto via FTS |

### Come funziona

```
Query utente
    │
    ├──────────────────────┐
    ▼                      ▼
Semantic Search         Full-Text Search
(pgvector cosine)       (tsvector/tsquery)
    │                      │
    ├── ranked list #1     ├── ranked list #2
    │                      │
    └──────────┬───────────┘
               │
               ▼
    Reciprocal Rank Fusion (RRF)
    score = Σ weight / (k + rank)
               │
               ▼
    Merged ranked list → Reranker → Top-K
```

**Reciprocal Rank Fusion (RRF)** e' l'algoritmo standard per fondere due ranked list senza bisogno di normalizzare gli score. E' usato da Elasticsearch, Pinecone, e tutti i principali sistemi di ricerca ibrida.

### Full-Text Search in PostgreSQL

Usa le funzionalita' native di PostgreSQL:

- **`to_tsvector(lang, text)`** — converte testo in token searchabili
- **`plainto_tsquery(lang, query)`** — converte query in modo sicuro (no errori di sintassi)
- **`ts_rank()`** — calcola rilevanza
- **Lingua configurabile**: italiano (default), inglese, tedesco, ecc.

Non richiede indici GIN aggiuntivi per funzionare (ma raccomandati per performance su grandi dataset):

```sql
-- Opzionale: indice GIN per performance su tabelle grandi
CREATE INDEX idx_chunks_fts ON knowledge_chunks
    USING GIN (to_tsvector('italian', chunk_text));
```

### Configurazione

```env
# Abilita/disabilita hybrid search (default: disabilitato)
KB_HYBRID_SEARCH_ENABLED=false

# Lingua per il full-text search di PostgreSQL
# Valori: italian, english, german, french, spanish, portuguese, ...
KB_FTS_LANGUAGE=italian

# Parametro K per RRF (default 60, standard del settore)
KB_RRF_K=60

# Pesi relativi nella fusione RRF
KB_HYBRID_SEMANTIC_WEIGHT=0.70
KB_HYBRID_FTS_WEIGHT=0.30
```

### Quando abilitarlo

- **Abilitare** se i documenti contengono codici prodotto, riferimenti legali, sigle, numeri di pratica, o altri termini che devono essere trovati per match esatto
- **Lasciare disabilitato** se il contenuto e' principalmente prosa e la ricerca semantica funziona bene da sola
- Il costo computazionale e' minimo (una query SQL in piu')

---

## Citations

Ogni risposta dell'assistente include le **citazioni** — i documenti sorgente che hanno fornito il contesto per la risposta. Questo permette all'utente di verificare l'informazione direttamente alla fonte.

### Come funziona

1. Il sistema RAG recupera N chunk da M documenti diversi
2. I chunk vengono raggruppati per documento sorgente
3. Per ogni documento si raccolgono: titolo, path, heading paths, numero di chunk usati
4. Le citazioni vengono salvate nel metadata del messaggio assistente
5. Il frontend le mostra in una sezione collassabile sotto la risposta

### Formato citazioni (API)

```json
{
    "answer": "L'autenticazione OAuth si configura...",
    "citations": [
        {
            "document_id": 12,
            "title": "Configurazione OAuth 2.0",
            "source_path": "docs/auth/oauth.md",
            "headings": ["Prerequisiti", "Configurazione Client"],
            "chunks_used": 3
        },
        {
            "document_id": 8,
            "title": "Architettura Sicurezza",
            "source_path": "docs/security/overview.md",
            "headings": ["Token Management"],
            "chunks_used": 1
        }
    ],
    "meta": { ... }
}
```

### UI nel frontend

Sotto ogni risposta dell'assistente appare un link "N fonte/i" cliccabile:

```
Assistente: L'autenticazione OAuth si configura...
   ▶ 2 fonte/i                          ← click per espandere
   ┌─────────────────────────────────┐
   │ 📄 Configurazione OAuth 2.0    │
   │    docs/auth/oauth.md          │
   │    [Prerequisiti] [Config...]  │
   │                                 │
   │ 📄 Architettura Sicurezza      │
   │    docs/security/overview.md   │
   │    [Token Management]          │
   └─────────────────────────────────┘
   gpt-4o · 2340ms · 4 chunk
```

Le citazioni sono persistite nel campo `metadata.citations` della tabella `messages`, quindi sono disponibili anche quando si ricarica una conversazione precedente.

---

## API

### POST `/api/kb/chat`

Endpoint principale per interrogare la knowledge base (stateless, senza conversazione).

**Headers:**

| Header | Obbligatorio | Descrizione |
|---|---|---|
| `Authorization` | Si | `Bearer {sanctum-token}` |
| `Content-Type` | Si | `application/json` |
| `X-Session-Id` | No | UUID per raggruppare messaggi della stessa sessione |

**Request body:**

```json
{
    "question": "Come configuro il sistema di autenticazione?",
    "project_key": "erp-core"
}
```

| Campo | Tipo | Obbligatorio | Descrizione |
|---|---|---|---|
| `question` | string | Si | Domanda in linguaggio naturale (max 10.000 char) |
| `project_key` | string | No | Filtra la ricerca semantica per progetto |

**Response 200:**

```json
{
    "answer": "Per configurare l'autenticazione nel modulo ERP-Core...",
    "citations": [
        {
            "document_id": 12,
            "title": "Configurazione Auth",
            "source_path": "docs/auth/setup.md",
            "headings": ["OAuth 2.0", "Prerequisiti"],
            "chunks_used": 2
        }
    ],
    "meta": {
        "provider": "openai",
        "model": "gpt-4o",
        "chunks_used": 5,
        "latency_ms": 2340
    }
}
```

**Autenticazione:** Laravel Sanctum (Bearer token).

---

## MCP Server

Il server MCP (Model Context Protocol) espone la knowledge base come set di tool read-only, permettendo a Claude e altri AI agent di interrogare la KB direttamente.

### Endpoint

```
/mcp/kb
```

Protetto da `auth:sanctum` e `throttle:api`.

### Tool disponibili

| Tool | Descrizione | Parametri |
|---|---|---|
| `KbSearchTool` | Ricerca semantica | `query` (required), `project_key`, `limit` |
| `KbReadDocumentTool` | Lettura documento completo | `document_id` (required) |
| `KbReadChunkTool` | Lettura singolo chunk | `chunk_id` (required) |
| `KbRecentChangesTool` | Documenti indicizzati di recente | `project_key`, `limit` |
| `KbSearchByProjectTool` | Ricerca vincolata a progetto | `project_key` (required), `query` (required), `limit` |

### Integrazione con Claude Desktop / Claude Code

```bash
# Claude Code CLI
claude mcp add --transport http kb http://localhost:8000/mcp/kb \
    --header "Authorization: Bearer {token}"
```

---

## Document Ingestion

### Ingestion Markdown via codice

```php
use App\Services\Kb\DocumentIngestor;

$ingestor = app(DocumentIngestor::class);

$ingestor->ingestMarkdown(
    projectKey: 'erp-core',
    sourcePath: 'docs/auth/setup.md',
    title: 'Configurazione Autenticazione',
    markdown: file_get_contents('/path/to/setup.md'),
    metadata: [
        'language' => 'it',
        'access_scope' => 'internal',
        'author' => 'team-auth',
    ],
);
```

### Pipeline di ingestion

1. **Hash** — SHA256 del contenuto per idempotenza
2. **Chunking** — Split del markdown in chunk (predisposto per parser AST-aware)
3. **Embedding** — Generazione embeddings via provider configurato
4. **Storage** — Transazione atomica: `KnowledgeDocument` + N `KnowledgeChunk` con embedding

### Idempotenza

L'ingestion e' idempotente: re-ingerire lo stesso documento con lo stesso contenuto non crea duplicati. I constraint unique su `(project_key, source_path, version_hash)` e `(knowledge_document_id, chunk_hash)` garantiscono consistenza.

---

## Estensione

### Aggiungere un nuovo AI provider

1. Crea `app/Ai/Providers/NuovoProvider.php` implementando `AiProviderInterface`
2. Implementa i metodi: `chat()`, `generateEmbeddings()`, `name()`, `supportsEmbeddings()`
3. Aggiungi il match case in `AiManager::resolve()`
4. Aggiungi la sezione configurazione in `config/ai.php`

### Aggiungere un driver di chat log

1. Crea `app/Services/ChatLog/Drivers/NuovoDriver.php` implementando `ChatLogDriverInterface`
2. Implementa il metodo `store(ChatLogEntry $entry): void`
3. Aggiungi il match case in `ChatLogManager::resolveDriver()`
4. Aggiungi la sezione configurazione in `config/chat-log.php`

### Aggiungere un MCP tool

1. Crea `app/Mcp/Tools/NuovoTool.php` estendendo `Laravel\Mcp\Server\Tool`
2. Definisci schema e handler
3. Registra la classe in `KnowledgeBaseServer::$tools`

---

## Come funziona il RAG

RAG (Retrieval-Augmented Generation) e' il pattern architetturale alla base di questo sistema. Invece di affidarsi solo alla conoscenza del modello AI, il sistema recupera informazioni rilevanti dalla knowledge base aziendale e le inietta nel prompt, garantendo risposte accurate, aggiornate e tracciate alle fonti.

### Flusso completo di una richiesta

```
Utente: "Come configuro OAuth nel modulo ERP?"
                │
                ▼
┌─── 1. EMBEDDING DELLA QUERY ────────────────────────────────┐
│  AiManager genera un vettore numerico (embedding) della     │
│  domanda usando il provider embeddings configurato.         │
│  Es: OpenAI text-embedding-3-small → vettore 1536-dim      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 2. RICERCA SEMANTICA + RERANKING ────────────────────────┐
│  pgvector confronta il vettore con tutti i chunk (coseno).   │
│  Over-retrieval: recupera 3x candidati (es. 24).            │
│  Reranker fonde: vector + keyword + heading score.           │
│  Risultato: top-K chunk piu' rilevanti (default: 8).        │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 3. COMPOSIZIONE PROMPT ──────────────────────────────────┐
│  I chunk recuperati vengono iniettati nel system prompt      │
│  (template Blade: resources/views/prompts/kb_rag.blade.php). │
│  Il prompt contiene regole precise:                          │
│  - Rispondi SOLO in base al contesto fornito                │
│  - Includi citazioni (documento, path, heading)             │
│  - Dichiara esplicitamente se il contesto e' insufficiente  │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 4. CHIAMATA AL MODELLO AI ───────────────────────────────┐
│  AiManager::chat() invia il prompt al provider configurato.  │
│  Il modello genera la risposta basandosi solo sul contesto   │
│  fornito (grounded generation, no hallucination).            │
│  La risposta include: contenuto, token usage, finish_reason. │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 5. LOGGING (opzionale) ──────────────────────────────────┐
│  Se CHAT_LOG_ENABLED=true, tutti i dati dell'interazione    │
│  vengono persistiti: domanda, risposta, provider, modello,  │
│  token, latenza, IP client, chunk usati. Protetto da        │
│  try/catch: errori di logging non bloccano mai la risposta. │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
             Risposta JSON al client
```

### Perche' RAG e non fine-tuning?

| Aspetto | RAG | Fine-tuning |
|---|---|---|
| **Aggiornamento dati** | Immediato (re-ingest documento) | Richiede re-training |
| **Tracciabilita'** | Citazioni esatte alle fonti | Nessuna |
| **Costo** | Basso (solo API call) | Alto (training + hosting) |
| **Hallucination** | Controllata (grounded) | Rischio maggiore |
| **Multi-tenant** | Filtro per project_key | Un modello per tenant |

---

## Come funziona il Multi-Provider AI

Il sistema e' progettato per non dipendere da nessun SDK AI esterno. Ogni provider viene chiamato via HTTP diretto (`Illuminate\Support\Facades\Http`), dando pieno controllo su autenticazione, retry, timeout, e formato delle risposte.

### Architettura del layer AI

```
AiManager (singleton)
    │
    ├── provider('openai')     → OpenAiProvider
    ├── provider('anthropic')  → AnthropicProvider
    ├── provider('gemini')     → GeminiProvider
    └── provider('openrouter') → OpenRouterProvider
    │
    ├── chat()                 → usa il provider di default
    ├── generateEmbeddings()   → usa il provider embeddings
    └── embeddingsProvider()   → risolve il provider per embeddings
```

### Provider separati per chat ed embeddings

Il concetto chiave e' che **chat e embeddings possono usare provider diversi**. Questo e' necessario perche':

- **Anthropic** (Claude) e' eccellente per la generazione testo, ma non offre un endpoint embeddings
- **OpenRouter** fa da gateway multi-modello per chat, ma non gestisce embeddings
- **OpenAI** e **Gemini** supportano entrambe le funzionalita'

Configurazione tipica enterprise:

```env
# Chat via Anthropic (Claude per qualita' superiore)
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...

# Embeddings via OpenAI (standard de facto, 1536-dim)
AI_EMBEDDINGS_PROVIDER=openai
OPENAI_API_KEY=sk-...
```

### Matrice compatibilita' provider

| Provider | Chat | Embeddings | Note |
|---|---|---|---|
| **OpenAI** | Si | Si | Default. Supporta entrambi. |
| **Anthropic** | Si | No | Richiede embeddings provider separato. |
| **Gemini** | Si | Si | Embedding dim diverse da OpenAI (768 vs 1536). |
| **OpenRouter** | Si | No | Gateway multi-modello. Richiede embeddings separato. |

### DTO di risposta

Ogni chiamata AI restituisce un DTO tipizzato:

- **`AiResponse`**: `content`, `provider`, `model`, `promptTokens`, `completionTokens`, `totalTokens`, `finishReason`
- **`EmbeddingsResponse`**: `embeddings` (array di vettori float), `provider`, `model`, `totalTokens`

Questo permette al controller e al chat log di tracciare automaticamente quale provider/modello ha generato ogni risposta.

---

## Come funziona il Chat Logging

Il sistema di chat logging e' un **servizio indipendente con architettura a driver**, simile ai pattern di Laravel per filesystem, cache e queue.

### Architettura

```
KbChatController
    │
    ▼
ChatLogManager::log(ChatLogEntry)
    │
    ├── enabled? → No → return (no-op)
    │
    ├── resolveDriver() → driver configurato
    │      │
    │      ├── 'database' → DatabaseChatLogDriver
    │      │                    └── ChatLog::create(...)
    │      ├── 'bigquery' → (predisposto, da implementare)
    │      └── 'cloudwatch' → (predisposto, da implementare)
    │
    └── try/catch → errori loggati su logger standard, mai propagati
```

### Perche' un service dedicato e non Monolog?

I dati delle chat sono **dati strutturati** (domanda, risposta, token count, latenza, IP), non righe di log testuali. Un service dedicato permette:

- **Query SQL dirette**: "quante richieste per progetto X nell'ultimo mese?"
- **Analytics strutturate**: costo per provider, latenza media, chunk usage
- **Export pulito**: verso BI, dashboard, o sistemi di monitoring
- **Separazione netta**: i log applicativi restano nel logger standard, le interazioni chat hanno il loro storage

### Tabella `chat_logs`

```sql
id                  BIGINT PK AUTO
session_id          UUID (indexed) — raggruppa messaggi di una sessione
user_id             FK nullable → users
question            TEXT
answer              TEXT
project_key         VARCHAR(120) nullable (indexed)
ai_provider         VARCHAR(64) (indexed) — openai, anthropic, gemini, openrouter
ai_model            VARCHAR(128) (indexed) — gpt-4o, claude-sonnet-4-20250514, ...
chunks_count        SMALLINT — quanti chunk di contesto usati
sources             JSON — path dei documenti sorgente
prompt_tokens       INT nullable
completion_tokens   INT nullable
total_tokens        INT nullable
latency_ms          INT — tempo end-to-end in ms
client_ip           VARCHAR(45) nullable
user_agent          VARCHAR(512) nullable
extra               JSON nullable — metadati estendibili
created_at          TIMESTAMP (indexed)
```

### Query di esempio

```sql
-- Costo medio per provider nell'ultimo mese
SELECT ai_provider, ai_model,
       COUNT(*) as requests,
       AVG(total_tokens) as avg_tokens,
       AVG(latency_ms) as avg_latency_ms
FROM chat_logs
WHERE created_at >= NOW() - INTERVAL '30 days'
GROUP BY ai_provider, ai_model
ORDER BY requests DESC;

-- Sessioni con piu' interazioni
SELECT session_id, COUNT(*) as messages,
       MIN(created_at) as started_at,
       MAX(created_at) as ended_at
FROM chat_logs
GROUP BY session_id
HAVING COUNT(*) > 1
ORDER BY messages DESC;

-- Distribuzione chunk usage
SELECT chunks_count, COUNT(*) as frequency
FROM chat_logs
GROUP BY chunks_count
ORDER BY chunks_count;
```

---

## Quick Start: Onboarding in 5 minuti

Per chi vuole partire velocemente con la configurazione minima (OpenAI + PostgreSQL):

```bash
# 1. Setup
cd 04_laravel
composer install
cp .env.example .env
php artisan key:generate

# 2. Database — configura PostgreSQL in .env
#    DB_CONNECTION=pgsql
#    DB_DATABASE=enterprise_kb

# 3. AI — aggiungi la key OpenAI in .env
#    OPENAI_API_KEY=sk-...

# 4. Migration
php artisan migrate

# 5. Crea utente
php artisan tinker --execute="
    \App\Models\User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
    ]);
"

# 6. Avvia
php artisan serve

# 7. Apri http://localhost:8000 → login con admin@example.com / password
# 8. Inizia a chattare con la knowledge base!
```

Per l'accesso API programmatico (Sanctum token):

```bash
php artisan tinker --execute="echo \App\Models\User::first()->createToken('api')->plainTextToken;"

curl -X POST http://localhost:8000/api/kb/chat \
  -H 'Authorization: Bearer {token}' \
  -H 'Content-Type: application/json' \
  -d '{"question": "Come funziona il sistema?"}'
```

Per abilitare il chat logging, aggiungi in `.env`:

```env
CHAT_LOG_ENABLED=true
```

Per passare a Claude (Anthropic) per le risposte:

```env
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
AI_EMBEDDINGS_PROVIDER=openai
```

Per abilitare la hybrid search (utile se i documenti contengono codici prodotto, sigle, riferimenti legali):

```env
KB_HYBRID_SEARCH_ENABLED=true
KB_FTS_LANGUAGE=italian
```

---

## Struttura directory

```
04_laravel/
├── app/
│   ├── Ai/
│   │   ├── AiManager.php                    # Manager multi-provider (singleton)
│   │   ├── AiProviderInterface.php          # Contratto per tutti i provider
│   │   ├── AiResponse.php                   # DTO risposta chat
│   │   ├── EmbeddingsResponse.php           # DTO risposta embeddings
│   │   ├── Agents/
│   │   │   └── KbAssistant.php              # Agent RAG (prompt builder)
│   │   └── Providers/
│   │       ├── OpenAiProvider.php            # OpenAI (chat + embeddings)
│   │       ├── AnthropicProvider.php         # Anthropic (chat only)
│   │       ├── GeminiProvider.php            # Gemini (chat + embeddings)
│   │       └── OpenRouterProvider.php        # OpenRouter (chat, multi-model)
│   ├── Http/Controllers/
│   │   ├── Auth/
│   │   │   ├── LoginController.php          # Login / logout
│   │   │   └── PasswordResetController.php  # Forgot / reset password
│   │   ├── ChatController.php               # Chat web page
│   │   └── Api/
│   │       ├── KbChatController.php         # API stateless (Sanctum)
│   │       ├── ConversationController.php   # CRUD conversazioni
│   │       ├── MessageController.php        # Invio messaggi + AI response
│   │       └── FeedbackController.php       # Rating thumbs up/down
│   ├── Mcp/
│   │   ├── Servers/KnowledgeBaseServer.php   # MCP server
│   │   └── Tools/                            # 5 tool MCP read-only
│   ├── Models/
│   │   ├── User.php
│   │   ├── Conversation.php
│   │   ├── Message.php
│   │   ├── KnowledgeDocument.php
│   │   ├── KnowledgeChunk.php
│   │   ├── ChatLog.php
│   │   └── EmbeddingCache.php
│   ├── Providers/
│   │   ├── AiServiceProvider.php
│   │   └── ChatLogServiceProvider.php
│   └── Services/
│       ├── ChatLog/
│       │   ├── ChatLogDriverInterface.php
│       │   ├── ChatLogEntry.php              # DTO immutabile
│       │   ├── ChatLogManager.php            # Manager con driver pattern
│       │   └── Drivers/
│       │       └── DatabaseChatLogDriver.php
│       └── Kb/
│           ├── DocumentIngestor.php          # Pipeline ingestion (with cache)
│           ├── KbSearchService.php           # Hybrid search + reranking
│           ├── EmbeddingCacheService.php     # Embedding cache (DB-backed)
│           ├── FewShotService.php            # Few-shot examples from feedback
│           ├── Reranker.php                  # Hybrid reranker (keyword + heading)
│           └── MarkdownChunker.php           # Chunking strategy
├── config/
│   ├── ai.php                                # Config multi-provider
│   ├── chat-log.php                          # Config chat logging
│   └── kb.php                                # Config KB + reranking
├── database/migrations/
│   ├── ..._create_users_table.php
│   ├── ..._create_knowledge_documents_table.php
│   ├── ..._create_knowledge_chunks_table.php
│   ├── ..._create_chat_logs_table.php
│   ├── ..._create_conversations_table.php
│   ├── ..._create_messages_table.php
│   ├── ..._create_embedding_cache_table.php
│   └── ..._add_rating_to_messages_table.php
├── resources/views/
│   ├── layouts/app.blade.php                 # Layout base (Tailwind CDN)
│   ├── auth/
│   │   ├── login.blade.php
│   │   ├── forgot-password.blade.php
│   │   └── reset-password.blade.php
│   ├── chat.blade.php                        # Chat UI (Alpine.js + STT)
│   └── prompts/
│       └── kb_rag.blade.php                  # System prompt template
├── routes/
│   ├── web.php                               # Auth + Chat UI + AJAX
│   ├── api.php                               # API Sanctum (POST /api/kb/chat)
│   └── ai.php                                # MCP server (/mcp/kb)
└── README.md
```

---

## Riepilogo variabili d'ambiente

```env
# ── Applicazione ─────────────────────────────────────────────
APP_KEY=
APP_URL=http://localhost:8000

# ── Database (PostgreSQL + pgvector) ─────────────────────────
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=enterprise_kb
DB_USERNAME=postgres
DB_PASSWORD=

# ── AI Provider ──────────────────────────────────────────────
AI_PROVIDER=openai
AI_EMBEDDINGS_PROVIDER=

OPENAI_API_KEY=
OPENAI_CHAT_MODEL=gpt-4o
OPENAI_EMBEDDINGS_MODEL=text-embedding-3-small

ANTHROPIC_API_KEY=
ANTHROPIC_CHAT_MODEL=claude-sonnet-4-20250514

GEMINI_API_KEY=
GEMINI_CHAT_MODEL=gemini-2.0-flash
GEMINI_EMBEDDINGS_MODEL=text-embedding-004

OPENROUTER_API_KEY=
OPENROUTER_CHAT_MODEL=anthropic/claude-sonnet-4-20250514
OPENROUTER_APP_NAME="Enterprise KB"

# ── Chat Logging ─────────────────────────────────────────────
CHAT_LOG_ENABLED=false
CHAT_LOG_DRIVER=database

# ── Knowledge Base ───────────────────────────────────────────
KB_EMBEDDINGS_DIMENSIONS=1536
KB_MIN_SIMILARITY=0.30
KB_DEFAULT_LIMIT=8
KB_MARKDOWN_ROOT=

# ── Embedding Cache ──────────────────────────────────────────
KB_EMBEDDING_CACHE_ENABLED=true

# ── Hybrid Search ────────────────────────────────────────────
KB_HYBRID_SEARCH_ENABLED=false
KB_FTS_LANGUAGE=italian
KB_RRF_K=60
KB_HYBRID_SEMANTIC_WEIGHT=0.70
KB_HYBRID_FTS_WEIGHT=0.30

# ── Reranking ────────────────────────────────────────────────
KB_RERANKING_ENABLED=true
KB_RERANK_CANDIDATE_MULTIPLIER=3
KB_RERANK_VECTOR_WEIGHT=0.60
KB_RERANK_KEYWORD_WEIGHT=0.30
KB_RERANK_HEADING_WEIGHT=0.10

# ── Mail (per recupero password) ─────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@example.com
```

---

## License

This project is open-sourced under the [MIT License](LICENSE).

You are free to use, modify, and distribute this software for any purpose, including commercial use.

---

## Contributing

Contributions are welcome! Here's how to get started:

1. **Fork** the repository
2. **Create** a feature branch: `git checkout -b feature/my-feature`
3. **Commit** your changes: `git commit -m 'Add my feature'`
4. **Push** to the branch: `git push origin feature/my-feature`
5. **Open** a Pull Request

### Guidelines

- Follow PSR-12 coding standards for PHP
- Add or update tests for new features when applicable
- Keep PRs focused — one feature or fix per PR
- Update the README if your change adds or modifies user-facing features
- Use English for code, comments, and commit messages

### Reporting Issues

Use [GitHub Issues](../../issues) to report bugs or request features. Please include:
- Steps to reproduce
- Expected vs. actual behavior
- Laravel version, PHP version, and AI provider used

---

## Changelog

### v1.0.0 — Initial Release

**Core RAG Pipeline**
- Document ingestion with markdown chunking and pgvector storage
- Semantic search with cosine similarity on PostgreSQL + pgvector
- Hybrid search (vector + full-text) with Reciprocal Rank Fusion
- Hybrid reranking (vector + keyword + heading scoring)
- Embedding cache to eliminate redundant API calls

**Multi-Provider AI**
- Support for OpenAI, Anthropic (Claude), Google Gemini, OpenRouter
- Separate providers for chat and embeddings
- Multi-turn conversation history sent to AI
- HTTP-direct integration (no external SDKs)

**Chat Interface**
- ChatGPT-like UI with sidebar, conversation management
- Speech-to-text via native Web Speech API
- Smart visualizations: Chart.js charts, action buttons (copy, download), enhanced tables
- Citations showing source documents used for each answer
- Feedback loop with few-shot learning from positive ratings
- Markdown rendering with syntax-highlighted code blocks + copy button

**Enterprise Features**
- Laravel session auth (login, logout, password reset — no registration)
- Structured chat logging (DB, extensible to BigQuery/CloudWatch)
- Per-user conversation isolation
- MCP Server with 5 read-only tools for Claude Desktop/Code integration
- Full API (Sanctum) for programmatic access
