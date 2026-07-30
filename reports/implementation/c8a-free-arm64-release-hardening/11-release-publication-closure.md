# Release publication closure

The dispatch-only container release workflow validates an exact full source SHA,
requires that SHA to be a clean ancestor of `origin/main`, and executes the same
reusable quality, security, and native ARM64 contracts as C8A certification.
Only `sha-<full-sha>` and `v<semver>` tags are constructed for the API, web, and
PostGIS images. BuildKit provenance and SBOM generation remain enabled.

Publication verification consumes structured OCI JSON from Buildx. The
versioned release manifest records the source commit and tree, the three image
index digests, both immutable tag resolutions, exactly `linux/amd64` and
`linux/arm64` platform digests, and the source revision label from each image
configuration. A strict validator rejects malformed JSON, missing or unexpected
architectures, invalid digests, source-label mismatches, mutable tags, and tag
digest mismatches.

Each image index is separately attested with the pinned official
`actions/attest` action using `subject-name`, `subject-digest`, and
`push-to-registry: true`. The workflow verifies each attestation with GitHub CLI
and uploads the machine-readable manifest, exact digest references for
`.env.production`, attestation metadata, and attestation bundles.

C8A certification includes a branch-safe release dry-run. It validates source
ancestry in an isolated Git fixture, builds all three multi-platform definitions
to OCI layouts without a registry output, constructs and validates the release
manifest, enforces immutable action references, and uploads the complete dry-run
evidence. The shared ARM installer is architecture-gated and checksum-verifies
the pinned AWS CLI ARM64 archive before installation.

Final commit, tree, CI, and independently cloned bundle evidence are generated
outside the represented commit so that no bundle is embedded in the commit it
represents.
