# ADR 0009: Local storage before object storage

Status: Accepted

## Decision

Define a media-storage port but do not add uploads or object storage in C0-C2.
Future local storage may precede an object-storage adapter after threat review.

## Consequences

MinIO and unused media infrastructure are avoided. No UI or API implies uploads
are available before the authorized media checkpoint.
