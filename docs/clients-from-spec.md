# Client codegen from `openapi.json`

The Laravel API publishes its OpenAPI 3.1 spec to [`openapi.json`](../openapi.json) at the repo root. Both the React SPA (`javachka`) and the Swift iOS app (`Bagyt`) generate their typed API clients from that one file — no hand-written DTOs, no drift, no `cmd-F APIDTOs.swift` when a controller changes shape.

## Updating the spec

When you change a controller, FormRequest, or response shape:

```bash
php artisan scramble:export --path=openapi.json --silent
git diff openapi.json   # eyeball the change
git add openapi.json
git commit
```

CI fails the build (spec-drift step) if `openapi.json` is out of sync with the code, so this step can't be skipped.

You can also browse the live docs locally — Scramble mounts Stoplight Elements at:

```
http://localhost:8000/docs/api
```

## Regenerating the React client (`javachka`)

We use [`openapi-typescript`](https://github.com/openapi-ts/openapi-typescript) for types and [`openapi-fetch`](https://github.com/openapi-ts/openapi-fetch) for the typed wrapper.

```bash
# in javachka/
npm install -D openapi-typescript
npm install openapi-fetch

# generate the types
npx openapi-typescript ../panacea/openapi.json -o src/api/types.gen.ts
```

Recommended import pattern in `src/api/client.ts`:

```ts
import createClient from "openapi-fetch";
import type { paths } from "./types.gen";

export const api = createClient<paths>({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8000/api",
});

api.use({
  onRequest({ request }) {
    const token = localStorage.getItem("auth_token");
    if (token) request.headers.set("Authorization", `Bearer ${token}`);
    return request;
  },
});
```

Existing hand-written DTOs in `src/api/*.js` can be deleted as you migrate each call site.

## Regenerating the Swift client (`Bagyt`)

We use [`swift-openapi-generator`](https://github.com/apple/swift-openapi-generator) via SwiftPM.

In `Bagyt/Package.swift`:

```swift
.package(url: "https://github.com/apple/swift-openapi-generator", from: "1.0.0"),
.package(url: "https://github.com/apple/swift-openapi-runtime", from: "1.0.0"),
.package(url: "https://github.com/apple/swift-openapi-urlsession", from: "1.0.0"),
```

Drop `panacea/openapi.json` into `Bagyt/Sources/PanaceaAPI/openapi.json` and add a generator config (`openapi-generator-config.yaml`):

```yaml
generate:
  - types
  - client
accessModifier: public
```

Build with the SwiftPM plugin enabled. Generated `Types.swift` + `Client.swift` replace the hand-written `Core/Models/APIDTOs.swift` (delete that file once `AuthService.swift` and `ChatService.swift` switch to the generated client).

The existing public surface of `AuthService` and `ChatService` stays unchanged — we only swap the internal HTTP/decoding plumbing. Offline mode and the Keychain token logic don't move.

## Endpoints intentionally outside the spec

- **`POST /api/chats/{id}/messages/stream`** — Server-Sent Events. Scramble doesn't model SSE schema, so this endpoint shows up in the spec as a regular POST returning `text/event-stream` but the per-event payload (`meta` / `delta` / `final` / `saved` / `error`) is documented in [`docs/architecture.md`](architecture.md), not in the OpenAPI types.

If you need typed SSE on a client, decode events by hand against the documented event shapes — both clients already do this and will continue to until OpenAPI 3.x grows real SSE support.
