# Postieri Perfex API

A production-grade REST API + webhooks module for [Perfex CRM](https://www.perfexcrm.com) (3.2+).

Built by [Postieri XYZ L.L.C.](https://postieri.xyz) for internal automation (email follow-ups, invoice generation, subscription alerts) and released as a productizable module.

## Features

- 🔑 **Token-based auth** with bcrypt/Argon2id hashes, scoped permissions, and rotation
- 📊 **Resource coverage**: customers, contacts, invoices, subscriptions, leads, projects, tasks, tickets (read)
- 📄 **PDF generation** via Perfex's native renderer
- 🪝 **Webhooks dispatcher** with HMAC-SHA256 signatures and exponential-backoff retries
- ⏱️ **Rate limiting**: 100 req/min and 1000 req/hour per token (sliding window)
- 📚 **OpenAPI 3.1** spec, Postman collection, Python SDK
- 🔒 **Security defaults**: Argon2id, prepared statements, constant-time checks, scope checks
- 🧩 **Standard Perfex module structure** — drop into `modules/postieri_api/` and activate

## Requirements

- **Perfex CRM** 3.2 or newer (tested on 3.4.1)
- **PHP** 8.1, 8.2, or 8.3
- **MySQL** 5.7+ / MariaDB 10.3+
- **Guzzle** 7 (auto-installed via composer)
- **PHP extensions**: `json`, `mbstring`, `pdo`, `pdo_mysql`

## Installation

### Option A — Production (drop into existing Perfex)

1. **Clone or download** this repo:
   ```bash
   cd <perfex-root>/modules/
   git clone https://github.com/postierixyz/postieri-perfex-api.git postieri_api
   ```

2. **Install composer dependencies** (from the new module folder):
   ```bash
   cd postieri_api
   composer install --no-dev
   ```

3. **Activate** the module in Perfex admin:
   - Setup → Modules → Postieri API → **Activate**
   - The activation creates 4 database tables: `tblpostieri_api_tokens`, `tblpostieri_api_webhooks`, `tblpostieri_api_webhook_deliveries`, `tblpostieri_api_rate_log`

4. **Issue your first token** (admin only):
   ```bash
   curl -X POST https://your-perfex.example.com/api/v1/auth/token \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@yourcompany.com","password":"admin_password"}'
   ```
   Save the returned `data.token` — it is shown **once** and never again.

### Option B — Local development (Docker)

```bash
git clone https://github.com/postierixyz/postieri-perfex-api.git
cd postieri-perfex-api
# Mount this directory into your Perfex's modules/ path
# (See docs/development.md for the recommended docker-compose setup)
```

## Configuration

Settings → API → **Postieri API**:
- **API Enabled** (default: on)
- **Rate limit per minute** (default: 100)
- **Rate limit per hour** (default: 1000)
- **Webhook signing secret** (default: auto-generated, change to rotate)

## Quick example

```bash
# Issue a token (admin)
curl -X POST https://perfex.example.com/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"secret"}'

# Use the token to list customers
curl https://perfex.example.com/api/v1/customers?per_page=5 \
  -H "Authorization: Bearer <paste-token-here>"

# Get invoice PDF
curl https://perfex.example.com/api/v1/invoices/123/pdf \
  -H "Authorization: Bearer <paste-token-here>" \
  -o invoice_123.pdf
```

Full reference: see [docs/openapi.yaml](docs/openapi.yaml) (OpenAPI 3.1).

## Python SDK

```bash
pip install requests  # only dep
```

```python
from sdk.python.perfex_client import PerfexClient

client = PerfexClient(
    base_url="https://perfex.example.com",
    token="...",
)

customers = client.list_customers(search="Acme")
pdf_bytes = client.get_invoice_pdf(123)
```

See [sdk/python/perfex_client.py](sdk/python/perfex_client.py).

## Webhooks

Subscribe to events and receive HMAC-signed POSTs to your endpoint:

```bash
curl -X POST https://perfex.example.com/api/v1/webhooks/create \
  -H "Authorization: Bearer <admin-token>" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"Postieri CRM sync",
    "url":"https://crm.postieri.xyz/webhook",
    "events":["invoice.paid","subscription.expiring","lead.converted"]
  }'
```

Available events:
- `invoice.created`
- `invoice.paid`
- `subscription.expiring` (polled daily by cron)
- `subscription.expired`
- `lead.created`
- `lead.converted`

### Verifying signatures

Your endpoint receives `X-Postieri-Signature: <hex-hmac-sha256>` and the raw body. Verify:

```python
import hmac, hashlib
expected = hmac.new(secret.encode(), request.body, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, request.headers["X-Postieri-Signature"]):
    abort(401)
```

### Cron setup for `subscription.expiring`

Perfex's built-in cron is fine, but to make it bulletproof, set up a daily cron job:

**Plesk → Tools & Settings → Scheduled Tasks**:
```
0 6 * * * /usr/bin/php <perfex-root>/index.php postieri_api/cron/subscriptions
```

Or simpler (just hit an admin-only URL):
```
0 6 * * * curl -s "https://perfex.example.com/api/internal/cron/subscriptions?key=<cron-secret>"
```

## Architecture

See [docs/adr/0001-auth-routing-versioning.md](docs/adr/0001-auth-routing-versioning.md) for the design decisions.

## Development

```bash
# Install dev deps
composer install

# Run unit tests
composer test

# Static analysis
composer analyse

# Code style
composer cs-fix
```

## Contributing

Issues and PRs welcome. The implementation plan lives at [docs/plans/2026-06-01-postieri-perfex-api.md](docs/plans/2026-06-01-postieri-perfex-api.md) — when starting a task, reference the phase and task number.

## License

MIT — see [LICENSE](LICENSE).

## Credits

Built by [Luan Kërleshi](https://github.com/postierixyz) and Hermes at [Postieri XYZ L.L.C.](https://postieri.xyz), Gjakovë, Kosovo.
