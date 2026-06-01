"""
postieri_perfex — Python client for the Postieri Perfex API.

Usage:
    from postieri_perfex import PerfexClient

    client = PerfexClient(
        base_url="https://perfex.example.com",
        token="pt_xxxxxxxx...",
    )

    # List customers
    customers = client.customers.list(search="acme", page=1, per_page=25)

    # Create an invoice
    inv = client.invoices.create(
        customer_id=42,
        date="2026-06-01",
        due_date="2026-06-15",
        items=[{"description": "Hosting", "qty": 1, "rate": 50.0}],
    )

    # Download a PDF
    pdf_bytes = client.invoices.pdf(inv["id"])

    # Subscribe to webhooks
    hook = client.webhooks.create(
        name="My App",
        url="https://my-app.example.com/webhook",
        events=["invoice.paid", "subscription.expiring"],
    )

For full docs, see: https://github.com/postierixyz/postieri-perfex-api
"""

from __future__ import annotations

import json
import time
from typing import Any, Iterable
from urllib import error, parse, request


__all__ = ["PerfexClient", "PerfexError", "PerfexRateLimitedError"]


class PerfexError(Exception):
    """Base exception for Postieri Perfex API errors."""

    def __init__(self, message: str, *, code: str | None = None,
                 status: int | None = None, details: Any = None):
        super().__init__(message)
        self.code = code
        self.status = status
        self.details = details


class PerfexRateLimitedError(PerfexError):
    """Raised when the server returns HTTP 429. `retry_after` is in seconds."""


class _Resource:
    """Base class for resource namespaces (customers, invoices, …)."""

    def __init__(self, client: "PerfexClient"):
        self._c = client

    def _list(self, path: str, **params) -> dict:
        return self._c._get(path, **params)

    def _get_one(self, path: str) -> dict:
        return self._c._get(path)

    def _create(self, path: str, body: dict) -> dict:
        return self._c._post(path, body)

    def _update(self, path: str, body: dict) -> dict:
        return self._c._put(path, body)

    def _delete(self, path: str) -> None:
        self._c._delete(path)


class _CustomerResource(_Resource):
    def list(self, *, search: str | None = None, page: int = 1, per_page: int = 25) -> dict:
        return self._list("customers", q=search, page=page, per_page=per_page)

    def get(self, customer_id: int) -> dict:
        return self._get_one(f"customers/{customer_id}")

    def create(self, **fields) -> dict:
        return self._create("customers/create", fields)

    def update(self, customer_id: int, **fields) -> dict:
        return self._update(f"customers/{customer_id}/update", fields)

    def delete(self, customer_id: int) -> None:
        self._delete(f"customers/{customer_id}/delete")


class _ContactResource(_Resource):
    def list(self, *, customer_id: int | None = None, search: str | None = None,
             page: int = 1, per_page: int = 25) -> dict:
        return self._list("contacts", customer_id=customer_id, q=search,
                          page=page, per_page=per_page)

    def get(self, contact_id: int) -> dict:
        return self._get_one(f"contacts/{contact_id}")

    def create(self, **fields) -> dict:
        return self._create("contacts/create", fields)

    def update(self, contact_id: int, **fields) -> dict:
        return self._update(f"contacts/{contact_id}/update", fields)

    def delete(self, contact_id: int) -> None:
        self._delete(f"contacts/{contact_id}/delete")


class _InvoiceResource(_Resource):
    def list(self, *, customer_id: int | None = None, status: int | None = None,
             page: int = 1, per_page: int = 25) -> dict:
        return self._list("invoices", customer_id=customer_id, status=status,
                          page=page, per_page=per_page)

    def get(self, invoice_id: int) -> dict:
        return self._get_one(f"invoices/{invoice_id}")

    def create(self, **fields) -> dict:
        return self._create("invoices/create", fields)

    def update(self, invoice_id: int, **fields) -> dict:
        return self._update(f"invoices/{invoice_id}/update", fields)

    def pdf(self, invoice_id: int) -> bytes:
        return self._c._get_raw(f"invoices/{invoice_id}/pdf")


class _SubscriptionResource(_Resource):
    def list(self, *, customer_id: int | None = None, status: str | None = None,
             page: int = 1, per_page: int = 25) -> dict:
        return self._list("subscriptions", customer_id=customer_id, status=status,
                          page=page, per_page=per_page)

    def get(self, subscription_id: int) -> dict:
        return self._get_one(f"subscriptions/{subscription_id}")


class _LeadResource(_Resource):
    def list(self, *, search: str | None = None, status: str | None = None,
             page: int = 1, per_page: int = 25) -> dict:
        return self._list("leads", q=search, status=status, page=page, per_page=per_page)

    def get(self, lead_id: int) -> dict:
        return self._get_one(f"leads/{lead_id}")

    def create(self, **fields) -> dict:
        return self._create("leads/create", fields)

    def update(self, lead_id: int, **fields) -> dict:
        return self._update(f"leads/{lead_id}/update", fields)

    def delete(self, lead_id: int) -> None:
        self._delete(f"leads/{lead_id}/delete")

    def convert(self, lead_id: int) -> dict:
        return self._c._post(f"leads/{lead_id}/convert", {})


