# Deploying Panacea

This document walks you from "code on my laptop" to "live URL for the
defense" in roughly 30–45 minutes. Local-dev setup lives in
[README.md](./README.md) — this file is **only** the cloud path.

Stack:
- **Postgres** → Supabase (free tier, pgvector preinstalled, daily backups)
- **Laravel API** → Fly.io (`panacea-api.fly.dev`)
- **React SPA** → Vercel (`bagyt.vercel.app`)
- **AI service** → teammate's local FastAPI, swapped in via env var once reachable

Everything fits on free tiers. Total cost: $0.

---

## Prerequisites

- A GitHub account (you already have one).
- Fly CLI installed: `iwr https://fly.io/install.ps1 -useb | iex` (PowerShell).
- Vercel CLI installed: `npm i -g vercel`.
- A Supabase account: <https://supabase.com> — sign in with GitHub.
- A Sentry account (optional but recommended): <https://sentry.io> — sign in with GitHub, create a project for each repo.

---

## Step 1 — Supabase (Postgres + pgvector)

1. New project → name `panacea-prod`, region **eu-central-1** (Frankfurt), set a strong DB password, save it in a password manager.
2. Wait ~3 minutes for provisioning.
3. Open the **SQL Editor**, paste:
   ```sql
   CREATE EXTENSION IF NOT EXISTS vector;
   CREATE EXTENSION IF NOT EXISTS pg_trgm;
   ```
4. Copy the **Session Pooler** connection string from Settings → Database. The string has the shape:
   ```
   postgresql://postgres.<ref>:<password>@aws-0-eu-central-1.pooler.supabase.com:5432/postgres
   ```
   ⚠️ Use the **session** pooler (port 5432), not the transaction pooler (6543). Laravel migrations rely on session-scoped temp tables.

You'll plug those credentials into Fly secrets in Step 3.

---

## Step 2 — Sentry (optional, ~5 min)

1. Create two Sentry projects: `panacea-api` (platform: PHP/Laravel) and `bagyt` (platform: React).
2. Copy each project's **DSN** from Settings → Client Keys.
3. Hold them for Steps 3 and 4.

If you skip Sentry, leave the DSN env vars blank — the SDKs are no-op when unset (see `bootstrap/app.php` for the Laravel side, `src/main.jsx` for the React side).

---

## Step 3 — Fly.io (Laravel API)

From the `panacea/` directory:

```powershell
fly auth login              # opens browser
fly launch --no-deploy --copy-config   # detects fly.toml, asks app name → accept "panacea-api"
```

Set every secret in one batch. Replace `<…>` placeholders:

```powershell
fly secrets set `
  APP_KEY="base64:$([Convert]::ToBase64String((1..32 | ForEach-Object {Get-Random -Min 0 -Max 256})))" `
  DB_HOST=aws-0-eu-central-1.pooler.supabase.com `
  DB_PORT=5432 `
  DB_DATABASE=postgres `
  DB_USERNAME=postgres.<supabase-ref> `
  DB_PASSWORD="<supabase-password>" `
  AI_SERVICE_TOKEN="$([guid]::NewGuid().ToString('N'))" `
  HEALTH_PROBE_TOKEN="$([guid]::NewGuid().ToString('N'))" `
  SENTRY_LARAVEL_DSN="<sentry-laravel-dsn-or-empty>" `
  ALLOWED_ORIGINS="https://bagyt.vercel.app"
```

> **Why these specifically:** `APP_KEY` encrypts the user/PII columns and signs sessions — it must be 32 random bytes, base64-encoded. `AI_SERVICE_TOKEN` is a shared secret with the Python AI service. `HEALTH_PROBE_TOKEN` gates `/api/health/deep` so external uptime monitors can hit it without exposing DB latency to the world. `ALLOWED_ORIGINS` is the production frontend; preview deploys are admitted by the `*.vercel.app` regex in `config/cors.php`.

Deploy:

```powershell
fly deploy
```

The release VM runs `php artisan migrate --force` against Supabase, then the app VM accepts traffic on `https://panacea-api.fly.dev`. Verify:

```powershell
curl https://panacea-api.fly.dev/api/health
# → {"status":"ok"}

curl -H "X-Health-Probe-Token: <your-token>" https://panacea-api.fly.dev/api/health/deep
# → {"status":"ok","checks":{"db":...}}
```

---

## Step 4 — Vercel (React SPA)

