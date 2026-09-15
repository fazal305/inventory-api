# Deployment

## Live deployment

**API base URL: `https://inventory-api-guxd.onrender.com/api/v1`**

Deployed on [Render](https://render.com):
- **Web service**: Docker-based (Render has no native PHP runtime — see
  "Why Docker" below), free instance tier, deployed from
  [github.com/fazal305/inventory-api](https://github.com/fazal305/inventory-api)
  (`master` branch, auto-deploys on push).
- **Database**: Render's managed Postgres, free tier. Render's managed
  database offering is Postgres-only, which is why this project supports
  `DB_CONNECTION=pgsql` as well as MySQL — see
  `src/Config/Database.php` and `database/migrations/pgsql/`. MySQL remains
  the project's primary, fully-documented database (see README and
  `docs/SECURITY.md`); Postgres exists specifically because this host
  required it.

Verified with the full 50-assertion integration suite run directly against
the live URL:
```bash
TEST_BASE_URL="https://inventory-api-guxd.onrender.com/api/v1" php tests/run.php
```
Result: **50 passed, 0 failed** — real HTTPS, real Cloudflare-fronted
infrastructure, real production database, not a local simulation.

### Known limitations of the free tier (accepted deliberately)

- **Cold starts**: the free web service spins down after inactivity;
  the first request after idle time can take 50+ seconds while it wakes up.
  Not fixable without a paid instance.
- **Database expiry**: Render's free Postgres expires 30 days after
  creation unless upgraded to a paid plan. Fine for a demo/portfolio
  project; would need addressing before any real long-term use.

## Why Docker (not native PHP)

Render's "New Web Service" language options are Docker, Elixir, Go, Node,
Python 3, Ruby, and Rust — no PHP. `Dockerfile` (repo root) is `php:8.2-cli`
with `pdo_mysql` and `pdo_pgsql` compiled in (the latter needs `libpq-dev`
installed first — the base image doesn't ship it, discovered from an actual
failed build log, not assumed). The `CMD` runs the exact same
`php -S 0.0.0.0:$PORT -t public public/index.php` command used throughout
local development — same router-script front-controller pattern, just
bound to `0.0.0.0` and Render's assigned `$PORT` instead of
`127.0.0.1:8000`.

`Procfile` and `nixpacks.toml` (also in the repo root) are leftover from an
earlier attempt to deploy on Railway (abandoned when that account's free
trial had already hit its 2-project limit from prior unrelated work) —
harmless, ignored by Render's Docker build, kept in case Railway is
revisited later.

## Environment variables actually set on Render

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | Render Postgres **internal** hostname (same-region private network — lower latency, no public egress needed since the web service and database are both in Render's Singapore region) |
| `DB_PORT` | `5432` |
| `DB_NAME`, `DB_USER`, `DB_PASS` | From the Render Postgres instance's own credentials |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:3000` (placeholder — update to the real deployed origin once a frontend exists; see README) |
| `TOKEN_TTL_SECONDS` | `604800` |

Set via Render's dashboard (Environment Variables), not a committed file —
consistent with the project's own rule of never committing real credentials.

## A real bug this deployment caught

`src/Config/Env.php` originally **threw** if no `.env` file existed. That's
correct for local dev (a missing `.env` there really is a setup mistake),
but broke every single request on Render, which has no `.env` file at all —
configuration comes entirely from the platform's own environment variable
injection, which `getenv()` already sees. Fixed to treat a missing file as
"nothing to load" instead of an error. Caught by actually hitting the
deployed health endpoint and getting a real `500`, not assumed — the fix
is `bb4a919` (see git log).

## What deploying required (general, host-agnostic)

1. A host that can run the app (Docker on Render, in this case).
2. A database — Postgres here, specifically because that's what this host's
   managed offering provides.
3. Real environment variables set on the host, not copied from local `.env`.
4. HTTPS — Render provides this by default via its own TLS termination
   (fronted by Cloudflare); no certificate setup needed on the app's end.
5. Migrations run once against the live database (`database/migrate.php`,
   with `DB_CONNECTION=pgsql` pointed at the production instance).
6. Verification against the *actual* deployed URL with real HTTP requests —
   not assumed to work because it worked locally (§50's own instruction).

## A host that did *not* work: InfinityFree

Before Render, InfinityFree (free PHP/MySQL shared hosting) was attempted
and abandoned after discovering — empirically, not assumed — that its
shared network layer serves a JavaScript anti-bot challenge in front of
**every** request without a browser-solved cookie, confirmed present on
every domain on that account, with no per-domain toggle to disable it. That
completely breaks a headless API: `curl`, Postman, this project's own test
suite, and a future React app's `fetch()` calls would all receive the
challenge page instead of JSON. Noted here so the reasoning for switching
hosts isn't lost.
