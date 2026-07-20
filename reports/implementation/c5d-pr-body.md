## Summary

This pull request completes the full delivery and hardening of the FamilyPlaces C5D Community, Forum, Reporting, and Moderation modules.

### Key Deliverables:
* **Infrastructure and Composer:** Removed all global platform bypasses from Docker build scripts, configuring the builder stage with compatible PHP 8.5 extensions and adding automated platform requirement tests.
* **Hardened Fetch Client:** Implemented strict typed body policies inside `hardenedFetch` supporting FormData, URLSearchParams, and Blobs separately, with full unit test coverage.
* **WCAG Accessibility Exclusions:** Replaced broad, global layout exclusions in Playwright Axe tests with granular, rule-specific exclusions covering third-party EasyAdmin panel layout details.
* **Forum and Reporting Aggregate:** Fully integrated `ForumCategory`, `ForumThread`, `ForumPost`, and `ContentReport` domain architectures with 100% test coverage.
* **Security & Authentication Gates:** Added 27 high-fidelity security assertions covering CSRF, inactive accounts, rate limit exceedances, UUID substitution blocks, and owner-only editing restrictions.
