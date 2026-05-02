# Panacea

Three-tier medical consultation system:

- **Laravel API** (this repo) — REST + SSE backend. Sanctum bearer tokens, Spatie roles/permissions, Pest tests, OpenAPI spec auto-generated from controllers.
- **React SPA** — separate repo at `J:\javachka\bagyt`. Vite + TanStack Query + i18next (en/ru/kk).
- **Python FastAPI ai-service** — teammate-owned, reached over an ngrok tunnel. Skippable in dev via `AI_USE_MOCK=true`.
- **Postgres 16 + pgvector** — runs in a Docker container. Owns chat history, embeddings, full-text + semantic search, and the new `health_metrics` table fed by the iOS app.

> **Pointers**
> - Architecture: [`docs/architecture.md`](docs/architecture.md)
> - Client codegen (React + Swift from `openapi.json`): [`docs/clients-from-spec.md`](docs/clients-from-spec.md)
> - Live API docs: `http://localhost:8000/docs/api` once `php artisan serve` is running
> - Generated spec: [`openapi.json`](openapi.json)

---

## Prerequisites

| Tool | Version | Why |
|---|---|---|
| **PHP** | 8.2+ | Laravel 12. Bundled with XAMPP. Required extensions: `pdo_pgsql`, `pdo_sqlite`, `mbstring`, `intl`, `bcmath` (all ship with XAMPP, may need uncommenting in `php.ini`). |
| **Composer** | 2.x | PHP package manager. |
| **Node.js** | 18+ | For the React SPA. |
| **npm** | 9+ | Bundled with Node. |
| **Docker Desktop** | 28+ | For the Postgres + pgvector container. |
| **psql** *(optional)* | 15+ | CLI for the DB. Bundled with most Postgres installers. Docker `exec` also works. |
| **Git** | 2.x | Both repos. |

---

## First-time setup

### 1. Backend (this repo)

```bash
cd c:/xampp/htdocs/panacea

# install PHP deps
composer install

# copy env, generate app key
cp .env.example .env
php artisan key:generate
```

### 2. Start Postgres in Docker

```bash
docker run -d --name panacea-pg \
  -e POSTGRES_USER=postgres \
  -e POSTGRES_PASSWORD=postgres \
  -e POSTGRES_DB=panacea \
  -p 5433:5432 \
  pgvector/pgvector:pg16
```

> Port 5433 (not the default 5432) avoids colliding with a native Postgres install. The `.env.example` matches this.

Then create a separate `panacea_test` database for the test suite:

```bash
docker exec panacea-pg psql -U postgres -c "CREATE DATABASE panacea_test;"
```

### 3. Migrate + seed

```bash
php artisan migrate --seed
```

This runs all migrations (creates 20 tables + installs `vector` and `pg_trgm` extensions) and seeds the Spatie roles + a default admin user (`admin@panacea.local` / `adminpass`).

### 4. Frontend

```bash
cd J:/javachka/bagyt
npm install
cp .env.example .env   # if you don't already have one
```

Make sure `.env` has:
```
VITE_API_URL=http://127.0.0.1:8000/api
```

---

## Daily operations

### Start everything

```bash
# 1. If Docker Desktop isn't running, launch it. Then:
docker start panacea-pg

# 2. Laravel API (one terminal)
cd c:/xampp/htdocs/panacea
php artisan serve                  # listens on http://127.0.0.1:8000

# 3. React SPA (another terminal)
cd J:/javachka/bagyt
npm run dev                        # listens on http://localhost:5173
```

Open `http://localhost:5173/login` and sign in (or register).

### Stop everything

- Ctrl+C in the `php artisan serve` and `npm run dev` terminals.
- The Postgres container can stay running across reboots; if you want it off:
  ```bash
  docker stop panacea-pg
  ```

### Restart Postgres only

```bash
docker stop panacea-pg && docker start panacea-pg
```

Data persists across `stop`/`start`. **Only `docker rm -f panacea-pg` wipes data.**

---

## Database management

### Inspect from the CLI

```bash
# psql shell inside the container
docker exec -it panacea-pg psql -U postgres -d panacea

# psql from host (you have psql 15.4 locally)
PGPASSWORD=postgres psql -h 127.0.0.1 -p 5433 -U postgres -d panacea
```

