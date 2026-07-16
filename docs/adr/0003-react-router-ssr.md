# ADR 0003: React Router Framework Mode SSR

Status: Accepted

## Decision

Use current stable React Router Framework Mode with SSR from the first web
checkpoint. Search state is encoded in URLs and initial data loads server-side.

## Consequences

Core pages remain crawlable and usable without JavaScript. MapLibre is lazy,
client-only enhancement and must preserve a textual fallback.
