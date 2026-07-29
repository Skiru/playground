# Independent review

The final review must be run from a clean checkout because the first automated reviewer was invoked from the primary checkout and saw only its unrelated untracked image. Required final checks include workflow action pinning, no `latest`, no database ports, rendered Compose topology, no secret values, and arm64 manifests after release publication.
