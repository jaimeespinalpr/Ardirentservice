import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")
NODE = shutil.which("node")

CASES = {
    "ardirentservice.com": True,
    "www.ardirentservice.com": True,
    "pay.ardirentservice.com": True,
    "fakeardirentservice.com": False,
    "localhost": False,
    "pay.ardirentservice.com:8443": True,
}


class DomainSecurityTests(unittest.TestCase):
    @unittest.skipUnless(PHP, "PHP runtime is not installed locally")
    def test_php_cookie_domain_allowlist(self):
        for host, allowed in CASES.items():
            code = (
                "require 'accounts_common.php';"
                f"echo json_encode(account_cookie_domain_for_host({json.dumps(host)}));"
            )
            result = subprocess.run(
                [PHP, "-r", code], cwd=ROOT, text=True, capture_output=True, check=False
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), ".ardirentservice.com" if allowed else "")

    @unittest.skipUnless(NODE, "Node.js is required")
    def test_frontend_api_url_allowlist(self):
        module_path = json.dumps(str(ROOT / "assets" / "account-api-url.js"))
        script = f"""
          const select = require({module_path});
          const cases = {json.dumps(CASES)};
          for (const [host, allowed] of Object.entries(cases)) {{
            const actual = select(host);
            const expected = allowed
              ? 'https://pay.ardirentservice.com/accounts_api.php'
              : 'accounts_api.php';
            if (actual !== expected) {{
              throw new Error(`${{host}}: expected ${{expected}}, got ${{actual}}`);
            }}
          }}
        """
        result = subprocess.run(
            [NODE, "-e", script], cwd=ROOT, text=True, capture_output=True, check=False
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_account_pages_load_selector_before_account_client(self):
        for filename in ("index.html", "equipment.html", "account.html"):
            html = (ROOT / filename).read_text(encoding="utf-8")
            selector = html.find("assets/account-api-url.js")
            client = html.find("assets/account.js")
            self.assertGreaterEqual(selector, 0, filename)
            self.assertGreater(client, selector, filename)

    def test_account_client_uses_selector_not_permanent_production_url(self):
        source = (ROOT / "assets" / "account.js").read_text(encoding="utf-8")
        self.assertIn("window.ardiAccountApiUrl(window.location.hostname)", source)
        self.assertNotIn('const API = "https://pay.ardirentservice.com/accounts_api.php"', source)


if __name__ == "__main__":
    unittest.main()
