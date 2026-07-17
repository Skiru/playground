# C2 Final Risk Register

This document registers the risks identified during the C2 Final pre-merge closure and the implemented mitigations and policies.

## 1. Identified Risks and Mitigations

### 1.1. Brand / Theme Coupling
- **Risk**: Dynamic branding changes or future rebranding could require substantial view modifications or risk breaking current UI designs.
- **Severity**: Medium
- **Mitigation**: Implemented semantic design tokens (CSS variables) in `tokens.css` and a central semantic asset manifest. All colors, typography, spacing, and brand imagery are mapped to semantic variables.
- **Verification**: Built an automated checker (`gate-a-checks.js`) to reject any new hardcoded color hex values outside the theme files.

### 1.2. Copy and Localization Maintenance
- **Risk**: Hardcoded copy strings spread across views would complicate editing, internationalization, or regional branding adjustments.
- **Severity**: Medium
- **Mitigation**: Introduced a typed content catalog with strict compile-time validation under `apps/web/app/content/`.
- **Verification**: Checked via `gate-a-checks.js` to prevent user-facing Polish literal strings inside TSX/JSX of routes and components.

### 1.3. Base Image and Supply Chain Drift
- **Risk**: Using rolling or floating image tags (such as `:latest` or `:18`) allows unvetted dependencies or base system updates to enter production builds silently.
- **Severity**: High
- **Mitigation**: All production base images are pinned to unique SHA256 digests in both Dockerfiles and compose files.
- **Verification**: Automated by Dependabot/Renovate matching patterns and confirmed by `verify-artifact-chain` script.

### 1.4. Image Vulnerabilities
- **Risk**: Base systems (e.g. Debian bookworm) include package-level vulnerabilities that could expose the runtime containers.
- **Severity**: High
- **Mitigation**: Configured explicit `apt-get update && apt-get upgrade -y` instructions in Dockerfile build steps to fetch and apply security patches.
- **Verification**: Scanned with Trivy 0.58.2, showing **0** upgradable HIGH or CRITICAL issues remaining in any production container.

### 1.5. Database Disaster Recovery
- **Risk**: Data loss or corruption without a fully verified, encrypted path to recovery.
- **Severity**: Critical
- **Mitigation**: Developed robust shell backup/restore/verification scripts and verified them via an automated restore drill.
- **Verification**: Executed `./scripts/restore-drill` in CI/test pipelines, verifying PostGIS extension queries, migration status, and correct place counts after full recovery.

## 2. Known Limitations
- **Debian OS-level Unpatched Vulnerabilities**: There are 185 vulnerabilities in `debian 12.15` and 21 in `debian-slim` base images. These are unfixed upstream Debian packages that have no patch available yet from Debian maintainers. This is an accepted risk for standard Debian Bookworm images.
- **Local GitHub CLI (`gh`) Absence**: The local workspace lacks `gh` CLI. Pull request and check verification was handled using curl queries to the GitHub REST API.
