# Security, Supply Chain, and Hardening Audit

This document records the exact security hardening steps, action pinning, base image pinning, Trivy scanning results, and SBOM generation.

## 1. Action Pinning Verification

All actions in `.github/workflows/` have been verified to be pinned to full 40-character commit SHAs.

- `actions/checkout` -> `08c6903cd8c0fde910a37f88322edcfb5dd907a8` (v5.0.0)
- `actions/setup-node` -> `a0853c24544627f65ddf259abe73b1d18a591444` (v5.0.0)
- `docker/setup-buildx-action` -> `e468171a9de216ec08956ac3ada2f0791b6bd435` (v3.11.1)
- `actions/upload-artifact` -> `ea165f8d65b6e75b540449e92b4886f43607fa02` (v4.6.2)

No mutable tags or rolling versions are used.

## 2. GITHUB_TOKEN Least Privilege

All workflows have explicitly configured read-only default permissions:
```yaml
permissions:
  contents: read
```
Only the dedicated release/packages job receives packages-write permissions when triggered on `main` branch.

## 3. Base Image Pinning

All base images used in production configurations have been pinned to secure immutable digests:

- **FrankenPHP API/Worker Base**: `dunglas/frankenphp:php8.5-bookworm@sha256:cd7a5db256e74255bb50edf57b19e1bc6f57f91557d7bb864cd76e89543b6727`
- **Node Web Base**: `node:24-bookworm@sha256:5711a0d445a1af54af9589066c646df387d1831a608226f4cd694fc59e745059`
- **Node Web Production**: `node:24-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d`
- **PostgreSQL/PostGIS Database**: `postgis/postgis:18-3.6@sha256:c893f6f2652d11e13f50f8623045b3523991b41d038b4d213dc040f42641f0d7`

No `latest` tags are used in production configurations.

## 4. Trivy Vulnerability Scan Results

Both the API and Web images were built once and scanned using the pinned official Trivy image `aquasec/trivy:0.58.2@sha256:665030f4d33a82c1e8d9d5e0453365842236723c1ee5cc3becca698268e66a56`.

- **API/Worker Scan (`reports/security/trivy-api.json`)**:
  - **Fixed/Upgradable HIGH/CRITICAL**: `0` (Fully resolved by adding `apt-get upgrade` in build layers)
  - **Unfixed Debian OS vulnerabilities**: 185 (Known Debian bookworm upstream vulnerabilities without available patches)
  - **Application/Composer Packages**: `0` vulnerabilities
- **Web Scan (`reports/security/trivy-web.json`)**:
  - **Fixed/Upgradable HIGH/CRITICAL**: `0` (Fully resolved by adding `apt-get upgrade` in build layers)
  - **Unfixed Debian OS vulnerabilities**: 21 (Slim base Debian upstream vulnerabilities without available patches)
  - **Application/Node Packages**: `0` vulnerabilities

## 5. Software Bill of Materials (SBOM)

SBOMs have been generated using the CycloneDX format:
- **API/Worker SBOM**: `reports/security/sbom-api.json`
- **Web SBOM**: `reports/security/sbom-web.json`
- **SBOM Index**: `reports/security/sbom-index.json`

All files are validated against CycloneDX schema, indexing all OS libraries, PHP composer packages, and Node packages.

## 6. Runtime Hardening Checklist

| Security Control | Implementation | Status |
|---|---|---|
| Non-Root User | `USER www-data` (API) / `USER node` (Web) | `PASS` |
| No Bind Mounts | Production compose uses images with baked assets | `PASS` |
| No Dev Dependencies| Removed from production build layers | `PASS` |
| Healthchecks | `curl` live check (API) / `node` fetch (Web) | `PASS` |
| No Compilation Tools| Excluded from final runtime images | `PASS` |
| Image History Clean | No secrets or credentials embedded in docker layers | `PASS` |
