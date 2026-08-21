#!/usr/bin/env python3
"""Automated regression and test matrix suite for production deployment scripts."""

import importlib.util
import os
import pathlib
import subprocess
import tempfile
import unittest


MODULE_PATH = pathlib.Path(__file__).parents[1] / "release_manifest.py"
SPEC = importlib.util.spec_from_file_location("release_manifest", MODULE_PATH)
release_manifest = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(release_manifest)

SHA = "a" * 40
TREE = "b" * 40
DIGESTS = [f"sha256:{character * 64}" for character in "123456789abcdef0123456789"]


def valid_multiarch_manifest():
    images = []
    for index, (component, name) in enumerate(release_manifest.IMAGES):
        digest = DIGESTS[index]
        images.append({
            "component": component,
            "name": name,
            "versionTag": "v1.4.0",
            "shaTag": f"sha-{SHA}",
            "manifestDigest": digest,
            "manifestMediaType": "application/vnd.oci.image.index.v1+json",
            "tagDigests": {"version": digest, "sha": digest},
            "platforms": [
                {"os": "linux", "architecture": "amd64", "digest": DIGESTS[(index * 2) + 3], "sourceRevision": SHA},
                {"os": "linux", "architecture": "arm64", "digest": DIGESTS[(index * 2) + 4], "sourceRevision": SHA},
            ],
        })
    return {
        "formatVersion": 1,
        "releaseVersion": "1.4.0",
        "sourceSha": SHA,
        "sourceTree": TREE,
        "generatedAt": "2026-08-19T12:00:00Z",
        "images": images,
    }


