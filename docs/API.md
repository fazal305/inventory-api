# API Reference

Base URL: `http://127.0.0.1:8000/api/v1` (local dev)

Every response is `Content-Type: application/json` (except `204 No Content`,
which has no body) and uses one of these two envelopes:

**Success**
```json
{ "success": true, "data": { }, "meta": null }
```
`meta` is populated only on `GET /products` (pagination info); every other
endpoint returns `meta: null`.

**Error**
```json
{ "success": false, "error": { "code": "STRING_CODE", "message": "Human-readable text.", "details": null } }
```

---

## Auth

### `POST /auth/register`
No authentication required.

| | |
|---|---|
| Body | `{"name": string, "email": string, "password": string (min 8 chars)}` |
| Success | `201 Created`, the created user (never includes `password_hash`) |
| Errors | `422 VALIDATION_ERROR` (per-field details) · `409 DUPLICATE_EMAIL` |

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Ada Lovelace","email":"ada@example.com","password":"secret123"}'
```
```json
{"success":true,"data":{"id":1,"name":"Ada Lovelace","email":"ada@example.com","created_at":"...","updated_at":"..."},"meta":null}
```

### `POST /auth/login`
No authentication required.

| | |
|---|---|
| Body | `{"email": string, "password": string}` |
| Success | `200 OK`, `{"token": string, "expires_at": ISO-8601}` |
| Errors | `422 VALIDATION_ERROR` · `401 INVALID_CREDENTIALS` (same error for unknown email or wrong password, deliberately — see README Security section) |

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"ada@example.com","password":"secret123"}'
```
```json
{"success":true,"data":{"token":"a1b2c3...","expires_at":"2026-09-22T14:54:56+00:00"},"meta":null}
```

### `POST /auth/logout`
Requires `Authorization: Bearer <token>`.

| | |
|---|---|
| Success | `204 No Content` |
| Errors | `401 UNAUTHORIZED` (missing/invalid/expired/already-revoked token) |

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/logout -H "Authorization: Bearer <token>"
```

---

## Categories

Reads are public. Writes require `Authorization: Bearer <token>`.

### `GET /categories`
Returns all categories, no pagination (small, fixed-size resource).

### `GET /categories/{id}`
| Errors | `400 INVALID_ID` (non-numeric id) · `404 NOT_FOUND` |

### `POST /categories` — auth required
| Body | `{"name": string (required, ≤100 chars), "description": string\|null}` |
| Success | `201 Created` — `slug` is generated from `name` automatically |
| Errors | `422 VALIDATION_ERROR` · `409 DUPLICATE_CATEGORY` |

```bash
curl -X POST http://127.0.0.1:8000/api/v1/categories \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"name":"Home & Garden","description":"Yard and home items."}'
```
```json
{"success":true,"data":{"id":5,"name":"Home & Garden","slug":"home-garden","description":"Yard and home items.","created_at":"...","updated_at":"..."},"meta":null}
```

### `PUT /categories/{id}` — auth required
Full replace — `name` is required (same as POST). Renaming regenerates `slug`.
Errors: `400 INVALID_ID` · `404 NOT_FOUND` · `422 VALIDATION_ERROR` · `409 DUPLICATE_CATEGORY`

### `PATCH /categories/{id}` — auth required
Partial update — any subset of `name`/`description`, at least one required.
Same error set as PUT, plus `422` for an empty body.

### `DELETE /categories/{id}` — auth required
| Success | `204 No Content` |
| Errors | `400 INVALID_ID` · `404 NOT_FOUND` · `409 CATEGORY_HAS_PRODUCTS` (`details.product_count` tells you how many) |

---

## Products

Reads are public. Writes require `Authorization: Bearer <token>`.

### `GET /products`
Supports search, filtering, sorting, and pagination together.

| Query param | Notes |
|---|---|
| `search` | substring match against `name` |
| `category` | category **slug** (not id or name) — an unknown slug returns an empty page, not `404` |
| `sort` | one of `name`, `price`, `quantity`, `created_at`; prefix with `-` for descending (e.g. `-price`); anything else → `422` |
| `page` | positive integer, default `1`; non-numeric → `422` |
| `limit` | positive integer, default `20`, capped at `100` (an oversized value is silently capped, not rejected); non-positive → `422` |

Response includes pagination `meta`:
```json
{
  "success": true,
  "data": [ ],
  "meta": { "page": 1, "limit": 20, "total": 26, "totalPages": 2 }
}
```

```bash
curl "http://127.0.0.1:8000/api/v1/products?category=electronics&sort=-price&page=1&limit=10"
```

### `GET /products/{id}`
Errors: `400 INVALID_ID` · `404 NOT_FOUND`

### `POST /products` — auth required
| Body | `{"name": string, "sku": string (unique), "category_id": int (must exist), "price": number ≥ 0, "quantity": int ≥ 0, "description": string\|null}` |
| Success | `201 Created` |
| Errors | `422 VALIDATION_ERROR` · `422 INVALID_CATEGORY` (`category_id` doesn't reference an existing category) · `409 DUPLICATE_SKU` |

```bash
curl -X POST http://127.0.0.1:8000/api/v1/products \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"name":"Wireless Mouse","sku":"SKU-2001","category_id":1,"price":29.99,"quantity":50}'
```

### `PUT /products/{id}` — auth required
Full replace, all fields required. Same error set as POST plus `400 INVALID_ID` / `404 NOT_FOUND`.

### `PATCH /products/{id}` — auth required
Partial update, at least one field required, whatever is present is fully re-validated (including SKU uniqueness and category existence).

### `DELETE /products/{id}` — auth required
`204 No Content` on success; `400 INVALID_ID` · `404 NOT_FOUND` otherwise.

---

## Protocol-level responses (apply to every route)

| Situation | Response |
|---|---|
| Unknown path | `404 ROUTE_NOT_FOUND` |
| Known path, wrong method | `405 METHOD_NOT_ALLOWED` with an `Allow` header listing valid methods |
| Malformed JSON body | `400 INVALID_JSON` |
| Unhandled server error | `500 INTERNAL_SERVER_ERROR` (never leaks internals — see `docs/SECURITY.md`) |
