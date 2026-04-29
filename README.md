# Panacea API

Laravel 12 backend for a three-tier medical consultation system. Owns user identity, chat history, anamneses, role/permission authorization, and the contract with a Python FastAPI ai-service. Consumed by a React SPA and a Swift iOS client (separate repos).

- **Architecture overview:** [`docs/architecture.md`](docs/architecture.md)
- **Generated OpenAPI 3.1 spec:** [`openapi.json`](openapi.json) — also live at `/docs/api` (Stoplight Elements)
- **Client codegen workflow:** [`docs/clients-from-spec.md`](docs/clients-from-spec.md)

## Stack

- PHP 8.2, Laravel 12, Pest 3
- **Postgres 16 + pgvector** (semantic + full-text + JSONB search on `chat_messages`)
- Redis-optional (cache + queue currently DB-backed by default)
- Sanctum bearer tokens, Spatie permissions, activitylog
- `dedoc/scramble` for OpenAPI auto-discovery

## Local setup

Postgres with pgvector is mandatory. Easiest path is the Docker image CI uses:

```bash
docker run -d --name panacea-pg \
  -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
  -e POSTGRES_DB=panacea \
  -p 5432:5432 \
  pgvector/pgvector:pg16
```

If you already have Postgres 15.x natively bound to 5432 (XAMPP / EnterpriseDB installer), publish the container on `5433` instead and override `DB_PORT=5433` in `.env`.

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Seeded admin: `admin@panacea.local` / `adminpass`.

## Tests

```bash
php artisan test
```

Tests run on sqlite `:memory:` by default for speed (the pgvector migration is a no-op there). The full Postgres path runs in CI on every push.

To run the suite against your local Postgres:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
  DB_DATABASE=panacea_test DB_USERNAME=postgres DB_PASSWORD=postgres \
  php artisan test
```

(Create `panacea_test` first: `docker exec panacea-pg psql -U postgres -c "CREATE DATABASE panacea_test;"`)

## OpenAPI spec

The spec is committed at [`openapi.json`](openapi.json). Regenerate after touching a controller:

```bash
php artisan scramble:export --path=openapi.json --silent
```

CI fails if the committed spec is out of sync with the controllers. See [`docs/clients-from-spec.md`](docs/clients-from-spec.md) for React + Swift codegen instructions.

## ai-service

The Python FastAPI service is owned by a teammate and reached over an ngrok tunnel set in `AI_MODULE_URL`. With `AI_USE_MOCK=true` (the default in `phpunit.xml`), all AI calls return deterministic mock responses, so tests and offline dev work without it.

## License

MIT.
