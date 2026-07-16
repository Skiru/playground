# ADR 0001: Modular monolith

Status: Accepted

## Decision

Build one Symfony deployable divided into capability modules with enforced
Domain/Application/Infrastructure/UI direction. Do not model modules as bundles.

## Consequences

Transactions and operations stay simple while ownership remains explicit.
Deptrac is mandatory. Extraction requires measured pressure, not speculation.