Useful inside psql:
```
\dt              # list tables
\d users         # describe table
\dx              # list installed extensions (vector, pg_trgm)
\l               # list databases
\q               # quit
```

### Inspect via Docker Desktop

Open Docker Desktop → Containers → `panacea-pg` → Exec tab → `psql -U postgres -d panacea`. Same psql session, just inside the GUI.

### GUI tool

Connection params for any client (DBeaver, TablePlus, pgAdmin):
```
Host:     127.0.0.1
Port:     5433
Database: panacea
User:     postgres
Password: postgres
```

### Run migrations after pulling new code

```bash
php artisan migrate
```

Re-running on an already-migrated DB is a no-op. To wipe and start fresh:

```bash
php artisan migrate:fresh --seed
```

> **Warning:** `migrate:fresh` drops every table. Use only on dev data.

### Common one-liners

```bash
# row counts everywhere
docker exec panacea-pg psql -U postgres -d panacea -c "
SELECT relname, n_live_tup
FROM pg_stat_user_tables
ORDER BY n_live_tup DESC;"

# recent activity log
docker exec panacea-pg psql -U postgres -d panacea -c "
SELECT log_name, description, causer_id, created_at
FROM activity_log ORDER BY id DESC LIMIT 20;"

# health metrics from the iOS app
docker exec panacea-pg psql -U postgres -d panacea -c "
SELECT user_id, type, value, unit, source, recorded_at
FROM health_metrics ORDER BY id DESC LIMIT 10;"

# embeddings populated?
docker exec panacea-pg psql -U postgres -d panacea -c "
SELECT count(*) AS total, count(embedding) AS with_embedding
FROM chat_messages;"
```

---

## Running tests

### Backend (Pest)

```bash
cd c:/xampp/htdocs/panacea
php artisan test
```

By default tests run on **sqlite `:memory:`** — fast (~2s), no Docker dependency, but the pgvector migration is a no-op there. The semantic-search test is `markTestSkipped` on sqlite.

To run the suite against the real Postgres + pgvector:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
  DB_DATABASE=panacea_test DB_USERNAME=postgres DB_PASSWORD=postgres \
  php artisan test
```

CI runs both drivers in a matrix on every push.

### Frontend

```bash
cd J:/javachka/bagyt
npm run dev    # dev server, hot-reload
npm run build  # production build, outputs to dist/
```

> No frontend tests yet — Vitest is configured but the suite is empty.

### OpenAPI spec drift check

After touching a controller, regenerate the spec and commit it (CI enforces sync):

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: \
  php artisan scramble:export --path=openapi.json --silent
```

---

## Common workflows

### Smoke-test the live stack end-to-end

```bash
# (assumes everything's running)
curl http://127.0.0.1:8000/api/health
# → {"status":"ok"}

curl http://127.0.0.1:8000/api/health/deep
# → {"laravel":"ok","ai_service":{"status":"ok"|"down","latency_ms":42}}

# register a user
curl -X POST http://127.0.0.1:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Smoke","email":"smoke@example.com","password":"secret123","password_confirmation":"secret123"}'
```

In the browser: `http://localhost:5173/login` → register → send a chat → check the dashboard.

### Browse the API spec interactively

`http://localhost:8000/docs/api` — Stoplight Elements UI with every endpoint, request/response schemas, and a Try-It panel. Mounted automatically by `dedoc/scramble`.

### Look at logs

```bash
# Laravel
tail -f c:/xampp/htdocs/panacea/storage/logs/laravel.log

# Postgres
docker logs -f panacea-pg
```

### Mock the AI service (dev without ngrok)

In `.env`:
```
AI_USE_MOCK=true
```

Then `php artisan serve` again. All AI calls return deterministic mock responses; chat works, embeddings get fake-but-stable vectors, search returns sensible results.

---

## Repo layout (what lives where)