class TestDeployMatrix(unittest.TestCase):
    def test_multiarch_manifest_validation_pass(self):
        manifest = valid_multiarch_manifest()
        release_manifest.validate_manifest(manifest, "1.4.0", SHA, TREE)

    def test_rejects_single_arch_amd64_only(self):
        manifest = valid_multiarch_manifest()
        manifest["images"][0]["platforms"] = [
            {"os": "linux", "architecture": "amd64", "digest": DIGESTS[3], "sourceRevision": SHA}
        ]
        with self.assertRaises(release_manifest.ManifestError):
            release_manifest.validate_manifest(manifest, "1.4.0", SHA, TREE)

    def test_rejects_single_arch_arm64_only(self):
        manifest = valid_multiarch_manifest()
        manifest["images"][2]["platforms"] = [
            {"os": "linux", "architecture": "arm64", "digest": DIGESTS[4], "sourceRevision": SHA}
        ]
        with self.assertRaises(release_manifest.ManifestError):
            release_manifest.validate_manifest(manifest, "1.4.0", SHA, TREE)

    def test_rejects_missing_component_postgis(self):
        manifest = valid_multiarch_manifest()
        manifest["images"] = [img for img in manifest["images"] if img["component"] != "postgis"]
        with self.assertRaises(release_manifest.ManifestError):
            release_manifest.validate_manifest(manifest, "1.4.0", SHA, TREE)

    def test_env_precedence_in_lib_sh(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            env_file = os.path.join(tmpdir, ".env.production")
            with open(env_file, "w") as f:
                f.write('RELEASE_SHA="stale_sha_from_file"\nSTORAGE_DRIVER="local"\nBACKUP_ENABLED="false"\n')

            # Test that process environment wins over file
            cmd = f"""
            export RELEASE_SHA="process_sha_override"
            export ENV_FILE="{env_file}"
            . scripts/production/lib.sh
            require_env
            echo "RELEASE_SHA=$RELEASE_SHA"
            echo "STORAGE_DRIVER=$STORAGE_DRIVER"
            """
            result = subprocess.run(["/bin/bash", "-c", cmd], stdout=subprocess.PIPE, text=True, check=True)
            output = result.stdout
            self.assertIn("RELEASE_SHA=process_sha_override", output)
            self.assertIn("STORAGE_DRIVER=local", output)

    def test_no_zsh_references_in_scripts_and_config(self):
        root = pathlib.Path(__file__).parents[3]
        target_dirs = [root / "scripts", root / "infra", root / ".github"]
        zsh_matches = []
        skip_parts = {".git", "node_modules", "vendor", "var", "__pycache__"}
        for target_dir in target_dirs:
            if not target_dir.exists():
                continue
            for path in target_dir.rglob("*"):
                if path.is_file() and path != pathlib.Path(__file__) and not any(p in path.parts for p in skip_parts):
                    try:
                        content = path.read_text(encoding="utf-8", errors="ignore")
                        if "zsh" in content:
                            zsh_matches.append(str(path))
                    except Exception:
                        pass
        self.assertEqual(zsh_matches, [], f"Found unexpected zsh references in: {zsh_matches}")

    def test_resolve_release_rejects_sha_input(self):
        with self.assertRaises(release_manifest.ManifestError) as ctx:
            release_manifest.resolve_release("fe7027903644f49cfe1f78e83bda9e06fcde3b42")
        self.assertIn("expected release version", str(ctx.exception))

    def test_deploy_script_rejects_sha_input(self):
        root = pathlib.Path(__file__).parents[3]
        cmd = [str(root / "scripts/production/deploy"), "--release", "fe7027903644f49cfe1f78e83bda9e06fcde3b42"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.assertNotEqual(res.returncode, 0)
        self.assertIn("expected release version", res.stderr)

    def test_resolve_release_script_0_1_5(self):
        root = pathlib.Path(__file__).parents[3]
        cmd = [str(root / "scripts/production/resolve-release"), "0.1.5"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.assertEqual(res.returncode, 0)
        self.assertIn("RELEASE_VERSION=0.1.5", res.stdout)
        self.assertIn("RELEASE_SHA=fe7027903644f49cfe1f78e83bda9e06fcde3b42", res.stdout)
        self.assertIn("API_IMAGE=ghcr.io/skiru/family-places-api@sha256:", res.stdout)
        self.assertIn("WEB_IMAGE=ghcr.io/skiru/family-places-web@sha256:", res.stdout)
        self.assertIn("POSTGIS_IMAGE=ghcr.io/skiru/family-places-postgis@sha256:", res.stdout)
        self.assertIn("CF_ACCESS_VALIDATOR_IMAGE=ghcr.io/skiru/family-places-cf-access-validator@sha256:", res.stdout)

    def test_deploy_script_dry_run_0_1_5(self):
        root = pathlib.Path(__file__).parents[3]
        with tempfile.TemporaryDirectory() as tmpdir:
            token_file = os.path.join(tmpdir, "cf-token")
            with open(token_file, "w") as f:
                f.write("dummy-token\n")

            dozzle_file = os.path.join(tmpdir, "dozzle-users.yml")
            with open(dozzle_file, "w") as f:
                f.write("users:\n  operator:\n    password: dummy\n")

            env_file = os.path.join(tmpdir, ".env.production")
            with open(env_file, "w") as f:
                f.write(f"""
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=test_app_secret_12345678901234567890123456789012
POSTGRES_DB=family_places
POSTGRES_USER=family_places
POSTGRES_PASSWORD=test_db_password_12345678901234567890123456789012
DATABASE_URL="postgresql://family_places:test_db_password_12345678901234567890123456789012@database:5432/family_places?serverVersion=18&charset=utf8"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
GOOGLE_IDENTITY_ENABLED=false
APP_PUBLIC_ORIGIN=https://playground.com.pl
TRUSTED_AUTH_ORIGINS=https://playground.com.pl
GATEWAY_DOMAIN=playground.com.pl
ACME_EMAIL=admin@example.com
DEV_AUTH_ENABLED=false
PLACE_DISCOVERY_ENABLED=false
STORAGE_DRIVER=local
MEDIA_PUBLIC_BASE_URL=https://playground.com.pl/media
BACKUP_ENABLED=false
MAP_STYLE_URL=https://tiles.openfreemap.org/styles/liberty
MAP_PROVIDER_NAME=OpenFreeMap
MAP_ATTRIBUTION="OpenFreeMap © OpenMapTiles Data from OpenStreetMap"
CLOUDFLARE_ACCESS_ISSUER=https://playground.cloudflareaccess.com
CLOUDFLARE_ACCESS_AUD=test-aud-12345
CLOUDFLARE_ACCESS_TEST_MODE=false
CLOUDFLARE_TUNNEL_TOKEN_FILE={token_file}
""")

            env = dict(os.environ, ENV_FILE=env_file, CLOUDFLARE_TUNNEL_TOKEN_FILE=token_file)
            cmd = [str(root / "scripts/production/deploy"), "--release", "0.1.5", "--dry-run"]
            res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env)
            self.assertEqual(res.returncode, 0, f"deploy --dry-run failed: {res.stderr}")
            self.assertIn("Release: 0.1.5", res.stdout)
            self.assertIn("Source SHA: fe7027903644f49cfe1f78e83bda9e06fcde3b42", res.stdout)
            self.assertIn("API: ghcr.io/skiru/family-places-api@sha256:", res.stdout)
            self.assertIn("WEB: ghcr.io/skiru/family-places-web@sha256:", res.stdout)
            self.assertIn("POSTGIS: ghcr.io/skiru/family-places-postgis@sha256:", res.stdout)
            self.assertIn("CF_ACCESS_VALIDATOR: ghcr.io/skiru/family-places-cf-access-validator@sha256:", res.stdout)
            self.assertIn("Storage: local", res.stdout)
            self.assertIn("Backup: disabled", res.stdout)
            self.assertIn("Discovery: disabled", res.stdout)

    def test_deploy_script_rejects_missing_cloudflare_token(self):
        root = pathlib.Path(__file__).parents[3]
        with tempfile.TemporaryDirectory() as tmpdir:
            env_file = os.path.join(tmpdir, ".env.production")
            token_path = "/nonexistent/path/token"
            with open(env_file, "w") as f:
                f.write(f"""
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=test_app_secret_12345678901234567890123456789012
POSTGRES_DB=family_places
POSTGRES_USER=family_places
POSTGRES_PASSWORD=test_db_password_12345678901234567890123456789012
DATABASE_URL="postgresql://family_places:test_db_password_12345678901234567890123456789012@database:5432/family_places?serverVersion=18&charset=utf8"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
GOOGLE_IDENTITY_ENABLED=false
APP_PUBLIC_ORIGIN=https://playground.com.pl
TRUSTED_AUTH_ORIGINS=https://playground.com.pl
GATEWAY_DOMAIN=playground.com.pl
ACME_EMAIL=admin@example.com
DEV_AUTH_ENABLED=false
PLACE_DISCOVERY_ENABLED=false
STORAGE_DRIVER=local
MEDIA_PUBLIC_BASE_URL=https://playground.com.pl/media
BACKUP_ENABLED=false
MAP_STYLE_URL=https://tiles.openfreemap.org/styles/liberty
MAP_PROVIDER_NAME=OpenFreeMap
MAP_ATTRIBUTION="OpenFreeMap © OpenMapTiles Data from OpenStreetMap"
CLOUDFLARE_ACCESS_ISSUER=https://playground.cloudflareaccess.com
CLOUDFLARE_ACCESS_AUD=test-aud-12345
CLOUDFLARE_ACCESS_TEST_MODE=false
CLOUDFLARE_TUNNEL_TOKEN_FILE={token_path}
""")

            env = dict(os.environ, ENV_FILE=env_file, CLOUDFLARE_TUNNEL_TOKEN_FILE=token_path)
            cmd = [str(root / "scripts/production/deploy"), "--release", "0.1.5", "--dry-run"]
            res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env)
            self.assertNotEqual(res.returncode, 0)
            self.assertTrue("CLOUDFLARE_TUNNEL_TOKEN_FILE must be readable" in res.stderr or "Missing Cloudflare tunnel token" in res.stderr)

    def test_deploy_script_rejects_nonexistent_release(self):
        root = pathlib.Path(__file__).parents[3]
        with tempfile.TemporaryDirectory() as tmpdir:
            token_file = os.path.join(tmpdir, "cf-token")
            with open(token_file, "w") as f:
                f.write("dummy-token\n")

            dozzle_file = os.path.join(tmpdir, "dozzle-users.yml")
            with open(dozzle_file, "w") as f:
                f.write("users:\n  operator:\n    password: dummy\n")

            env_file = os.path.join(tmpdir, ".env.production")
            with open(env_file, "w") as f:
                f.write(f"""
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=test_app_secret_12345678901234567890123456789012
POSTGRES_DB=family_places
POSTGRES_USER=family_places
POSTGRES_PASSWORD=test_db_password_12345678901234567890123456789012
DATABASE_URL="postgresql://family_places:test_db_password_12345678901234567890123456789012@database:5432/family_places?serverVersion=18&charset=utf8"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
GOOGLE_IDENTITY_ENABLED=false
APP_PUBLIC_ORIGIN=https://playground.com.pl
TRUSTED_AUTH_ORIGINS=https://playground.com.pl
GATEWAY_DOMAIN=playground.com.pl
ACME_EMAIL=admin@example.com
DEV_AUTH_ENABLED=false
PLACE_DISCOVERY_ENABLED=false
STORAGE_DRIVER=local
MEDIA_PUBLIC_BASE_URL=https://playground.com.pl/media
BACKUP_ENABLED=false
MAP_STYLE_URL=https://tiles.openfreemap.org/styles/liberty
MAP_PROVIDER_NAME=OpenFreeMap
MAP_ATTRIBUTION="OpenFreeMap © OpenMapTiles Data from OpenStreetMap"
CLOUDFLARE_ACCESS_ISSUER=https://playground.cloudflareaccess.com
CLOUDFLARE_ACCESS_AUD=test-aud-12345
CLOUDFLARE_ACCESS_TEST_MODE=false
CLOUDFLARE_TUNNEL_TOKEN_FILE={token_file}
""")

            env = dict(os.environ, ENV_FILE=env_file, CLOUDFLARE_TUNNEL_TOKEN_FILE=token_file)
            cmd = [str(root / "scripts/production/deploy"), "--release", "999.999.999", "--dry-run"]
            res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env)
            self.assertNotEqual(res.returncode, 0)


if __name__ == "__main__":
    unittest.main()
