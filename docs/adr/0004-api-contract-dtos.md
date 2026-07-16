# ADR 0004: Explicit API contract DTOs

Status: Accepted

## Decision

Expose explicit API Platform resource DTOs and operations under `/api/v1`.
Doctrine entities are private persistence models; errors use RFC 9457.

## Consequences

Every URI, input, output, limit, status, security rule, and OpenAPI description
is reviewed. Convenience auto-CRUD is not enabled.
