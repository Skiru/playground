#!/usr/bin/env python3
"""Build and validate the machine-readable FamilyPlaces release manifest."""

from __future__ import annotations

import argparse
import datetime as dt
import json
import pathlib
import re
import subprocess
import sys
from typing import Any


IMAGES = (
    ("api", "ghcr.io/skiru/family-places-api"),
    ("web", "ghcr.io/skiru/family-places-web"),
    ("postgis", "ghcr.io/skiru/family-places-postgis"),
)
PLATFORMS = (("linux", "amd64"),)
INDEX_MEDIA_TYPES = {
    "application/vnd.docker.distribution.manifest.list.v2+json",
    "application/vnd.oci.image.index.v1+json",
    "application/vnd.oci.image.manifest.v1+json",
    "application/vnd.docker.distribution.manifest.v2+json",
}
DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
SEMVER_RE = re.compile(
    r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)"
    r"(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?"
    r"$"
)


class ManifestError(ValueError):
    pass


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise ManifestError(message)


def _read_json(path: pathlib.Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise ManifestError(f"invalid JSON in {path}: {error}") from error
    _require(isinstance(value, dict), f"{path} must contain a JSON object")
    return value


def _rfc3339_now() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _validate_rfc3339(value: Any) -> bool:
    if not isinstance(value, str) or not value.endswith("Z"):
        return False
    try:
        dt.datetime.fromisoformat(value[:-1] + "+00:00")
    except ValueError:
        return False
    return True


def validate_manifest(
    manifest: dict[str, Any], version: str, source_sha: str, source_tree: str
) -> None:
    _require(manifest.get("formatVersion") == 1, "formatVersion must equal 1")
    _require(SEMVER_RE.fullmatch(version) is not None, "expected release version is not SemVer")
    _require(SHA_RE.fullmatch(source_sha) is not None, "expected source SHA is invalid")
    _require(SHA_RE.fullmatch(source_tree) is not None, "expected source tree is invalid")
    _require(manifest.get("releaseVersion") == version, "releaseVersion mismatch")
    _require(manifest.get("sourceSha") == source_sha, "sourceSha mismatch")
    _require(manifest.get("sourceTree") == source_tree, "sourceTree mismatch")
    _require(_validate_rfc3339(manifest.get("generatedAt")), "generatedAt must be RFC3339 UTC")

    images = manifest.get("images")
    _require(isinstance(images, list), "images must be an array")
    _require(len(images) == len(IMAGES), "manifest must contain exactly three images")
    expected = dict(IMAGES)
    _require({image.get("component") for image in images if isinstance(image, dict)} == set(expected),
             "manifest components must be exactly api, web, and postgis")

    for image in images:
        _require(isinstance(image, dict), "each image must be an object")
        component = image["component"]
        name = expected[component]
        manifest_digest = image.get("manifestDigest")
        version_tag = f"v{version}"
        sha_tag = f"sha-{source_sha}"
        _require(image.get("name") == name, f"{component}: image name mismatch")
        _require(image.get("versionTag") == version_tag, f"{component}: version tag mismatch")
        _require(image.get("shaTag") == sha_tag, f"{component}: SHA tag mismatch")
        _require(DIGEST_RE.fullmatch(str(manifest_digest)) is not None,
                 f"{component}: invalid manifest digest")
        _require(image.get("manifestMediaType") in INDEX_MEDIA_TYPES,
                 f"{component}: manifest must be an OCI image index")
        _require(version_tag not in ("main", "latest") and sha_tag not in ("main", "latest"),
                 f"{component}: forbidden mutable tag")

        tag_digests = image.get("tagDigests")
        _require(isinstance(tag_digests, dict), f"{component}: tagDigests must be an object")
        _require(set(tag_digests) == {"version", "sha"}, f"{component}: unexpected immutable tag")
        _require(tag_digests.get("version") == manifest_digest,
                 f"{component}: version tag digest mismatch")
        _require(tag_digests.get("sha") == manifest_digest,
                 f"{component}: SHA tag digest mismatch")

        platforms = image.get("platforms")
        _require(isinstance(platforms, list), f"{component}: platforms must be an array")
        _require(len(platforms) == 1, f"{component}: exactly one platform is required")
        actual_platforms = {
            (platform.get("os"), platform.get("architecture"))
            for platform in platforms
            if isinstance(platform, dict)
        }
        _require(actual_platforms == set(PLATFORMS),
                 f"{component}: platforms must be exactly linux/amd64")
        for platform in platforms:
            _require(DIGEST_RE.fullmatch(str(platform.get("digest"))) is not None,
                     f"{component}: invalid platform digest")
            _require(platform.get("sourceRevision") == source_sha,
                     f"{component}: OCI source revision label mismatch")


def _run_json(*command: str) -> dict[str, Any]:
    result = subprocess.run(command, check=True, stdout=subprocess.PIPE)
    try:
        value = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise ManifestError(f"command returned malformed JSON: {' '.join(command)}") from error
    _require(isinstance(value, dict), f"command must return a JSON object: {' '.join(command)}")
    return value


def _inspect_manifest(reference: str) -> tuple[dict[str, Any], str]:
    manifest = _run_json(
        "docker", "buildx", "imagetools", "inspect", reference,
        "--format", "{{json .Manifest}}",
    )
    digest = manifest.get("digest")
    _require(DIGEST_RE.fullmatch(str(digest)) is not None,
             f"registry returned an invalid manifest digest for {reference}")
    return manifest, digest


def _platform_entries(index: dict[str, Any], image_name: str, source_sha: str) -> list[dict[str, str]]:
    descriptors = index.get("manifests")
    _require(isinstance(descriptors, list), f"{image_name}: expected an OCI image index")
    entries: list[dict[str, str]] = []
    for descriptor in descriptors:
        platform = descriptor.get("platform", {})
        pair = (platform.get("os"), platform.get("architecture"))
        if pair not in PLATFORMS:
            annotations = descriptor.get("annotations", {})
            if annotations.get("vnd.docker.reference.type") == "attestation-manifest":
                continue
            raise ManifestError(f"{image_name}: unexpected platform {pair[0]}/{pair[1]}")
        digest = descriptor.get("digest")
        _require(DIGEST_RE.fullmatch(str(digest)) is not None,
                 f"{image_name}: invalid platform digest")
        config = _run_json(
            "docker", "buildx", "imagetools", "inspect", f"{image_name}@{digest}",
            "--format", "{{json .Image}}",
        )
        labels = config.get("config", {}).get("Labels", {})
        _require(labels.get("org.opencontainers.image.revision") == source_sha,
                 f"{image_name}@{digest}: OCI source revision label mismatch")
        entries.append({
            "os": pair[0],
            "architecture": pair[1],
            "digest": digest,
            "sourceRevision": source_sha,
        })
    entries.sort(key=lambda item: item["architecture"])
    return entries


def build_registry_manifest(version: str, source_sha: str, source_tree: str) -> dict[str, Any]:
    images: list[dict[str, Any]] = []
    for component, name in IMAGES:
        sha_tag = f"sha-{source_sha}"
        version_tag = f"v{version}"
        index, sha_digest = _inspect_manifest(f"{name}:{sha_tag}")
        _, version_digest = _inspect_manifest(f"{name}:{version_tag}")
        _require(sha_digest == version_digest,
                 f"{component}: immutable tags resolve to different manifest digests")
        images.append({
            "component": component,
            "name": name,
            "versionTag": version_tag,
            "shaTag": sha_tag,
            "manifestDigest": sha_digest,
            "manifestMediaType": index.get("mediaType"),
            "tagDigests": {"version": version_digest, "sha": sha_digest},
            "platforms": _platform_entries(index, name, source_sha),
        })
    return {
        "formatVersion": 1,
        "releaseVersion": version,
        "sourceSha": source_sha,
        "sourceTree": source_tree,
        "generatedAt": _rfc3339_now(),
        "images": images,
    }


def _blob(layout: pathlib.Path, digest: str) -> pathlib.Path:
    algorithm, value = digest.split(":", 1)
    return layout / "blobs" / algorithm / value


def _layout_index(layout: pathlib.Path) -> tuple[dict[str, Any], str]:
    root = _read_json(layout / "index.json")
    descriptors = root.get("manifests")
    _require(isinstance(descriptors, list) and descriptors,
             f"{layout}: OCI layout must have a root descriptor")
    digests = {descriptor.get("digest") for descriptor in descriptors}
    _require(len(digests) == 1, f"{layout}: OCI layout tags must resolve to one digest")
    digest = next(iter(digests))
    _require(DIGEST_RE.fullmatch(str(digest)) is not None, f"{layout}: invalid root digest")
    return _read_json(_blob(layout, digest)), digest


def _layout_platforms(layout: pathlib.Path, index: dict[str, Any], source_sha: str) -> list[dict[str, str]]:
    entries: list[dict[str, str]] = []
    descriptors = index.get("manifests")
    _require(isinstance(descriptors, list), f"{layout}: root descriptor is not an image index")
    for descriptor in descriptors:
        platform = descriptor.get("platform", {})
        pair = (platform.get("os"), platform.get("architecture"))
        if pair not in PLATFORMS:
            annotations = descriptor.get("annotations", {})
            if annotations.get("vnd.docker.reference.type") == "attestation-manifest":
                continue
            raise ManifestError(f"{layout}: unexpected platform {pair[0]}/{pair[1]}")
        digest = descriptor.get("digest")
        image_manifest = _read_json(_blob(layout, digest))
        config_digest = image_manifest.get("config", {}).get("digest")
        _require(DIGEST_RE.fullmatch(str(config_digest)) is not None,
                 f"{layout}: invalid image config digest")
        config = _read_json(_blob(layout, config_digest))
        labels = config.get("config", {}).get("Labels", {})
        _require(labels.get("org.opencontainers.image.revision") == source_sha,
                 f"{layout}: OCI source revision label mismatch")
        entries.append({
            "os": pair[0],
            "architecture": pair[1],
            "digest": digest,
            "sourceRevision": source_sha,
        })
    entries.sort(key=lambda item: item["architecture"])
    return entries


def build_layout_manifest(
    layouts: pathlib.Path, version: str, source_sha: str, source_tree: str
) -> dict[str, Any]:
    images: list[dict[str, Any]] = []
    for component, name in IMAGES:
        index, digest = _layout_index(layouts / f"{component}-oci-layout")
        images.append({
            "component": component,
            "name": name,
            "versionTag": f"v{version}",
            "shaTag": f"sha-{source_sha}",
            "manifestDigest": digest,
            "manifestMediaType": index.get("mediaType"),
            "tagDigests": {"version": digest, "sha": digest},
            "platforms": _layout_platforms(
                layouts / f"{component}-oci-layout", index, source_sha
            ),
        })
    return {
        "formatVersion": 1,
        "releaseVersion": version,
        "sourceSha": source_sha,
        "sourceTree": source_tree,
        "generatedAt": _rfc3339_now(),
        "images": images,
    }


def _write_manifest(path: pathlib.Path, manifest: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)
    validate = subparsers.add_parser("validate")
    validate.add_argument("manifest", type=pathlib.Path)
    registry = subparsers.add_parser("from-registry")
    registry.add_argument("output", type=pathlib.Path)
    layouts = subparsers.add_parser("from-layouts")
    layouts.add_argument("layouts", type=pathlib.Path)
    layouts.add_argument("output", type=pathlib.Path)
    for command in (validate, registry, layouts):
        command.add_argument("version")
        command.add_argument("source_sha")
        command.add_argument("source_tree")
    args = parser.parse_args()
    try:
        if args.command == "validate":
            manifest = _read_json(args.manifest)
        elif args.command == "from-registry":
            manifest = build_registry_manifest(args.version, args.source_sha, args.source_tree)
            _write_manifest(args.output, manifest)
        else:
            manifest = build_layout_manifest(
                args.layouts, args.version, args.source_sha, args.source_tree
            )
            _write_manifest(args.output, manifest)
        validate_manifest(manifest, args.version, args.source_sha, args.source_tree)
    except (ManifestError, subprocess.CalledProcessError) as error:
        print(f"release manifest validation failed: {error}", file=sys.stderr)
        return 1
    print("release manifest validation passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
