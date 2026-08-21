#!/usr/bin/env python3

import importlib.util
import pathlib
import unittest


MODULE_PATH = pathlib.Path(__file__).parents[1] / "release_manifest.py"
SPEC = importlib.util.spec_from_file_location("release_manifest", MODULE_PATH)
release_manifest = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(release_manifest)

SHA = "a" * 40
TREE = "b" * 40
DIGESTS = [f"sha256:{character * 64}" for character in "123456789abcdef0123456789"]


def valid_manifest():
    images = []
    for index, (component, name) in enumerate(release_manifest.IMAGES):
        digest = DIGESTS[index]
        images.append({
            "component": component,
            "name": name,
            "versionTag": "v0.1.0",
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
        "releaseVersion": "0.1.0",
        "sourceSha": SHA,
        "sourceTree": TREE,
        "generatedAt": "2026-07-29T20:00:00Z",
        "images": images,
    }


class ReleaseManifestTests(unittest.TestCase):
    def assert_invalid(self, mutate):
        manifest = valid_manifest()
        mutate(manifest)
        with self.assertRaises(release_manifest.ManifestError):
            release_manifest.validate_manifest(manifest, "0.1.0", SHA, TREE)

    def test_accepts_complete_manifest(self):
        release_manifest.validate_manifest(valid_manifest(), "0.1.0", SHA, TREE)

    def test_rejects_single_platform_manifest(self):
        self.assert_invalid(lambda value: value["images"][0].update(platforms=[
            {"os": "linux", "architecture": "amd64", "digest": DIGESTS[3], "sourceRevision": SHA}
        ]))

    def test_rejects_empty_platforms(self):
        self.assert_invalid(lambda value: value["images"][0].update(platforms=[]))

    def test_rejects_unexpected_architecture(self):
        self.assert_invalid(lambda value: value["images"][1]["platforms"][0].update(architecture="s390x"))

    def test_rejects_mismatched_immutable_tags(self):
        self.assert_invalid(lambda value: value["images"][2]["tagDigests"].update(version=DIGESTS[12]))

    def test_rejects_invalid_manifest_digest(self):
        self.assert_invalid(lambda value: value["images"][0].update(manifestDigest="not-a-digest"))

    def test_rejects_invalid_manifest_media_type(self):
        self.assert_invalid(lambda value: value["images"][0].update(manifestMediaType="invalid/media-type"))

    def test_rejects_wrong_source_revision_label(self):
        self.assert_invalid(lambda value: value["images"][0]["platforms"][0].update(sourceRevision="c" * 40))

    def test_rejects_missing_image(self):
        self.assert_invalid(lambda value: value["images"].pop())

    def test_rejects_main_tag(self):
        self.assert_invalid(lambda value: value["images"][0].update(versionTag="main"))

    def test_rejects_latest_tag(self):
        self.assert_invalid(lambda value: value["images"][0].update(shaTag="latest"))

    def test_rejects_malformed_json(self):
        with self.assertRaises(release_manifest.ManifestError):
            release_manifest._read_json(pathlib.Path(__file__).with_name("malformed.json"))


if __name__ == "__main__":
    unittest.main()
