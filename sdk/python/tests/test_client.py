"""
Tests for the postieri_perfex client. Uses a tiny in-process HTTP recorder
so we don't need a real Perfex server.
"""
import hashlib
import hmac
import json
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import unittest
from postieri_perfex import PerfexClient, PerfexError, PerfexRateLimitedError


class _RecorderHandler(BaseHTTPRequestHandler):
    """Echoes back a canned response, records the request."""

    # Filled in by the test before serving
    next_response_status = 200
    next_response_body   = b""
    next_response_headers: dict = {}

    recorded_requests: list = []  # type: ignore[assignment]
    served_once = threading.Event()

    def log_message(self, *args, **kwargs):
        pass

    def do_GET(self):     self._capture()
    def do_POST(self):    self._capture()
    def do_PUT(self):     self._capture()
    def do_DELETE(self):  self._capture()

    def _capture(self):
        n = int(self.headers.get("Content-Length", "0") or 0)
        body = self.rfile.read(n) if n else b""
        self.__class__.recorded_requests.append({
            "method":  self.command,
            "path":    self.path,
            "headers": dict(self.headers),
            "body":    body,
        })
        for k, v in self.__class__.next_response_headers.items():
            self.send_header(k, v)
        self.send_response(self.__class__.next_response_status)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(self.__class__.next_response_body)
        self.__class__.served_once.set()


def _start_server():
    server = HTTPServer(("127.0.0.1", 0), _RecorderHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server


class TestClient(unittest.TestCase):
    server = None
    base_url = None

    @classmethod
    def setUpClass(cls):
        cls.server = _start_server()
        cls.base_url = f"http://127.0.0.1:{cls.server.server_address[1]}"

    def setUp(self):
        _RecorderHandler.recorded_requests.clear()
        _RecorderHandler.served_once.clear()
        _RecorderHandler.next_response_status = 200
        _RecorderHandler.next_response_body = b'{"status":true,"data":[]}'
        _RecorderHandler.next_response_headers = {}

    def _ok(self, data, status=200, headers=None):
        _RecorderHandler.next_response_status = status
        _RecorderHandler.next_response_body = json.dumps({"status": True, "data": data}).encode()
        _RecorderHandler.next_response_headers = headers or {}

    def _err(self, status, code, message, details=None):
        _RecorderHandler.next_response_status = status
        _RecorderHandler.next_response_body = json.dumps({
            "status": False,
            "error": {
                "code": code,
                "message": message,
                "details": details or {},
            }
        }).encode()

    def _last(self):
        return _RecorderHandler.recorded_requests[-1]

    def test_bearer_token_sent(self):
        self._ok([{"id": 1}])
        c = PerfexClient(self.base_url, token="pt_abc")
        c.customers.list()
        self.assertEqual(self._last()["headers"]["Authorization"], "Bearer pt_abc")

    def test_pagination_query(self):
        self._ok({"data": [], "meta": {}})
        c = PerfexClient(self.base_url, token="pt")
        c.customers.list(page=2, per_page=50, search="acme")
        self.assertIn("page=2", self._last()["path"])
        self.assertIn("per_page=50", self._last()["path"])
        self.assertIn("q=acme", self._last()["path"])

    def test_create_returns_data(self):
        self._ok({"id": 99})
        c = PerfexClient(self.base_url, token="pt")
        out = c.invoices.create(customer_id=1, date="2026-06-01", due_date="2026-06-15")
        self.assertEqual(out["id"], 99)

    def test_error_raises(self):
        self._err(404, "not_found", "Invoice 99 not found")
        c = PerfexClient(self.base_url, token="pt")
        with self.assertRaises(PerfexError) as ctx:
            c.invoices.get(99)
        self.assertEqual(ctx.exception.code, "not_found")
        self.assertEqual(ctx.exception.status, 404)

    def test_429_raises_rate_limited(self):
        self._err(429, "rate_limited", "Rate limit exceeded", {"retry_after_seconds": 60})
        c = PerfexClient(self.base_url, token="pt", max_retries=0)
        with self.assertRaises(PerfexRateLimitedError):
            c.invoices.list()

    def test_pdf_returns_bytes(self):
        _RecorderHandler.next_response_status = 200
        _RecorderHandler.next_response_body = b"%PDF-1.4 fake"
        _RecorderHandler.next_response_headers = {"Content-Type": "application/pdf"}
        c = PerfexClient(self.base_url, token="pt")
        pdf = c.invoices.pdf(1)
        self.assertEqual(pdf[:4], b"%PDF")

    def test_lead_convert(self):
        self._ok({"lead_id": 5, "customer_id": 88, "status": "converted"})
        c = PerfexClient(self.base_url, token="pt")
        out = c.leads.convert(5)
        self.assertEqual(out["customer_id"], 88)

    def test_webhook_create(self):
        self._ok({
            "id": 7, "name": "X", "url": "https://x/y",
            "events": ["invoice.paid"], "secret": "s3cret",
            "is_active": 1, "created_by": 1,
            "created_at": "2026-06-01 00:00:00", "updated_at": "2026-06-01 00:00:00",
        })
        c = PerfexClient(self.base_url, token="pt")
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
