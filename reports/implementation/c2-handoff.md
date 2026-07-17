# C2 Handoff

## Delivered boundary

C2 delivers the published FamilyPlaces catalogue: administrator-managed place
drafts and publication, PostGIS discovery endpoints, generated TypeScript client,
and SSR home, results, details and progressively enhanced map views. C3 features
such as public registration, Google login, favorites, reviews, forum and uploads
are not implemented.

## Deployment inputs

- Provide unique `APP_SECRET`, PostgreSQL credentials and `DATABASE_URL`.
- Provide `MAP_STYLE_URL`, `MAP_ATTRIBUTION` and `MAP_PROVIDER_NAME` that satisfy
  the selected provider's terms.
- Create administrators explicitly with `app:user:create-admin`; fixtures never
  create production users.
- Run Doctrine migrations before starting API and worker processes. Never use
  `doctrine:schema:update --force`.
- Keep the API and database on the internal network. Browser API traffic goes
  through the web application's same-origin resource routes.

## Operational checks

- API liveness: `/api/v1/health/live`.
- API readiness, including PostgreSQL: `/api/v1/health/ready`.
- Web health: `/` must return useful SSR HTML.
- Confirm PostGIS and migration status before traffic is enabled.
- Confirm map attribution and the textual fallback on the deployed origin.
- Monitor discovery latency, 429 rates and PostgreSQL plans for the geography
  index as catalogue volume grows.

## Known residuals

- Backup and restore automation is intentionally outside C0-C2 and must be
  completed and tested before a production launch with irreplaceable data.
- Opening-hours SQL is complex but covered by focused runtime scenarios; extract
  it only when a second consumer or broader timezone matrix creates a concrete
  reuse need.
- MapLibre is a lazy client chunk of approximately 1 MB uncompressed. It does not
  block SSR content, but should be monitored against production performance
  budgets.
- Automated jsdom accessibility checks exclude computed color contrast because
  jsdom has no canvas implementation; browser-level contrast review remains a
  release responsibility.

## Evidence

- `reports/implementation/checkpoint-c1r.json`
- `reports/implementation/checkpoint-c2a.json`
- `reports/implementation/checkpoint-c2b.json`
- `reports/implementation/checkpoint-c2c.json`
- `reports/implementation/checkpoint-ledger.md`
- `reports/implementation/risk-register.md`
