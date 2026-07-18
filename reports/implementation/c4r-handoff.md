# C4R Media Pipeline - Handoff Documentation

## Features Implemented
- **Secure Image Uploads (Gate A):** Validation of uploads (max 10 files, 12MB/file, 50MB/request, server-side MIME detection, minimum 320x240 dimensions, max 40 megapixels, and strict polyglot rejection). Centralized key formatting via `StorageObjectKey` value object.
- **Idempotent Background Processing (Gate B):** Implemented transaction-safe photo uploads and an asynchronous message consumer `ProcessPhotoHandler` using generation-specific paths to protect against race conditions. Safe deletion and set main workflows.
- **Accessible responsive Place Galleries (Gate C):** WebP conversion using GD with EXIF orientation normalization and transparency preservation. Public read models with absolute public URLs. Semantic `<figure>` and `<figcaption>` HTML gallery with focusable items and lazy loading.
- **Verification and PR (Gate D):** Passed 109/109 backend tests, 10/10 Vitest frontend tests, and 20/20 Playwright E2E integration tests (desktop, mobile, accessibility, admin media journey, and public gallery).

## Known Limitations
- Background subagent integration can be slow during large parallel docker builds (mitigated by using target-specific builds).
- S3 integration relies on the official AWS SDK provider chain.

## Verification
To run the full suite:
1. Back-end Unit Tests: `docker compose -f compose.yaml -f compose.test.yaml run --rm api php bin/phpunit`
2. Frontend Vitest: `pnpm --filter @family-places/web test`
3. E2E Playwright: `./scripts/reproduce-integration-e2e`
