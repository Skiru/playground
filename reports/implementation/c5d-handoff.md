# C5D Community, Forum & Moderation Module Handoff

This document summarizes the files, structure, and architecture of the newly delivered community, forum, and moderation module.

## 1. Directory Structure

* **Domain layer (`src/Community/Domain`):** Contains domain models, repositories, and statuses for `ForumCategory`, `ForumThread`, `ForumPost`, and `ContentReport`.
* **Application layer (`src/Community/Application/UseCase`):** Focused single-responsibility application use cases like `CreateForumThread`, `ReportContent`, `ModerateContent`, and `GetCommunityFeed`.
* **Infrastructure layer (`src/Community/Infrastructure`):** Optimally indexed DBAL adapters with N+1 batch profiles resolver implementation.
* **Controllers (`src/Community/UI/Http`):** Focused command/query controllers implementing payload limits (8KB), rate limiters, CSRF validation, and problem details responses.

## 2. Testing and Quality

* **PHPUnit Integration Suite:** 131 tests and 966 assertions completely green covering security, permissions, rate limiting, and duplication rules.
* **Vitest Suite:** 34 tests completely green covering `hardenedFetch` typed body policy.
* **TypeScript compilation:** `tsc` builds the workspace and generated API client with 0 errors.
