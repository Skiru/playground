#!/usr/bin/env python3
import json
import os
import sys
import time
import logging
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse
import jwt
import requests
from jwt.exceptions import PyJWTError, ExpiredSignatureError, InvalidAudienceError, InvalidIssuerError

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")

TEAM_DOMAIN = os.environ.get("CLOUDFLARE_ACCESS_TEAM_DOMAIN", "playground")
DEFAULT_ISSUER = f"https://{TEAM_DOMAIN}.cloudflareaccess.com"
ISSUER = os.environ.get("CLOUDFLARE_ACCESS_ISSUER", DEFAULT_ISSUER)
AUDIENCE = os.environ.get("CLOUDFLARE_ACCESS_AUD", "")
JWKS_URL = os.environ.get("CLOUDFLARE_ACCESS_JWKS_URL", f"{ISSUER}/cdn-cgi/access/certs")
TEST_MODE = os.environ.get("CLOUDFLARE_ACCESS_TEST_MODE", "false").lower() in ("true", "1", "yes")
TEST_PUBLIC_KEY = os.environ.get("CLOUDFLARE_ACCESS_TEST_PUBLIC_KEY", "")

_jwks_cache = {"keys": {}, "expires_at": 0}

def get_jwks():
    now = time.time()
    if _jwks_cache["expires_at"] > now and _jwks_cache["keys"]:
        return _jwks_cache["keys"]
    
    try:
        logging.info(f"Fetching JWKS from {JWKS_URL}")
        resp = requests.get(JWKS_URL, timeout=5)
        resp.raise_for_status()
        data = resp.json()
        keys_by_kid = {}
        for key_dict in data.get("keys", []):
            kid = key_dict.get("kid")
            if kid:
                keys_by_kid[kid] = jwt.algorithms.RSAAlgorithm.from_jwk(json.dumps(key_dict))
        _jwks_cache["keys"] = keys_by_kid
        _jwks_cache["expires_at"] = now + 3600
        return keys_by_kid
    except Exception as e:
        logging.error(f"Failed to fetch JWKS: {e}")
        return _jwks_cache.get("keys", {})

def validate_assertion(token):
    if not token:
        return False, "missing_token", None

    try:
        unverified_header = jwt.get_unverified_header(token)
    except Exception as e:
        return False, f"malformed_header: {e}", None

    kid = unverified_header.get("kid")
    alg = unverified_header.get("alg", "RS256")

    key = None
    if TEST_MODE and TEST_PUBLIC_KEY:
        key = TEST_PUBLIC_KEY
    else:
        jwks = get_jwks()
        if kid not in jwks:
            # Force refresh JWKS once
            _jwks_cache["expires_at"] = 0
            jwks = get_jwks()
        key = jwks.get(kid)

    if not key:
        return False, f"unknown_kid: {kid}", None

    try:
        kwargs = {
            "algorithms": [alg],
            "options": {"verify_aud": bool(AUDIENCE), "verify_iss": bool(ISSUER)},
            "leeway": 10,
        }
        if AUDIENCE:
            kwargs["audience"] = AUDIENCE
        if ISSUER:
            kwargs["issuer"] = ISSUER

        payload = jwt.decode(token, key, **kwargs)
        email = payload.get("email") or payload.get("sub")
        if not email:
            return False, "missing_email_claim", None

        return True, "ok", email
    except ExpiredSignatureError:
        return False, "expired_token", None
    except InvalidAudienceError:
        return False, "invalid_audience", None
    except InvalidIssuerError:
        return False, "invalid_issuer", None
    except PyJWTError as e:
        return False, f"jwt_error: {e}", None

class ValidatorHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        self._handle_request()

    def do_POST(self):
        self._handle_request()

    def do_HEAD(self):
        self._handle_request()

    def _handle_request(self):
        parsed = urlparse(self.path)
        if parsed.path == "/healthz":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(b'{"status":"ok"}')
            return

        if parsed.path in ("/validate", "/"):
            assertion = self.headers.get("Cf-Access-Jwt-Assertion", "").strip()
            valid, reason, email = validate_assertion(assertion)
            if valid and email:
                self.send_response(200)
                self.send_header("Cf-Access-Authenticated-User-Email", email)
                self.send_header("Content-Type", "text/plain")
                self.end_headers()
                self.wfile.write(b"OK")
            else:
                logging.warning(f"Access validation failed: {reason}")
                self.send_response(403)
                self.send_header("Content-Type", "text/plain")
                self.end_headers()
                self.wfile.write(f"Forbidden: {reason}".encode("utf-8"))
            return

        self.send_response(404)
        self.end_headers()

    def log_message(self, format, *args):
        pass

def run(port=8080):
    server_address = ("", port)
    httpd = HTTPServer(server_address, ValidatorHandler)
    logging.info(f"Starting CF Access Validator on port {port} (Test Mode: {TEST_MODE})")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    httpd.server_close()

if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))
    run(port)
