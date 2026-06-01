# ADR-0001: Authentication, Routing, and Versioning

> **Status:** Accepted
> **Date:** 2026-06-01
> **Deciders:** Luan Kërleshi, Hermes
> **Context:** First architecture decision for the `postieri_api` Perfex module. Establishes auth, routing, response shape, webhooks, and rate limiting conventions.

---

## Context

We are building a custom REST API module for Perfex CRM v3.4.1 (self-hosted, PHP 8.1+, MySQL). The module must:

1. Expose a stable, well-documented API surface to Postieri's internal pipeline (Python wrapper, email automation, Cron jobs).
2. Eventually be productizable for other Perfex shops (competing with Fezoo's CodeCanyon module, item 23714258).
3. Support webhooks for event-driven automation (specifically `subscription.expiring`, `invoice.paid`, `lead.converted`).
4. Coexist with — not break — existing Perfex functionality.

Perfex has **no public REST API** by default. The de facto third-party module (Fezoo) uses a simple `authtoken` header without versioning, pagination, or rotation. We will do better.

## Decisions

### D1. URL versioning — `/api/v1/...`

- **Decision:** All endpoints live under `/api/v1/{resource}`.
- **Rationale:** Fezoo uses `/api/{resource}` with no version prefix. This is a known anti-pattern: any breaking change forces a forked codebase. We add `/v1/` from day 1 so we can ship `/v2/` later without breaking clients.
- **Consequence:** Slightly longer URLs. Easily worth it.

### D2. Authentication — Bearer token, scoped, rotatable

- **Decision:** `Authorization: Bearer <token>` header. Tokens are 64-char hex strings (`bin2hex(random_bytes(32))`), stored hashed (Argon2id via `password_hash`). Each token has `scopes` (e.g. `customers:read`, `invoices:write`), an `expires_at`, and a `revoked_at`.
- **Rationale:** Fezoo's `authtoken` header is non-standard and tokens are unscoped (full user access). Bearer is the RFC 6750 standard, supported by every HTTP client. Argon2id is the OWASP-recommended hash. Scoped tokens enable least-privilege. `revoked_at` enables kill-switch.
- **Consequence:** More implementation work upfront, but a stronger product and a real differentiator.
- **Out of scope for v1:** OAuth2 server, JWT, token refresh flow. We may add OAuth2 in v2 if productization requires it.

### D3. Response envelope — `{ status, data, meta, error }`

- **Decision:** All responses use a consistent envelope:
  ```json
  { "status": true, "data": {...}, "meta": { "page": 1, "per_page": 25, "total": 142 } }
  ```
  Errors:
  ```json
  { "status": false, "error": { "code": "validation_failed", "message": "...", "details": {...} } }
  ```
- **Rationale:** Fezoo returns flat responses with ad-hoc error shapes. A consistent envelope simplifies client code, makes pagination universal, and signals professionalism.
- **Consequence:** Clients must unwrap `data` and `meta`. Trivial cost.

### D4. Pagination — `?page=&per_page=`

- **Decision:** All list endpoints accept `?page=N&per_page=M` (default 25, max 100). Response includes `meta.page`, `meta.per_page`, `meta.total`, `meta.total_pages`.
- **Rationale:** Fezoo has no pagination on most endpoints — a known pain point on large datasets. We paginate from day 1.
- **Consequence:** Slightly more complex controllers. Worth it.

### D5. Rate limiting — 100 req/min/token, 429 on exceed

- **Decision:** Sliding window, 100 req/min/token, 1000 req/hour/token. Headers `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`. HTTP 429 with `Retry-After` when exceeded.
- **Storage:** Perfex DB table `tblpostieri_api_rate_log`. No Redis dependency.
- **Rationale:** Self-contained, no infra additions. Fezoo has no rate limit; ours is a competitive feature.

### D6. Webhooks — first-class, event-driven + cron-polled

- **Decision:** Subscribers register via `POST /api/v1/webhooks` with `{url, events[]}`. Delivery via `WebhookDispatcher` (Guzzle). Failed deliveries retried with exponential backoff (1m, 5m, 30m, 2h, 12h), logged in `tblpostieri_api_webhook_deliveries`.
- **Events emitted via Perfex hooks:** `invoice.paid`, `invoice.created`, `customer.created`, `customer.updated`, `lead.created`, `lead.converted_to_customer`, `task.status_changed`, `project.status_changed`, `ticket.opened`, `ticket.closed`.
- **Cron-polled events:** `subscription.expiring` (no native Perfex hook — daily cron checks `next_billing_cycle <= NOW() + 7 days`), `subscription.expired`.
- **Rationale:** Webhooks are the biggest differentiator vs Fezoo (sparse event coverage). `subscription.expiring` is critical for Postieri's email automation use case.
- **Consequence:** A small daily cron job must be installed. We document this in the module's install instructions.

### D7. Module location — `modules/postieri_api/` (root-level)

- **Decision:** Module lives at `<perfex-root>/modules/postieri_api/`. **Not** `application/modules/`.
- **Rationale:** Confirmed in Perfex 3.4.1 source: `define('APP_MODULES_PATH', FCPATH . 'modules/')` in `application/config/constants.php`. `application/modules/` does not exist. The original skill had this wrong — fixed 2026-06-01.
- **Consequence:** Module is installed by copying to `modules/postieri_api/` and activating via `Setup → Modules`.

### D8. Database tables — `tblpostieri_api_*`

- **Decision:** All module tables prefixed `tblpostieri_api_` (e.g. `tblpostieri_api_tokens`, `tblpostieri_api_webhooks`, `tblpostieri_api_webhook_deliveries`, `tblpostieri_api_rate_log`).
- **Rationale:** Avoids collision with Fezoo's `tblperfex_api_tokens` (if both modules coexist) and clearly delineates module ownership.
- **Consequence:** Longer table names. Trivial cost.

## Out of Scope for v1

The following are explicitly **not** in v1:

- OAuth2 server / third-party app authorization
- JWT tokens
- GraphQL endpoint
- WebSocket / streaming
- Multi-tenant isolation
- API key rotation without re-auth
- Request signing (HMAC)
- IP allowlisting

These may be added in v2 if productization demands them.

## References

- RFC 6750 — OAuth 2.0 Bearer Token Usage: https://datatracker.ietf.org/doc/html/rfc6750
- OWASP Password Storage Cheat Sheet (Argon2id): https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
- Fezoo module reference: see `business/perfex-crm-integration/references/fezoo-module-reference.md`
- Perfex 3.4.1 source (cloned): `/workspace/company/perfex-source/perfex_crm/`