```
c:/xampp/htdocs/
├── panacea/                  ← THIS REPO (Laravel API)
│   ├── app/Modules/
│   │   ├── Auth/             ← user identity, register/login/profile/password
│   │   ├── Chat/             ← chats + messages + search
│   │   ├── Anamnesis/        ← AI-generated medical histories
│   │   ├── AI/               ← AIService + Embedder + jobs
│   │   └── Health/           ← HealthKit metrics endpoints
│   ├── docs/
│   │   ├── architecture.md   ← target shape, phase rollout
│   │   └── clients-from-spec.md
│   ├── openapi.json          ← canonical spec, regenerated via scramble
│   └── README.md             ← this file
│
└── Bagyt/                    ← Swift iOS app (friend's repo)

J:/javachka/bagyt/            ← React SPA
  src/
    api/                      ← axios + per-domain helpers + hooks/
    pages/                    ← routes (Dashboard, Chats, ChatDetail, ...)
    components/               ← Header, SidebarNav, SearchBar, ...
    context/AuthContext.jsx   ← session state
    i18n.js                   ← en/ru/kk translations
```

### Branches in flight

| Repo | Branch | Status |
|---|---|---|
| Laravel | `feat/architecture-v2` | All five phases shipped: modular monolith, hardening, pgvector, health metrics, OpenAPI |
| React | `feat/architecture-v2-frontend` | Adapts to the new contract + adds search + health UI |
| React | `feat/perf-query-and-codesplit` | TanStack Query + lazy routes, profile edit + change-password forms (stacked on architecture-v2-frontend) |
| iOS (Bagyt) | `feat/health-upload` | HealthMetricsService — patch file at `c:/xampp/htdocs/health-upload.patch`, awaiting friend's `git am` |

---

## Troubleshooting

### `Connection refused` from Laravel

Postgres container stopped. `docker start panacea-pg`.

### `php artisan serve` says CORS error in browser

Check `ALLOWED_ORIGINS` in `.env`. Should include `http://localhost:5173`.

### `npm run dev` succeeds but the SPA can't reach the API

Check `J:/javachka/bagyt/.env`:
```
VITE_API_URL=http://127.0.0.1:8000/api
```
Restart `npm run dev` after changes — Vite reads `.env` at boot.

### `pdo_pgsql is not loaded` (PHP)

Open `c:/xampp/php/php.ini`, uncomment:
```ini
extension=pdo_pgsql
extension=pgsql
```
Save. Restart any running PHP process. (CLI processes pick it up on next invocation; Apache via XAMPP needs a control-panel restart.)

### Tests fail with `panacea/public/api/health could not be found`

`phpunit.xml` overrides `APP_URL` to `http://localhost` for tests — if you've changed it, revert. The XAMPP `APP_URL=http://localhost/panacea/public` is a dev-server thing; the test harness needs the bare host.

### iOS app has no metrics in DB

Check the live data:
```bash
docker exec panacea-pg psql -U postgres -d panacea -c \
  "SELECT count(*) FROM health_metrics WHERE source = 'healthkit';"
```
If 0 — the iOS app hasn't applied `health-upload.patch` yet, or hasn't logged in + opened the Health view since.

### Port collisions

| Service | Port | Owned by |
|---|---|---|
| Postgres (Docker) | 5433 | container `panacea-pg` |
| Postgres (native, optional) | 5432 | EnterpriseDB / homebrew Postgres |
| Laravel `php artisan serve` | 8000 | this repo |
| React `npm run dev` | 5173 | the SPA |
| MySQL (XAMPP) | 3306 | unused now (project switched to Postgres) |

If 5433 is taken, edit the `docker run` command's `-p` mapping and update `.env`'s `DB_PORT`.

---

## Resetting from scratch

If everything's broken and you want a clean slate:

```bash
# 1. Nuke the Postgres container + volume
docker rm -f panacea-pg

# 2. Recreate it
docker run -d --name panacea-pg \
  -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
  -e POSTGRES_DB=panacea -p 5433:5432 \
  pgvector/pgvector:pg16

# 3. Wait a few seconds, then:
docker exec panacea-pg psql -U postgres -c "CREATE DATABASE panacea_test;"

# 4. Re-migrate + reseed
cd c:/xampp/htdocs/panacea
php artisan migrate --seed

# 5. (frontend) clear node_modules + reinstall
cd J:/javachka/bagyt
rm -rf node_modules package-lock.json
npm install
```

---

## License

MIT.
