# ADR 0010: Provider abstractions at outbound boundaries

Status: Accepted

## Decision

Use ports for map configuration, geocoding, travel time, media storage, and
external places. In C0-C2 only map configuration and local catalogue are real.

## Consequences

Missing credentials produce explicit provider-unavailable behavior. No fake
travel time, imported data, or arbitrary URL fetching is introduced.
