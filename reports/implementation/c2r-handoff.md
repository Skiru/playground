# C2R Handoff

`STATUS=READY_FOR_C3`

## Certified Boundary

C2R certifies implementation SHA `c88a1704e66309db2984396885cb43468da60044` on `feat/family-places-platform-v1`. The certificate is intentionally not self-referential: later report commits do not change the certified code SHA.

The delivered boundary includes aggregate-backed place administration, atomic publication with concurrency protection, dictionary CRUD, PostgreSQL 18/PostGIS discovery, opening-hours evaluation, strict public DTO/OpenAPI contracts, generated TypeScript client drift protection, React Router SSR, progressive map behavior, production containers, desktop/mobile browser coverage, and browser accessibility checks.

## Evidence

- Checkpoints: `checkpoint-c2r-a.json` through `checkpoint-c2r-e.json`.
- Certification: `certification-c2r.json`.
- Test matrix: `c2r-test-matrix.json`.
- Adversarial review: `c2r-adversarial-review.json`.
- PR: https://github.com/Skiru/playground/pull/1
- Exact remote checks: 6/6 successful on `c88a1704e66309db2984396885cb43468da60044`.

## Deployment Inputs

- Provide unique production `APP_SECRET`, PostgreSQL credentials, and `DATABASE_URL`.
- Provide `MAP_STYLE_URL`, `MAP_ATTRIBUTION`, and `MAP_PROVIDER_NAME` under valid provider terms.
- Create administrators explicitly with `app:user:create-admin`; deterministic fixtures are test-only.
- Run Doctrine migrations before API and worker startup.
- Keep API, worker, and PostgreSQL on internal networking; expose browser traffic through the web boundary.

## Operational Checks

- API liveness: `/api/v1/health/live`.
- API readiness: `/api/v1/health/ready`.
- Web readiness: `/` returns useful SSR HTML before JavaScript.
- Monitor discovery latency, PostgreSQL query plans, 429 responses, map failures, and worker health.
- Test backup restoration before production stores irreplaceable data.

## Scope Guard

C3 was not started and no merge was performed. Google login, public registration, favorites, reviews, forum, and uploads remain outside C2R.
