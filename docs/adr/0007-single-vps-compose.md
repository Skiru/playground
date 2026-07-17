# ADR 0007: Single-VPS Compose baseline

Status: Accepted

## Decision

Package stateless applications and stateful dependencies in validated Docker
Compose suitable for one VPS, with isolated edge and internal networks.

## Consequences

Operations stay affordable and understandable. CI builds images but C0-C2 do not
deploy. Kubernetes and automatic production rollout remain out of scope.
