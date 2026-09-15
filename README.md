# Inventory Management REST API

A backend-first REST API for managing product inventory — categories,
products, and token-based authentication — built as a learning project to
move from server-rendered PHP applications to designing an API a separate
client (Postman today, a React app later) consumes over HTTP + JSON.

There is no server-rendered dashboard. The API is the product.

## Features

- Token-based authentication (register / login / logout) with hashed,
  revocable, expiring tokens
- Full CRUD for categories and products, with the product → category
  relationship enforced at the database level
- Search, category filtering (by slug), allowlisted sorting, and pagination
  on the product listing endpoint
- Server-side validation on every write endpoint, independent of anything a
  client claims to have already checked
- A consistent JSON response envelope and a fixed set of error codes across
  every endpoint
- CORS configured against an explicit origin allowlist (never `*`)
- A written security review (`docs/SECURITY.md`) and a 50-assertion
  integration test suite (`tests/run.php`)

## Tech Stack

- PHP 8.4, plain (no framework)
- MySQL 8.4, accessed via PDO with prepared statements
- No Composer dependency in this environment (see **Notes on environment
  constraints** below) — PSR-4-style autoloading and `.env` loading are
  hand-rolled in ~15 and ~35 lines respectively; swapping in Composer later
  would require no change to any class

## Architecture

```
inventory-api/
├── public/index.php        # front controller: autoload, CORS, error handling, routing
├── src/
│   ├── Config/              # .env loading, PDO connection factory
│   ├── Controllers/          # thin HTTP <-> Service translation
│   ├── Services/               # business rules (uniqueness, category checks)
│   ├── Repositories/            # the only layer that writes SQL
│   ├── Validation/                # server-side input validation
│   ├── Middleware/                 # AuthMiddleware (bearer token check)
│   ├── Responses/                   # ApiResponse: the JSON envelope
│   ├── Routing/                      # Router: regex path matching
│   └── Support/                       # ApiException, AuthContext, Slug, RouteParams
├── database/
│   ├── migrations/                     # numbered .sql files
│   ├── migrate.php                      # tracks & applies new migrations
│   └── seeders/seed.php                  # sample categories/products
├── docs/
│   ├── API.md                             # full endpoint reference
│   ├── SECURITY.md                         # security review
│   └── postman_collection.json              # importable Postman collection
├── tests/run.php                            # integration test suite
└── .env.example
```

Request flow: `index.php` → `Router` → (`AuthMiddleware` if the route
requires it) → `Controller` → `Service` (validation + business rules) →
`Repository` (the only place SQL is written) → MySQL.

## API Endpoints

Full reference with request/response bodies and every error case:
**[docs/API.md](docs/API.md)**.

| Method | Path | Auth |
|---|---|---|
| POST | `/api/v1/auth/register` | — |
| POST | `/api/v1/auth/login` | — |
| POST | `/api/v1/auth/logout` | required |
| GET | `/api/v1/categories` | — |
| GET | `/api/v1/categories/{id}` | — |
| POST / PUT / PATCH / DELETE | `/api/v1/categories[/{id}]` | required |
| GET | `/api/v1/products` (search/filter/sort/pagination) | — |
| GET | `/api/v1/products/{id}` | — |
| POST / PUT / PATCH / DELETE | `/api/v1/products[/{id}]` | required |

## Database

