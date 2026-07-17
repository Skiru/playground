# C3 Authorization Prompt

STATUS=SUPERSEDED_DO_NOT_EXECUTE

Begin C3 only after confirming the repository state below.

## Required Input

- Repository: `Skiru/playground`
- Branch: `feat/family-places-platform-v1`
- Certified C2R implementation SHA: `c88a1704e66309db2984396885cb43468da60044`
- Certified tree: `8d2927eadf3da188e47b0e57acf236b0abcc452f`
- PR: https://github.com/Skiru/playground/pull/1
- C2R certificate: `reports/implementation/certification-c2r.json`
- C2R handoff: `reports/implementation/c2r-handoff.md`
- C2R risk register: `reports/implementation/c2r-risk-register.md`

## Preflight

Verify the current branch, ancestry from the certified implementation SHA, worktree state, remote SHA, and exact remote checks before changing code. Do not rewrite or weaken C2R invariants, generated-client drift, production gates, or the single aggregate write path.

## C3 Boundary

Implement only the separately supplied C3 requirements. Treat Google login, public registration, favorites, reviews, forum, uploads, and any community capability as unauthorized unless the C3 request explicitly includes them.

Preserve Symfony modular-monolith boundaries, React Router SSR, PostgreSQL/PostGIS, the OpenAPI-generated client, Docker Compose, one database, typed commands, optimistic locking, publication transactions, published-only public discovery, resource limits, map fallback behavior, and desktop/mobile accessibility coverage.

Do not merge unless explicitly instructed. Produce checkpoint evidence and bind any new certification to the exact implementation SHA rather than to its own report commit.
