# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

"Panacea" is a Laravel 12 JSON API backend. Authenticated users submit free-text `complaints`; the server forwards each complaint (with the user's age/sex context) to an external AI module and persists the returned text as a `recommendation`. The frontend is an external React SPA (expected at `http://localhost:5173`) and a Swift mobile client — both talk to the same API but use different auth mechanisms (see "Dual auth model" below).

## Common commands

```bash
# One-time setup (installs PHP + JS deps, creates .env, runs migrations, builds assets)
composer setup

# Run the full dev stack (server + queue worker + vite) concurrently
composer dev

# Tests — always use this wrapper, it clears stale config first
composer test

# Single test
php artisan test --filter=testNameOrClass
# or, directly via Pest:
vendor/bin/pest --filter=testNameOrClass

# Code formatting (Laravel Pint is pre-installed)
vendor/bin/pint

# Queue worker (needed because QUEUE_CONNECTION=database)
php artisan queue:listen --tries=1
```

Tests run against **in-memory SQLite** (see `phpunit.xml`), even though the app DB is MySQL. Migrations must stay SQLite-compatible or tests will break. `tests/Pest.php` applies `RefreshDatabase` to every Feature test.

## Architecture notes

### Dual auth model (stateful API + Sanctum tokens)
`bootstrap/app.php` calls `$middleware->statefulApi()`, so **every `/api/*` route goes through session + CSRF** — this is not a pure-token API. The setup deliberately supports two clients at once, and `AuthController` discriminates between them via the private `isStatefulRequest(Request)` helper:

```php
private function isStatefulRequest(Request $request): bool {
    if ($request->header('X-Requested-With') === 'XMLHttpRequest') return true;
    if ($request->attributes->get('sanctum.stateful')) return true;
    return false;
}
```

- **React SPA** (stateful): axios in `resources/js/bootstrap.js` sets `X-Requested-With: XMLHttpRequest` globally. Flow: GET `/sanctum/csrf-cookie` → POST `/api/login`. `register`/`login` call `Auth::login()` + `session()->regenerate()` (session-fixation defence) and return **only** `{user}` — no token. `logout` invalidates the session, regenerates the CSRF token, and forgets the `laravel_session` + `XSRF-TOKEN` cookies.
- **Swift mobile** (stateless): no `X-Requested-With` header. `register`/`login` mint a Sanctum `plainTextToken` named `mobile-app` / `mobile`. `logout` deletes **only** `currentAccessToken()` — other devices' tokens stay valid.

When adding a new frontend origin, update **both** `FRONTEND_URL` (consumed by `config/cors.php` `allowed_origins`) **and** `SANCTUM_STATEFUL_DOMAINS` env vars. CORS origins parse from a comma-separated `FRONTEND_URL` — no hardcoded URLs in `config/cors.php`.

`AuthController::register` strictly validates `sex` (`in:male,female,other`) and `age` (`integer|min:0|max:120`) even though both stay in `User::$fillable` for mass assignment by the controller — relying on validation, not `$fillable`, for the safety boundary.

The `password` field is hashed by the Eloquent `'password' => 'hashed'` cast in `User::casts()` — controllers must pass plaintext, never `bcrypt()` it manually (would double-hash with older Laravel; idempotent in 11+ but still wrong).

### AI integration flow
`POST /api/complaints/analyze` with `{complaint_id}` is the core domain endpoint (`app/Http/Controllers/ComplaintAIController.php`):
1. Validates the complaint belongs to `Auth::id()` — returns 403 if not (manual check, see "Authorization" below).
2. Branches on `config('services.ai.use_mock')`. **Mock mode** returns a canned response with optional `sleep(config('services.ai.mock_delay'))`. **Real mode** POSTs to `config('services.ai.url')` with header `X-Service-Token: config('services.ai.token')`, timeout `config('services.ai.timeout')`, and a payload containing the user's `age`, `sex`, plus placeholder `goals`/`metrics`.
3. Extracts `reply` (falls back through `response` / `message` / `answer` keys) and persists it as a new `Recommendation` row.

**All AI settings are read via `config('services.ai.*')`, never `env()` at runtime** — this is required so `php artisan config:cache` (used in production) doesn't return `null`. The full config block lives in `config/services.php` under `ai`. Env keys: `AI_USE_MOCK`, `AI_MODULE_URL`, `AI_SERVICE_TOKEN`, `AI_TIMEOUT`, `AI_MOCK_DELAY`. To wire the real AI service, set `AI_USE_MOCK=false` plus URL/token in `.env`, then `php artisan config:clear` (or rebuild cache).

### Routing
`routes/web.php` is intentionally minimal — only the Sanctum `/sanctum/csrf-cookie` endpoint. **All app routes live in `routes/api.php`.** Inside the `auth:sanctum` group, `POST /complaints/analyze` must come **before** `Route::apiResource('complaints')` or it would be swallowed by the resource's `show` route (`/complaints/{complaint}`).

### Pagination
`ComplaintController@index` and `RecommendationController@index` return Laravel `LengthAwarePaginator` JSON shape (`data`, `links`, `meta`), not bare arrays. Per-page is `?per_page=N` (default 20, clamped 1–100). **This is a breaking change** from the older shape — any frontend consumer expecting a flat array must read `.data` (and for complaints, `.complaints.data`).

### Data model
- `User` hasMany `Complaint`, hasMany `Recommendation`. `users` table has non-standard `sex` (string, default 'male') and `age` (int, default 30) columns used as AI context.
- `Complaint` belongsTo `User`, hasMany `Recommendation`, plus a `latestRecommendation()` hasOne relation (used by `ComplaintController@index` to avoid N+1).
- `Recommendation` belongsTo both `User` and `Complaint`. `complaint_id` was added in a later migration and is nullable — older seeded rows may lack it.
- FK `complaints.user_id` and `recommendations.user_id` are `cascadeOnDelete()` (set by migration `2026_04_16_120000_fix_user_fk_cascade...`). Deleting a user wipes their complaints + recommendations atomically.

### Authorization
`app/Policies/ComplaintPolicy.php` exists but every method returns `false` and nothing registers/uses it. Ownership checks are done manually in each controller via `Complaint::where('user_id', Auth::id())->findOrFail($id)` or direct `$complaint->user_id !== Auth::id()` comparison (which returns 403 in `ComplaintAIController`). Follow that pattern when adding new endpoints — do not assume the policy is live.

### Legacy / deprecated controllers
These controllers carry `@deprecated` PHPDoc and are **not routed**. Don't extend them; they are kept as historical reference and should be deleted in a future revision:
- `SessionController`, `RegisteredUserController` — old Blade-based form auth, superseded by `AuthController`.
- `JsonSessionController` — stub from an earlier auth experiment.
- `PythonController` — posts to `http://127.0.0.1:5001/analyze`, predates the AI module integration.
- `resources/views/index.blade.php`, `welcome.blade.php` — mostly commented out; the real UI is the external React SPA.

Use `AuthController` / `ComplaintController` / `ComplaintAIController` / `RecommendationController` instead.

### Frontend stack
Tailwind v4 via `@tailwindcss/vite` — there is **no `tailwind.config.js`**; theme config lives in `resources/css/app.css` using `@theme` and `@source`. Vite entry points are `resources/css/app.css` and `resources/js/app.js` (see `vite.config.js`). `resources/js/bootstrap.js` sets `axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'` globally — this is the marker `AuthController::isStatefulRequest()` keys off.

## Conventions observed in this codebase

- Russian-language comments are scattered throughout controllers and migrations — preserve them when editing.
- Validation is inline via `$request->validate([...])`; there are no FormRequest classes.
- Responses are always `response()->json(...)` — do not return arrays or views from API controllers.
- Health check endpoint: `/up` (configured in `bootstrap/app.php`).
- Never call `env()` outside config files — anything `php artisan config:cache` would freeze must go through `config()`.
