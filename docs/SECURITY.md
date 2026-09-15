# Security Review

Status of every item from the project's security checklist, against what is
actually implemented in this codebase as of Phase 8 — not what's aspirational.
Each item says **Implemented** (with the file that proves it) or **Production
hardening consideration** (a real gap, appropriate to leave open at this
project's scale, with what closing it would require).

## Authentication — Implemented

Opaque, random, hashed bearer tokens (`src/Services/AuthService.php`,
`src/Middleware/AuthMiddleware.php`). Not JWT — see the Phase 1 discussion:
revocation is a stated requirement, and an opaque token gives it for free
without a stateless-token blocklist.

## Authorization — Implemented (for what this project needs)

Every write endpoint requires a valid, non-revoked, non-expired token
(`AuthMiddleware`). There is currently one flat role — "authenticated" — so
authorization here is binary, not role-based. That's an honest description
of the scope, not a shortfall: nothing in the spec calls for multiple roles
(e.g. "manager" vs "viewer"). If that need appears later, the FK from
`personal_access_tokens.user_id` to `users` and the `AuthContext::userId()`
helper are already the right seam to hang a `role` column and per-route
checks off of.

## Password storage — Implemented

`password_hash()` / `password_verify()` (`AuthService::register`,
`AuthService::login`) — bcrypt under PHP's current `PASSWORD_DEFAULT`. No
plaintext password is ever stored or logged.

## Token security — Implemented

Tokens are 256 bits of `random_bytes()`, and only their SHA-256 hash is ever
written to `personal_access_tokens.token_hash`
(`AuthService::login`/`TokenRepository`). A database dump alone cannot be
replayed as valid tokens. Expiry is enforced in the same query that checks
validity (`TokenRepository::findValid`: `expires_at > NOW()`), and logout
revokes by setting `revoked_at` rather than trusting the client to forget
the token.

## SQL injection — Implemented

Every repository uses PDO prepared statements with bound parameters
(`UserRepository`, `TokenRepository`, `CategoryRepository`,
`ProductRepository`). `PDO::ATTR_EMULATE_PREPARES => false`
(`Config/Database.php`) means MySQL receives real parameterized queries, not
values interpolated client-side before sending. The one place identifiers
(not values) enter a query — `ORDER BY` column/direction in
`ProductRepository::search` and the dynamic `SET` clause in
`CategoryRepository`/`ProductRepository::update` — is restricted to a fixed
PHP-side allowlist (`ProductQueryValidator::SORTABLE_COLUMNS`, and the
validator-controlled field keys for updates), never the raw client string,
because a placeholder can bind a value but not a column name.

## XSS — Not directly applicable, verified anyway

This API returns only `Content-Type: application/json`, never HTML — a JSON
response body isn't executed by a browser the way an HTML response would
be, so classic reflected/stored XSS (injecting a `<script>` tag rendered
into a page) has no delivery mechanism here. The genuine residual risk moves
to whatever renders this data later: a future React client must still escape
product names/descriptions when displaying them (React does this by default
for text content) — that's the client's responsibility, not something this
API can enforce.

## CORS — Implemented

`public/index.php` reflects `Access-Control-Allow-Origin` only for an origin
present in the `.env` `CORS_ALLOWED_ORIGINS` allowlist — never `*`. Verified
live: an allowed origin gets the header back, `http://evil.example.com` gets
nothing. Preflight `OPTIONS` requests are answered directly.

## CSRF — Not applicable to this authentication design

CSRF exploits *ambient* credentials a browser attaches automatically
(cookies). This API's tokens live in an `Authorization: Bearer` header,
which a browser never attaches on its own — a malicious site's forged
`<form>` or `fetch()` cannot make the victim's browser add that header
without JavaScript that CORS itself would have to permit first. No CSRF
token is implemented because there is no ambient-credential surface for it
to protect.

## Input validation — Implemented

Every write endpoint validates server-side regardless of what the client
claims to have already checked (`AuthValidator`, `CategoryValidator`,
`ProductValidator`, `ProductQueryValidator`) — required fields, types,
length limits, numeric bounds (price/quantity ≥ 0), and query parameter
shape (page/limit/sort).

## Mass assignment — Implemented

Controllers never do `foreach ($body as $key => $value)`. Every field a
repository writes comes from a Validator's explicit, named return array
(e.g. `ProductValidator::validate` only ever returns `name`, `sku`,
`category_id`, `price`, `quantity`, `description` — never `id` or
`created_at`, even if the client's JSON body included them).

## IDOR (Insecure Direct Object Reference) — Implemented for this project's data model

Products and categories are not user-owned in this schema — any
authenticated user managing inventory is expected to manage all of it (this
is an internal inventory tool, not a multi-tenant SaaS). So there is no
"can user A see user B's product" boundary to violate yet. Every `{id}`
lookup does check *existence* uniformly (`NOT_FOUND` for a missing id,
same shape whether the id never existed or was just deleted) rather than
leaking which case is true. If resources become user- or tenant-scoped
later, every repository `find()` would need a `WHERE owner_id = :userId`
clause added — flagging that as the concrete follow-up, not implementing it
speculatively now.

## Rate limiting — Production hardening consideration (not implemented)

Named honestly as a gap. A meaningful rate limiter needs state shared across
requests/processes (Redis, Memcached, or a dedicated gateway) — a per-request
PHP script has no memory of the previous request. Building a DB-backed
counter would add real latency and complexity for a threat model (a learning
project's local/dev API) that doesn't currently justify it. Production
deployment would add this at the web-server/reverse-proxy layer (e.g. nginx
`limit_req`) or an API gateway, rather than in application code.

## Information leakage — Implemented

The global exception handler (`public/index.php`) returns a generic
`INTERNAL_SERVER_ERROR` with no stack trace, query text, file path, or
exception message for anything that isn't a deliberately-thrown
`ApiException` — verified by design: only `ApiException`s (which every
Service/Validator constructs with a safe, pre-written message) ever reach
the client with detail.

## Error leakage — Implemented

Same mechanism as above. `error_log()` receives the real
message/file/line for genuine bugs; the HTTP response never does.

## Secrets — Implemented

No password, connection string, or token appears hardcoded in any `src/` or
`public/` file — all come from `Env::get()`, reading `.env`.

## Environment configuration — Implemented

`.env` is git-ignored (`.gitignore`); `.env.example` documents every
variable with placeholder/non-secret values and is the only one meant to be
committed.

## Database permissions — Production hardening consideration

Local development connects as `root` with the machine's existing empty local
password (matching this machine's other local projects). A real deployment
should use a dedicated MySQL user with only the privileges this app actually
needs (`SELECT`/`INSERT`/`UPDATE`/`DELETE` on `inventory_api`'s tables — no
`DROP`, `CREATE USER`, or cross-database access), created via `GRANT`. Not
done here because it's a deployment-environment concern, not something a
local dev database benefits from.

---

## Summary

| Area | Status |
|---|---|
| Authentication, password storage, token security | Implemented |
| SQL injection defense | Implemented |
| CORS | Implemented |
| Input validation, mass assignment | Implemented |
| Error/information leakage | Implemented |
| Secrets/environment config | Implemented |
| Authorization, IDOR | Implemented for this project's single-role, non-tenant data model |
| CSRF | Not applicable to header-token auth |
| XSS | Not applicable to a JSON-only API; pushed to the future client |
| Rate limiting | Open — deployment/infrastructure concern |
| Database least-privilege | Open — deployment concern, not a local-dev one |