From the `bagyt/` directory:

```powershell
vercel login                # browser auth
vercel link                 # creates .vercel/project.json, name "bagyt"

# Set the production API URL — baked into the bundle at build time.
vercel env add VITE_API_URL production
# When prompted, paste: https://panacea-api.fly.dev

# Same value for preview (preview deploys talk to prod API; simpler than
# spinning a preview backend and good enough for a demo).
vercel env add VITE_API_URL preview

# Optional: Sentry browser DSN
vercel env add VITE_SENTRY_DSN production
vercel env add VITE_SENTRY_DSN preview

vercel --prod
```

Deploy lands at `https://bagyt-<hash>.vercel.app` and an alias is also set up at `https://bagyt.vercel.app`.

Verify in the browser: open DevTools → Network → load any page → confirm requests fire at `https://panacea-api.fly.dev/api/*` and return 200/401 (not CORS-rejected).

---

## Step 5 — Hot-swap the AI service (when teammate is ready)

While the AI service is offline, the backend short-circuits via `AI_USE_MOCK=true` and returns a stub instead of 500s. When your teammate exposes their service (ngrok URL, or eventually a dedicated host), flip both flags:

```powershell
fly secrets set `
  AI_MODULE_URL="https://<teammate-url>/v1/chat" `
  AI_USE_MOCK=false
```

Fly restarts the VM with the new env. No code change, no redeploy. To go back to mock mode, set `AI_USE_MOCK=true` again.

---

## Updating production

After committing to `main`:

```powershell
# Backend
cd panacea
fly deploy

# Frontend
cd ../bagyt
vercel --prod
```

GitHub Actions runs CI on every push; require it to pass before deploying.

---

## Rollback

Fly retains a release history:

```powershell
fly releases                                          # list past deploys
fly deploy --image registry.fly.io/panacea-api:deployment-XXXXX
```

Vercel rollbacks are one click in the dashboard (Deployments → ⋯ → Promote to Production).

---

## Branch protection (one-time, GitHub UI)

Settings → Branches → "Add branch protection rule" for `main`:
- ✅ Require a pull request before merging
- ✅ Require status checks to pass:
  - `Pest tests (sqlite)`
  - `Pest tests (pgsql)`
  - `OpenAPI spec drift check`
  - `Lint + test + build` (the bagyt workflow)
- ❌ Do not require linear history — the merge commits Antinikita uses are fine.

---

## Constraints to know about

### APP_KEY rotation

Encrypted columns (`users.email_encrypted`, etc.) plus the `email_hash` HMAC sidecar all derive from `APP_KEY`. Rotating it without a re-encryption migration **bricks every encrypted row in the database**. Out of scope here — just know that `fly secrets set APP_KEY=…` after the first deploy is a one-way ticket to data loss unless you've prepped a migration first.

### Backups

Supabase free tier: daily auto-backups, 7-day retention, accessible from Dashboard → Database → Backups. Restoring creates a *new* project from the backup point; it isn't in-place. If you need PITR or longer retention, the $25/mo "Pro" plan adds both.

### Fly free-tier limits

- 3 shared-CPU VMs, 1 GB RAM each, 3 GB persistent volume
- 160 GB outbound bandwidth/mo
- Auto-stops after ~5 min idle (cold start ~3–5 s on first hit)

For a diploma demo this is plenty; the cold-start is mostly invisible during a defense if you warm it up beforehand.

### Vercel hobby plan

100 GB bandwidth/mo, unlimited builds, no team features. Perfect for the demo.

---

## Where to look when things break

| Symptom | Look here |
|---|---|
| 500 on every request | `fly logs` — usually missing secret or migration failure |
| CORS error in browser | Check `ALLOWED_ORIGINS` and the regex in `config/cors.php` |
| `relation "jobs" does not exist` | Release command didn't migrate — re-run `fly deploy` or `fly ssh console -C "php artisan migrate --force"` |
| SPA loads but API calls fail | Verify `VITE_API_URL` was set *before* the build; check DevTools `connect-src` in the CSP meta tag |
| AI endpoints return 502 | Either the teammate's service is down (expected if `AI_USE_MOCK=false`), or `AI_MODULE_URL` is stale |
| `fly logs` shows Sentry init errors | DSN is malformed; `fly secrets set SENTRY_LARAVEL_DSN=""` to disable |

---

For local development (XAMPP or Docker), see [README.md](./README.md).
