#!/usr/bin/env python3
import json
import os
import sys
import time
import unittest
import threading
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import jwt
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives import serialization

# Generate test RSA key pair
private_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
pem_private = private_key.private_bytes(
    encoding=serialization.Encoding.PEM,
    format=serialization.PrivateFormat.PKCS8,
    encryption_algorithm=serialization.NoEncryption()
).decode('utf-8')

pem_public = private_key.public_key().public_bytes(
    encoding=serialization.Encoding.PEM,
    format=serialization.PublicFormat.SubjectPublicKeyInfo
).decode('utf-8')

# Generate another untrusted RSA key pair for signature forgery tests
untrusted_private_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
untrusted_pem_private = untrusted_private_key.private_bytes(
    encoding=serialization.Encoding.PEM,
    format=serialization.PrivateFormat.PKCS8,
    encryption_algorithm=serialization.NoEncryption()
).decode('utf-8')

os.environ["CLOUDFLARE_ACCESS_TEST_MODE"] = "true"
os.environ["CLOUDFLARE_ACCESS_TEST_PUBLIC_KEY"] = pem_public
os.environ["CLOUDFLARE_ACCESS_ISSUER"] = "https://playground.cloudflareaccess.com"
os.environ["CLOUDFLARE_ACCESS_AUD"] = "test-aud-12345"

from app import run, validate_assertion, ValidatorHandler
from http.server import HTTPServer

class TestCFAccessValidator(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = HTTPServer(("127.0.0.1", 8089), ValidatorHandler)
        cls.server_thread = threading.Thread(target=cls.server.serve_forever)
        cls.server_thread.daemon = True
        cls.server_thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()

    def make_jwt(self, email="admin@playground.com.pl", iss="https://playground.cloudflareaccess.com", aud="test-aud-12345", exp_offset=3600, key=pem_private):
        payload = {
            "iss": iss,
            "aud": aud,
            "sub": "user-123",
            "email": email,
            "exp": int(time.time()) + exp_offset,
            "iat": int(time.time()),
        }
        return jwt.encode(payload, key, algorithm="RS256", headers={"kid": "test-kid"})

    def test_healthz(self):
        req = Request("http://127.0.0.1:8089/healthz")
        with urlopen(req) as resp:
            self.assertEqual(resp.status, 200)
            data = json.loads(resp.read().decode())
            self.assertEqual(data["status"], "ok")

    def test_attack1_direct_origin_fake_email_header_no_jwt(self):
        # Request with fake Cf-Access-Authenticated-User-Email header but NO Jwt Assertion header
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Cf-Access-Authenticated-User-Email", "attacker@evil.com")
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_attack2_direct_origin_fake_remote_user_no_jwt(self):
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Remote-User", "admin")
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_attack3_direct_origin_fake_remote_roles_no_jwt(self):
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Remote-Roles", "ROLE_ADMIN")
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_attack4_direct_origin_no_auth(self):
        req = Request("http://127.0.0.1:8089/validate")
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_fake_signature_jwt(self):
        # Token signed with untrusted key
        token = self.make_jwt(key=untrusted_pem_private)
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Cf-Access-Jwt-Assertion", token)
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_expired_jwt(self):
        token = self.make_jwt(exp_offset=-100)
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Cf-Access-Jwt-Assertion", token)
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_wrong_issuer_jwt(self):
        token = self.make_jwt(iss="https://attacker.com")
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Cf-Access-Jwt-Assertion", token)
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_wrong_audience_jwt(self):
        token = self.make_jwt(aud="wrong-aud")
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Cf-Access-Jwt-Assertion", token)
        with self.assertRaises(HTTPError) as cm:
            urlopen(req)
        self.assertEqual(cm.exception.code, 403)

    def test_authenticated_valid_flow(self):
        token = self.make_jwt(email="valid_user@playground.com.pl")
        req = Request("http://127.0.0.1:8089/validate")
        req.add_header("Cf-Access-Jwt-Assertion", token)
        # Even if attacker sends a fake email header alongside the valid JWT, validator MUST extract email from verified JWT payload
        req.add_header("Cf-Access-Authenticated-User-Email", "spoofed@attacker.com")
        
        with urlopen(req) as resp:
            self.assertEqual(resp.status, 200)
            returned_email = resp.headers.get("Cf-Access-Authenticated-User-Email")
            self.assertEqual(returned_email, "valid_user@playground.com.pl")

if __name__ == "__main__":
    unittest.main()
