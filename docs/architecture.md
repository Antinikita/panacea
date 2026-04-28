# Panacea Architecture v2

> **Status:** Phase 0 — foundation in place. This document is the *target* shape of the system after the v2 rewrite. It is updated at the end of each phase.

## Overview

Panacea is a three-tier medical consultation system:

```
┌─────────────────┐      ┌───────────────────────┐      ┌──────────────────┐
│  React SPA      │ ───▶ │  Laravel API          │ ───▶ │  Python AI svc   │
│  (javachka)     │      │  (this repo)          │      │  (FastAPI/ngrok) │
│                 │ ◀─── │  Sanctum + Postgres   │ ◀─── │  OpenAI + Qdrant │
└─────────────────┘      └───────────────────────┘      └──────────────────┘
        ▲                          ▲
        │                          │
┌───────┴─────────┐                │
│  Swift iOS app  │ ───────────────┘
│  (Bagyt)        │
└─────────────────┘
```

The Laravel API is the **integration / auth / persistence layer**. It owns user identity, chat history, anamneses, role-based authorization, audit logs, and the contract with the ai-service. It does not run any LLM logic itself.

## Target module shape (Phase 1)

```
app/Modules/
  Auth/                     ← user identity, sessions, tokens
    Models/User.php
    Http/Controllers/AuthController.php
    Http/Requests/{Login,Register}Request.php
    Services/AuthService.php
    Database/Migrations/...
    Tests/Feature/AuthTest.php
    AuthServiceProvider.php
    routes.php

  Chat/                     ← chat sessions + messages + search
    Models/{Chat,ChatMessage}.php
    Http/Controllers/{ChatController,SearchController}.php
    Http/Requests/{SendMessage,StreamMessage,UpdateMessage,Regenerate,SearchRequest}.php
    Services/{ChatService,MessageHistoryService,SearchService}.php
    Events/{MessageSent,AssistantReplyCreated}.php
    Database/Migrations/...
    Tests/...
    ChatServiceProvider.php
    routes.php

  Anamnesis/                ← structured clinical summaries derived from a chat
    Models/Anamnesis.php
    Http/Controllers/AnamnesisController.php
    Services/AnamnesisService.php
    Database/Migrations/...
    Tests/...
    AnamnesisServiceProvider.php
    routes.php

  AI/                       ← thin wrapper around the Python ai-service
    Services/{AIClient,Embedder}.php
    Jobs/EmbedMessageJob.php
    AIServiceProvider.php

  Health/                   ← Phase 4 — pulse/sleep/steps from HealthKit
    Models/HealthMetric.php
    Http/Controllers/HealthMetricController.php
    Services/{HealthIngestService,HealthQueryService}.php
    Database/Migrations/...
    Tests/...
    HealthServiceProvider.php
    routes.php
```

**Module boundaries:** modules talk via *services* and *events*, never via direct cross-module model access. Each module exposes a `PublicApi.php` interface that lists what other modules may call. Internals stay private.

## Request lifecycle

```
HTTP request
   │
   ▼
HandleCors  ──▶  RequestId middleware (UUID into log context, returned as X-Request-Id)
   │
   ▼
auth:sanctum (Bearer token)
   │
   ▼
Idempotency middleware (caches response by Idempotency-Key for 24h on AI-write endpoints)
   │
   ▼
throttle:ai-write (or auth-strict / api-default)
   │
   ▼
can:chat.create (Spatie permission middleware)
   │
   ▼
FormRequest validation
   │
   ▼
Module Controller ──▶ Module Service ──▶ DB::transaction { user msg | AI call | assistant msg }
                                                │
                                                ▼
                                          AI\Services\AIClient (Http::retry(2,500ms) → ngrok)
                                                │
                                                ▼
                                          Domain event: AssistantReplyCreated
                                                │
                                                ▼
                                          AI\Jobs\EmbedMessageJob (queue) → pgvector
   │
   ▼
Structured JSON response: { user_message, assistant_message, error?: { code, message } }
```

## Storage (Phase 3)

Single Postgres 16 instance with three extensions:

- **`vector`** (pgvector) — `chat_messages.embedding vector(1536)` with HNSW cosine index for semantic search.
- **`pg_trgm`** — used in conjunction with tsvector for fuzzy ranking.
- Native **`tsvector`** — `chat_messages.tsv` is a generated column with a GIN index for full-text search.

