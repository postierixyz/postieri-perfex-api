# Postieri Perfex — Python SDK

A small, dependency-free synchronous client for the [Postieri Perfex API](https://github.com/postierixyz/postieri-perfex-api).

## Install

```bash
pip install postieri-perfex
# or from source:
pip install git+https://github.com/postierixyz/postieri-perfex-api.git#subdirectory=sdk/python
```

## Quick start

```python
from postieri_perfex import PerfexClient

# Option 1: pass a long-lived token
client = PerfexClient(
    base_url="https://perfex.example.com",
    token="pt_xxxxxxxx...",
)

# Option 2: let the client issue a token for you (self-service flow)
client = PerfexClient(
    base_url="https://perfex.example.com",
    email="admin@example.com",
    password="...",
)
```

## Customers

```python
# List with pagination + search
page = client.customers.list(search="acme", page=1, per_page=25)
for c in page["data"]:
    print(c["id"], c["company"], c["active"])

# Get a single customer (with contacts)
c = client.customers.get(42)
print(c["contacts"])

# Create
new = client.customers.create(
    company="Acme Corp",
    firstname="John",
    lastname="Doe",
    email="john@acme.example",
    password="temp-password-123",
)
print("Created", new["id"])
```

## Invoices

```python
# Create
inv = client.invoices.create(
    customer_id=42,
    date="2026-06-01",
    due_date="2026-06-15",
    items=[{"description": "Hosting", "qty": 1, "rate": 50.0}],
)
print("Invoice", inv["number"], "totalling", inv["total"])

# Download the PDF
pdf_bytes = client.invoices.pdf(inv["id"])
with open(f"invoice-{inv['id']}.pdf", "wb") as f:
    f.write(pdf_bytes)
```

## Subscriptions (with status helpers)

```python
# All subscriptions expiring in the next 14 days
expiring = client.subscriptions.list(status="expiring")

for s in expiring["data"]:
    print(s["customer_company"], "ends in", s["days_until_renewal"], "days")
```

## Leads

```python
# Capture a lead from your website
client.leads.create(
    name="Jane Doe",
    email="jane@prospect.example",
    company="Prospect Inc",
    source="website",
    description="Filled contact form",
)

# Convert to customer
result = client.leads.convert(lead_id=123)
print("New customer id:", result["customer_id"])
```

## Webhooks

```python
hook = client.webhooks.create(
    name="My App",
    url="https://my-app.example.com/webhook",
    events=["invoice.paid", "subscription.expiring"],
)
print("Secret:", hook["secret"])  # save this — verify signatures with it

# List recent deliveries
deliveries = client.webhooks.deliveries(hook["id"], page=1, per_page=25)
for d in deliveries["data"]:
    print(d["event"], d["response_status"], d["attempt"])
```

## Verifying webhook signatures

Webhooks are signed with HMAC-SHA256. The signature is sent in the
`X-Postieri-Signature` header as `sha256=<hex>`. In your webhook endpoint:

```python
import hmac, hashlib

def verify(secret: str, body: bytes, header: str) -> bool:
    expected = "sha256=" + hmac.new(
        secret.encode(), body, hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, header)

# In Flask:
@app.post("/webhook")
def webhook():
    sig = request.headers.get("X-Postieri-Signature", "")
    if not verify(SECRET, request.data, sig):
        abort(401)
    event = request.headers["X-Postieri-Event"]
    payload = request.json
    # ... handle event
    return "", 204
```

## Error handling

```python
from postieri_perfex import PerfexClient, PerfexError, PerfexRateLimitedError

try:
    client.invoices.get(999)
except PerfexError as e:
    print(f"[{e.code}] {e.status}: {e}")
    # e.g. [not_found] 404: Invoice 999 not found
```

## License

MIT © Postieri XYZ L.L.C.
