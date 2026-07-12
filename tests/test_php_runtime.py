import json
import os
from pathlib import Path
import shutil
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

ROOT = Path(__file__).resolve().parents[1]
PHP_AVAILABLE = shutil.which("php") is not None
PHP = shutil.which("php") or "php"
pytestmark = pytest.mark.skipif(not PHP_AVAILABLE, reason="PHP runtime is not installed locally")


def php_run(code: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    merged = os.environ.copy()
    if env:
        merged.update(env)
    return subprocess.run(
        [PHP, "-r", code],
        cwd=ROOT,
        env=merged,
        text=True,
        capture_output=True,
        check=False,
        timeout=20,
    )


def test_all_php_files_pass_runtime_lint():
    php_files = sorted(ROOT.glob("*.php"))
    assert php_files
    failures = []
    for path in php_files:
        result = subprocess.run(
            [PHP, "-l", str(path)], text=True, capture_output=True, check=False, timeout=20
        )
        if result.returncode:
            failures.append(result.stdout + result.stderr)
    assert not failures, "\n".join(failures)


def test_config_endpoint_executes_without_opening_sqlite_or_returning_secrets():
    code = (
        "$_SERVER['REQUEST_METHOD']='GET';"
        "$_GET['action']='config';"
        "include 'accounts_api.php';"
    )
    env = {
        "ACCOUNT_BACKEND": "supabase",
        "SUPABASE_URL": "https://example.supabase.co",
        "SUPABASE_PUBLISHABLE_KEY": "public-test-value",
        "SUPABASE_SECRET_KEY": "must-never-be-returned",
        "SUPABASE_SERVICE_ROLE_KEY": "must-never-be-returned-either",
    }
    result = php_run(code, env)
    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)
    assert payload == {
        "ok": True,
        "account_backend": "supabase",
        "supabase_url": "https://example.supabase.co",
        "supabase_publishable_key": "public-test-value",
    }
    assert "must-never" not in result.stdout


class _SupabaseAuthHandler(BaseHTTPRequestHandler):
    received: dict[str, str] = {}

    def do_GET(self):  # noqa: N802
        type(self).received = {key.lower(): value for key, value in self.headers.items()}
        body = json.dumps({"id": "00000000-0000-4000-8000-000000000001", "email": "test@example.invalid"}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format: str, *args):  # noqa: A002
        return


def test_bearer_validation_sends_real_headers_to_supabase():
    server = HTTPServer(("127.0.0.1", 0), _SupabaseAuthHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        code = (
            "require 'supabase_common.php';"
            "$user=supabase_validate_bearer('jwt-test-value');"
            "echo json_encode(['id'=>$user['id'] ?? null]);"
        )
        result = php_run(
            code,
            {
                "SUPABASE_URL": f"http://127.0.0.1:{server.server_port}",
                "SUPABASE_PUBLISHABLE_KEY": "public-test-value",
            },
        )
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()

    assert result.returncode == 0, result.stderr
    assert json.loads(result.stdout)["id"] == "00000000-0000-4000-8000-000000000001"
    assert _SupabaseAuthHandler.received["authorization"] == "Bearer jwt-test-value"
    assert _SupabaseAuthHandler.received["apikey"] == "public-test-value"
    assert "jwt-test-value" not in result.stderr
    assert "public-test-value" not in result.stderr
