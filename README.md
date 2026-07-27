# FamilyPlaces

Production-oriented monorepo for a server-rendered catalogue of child-friendly
places. Features include a public geospatial catalogue, Google session identity,
a complete public community forum with categories, threads and replies, a global community activity
feed, content reporting, and an explicit concurrency-safe, audit-logged moderator queue.

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

## Local Start

The Docker-based path does not depend on host PHP or Node versions:

```fish
cp .env.example .env; ./scripts/bootstrap; ./scripts/dev
```

### Google Identity & Dev Bypass Config

To configure Google login and development bypass options, the following variables can be set in your `.env` file:
- `GOOGLE_IDENTITY_ENABLED=true/false` (Enables/disables Google Sign-in on the frontend)
- `PUBLIC_GOOGLE_CLIENT_ID=your-client-id` (The Google Client ID for the app, required if Google identity is enabled)
- `DEV_AUTH_ENABLED=true/false` (Enables/disables the bypass login button on dev/test environments)

The web SSR server is available at `http://localhost:3000`, API at
`http://localhost:8080`, and Mailpit at `http://localhost:8025` in the dev
profile. Use `./scripts/stop` to stop services. Data is preserved unless
`./scripts/reset-dev --with-data-loss` is invoked explicitly.

Quality commands for a Node 24/pnpm 11 host are `pnpm check`, `pnpm test`,
`pnpm api:generate`, and `pnpm e2e`. Their repository scripts use containers
for the PHP 8.5 toolchain.