class _WebhookResource(_Resource):
    def list(self) -> list[dict]:
        return self._list("webhooks")["data"]

    def get(self, webhook_id: int) -> dict:
        return self._get_one(f"webhooks/{webhook_id}")

    def create(self, *, name: str, url: str, events: list[str],
               is_active: bool = True, secret: str | None = None) -> dict:
        body: dict[str, Any] = {
            "name": name, "url": url, "events": events,
            "is_active": 1 if is_active else 0,
        }
        if secret:
            body["secret"] = secret
        return self._create("webhooks/create", body)

    def update(self, webhook_id: int, **fields) -> dict:
        return self._update(f"webhooks/{webhook_id}/update", fields)

    def delete(self, webhook_id: int) -> None:
        self._delete(f"webhooks/{webhook_id}/delete")

    def deliveries(self, webhook_id: int, page: int = 1, per_page: int = 25) -> dict:
        return self._list(f"webhooks/{webhook_id}/deliveries",
                          page=page, per_page=per_page)


class _AuthResource(_Resource):
    def issue_token(self, *, email: str, password: str, name: str = "API token") -> dict:
        return self._c._post("auth/token", {
            "email": email, "password": password, "name": name,
        })

    def list_tokens(self) -> list[dict]:
        return self._list("auth/tokens")["data"]

    def revoke_token(self, token_id: int) -> None:
        self._delete(f"auth/token/{token_id}")


class PerfexClient:
    """Synchronous client for the Postieri Perfex API."""

    def __init__(
        self,
        base_url: str,
        token: str | None = None,
        *,
        email: str | None = None,
        password: str | None = None,
        timeout: float = 30.0,
        max_retries: int = 3,
        user_agent: str = "postieri-perfex-python/0.1.0",
    ):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self.max_retries = max_retries
        self.user_agent = user_agent

        if not token and email and password:
            self.token = self._issue_from_credentials(email, password)

        # Resource namespaces
        self.customers     = _CustomerResource(self)
        self.contacts      = _ContactResource(self)
        self.invoices      = _InvoiceResource(self)
        self.subscriptions = _SubscriptionResource(self)
        self.leads         = _LeadResource(self)
        self.webhooks      = _WebhookResource(self)
        self.auth          = _AuthResource(self)

    # ------------------------------------------------------------------
    # Internal HTTP plumbing
    # ------------------------------------------------------------------
    def _url(self, path: str) -> str:
        return f"{self.base_url}/api/v1/{path.lstrip('/')}"

    def _issue_from_credentials(self, email: str, password: str) -> str:
        data = self._post_no_auth("auth/token", {
            "email": email, "password": password,
        })
        return data["data"]["token"]

    def _request(self, method: str, path: str, *, body: Any = None,
                 auth: bool = True, raw: bool = False) -> Any:
        url = self._url(path)
        data = None
        headers = {"Accept": "application/json", "User-Agent": self.user_agent}
        if body is not None and not raw:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"
        if auth and self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        req = request.Request(url, data=data, method=method, headers=headers)
        for attempt in range(self.max_retries + 1):
            try:
                with request.urlopen(req, timeout=self.timeout) as resp:
                    status = resp.status
                    content_type = resp.headers.get("Content-Type", "")
                    payload = resp.read()
                    if raw or "application/pdf" in content_type:
                        return payload
                    if status == 204 or not payload:
                        return None
                    out = json.loads(payload)
                    if isinstance(out, dict) and out.get("status") is True:
                        return out
                    err = out.get("error", {}) if isinstance(out, dict) else {}
                    raise PerfexError(
                        err.get("message", "Unknown error"),
                        code=err.get("code"),
                        status=status,
                        details=err.get("details"),
                    )
            except error.HTTPError as e:
                status = e.code
                payload = e.read()
                try:
                    out = json.loads(payload)
                except Exception:
                    out = {}
                if status == 429:
                    retry_after = int(e.headers.get("Retry-After", "60"))
                    if attempt < self.max_retries:
                        time.sleep(retry_after)
                        continue
                    raise PerfexRateLimitedError(
                        "Rate limit exceeded",
                        code="rate_limited", status=429, details=out,
                    ) from e
                err = out.get("error", {}) if isinstance(out, dict) else {}
                raise PerfexError(
                    err.get("message", str(e)),
                    code=err.get("code"),
                    status=status,
                    details=err.get("details"),
                ) from e
            except error.URLError as e:
                if attempt < self.max_retries:
                    time.sleep(2 ** attempt)
                    continue
                raise PerfexError(f"Network error: {e.reason}") from e
        raise PerfexError("Exhausted retries")

    def _get(self, path: str, **params) -> dict:
        if params:
            qs = parse.urlencode({k: v for k, v in params.items() if v is not None})
            path = f"{path}?{qs}"
        return self._request("GET", path)

    def _get_raw(self, path: str) -> bytes:
        return self._request("GET", path, raw=True)

    def _post(self, path: str, body: dict) -> dict:
        return self._request("POST", path, body=body)

    def _post_no_auth(self, path: str, body: dict) -> dict:
        return self._request("POST", path, body=body, auth=False)

    def _put(self, path: str, body: dict) -> dict:
        return self._request("PUT", path, body=body)

    def _delete(self, path: str) -> None:
        self._request("DELETE", path)
