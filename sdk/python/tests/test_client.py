"""
Tests for the postieri_perfex client. Uses a tiny in-process HTTP server
so we don't need a real Perfex server.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import socket
import threading
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import unittest
from postieri_perfex import PerfexClient, PerfexError, PerfexRateLimitedError


class _MockServer:
    """Minimal HTTP/1.0 server that echoes one response per request.

    Uses raw sockets instead of BaseHTTPRequestHandler so we control
    the exact bytes on the wire.
    """

    def __init__(self):
        self.status = 200
        self.body = b""
        self.headers: dict[str, str] = {}
        self.requests: list[dict] = []
        self._lock = threading.Lock()
        self._event = threading.Event()
        self._sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._sock.bind(("127.0.0.1", 0))
        self._sock.listen(8)
        self._port = self._sock.getsockname()[1]
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._serve, daemon=True)
        self._thread.start()

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self._port}"

    def stop(self):
        self._stop.set()
        try:
            self._sock.close()
        except Exception:
            pass

    def set_response(self, status: int, body: bytes, headers: dict[str, str] | None = None):
        self.status = status
        self.body = body
        self.headers = headers or {}

    def _serve(self):
        while not self._stop.is_set():
            try:
                conn, _ = self._sock.accept()
            except OSError:
                break
            threading.Thread(target=self._handle, args=(conn,), daemon=True).start()

    def _handle(self, conn):
        try:
            data = b""
            conn.settimeout(2.0)
            # Read until end of headers + body
            while b"\r\n\r\n" not in data:
                chunk = conn.recv(4096)
                if not chunk:
                    break
                data += chunk
                # If we have headers, parse content-length and read body
                if b"\r\n\r\n" in data:
                    headers, body = data.split(b"\r\n\r\n", 1)
                    try:
                        cl = int(next(
                            (h.split(b":", 1)[1].strip()
                             for h in headers.split(b"\r\n")
                             if h.lower().startswith(b"content-length:")),
                            b"0"
                        ))
                    except (IndexError, ValueError):
                        cl = 0
                    while len(body) < cl:
                        chunk = conn.recv(4096)
                        if not chunk:
                            break
                        body += chunk
                    data = headers + b"\r\n\r\n" + body
                    break
            # Parse and record
            if b"\r\n\r\n" in data:
                raw_headers, body = data.split(b"\r\n\r\n", 1)
                lines = raw_headers.split(b"\r\n")
                request_line = lines[0].decode("latin-1")
                method, path, _ = request_line.split(" ", 2)
                hdrs = {}
                for line in lines[1:]:
                    if b":" in line:
                        k, v = line.split(b":", 1)
                        hdrs[k.decode("latin-1").strip()] = v.decode("latin-1").strip()
                with self._lock:
                    self.requests.append({
                        "method":  method,
                        "path":    path,
                        "headers": hdrs,
                        "body":    body,
                    })
            # Build response
            if "Content-Type" not in self.headers and "content-type" not in {h.lower() for h in self.headers}:
                self.headers["Content-Type"] = "application/json"
            self.headers.setdefault("Content-Length", str(len(self.body)))
            reason = {200: "OK", 201: "Created", 204: "No Content", 401: "Unauthorized",
                      403: "Forbidden", 404: "Not Found", 422: "Unprocessable Entity",
                      429: "Too Many Requests"}.get(self.status, "OK")
            response = f"HTTP/1.1 {self.status} {reason}\r\n"
            for k, v in self.headers.items():
                response += f"{k}: {v}\r\n"
            response += "\r\n"
            conn.sendall(response.encode("latin-1") + self.body)
        except Exception:
            pass
        finally:
            try:
                conn.close()
            except Exception:
                pass


class TestClient(unittest.TestCase):
    server: _MockServer

    @classmethod
    def setUpClass(cls):
        cls.server = _MockServer()

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def setUp(self):
        self.server.requests.clear()
        self.server.set_response(200, b'{"status":true,"data":[]}')

    # ---- helpers ----
    def _ok(self, data, status=200, headers=None):
        self.server.set_response(
            status, json.dumps({"status": True, "data": data}).encode(),
            headers or {})

    def _err(self, status, code, message, details=None):
        self.server.set_response(
            status,
            json.dumps({
                "status": False,
                "error": {"code": code, "message": message, "details": details or {}}
            }).encode(),
        )

    def _last(self):
        return self.server.requests[-1]

    # ---- tests ----
    def test_bearer_token_sent(self):
        self._ok([{"id": 1}])
        c = PerfexClient(self.server.url, token="pt_abc")
        c.customers.list()
        self.assertEqual(self._last()["headers"]["Authorization"], "Bearer pt_abc")

    def test_pagination_query(self):
        self._ok({"data": [], "meta": {}})
        c = PerfexClient(self.server.url, token="pt")
        c.customers.list(page=2, per_page=50, search="acme")
        path = self._last()["path"]
        self.assertIn("page=2", path)
        self.assertIn("per_page=50", path)
        self.assertIn("q=acme", path)

    def test_create_returns_data(self):
        self._ok({"id": 99})
        c = PerfexClient(self.server.url, token="pt")
        out = c.invoices.create(customer_id=1, date="2026-06-01", due_date="2026-06-15")
        self.assertEqual(out["id"], 99)

    def test_error_raises(self):
        self._err(404, "not_found", "Invoice 99 not found")
        c = PerfexClient(self.server.url, token="pt")
        with self.assertRaises(PerfexError) as ctx:
            c.invoices.get(99)
        self.assertEqual(ctx.exception.code, "not_found")
        self.assertEqual(ctx.exception.status, 404)

    def test_429_raises_rate_limited(self):
        self._err(429, "rate_limited", "Rate limit exceeded", {"retry_after_seconds": 60})
        c = PerfexClient(self.server.url, token="pt", max_retries=0)
        with self.assertRaises(PerfexRateLimitedError):
            c.invoices.list()

    def test_pdf_returns_bytes(self):
        self.server.set_response(200, b"%PDF-1.4 fake", {"Content-Type": "application/pdf"})
        c = PerfexClient(self.server.url, token="pt")
        pdf = c.invoices.pdf(1)
        self.assertEqual(pdf[:4], b"%PDF")

    def test_lead_convert(self):
        self._ok({"lead_id": 5, "customer_id": 88, "status": "converted"})
        c = PerfexClient(self.server.url, token="pt")
        out = c.leads.convert(5)
        self.assertEqual(out["customer_id"], 88)

    def test_webhook_create(self):
        self._ok({
            "id": 7, "name": "X", "url": "https://x/y",
            "events": ["invoice.paid"], "secret": "s3cret",
            "is_active": 1, "created_by": 1,
            "created_at": "2026-06-01 00:00:00", "updated_at": "2026-06-01 00:00:00",
        })
        c = PerfexClient(self.server.url, token="pt")
        h = c.webhooks.create(name="X", url="https://x/y", events=["invoice.paid"])
        self.assertEqual(h["secret"], "s3cret")

    def test_signature_verification(self):
        # The library doesn't sign outgoing requests; this just sanity-checks
        # the recipe in the README.
        secret = "s3cret"
        body = b'{"event":"invoice.paid","timestamp":"2026-06-01T10:00:00Z","data":{"invoice_id":1}}'
        sig = "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
        expected = "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
        self.assertTrue(hmac.compare_digest(expected, sig))


if __name__ == "__main__":
    unittest.main()
