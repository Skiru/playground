# C5D Community, Forum & Moderation Module Handoff

This document summarizes the files, structure, and architecture of the newly delivered community, forum, and moderation module.

## 1. Directory Structure

* **Domain layer (`src/Community/Domain`):** Contains domain models, repositories, and statuses for `ForumCategory`, `ForumThread`, `ForumPost`, and `ContentReport`.
* **Application layer (`src/Community/Application/UseCase`):** Focused single-responsibility application use cases like `CreateForumThread`, `ReportContent`, `ModerateContent`, and `GetCommunityFeed`.
* **Infrastructure layer (`src/Community/Infrastructure`):** Optimally indexed DBAL adapters with N+1 batch profiles resolver implementation.
* **Controllers (`src/Community/UI/Http`):** Focused command/query controllers implementing payload limits (8KB), rate limiters, CSRF validation, and problem details responses.

## 2. Testing and Quality

* **PHPUnit Integration Suite:** 137 tests and 1,016 assertions pass, covering security, permissions, rate limiting, concurrency, and duplication rules.
* **Vitest Suite:** 40 tests pass, including `hardenedFetch`, route, image fallback, and rendering behavior.
* **Playwright Suite:** 48 real desktop/mobile journeys pass with zero retries and zero skips, including community Axe scans.
* **Static and Build Gates:** TypeScript, ESLint, production build, PHP-CS-Fixer, PHPStan, and Deptrac pass with zero errors.
* **Operational Gates:** Clean/upgrade migration rehearsal, OpenAPI drift, Compose validation, Gitleaks, pnpm audit, and Composer audit pass.