Hybrid search uses **reciprocal rank fusion** of the tsvector and pgvector result sets — best UX for free-form queries.

`chat_messages.metadata` is **`jsonb`** with a `jsonb_path_ops` GIN index — flexible per-message metadata (RAG sources, intent, edit history) without schema migrations.

The existing relational core (`users`, `chats`, `anamneses`, `personal_access_tokens`, Spatie permission tables, `health_metrics`) stays in standard tables.

## AI integration contract

The Python ai-service is owned by a teammate. Its contract is the boundary we keep stable:

- **`POST /v1/chat`** (sync JSON) — body `{message, locale, history:[{role,content}], profile:{age,sex,goals,metrics}}`, returns `{answer, rag_used?, intent?, ...}`. Auth via `X-Service-Token` + `X-User-Id` headers.
- **`POST /v1/chat/stream`** (SSE) — same payload, emits `meta → delta* → final → saved` events.
- **`POST /v1/embed`** (Phase 3, to be coordinated with the team) — body `{text, locale}`, returns `{embedding: [...]}`.

Laravel calls these via `App\Modules\AI\Services\AIClient` with retry+backoff and a request-correlated `X-Request-Id` header. AIClient is the only place that knows about the ai-service URL — every other module depends on the abstraction.

## Observability

- **Sentry** captures exceptions + 10% of traces (`SENTRY_TRACES_SAMPLE_RATE=0.1`).
- **Request ID middleware** stamps every request with a UUID, pushes it into `Log::shareContext`, and returns it as `X-Request-Id` so client-reported errors are traceable end-to-end.
- **Structured logs** — every AI call logs status + latency + request_id, never request/response bodies (PII).
- **Activity log** (`spatie/laravel-activitylog`) records every `User`/`Chat`/`ChatMessage`/`Anamnesis` mutation + auth events (`login_success`, `login_failed`, `register`, `logout`) for medical-app audit needs.

## Authorization

Spatie `laravel-permission` with two roles:

- **`user`** — granted on registration. Has `chat.{create,read,update,delete}` and `anamnesis.{create,read,update,delete}` scoped to their own records.
- **`admin`** — full permissions. Created via seeder.

Routes use `can:<permission>` middleware. Services additionally scope all reads to `auth()->id()` so a `find(...)` cross-user attempt 404s rather than returning data.

## Testing strategy

- **Pest** with `RefreshDatabase` against sqlite `:memory:` for unit + feature tests. Fast (~1s for the full suite).
- **CI** (`.github/workflows/ci.yml`) runs Pest on every push and PR.
- AI calls **always mocked** in tests via `$this->mock(AIService::class, ...)` — tests never hit ngrok.
- Per-module tests live inside the module (`app/Modules/<Name>/Tests/Feature/...`). Each test file declares `uses(Tests\TestCase::class, RefreshDatabase::class)` because Pest only auto-applies its config to tests under `tests/`.
- **Pint** code-style check is deliberately **off** in CI for Phase 0 — to be turned on in Phase 1 after a single mass-fix commit, so we don't pile cosmetic churn on top of the structural changes.

## Out of scope (won't build)

- Microservices split — modular monolith is the right complexity ceiling for this team size.
- Real-time WebSocket sync (Reverb) — SSE handles streaming; multi-device live sync deferred unless a grader asks.
- Event sourcing — domain events are dispatched but state lives in tables, not a journal.
- Polyglot persistence (Mongo / Cassandra) — pgvector + JSONB + tsvector covers the same use cases on one DB.

## Phase rollout (high level)

| Phase | Focus | Status |
|-------|-------|--------|
| 0 | Foundation: branch, Pest baseline, CI, this doc | ✅ done |
| 1 | Modular monolith refactor (no behavior change) | ✅ done |
| 2 | Hardening: transactions, idempotency, rate limits, structured errors, audit log, RequestId | ✅ done (Sentry deferred to when DSN is provisioned) |
| 3 | Postgres++: pgvector + JSONB + tsvector + semantic search endpoint | ✅ done (sqlite still works locally; Postgres in CI matrix; ai-service /v1/embed is mocked until teammate exposes it) |
| 3 | Postgres++: pgvector + JSONB + tsvector + semantic search endpoint | pending |
| 4 | Health metrics module (HealthKit ingestion + AI profile enrichment) | pending |
| 5 | OpenAPI spec + client codegen for React + Swift | pending |

Detailed plan lives in `~/.claude/plans/calm-herding-wozniak.md`.
