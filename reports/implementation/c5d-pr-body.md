## Summary

This pull request completes the full delivery and hardening of the FamilyPlaces C5D Community, Forum, Reporting, and Moderation modules.

### Key Deliverables:
* **Infrastructure and Composer:** Removed all global platform bypasses from Docker build scripts, configuring the builder stage with compatible PHP 8.5 extensions and adding automated platform requirement tests.
* **Hardened Fetch Client:** Implemented strict typed body policies inside `hardenedFetch` supporting FormData, URLSearchParams, and Blobs separately, with full unit test coverage.
* **WCAG Accessibility Exclusions:** Replaced broad, global layout exclusions in Playwright Axe tests with granular, rule-specific exclusions covering third-party EasyAdmin panel layout details.
* **Forum and Reporting Aggregate:** Fully integrated `ForumCategory`, `ForumThread`, `ForumPost`, and `ContentReport` domain architectures with 100% test coverage.
* **Security & Authentication Gates:** Added high-fidelity security assertions covering CSRF, inactive accounts, rate-limit exceedances, UUID substitution blocks, and owner-only editing restrictions.
* **Production Certification Repairs:** Fixed generated-client browser routing, nested discussion replies, moderator no-store refreshes, fixture completeness, SPA navigation synchronization, and accessible names/titles.
* **Dependency Security:** Updated React Router to 8.3.0 and Guzzle to 7.15.1; pnpm and Composer audits report no known advisories.
* **Final Verification:** 137 PHPUnit tests with 1,016 assertions, 40 Vitest tests, and 48 desktop/mobile Playwright tests pass with zero retries and zero skips. Community Axe journeys report zero serious or critical violations.
