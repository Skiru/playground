# C5D Risk Register

This document identifies major implementation, architectural, and operational risks alongside their severity, mitigation strategies, and notes.

| Risk ID | Title | Impact | Severity | Mitigation Strategy | Notes |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **R-01** | Third-party EasyAdmin WCAG Violations | Accessibility issues for dashboard screen reader/keyboard users. | Medium | Narrow exclusions in `accessibility.spec.ts` for EasyAdmin panel and documented WCAG exceptions. | Accepted residual vendor risk. Main public application `/spolecznosc` and `/forum` remain 100% accessible. |
| **R-02** | Simultaneous duplicate review inserts | SQL constraint leaks or duplicate review records. | Low-Medium | DBAL translation of DB unique constraint races to deterministic HTTP 409 `REVIEW_ALREADY_EXISTS`. | Tested with multiple rapid concurrent requests. |
| **R-03** | Moderation action cascading deletions | Silent loss of evidence or reports due to database cascade deletes. | Medium | Implemented defensive non-cascading soft deletes and review cascades. Moderation history is strictly append-only. | All moderation logs are preserved permanently. |
