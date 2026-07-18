# C4R2 Media Runtime - Handoff Documentation

## Features Implemented
- **Typed Worker Error Classification (Gate A):** Introduced custom typed storage exceptions and Gd image processing exceptions. Handled corrupt and unsupported images permanently without retrying, while propagating transient failures for async queue retry.
- **Strict Production Media Contract (Gate B):** Implemented fast-fail validation on public base URLs. Prevented starting production containers with local, localhost, or unencrypted http configurations.
- **Asynchronous Recoverable Photo Deletion (Gate C):** Moved file cleanup out of the synchronous web thread. Deletion is now managed by an idempotent async command `CleanupPlacePhotoFiles`, which can be safely retried from the administration interface.

## Known Limitations
- Deletion remains asynchronous, meaning the photo will show as "DELETING" in the admin panel until the worker processes the cleanup. This is expected and handled gracefully by the UI.

## Verification
To run the full suite:
1. Back-end Unit & Integration Tests: `docker compose -f compose.yaml -f compose.test.yaml run --rm api php bin/phpunit`
2. Frontend Vitest: `pnpm --filter @family-places/web test`
3. E2E Playwright: `./scripts/reproduce-integration-e2e`
