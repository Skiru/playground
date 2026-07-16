# ADR 0008: Generated OpenAPI client

Status: Accepted

## Decision

Generate and commit a Fetch SDK and types using `@hey-api/openapi-ts` from the
locally exported API Platform OpenAPI document. Web imports this package.

## Consequences

CI exports, regenerates, and fails on diff. Builds never fetch production schema,
and handwritten copies of API types are prohibited.
