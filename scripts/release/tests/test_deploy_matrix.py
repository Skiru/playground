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


if __name__ == "__main__":
    unittest.main()
