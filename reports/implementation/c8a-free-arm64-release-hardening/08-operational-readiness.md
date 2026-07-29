# Operational readiness

Exact deployment: `scripts/production/deploy`. Exact rollback: `scripts/production/rollback .production/releases/<recorded-release-env>`. Deployment locks, pulls before mutation, takes a pre-deploy backup, migrates one-shot, waits for health, validates PostGIS, and performs a disposable R2 object operation. Rollback never downgrades schema and requires explicit compatibility certification.
