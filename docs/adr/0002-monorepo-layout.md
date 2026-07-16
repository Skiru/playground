# ADR 0002: Monorepo layout

Status: Accepted

## Decision

Keep API, web, generated client, infrastructure, docs, reports, and automation in
one repository. `packages/api-client` is the only shared package in C0-C2.

## Consequences

Contract changes can be gated atomically. Generic shared utility packages are
forbidden until concrete reuse justifies them.
