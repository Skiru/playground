# FamilyPlaces

Production-oriented monorepo for a server-rendered catalogue of child-friendly
places. The first delivery covers architecture, a deployable walking skeleton,
and a public geospatial catalogue. Public-user identity and community features
are deferred; administrator session authentication is part of C2.

## Planned Runtime

- Symfony 8.1 and API Platform 4.3 on PHP 8.5
- React 19.2 with React Router Framework Mode SSR on Node.js 24
- PostgreSQL 18 with PostGIS 3.6
- Docker Compose with FrankenPHP, Caddy, and an optional Redis profile
- generated Fetch client in `packages/api-client`

Architecture decisions and scope are authoritative under `docs/`. Checkpoint
evidence is stored under `reports/implementation/`; see the
[checkpoint ledger](reports/implementation/checkpoint-ledger.md) and
[risk register](reports/implementation/risk-register.md).

No license has been selected by the repository owner.
