# Deployment Requirements

This is a requirements writeup, not a deployment — per the project brief,
deploying prematurely (before the API is finished and reviewed) is worse
than not deploying yet. Nothing here has been provisioned; this documents
what a real deployment would need and why, so the decision to actually do
it is a deliberate one, made with a specific host in mind.

## What the API needs to run anywhere

| Requirement | Detail |
|---|---|
| PHP runtime | 8.4+ with `pdo_mysql`, `mbstring`, `fileinfo` extensions (same as local — see README) |
| MySQL | 8+, reachable from the PHP process |
| Environment variables | Every value in `.env.example` — set as real environment variables or a `.env` file **that is never committed** (see Security below) |
| HTTPS | Required in production. A bearer token sent over plain HTTP is readable by anyone on the network path — the entire point of a hashed, unguessable token is defeated if it's transmitted in the clear. Most hosts (a PaaS, or a reverse proxy like nginx with Let's Encrypt) provide this without changing the application. |
| Web server configuration | `public/` must be the document root, not the project root — nothing outside `public/index.php` should be reachable by URL (this is why `.env`, `src/`, `database/` all live outside `public/` already). On Apache/nginx, all requests need to route to `public/index.php` (a rewrite rule / `try_files`), the same job the PHP built-in dev server's router argument does locally. |
| CORS | Update `CORS_ALLOWED_ORIGINS` to the real deployed origin of the React client — not `localhost:3000`. |
| Database setup | Run `database/migrate.php` and, if desired, `database/seeders/seed.php` against the production database once, from a trusted machine with production credentials — never commit production credentials anywhere to make this "easier." |

## Security changes specific to deployment (not needed locally)

- **Database credentials**: local dev uses `root` with no password, matching
  every other local project on this machine (see `docs/SECURITY.md`,
  "Database permissions"). Production needs a dedicated MySQL user with only
  `SELECT`/`INSERT`/`UPDATE`/`DELETE` on the `inventory_api` schema — created
  with `GRANT`, not `root`.
- **`APP_DEBUG`**: not currently read by the application (there's no
  debug-mode branch in the error handler — it always returns the generic
  `500` regardless), but if that changes later, it must default to `false`
  and never be `true` in production; stack traces in a live error response
  are a real information-leakage vector (see `docs/SECURITY.md`).
- **Rate limiting**: flagged in `docs/SECURITY.md` as unimplemented at the
  application layer. A production deployment should add this at the
  reverse-proxy/gateway layer (e.g. nginx `limit_req`) rather than skip it
  entirely.

## What deploying would actually involve (when you're ready)

1. Choose a host that runs PHP (a VPS, or a PaaS like Railway/Render that
   supports PHP + MySQL, or shared hosting with SSH access).
2. Provision a MySQL instance there (managed database service, or one on
   the same host).
3. Set the environment variables from `.env.example` with real production
   values (never the local dev ones).
4. Point the web server's document root at `public/`.
5. Run migrations once against the production database.
6. Verify the *deployed* API actually works — hit its real URL with the
   same `curl`/Postman checks used locally, not just assume it works
   because it worked on this machine (§50: "verify the actual production
   API rather than assuming it works").

## Chosen host: free PHP/MySQL shared hosting (e.g. InfinityFree)

This kind of host has one structural constraint the requirements above
don't cover: **you can't point the document root at `public/`** — the
control panel gives you a fixed `htdocs/` folder as the only web-accessible
directory, with no option to change it. Everything else (`src/`, `database/`,
`.env`) needs to live *outside* `htdocs/` so it's never reachable by URL —
the same principle behind `public/` existing at all, just enforced by the
host instead of by web server config.

The fix is a two-line **bootstrap file**, not a second copy of the app:

```
home/                              (your FTP root — not all web-accessible)
├── htdocs/                        (the ONLY web-accessible folder)
│   ├── index.php                  (2 lines — see below)
│   └── .htaccess                  (copy of public/.htaccess)
└── inventory-api/                 (the entire project, uploaded as-is)
    ├── public/index.php           (the real front controller — unchanged)
    ├── src/ ...
    ├── database/ ...
    └── .env                       (production values — never the local ones)
```

`htdocs/index.php` is just:
```php
<?php
require __DIR__ . '/../inventory-api/public/index.php';
```
Because the real `public/index.php` still resolves its own `__DIR__`
correctly wherever it's `require`d from, every `../src/...`,
`../.env` path inside it keeps working with zero code changes — the
bootstrap file is the only host-specific artifact, not a fork of the app.

### Steps

1. **You create the account** — I can't sign up for a hosting account on
   your behalf (that's outside what I can do here). Sign up at your chosen
   free host, create a subdomain/hosting slot, and from its control panel:
   - create a MySQL database (note the DB host, name, username, password —
     free hosts almost always use a **different** MySQL host than
     `localhost`, e.g. `sqlXXX.infinityfree.com`)
   - find your FTP credentials (host, username, password)
2. **Hand me the connection details** (FTP host/user/pass, MySQL
   host/name/user/pass) — not your hosting account's login password, just
   these service credentials, the same way you'd hand any deploy tool its
   config.
3. I'll then:
   - upload `inventory-api/` (minus `.git`) via FTP
   - create `inventory-api/.env` on the server with the real DB credentials
     and the deployed origin in `CORS_ALLOWED_ORIGINS`
   - create `htdocs/index.php` (the bootstrap) and `htdocs/.htaccess`
   - run `database/migrate.php` (and `seed.php` if you want sample data)
     against the remote database
   - verify the *actual deployed* API with real HTTP requests — the same
     `curl` checks used throughout this project, now against the live URL

Nothing above has been done yet — this is the plan, waiting on step 1.