4 tables: `users`, `personal_access_tokens`, `categories`, `products`.
Schema, keys, and indexes are defined in `database/migrations/`. Notably:
`products.category_id` is a required foreign key (`ON DELETE RESTRICT` —
a category with products can't be deleted), and both `products.sku` and
`categories.slug`/`name` carry unique constraints enforced by MySQL itself,
not just application code.

## Authentication

Opaque, random, SHA-256-hashed bearer tokens — not JWT, chosen specifically
because logout/revocation is a requirement, and a stateless JWT can't be
revoked without the same database-backed check an opaque token already
needs. See `docs/SECURITY.md` for the full reasoning and threat model.

## Installation

Requirements: PHP 8.4+ with `pdo_mysql`, `mbstring`, `fileinfo` extensions;
MySQL 8+.

```bash
git clone <repo-url>
cd inventory-api
cp .env.example .env      # edit DB credentials if needed
```

## Environment Variables

See `.env.example` for the full list with defaults:

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL connection |
| `CORS_ALLOWED_ORIGINS` | comma-separated list of browser origins allowed to call this API |
| `TOKEN_TTL_SECONDS` | bearer token lifetime (default 604800 = 7 days) |

`.env` is git-ignored; never commit it. Only `.env.example` (placeholder
values) is committed.

## Running Locally

```bash
# 1. Create the database and apply the schema
mysql -u root -e "CREATE DATABASE inventory_api CHARACTER SET utf8mb4;"
php database/migrate.php

# 2. (optional) load sample data
php database/seeders/seed.php

# 3. Start the API
php -S 127.0.0.1:8000 -t public public/index.php
```

The API is now at `http://127.0.0.1:8000/api/v1`.

## API Testing

**Postman / Thunder Client**: import `docs/postman_collection.json`. It
covers every endpoint plus the documented failure cases (validation errors,
duplicate SKU/email, invalid category, unauthorized/forbidden, not found,
malformed JSON) and auto-captures the auth token after login.

**curl**: every endpoint in `docs/API.md` includes a working `curl` example.

**Automated integration tests**:
```bash
php tests/run.php
```
Runs 50 assertions against a live server + database (start the dev server
first). Safe to re-run — it generates unique test data per run and cleans
up after itself.

## API Documentation

- [docs/API.md](docs/API.md) — every endpoint, method, auth requirement,
  request/response shape, and error codes
- [docs/postman_collection.json](docs/postman_collection.json) — importable
  Postman collection

## Security

Full written review: **[docs/SECURITY.md](docs/SECURITY.md)**. Summary:
password hashing, hashed/revocable tokens, parameterized SQL everywhere,
mass-assignment-safe field allowlisting, origin-allowlisted CORS (no `*`),
and centralized error handling that never leaks stack traces or query text
are implemented. Rate limiting and least-privilege database credentials are
explicitly named as open, deployment-stage considerations rather than
implemented here — see the doc for why.

## Future React Client

Every design decision — the JSON envelope, the error code scheme, CORS
configuration, and stateless bearer-token auth — assumes the next consumer
of this API is a React app on a different origin, not a server-rendered
page. No backend change should be required to build that client against
what already exists here.

## Future Improvements

Deliberately **not** built now, with the reasoning for why:

- **`stock_movements` table** — the current `products.quantity` is a single
  mutable number. A real inventory system often wants a movement ledger
  (stock in/out/adjustment, with reason and timestamp) so quantity changes
  are auditable and reconstructable. Not built in v1 because nothing here
  yet needs that history — it's the clearest candidate for the first "real"
  feature added after this project, and is exactly where a database
  transaction (`BEGIN` → insert movement → update quantity → `COMMIT`)
  would first become genuinely necessary.
- **Rate limiting** — needs shared state across requests (Redis, or a
  reverse-proxy layer), which a bare PHP script doesn't have.
- **Role-based authorization** — every authenticated user currently has
  equal access; a "manager vs. viewer" split would be a natural next step
  if this stopped being a single-operator tool.
- **PHPUnit** — not installed because Composer isn't available in this
  environment; `tests/run.php` covers the same ground via HTTP integration
  tests instead. Worth revisiting once Composer is set up.
- **OpenAPI/Swagger spec** — `docs/API.md` is hand-written Markdown; an
  OpenAPI YAML file would make the contract machine-readable (for
  client-code generation, e.g.) but wasn't necessary to prove the API works.

## Notes on environment constraints

This project was built in an environment without Composer or the PHP `curl`
extension available. Two pragmatic substitutions were made, both documented
in code comments at the point they matter:

1. **Autoloading and `.env` loading** are hand-rolled (`public/index.php`,
   `src/Config/Env.php`) instead of using Composer's generated autoloader
   and `vlucas/phpdotenv`. Functionally equivalent for this project's scope.
2. **Integration tests** (`tests/run.php`) use PHP's stream-context HTTP
   client instead of the `curl` extension or PHPUnit + Guzzle.

Neither substitution changes the API's behavior or contract — only how the
project's own tooling is implemented.
